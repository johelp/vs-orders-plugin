// Offline unit tests for payment verification, webhooks, emails and Cloudflare Access auth.
// Runs the real migration SQL on node:sqlite through a minimal D1-compatible adapter; network calls are mocked.
// Run: npm test
import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import { createOrder, createStripeIntent, stripeWebhook } from '../src/api-public.js';
import { requireUser } from '../src/auth.js';
import { handleApi } from '../src/router.js';

const root = new URL('..', import.meta.url);

// ─── Minimal D1 adapter ───
class Stmt {
  constructor(db, sql, params = []) {
    this.db = db;
    this.sql = sql;
    this.params = params;
  }
  bind(...params) {
    return new Stmt(this.db, this.sql, params.map((p) => (p === undefined ? null : p)));
  }
  _plain(row) {
    return row ? { ...row } : null;
  }
  async first() {
    return this._plain(this.db.prepare(this.sql).get(...this.params));
  }
  async all() {
    return { results: this.db.prepare(this.sql).all(...this.params).map((r) => ({ ...r })) };
  }
  async run() {
    const info = this.db.prepare(this.sql).run(...this.params);
    return { meta: { changes: Number(info.changes) } };
  }
}
function makeD1() {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON;');
  db.exec(readFileSync(new URL('migrations/0001_init.sql', root), 'utf8'));
  db.exec(readFileSync(new URL('seed/menu.sql', root), 'utf8'));
  return {
    raw: db,
    prepare: (sql) => new Stmt(db, sql),
    async batch(stmts) {
      db.exec('BEGIN');
      try {
        const out = stmts.map((s) => ({ results: db.prepare(s.sql).all(...s.params).map((r) => ({ ...r })) }));
        db.exec('COMMIT');
        return out;
      } catch (e) {
        db.exec('ROLLBACK');
        throw e;
      }
    },
  };
}

// ─── fetch mock ───
const realFetch = globalThis.fetch;
let routes;
let calls;
function mockFetch(handlers) {
  routes = handlers;
  calls = [];
  globalThis.fetch = async (input, init = {}) => {
    const url = typeof input === 'string' ? input : input.url;
    const method = (init.method || 'GET').toUpperCase();
    calls.push({ url, method, body: init.body });
    for (const [pattern, fn] of routes) {
      if (url.includes(pattern)) {
        const { status = 200, body } = await fn({ url, method, body: init.body });
        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
      }
    }
    throw new Error(`Unmocked fetch: ${method} ${url}`);
  };
}

let env;
let pending;
beforeEach(() => {
  env = {
    DB: makeD1(),
    TIMEZONE: 'America/New_York',
    STRIPE_TEST_PUBLISHABLE_KEY: 'pk_test_x',
    STRIPE_TEST_SECRET_KEY: 'sk_test_x',
    STRIPE_TEST_WEBHOOK_SECRET: 'whsec_test',
    RESEND_API_KEY: 're_test',
    EMAIL_FROM: 'Shop <orders@example.com>',
  };
  env.DB.raw.exec("UPDATE settings SET value = '1' WHERE key = 'delivery_active'; UPDATE settings SET value = '20' WHERE key = 'minimum_order'; UPDATE settings SET value = 'admin@example.com' WHERE key = 'admin_email'; UPDATE settings SET value = 'Queens' WHERE key = 'weekly_zone';");
  pending = [];
});
afterEach(() => {
  globalThis.fetch = realFetch;
});

const ctx = (path, body, headers = {}) => ({
  request: new Request(`https://shop.example.com${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  }),
  env,
  waitUntil: (p) => pending.push(p),
});
const bodyOf = async (res) => ({ status: res.status, data: await res.json() });
async function expectHttpError(promise, status) {
  await assert.rejects(promise, (err) => err.status === status || assert.fail(`expected ${status}, got ${err.status}: ${err.message}`));
}

// Product 13 = Spaghetti Pomodoro $16, product 14 = Tagliatelle Bolognese $19 (seed order).
const ITEMS = [{ id: 13, qty: 1 }, { id: 14, qty: 1 }];
const TOTAL_CENTS = 3500;
const customer = { name: 'Ana <b>Bold</b>', email: 'ana@example.com', phone: '555', address: '1 Main St' };

function stripeRoutes(intents) {
  return [
    ['api.stripe.com/v1/payment_intents/', ({ url }) => {
      const id = url.split('/').pop();
      return intents[id] ? { body: intents[id] } : { status: 404, body: { error: { message: 'No such payment_intent' } } };
    }],
    ['api.stripe.com/v1/refunds', ({ body }) => ({ body: { id: 're_1', payment_intent: new URLSearchParams(body).get('payment_intent') } })],
    ['api.stripe.com/v1/payment_intents', ({ body }) => {
      const p = new URLSearchParams(body);
      return { body: { id: 'pi_new', client_secret: 'pi_new_secret', amount: Number(p.get('amount')) } };
    }],
    ['api.resend.com/emails', () => ({ body: { id: 'email_1' } })],
  ];
}

test('create-intent charges the server-computed total, not a client amount', async () => {
  mockFetch(stripeRoutes({}));
  const res = await bodyOf(await createStripeIntent(ctx('/api/stripe/create-intent', { items: ITEMS, amount: 0.5 })));
  assert.equal(res.data.paymentIntentId, 'pi_new');
  const sent = new URLSearchParams(calls.find((c) => c.url.endsWith('/payment_intents')).body);
  assert.equal(sent.get('amount'), String(TOTAL_CENTS));
  assert.equal(sent.get('currency'), 'usd');
});

test('create-intent refuses carts below the minimum', async () => {
  mockFetch(stripeRoutes({}));
  await expectHttpError(createStripeIntent(ctx('/api/stripe/create-intent', { items: [{ id: 13, qty: 1 }] })), 400);
});

test('paid order: verifies the PaymentIntent with Stripe and marks it paid', async () => {
  mockFetch(stripeRoutes({ pi_ok: { id: 'pi_ok', amount: TOTAL_CENTS, currency: 'usd', status: 'succeeded' } }));
  const res = await bodyOf(await createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: 'pi_ok' })));
  assert.equal(res.status, 200);
  const order = env.DB.raw.prepare('SELECT * FROM orders WHERE id = ?').get(res.data.order_id);
  assert.equal(order.payment_status, 'paid');
  assert.equal(order.customer_zone, 'Queens');
  assert.equal(order.total, 35);

  await Promise.all(pending);
  const emails = calls.filter((c) => c.url.includes('resend')).map((c) => JSON.parse(c.body));
  assert.deepEqual(emails.map((e) => e.to[0]).sort(), ['admin@example.com', 'ana@example.com']);
  const confirmation = emails.find((e) => e.to[0] === 'ana@example.com');
  assert.match(confirmation.html, /Ana &lt;b&gt;Bold&lt;\/b&gt;/, 'customer input is HTML-escaped in emails');
  assert.match(confirmation.subject, new RegExp(res.data.ref));
});

test('re-submitting the same PaymentIntent returns the existing order (no duplicate)', async () => {
  mockFetch(stripeRoutes({ pi_ok: { id: 'pi_ok', amount: TOTAL_CENTS, currency: 'usd', status: 'succeeded' } }));
  const a = await bodyOf(await createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: 'pi_ok' })));
  const b = await bodyOf(await createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: 'pi_ok' })));
  assert.equal(a.data.ref, b.data.ref);
  assert.equal(env.DB.raw.prepare('SELECT COUNT(*) AS c FROM orders').get().c, 1);
  assert.equal(env.DB.raw.prepare("SELECT value FROM settings WHERE key = 'current_order_count'").get().value, '1');
});

test('orders are rejected when the PaymentIntent is missing, unpaid or for a different amount', async () => {
  mockFetch(stripeRoutes({
    pi_unpaid: { id: 'pi_unpaid', amount: TOTAL_CENTS, currency: 'usd', status: 'requires_payment_method' },
    pi_cheap: { id: 'pi_cheap', amount: 50, currency: 'usd', status: 'succeeded' },
  }));
  await expectHttpError(createOrder(ctx('/api/orders', { ...customer, items: ITEMS })), 402);
  await expectHttpError(createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: 'pi_unpaid' })), 402);
  await expectHttpError(createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: 'pi_cheap' })), 400);
  await expectHttpError(createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: '../../charges' })), 400);
  assert.equal(env.DB.raw.prepare('SELECT COUNT(*) AS c FROM orders').get().c, 0);
});

test('processing payments (e.g. bank redirects) create a pending order the webhook later marks paid', async () => {
  mockFetch(stripeRoutes({ pi_proc: { id: 'pi_proc', amount: TOTAL_CENTS, currency: 'usd', status: 'processing' } }));
  const res = await bodyOf(await createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: 'pi_proc' })));
  const status = () => env.DB.raw.prepare('SELECT payment_status FROM orders WHERE id = ?').get(res.data.order_id).payment_status;
  assert.equal(status(), 'pending');

  const payload = JSON.stringify({ type: 'payment_intent.succeeded', data: { object: { id: 'pi_proc' } } });
  const sign = async (secret, ts = Math.floor(Date.now() / 1000)) => {
    const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const sig = Buffer.from(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(`${ts}.${payload}`))).toString('hex');
    return `t=${ts},v1=${sig}`;
  };
  await expectHttpError(stripeWebhook(ctx('/api/stripe/webhook', payload, { 'Stripe-Signature': await sign('whsec_wrong') })), 400);
  await expectHttpError(stripeWebhook(ctx('/api/stripe/webhook', payload, { 'Stripe-Signature': await sign('whsec_test', 1000) })), 400);
  assert.equal(status(), 'pending');
  const ok = await stripeWebhook(ctx('/api/stripe/webhook', payload, { 'Stripe-Signature': await sign('whsec_test') }));
  assert.equal(ok.status, 200);
  assert.equal(status(), 'paid');
});

test('a paid order that hits capacity is refunded automatically', async () => {
  env.DB.raw.exec("UPDATE settings SET value = '1' WHERE key = 'max_orders'; UPDATE settings SET value = '0' WHERE key = 'current_order_count';");
  mockFetch(stripeRoutes({
    pi_a: { id: 'pi_a', amount: TOTAL_CENTS, currency: 'usd', status: 'succeeded' },
    pi_b: { id: 'pi_b', amount: TOTAL_CENTS, currency: 'usd', status: 'succeeded' },
  }));
  await createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: 'pi_a' }));
  // Race: pi_b's customer passed the initial capacity check, but another order fills the round
  // (count 1 → 2 of 2) right before pi_b reserves its slot.
  env.DB.raw.exec("UPDATE settings SET value = '2' WHERE key = 'max_orders';");
  const origPrepare = env.DB.prepare;
  env.DB.prepare = (sql) => {
    if (sql.includes("key = 'current_order_count' AND")) env.DB.raw.exec("UPDATE settings SET value = '2' WHERE key = 'current_order_count';");
    return origPrepare(sql);
  };
  await assert.rejects(createOrder(ctx('/api/orders', { ...customer, items: ITEMS, stripe_pi: 'pi_b' })), /refunded/);
  const refund = calls.find((c) => c.url.includes('/refunds'));
  assert.equal(new URLSearchParams(refund.body).get('payment_intent'), 'pi_b');
});

test('without Stripe keys, orders are accepted as pending and client payment_status is ignored', async () => {
  delete env.STRIPE_TEST_PUBLISHABLE_KEY;
  delete env.STRIPE_TEST_SECRET_KEY;
  mockFetch(stripeRoutes({}));
  const res = await bodyOf(await createOrder(ctx('/api/orders', { ...customer, items: ITEMS, payment_status: 'paid' })));
  const order = env.DB.raw.prepare('SELECT payment_status FROM orders WHERE id = ?').get(res.data.order_id);
  assert.equal(order.payment_status, 'pending');
  assert.equal(calls.filter((c) => c.url.includes('stripe')).length, 0);
});

// ─── Cloudflare Access ───

async function accessSetup() {
  const { privateKey, publicKey } = await crypto.subtle.generateKey(
    { name: 'RSASSA-PKCS1-v1_5', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
    true,
    ['sign', 'verify'],
  );
  const kid = `k-${crypto.randomUUID()}`;
  const jwk = { ...(await crypto.subtle.exportKey('jwk', publicKey)), kid, alg: 'RS256', use: 'sig' };
  mockFetch([['team.cloudflareaccess.com/cdn-cgi/access/certs', () => ({ body: { keys: [jwk] } })]]);
  const b64 = (obj) => Buffer.from(JSON.stringify(obj)).toString('base64url');
  const sign = async (claims, keyId = kid, key = privateKey) => {
    const now = Math.floor(Date.now() / 1000);
    const data = `${b64({ alg: 'RS256', kid: keyId, typ: 'JWT' })}.${b64({ aud: ['aud123'], iss: 'https://team.cloudflareaccess.com', iat: now, exp: now + 3600, email: 'boss@example.com', ...claims })}`;
    const sig = Buffer.from(await crypto.subtle.sign('RSASSA-PKCS1-v1_5', key, new TextEncoder().encode(data))).toString('base64url');
    return `${data}.${sig}`;
  };
  env.ACCESS_TEAM_DOMAIN = 'team.cloudflareaccess.com';
  env.ACCESS_AUD = 'aud123';
  return { sign };
}
const adminReq = (token, url = 'https://shop.example.com/api/admin/me', extra = {}) =>
  new Request(url, { headers: token ? { 'Cf-Access-Jwt-Assertion': token, ...extra } : extra });

test('Access: valid token + admin_users row resolves the role', async () => {
  const { sign } = await accessSetup();
  env.DB.raw.exec("INSERT INTO admin_users (email, role) VALUES ('boss@example.com', 'manager')");
  const user = await requireUser(adminReq(await sign({ email: 'Boss@Example.com' })), env);
  assert.deepEqual(user, { email: 'boss@example.com', role: 'manager' });
});

test('Access: token from the CF_Authorization cookie works too; ADMIN_EMAILS grants admin', async () => {
  const { sign } = await accessSetup();
  env.ADMIN_EMAILS = 'someone@x.com, boss@example.com';
  const token = await sign({});
  const user = await requireUser(adminReq(null, undefined, { Cookie: `a=b; CF_Authorization=${token}` }), env);
  assert.equal(user.role, 'admin');
});

test('Access: rejects missing, forged, expired, wrong-audience and wrong-issuer tokens', async () => {
  const { sign } = await accessSetup();
  env.ADMIN_EMAILS = 'boss@example.com';
  const other = await crypto.subtle.generateKey(
    { name: 'RSASSA-PKCS1-v1_5', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
    true,
    ['sign', 'verify'],
  );
  await expectHttpError(requireUser(adminReq(null), env), 401);
  await expectHttpError(requireUser(adminReq(await sign({}, undefined, other.privateKey)), env), 401);
  await expectHttpError(requireUser(adminReq(await sign({ exp: Math.floor(Date.now() / 1000) - 10 })), env), 401);
  await expectHttpError(requireUser(adminReq(await sign({ aud: ['other'] })), env), 401);
  await expectHttpError(requireUser(adminReq(await sign({ iss: 'https://evil.cloudflareaccess.com' })), env), 401);
  await expectHttpError(requireUser(adminReq(await sign({}, 'unknown-kid')), env), 401);
});

test('Access: authenticated but unlisted users get 403', async () => {
  const { sign } = await accessSetup();
  await expectHttpError(requireUser(adminReq(await sign({ email: 'stranger@example.com' })), env), 403);
});

test('DEV_ADMIN_EMAIL bypass only works on localhost and only without Access configured', async () => {
  env.DEV_ADMIN_EMAIL = 'dev@example.com';
  const local = await requireUser(new Request('http://localhost:8788/api/admin/me'), env);
  assert.equal(local.role, 'admin');
  await expectHttpError(requireUser(new Request('https://shop.example.com/api/admin/me'), env), 500);
  await accessSetup();
  await expectHttpError(requireUser(new Request('http://localhost:8788/api/admin/me'), env), 401);
});

test('router: CORS only for allowed origins on public routes, never on admin routes', async () => {
  env.ALLOWED_ORIGINS = 'https://www.example.com';
  env.DEV_ADMIN_EMAIL = 'dev@example.com';
  const call = (url, init = {}) => handleApi({ request: new Request(url, init), env, waitUntil: () => {} });
  const ok = await call('https://shop.example.com/api/status', { headers: { Origin: 'https://www.example.com' } });
  assert.equal(ok.headers.get('Access-Control-Allow-Origin'), 'https://www.example.com');
  const bad = await call('https://shop.example.com/api/status', { headers: { Origin: 'https://evil.com' } });
  assert.equal(bad.headers.get('Access-Control-Allow-Origin'), null);
  const pre = await call('https://shop.example.com/api/orders', { method: 'OPTIONS', headers: { Origin: 'https://www.example.com' } });
  assert.equal(pre.status, 204);
  const adminCors = await call('http://localhost:8788/api/admin/me', { headers: { Origin: 'https://www.example.com' } });
  assert.equal(adminCors.headers.get('Access-Control-Allow-Origin'), null);
});
