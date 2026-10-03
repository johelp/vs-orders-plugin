import { HttpError, json, errorResponse, allowedOrigin, withCors } from './http.js';
import { requireUser } from './auth.js';
import * as pub from './api-public.js';
import * as admin from './api-admin.js';

const PUBLIC_ROUTES = [
  ['GET', '/api/products', pub.getMenu],
  ['GET', '/api/status', pub.getStatus],
  ['POST', '/api/orders', pub.createOrder],
  ['POST', '/api/subscribe', pub.subscribe],
  ['POST', '/api/stripe/create-intent', pub.createStripeIntent],
  ['POST', '/api/stripe/webhook', pub.stripeWebhook],
];

const ADMIN_ROUTES = [
  ['GET', '/api/admin/me', admin.me],
  ['GET', '/api/admin/dashboard', admin.dashboard],
  ['GET', '/api/admin/orders', admin.listOrders],
  ['GET', '/api/admin/orders/:id', admin.getOrder],
  ['POST', '/api/admin/orders/:id/status', admin.updateOrderStatus],
  ['POST', '/api/admin/orders/:id/payment', admin.updateOrderPayment],
  ['GET', '/api/admin/orders/:id/messages', admin.getMessages],
  ['POST', '/api/admin/orders/:id/messages', admin.sendMessage],
  ['GET', '/api/admin/products', admin.listProducts],
  ['POST', '/api/admin/products', admin.createProduct],
  ['POST', '/api/admin/products/bulk-toggle', admin.bulkToggleProducts],
  ['PUT', '/api/admin/products/:id', admin.updateProduct],
  ['DELETE', '/api/admin/products/:id', admin.deleteProduct],
  ['POST', '/api/admin/products/:id/toggle', admin.toggleProduct],
  ['POST', '/api/admin/uploads', admin.uploadImage],
  ['GET', '/api/admin/categories', admin.listCategories],
  ['POST', '/api/admin/categories', admin.createCategory],
  ['POST', '/api/admin/categories/reorder', admin.reorderCategories],
  ['PUT', '/api/admin/categories/:id', admin.updateCategory],
  ['DELETE', '/api/admin/categories/:id', admin.deleteCategory],
  ['GET', '/api/admin/settings', admin.getSettings],
  ['PUT', '/api/admin/settings', admin.updateSettings],
  ['POST', '/api/admin/delivery/toggle', admin.toggleDelivery],
  ['GET', '/api/admin/subscribers', admin.listSubscribers],
  ['GET', '/api/admin/subscribers.csv', admin.exportSubscribersCsv],
  ['POST', '/api/admin/subscribers/notify', admin.notifySubscribers],
  ['DELETE', '/api/admin/subscribers/:id', admin.deleteSubscriber],
  ['GET', '/api/admin/users', admin.listUsers],
  ['POST', '/api/admin/users', admin.saveUser],
  ['DELETE', '/api/admin/users/:email', admin.deleteUser],
  ['POST', '/api/admin/reset', admin.resetData],
];

function compile(routes) {
  return routes.map(([method, pattern, handler]) => {
    const names = [];
    const re = new RegExp(
      '^' + pattern.replace(/[.]/g, '\\.').replace(/:(\w+)/g, (_, n) => (names.push(n), '([^/]+)')) + '/?$',
    );
    return { method, re, names, handler };
  });
}

const publicTable = compile(PUBLIC_ROUTES);
const adminTable = compile(ADMIN_ROUTES);

function match(table, method, path) {
  let pathMatched = false;
  for (const r of table) {
    const m = path.match(r.re);
    if (!m) continue;
    pathMatched = true;
    if (r.method === method || (method === 'HEAD' && r.method === 'GET')) {
      const params = Object.fromEntries(r.names.map((n, i) => [n, m[i + 1]]));
      return { handler: r.handler, params };
    }
  }
  if (pathMatched) throw new HttpError(405, 'Method not allowed.');
  return null;
}

export async function handleApi(context) {
  const { request, env } = context;
  const url = new URL(request.url);
  const path = url.pathname;
  const method = request.method.toUpperCase();
  const isAdmin = path === '/api/admin' || path.startsWith('/api/admin/');
  const origin = isAdmin ? null : allowedOrigin(request, env);

  try {
    if (!env.DB) throw new HttpError(500, 'D1 binding "DB" is not configured.');

    if (!isAdmin && method === 'OPTIONS') {
      return withCors(new Response(null, { status: origin ? 204 : 403 }), origin);
    }

    const route = match(isAdmin ? adminTable : publicTable, method, path);
    if (!route) throw new HttpError(404, 'Not found.');

    const ctx = { request, env, url, params: route.params, waitUntil: (p) => context.waitUntil(p) };
    if (isAdmin) {
      // Custom header can't be sent cross-site without a CORS preflight, which we never grant for /api/admin.
      if (method !== 'GET' && method !== 'HEAD' && request.headers.get('X-Requested-With') !== 'vso-admin') {
        throw new HttpError(403, 'Missing X-Requested-With header.');
      }
      ctx.user = await requireUser(request, env);
    }
    return withCors(await route.handler(ctx), origin);
  } catch (err) {
    return withCors(errorResponse(err), origin);
  }
}

export async function handleMedia({ request, env, params }) {
  if (!env.MEDIA) return json({ error: 'Media storage not configured.' }, 404);
  const key = (Array.isArray(params.path) ? params.path : [params.path]).filter(Boolean).join('/');
  if (!key || key.includes('..')) return json({ error: 'Not found.' }, 404);
  const object = await env.MEDIA.get(key);
  if (!object) return json({ error: 'Not found.' }, 404);
  const headers = new Headers();
  object.writeHttpMetadata(headers);
  headers.set('ETag', object.httpEtag);
  headers.set('Cache-Control', 'public, max-age=31536000, immutable');
  headers.set('X-Content-Type-Options', 'nosniff');
  if (request.headers.get('If-None-Match') === object.httpEtag) return new Response(null, { status: 304, headers });
  return new Response(request.method === 'HEAD' ? null : object.body, { headers });
}
