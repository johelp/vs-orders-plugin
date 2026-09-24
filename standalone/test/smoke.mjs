// End-to-end smoke test against a LOCAL dev server (`npm run dev`) with DEV_ADMIN_EMAIL set in .dev.vars
// and Stripe keys NOT set. It creates and deletes data, so it refuses to run against non-local hosts.
import assert from 'node:assert/strict';

const BASE = process.env.BASE_URL || 'http://localhost:8788';
if (!/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(BASE)) {
  console.error(`Refusing to run against ${BASE}: smoke tests mutate data and are for local dev only.`);
  process.exit(1);
}

let passed = 0;
async function step(name, fn) {
  try {
    await fn();
    passed++;
    console.log(`  ✓ ${name}`);
  } catch (err) {
    console.error(`  ✗ ${name}\n    ${err.stack || err}`);
    await restoreSettings().catch(() => {});
    process.exit(1);
  }
}

async function call(method, path, body, { admin = false, raw = false, headers = {} } = {}) {
  const h = { ...headers };
  if (body !== undefined && !(body instanceof FormData)) h['Content-Type'] = 'application/json';
  if (admin && method !== 'GET') h['X-Requested-With'] = 'vso-admin';
  const res = await fetch(BASE + path, {
    method,
    headers: h,
    body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
  });
  if (raw) return res;
  const data = await res.json().catch(() => null);
  return { status: res.status, data };
}
const adminCall = (method, path, body, opts = {}) => call(method, path, body, { ...opts, admin: true });

console.log(`Smoke testing ${BASE}`);

const original = (await adminCall('GET', '/api/admin/settings')).data;
if (!original?.settings) {
  console.error('Could not read admin settings. Is `npm run dev` running with DEV_ADMIN_EMAIL in .dev.vars?');
  process.exit(1);
}
async function restoreSettings() {
  await adminCall('PUT', '/api/admin/settings', original.settings);
  const now = (await adminCall('GET', '/api/admin/settings')).data;
  if (now.delivery.active !== original.delivery.active) await adminCall('POST', '/api/admin/delivery/toggle', {});
}

let menu;
let orderId;
let productId;
let categoryId;

await step('admin session resolves via dev bypass', async () => {
  const { status, data } = await adminCall('GET', '/api/admin/me');
  assert.equal(status, 200);
  assert.equal(data.role, 'admin');
  assert.ok(data.statuses.new);
});

await step('mutations without X-Requested-With are rejected', async () => {
  const { status } = await call('POST', '/api/admin/delivery/toggle', {});
  assert.equal(status, 403);
});

await step('reset orders and subscribers for a clean run', async () => {
  const { status } = await adminCall('POST', '/api/admin/reset', { scope: 'factory', confirm: 'RESET' });
  assert.equal(status, 200);
});

await step('configure weekly delivery (min $30, max 2 orders)', async () => {
  const { status, data } = await adminCall('PUT', '/api/admin/settings', {
    weekly_zone: 'Westchester County, NY',
    weekly_delivery_day: 'Saturday',
    weekly_deadline: 'Thursday 8pm',
    minimum_order: 30,
    max_orders: 2,
    how_steps: [{ title: 'Step A', desc: 'Desc A' }, { title: '', desc: 'dropped' }],
    delivery_active: '1',
  });
  assert.equal(status, 200);
  assert.ok(!data.updated.includes('delivery_active'), 'delivery_active must not be settable via settings');
});

await step('orders are rejected while delivery is closed', async () => {
  const s = await adminCall('GET', '/api/admin/settings');
  if (s.data.delivery.active) await adminCall('POST', '/api/admin/delivery/toggle', {});
  const { status, data } = await call('POST', '/api/orders', { items: [{ id: 1, qty: 5 }], name: 'A', email: 'a@b.co', phone: '1', address: 'x' });
  assert.equal(status, 403, JSON.stringify(data));
});

await step('open delivery', async () => {
  const { data } = await adminCall('POST', '/api/admin/delivery/toggle', {});
  assert.equal(data.active, true);
  const status = await call('GET', '/api/status');
  assert.equal(status.data.deliveryActive, true);
});

await step('public menu includes content and stripe flags', async () => {
  const { status, data } = await call('GET', '/api/products');
  assert.equal(status, 200);
  assert.ok(data.products.length >= 1);
  assert.equal(data.content.howSteps.length, 1);
  assert.equal(data.stripe.enabled, false);
  assert.equal(data.minimumOrder, 30);
  menu = data;
});

await step('order below minimum is rejected', async () => {
  const cheap = menu.products.find((p) => p.price < 30);
  const { status, data } = await call('POST', '/api/orders', { items: [{ id: cheap.id, qty: 1 }], name: 'A', email: 'a@b.co', phone: '1', address: 'x' });
  assert.equal(status, 400);
  assert.match(data.error, /Minimum order/);
});

await step('order with invalid email is rejected', async () => {
  const { status } = await call('POST', '/api/orders', { items: [{ id: menu.products[0].id, qty: 9 }], name: 'A', email: 'nope', phone: '1', address: 'x' });
  assert.equal(status, 400);
});

await step('order is priced server-side (client price ignored)', async () => {
  const p = menu.products[0];
  const qty = Math.ceil(30 / p.price) + 1;
  const { status, data } = await call('POST', '/api/orders', {
    items: [{ id: p.id, qty, price: 0.01 }, { id: 999999, qty: 1 }],
    name: 'Maria <b>Rossi</b>',
    email: 'Maria@Example.com',
    phone: '555-0100',
    address: '1 Main St',
    notes: 'Buzzer 4',
    payment_status: 'paid',
  });
  assert.equal(status, 200, JSON.stringify(data));
  assert.match(data.ref, /^VS-\d{6}-0001$/);
  assert.equal(data.total, Math.round(p.price * qty * 100) / 100);
  orderId = data.order_id;
});

await step('client cannot self-mark an order as paid', async () => {
  const { data } = await adminCall('GET', `/api/admin/orders/${orderId}`);
  assert.equal(data.order.payment_status, 'pending');
  assert.equal(data.order.customer_email, 'maria@example.com');
  assert.equal(data.order.customer_zone, 'Westchester County, NY');
  assert.equal(data.items.length, 1);
});

await step('capacity is enforced (max 2)', async () => {
  const p = menu.products[0];
  const qty = Math.ceil(30 / p.price) + 1;
  const body = { items: [{ id: p.id, qty }], name: 'B', email: 'b@example.com', phone: '1', address: 'x' };
  assert.equal((await call('POST', '/api/orders', body)).status, 200);
  const third = await call('POST', '/api/orders', body);
  assert.equal(third.status, 403);
  const status = await call('GET', '/api/status');
  assert.equal(status.data.atCapacity, true);
  assert.equal(status.data.deliveryActive, false);
});

await step('reopening delivery resets the round counter', async () => {
  await adminCall('POST', '/api/admin/delivery/toggle', {});
  await adminCall('POST', '/api/admin/delivery/toggle', {});
  const status = await call('GET', '/api/status');
  assert.equal(status.data.deliveryActive, true);
});

await step('order list, filters and search', async () => {
  const all = await adminCall('GET', '/api/admin/orders');
  assert.equal(all.data.total, 2);
  assert.deepEqual(all.data.zones, ['Westchester County, NY']);
  const search = await adminCall('GET', '/api/admin/orders?q=maria');
  assert.equal(search.data.total, 1);
  const byStatus = await adminCall('GET', '/api/admin/orders?status=delivered');
  assert.equal(byStatus.data.total, 0);
});

await step('status and payment updates', async () => {
  const bad = await adminCall('POST', `/api/admin/orders/${orderId}/status`, { status: 'shipped' });
  assert.equal(bad.status, 400);
  const ok = await adminCall('POST', `/api/admin/orders/${orderId}/status`, { status: 'confirmed' });
  assert.equal(ok.data.status, 'confirmed');
  const pay = await adminCall('POST', `/api/admin/orders/${orderId}/payment`, { payment_status: 'paid' });
  assert.equal(pay.data.payment_status, 'paid');
});

await step('messaging logs the message (failed without email provider)', async () => {
  const { data } = await adminCall('POST', `/api/admin/orders/${orderId}/messages`, { body: 'Arriving at 3pm' });
  assert.equal(data.data.body, 'Arriving at 3pm');
  const thread = await adminCall('GET', `/api/admin/orders/${orderId}/messages`);
  assert.equal(thread.data.length, 1);
});

await step('dashboard stats', async () => {
  const { data } = await adminCall('GET', '/api/admin/dashboard');
  assert.equal(data.stats.totalOrders, 2);
  assert.equal(data.stats.todayOrders, 2);
  assert.equal(data.statusCounts.confirmed, 1);
  assert.equal(data.recent.length, 2);
});

await step('category CRUD + reorder', async () => {
  const created = await adminCall('POST', '/api/admin/categories', { name: 'Specials', icon: '⭐' });
  assert.equal(created.status, 201);
  categoryId = created.data.category.id;
  assert.equal(created.data.category.slug, 'specials');
  const dup = await adminCall('POST', '/api/admin/categories', { name: 'Specials' });
  assert.equal(dup.data.category.slug, 'specials-2');
  await adminCall('DELETE', `/api/admin/categories/${dup.data.category.id}`);
  const list = await adminCall('GET', '/api/admin/categories');
  const ids = list.data.map((c) => c.id);
  const reordered = [categoryId, ...ids.filter((id) => id !== categoryId)];
  await adminCall('POST', '/api/admin/categories/reorder', { ids: reordered });
  const after = await adminCall('GET', '/api/admin/categories');
  assert.equal(after.data[0].id, categoryId);
});

await step('image upload to R2 and serving from /media', async () => {
  const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), (c) => c.charCodeAt(0));
  const form = new FormData();
  form.append('file', new Blob([png], { type: 'image/png' }), 'dot.png');
  const up = await adminCall('POST', '/api/admin/uploads', form);
  assert.equal(up.status, 201, JSON.stringify(up.data));
  const res = await fetch(BASE + up.data.url);
  assert.equal(res.status, 200);
  assert.equal(res.headers.get('content-type'), 'image/png');
  globalThis.uploadedUrl = up.data.url;
});

await step('product CRUD + toggle + bulk toggle', async () => {
  const created = await adminCall('POST', '/api/admin/products', {
    name: 'Cassata Special', description: 'Test', price: 12.5, category_id: categoryId, image_url: globalThis.uploadedUrl,
  });
  assert.equal(created.status, 201, JSON.stringify(created.data));
  productId = created.data.product.id;
  const badImg = await adminCall('PUT', `/api/admin/products/${productId}`, { image_url: 'javascript:alert(1)' });
  assert.equal(badImg.status, 400);
  const upd = await adminCall('PUT', `/api/admin/products/${productId}`, { price: 13 });
  assert.equal(upd.data.product.price, 13);
  const menuNow = await call('GET', '/api/products');
  assert.equal(menuNow.data.categories[0].slug, 'specials');
  const t = await adminCall('POST', `/api/admin/products/${productId}/toggle`, {});
  assert.equal(t.data.active, false);
  const bulk = await adminCall('POST', '/api/admin/products/bulk-toggle', { ids: [productId], active: true });
  assert.equal(bulk.data.count, 1);
  const cantDelete = await adminCall('DELETE', `/api/admin/categories/${categoryId}`);
  assert.equal(cantDelete.status, 400);
  await adminCall('DELETE', `/api/admin/products/${productId}`);
  const gone = await fetch(BASE + globalThis.uploadedUrl);
  assert.equal(gone.status, 404, 'deleting a product removes its uploaded image');
  assert.equal((await adminCall('DELETE', `/api/admin/categories/${categoryId}`)).status, 200);
});

await step('subscribe (new, duplicate, invalid) + stats + CSV', async () => {
  const a = await call('POST', '/api/subscribe', { email: 'sub@example.com', name: 'Sub', zone: 'Brooklyn' });
  assert.match(a.data.message, /Subscribed/);
  const b = await call('POST', '/api/subscribe', { email: 'SUB@example.com', zone: 'Queens' });
  assert.match(b.data.message, /already/);
  const c = await call('POST', '/api/subscribe', { email: 'bad' });
  assert.equal(c.status, 400);
  const list = await adminCall('GET', '/api/admin/subscribers');
  assert.equal(list.data.stats.total, 1);
  assert.equal(list.data.subscribers[0].zone, 'Queens');
  assert.equal(list.data.subscribers[0].name, 'Sub');
  const csv = await call('GET', '/api/admin/subscribers.csv', undefined, { raw: true });
  const text = await csv.text();
  assert.match(text, /sub@example\.com/);
});

await step('notify subscribers fails clearly without an email provider', async () => {
  const { status, data } = await adminCall('POST', '/api/admin/subscribers/notify', {});
  assert.equal(status, 503);
  assert.match(data.error, /RESEND_API_KEY/);
});

await step('user management', async () => {
  assert.equal((await adminCall('POST', '/api/admin/users', { email: 'mgr@example.com', role: 'manager' })).status, 200);
  const list = await adminCall('GET', '/api/admin/users');
  assert.ok(list.data.users.some((u) => u.email === 'mgr@example.com' && u.role === 'manager'));
  assert.equal((await adminCall('DELETE', '/api/admin/users/mgr%40example.com')).status, 200);
});

await step('stripe endpoints refuse when not configured / unsigned', async () => {
  assert.equal((await call('POST', '/api/stripe/create-intent', { items: [] })).status, 503);
  const wh = await call('POST', '/api/stripe/webhook', { type: 'payment_intent.succeeded' });
  assert.ok([400, 503].includes(wh.status));
});

await step('unknown routes 404, wrong methods 405', async () => {
  assert.equal((await call('GET', '/api/nope')).status, 404);
  assert.equal((await call('DELETE', '/api/products')).status, 405);
});

await step('factory reset clears orders and subscribers', async () => {
  const bad = await adminCall('POST', '/api/admin/reset', { scope: 'factory' });
  assert.equal(bad.status, 400);
  const { data } = await adminCall('POST', '/api/admin/reset', { scope: 'factory', confirm: 'RESET' });
  assert.equal(data.deleted.orders, 2);
  assert.equal(data.deleted.subscribers, 1);
});

await restoreSettings();
console.log(`\nAll ${passed} checks passed.`);
