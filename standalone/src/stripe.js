import { HttpError } from './http.js';

const STRIPE_API = 'https://api.stripe.com/v1';

async function stripeRequest(secretKey, method, path, params) {
  const init = {
    method,
    headers: { Authorization: `Bearer ${secretKey}`, 'Content-Type': 'application/x-www-form-urlencoded' },
  };
  if (params) init.body = new URLSearchParams(params).toString();
  const res = await fetch(`${STRIPE_API}${path}`, init);
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.error) {
    throw new HttpError(res.status >= 500 ? 502 : 400, data.error?.message || `Stripe error (${res.status})`);
  }
  return data;
}

export function createPaymentIntent(secretKey, { amountCents, name, email, source }) {
  return stripeRequest(secretKey, 'POST', '/payment_intents', {
    amount: String(amountCents),
    currency: 'usd',
    'automatic_payment_methods[enabled]': 'true',
    'metadata[source]': source,
    'metadata[customer_name]': name,
    'metadata[customer_email]': email,
  });
}

export function retrievePaymentIntent(secretKey, id) {
  if (!/^pi_[A-Za-z0-9]+$/.test(id)) throw new HttpError(400, 'Invalid payment reference.');
  return stripeRequest(secretKey, 'GET', `/payment_intents/${id}`);
}

export function refundPaymentIntent(secretKey, id) {
  return stripeRequest(secretKey, 'POST', '/refunds', { payment_intent: id });
}

function hexToBytes(hex) {
  if (!/^[0-9a-f]*$/i.test(hex) || hex.length % 2) return null;
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16);
  return out;
}

/** Verifies a Stripe-Signature header against any of the configured secrets. */
export async function verifyStripeSignature(payload, sigHeader, secrets, toleranceSec = 300) {
  if (!sigHeader || !secrets.length) return false;
  let timestamp = '';
  const signatures = [];
  for (const part of sigHeader.split(',')) {
    const [k, v] = part.split('=', 2);
    if (k === 't') timestamp = v;
    if (k === 'v1' && v) signatures.push(v);
  }
  const ts = Number(timestamp);
  if (!ts || Math.abs(Date.now() / 1000 - ts) > toleranceSec) return false;
  const data = new TextEncoder().encode(`${timestamp}.${payload}`);
  for (const secret of secrets) {
    const key = await crypto.subtle.importKey(
      'raw',
      new TextEncoder().encode(secret),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['verify'],
    );
    for (const sig of signatures) {
      const bytes = hexToBytes(sig);
      if (bytes && (await crypto.subtle.verify('HMAC', key, bytes, data))) return true;
    }
  }
  return false;
}
