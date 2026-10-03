/* VS Orders — delivery status widget (replaces the [visit_sicily_status] shortcode).
   Usage: <div class="vso-status" data-order-url="/"></div><script src="/vso/status-widget.js" defer></script> */
(function () {
  const script = document.currentScript;
  const API = ((window.VSO_CONFIG && window.VSO_CONFIG.apiBase) || (script && script.dataset.api) || '/api').replace(/\/$/, '');

  const CSS = `
@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,700;1,400&family=DM+Sans:wght@400;600;700&display=swap');
.vso-sw{font-family:'DM Sans',-apple-system,sans-serif;max-width:520px;margin:0 auto;border-radius:12px;overflow:hidden}
.vso-sw-special{padding:18px;position:relative;border-radius:12px}
.vso-sw-special.open{background:linear-gradient(135deg,#f0faf3,#eafaf1);border:1.5px solid #27ae60}
.vso-sw-special.closed{background:#fafafa;border:1.5px solid #ddd}
.vso-sw-special.full{background:#fef9e7;border:1.5px solid #f39c12}
.vso-sw .section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:2px;margin-bottom:8px}
.vso-sw .section-label.on{color:#27ae60}.vso-sw .section-label.off{color:#999}
.vso-sw .sw-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:700;color:#2C1810;margin:0 0 2px;line-height:1.2}
.vso-sw .sw-subtitle{font-size:13px;color:#8B7355;margin-bottom:10px;line-height:1.4;font-style:italic;font-family:'Cormorant Garamond',serif}
.vso-sw .status-line{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.vso-sw .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.vso-sw .dot.on{background:#27ae60;box-shadow:0 0 8px rgba(39,174,96,.4);animation:vso-pulse 2s infinite}
.vso-sw .dot.off{background:#ccc}.vso-sw .dot.full{background:#f39c12}
@keyframes vso-pulse{0%,100%{opacity:1}50%{opacity:.5}}
.vso-sw .status-text{font-size:14px;font-weight:700}
.vso-sw .status-text.on{color:#27ae60}.vso-sw .status-text.off{color:#999}.vso-sw .status-text.full{color:#f39c12}
.vso-sw .zone-name{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:700;color:#2C1810;margin-bottom:4px}
.vso-sw .meta{font-size:12px;color:#4A2C20;display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.vso-sw .btn-special{display:inline-block;background:#2C1810;color:#D4AF37;border:none;padding:11px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;text-align:center;letter-spacing:.5px;transition:background .2s}
.vso-sw .btn-special:hover{background:#4A2C20}
.vso-sw .closed-note{font-size:13px;color:#8B7355;line-height:1.5;margin-bottom:4px}
.vso-sw-always{margin-top:10px;padding:14px 18px;background:#fff;border:1px solid #e8e4df;border-radius:10px;display:flex;align-items:center;gap:12px}
.vso-sw-always .ue-info{flex:1}
.vso-sw-always .ue-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:#8B7355;margin-bottom:2px}
.vso-sw-always .ue-text{font-size:13px;color:#4A2C20;line-height:1.3}
.vso-sw .btn-ue{display:inline-block;background:rgba(44,24,16,.06);color:#2C1810;border:1.5px solid rgba(44,24,16,.12);padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;text-align:center;white-space:nowrap;transition:all .2s}
.vso-sw .btn-ue:hover{background:rgba(44,24,16,.1);border-color:rgba(44,24,16,.2)}`;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function renderWidget(d, orderUrl) {
    let h = '<div class="vso-sw">';
    if (d.deliveryActive) {
      h += `<div class="vso-sw-special open">
        <div class="section-label on">🚗 Special Weekly Delivery</div>
        <div class="sw-title">Nicolas &amp; Vincenzo deliver to your door</div>
        <div class="sw-subtitle">Fresh Sicilian food, personally delivered from our East Village bakery.</div>
        <div class="status-line"><span class="dot on"></span><span class="status-text on">Orders Open!</span></div>
        ${d.weeklyZone ? `<div class="zone-name">📍 ${esc(d.weeklyZone)}</div>` : ''}
        <div class="meta">
          ${d.weeklyDeliveryDay ? `<span>🗓 ${esc(d.weeklyDeliveryDay)}</span>` : ''}
          ${d.weeklyDeadline ? `<span>⏰ Order by ${esc(d.weeklyDeadline)}</span>` : ''}
          <span>💰 Min. $${esc(d.minimumOrder)}</span>
        </div>
        ${orderUrl ? `<a href="${esc(orderUrl)}" class="btn-special">Place Your Order →</a>` : ''}
      </div>`;
    } else if (d.atCapacity) {
      h += `<div class="vso-sw-special full">
        <div class="section-label off">🚗 Special Weekly Delivery</div>
        <div class="status-line"><span class="dot full"></span><span class="status-text full">Capacity Reached</span></div>
        <div class="closed-note">This week's delivery round is full. Subscribe on our menu page to get notified for the next one!</div>
      </div>`;
    } else {
      h += `<div class="vso-sw-special closed">
        <div class="section-label off">🚗 Special Weekly Delivery</div>
        <div class="sw-title" style="color:#999;">Coming Soon to Your Area</div>
        <div class="status-line"><span class="dot off"></span><span class="status-text off">Not currently accepting orders</span></div>
        <div class="closed-note">${esc(d.closedMessage)}</div>
        ${orderUrl ? `<a href="${esc(orderUrl)}" class="btn-ue" style="margin-top:4px;">📩 Get Notified →</a>` : ''}
      </div>`;
    }
    if (d.uberEatsUrl) {
      h += `<div class="vso-sw-always">
        <div class="ue-info"><div class="ue-label">Always Available</div><div class="ue-text">Order anytime via Uber Eats for standard delivery.</div></div>
        <a href="${esc(d.uberEatsUrl)}" class="btn-ue" target="_blank" rel="noopener">🛵 Uber Eats</a>
      </div>`;
    }
    return h + '</div>';
  }

  async function init() {
    const targets = document.querySelectorAll('.vso-status, #vso-status');
    if (!targets.length) return;
    if (!document.getElementById('vso-sw-css')) {
      const style = document.createElement('style');
      style.id = 'vso-sw-css';
      style.textContent = CSS;
      document.head.appendChild(style);
    }
    try {
      const r = await fetch(API + '/status', { cache: 'no-store' });
      if (!r.ok) throw new Error(String(r.status));
      const d = await r.json();
      targets.forEach((el) => { el.innerHTML = renderWidget(d, el.dataset.orderUrl || '/'); });
    } catch (e) {
      targets.forEach((el) => { el.innerHTML = ''; });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
