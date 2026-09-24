export const ORDER_STATUSES = {
  new:       { label: 'New',       color: '#3498db', bg: '#ebf5fb' },
  confirmed: { label: 'Confirmed', color: '#f39c12', bg: '#fef9e7' },
  preparing: { label: 'Preparing', color: '#e67e22', bg: '#fdf2e9' },
  delivered: { label: 'Delivered', color: '#27ae60', bg: '#eafaf1' },
  cancelled: { label: 'Cancelled', color: '#e74c3c', bg: '#fdedec' },
};

export const PAYMENT_STATUSES = ['pending', 'paid', 'refunded'];

// Editable by delivery managers and admins (mirrors the WP settings page).
export const MANAGER_KEYS = {
  weekly_zone: 200,
  weekly_delivery_day: 200,
  weekly_deadline: 200,
  minimum_order: 'number',
  max_orders: 'int',
  ubereats_url: 'url',
  closed_message: 2000,
  how_title: 200,
  how_steps: 'steps',
  how_note: 500,
  checkout_note: 1000,
  confirm_title: 200,
  confirm_message: 1000,
};

// Admin-only settings.
export const ADMIN_KEYS = {
  admin_email: 'email',
  stripe_mode: 'stripe_mode',
  mailerlite_group_id: 100,
  business_name: 200,
  business_address: 300,
  business_phone: 100,
  business_website: 200,
  business_instagram: 100,
  order_page_url: 'url',
};

export async function loadSettings(db) {
  const { results } = await db.prepare('SELECT key, value FROM settings').all();
  const s = {};
  for (const row of results) s[row.key] = row.value;
  return s;
}

export async function setSetting(db, key, value) {
  await db
    .prepare('INSERT INTO settings (key, value) VALUES (?1, ?2) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
    .bind(key, String(value))
    .run();
}

export function settingsStatements(db, values) {
  const stmt = db.prepare(
    'INSERT INTO settings (key, value) VALUES (?1, ?2) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
  );
  return Object.entries(values).map(([k, v]) => stmt.bind(k, String(v)));
}

export function num(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

export function parseSteps(value) {
  try {
    const steps = JSON.parse(value || '[]');
    return Array.isArray(steps) ? steps : [];
  } catch {
    return [];
  }
}

export function stripeConfig(env, settings) {
  const mode = settings.stripe_mode === 'live' ? 'live' : 'test';
  const prefix = mode === 'live' ? 'STRIPE_LIVE_' : 'STRIPE_TEST_';
  const publishableKey = env[prefix + 'PUBLISHABLE_KEY'] || '';
  const secretKey = env[prefix + 'SECRET_KEY'] || '';
  return {
    mode,
    publishableKey,
    secretKey,
    enabled: Boolean(publishableKey && secretKey),
    testMode: mode === 'test',
  };
}

export function deliveryState(settings) {
  const active = settings.delivery_active === '1';
  const maxOrders = Math.trunc(num(settings.max_orders));
  const orderCount = Math.trunc(num(settings.current_order_count));
  const atCapacity = maxOrders > 0 && orderCount >= maxOrders;
  return { active, maxOrders, orderCount, atCapacity, open: active && !atCapacity };
}
