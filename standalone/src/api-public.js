import { HttpError, json, readJson, str, isEmail, round2, siteOrigin } from './http.js';
import { loadSettings, deliveryState, stripeConfig, parseSteps, num } from './settings.js';
import { createPaymentIntent, retrievePaymentIntent, refundPaymentIntent, verifyStripeSignature } from './stripe.js';
import { sendEmail, orderConfirmationEmail, adminNotificationEmail } from './email.js';
import { mailerliteSync } from './mailerlite.js';

const NO_CACHE = { 'Cache-Control': 'no-store, no-cache, must-revalidate, max-age=0', Pragma: 'no-cache' };

function formatMoney(n) {
  return Number.isInteger(n) ? `$${n}` : `$${n.toFixed(2)}`;
}

export function localYmd(timeZone, date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timeZone || 'UTC',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);
  const get = (t) => parts.find((p) => p.type === t).value;
  return { y: get('year'), m: get('month'), d: get('day') };
}

function publicContent(settings) {
  const minimum = num(settings.minimum_order, 100);
  return {
    howTitle: settings.how_title || '',
    howSteps: parseSteps(settings.how_steps),
    howNote: (settings.how_note || '').replaceAll('{{minimum}}', formatMoney(minimum)),
    checkoutNote: settings.checkout_note || '',
    confirmTitle: settings.confirm_title || 'Grazie mille!',
    confirmMessage: settings.confirm_message || '',
  };
}

function publicStatus(settings) {
  const del = deliveryState(settings);
  return {
    deliveryActive: del.open,
    atCapacity: del.atCapacity,
    uberEatsUrl: settings.ubereats_url || '',
    closedMessage: settings.closed_message || '',
    minimumOrder: num(settings.minimum_order, 100),
    weeklyZone: settings.weekly_zone || '',
    weeklyDeadline: settings.weekly_deadline || '',
    weeklyDeliveryDay: settings.weekly_delivery_day || '',
    orderCount: del.orderCount,
    maxOrders: del.maxOrders,
  };
}

export async function getMenu({ env }) {
  const settings = await loadSettings(env.DB);
  const { results } = await env.DB.prepare(
    `SELECT p.id, p.name, p.description, p.price, p.image_url, c.slug AS cat_slug, c.name AS cat_name,
            c.icon AS cat_icon, c.sort_order AS cat_order, c.id AS cat_id
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.active = 1
      ORDER BY p.sort_order ASC, p.id ASC`,
  ).all();

  const categories = new Map();
  const products = results.map((p) => {
    const slug = p.cat_slug || 'uncategorized';
    if (!categories.has(slug)) {
      categories.set(slug, {
        slug,
        name: p.cat_name || 'Other',
        icon: p.cat_icon || '',
        order: p.cat_slug ? p.cat_order * 100000 + p.cat_id : Number.MAX_SAFE_INTEGER,
      });
    }
    return {
      id: p.id,
      name: p.name,
      description: p.description,
      price: Number(p.price),
      category: slug,
      image: p.image_url || null,
      imageFull: p.image_url || null,
    };
  });

  const stripe = stripeConfig(env, settings);
  return json(
    {
      categories: [...categories.values()].sort((a, b) => a.order - b.order),
      products,
      ...publicStatus(settings),
      content: publicContent(settings),
      stripe: { enabled: stripe.enabled, publishableKey: stripe.enabled ? stripe.publishableKey : '', testMode: stripe.testMode },
    },
    200,
    NO_CACHE,
  );
}

export async function getStatus({ env }) {
  const settings = await loadSettings(env.DB);
  const { orderCount, maxOrders, ...status } = publicStatus(settings);
  return json(status, 200, NO_CACHE);
}

/** Prices the cart server-side from active products only. */
async function priceCart(db, rawItems) {
  if (!Array.isArray(rawItems) || rawItems.length === 0) throw new HttpError(400, "Field 'items' is required.");
  if (rawItems.length > 100) throw new HttpError(400, 'Too many items.');
  const qtyById = new Map();
  for (const item of rawItems) {
    const id = parseInt(item?.id, 10);
    const qty = Math.min(99, Math.max(1, parseInt(item?.qty, 10) || 1));
    if (id > 0) qtyById.set(id, Math.min(99, (qtyById.get(id) || 0) + qty));
  }
  if (qtyById.size === 0) throw new HttpError(400, 'No valid items.');
  const ids = [...qtyById.keys()];
  const { results } = await db
    .prepare(`SELECT id, name, price FROM products WHERE active = 1 AND id IN (${ids.map(() => '?').join(',')})`)
    .bind(...ids)
    .all();
  const byId = new Map(results.map((r) => [r.id, r]));
  const items = [];
  for (const id of ids) {
    const p = byId.get(id);
    if (p) items.push({ id: p.id, name: p.name, price: Number(p.price), qty: qtyById.get(id) });
  }
  if (items.length === 0) throw new HttpError(400, 'No valid items.');
  const subtotal = round2(items.reduce((s, i) => s + i.price * i.qty, 0));
  return { items, subtotal };
}

function assertOpen(settings) {
  const del = deliveryState(settings);
  if (!del.active) throw new HttpError(403, 'Orders are currently closed.');
  if (del.atCapacity) throw new HttpError(403, "Sorry, we've reached capacity for this delivery round.");
  return del;
}

function assertMinimum(settings, subtotal) {
  const min = num(settings.minimum_order, 100);
  if (subtotal < min) {
    throw new HttpError(400, `Minimum order is ${formatMoney(min)}. Your total is $${subtotal.toFixed(2)}.`);
  }
}

export async function createStripeIntent({ request, env }) {
  const body = await readJson(request);
  const settings = await loadSettings(env.DB);
  const stripe = stripeConfig(env, settings);
  if (!stripe.enabled) throw new HttpError(503, 'Stripe is not configured.');
  assertOpen(settings);
  const { subtotal } = await priceCart(env.DB, body.items);
  assertMinimum(settings, subtotal);
  const amountCents = Math.round(subtotal * 100);
  if (amountCents < 50) throw new HttpError(400, 'Minimum payment is $0.50.');
  const intent = await createPaymentIntent(stripe.secretKey, {
    amountCents,
    name: str(body.name, 200),
    email: str(body.email, 254),
    source: (settings.business_name || 'vs_orders').toLowerCase().replace(/[^a-z0-9]+/g, '_'),
  });
  return json({ clientSecret: intent.client_secret, paymentIntentId: intent.id, amount: subtotal });
}

async function findOrderByIntent(db, pi) {
  return db.prepare('SELECT id, ref, total FROM orders WHERE stripe_payment_intent = ?1').bind(pi).first();
}

async function releaseCapacity(db) {
  await db
    .prepare(
      "UPDATE settings SET value = MAX(CAST(value AS INTEGER) - 1, 0) WHERE key = 'current_order_count'",
    )
    .run();
}

export async function createOrder(ctx) {
  const { request, env } = ctx;
  const db = env.DB;
  const body = await readJson(request);
  const settings = await loadSettings(db);
  const del = assertOpen(settings);

  const fields = {
    name: str(body.name, 200),
    email: str(body.email, 254).toLowerCase(),
    phone: str(body.phone, 50),
    address: str(body.address, 500),
  };
  if (!Array.isArray(body.items) || body.items.length === 0) throw new HttpError(400, "Field 'items' is required.");
  for (const [k, v] of Object.entries(fields)) {
    if (!v) throw new HttpError(400, `Field '${k}' is required.`);
  }
  if (!isEmail(fields.email)) throw new HttpError(400, 'Please enter a valid email.');
  const notes = str(body.notes, 2000);

  const { items, subtotal } = await priceCart(db, body.items);
  assertMinimum(settings, subtotal);

  const stripe = stripeConfig(env, settings);
  let paymentStatus = 'pending';
  let pi = null;
  if (stripe.enabled) {
    pi = str(body.stripe_pi, 100);
    if (!pi) throw new HttpError(402, 'Payment is required to place this order.');
    const existing = await findOrderByIntent(db, pi);
    if (existing) return json({ success: true, ref: existing.ref, order_id: existing.id, total: existing.total });
    const intent = await retrievePaymentIntent(stripe.secretKey, pi);
    if (intent.currency !== 'usd' || intent.amount !== Math.round(subtotal * 100)) {
      throw new HttpError(400, 'Payment amount does not match the order total. Please try again.');
    }
    if (intent.status === 'succeeded') paymentStatus = 'paid';
    else if (intent.status !== 'processing') throw new HttpError(402, 'Payment has not been completed.');
  }

  const reserved = await db
    .prepare(
      `UPDATE settings SET value = CAST(value AS INTEGER) + 1
        WHERE key = 'current_order_count' AND (?1 <= 0 OR CAST(value AS INTEGER) < ?1)
        RETURNING value`,
    )
    .bind(del.maxOrders)
    .first();
  if (!reserved) {
    if (pi) {
      try {
        await refundPaymentIntent(stripe.secretKey, pi);
      } catch (err) {
        console.error('Refund after capacity rejection failed', pi, err);
      }
      throw new HttpError(403, "Sorry, we've reached capacity for this delivery round. Your payment has been refunded.");
    }
    throw new HttpError(403, "Sorry, we've reached capacity for this delivery round.");
  }

  let orderId;
  let ref;
  try {
    const seqRow = await db
      .prepare("UPDATE settings SET value = CAST(value AS INTEGER) + 1 WHERE key = 'last_order_seq' RETURNING value")
      .first();
    const { y, m, d } = localYmd(env.TIMEZONE);
    ref = `VS-${y.slice(2)}${m}${d}-${String(seqRow.value).padStart(4, '0')}`;
    const zone = settings.weekly_zone || str(body.zone, 200);
    const inserted = await db
      .prepare(
        `INSERT INTO orders (ref, subtotal, total, customer_name, customer_email, customer_phone, customer_address,
                             customer_zone, notes, payment_status, stripe_payment_intent)
         VALUES (?1, ?2, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10) RETURNING id`,
      )
      .bind(ref, subtotal, fields.name, fields.email, fields.phone, fields.address, zone, notes, paymentStatus, pi)
      .first();
    orderId = inserted.id;
  } catch (err) {
    await releaseCapacity(db);
    if (pi && /UNIQUE/i.test(String(err?.message))) {
      const existing = await findOrderByIntent(db, pi);
      if (existing) return json({ success: true, ref: existing.ref, order_id: existing.id, total: existing.total });
    }
    throw err;
  }

  const itemStmt = db.prepare('INSERT INTO order_items (order_id, product_id, name, price, qty) VALUES (?1, ?2, ?3, ?4, ?5)');
  await db.batch(items.map((i) => itemStmt.bind(orderId, i.id, i.name, i.price, i.qty)));

  const order = {
    id: orderId,
    ref,
    total: subtotal,
    customer_name: fields.name,
    customer_email: fields.email,
    customer_phone: fields.phone,
    customer_zone: settings.weekly_zone || str(body.zone, 200),
    payment_status: paymentStatus,
  };
  const adminTo = settings.admin_email || (env.ADMIN_EMAILS || '').split(',')[0]?.trim();
  const adminUrl = `${siteOrigin(request, env)}/admin/#/orders/${orderId}`;
  const confirmation = orderConfirmationEmail(settings, order, items);
  const notification = adminNotificationEmail(settings, order, items, adminUrl);
  ctx.waitUntil(
    Promise.all([
      sendEmail(env, { to: order.customer_email, ...confirmation }),
      adminTo ? sendEmail(env, { to: adminTo, ...notification }) : null,
    ]),
  );

  return json({ success: true, ref, order_id: orderId, total: subtotal });
}

export async function subscribe(ctx) {
  const { request, env } = ctx;
  const body = await readJson(request);
  const email = str(body.email, 254).toLowerCase();
  const name = str(body.name, 200);
  const zone = str(body.zone, 200);
  if (!isEmail(email)) throw new HttpError(400, 'Please enter a valid email.');

  const existing = await env.DB.prepare('SELECT id FROM subscribers WHERE email = ?1').bind(email).first();
  let message;
  if (existing) {
    await env.DB
      .prepare(
        "UPDATE subscribers SET name = CASE WHEN ?2 <> '' THEN ?2 ELSE name END, zone = CASE WHEN ?3 <> '' THEN ?3 ELSE zone END WHERE id = ?1",
      )
      .bind(existing.id, name, zone)
      .run();
    message = "You're already subscribed! We'll notify you.";
  } else {
    await env.DB.prepare('INSERT INTO subscribers (email, name, zone) VALUES (?1, ?2, ?3)').bind(email, name, zone).run();
    message = "Subscribed! We'll notify you when delivery opens.";
  }

  if (env.MAILERLITE_API_KEY) {
    const groupRow = await env.DB.prepare("SELECT value FROM settings WHERE key = 'mailerlite_group_id'").first();
    ctx.waitUntil(
      mailerliteSync(env, groupRow?.value || '', { email, name, zone }).then((ok) =>
        ok ? env.DB.prepare('UPDATE subscribers SET ml_synced = 1 WHERE email = ?1').bind(email).run() : null,
      ),
    );
  }
  return json({ success: true, message });
}

export async function stripeWebhook({ request, env }) {
  const payload = await request.text();
  const secrets = [env.STRIPE_TEST_WEBHOOK_SECRET, env.STRIPE_LIVE_WEBHOOK_SECRET].filter(Boolean);
  if (!secrets.length) throw new HttpError(503, 'Stripe webhook secret is not configured.');
  const ok = await verifyStripeSignature(payload, request.headers.get('Stripe-Signature'), secrets);
  if (!ok) throw new HttpError(400, 'Invalid webhook signature.');

  const event = JSON.parse(payload);
  const obj = event?.data?.object || {};
  const now = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
  if (event.type === 'payment_intent.succeeded' && obj.id) {
    await env.DB
      .prepare("UPDATE orders SET payment_status = 'paid', updated_at = ?2 WHERE stripe_payment_intent = ?1 AND payment_status = 'pending'")
      .bind(obj.id, now)
      .run();
  } else if (event.type === 'charge.refunded' && obj.payment_intent && obj.refunded) {
    await env.DB
      .prepare("UPDATE orders SET payment_status = 'refunded', updated_at = ?2 WHERE stripe_payment_intent = ?1")
      .bind(obj.payment_intent, now)
      .run();
  }
  return json({ received: true });
}
