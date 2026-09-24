(function () {
  'use strict';

  const API = '/api/admin';
  const view = document.getElementById('view');
  const modalEl = document.getElementById('modal');
  const pill = document.getElementById('delivery-pill');
  const S = { me: null, statuses: {}, paymentStatuses: [], delivery: null };
  let actions = {};
  let pollTimer = null;
  let routeSeq = 0;

  // ─── Utilities ───
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => '$' + Number(n || 0).toFixed(2);
  const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '');
  const isAdmin = () => S.me && S.me.role === 'admin';
  function fmtDate(iso, style) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (style === 'long') return d.toLocaleString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    if (style === 'ticket') return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  async function api(method, path, body) {
    const opts = { method, headers: {}, credentials: 'same-origin' };
    if (method !== 'GET') opts.headers['X-Requested-With'] = 'vso-admin';
    if (body instanceof FormData) opts.body = body;
    else if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const r = await fetch(API + path, opts);
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || `Request failed (${r.status})`);
    return d;
  }

  function toast(msg, type) {
    const el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.textContent = msg;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), type === 'error' ? 7000 : 3500);
  }

  async function run(btn, fn) {
    if (btn) btn.disabled = true;
    try {
      return await fn();
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      if (btn && btn.isConnected) btn.disabled = false;
    }
  }

  function statusBadge(st, lg) {
    const s = S.statuses[st] || S.statuses.new || { label: st, color: '#333', bg: '#eee' };
    return `<span class="badge ${lg ? 'lg' : ''}" style="background:${s.bg};color:${s.color}">${esc(s.label)}</span>`;
  }
  function payBadge(p, lg) {
    const c = { paid: ['#eafaf1', '#27ae60'], refunded: ['#f0f0f0', '#777'] }[p] || ['#fef9e7', '#f39c12'];
    return `<span class="badge ${lg ? 'lg' : ''}" style="background:${c[0]};color:${c[1]}">${esc(cap(p || 'pending'))}</span>`;
  }

  function openModal(html, bind) {
    modalEl.innerHTML = `<div class="box" role="dialog" aria-modal="true">${html}</div>`;
    modalEl.hidden = false;
    modalEl.onclick = (e) => { if (e.target === modalEl) closeModal(); };
    const box = modalEl.querySelector('.box');
    if (bind) bind(box);
    const first = box.querySelector('input,select,textarea');
    if (first) first.focus();
  }
  function closeModal() {
    modalEl.hidden = true;
    modalEl.innerHTML = '';
  }
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modalEl.hidden) closeModal(); });

  view.addEventListener('click', (e) => {
    const t = e.target.closest('[data-act]');
    if (!t || !view.contains(t)) return;
    const fn = actions[t.dataset.act] || GLOBAL_ACTIONS[t.dataset.act];
    if (fn) {
      e.preventDefault();
      fn(t, e);
    }
  });

  // ─── Header / delivery ───
  function renderHeader() {
    document.getElementById('brand-name').textContent = (S.me.businessName || 'Orders') + ' — Orders';
    document.title = (S.me.businessName || 'Orders') + ' Admin';
    document.getElementById('user-box').innerHTML =
      `<span class="email">${esc(S.me.email)}</span><span class="role">${esc(S.me.role === 'admin' ? 'Admin' : 'Delivery Manager')}</span><a href="/cdn-cgi/access/logout">Log out</a>`;
    document.getElementById('nav').hidden = false;
    document.querySelectorAll('[data-admin-only]').forEach((el) => { el.hidden = !isAdmin(); });
    renderPill();
  }

  function renderPill() {
    if (!S.delivery) return;
    const on = S.delivery.active;
    pill.hidden = false;
    pill.className = 'pill ' + (on ? 'on' : 'off');
    pill.textContent = on ? (S.delivery.atCapacity ? '🟠 FULL' : '🟢 OPEN') : '🔴 CLOSED';
    pill.title = on ? 'Delivery is open — click to close' : 'Delivery is closed — click to open';
  }

  async function toggleDelivery(btn) {
    const on = S.delivery && S.delivery.active;
    const msg = on
      ? 'Close delivery? Customers will no longer be able to order.'
      : 'Open delivery for a new round? This resets the order counter.';
    if (!confirm(msg)) return;
    await run(btn, async () => {
      const d = await api('POST', '/delivery/toggle', {});
      S.delivery = { ...S.delivery, active: d.active, atCapacity: false, orderCount: d.active ? 0 : S.delivery.orderCount };
      renderPill();
      toast(d.active ? 'Delivery is now OPEN ✓' : 'Delivery is now CLOSED ⏸', 'success');
      route();
    });
  }
  pill.addEventListener('click', () => toggleDelivery(pill));

  const GLOBAL_ACTIONS = { 'toggle-delivery': (btn) => toggleDelivery(btn) };

  function deliveryBar(del) {
    const on = del.active;
    let extra = '';
    if (on && del.zone) extra += ` <span>— ${esc(del.zone)}</span>`;
    if (on && del.maxOrders > 0) extra += ` <span>· ${del.orderCount}/${del.maxOrders} orders</span>`;
    if (on && del.atCapacity) extra += ' <span>· capacity reached</span>';
    return `<div class="delivery-bar ${on ? 'on' : 'off'}"><span class="txt">🚗 ${on ? 'Delivery OPEN' : 'Delivery CLOSED'}${extra}</span>
      <button class="btn ${on ? 'red' : 'green'}" data-act="toggle-delivery">${on ? '⏸ Close' : '▶ Open'}</button></div>`;
  }

  // ─── Router ───
  function parseHash() {
    const h = location.hash.replace(/^#/, '') || '/dashboard';
    const i = h.indexOf('?');
    return { path: i >= 0 ? h.slice(0, i) : h, query: new URLSearchParams(i >= 0 ? h.slice(i + 1) : '') };
  }
  function hashFor(path, params) {
    const q = new URLSearchParams();
    Object.entries(params || {}).forEach(([k, v]) => { if (v) q.set(k, v); });
    const s = q.toString();
    return '#' + path + (s ? '?' + s : '');
  }

  const ROUTES = [
    [/^\/(dashboard)?$/, 'dashboard', viewDashboard, false],
    [/^\/orders$/, 'orders', viewOrders, false],
    [/^\/orders\/(\d+)$/, 'orders', viewOrder, false],
    [/^\/products$/, 'products', viewProducts, false],
    [/^\/categories$/, 'categories', viewCategories, true],
    [/^\/subscribers$/, 'subscribers', viewSubscribers, false],
    [/^\/settings$/, 'settings', viewSettings, true],
    [/^\/users$/, 'users', viewUsers, true],
  ];

  async function route() {
    const seq = ++routeSeq;
    clearTimeout(pollTimer);
    actions = {};
    closeModal();
    const { path, query } = parseHash();
    const alive = () => seq === routeSeq;
    for (const [re, nav, fn, narrow] of ROUTES) {
      const m = path.match(re);
      if (!m) continue;
      document.querySelectorAll('[data-nav]').forEach((a) => a.classList.toggle('on', a.dataset.nav === nav));
      view.className = narrow ? 'narrow' : '';
      view.innerHTML = '<div class="loading">Loading…</div>';
      try {
        await fn({ m, query, alive });
      } catch (e) {
        if (alive()) view.innerHTML = `<div class="notice err">${esc(e.message)}</div>`;
      }
      return;
    }
    view.innerHTML = '<div class="empty">Page not found.</div>';
  }

  function schedulePoll(alive, ms) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(() => {
      if (alive() && modalEl.hidden && document.visibilityState === 'visible' && !view.contains(document.activeElement)) route();
      else if (alive()) schedulePoll(alive, ms);
    }, ms);
  }

  function orderRow(o) {
    return `<a class="list-row" href="#/orders/${o.id}">
      <div class="grow">
        <div class="row-flex"><span class="t">${esc(o.ref)}</span>${statusBadge(o.status)}${payBadge(o.payment_status)}</div>
        <div class="s">${esc(o.customer_name)}${o.customer_phone ? ' · ' + esc(o.customer_phone) : ''}${o.customer_zone ? ' · ' + esc(o.customer_zone) : ''}</div>
        <div class="x">${o.item_count} items · ${esc(fmtDate(o.created_at))}</div>
      </div>
      <div class="amt">${money(o.total)}</div>
    </a>`;
  }

  // ─── Dashboard ───
  async function viewDashboard({ alive }) {
    const d = await api('GET', '/dashboard');
    if (!alive()) return;
    S.delivery = { ...d.delivery };
    renderPill();
    const st = d.stats;
    const tiles = [
      [st.todayOrders, 'Orders Today', true],
      [money(st.todayRevenue), 'Revenue Today', true],
      [st.pending, 'New / Pending', false, st.pending ? '#e74c3c' : '#27ae60'],
      [st.totalOrders, 'Total Orders', false],
      [money(st.totalRevenue), 'Total Revenue', false],
      [st.activeProducts, 'Active Products', false],
      [st.subscribers, 'Subscribers', false],
    ];
    let h = `<h1>${esc(d.businessName || 'Orders')} — Dashboard</h1>`;
    h += deliveryBar(d.delivery);
    h += `<div class="stats">${tiles.map(([n, l, gold, color]) => `<div class="stat"><div class="n ${gold ? 'gold' : ''}" ${color ? `style="color:${color}"` : ''}>${esc(n)}</div><div class="l">${esc(l)}</div></div>`).join('')}</div>`;
    h += `<div class="chips">${Object.entries(d.statusCounts).map(([k, c]) => {
      const s = S.statuses[k];
      return `<a class="chip" href="${hashFor('/orders', { status: k })}" style="background:${s.bg};color:${s.color};font-weight:600">${esc(s.label)}: ${c}</a>`;
    }).join('')}</div>`;
    h += `<div class="panel flush"><div class="panel-hd">Recent Orders</div>${d.recent.length ? d.recent.map(orderRow).join('') : '<div class="empty">No orders yet.</div>'}</div>`;
    h += `<div class="row-flex"><a class="btn primary" href="#/orders">View All Orders →</a><a class="btn" href="#/products">Manage Products</a><a class="btn" href="#/categories">Categories</a></div>`;
    view.innerHTML = h;
    schedulePoll(alive, 60000);
  }

  // ─── Orders ───
  async function viewOrders({ query, alive }) {
    const f = { status: query.get('status') || '', q: query.get('q') || '', zone: query.get('zone') || '', payment: query.get('payment') || '' };
    const params = new URLSearchParams();
    Object.entries(f).forEach(([k, v]) => { if (v) params.set(k, v); });
    params.set('limit', '50');
    const d = await api('GET', '/orders?' + params);
    if (!alive()) return;
    let orders = d.orders;

    let h = `<h1><span>📋 Orders <small>(${d.total})</small></span><button class="btn" data-act="refresh">↻ Refresh</button></h1>`;
    h += `<div class="chips"><a class="chip ${!f.status ? 'on' : ''}" href="${hashFor('/orders', { ...f, status: '' })}">All</a>`;
    for (const [k, s] of Object.entries(S.statuses)) {
      h += `<a class="chip" href="${hashFor('/orders', { ...f, status: k })}" style="background:${s.bg};color:${s.color};${f.status === k ? `border-color:${s.color};font-weight:700` : ''}">${esc(s.label)}</a>`;
    }
    h += `</div>
      <form id="order-filters" class="row-flex" style="margin-bottom:14px">
        <input class="input grow" name="q" placeholder="Search ref, name, email, phone…" value="${esc(f.q)}" style="min-width:160px">
        <select class="input" name="zone" style="width:auto"><option value="">All Zones</option>${d.zones.map((z) => `<option ${z === f.zone ? 'selected' : ''} value="${esc(z)}">${esc(z)}</option>`).join('')}</select>
        <select class="input" name="payment" style="width:auto"><option value="">All Pay</option>${S.paymentStatuses.map((p) => `<option value="${p}" ${p === f.payment ? 'selected' : ''}>${cap(p)}</option>`).join('')}</select>
        <button class="btn primary" type="submit">Filter</button>
        <a class="btn" href="#/orders">Clear</a>
      </form>
      <div class="panel flush" id="order-list">${orders.length ? orders.map(orderRow).join('') : '<div class="empty">No orders match your filters.</div>'}</div>
      <div id="more"></div>`;
    view.innerHTML = h;

    const renderMore = () => {
      document.getElementById('more').innerHTML = orders.length < d.total ? `<button class="btn" data-act="more">Load more (${d.total - orders.length} left)</button>` : '';
    };
    renderMore();
    view.querySelector('#order-filters').addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      location.hash = hashFor('/orders', { status: f.status, q: fd.get('q').trim(), zone: fd.get('zone'), payment: fd.get('payment') });
    });
    actions = {
      refresh: () => route(),
      more: (btn) => run(btn, async () => {
        params.set('offset', String(orders.length));
        const next = await api('GET', '/orders?' + params);
        orders = orders.concat(next.orders);
        document.getElementById('order-list').insertAdjacentHTML('beforeend', next.orders.map(orderRow).join(''));
        renderMore();
      }),
    };
    if (!f.q) schedulePoll(alive, 60000);
  }

  async function viewOrder({ m, alive }) {
    const id = m[1];
    const d = await api('GET', '/orders/' + id);
    if (!alive()) return;
    const o = d.order;
    const b = d.business;
    const stripeUrl = o.stripe_payment_intent ? `https://dashboard.stripe.com/${d.stripeMode === 'live' ? '' : 'test/'}payments/${encodeURIComponent(o.stripe_payment_intent)}` : '';
    const itemsRows = d.items.map((it) => `<tr><td>${esc(it.name)}</td><td style="text-align:center">${it.qty}</td><td>${money(it.price * it.qty)}</td></tr>`).join('');

    let h = `<p><a href="#/orders">← Back to Orders</a></p>
      <div class="row-flex" style="margin-bottom:4px">
        <span style="font-size:22px;font-weight:700">${esc(o.ref)}</span>
        <span id="st-badge">${statusBadge(o.status, true)}</span><span id="pay-badge">${payBadge(o.payment_status, true)}</span>
        <span class="grow"></span>
        <button class="btn" data-act="ticket">🖨️ Print Ticket</button>
        <button class="btn primary" data-act="print">Print Now</button>
      </div>
      <p class="small muted" style="margin:0 0 16px">${esc(fmtDate(o.created_at, 'long'))}</p>
      <div class="grid2">
        <div class="panel"><h3>👤 Customer</h3>
          <div class="kv"><span class="k">Name</span><span class="v">${esc(o.customer_name)}</span></div>
          <div class="kv"><span class="k">Email</span><span class="v"><a href="mailto:${esc(o.customer_email)}">${esc(o.customer_email)}</a></span></div>
          <div class="kv"><span class="k">Phone</span><span class="v"><a href="tel:${esc(o.customer_phone)}">${esc(o.customer_phone)}</a></span></div>
          <div class="kv"><span class="k">Zone</span><span class="v">${esc(o.customer_zone || '—')}</span></div>
          <div class="kv"><span class="k">Address</span><span class="v">${esc(o.customer_address || '—')}</span></div>
          ${o.notes ? `<div class="notes">📝 ${esc(o.notes)}</div>` : ''}
        </div>
        <div class="panel"><h3>⚙️ Management</h3>
          <div class="kv"><span class="k">Status</span><span class="v"><select class="input" id="status-sel" style="width:auto">${Object.entries(S.statuses).map(([k, s]) => `<option value="${k}" ${k === o.status ? 'selected' : ''}>${esc(s.label)}</option>`).join('')}</select></span></div>
          <div class="kv"><span class="k">Payment</span><span class="v"><select class="input" id="pay-sel" style="width:auto">${S.paymentStatuses.map((p) => `<option value="${p}" ${p === o.payment_status ? 'selected' : ''}>${cap(p)}</option>`).join('')}</select></span></div>
          ${stripeUrl ? `<div class="kv"><span class="k">Stripe</span><span class="v"><a href="${stripeUrl}" target="_blank" rel="noopener">View payment ↗</a></span></div>` : ''}
          <div class="kv"><span class="k">Date</span><span class="v">${esc(fmtDate(o.created_at))}</span></div>
          <p class="tiny muted" style="margin:8px 0 0">Changing the status emails the customer.</p>
        </div>
      </div>
      <div class="panel"><h3>🛒 Items</h3>
        <table class="itbl"><thead><tr><th>Item</th><th style="text-align:center">Qty</th><th>Subtotal</th></tr></thead>
        <tbody>${itemsRows}<tr class="trow"><td colspan="2">Total</td><td style="color:var(--gold)">${money(o.total)}</td></tr></tbody></table>
      </div>
      <div class="panel"><h3>💬 Messages (<span id="msg-count">${d.messages.length}</span>)</h3>
        <div class="msgs" id="msgs">${d.messages.length ? d.messages.map(msgBubble).join('') : '<p class="empty small" id="no-msgs">No messages yet.</p>'}</div>
        <div class="row-flex" style="align-items:stretch">
          <textarea class="input grow" id="msg-body" placeholder="Message ${esc(o.customer_name)}… (Enter to send, Shift+Enter for a new line)" style="height:48px;min-height:48px"></textarea>
          <button class="btn primary" data-act="send">Send</button>
        </div>
        <div id="msg-feedback" class="small" style="min-height:18px;margin-top:4px"></div>
      </div>
      ${ticketHtml(o, d.items, b)}`;
    view.innerHTML = h;
    const msgs = document.getElementById('msgs');
    msgs.scrollTop = msgs.scrollHeight;

    view.querySelector('#status-sel').addEventListener('change', async (e) => {
      const sel = e.target;
      const prev = o.status;
      await run(sel, async () => {
        try {
          const r = await api('POST', `/orders/${id}/status`, { status: sel.value });
          o.status = r.status;
          document.getElementById('st-badge').innerHTML = statusBadge(r.status, true);
          document.getElementById('ticket').outerHTML = ticketHtml(o, d.items, b);
          toast(`Status → ${S.statuses[r.status].label}${r.emailed ? ' · customer emailed' : ''}`, 'success');
        } catch (err) {
          sel.value = prev;
          throw err;
        }
      });
    });
    view.querySelector('#pay-sel').addEventListener('change', async (e) => {
      const sel = e.target;
      const prev = o.payment_status;
      await run(sel, async () => {
        try {
          const r = await api('POST', `/orders/${id}/payment`, { payment_status: sel.value });
          o.payment_status = r.payment_status;
          document.getElementById('pay-badge').innerHTML = payBadge(r.payment_status, true);
          document.getElementById('ticket').outerHTML = ticketHtml(o, d.items, b);
          toast('Payment status updated', 'success');
        } catch (err) {
          sel.value = prev;
          throw err;
        }
      });
    });
    const bodyEl = view.querySelector('#msg-body');
    bodyEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        actions.send(view.querySelector('[data-act="send"]'));
      }
    });
    actions = {
      ticket: () => document.getElementById('ticket').classList.toggle('show'),
      print: () => { document.getElementById('ticket').classList.add('show'); window.print(); },
      send: (btn) => {
        const text = bodyEl.value.trim();
        if (!text || btn.disabled) return;
        const fb = document.getElementById('msg-feedback');
        fb.textContent = '';
        btn.textContent = 'Sending…';
        run(btn, async () => {
          const r = await api('POST', `/orders/${id}/messages`, { body: text });
          const none = document.getElementById('no-msgs');
          if (none) none.remove();
          msgs.insertAdjacentHTML('beforeend', msgBubble(r.data));
          msgs.scrollTop = msgs.scrollHeight;
          const count = document.getElementById('msg-count');
          count.textContent = String(Number(count.textContent) + 1);
          if (r.success) {
            bodyEl.value = '';
            fb.innerHTML = `<span class="ok">✓ ${esc(r.message)}</span>`;
          } else {
            fb.innerHTML = `<span class="bad">⚠ ${esc(r.message)}</span>`;
          }
        }).finally(() => { btn.textContent = 'Send'; });
      },
    };
  }

  function msgBubble(msg) {
    const out = msg.direction === 'outgoing';
    const failed = msg.status === 'failed';
    return `<div class="msg ${out ? 'out' : 'in'} ${failed ? 'failed' : ''}">${esc(msg.body)}<div class="m">${esc(msg.sender)} · ${esc(fmtDate(msg.created_at))}${failed ? ' · ⚠ Failed to send' : out ? ' · ✓ Sent' : ''}</div></div>`;
  }

  function ticketHtml(o, items, b) {
    const customer = [o.customer_name, o.customer_phone, o.customer_zone, o.customer_address].filter(Boolean).map(esc).join('\n');
    return `<div id="ticket">
      <div class="t-header"><h2>${esc((b.business_name || '').toUpperCase())}</h2>${b.business_address ? `<p>${esc(b.business_address)}</p>` : ''}${b.business_phone ? `<p>${esc(b.business_phone)}</p>` : ''}</div>
      <div class="t-ref">${esc(o.ref)}</div>
      <div style="text-align:center;font-size:11px">${esc(fmtDate(o.created_at, 'ticket'))}</div>
      <div class="t-divider"></div>
      ${items.map((it) => `<div class="t-item"><span class="qty">${it.qty}x</span><span class="name">${esc(it.name)}</span><span>${money(it.price * it.qty)}</span></div>`).join('')}
      <div class="t-total"><span>TOTAL</span><span>${money(o.total)}</span></div>
      <div class="t-divider"></div>
      <div class="t-customer"><strong>${customer}</strong>${o.notes ? `\n\n📝 ${esc(o.notes)}` : ''}</div>
      <div class="t-divider"></div>
      <div style="text-align:center;font-size:11px"><strong>Payment: ${esc(cap(o.payment_status))}</strong><br>Status: ${esc((S.statuses[o.status] || {}).label || o.status)}</div>
      <div class="t-footer">Grazie! 🇮🇹${b.business_website ? `<br>${esc(b.business_website)}` : ''}</div>
    </div>`;
  }

  // ─── Products ───
  async function viewProducts({ query, alive }) {
    const d = await api('GET', '/products');
    if (!alive()) return;
    const catFilter = query.get('cat') || '';
    let { products, categories } = d;

    function render() {
      const shown = catFilter ? products.filter((p) => String(p.category_id) === catFilter) : products;
      const catName = catFilter ? (categories.find((c) => String(c.id) === catFilter) || {}).name || '' : '';
      let h = `<h1><span>🍽️ Products <small>(${shown.length})</small></span>
        <span class="row-flex"><a class="btn" href="#/categories">📂 Categories</a><button class="btn primary" data-act="add">+ Add Product</button></span></h1>`;
      h += `<div class="chips"><a class="chip ${!catFilter ? 'on' : ''}" href="#/products">All</a>${categories.map((c) => `<a class="chip ${String(c.id) === catFilter ? 'on' : ''}" href="${hashFor('/products', { cat: String(c.id) })}">${esc(c.icon ? c.icon + ' ' : '')}${esc(c.name)} (${products.filter((p) => p.category_id === c.id).length})</a>`).join('')}</div>`;
      h += `<div class="row-flex" style="margin-bottom:12px">
        <span class="tiny muted" style="font-weight:600;text-transform:uppercase;letter-spacing:1px">Quick:</span>
        <button class="btn sm" data-act="bulk" data-on="1">✅ Activate all${catName ? ' in ' + esc(catName) : ''}</button>
        <button class="btn sm" data-act="bulk" data-on="0">⛔ Deactivate all${catName ? ' in ' + esc(catName) : ''}</button>`;
      if (!catFilter && categories.length) {
        h += `<span style="color:#ddd">|</span><span class="tiny muted">By category:</span>${categories.map((c) => `<button class="btn sm" data-act="cat-toggle" data-id="${c.id}" title="Toggle all ${esc(c.name)}">${esc(c.name)} ⇄</button>`).join('')}`;
      }
      h += `</div><div class="panel flush">`;
      h += shown.length ? shown.map(productRow).join('') : '<div class="empty">No products.</div>';
      h += `</div>`;
      view.innerHTML = h;
      view.querySelectorAll('.switch input').forEach((input) => input.addEventListener('change', () => toggleOne(input)));
    }

    function productRow(p) {
      return `<div class="list-row ${p.active ? '' : 'inactive'}" id="prow-${p.id}">
        <label class="switch" title="Visible on the menu"><input type="checkbox" data-id="${p.id}" ${p.active ? 'checked' : ''}><span></span></label>
        ${p.image_url ? `<img class="thumb" src="${esc(p.image_url)}" alt="" loading="lazy">` : ''}
        <div class="grow"><div class="t" style="font-size:14px">${esc(p.name)}</div><div class="s" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.description)}</div></div>
        <span class="badge" style="background:#f0ebe4;color:#4A2C20">${esc(p.category_name || '—')}</span>
        <span class="amt" style="min-width:56px;text-align:right">${money(p.price)}</span>
        <button class="btn sm" data-act="edit" data-id="${p.id}">Edit</button>
        <button class="btn sm danger-text" data-act="del" data-id="${p.id}" title="Delete">×</button>
      </div>`;
    }

    async function toggleOne(input) {
      const id = Number(input.dataset.id);
      await run(input, async () => {
        try {
          const r = await api('POST', `/products/${id}/toggle`, {});
          const p = products.find((x) => x.id === id);
          p.active = r.active ? 1 : 0;
          input.checked = r.active;
          document.getElementById('prow-' + id).classList.toggle('inactive', !r.active);
        } catch (e) {
          input.checked = !input.checked;
          throw e;
        }
      });
    }

    async function bulk(ids, active, btn) {
      await run(btn, async () => {
        await api('POST', '/products/bulk-toggle', { ids, active });
        products.forEach((p) => { if (ids.includes(p.id)) p.active = active ? 1 : 0; });
        render();
        toast(`${ids.length} products ${active ? 'activated' : 'deactivated'}`, 'success');
      });
    }

    actions = {
      add: () => productModal(null),
      edit: (btn) => productModal(products.find((p) => p.id === Number(btn.dataset.id))),
      del: (btn) => {
        const p = products.find((x) => x.id === Number(btn.dataset.id));
        if (!confirm(`Delete "${p.name}"? This cannot be undone.`)) return;
        run(btn, async () => {
          await api('DELETE', `/products/${p.id}`);
          products = products.filter((x) => x.id !== p.id);
          render();
          toast('Product deleted');
        });
      },
      bulk: (btn) => {
        const shown = catFilter ? products.filter((p) => String(p.category_id) === catFilter) : products;
        const on = btn.dataset.on === '1';
        if (!shown.length || !confirm(`${on ? 'Activate' : 'Deactivate'} all ${shown.length} products shown?`)) return;
        bulk(shown.map((p) => p.id), on, btn);
      },
      'cat-toggle': (btn) => {
        const c = categories.find((x) => x.id === Number(btn.dataset.id));
        const inCat = products.filter((p) => p.category_id === c.id);
        if (!inCat.length) return toast('No products in this category');
        const activate = inCat.filter((p) => p.active).length < inCat.length / 2;
        if (!confirm(`${activate ? 'Activate' : 'Deactivate'} all ${inCat.length} products in "${c.name}"?`)) return;
        bulk(inCat.map((p) => p.id), activate, btn);
      },
    };

    function productModal(p) {
      const isNew = !p;
      const cur = p || { name: '', description: '', price: '', category_id: catFilter ? Number(catFilter) : (categories[0] || {}).id, image_url: '', active: 1 };
      openModal(
        `<h3>${isNew ? 'Add Product' : 'Edit Product'}</h3>
        <form id="pform">
          <div class="field"><label class="lbl">Name</label><input class="input" name="name" required maxlength="200"></div>
          <div class="field"><label class="lbl">Description</label><input class="input" name="description" maxlength="1000"></div>
          <div class="grid2 field">
            <div><label class="lbl">Price ($)</label><input class="input" name="price" type="number" step="0.01" min="0" required></div>
            <div><label class="lbl">Category</label><select class="input" name="category_id"><option value="">— None —</option>${categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></div>
          </div>
          <div class="field"><label class="lbl">Image <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
            <div class="row-flex">
              <img class="img-preview" id="img-prev" alt="" ${cur.image_url ? `src="${esc(cur.image_url)}"` : 'hidden'}>
              <div class="grow">
                ${d.mediaEnabled ? `<label class="btn sm" style="margin-bottom:6px">📷 Upload<input type="file" id="img-file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif" hidden></label> ` : ''}
                <button type="button" class="btn sm danger-text" id="img-rm" ${cur.image_url ? '' : 'hidden'}>✕ Remove</button>
                <input class="input small" name="image_url" placeholder="${d.mediaEnabled ? '…or paste an https:// image URL' : 'https:// image URL'}">
              </div>
            </div>
          </div>
          ${isNew ? '<label class="row-flex small field"><input type="checkbox" name="active" checked> Active (visible on the menu)</label>' : ''}
          <div class="row-flex"><button class="btn primary" type="submit">Save</button><button class="btn" type="button" id="p-cancel">Cancel</button></div>
        </form>`,
        (box) => {
          const form = box.querySelector('#pform');
          form.elements.name.value = cur.name;
          form.elements.description.value = cur.description;
          form.elements.price.value = cur.price;
          form.elements.category_id.value = cur.category_id ? String(cur.category_id) : '';
          form.elements.image_url.value = cur.image_url;
          const prev = box.querySelector('#img-prev');
          const rm = box.querySelector('#img-rm');
          const setImg = (url) => {
            form.elements.image_url.value = url;
            prev.hidden = !url;
            if (url) prev.src = url;
            rm.hidden = !url;
          };
          form.elements.image_url.addEventListener('change', () => setImg(form.elements.image_url.value.trim()));
          rm.addEventListener('click', () => setImg(''));
          box.querySelector('#p-cancel').addEventListener('click', closeModal);
          const file = box.querySelector('#img-file');
          if (file) {
            file.addEventListener('change', () => {
              if (!file.files[0]) return;
              const fd = new FormData();
              fd.append('file', file.files[0]);
              run(null, async () => {
                toast('Uploading…');
                const r = await api('POST', '/uploads', fd);
                setImg(r.url);
              });
            });
          }
          form.addEventListener('submit', (e) => {
            e.preventDefault();
            const payload = {
              name: form.elements.name.value.trim(),
              description: form.elements.description.value.trim(),
              price: form.elements.price.value,
              category_id: form.elements.category_id.value || null,
              image_url: form.elements.image_url.value.trim(),
            };
            if (isNew) payload.active = form.elements.active.checked;
            run(form.querySelector('[type=submit]'), async () => {
              const r = isNew ? await api('POST', '/products', payload) : await api('PUT', `/products/${p.id}`, payload);
              const cat = categories.find((c) => c.id === r.product.category_id);
              const row = { ...r.product, category_name: cat ? cat.name : null };
              products = isNew ? products.concat(row) : products.map((x) => (x.id === row.id ? row : x));
              closeModal();
              render();
              toast(isNew ? 'Product added!' : 'Product updated!', 'success');
            });
          });
        },
      );
    }

    render();
  }

  // ─── Categories ───
  async function viewCategories({ alive }) {
    let cats = await api('GET', '/categories');
    if (!alive()) return;

    function render() {
      view.innerHTML = `<h1><span>📂 Menu Categories</span><button class="btn primary" data-act="add">+ Add Category</button></h1>
        <div class="panel flush">
          <div class="panel-hd">Drag (or use ▲▼) to reorder · ${cats.length} categories</div>
          <div id="cat-list">${cats.length ? cats.map(catRow).join('') : '<div class="empty">No categories yet. Add your first one above!</div>'}</div>
        </div>`;
      bindDrag();
    }

    function catRow(c, i) {
      return `<div class="list-row drag-row" draggable="true" data-id="${c.id}">
        <span class="handle">⋮⋮</span>
        <span style="font-size:20px;width:28px;text-align:center">${esc(c.icon || '📁')}</span>
        <div class="grow"><div class="t" style="font-size:14px">${esc(c.name)}</div><div class="s">slug: ${esc(c.slug)} · ${c.product_count} product${c.product_count !== 1 ? 's' : ''}</div></div>
        <button class="btn sm" data-act="up" data-id="${c.id}" ${i === 0 ? 'disabled' : ''} aria-label="Move up">▲</button>
        <button class="btn sm" data-act="down" data-id="${c.id}" ${i === cats.length - 1 ? 'disabled' : ''} aria-label="Move down">▼</button>
        <button class="btn sm" data-act="edit" data-id="${c.id}">Edit</button>
        ${c.product_count === 0 ? `<button class="btn sm danger-text" data-act="del" data-id="${c.id}">×</button>` : ''}
      </div>`;
    }

    async function saveOrder() {
      await run(null, async () => {
        await api('POST', '/categories/reorder', { ids: cats.map((c) => c.id) });
        toast('Category order saved', 'success');
      });
    }

    function bindDrag() {
      const list = document.getElementById('cat-list');
      let dragged = null;
      list.querySelectorAll('.drag-row').forEach((row) => {
        row.addEventListener('dragstart', () => { dragged = row; row.classList.add('dragging'); });
        row.addEventListener('dragend', () => { row.classList.remove('dragging'); dragged = null; });
        row.addEventListener('dragover', (e) => { e.preventDefault(); row.classList.add('over'); });
        row.addEventListener('dragleave', () => row.classList.remove('over'));
        row.addEventListener('drop', (e) => {
          e.preventDefault();
          row.classList.remove('over');
          if (!dragged || dragged === row) return;
          const rect = row.getBoundingClientRect();
          list.insertBefore(dragged, e.clientY < rect.top + rect.height / 2 ? row : row.nextSibling);
          const order = [...list.querySelectorAll('.drag-row')].map((r) => Number(r.dataset.id));
          cats = order.map((id) => cats.find((c) => c.id === id));
          render();
          saveOrder();
        });
      });
    }

    function move(id, delta) {
      const i = cats.findIndex((c) => c.id === id);
      const j = i + delta;
      if (j < 0 || j >= cats.length) return;
      [cats[i], cats[j]] = [cats[j], cats[i]];
      render();
      saveOrder();
    }

    function catModal(c) {
      const isNew = !c;
      openModal(
        `<h3>${isNew ? 'Add Category' : 'Edit Category'}</h3>
        <form id="cform">
          <div class="field"><label class="lbl">Name</label><input class="input" name="name" required maxlength="100" placeholder="e.g. Antipasti"></div>
          <div class="field"><label class="lbl">Icon (emoji)</label><input class="input" name="icon" maxlength="8" placeholder="🍝" style="text-align:center;width:100px"></div>
          <div class="row-flex"><button class="btn primary" type="submit">Save</button><button class="btn" type="button" id="c-cancel">Cancel</button></div>
        </form>`,
        (box) => {
          const form = box.querySelector('#cform');
          form.elements.name.value = c ? c.name : '';
          form.elements.icon.value = c ? c.icon : '';
          box.querySelector('#c-cancel').addEventListener('click', closeModal);
          form.addEventListener('submit', (e) => {
            e.preventDefault();
            const payload = { name: form.elements.name.value.trim(), icon: form.elements.icon.value.trim() };
            run(form.querySelector('[type=submit]'), async () => {
              if (isNew) {
                const r = await api('POST', '/categories', payload);
                cats.push({ ...r.category, product_count: 0 });
              } else {
                const r = await api('PUT', `/categories/${c.id}`, payload);
                cats = cats.map((x) => (x.id === c.id ? { ...r.category, product_count: x.product_count } : x));
              }
              closeModal();
              render();
              toast(isNew ? `Category "${payload.name}" created.` : 'Category updated.', 'success');
            });
          });
        },
      );
    }

    actions = {
      add: () => catModal(null),
      edit: (btn) => catModal(cats.find((c) => c.id === Number(btn.dataset.id))),
      up: (btn) => move(Number(btn.dataset.id), -1),
      down: (btn) => move(Number(btn.dataset.id), 1),
      del: (btn) => {
        const c = cats.find((x) => x.id === Number(btn.dataset.id));
        if (!confirm(`Delete category "${c.name}"?`)) return;
        run(btn, async () => {
          await api('DELETE', `/categories/${c.id}`);
          cats = cats.filter((x) => x.id !== c.id);
          render();
          toast('Category deleted');
        });
      },
    };
    render();
  }

  // ─── Subscribers ───
  async function viewSubscribers({ alive }) {
    const d = await api('GET', '/subscribers');
    if (!alive()) return;
    let subs = d.subscribers;
    const st = d.stats;
    function render() {
      let h = `<h1><span>📩 Subscribers <small>(${subs.length})</small></span>
        <span class="row-flex"><a class="btn" href="/api/admin/subscribers.csv">⬇ Export CSV</a>
        ${subs.length ? `<button class="btn green" data-act="notify" ${d.emailConfigured ? '' : 'disabled'}>📧 Notify All</button>` : ''}</span></h1>`;
      if (!d.emailConfigured) h += `<div class="notice warn">Email sending is not configured (RESEND_API_KEY), so "Notify All" is disabled.</div>`;
      if (st.lastNotify) h += `<p class="small muted">Last notification: ${esc(fmtDate(st.lastNotify.date))} — sent to ${st.lastNotify.count} of ${st.lastNotify.total}.</p>`;
      if (st.zones.length) h += `<div class="chips">${st.zones.map((z) => `<span class="badge" style="background:#f0ebe4;color:#4A2C20;font-size:11px">${esc(z.zone)}: ${z.count}</span>`).join('')}</div>`;
      h += `<div class="panel flush">${subs.length
        ? subs.map((s) => `<div class="list-row"><div class="grow"><div class="t">${esc(s.name || '—')}</div><div class="s">${esc(s.email)}</div></div>
            <span class="small muted">${esc(s.zone)}</span><span class="tiny muted">${esc(fmtDate(s.created_at))}</span>
            <button class="btn sm danger-text" data-act="del" data-id="${s.id}" title="Remove">×</button></div>`).join('')
        : '<div class="empty">No subscribers yet.</div>'}</div>`;
      view.innerHTML = h;
    }
    actions = {
      notify: (btn) => {
        if (!confirm(`Send the "Delivery is Open!" email to all ${subs.length} subscribers?`)) return;
        run(btn, async () => {
          const r = await api('POST', '/subscribers/notify', {});
          toast(`Sent to ${r.sent} of ${r.total} subscribers!`, 'success');
          route();
        });
      },
      del: (btn) => {
        const s = subs.find((x) => x.id === Number(btn.dataset.id));
        if (!confirm(`Remove ${s.email}?`)) return;
        run(btn, async () => {
          await api('DELETE', `/subscribers/${s.id}`);
          subs = subs.filter((x) => x.id !== s.id);
          render();
        });
      },
    };
    render();
  }

  // ─── Settings ───
  async function viewSettings({ alive }) {
    const d = await api('GET', '/settings');
    if (!alive()) return;
    const s = d.settings;
    S.delivery = { ...S.delivery, ...d.delivery };
    renderPill();
    const admin = d.role === 'admin';
    const ig = d.integrations;
    const input = (name, label, attrs) => `<div class="field"><label class="lbl">${label}</label><input class="input" name="${name}" value="${esc(s[name])}" ${attrs || ''}></div>`;
    let steps = s.how_steps.length ? s.how_steps.slice() : [];
    while (steps.length < 4) steps.push({ title: '', desc: '' });

    let h = `<h1>⚙️ Settings</h1>`;
    h += deliveryBar({ ...d.delivery, zone: s.weekly_zone });
    h += `<form id="settings-form">
      <div class="panel"><h3 class="sec">📅 This Week's Delivery</h3>
        <div class="grid2">${input('weekly_zone', 'Area', 'placeholder="Westchester County, NY"')}${input('weekly_delivery_day', 'Delivery Day', 'placeholder="Saturday, March 29"')}</div>
        <div class="grid3">${input('weekly_deadline', 'Deadline', 'placeholder="Thursday 8pm"')}${input('minimum_order', 'Min. Order ($)', 'type="number" min="0" step="1"')}${input('max_orders', 'Max Orders (0 = no limit)', 'type="number" min="0" step="1"')}</div>
      </div>
      <div class="panel"><h3 class="sec">✏️ Content</h3>
        <p class="small muted" style="margin-top:-6px">Customer-facing texts. <code>{{minimum}}</code> = min order amount.</p>
        ${input('how_title', 'How It Works — Title')}
        <div class="field"><label class="lbl">Steps</label><div id="steps"></div><button type="button" class="btn sm" data-act="add-step">+ Add step</button></div>
        ${input('how_note', 'Footer Note')}
        ${input('checkout_note', 'Checkout Note')}
        <div class="grid2">${input('confirm_title', 'Confirm Title')}${input('confirm_message', 'Confirm Message')}</div>
      </div>
      <div class="panel"><h3 class="sec">🔒 When Closed</h3>
        <div class="field"><label class="lbl">Message</label><textarea class="input" name="closed_message">${esc(s.closed_message)}</textarea></div>
        ${input('ubereats_url', 'Uber Eats URL', 'type="url"')}
      </div>`;
    if (admin) {
      h += `<h2 class="muted" style="font-size:16px;margin:24px 0 14px;padding-top:18px;border-top:3px solid var(--line)">🔐 Administrator Only</h2>
      <div class="panel" style="border-left:4px solid ${s.stripe_mode === 'live' ? 'var(--green)' : 'var(--orange)'}" id="stripe-card"><h3 class="sec">💳 Stripe</h3>
        <input type="hidden" name="stripe_mode" value="${esc(s.stripe_mode)}">
        <div class="seg"><button type="button" class="test ${s.stripe_mode !== 'live' ? 'on' : ''}" data-act="mode" data-mode="test">🧪 Test</button><button type="button" class="live ${s.stripe_mode === 'live' ? 'on' : ''}" data-act="mode" data-mode="live">🟢 Live</button></div>
        <div class="small">
          <div class="kv"><span class="k">Test keys (STRIPE_TEST_PUBLISHABLE_KEY / STRIPE_TEST_SECRET_KEY)</span><span class="${ig.stripe.testReady ? 'ok' : 'bad'}">${ig.stripe.testReady ? '✓ Set' : '✗ Missing'}</span></div>
          <div class="kv"><span class="k">Live keys (STRIPE_LIVE_PUBLISHABLE_KEY / STRIPE_LIVE_SECRET_KEY)</span><span class="${ig.stripe.liveReady ? 'ok' : 'bad'}">${ig.stripe.liveReady ? '✓ Set' : '✗ Missing'}</span></div>
          <div class="kv"><span class="k">Webhook secrets (STRIPE_TEST_WEBHOOK_SECRET / STRIPE_LIVE_WEBHOOK_SECRET)</span><span>${ig.stripe.testWebhook ? '<span class="ok">test ✓</span>' : '<span class="bad">test ✗</span>'} ${ig.stripe.liveWebhook ? '<span class="ok">live ✓</span>' : '<span class="bad">live ✗</span>'}</span></div>
        </div>
        <p class="tiny muted">Keys are Cloudflare secrets (never stored in the database): <code>npx wrangler pages secret put STRIPE_LIVE_SECRET_KEY</code>. Webhook endpoint (events <code>payment_intent.succeeded</code>, <code>charge.refunded</code>):</p>
        <div class="snippet mono" data-act="copy" title="Click to copy">${esc(ig.stripe.webhookUrl)}</div>
        <p class="tiny muted">Test card: <code>4242 4242 4242 4242</code>, any future date, any CVC. If the active mode has no keys, the shop takes orders without online payment.</p>
      </div>
      <div class="panel"><h3 class="sec">📧 Notifications & MailerLite</h3>
        ${input('admin_email', 'Admin Email (new order notifications)', 'type="email"')}
        <p class="small">Email provider (Resend, <code>RESEND_API_KEY</code>): <span class="${ig.email.configured ? 'ok' : 'bad'}">${ig.email.configured ? '✓ Configured' : '✗ Not configured — emails are not sent'}</span>${ig.email.from ? ` · From: <code>${esc(ig.email.from)}</code>` : ''}</p>
        ${input('mailerlite_group_id', 'MailerLite Group ID')}
        <p class="small">MailerLite API token (<code>MAILERLITE_API_KEY</code>): <span class="${ig.mailerlite.configured ? 'ok' : 'muted'}">${ig.mailerlite.configured ? '✓ Set' : 'Not set (sync disabled)'}</span></p>
      </div>
      <div class="panel"><h3 class="sec">🏪 Business (emails & print ticket)</h3>
        <div class="grid2">${input('business_name', 'Name')}${input('business_phone', 'Phone')}</div>
        ${input('business_address', 'Address')}
        <div class="grid2">${input('business_website', 'Website')}${input('business_instagram', 'Instagram')}</div>
        ${input('order_page_url', 'Order page URL (link in "Delivery is open" emails)', `type="url" placeholder="${esc(ig.orderPageDefault)}"`)}
      </div>`;
    }
    h += `<button class="btn primary" type="submit" style="padding:10px 22px;font-size:14px">Save Settings</button></form>`;
    const origin = location.origin;
    h += `<div class="panel" style="margin-top:18px"><h3 class="sec">📋 Embed on your site</h3>
      <p class="small muted">Replaces the WordPress shortcodes. Click to copy. The order page is also available at <a href="/" target="_blank">${esc(origin)}/</a>.</p>
      <label class="lbl">Ordering system — [visit_sicily_order]</label>
      <div class="snippet mono" data-act="copy">${esc(`<link rel="stylesheet" href="${origin}/vso/order-app.css">\n<div id="vso-order-app"></div>\n<script src="${origin}/vso/order-app.js" data-api="${origin}/api"></script>`)}</div>
      <label class="lbl">Status badge — [visit_sicily_status]</label>
      <div class="snippet mono" data-act="copy">${esc(`<div class="vso-status" data-order-url="${origin}/"></div>\n<script src="${origin}/vso/status-widget.js" data-api="${origin}/api" defer></script>`)}</div>
      <p class="tiny muted">On a different domain, add that domain to <code>ALLOWED_ORIGINS</code>.</p>
    </div>`;
    if (admin) {
      h += `<div class="panel danger"><h3 class="sec" style="color:var(--red)">⚠️ Data Management</h3>
        <p class="small muted">Permanent and irreversible actions.</p>
        <div class="danger-row"><div><strong class="small">Delete All Orders</strong><div class="tiny muted">All orders, items and messages</div></div><button class="btn red" data-act="reset" data-scope="orders">🗑 Delete</button></div>
        <div class="danger-row"><div><strong class="small">Delete All Subscribers</strong></div><button class="btn red" data-act="reset" data-scope="subscribers">🗑 Delete</button></div>
        <div class="danger-row" style="border:1px dashed var(--red)"><div><strong class="small" style="color:var(--red)">Factory Reset</strong><div class="tiny muted">All orders + messages + subscribers (products and settings are kept)</div></div><button class="btn red" data-act="reset" data-scope="factory">⚠️ Reset</button></div>
      </div>`;
    }
    view.innerHTML = h;

    const stepsEl = document.getElementById('steps');
    function renderSteps() {
      stepsEl.innerHTML = steps.map((st, i) => `<div class="step-row"><span class="step-num">${i + 1}</span>
        <input class="input" data-k="title" data-i="${i}" value="${esc(st.title)}" placeholder="Title">
        <input class="input" data-k="desc" data-i="${i}" value="${esc(st.desc)}" placeholder="Description">
        <button type="button" class="btn sm danger-text" data-act="rm-step" data-i="${i}" title="Remove">×</button></div>`).join('');
    }
    stepsEl.addEventListener('input', (e) => {
      const t = e.target;
      if (t.dataset.k) steps[Number(t.dataset.i)][t.dataset.k] = t.value;
    });
    renderSteps();

    const form = document.getElementById('settings-form');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const payload = {};
      form.querySelectorAll('[name]').forEach((el) => { payload[el.name] = el.value.trim(); });
      payload.how_steps = steps.filter((st) => st.title.trim());
      run(form.querySelector('[type=submit]'), async () => {
        await api('PUT', '/settings', payload);
        toast('Settings saved!', 'success');
        if (admin && payload.business_name !== S.me.businessName) {
          S.me.businessName = payload.business_name;
          renderHeader();
        }
        route();
      });
    });

    actions = {
      'add-step': () => {
        if (steps.length >= 6) return toast('Up to 6 steps');
        steps.push({ title: '', desc: '' });
        renderSteps();
      },
      'rm-step': (btn) => {
        steps.splice(Number(btn.dataset.i), 1);
        renderSteps();
      },
      mode: (btn) => {
        const mode = btn.dataset.mode;
        if (mode === 'live' && !ig.stripe.liveReady && !confirm('Live keys are not set. In live mode the shop will take orders WITHOUT online payment until you add them. Continue?')) return;
        form.elements.stripe_mode.value = mode;
        view.querySelectorAll('.seg button').forEach((b) => b.classList.toggle('on', b.dataset.mode === mode));
        document.getElementById('stripe-card').style.borderLeftColor = mode === 'live' ? 'var(--green)' : 'var(--orange)';
        toast('Click "Save Settings" to apply the Stripe mode.');
      },
      copy: (el) => {
        navigator.clipboard.writeText(el.textContent).then(() => toast('Copied!', 'success'), () => toast('Copy failed', 'error'));
      },
      reset: (btn) => {
        const scope = btn.dataset.scope;
        const labels = { orders: 'Delete ALL orders and messages?', subscribers: 'Delete ALL subscribers?', factory: 'FACTORY RESET: delete all orders, messages and subscribers?' };
        if (!confirm(labels[scope])) return;
        let confirmText;
        if (scope === 'factory') {
          confirmText = (prompt('Type RESET to confirm') || '').trim().toUpperCase();
          if (confirmText !== 'RESET') return;
        }
        run(btn, async () => {
          const r = await api('POST', '/reset', { scope, confirm: confirmText });
          const parts = Object.entries(r.deleted).map(([k, n]) => `${n} ${k}`);
          toast(`Deleted ${parts.join(' and ')}.`, 'success');
        });
      },
    };
  }

  // ─── Users ───
  async function viewUsers({ alive }) {
    if (!isAdmin()) {
      view.innerHTML = '<div class="notice err">Administrator access required.</div>';
      return;
    }
    const d = await api('GET', '/users');
    if (!alive()) return;
    let h = `<h1>👥 Users</h1>
      <p class="small muted">People who pass Cloudflare Access still need to be listed here (or in <code>ADMIN_EMAILS</code>) to use the panel.
      <strong>Delivery Managers</strong> handle orders, products, categories, subscribers and weekly settings. <strong>Admins</strong> also manage Stripe, notifications, business info, users and data resets.</p>
      <div class="panel"><h3 class="sec">Add or change a user</h3>
        <form id="uform" class="row-flex">
          <input class="input grow" name="email" type="email" required placeholder="name@example.com" style="min-width:200px">
          <select class="input" name="role" style="width:auto"><option value="manager">Delivery Manager</option><option value="admin">Admin</option></select>
          <button class="btn primary" type="submit">Save</button>
        </form>
        <p class="tiny muted">Remember to also allow this email in your Cloudflare Access policy.</p>
      </div>
      <div class="panel flush">`;
    for (const email of d.bootstrapAdmins) {
      h += `<div class="list-row"><div class="grow"><div class="t">${esc(email)}</div><div class="s">Admin · from ADMIN_EMAILS (edit in Cloudflare settings)</div></div></div>`;
    }
    h += d.users.map((u) => `<div class="list-row"><div class="grow"><div class="t">${esc(u.email)}${u.email === d.you ? ' <span class="badge" style="background:#f0ebe4">you</span>' : ''}</div><div class="s">${u.role === 'admin' ? 'Admin' : 'Delivery Manager'} · added ${esc(fmtDate(u.created_at))}</div></div>
      ${u.email !== d.you ? `<button class="btn sm danger-text" data-act="del" data-email="${esc(u.email)}">Remove</button>` : ''}</div>`).join('');
    if (!d.users.length && !d.bootstrapAdmins.length) h += '<div class="empty">No users yet.</div>';
    h += '</div>';
    view.innerHTML = h;
    const form = document.getElementById('uform');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      run(form.querySelector('[type=submit]'), async () => {
        await api('POST', '/users', { email: form.elements.email.value.trim(), role: form.elements.role.value });
        toast('User saved', 'success');
        route();
      });
    });
    actions = {
      del: (btn) => {
        if (!confirm(`Remove ${btn.dataset.email}?`)) return;
        run(btn, async () => {
          await api('DELETE', `/users/${encodeURIComponent(btn.dataset.email)}`);
          route();
        });
      },
    };
  }

  // ─── Boot ───
  async function init() {
    try {
      S.me = await api('GET', '/me');
    } catch (e) {
      view.innerHTML = `<div class="panel" style="max-width:520px;margin:40px auto;text-align:center"><h3 style="border:none">Access denied</h3><p>${esc(e.message)}</p><p><a class="btn" href="/cdn-cgi/access/logout">Sign in with another account</a></p></div>`;
      return;
    }
    S.statuses = S.me.statuses;
    S.paymentStatuses = S.me.paymentStatuses;
    S.delivery = S.me.delivery;
    renderHeader();
    window.addEventListener('hashchange', route);
    route();
  }

  init();
})();
