import { ORDER_STATUSES } from './settings.js';

const RESEND_URL = 'https://api.resend.com';

function fromAddress(env) {
  return env.EMAIL_FROM || 'Orders <onboarding@resend.dev>';
}

export function emailConfigured(env) {
  return Boolean(env.RESEND_API_KEY);
}

export async function sendEmail(env, { to, subject, html, replyTo }) {
  if (!emailConfigured(env)) {
    console.log(`[email skipped — RESEND_API_KEY not set] to=${to} subject=${subject}`);
    return { ok: false, error: 'Email is not configured (RESEND_API_KEY missing).' };
  }
  try {
    const res = await fetch(`${RESEND_URL}/emails`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${env.RESEND_API_KEY}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        from: fromAddress(env),
        to: [to],
        subject,
        html,
        reply_to: replyTo || env.EMAIL_REPLY_TO || undefined,
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      console.error('Resend error', res.status, JSON.stringify(data));
      return { ok: false, error: data.message || `Email provider returned ${res.status}` };
    }
    return { ok: true, id: data.id || '' };
  } catch (err) {
    console.error('Resend request failed', err);
    return { ok: false, error: String(err?.message || err) };
  }
}

/** Sends up to 100 emails per request via Resend's batch endpoint. Returns the number accepted. */
export async function sendBatch(env, emails) {
  if (!emailConfigured(env)) {
    console.log(`[batch email skipped — RESEND_API_KEY not set] ${emails.length} emails`);
    return 0;
  }
  let sent = 0;
  for (let i = 0; i < emails.length; i += 100) {
    const chunk = emails.slice(i, i + 100).map((e) => ({
      from: fromAddress(env),
      to: [e.to],
      subject: e.subject,
      html: e.html,
      reply_to: env.EMAIL_REPLY_TO || undefined,
    }));
    try {
      const res = await fetch(`${RESEND_URL}/emails/batch`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${env.RESEND_API_KEY}`, 'Content-Type': 'application/json' },
        body: JSON.stringify(chunk),
      });
      if (res.ok) sent += chunk.length;
      else console.error('Resend batch error', res.status, await res.text());
    } catch (err) {
      console.error('Resend batch failed', err);
    }
  }
  return sent;
}

// ─── Templates ───

export function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

const money = (n) => `$${Number(n).toFixed(2)}`;

function header(biz, size = 24, subtitle = '') {
  return `<div style="background:#2C1810;padding:24px;text-align:center;">
    <h1 style="color:#D4AF37;font-size:${size}px;margin:0;letter-spacing:2px;">${esc(biz.business_name).toUpperCase()}</h1>
    ${subtitle ? `<p style="color:rgba(212,175,55,0.5);font-size:11px;margin:6px 0 0;letter-spacing:3px;">${subtitle}</p>` : ''}
  </div>`;
}

function footer(biz) {
  const parts = [biz.business_name, biz.business_address].filter(Boolean).map(esc).join(' — ');
  return `<div style="background:#2C1810;padding:16px;text-align:center;">
    <p style="color:#8B7355;font-size:12px;margin:0;">${parts}${biz.business_phone ? ` | ${esc(biz.business_phone)}` : ''}</p>
  </div>`;
}

function wrap(inner) {
  return `<div style="max-width:600px;margin:0 auto;font-family:Georgia,serif;color:#2C1810;">${inner}</div>`;
}

export function orderConfirmationEmail(biz, order, items) {
  const rows = items
    .map(
      (i) => `<tr>
        <td style="padding:8px 12px;border-bottom:1px solid #f0ebe4;font-family:Georgia,serif;">${esc(i.name)}</td>
        <td style="padding:8px 12px;border-bottom:1px solid #f0ebe4;text-align:center;">${i.qty}</td>
        <td style="padding:8px 12px;border-bottom:1px solid #f0ebe4;text-align:right;">${money(i.price * i.qty)}</td>
      </tr>`,
    )
    .join('');
  const follow = biz.business_instagram ? ` Follow <strong>${esc(biz.business_instagram)}</strong> for zone schedule updates.` : '';
  const html = wrap(`${header(biz)}
    <div style="padding:32px 24px;background:#FAF6F1;">
      <h2 style="color:#2C1810;margin:0 0 8px;">Grazie, ${esc(order.customer_name)}!</h2>
      <p style="color:#8B7355;margin:0 0 24px;">Your order has been received. Here are the details:</p>
      <div style="background:white;border-radius:8px;padding:16px;margin-bottom:20px;">
        <p style="margin:0 0 4px;"><strong>Order Reference:</strong> <span style="color:#D4AF37;font-size:18px;">${esc(order.ref)}</span></p>
        <p style="margin:0;"><strong>Delivery Zone:</strong> ${esc(order.customer_zone)}</p>
      </div>
      <table style="width:100%;border-collapse:collapse;background:white;border-radius:8px;overflow:hidden;">
        <thead><tr style="background:#2C1810;color:#D4AF37;">
          <th style="padding:10px 12px;text-align:left;">Item</th>
          <th style="padding:10px 12px;text-align:center;">Qty</th>
          <th style="padding:10px 12px;text-align:right;">Price</th>
        </tr></thead>
        <tbody>${rows}</tbody>
        <tfoot><tr>
          <td colspan="2" style="padding:12px;font-weight:bold;font-size:16px;">Total</td>
          <td style="padding:12px;text-align:right;font-weight:bold;font-size:16px;color:#D4AF37;">${money(order.total)}</td>
        </tr></tfoot>
      </table>
      <div style="margin-top:24px;padding:16px;background:#fff8e1;border-radius:8px;border-left:4px solid #D4AF37;">
        <p style="margin:0;font-size:14px;">📍 <strong>Delivery Note:</strong> We deliver by zones each week. We'll contact you to confirm your delivery day based on your area.${follow}</p>
      </div>
      <p style="margin-top:24px;color:#8B7355;font-size:13px;">If you have questions, reply to this email${biz.business_phone ? ` or call us at ${esc(biz.business_phone)}` : ''}.</p>
    </div>
    ${footer(biz)}`);
  return { subject: `${biz.business_name} — Order ${order.ref} Confirmed`, html };
}

export function adminNotificationEmail(biz, order, items, adminUrl) {
  const lines = items.map((i) => `  • ${esc(i.name)} × ${i.qty} — ${money(i.price * i.qty)}`).join('\n');
  const payment = order.payment_status.charAt(0).toUpperCase() + order.payment_status.slice(1);
  const html = `<div style="max-width:600px;margin:0 auto;font-family:Arial,sans-serif;">
    <div style="background:#2C1810;padding:16px;text-align:center;"><h2 style="color:#D4AF37;margin:0;">New Order Received!</h2></div>
    <div style="padding:20px;background:#f9f9f9;">
      <p><strong>Reference:</strong> ${esc(order.ref)}</p>
      <p><strong>Customer:</strong> ${esc(order.customer_name)}</p>
      <p><strong>Phone:</strong> ${esc(order.customer_phone)}</p>
      <p><strong>Zone:</strong> ${esc(order.customer_zone)}</p>
      <p><strong>Payment:</strong> ${esc(payment)}</p>
      <p><strong>Total:</strong> ${money(order.total)}</p>
      <hr><pre style="font-family:monospace;font-size:13px;">${lines}</pre><hr>
      <p><a href="${esc(adminUrl)}">View in Dashboard →</a></p>
    </div>
  </div>`;
  return { subject: `🇮🇹 New Order ${order.ref} — ${money(order.total)} — ${order.customer_name}`, html };
}

const STATUS_MESSAGES = {
  confirmed: "Your order has been confirmed! We're scheduling your delivery.",
  preparing: 'Great news! Your order is being prepared with love.',
  delivered: 'Your order has been delivered. Buon appetito! 🇮🇹',
  cancelled: 'Your order has been cancelled. If you have questions, please reply to this email.',
};

export function statusUpdateEmail(biz, order, status) {
  const info = ORDER_STATUSES[status] || { label: status, color: '#333' };
  const msg = STATUS_MESSAGES[status] || `Your order status has been updated to: ${info.label}`;
  const html = wrap(`${header(biz, 20)}
    <div style="padding:32px 24px;background:#FAF6F1;text-align:center;">
      <p style="font-size:14px;color:#8B7355;margin:0 0 8px;">Order ${esc(order.ref)}</p>
      <div style="display:inline-block;padding:8px 20px;background:${info.color};color:white;border-radius:20px;font-size:16px;font-weight:bold;margin:12px 0;">${esc(info.label)}</div>
      <p style="font-size:16px;margin:16px 0 0;">${esc(order.customer_name)}, ${esc(msg)}</p>
    </div>
    ${footer(biz)}`);
  return { subject: `${biz.business_name} — Order ${order.ref} is now ${info.label}`, html };
}

export function customerMessageEmail(biz, order, body) {
  const html = wrap(`${header(biz, 18)}
    <div style="padding:24px;background:#FAF6F1;">
      <p style="color:#8B7355;font-size:13px;margin:0 0 16px;">Re: Order ${esc(order.ref)}</p>
      <div style="background:white;border-radius:8px;padding:16px;">${esc(body).replace(/\n/g, '<br>')}</div>
      <p style="margin-top:16px;color:#8B7355;font-size:12px;">Reply directly to this email${biz.business_phone ? ` or call ${esc(biz.business_phone)}` : ''}.</p>
    </div>
    ${footer(biz)}`);
  return { subject: `${biz.business_name} — Re: Order ${order.ref}`, html };
}

export function deliveryOpenEmail(biz, sub, orderUrl) {
  const name = sub.name || 'there';
  const zone = sub.zone ? `<p style="font-size:13px;color:#8B7355;">Your zone: <strong>${esc(sub.zone)}</strong></p>` : '';
  const html = wrap(`${header(biz, 22, 'DELIVERY IS OPEN')}
    <div style="padding:32px 24px;background:#FAF6F1;">
      <h2 style="color:#2C1810;margin:0 0 12px;font-size:22px;">Hey ${esc(name)}! 🎉</h2>
      <p style="color:#4A2C20;font-size:15px;line-height:1.6;margin:0 0 16px;">
        Great news — our special delivery service is now open!
        Order your favorite Sicilian dishes and we'll bring them to you.
      </p>
      ${zone}
      <div style="text-align:center;margin:24px 0;">
        <a href="${esc(orderUrl)}" style="display:inline-block;background:#2C1810;color:#D4AF37;padding:14px 32px;border-radius:10px;font-size:16px;font-weight:700;text-decoration:none;letter-spacing:1px;">Order Now →</a>
      </div>
      <p style="color:#8B7355;font-size:12px;text-align:center;">Arancine · Cannoli · Fresh Pasta · and more, delivered to your door.</p>
    </div>
    ${footer(biz)}`);
  return { subject: `🇮🇹 ${biz.business_name} — Delivery is Open! Order Now`, html };
}
