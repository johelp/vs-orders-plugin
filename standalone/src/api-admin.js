import { HttpError, json, readJson, str, isEmail, siteOrigin } from './http.js';
import {
  ORDER_STATUSES,
  PAYMENT_STATUSES,
  MANAGER_KEYS,
  ADMIN_KEYS,
  loadSettings,
  setSetting,
  settingsStatements,
  deliveryState,
  stripeConfig,
  parseSteps,
  num,
} from './settings.js';
import { requireAdmin } from './auth.js';
import {
  emailConfigured,
  sendEmail,
  sendBatch,
  statusUpdateEmail,
  customerMessageEmail,
  deliveryOpenEmail,
} from './email.js';
import { localYmd } from './api-public.js';

const nowIso = () => new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');

function intParam(value) {
  const n = parseInt(value, 10);
  if (!(n > 0)) throw new HttpError(400, 'Invalid id.');
  return n;
}

// ─── Session ───

export async function me({ env, user }) {
  const settings = await loadSettings(env.DB);
  const del = deliveryState(settings);
  return json({
    email: user.email,
    role: user.role,
    statuses: ORDER_STATUSES,
    paymentStatuses: PAYMENT_STATUSES,
    businessName: settings.business_name || '',
    delivery: { active: del.active, atCapacity: del.atCapacity, orderCount: del.orderCount, maxOrders: del.maxOrders, zone: settings.weekly_zone || '' },
  });
}

// ─── Dashboard ───

export async function dashboard({ env }) {
  const db = env.DB;
  const settings = await loadSettings(db);
  const del = deliveryState(settings);
  const since = new Date(Date.now() - 36 * 3600 * 1000).toISOString();
  const [byStatus, recent, recentWindow, activeProducts, subscribers] = await db.batch([
    db.prepare('SELECT status, COUNT(*) AS c, COALESCE(SUM(total), 0) AS revenue FROM orders GROUP BY status'),
    db.prepare(
      `SELECT o.id, o.ref, o.status, o.payment_status, o.total, o.customer_name, o.customer_zone, o.created_at,
              (SELECT COALESCE(SUM(qty), 0) FROM order_items WHERE order_id = o.id) AS item_count
         FROM orders o ORDER BY o.id DESC LIMIT 5`,
    ),
    db.prepare("SELECT total, created_at FROM orders WHERE status <> 'cancelled' AND created_at >= ?1").bind(since),
    db.prepare('SELECT COUNT(*) AS c FROM products WHERE active = 1'),
    db.prepare('SELECT COUNT(*) AS c FROM subscribers'),
  ]);

  const statusCounts = Object.fromEntries(Object.keys(ORDER_STATUSES).map((k) => [k, 0]));
  let totalOrders = 0;
  let totalRevenue = 0;
  for (const row of byStatus.results) {
    statusCounts[row.status] = row.c;
    totalOrders += row.c;
    if (row.status !== 'cancelled') totalRevenue += row.revenue;
  }
  const today = localYmd(env.TIMEZONE);
  const todayKey = `${today.y}-${today.m}-${today.d}`;
  let todayOrders = 0;
  let todayRevenue = 0;
  for (const row of recentWindow.results) {
    const d = localYmd(env.TIMEZONE, new Date(row.created_at));
    if (`${d.y}-${d.m}-${d.d}` === todayKey) {
      todayOrders++;
      todayRevenue += row.total;
    }
  }

  return json({
    delivery: { active: del.active, atCapacity: del.atCapacity, zone: settings.weekly_zone || '', orderCount: del.orderCount, maxOrders: del.maxOrders },
    stats: {
      todayOrders,
      todayRevenue,
      totalOrders,
      totalRevenue,
      activeProducts: activeProducts.results[0].c,
      subscribers: subscribers.results[0].c,
      pending: statusCounts.new,
    },
    statusCounts,
    recent: recent.results,
    businessName: settings.business_name || '',
  });
}

// ─── Orders ───

export async function listOrders({ env, url }) {
  const db = env.DB;
  const p = url.searchParams;
  const where = [];
  const binds = [];
  const status = p.get('status');
  if (status && ORDER_STATUSES[status]) {
    where.push('o.status = ?');
    binds.push(status);
  }
  const payment = p.get('payment');
  if (payment && PAYMENT_STATUSES.includes(payment)) {
    where.push('o.payment_status = ?');
    binds.push(payment);
  }
  const zone = str(p.get('zone'), 200);
  if (zone) {
    where.push('o.customer_zone = ?');
    binds.push(zone);
  }
  const q = str(p.get('q'), 200);
  if (q) {
    where.push('(o.ref LIKE ? OR o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.customer_phone LIKE ?)');
    const like = `%${q.replace(/[%_]/g, '')}%`;
    binds.push(like, like, like, like);
  }
  const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';
  const limit = Math.min(100, Math.max(1, parseInt(p.get('limit'), 10) || 50));
  const offset = Math.max(0, parseInt(p.get('offset'), 10) || 0);

  const [rows, count, zones] = await db.batch([
    db
      .prepare(
        `SELECT o.id, o.ref, o.status, o.payment_status, o.total, o.customer_name, o.customer_email, o.customer_phone,
                o.customer_zone, o.created_at,
                (SELECT COALESCE(SUM(qty), 0) FROM order_items WHERE order_id = o.id) AS item_count
           FROM orders o ${whereSql} ORDER BY o.id DESC LIMIT ${limit} OFFSET ${offset}`,
      )
      .bind(...binds),
    db.prepare(`SELECT COUNT(*) AS c FROM orders o ${whereSql}`).bind(...binds),
    db.prepare("SELECT DISTINCT customer_zone AS zone FROM orders WHERE customer_zone <> '' ORDER BY customer_zone"),
  ]);
  return json({ orders: rows.results, total: count.results[0].c, zones: zones.results.map((z) => z.zone) });
}

async function loadOrder(db, id) {
  const order = await db.prepare('SELECT * FROM orders WHERE id = ?1').bind(id).first();
  if (!order) throw new HttpError(404, 'Order not found.');
  return order;
}

export async function getOrder({ env, params }) {
  const db = env.DB;
  const id = intParam(params.id);
  const order = await loadOrder(db, id);
  const [items, messages] = await db.batch([
    db.prepare('SELECT product_id, name, price, qty FROM order_items WHERE order_id = ?1 ORDER BY id').bind(id),
    db.prepare('SELECT * FROM messages WHERE order_id = ?1 ORDER BY created_at ASC, id ASC').bind(id),
  ]);
  const settings = await loadSettings(db);
  const business = Object.fromEntries(Object.keys(ADMIN_KEYS).filter((k) => k.startsWith('business_')).map((k) => [k, settings[k] || '']));
  return json({ order, items: items.results, messages: messages.results, business, stripeMode: stripeConfig(env, settings).mode });
}

export async function updateOrderStatus(ctx) {
  const { env, request, params } = ctx;
  const id = intParam(params.id);
  const { status } = await readJson(request);
  if (!ORDER_STATUSES[status]) throw new HttpError(400, 'Invalid status.');
  const order = await loadOrder(env.DB, id);
  if (order.status === status) return json({ success: true, status, emailed: false });
  await env.DB.prepare('UPDATE orders SET status = ?2, updated_at = ?3 WHERE id = ?1').bind(id, status, nowIso()).run();
  const settings = await loadSettings(env.DB);
  const mail = statusUpdateEmail(settings, order, status);
  ctx.waitUntil(sendEmail(env, { to: order.customer_email, ...mail }));
  return json({ success: true, status, emailed: emailConfigured(env) });
}

export async function updateOrderPayment({ env, request, params }) {
  const id = intParam(params.id);
  const { payment_status: paymentStatus } = await readJson(request);
  if (!PAYMENT_STATUSES.includes(paymentStatus)) throw new HttpError(400, 'Invalid payment status.');
  await loadOrder(env.DB, id);
  await env.DB.prepare('UPDATE orders SET payment_status = ?2, updated_at = ?3 WHERE id = ?1').bind(id, paymentStatus, nowIso()).run();
  return json({ success: true, payment_status: paymentStatus });
}

export async function getMessages({ env, params }) {
  const id = intParam(params.id);
  const { results } = await env.DB.prepare('SELECT * FROM messages WHERE order_id = ?1 ORDER BY created_at ASC, id ASC').bind(id).all();
  return json(results);
}

export async function sendMessage({ env, request, params }) {
  const id = intParam(params.id);
  const body = str((await readJson(request)).body, 5000);
  if (!body) throw new HttpError(400, 'Message body is required.');
  const order = await loadOrder(env.DB, id);
  if (!order.customer_email) throw new HttpError(400, 'Customer has no email address on file.');
  const settings = await loadSettings(env.DB);
  const mail = customerMessageEmail(settings, order, body);
  const result = await sendEmail(env, { to: order.customer_email, ...mail });
  const message = await env.DB
    .prepare(
      `INSERT INTO messages (order_id, direction, channel, subject, body, sender, message_id, status)
       VALUES (?1, 'outgoing', 'email', ?2, ?3, ?4, ?5, ?6) RETURNING *`,
    )
    .bind(id, mail.subject, body, settings.business_name || '', result.id || '', result.ok ? 'sent' : 'failed')
    .first();
  if (!result.ok) {
    return json({ success: false, message: `Email failed to send: ${result.error}`, data: message });
  }
  return json({ success: true, message: `Message sent to ${order.customer_email}`, data: message });
}

// ─── Products ───

function cleanImageUrl(value) {
  const url = str(value, 1000);
  if (!url) return '';
  if (url.startsWith('/media/') || /^https:\/\/[^\s"'<>]+$/i.test(url)) return url;
  throw new HttpError(400, 'Image must be an uploaded image or an https:// URL.');
}

async function deleteMediaIfOwned(env, url) {
  if (env.MEDIA && url && url.startsWith('/media/')) {
    await env.MEDIA.delete(decodeURIComponent(url.slice('/media/'.length)));
  }
}

async function productPayload(db, body, partial = false) {
  const out = {};
  if (!partial || 'name' in body) {
    out.name = str(body.name, 200);
    if (!out.name) throw new HttpError(400, 'Name is required.');
  }
  if (!partial || 'description' in body) out.description = str(body.description, 1000);
  if (!partial || 'price' in body) {
    out.price = Math.round(num(body.price, NaN) * 100) / 100;
    if (!(out.price >= 0)) throw new HttpError(400, 'Price must be a positive number.');
  }
  if (!partial || 'category_id' in body) {
    const cid = parseInt(body.category_id, 10);
    if (cid > 0) {
      const cat = await db.prepare('SELECT id FROM categories WHERE id = ?1').bind(cid).first();
      if (!cat) throw new HttpError(400, 'Category not found.');
      out.category_id = cid;
    } else {
      out.category_id = null;
    }
  }
  if (!partial || 'image_url' in body) out.image_url = cleanImageUrl(body.image_url);
  if ('active' in body) out.active = body.active ? 1 : 0;
  return out;
}

export async function listProducts({ env }) {
  const db = env.DB;
  const [products, categories] = await db.batch([
    db.prepare(
      `SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
        ORDER BY p.sort_order ASC, p.id ASC`,
    ),
    db.prepare(
      `SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count
         FROM categories c ORDER BY c.sort_order ASC, c.id ASC`,
    ),
  ]);
  return json({ products: products.results, categories: categories.results, mediaEnabled: Boolean(env.MEDIA) });
}

export async function createProduct({ env, request }) {
  const body = await readJson(request);
  const data = await productPayload(env.DB, body);
  const active = 'active' in data ? data.active : 1;
  const product = await env.DB
    .prepare(
      `INSERT INTO products (category_id, name, description, price, image_url, active, sort_order)
       VALUES (?1, ?2, ?3, ?4, ?5, ?6, (SELECT COALESCE(MAX(sort_order), -1) + 1 FROM products)) RETURNING *`,
    )
    .bind(data.category_id, data.name, data.description, data.price, data.image_url, active)
    .first();
  return json({ success: true, product }, 201);
}

export async function updateProduct({ env, request, params }) {
  const id = intParam(params.id);
  const existing = await env.DB.prepare('SELECT * FROM products WHERE id = ?1').bind(id).first();
  if (!existing) throw new HttpError(404, 'Product not found.');
  const data = await productPayload(env.DB, await readJson(request), true);
  const keys = Object.keys(data);
  if (keys.length) {
    const sets = keys.map((k, i) => `${k} = ?${i + 2}`).join(', ');
    await env.DB.prepare(`UPDATE products SET ${sets} WHERE id = ?1`).bind(id, ...keys.map((k) => data[k])).run();
  }
  if ('image_url' in data && data.image_url !== existing.image_url) await deleteMediaIfOwned(env, existing.image_url);
  const product = await env.DB.prepare('SELECT * FROM products WHERE id = ?1').bind(id).first();
  return json({ success: true, product });
}

export async function deleteProduct({ env, params }) {
  const id = intParam(params.id);
  const existing = await env.DB.prepare('SELECT image_url FROM products WHERE id = ?1').bind(id).first();
  if (!existing) throw new HttpError(404, 'Product not found.');
  await env.DB.prepare('DELETE FROM products WHERE id = ?1').bind(id).run();
  await deleteMediaIfOwned(env, existing.image_url);
  return json({ success: true });
}

export async function toggleProduct({ env, params }) {
  const id = intParam(params.id);
  const row = await env.DB
    .prepare('UPDATE products SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id = ?1 RETURNING active')
    .bind(id)
    .first();
  if (!row) throw new HttpError(404, 'Product not found.');
  return json({ success: true, active: row.active === 1 });
}

export async function bulkToggleProducts({ env, request }) {
  const body = await readJson(request);
  const ids = (Array.isArray(body.ids) ? body.ids : []).map((v) => parseInt(v, 10)).filter((n) => n > 0).slice(0, 1000);
  if (!ids.length) return json({ success: true, count: 0, active: Boolean(body.active) });
  const active = body.active ? 1 : 0;
  const stmt = env.DB.prepare('UPDATE products SET active = ?2 WHERE id = ?1');
  await env.DB.batch(ids.map((id) => stmt.bind(id, active)));
  return json({ success: true, count: ids.length, active: Boolean(active) });
}

const IMAGE_TYPES = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif', 'image/avif': 'avif' };

export async function uploadImage({ env, request }) {
  if (!env.MEDIA) throw new HttpError(503, 'Image storage (R2 binding MEDIA) is not configured.');
  const form = await request.formData().catch(() => null);
  const file = form?.get('file');
  if (!file || typeof file === 'string') throw new HttpError(400, 'No file uploaded.');
  const ext = IMAGE_TYPES[file.type];
  if (!ext) throw new HttpError(400, 'Only JPG, PNG, WebP, GIF or AVIF images are allowed.');
  if (file.size > 5 * 1024 * 1024) throw new HttpError(400, 'Image must be 5 MB or smaller.');
  const key = `products/${crypto.randomUUID()}.${ext}`;
  await env.MEDIA.put(key, file.stream(), { httpMetadata: { contentType: file.type } });
  return json({ success: true, url: `/media/${key}` }, 201);
}

// ─── Categories ───

function slugify(name) {
  return (
    name
      .normalize('NFKD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 60) || 'category'
  );
}

export async function listCategories({ env }) {
  const { results } = await env.DB
    .prepare(
      `SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count
         FROM categories c ORDER BY c.sort_order ASC, c.id ASC`,
    )
    .all();
  return json(results);
}

export async function createCategory({ env, request }) {
  const body = await readJson(request);
  const name = str(body.name, 100);
  if (!name) throw new HttpError(400, 'Name is required.');
  const icon = str(body.icon, 16);
  const base = slugify(name);
  let slug = base;
  for (let i = 2; await env.DB.prepare('SELECT 1 FROM categories WHERE slug = ?1').bind(slug).first(); i++) slug = `${base}-${i}`;
  const category = await env.DB
    .prepare(
      `INSERT INTO categories (slug, name, icon, sort_order)
       VALUES (?1, ?2, ?3, (SELECT COALESCE(MAX(sort_order), -1) + 1 FROM categories)) RETURNING *`,
    )
    .bind(slug, name, icon)
    .first();
  return json({ success: true, category }, 201);
}

export async function updateCategory({ env, request, params }) {
  const id = intParam(params.id);
  const body = await readJson(request);
  const name = str(body.name, 100);
  if (!name) throw new HttpError(400, 'Name is required.');
  const category = await env.DB
    .prepare('UPDATE categories SET name = ?2, icon = ?3 WHERE id = ?1 RETURNING *')
    .bind(id, name, str(body.icon, 16))
    .first();
  if (!category) throw new HttpError(404, 'Category not found.');
  return json({ success: true, category });
}

export async function deleteCategory({ env, params }) {
  const id = intParam(params.id);
  const row = await env.DB.prepare('SELECT COUNT(*) AS c FROM products WHERE category_id = ?1').bind(id).first();
  if (row.c > 0) throw new HttpError(400, 'Move or delete the products in this category first.');
  await env.DB.prepare('DELETE FROM categories WHERE id = ?1').bind(id).run();
  return json({ success: true });
}

export async function reorderCategories({ env, request }) {
  const { ids } = await readJson(request);
  const list = (Array.isArray(ids) ? ids : []).map((v) => parseInt(v, 10)).filter((n) => n > 0);
  if (!list.length) throw new HttpError(400, 'No categories to reorder.');
  const stmt = env.DB.prepare('UPDATE categories SET sort_order = ?2 WHERE id = ?1');
  await env.DB.batch(list.map((id, i) => stmt.bind(id, i)));
  return json({ success: true });
}

// ─── Settings ───

function cleanSetting(type, value) {
  if (type === 'number') return String(Math.max(0, Math.round(num(value) * 100) / 100));
  if (type === 'int') return String(Math.max(0, Math.trunc(num(value))));
  if (type === 'url') {
    const v = str(value, 1000);
    if (v && !/^https?:\/\/[^\s"'<>]+$/i.test(v)) throw new HttpError(400, `Invalid URL: ${v}`);
    return v;
  }
  if (type === 'email') {
    const v = str(value, 254);
    if (v && !isEmail(v)) throw new HttpError(400, `Invalid email: ${v}`);
    return v;
  }
  if (type === 'stripe_mode') return value === 'live' ? 'live' : 'test';
  if (type === 'steps') {
    const steps = (Array.isArray(value) ? value : [])
      .slice(0, 6)
      .map((s) => ({ title: str(s?.title, 100), desc: str(s?.desc, 300) }))
      .filter((s) => s.title);
    return JSON.stringify(steps);
  }
  return str(value, type);
}

export async function getSettings({ env, request, user }) {
  const settings = await loadSettings(env.DB);
  const del = deliveryState(settings);
  const keys = user.role === 'admin' ? { ...MANAGER_KEYS, ...ADMIN_KEYS } : MANAGER_KEYS;
  const values = {};
  for (const k of Object.keys(keys)) values[k] = k === 'how_steps' ? parseSteps(settings[k]) : settings[k] ?? '';
  const out = {
    role: user.role,
    settings: values,
    delivery: { active: del.active, atCapacity: del.atCapacity, orderCount: del.orderCount, maxOrders: del.maxOrders },
  };
  if (user.role === 'admin') {
    const origin = siteOrigin(request, env);
    out.integrations = {
      stripe: {
        mode: stripeConfig(env, settings).mode,
        testReady: Boolean(env.STRIPE_TEST_PUBLISHABLE_KEY && env.STRIPE_TEST_SECRET_KEY),
        liveReady: Boolean(env.STRIPE_LIVE_PUBLISHABLE_KEY && env.STRIPE_LIVE_SECRET_KEY),
        testWebhook: Boolean(env.STRIPE_TEST_WEBHOOK_SECRET),
        liveWebhook: Boolean(env.STRIPE_LIVE_WEBHOOK_SECRET),
        webhookUrl: `${origin}/api/stripe/webhook`,
      },
      email: { configured: emailConfigured(env), from: env.EMAIL_FROM || '' },
      mailerlite: { configured: Boolean(env.MAILERLITE_API_KEY) },
      media: { configured: Boolean(env.MEDIA) },
      orderPageDefault: `${origin}/`,
    };
  }
  return json(out);
}

export async function updateSettings({ env, request, user }) {
  const body = await readJson(request);
  const allowed = user.role === 'admin' ? { ...MANAGER_KEYS, ...ADMIN_KEYS } : MANAGER_KEYS;
  const values = {};
  for (const [key, type] of Object.entries(allowed)) {
    if (key in body) values[key] = cleanSetting(type, body[key]);
  }
  if (Object.keys(values).length) await env.DB.batch(settingsStatements(env.DB, values));
  return json({ success: true, updated: Object.keys(values) });
}

export async function toggleDelivery({ env }) {
  const settings = await loadSettings(env.DB);
  const open = settings.delivery_active !== '1';
  const values = { delivery_active: open ? '1' : '0' };
  if (open) values.current_order_count = '0';
  await env.DB.batch(settingsStatements(env.DB, values));
  return json({ success: true, active: open });
}

// ─── Subscribers ───

async function subscriberStats(db) {
  const [total, zones, last] = await db.batch([
    db.prepare('SELECT COUNT(*) AS c FROM subscribers'),
    db.prepare("SELECT CASE WHEN zone = '' THEN 'Unknown' ELSE zone END AS zone, COUNT(*) AS count FROM subscribers GROUP BY 1 ORDER BY count DESC"),
    db.prepare("SELECT value FROM settings WHERE key = 'last_subscriber_notify'"),
  ]);
  let lastNotify = null;
  try {
    lastNotify = last.results[0]?.value ? JSON.parse(last.results[0].value) : null;
  } catch {
    lastNotify = null;
  }
  return { total: total.results[0].c, zones: zones.results, lastNotify };
}

export async function listSubscribers({ env }) {
  const { results } = await env.DB.prepare('SELECT * FROM subscribers ORDER BY id DESC').all();
  return json({ subscribers: results, stats: await subscriberStats(env.DB), emailConfigured: emailConfigured(env) });
}

function csvCell(value) {
  let v = String(value ?? '');
  if (/^[=+\-@\t\r]/.test(v)) v = `'${v}`;
  return /[",\n\r]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
}

export async function exportSubscribersCsv({ env }) {
  const { results } = await env.DB.prepare('SELECT name, email, zone, created_at FROM subscribers ORDER BY id').all();
  const lines = [['Name', 'Email', 'Zone', 'Date'], ...results.map((r) => [r.name, r.email, r.zone, r.created_at])];
  const csv = lines.map((row) => row.map(csvCell).join(',')).join('\r\n');
  return new Response(`﻿${csv}\r\n`, {
    headers: {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': `attachment; filename="subscribers-${new Date().toISOString().slice(0, 10)}.csv"`,
      'Cache-Control': 'no-store',
    },
  });
}

export async function deleteSubscriber({ env, params }) {
  const id = intParam(params.id);
  await env.DB.prepare('DELETE FROM subscribers WHERE id = ?1').bind(id).run();
  return json({ success: true });
}

export async function notifySubscribers({ env, request }) {
  if (!emailConfigured(env)) throw new HttpError(503, 'Email is not configured (RESEND_API_KEY missing).');
  const settings = await loadSettings(env.DB);
  const { results } = await env.DB.prepare('SELECT email, name, zone FROM subscribers').all();
  if (!results.length) return json({ success: true, sent: 0, total: 0 });
  const orderUrl = settings.order_page_url || `${siteOrigin(request, env)}/`;
  const emails = results.map((sub) => ({ to: sub.email, ...deliveryOpenEmail(settings, sub, orderUrl) }));
  const sent = await sendBatch(env, emails);
  const record = { date: nowIso(), count: sent, total: results.length };
  await setSetting(env.DB, 'last_subscriber_notify', JSON.stringify(record));
  return json({ success: true, sent, total: results.length });
}

// ─── Users (admin only) ───

export async function listUsers({ env, user }) {
  requireAdmin(user);
  const { results } = await env.DB.prepare('SELECT email, role, created_at FROM admin_users ORDER BY email').all();
  const bootstrap = (env.ADMIN_EMAILS || '').split(',').map((s) => s.trim().toLowerCase()).filter(Boolean);
  return json({ users: results, bootstrapAdmins: bootstrap, you: user.email });
}

export async function saveUser({ env, request, user }) {
  requireAdmin(user);
  const body = await readJson(request);
  const email = str(body.email, 254).toLowerCase();
  if (!isEmail(email)) throw new HttpError(400, 'Enter a valid email.');
  const role = body.role === 'admin' ? 'admin' : 'manager';
  if (email === user.email && role !== 'admin') throw new HttpError(400, "You can't remove your own admin role.");
  await env.DB
    .prepare('INSERT INTO admin_users (email, role) VALUES (?1, ?2) ON CONFLICT(email) DO UPDATE SET role = excluded.role')
    .bind(email, role)
    .run();
  return json({ success: true });
}

export async function deleteUser({ env, params, user }) {
  requireAdmin(user);
  const email = decodeURIComponent(params.email || '').toLowerCase();
  if (email === user.email) throw new HttpError(400, "You can't remove yourself.");
  await env.DB.prepare('DELETE FROM admin_users WHERE email = ?1').bind(email).run();
  return json({ success: true });
}

// ─── Data management (admin only) ───

export async function resetData({ env, request, user }) {
  requireAdmin(user);
  const { scope, confirm } = await readJson(request);
  if (!['orders', 'subscribers', 'factory'].includes(scope)) throw new HttpError(400, 'Invalid reset scope.');
  if (scope === 'factory' && confirm !== 'RESET') throw new HttpError(400, 'Type RESET to confirm a factory reset.');
  const db = env.DB;
  const result = {};
  const stmts = [];
  if (scope === 'orders' || scope === 'factory') {
    result.orders = (await db.prepare('SELECT COUNT(*) AS c FROM orders').first()).c;
    stmts.push(db.prepare('DELETE FROM orders'), ...settingsStatements(db, { current_order_count: 0, last_order_seq: 0 }));
  }
  if (scope === 'subscribers' || scope === 'factory') {
    result.subscribers = (await db.prepare('SELECT COUNT(*) AS c FROM subscribers').first()).c;
    stmts.push(db.prepare('DELETE FROM subscribers'), ...settingsStatements(db, { last_subscriber_notify: '' }));
  }
  await db.batch(stmts);
  return json({ success: true, deleted: result });
}
