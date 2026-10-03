/* VS Orders — customer ordering app. Mount: <div id="vso-order-app"></div><script src="/vso/order-app.js"></script> */
(function () {
  const app = document.getElementById('vso-order-app');
  if (!app) return;
  const script = document.currentScript;
  const API = ((window.VSO_CONFIG && window.VSO_CONFIG.apiBase) || (script && script.dataset.api) || '/api').replace(/\/$/, '');
  const PENDING_KEY = 'vso_pending_order';
  const ZONES = ['Manhattan', 'Brooklyn', 'Queens', 'The Bronx', 'Staten Island', 'Westchester', 'Long Island', 'New Jersey', 'Connecticut', 'Other'];

  let CFG = { stripeEnabled: false, stripePk: '', stripeTestMode: false };
  let C = {};
  const S = {
    step: 'loading', products: [], categories: [], cart: {}, activeCat: '',
    form: { name: '', email: '', phone: '', address: '', notes: '' },
    error: '', orderRef: '', orderTotal: 0, submitting: false,
    deliveryActive: false, atCapacity: false, uberEatsUrl: '', closedMessage: '', minimumOrder: 100,
    weeklyZone: '', weeklyDeadline: '', weeklyDeliveryDay: '',
    showSubForm: false, subDone: false, subError: '',
    intent: null, stripeSetupError: '',
  };

  let stripe = null;
  let elements = null;
  let stripeReady = null;
  let mountToken = 0;

  // ─── Helpers ───
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  async function apiGet(path) {
    const r = await fetch(API + path, { cache: 'no-store' });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed (' + r.status + ')');
    return d;
  }
  async function apiPost(path, body) {
    const r = await fetch(API + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed (' + r.status + ')');
    return d;
  }
  function loadStripeJs() {
    return new Promise((resolve, reject) => {
      if (window.Stripe) return resolve();
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/v3/';
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Could not load the payment form.'));
      document.head.appendChild(s);
    });
  }

  const cc = () => Object.values(S.cart).reduce((s, q) => s + q, 0);
  const ct = () => Object.entries(S.cart).reduce((s, [id, q]) => { const p = S.products.find((x) => x.id == id); return s + (p ? p.price * q : 0); }, 0);
  const ci = () => Object.entries(S.cart).filter(([, q]) => q > 0).map(([id, qty]) => { const p = S.products.find((x) => x.id == id); return p ? { ...p, qty: Number(qty) } : null; }).filter(Boolean);
  const cartItems = () => ci().map((i) => ({ id: i.id, qty: i.qty }));
  const cartKey = () => JSON.stringify(cartItems());
  const formOk = () => { const f = S.form; return !!(f.name && f.email && f.phone && f.address); };

  function savePending() {
    try { sessionStorage.setItem(PENDING_KEY, JSON.stringify({ form: S.form, cart: S.cart, intentId: S.intent && S.intent.id, at: Date.now() })); } catch (e) { /* storage unavailable */ }
  }
  function readPending() {
    try { return JSON.parse(sessionStorage.getItem(PENDING_KEY) || 'null'); } catch (e) { return null; }
  }
  function clearPending() {
    try { sessionStorage.removeItem(PENDING_KEY); } catch (e) { /* storage unavailable */ }
  }

  // ─── Load ───
  async function load() {
    try {
      const d = await apiGet('/products?_t=' + Date.now());
      S.products = d.products || [];
      S.categories = d.categories || [];
      S.activeCat = (S.categories[0] && S.categories[0].slug) || '';
      S.deliveryActive = d.deliveryActive; S.atCapacity = d.atCapacity; S.uberEatsUrl = d.uberEatsUrl; S.closedMessage = d.closedMessage;
      S.minimumOrder = d.minimumOrder || 0; S.weeklyZone = d.weeklyZone; S.weeklyDeadline = d.weeklyDeadline; S.weeklyDeliveryDay = d.weeklyDeliveryDay;
      C = d.content || {};
      const st = d.stripe || {};
      CFG = { stripeEnabled: !!st.enabled, stripePk: st.publishableKey || '', stripeTestMode: !!st.testMode };
      if (CFG.stripeEnabled) {
        stripeReady = loadStripeJs();
        stripeReady.catch(() => {});
      }
      S.step = 'menu';
    } catch (e) {
      S.step = 'menu';
      S.error = 'Could not load menu.';
    }
    if (!(await resumeRedirectedPayment())) render();
  }

  /** Completes an order after a redirect-based payment method (PayPal, Klarna, Cash App…) returns. */
  async function resumeRedirectedPayment() {
    const qs = new URLSearchParams(location.search);
    const pi = qs.get('payment_intent');
    if (!pi) return false;
    history.replaceState(null, '', location.pathname + location.hash);
    const pending = readPending();
    if (!pending || pending.intentId !== pi) return false;
    S.form = pending.form; S.cart = pending.cart; S.intent = { id: pi };
    if (qs.get('redirect_status') === 'failed') {
      clearPending();
      S.intent = null;
      S.step = 'checkout';
      S.error = 'Payment failed or was cancelled. Please try again.';
      render();
      return true;
    }
    app.innerHTML = '<div class="vso-loading">Finalizing your order…</div>';
    try {
      await postOrder();
    } catch (x) {
      S.step = 'checkout';
      S.error = x.message;
      render();
    }
    return true;
  }

  // ─── Render ───
  function fi(n, l, v, t, r) {
    return `<div class="vso-fg"><label>${l} ${r ? '<span class="req">*</span>' : ''}</label><input type="${t}" value="${esc(v)}" oninput="vsoF('${n}',this.value)" placeholder="${l}" autocomplete="${{ name: 'name', email: 'email', phone: 'tel', address: 'street-address' }[n] || 'off'}"></div>`;
  }

  function updateSubmitBtn() {
    const btn = app.querySelector('.vso-submit');
    if (btn) btn.disabled = !formOk() || S.submitting;
  }

  function render() {
    if (S.step === 'loading') { app.innerHTML = '<div class="vso-loading">Loading menu...</div>'; return; }
    if (S.step === 'menu') return renderMenu();
    if (S.step === 'checkout') return renderCheckout();
    if (S.step === 'confirm') return renderConfirm();
  }

  function renderMenu() {
    const cats = S.categories;
    const prods = S.products.filter((p) => p.category === S.activeCat);
    const count = cc(); const total = ct();
    const cn = (cats.find((c) => c.slug === S.activeCat) || {}).name || '';
    const isOpen = S.deliveryActive;
    const minMet = total >= S.minimumOrder;
    const pct = S.minimumOrder > 0 ? Math.min(100, (total / S.minimumOrder) * 100) : 100;
    const steps = C.howSteps || [];

    let h = '';
    if (steps.length) {
      h += `<div class="vso-how"><div class="vso-how-hd" onclick="this.parentElement.classList.toggle('collapsed')"><h3>${esc(C.howTitle || '')}</h3><span class="vso-how-arr">▾</span></div><div class="vso-how-inner"><div class="vso-how-steps">`;
      steps.forEach((s, i) => { h += `<div class="vso-how-step"><div class="num">${i + 1}</div><div class="t">${esc(s.title)}</div><div class="d">${esc(s.desc)}</div></div>`; });
      h += `</div>`;
      if (C.howNote) h += `<div class="vso-how-note">${esc(C.howNote)}</div>`;
      h += `</div></div>`;
    }
    if (isOpen && S.weeklyZone) {
      h += `<div class="vso-zone-on"><span class="dot"></span><div style="flex:1"><div class="area">📍 ${esc(S.weeklyZone)}</div><div class="meta">`;
      if (S.weeklyDeliveryDay) h += `<span>🗓 ${esc(S.weeklyDeliveryDay)}</span>`;
      if (S.weeklyDeadline) h += `<span>⏰ Order by ${esc(S.weeklyDeadline)}</span>`;
      h += `</div></div></div>`;
    } else if (!isOpen) {
      h += `<div class="vso-zone-off"><span class="dot"></span><span>Orders are currently closed — browse the menu and subscribe to get notified</span></div>`;
    }
    if (S.atCapacity) h += `<div class="vso-err" style="text-align:center;font-weight:600;">⚠ Capacity reached. Subscribe for the next round!</div>`;
    if (isOpen && count > 0) h += `<div class="vso-min"><span class="lbl ${minMet ? 'ok' : ''}">${minMet ? '✓ Minimum met' : '$' + total.toFixed(0) + ' / $' + S.minimumOrder}</span><div class="track"><div class="fill" style="width:${pct}%"></div></div></div>`;

    h += `<div class="vso-cats">`;
    cats.forEach((c) => { h += `<button class="vso-cat ${c.slug === S.activeCat ? 'on' : ''}" data-cat="${esc(c.slug)}">${c.icon ? `<span class="ic">${esc(c.icon)}</span>` : ''}${esc(c.name)}</button>`; });
    h += `</div>`;
    h += `<div class="vso-body"><h3 class="vso-stitle">${esc(cn)}</h3>`;
    if (S.error) h += `<div class="vso-err">${esc(S.error)}</div>`;
    prods.forEach((p) => {
      const q = S.cart[p.id] || 0;
      h += `<div class="vso-item ${q ? 'ic' : ''}">`;
      if (p.image) h += `<img src="${esc(p.image)}" class="vso-item-img" alt="${esc(p.name)}" loading="lazy" onclick="vsoZoom(${p.id})">`;
      h += `<div class="vso-info"><div class="vso-name">${esc(p.name)}</div><div class="vso-desc">${esc(p.description)}</div><div class="vso-price">$${p.price.toFixed(2)}</div></div>`;
      if (isOpen) {
        h += `<div class="vso-acts">${q ? `<button class="vso-qb rm" onclick="vsoRm(${p.id})" aria-label="Remove one">−</button><span class="vso-qty">${q}</span>` : ''}
      <button class="vso-qb add" onclick="vsoAdd(${p.id})" aria-label="Add one">+</button></div>`;
      }
      h += `</div>`;
    });
    if (!prods.length) h += '<p style="text-align:center;color:#8B7355;padding:30px;">No items in this category.</p>';
    h += `</div>`;

    if (isOpen) {
      h += `<div class="vso-bar ${count === 0 ? 'hide' : ''}"><div class="vso-bar-l"><div class="vso-badge">${count}</div><div><div class="vso-bar-total">$${total.toFixed(2)}</div>${!minMet ? `<div class="vso-bar-min">$${(S.minimumOrder - total).toFixed(0)} more</div>` : ''}</div></div>
      <button class="vso-bar-btn" ${!minMet ? 'disabled' : ''} onclick="vsoGo('checkout')">${minMet ? 'Order Now →' : 'Min $' + S.minimumOrder}</button></div>`;
    } else {
      h += `<div class="vso-cbar"><div class="vso-cbar-msg">${esc(S.closedMessage)}</div>`;
      if (S.subDone) {
        h += `<div class="vso-sub-ok">✓ Subscribed! We'll notify you.</div>`;
      } else if (S.showSubForm) {
        h += `<div class="vso-subf"><div class="row"><input type="text" id="vso-sn" placeholder="Your name" autocomplete="name"><input type="email" id="vso-se" placeholder="your@email.com" autocomplete="email"></div>
        ${S.subError ? `<div class="err">${esc(S.subError)}</div>` : ''}
        <select id="vso-sz"><option value="">Your area (optional)</option>${ZONES.map((z) => `<option>${z}</option>`).join('')}</select>
        <button onclick="vsoSub()">📩 Notify Me</button></div>`;
      } else {
        h += `<div class="vso-cbar-btns"><button class="b1" onclick="vsoShowSub()">📩 Get Notified</button>`;
        if (S.uberEatsUrl) h += `<a class="b2" href="${esc(S.uberEatsUrl)}" target="_blank" rel="noopener">🛵 Order on Uber Eats</a>`;
        h += `</div>`;
      }
      h += `</div>`;
    }
    app.innerHTML = h;
    app.querySelectorAll('.vso-cat').forEach((b) => b.addEventListener('click', () => { S.activeCat = b.dataset.cat; render(); }));
  }

  function renderCheckout() {
    const items = ci(); const total = ct(); const f = S.form; const note = C.checkoutNote || '';
    let h = `<div class="vso-chk-back"><button onclick="vsoGo('menu')">← Back</button><span>Your Order</span></div>`;
    if (S.weeklyZone) h += `<div class="vso-note" style="text-align:center;font-size:14px;">📍 <strong>${esc(S.weeklyZone)}</strong>${S.weeklyDeliveryDay ? ' · 🗓 ' + esc(S.weeklyDeliveryDay) : ''}</div>`;
    h += `<div class="vso-card"><div class="vso-card-title">Order Summary</div>`;
    items.forEach((i) => { h += `<div class="vso-srow"><div><span class="vso-srow-name">${esc(i.name)}</span><span class="vso-srow-qty">× ${i.qty}</span></div><span class="vso-srow-price">$${(i.price * i.qty).toFixed(2)}</span></div>`; });
    h += `<div class="vso-stotal"><span>Total</span><span>$${total.toFixed(2)}</span></div></div>`;
    if (note) h += `<div class="vso-note">🚗 ${esc(note)}</div>`;
    if (S.error) h += `<div class="vso-err">${esc(S.error)}</div>`;
    h += `<div class="vso-card"><div class="vso-card-title">Your Details</div>`;
    h += fi('name', 'Full Name', f.name, 'text', 1) + fi('email', 'Email', f.email, 'email', 1) + fi('phone', 'Phone / WhatsApp', f.phone, 'tel', 1) + fi('address', 'Delivery Address', f.address, 'text', 1);
    h += `<div class="vso-fg"><label>Notes</label><textarea placeholder="Buzzer code, allergies…" oninput="vsoF('notes',this.value)">${esc(f.notes)}</textarea></div></div>`;
    if (CFG.stripeEnabled) {
      h += `<div class="vso-card"><div class="vso-card-title">Payment${CFG.stripeTestMode ? ' <span style="color:#e67e22;">(TEST)</span>' : ''}</div>`;
      if (S.stripeSetupError) {
        h += `<div class="vso-err">Payment setup failed: ${esc(S.stripeSetupError)}<br><button class="vso-retry" onclick="vsoRetryStripe()">Retry</button></div>`;
      } else {
        h += `<div id="vso-express-el" style="margin-bottom:12px;"></div><div id="vso-payment-el"><div class="vso-loading" style="padding:12px;font-size:13px;">Loading payment options…</div></div>`;
      }
      h += `</div>`;
    }
    const ok = formOk();
    const payBlocked = CFG.stripeEnabled && !!S.stripeSetupError;
    h += `<button class="vso-submit ${S.submitting ? 'ld' : ''}" ${!ok || S.submitting || payBlocked ? 'disabled' : ''} onclick="vsoSubmit()">${CFG.stripeEnabled ? 'Pay & Place Order' : 'Place Order'} — $${total.toFixed(2)}</button>`;
    app.innerHTML = h;
    if (CFG.stripeEnabled && !S.stripeSetupError) mountStripe();
  }

  function renderConfirm() {
    const f = S.form; const count = cc(); const total = S.orderTotal || ct();
    const title = C.confirmTitle || 'Thank you!'; const msg = C.confirmMessage || 'Your order has been received.';
    app.innerHTML = `<div class="vso-confirm"><div class="vso-confirm-icon">🇮🇹</div><h3>${esc(title)}</h3>
    <p class="sub">${esc(msg)}${S.weeklyDeliveryDay ? " We'll see you on <strong>" + esc(S.weeklyDeliveryDay) + '</strong>.' : ''}</p>
    <div class="ref-label">Your order number</div><div class="ref-box">${esc(S.orderRef)}</div>
    <div class="vso-confirm-det"><strong>Name:</strong> ${esc(f.name)}<br><strong>Email:</strong> ${esc(f.email)}<br><strong>Phone:</strong> ${esc(f.phone)}<br><strong>Address:</strong> ${esc(f.address)}<br>${S.weeklyZone ? '<strong>Area:</strong> ' + esc(S.weeklyZone) + '<br>' : ''}<strong>Items:</strong> ${count}</div>
    <div class="vso-confirm-tot">Total: $${Number(total).toFixed(2)}</div>
    <p style="font-size:12px;color:#8B7355;margin-bottom:16px;">Confirmation sent to <strong>${esc(f.email)}</strong></p>
    <button class="vso-neworder" onclick="vsoReset()">New Order</button></div>`;
  }

  // ─── Stripe ───
  async function mountStripe() {
    const token = ++mountToken;
    elements = null;
    try {
      await stripeReady;
      if (!stripe) stripe = window.Stripe(CFG.stripePk);
      const key = cartKey();
      if (!S.intent || !S.intent.clientSecret || S.intent.cartKey !== key) {
        const d = await apiPost('/stripe/create-intent', { items: cartItems(), name: S.form.name, email: S.form.email });
        S.intent = { id: d.paymentIntentId, clientSecret: d.clientSecret, cartKey: key };
      }
      if (token !== mountToken || S.step !== 'checkout') return;
      const els = stripe.elements({
        clientSecret: S.intent.clientSecret,
        appearance: { theme: 'stripe', variables: { colorPrimary: '#2C1810', colorText: '#2C1810', fontFamily: "'DM Sans',sans-serif", borderRadius: '8px' } },
      });
      const ex = document.getElementById('vso-express-el');
      if (ex) {
        ex.innerHTML = '';
        const ee = els.create('expressCheckout', { buttonType: { applePay: 'buy', googlePay: 'buy', paypal: 'buynow' }, layout: { maxColumns: 3, maxRows: 1 } });
        ee.on('click', (ev) => {
          if (!formOk()) { showInlineError('Please fill in your details above before paying.'); return; }
          ev.resolve();
        });
        ee.on('confirm', () => payAndOrder(false));
        ee.on('ready', ({ availablePaymentMethods }) => { if (!availablePaymentMethods) ex.style.display = 'none'; });
        ee.mount(ex);
      }
      const pe = document.getElementById('vso-payment-el');
      if (pe) {
        pe.innerHTML = '';
        els.create('payment', { layout: 'tabs' }).mount(pe);
      }
      elements = els;
    } catch (x) {
      if (token !== mountToken) return;
      S.stripeSetupError = x.message || 'Unknown error';
      renderCheckout();
    }
  }

  function showInlineError(msg) {
    S.error = msg;
    let box = app.querySelector('.vso-err');
    if (!box) {
      box = document.createElement('div');
      box.className = 'vso-err';
      const details = app.querySelectorAll('.vso-card')[1];
      if (details) details.parentNode.insertBefore(box, details); else app.prepend(box);
    }
    box.textContent = msg;
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  async function confirmPayment(withBilling) {
    if (!stripe || !elements) throw new Error('The payment form is still loading. Please wait a moment.');
    savePending();
    const f = S.form;
    const confirmParams = { return_url: location.origin + location.pathname };
    if (withBilling) confirmParams.payment_method_data = { billing_details: { name: f.name, email: f.email, phone: f.phone } };
    const { error } = await stripe.confirmPayment({ elements, confirmParams, redirect: 'if_required' });
    if (error) { clearPending(); throw new Error(error.message); }
  }

  async function payAndOrder(withBilling) {
    if (S.submitting) return;
    if (!formOk()) return;
    S.submitting = true; S.error = '';
    const btn = app.querySelector('.vso-submit');
    if (btn) { btn.disabled = true; btn.classList.add('ld'); }
    try {
      if (CFG.stripeEnabled) await confirmPayment(withBilling);
      await postOrder();
    } catch (x) {
      S.error = x.message;
      S.submitting = false;
      renderCheckout();
    }
  }

  async function postOrder() {
    const f = S.form;
    const d = await apiPost('/orders', { ...f, zone: S.weeklyZone, items: cartItems(), stripe_pi: (S.intent && S.intent.id) || '' });
    clearPending();
    S.orderRef = d.ref; S.orderTotal = d.total; S.intent = null; S.step = 'confirm'; S.submitting = false;
    render();
    app.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function vsoSub() {
    const em = ((document.getElementById('vso-se') || {}).value || '').trim();
    const n = ((document.getElementById('vso-sn') || {}).value || '').trim();
    const z = (document.getElementById('vso-sz') || {}).value || '';
    if (!em || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
      S.subError = 'Please enter a valid email.'; render();
      const input = document.getElementById('vso-se'); if (input) { input.value = em; input.focus(); }
      const nameInput = document.getElementById('vso-sn'); if (nameInput) nameInput.value = n;
      return;
    }
    try { await apiPost('/subscribe', { email: em, name: n, zone: z }); } catch (x) { /* shown as subscribed either way, like the plugin */ }
    S.subDone = true; S.subError = ''; render();
  }

  // ─── Globals used by inline handlers ───
  window.vsoAdd = function (id) { if (!S.deliveryActive) return; S.cart[id] = (S.cart[id] || 0) + 1; render(); };
  window.vsoRm = function (id) { if (S.cart[id] > 1) S.cart[id]--; else delete S.cart[id]; render(); };
  window.vsoGo = function (step) { S.step = step; S.error = ''; if (step === 'menu') mountToken++; render(); window.scrollTo({ top: app.offsetTop - 20 }); };
  window.vsoF = function (k, v) { S.form[k] = v; updateSubmitBtn(); };
  window.vsoShowSub = function () { S.showSubForm = true; render(); setTimeout(() => { const el = document.getElementById('vso-sn'); if (el) el.focus(); }, 50); };
  window.vsoSub = vsoSub;
  window.vsoSubmit = function () { payAndOrder(true); };
  window.vsoRetryStripe = function () { S.stripeSetupError = ''; S.intent = null; if (CFG.stripeEnabled && !window.Stripe) { stripeReady = loadStripeJs(); stripeReady.catch(() => {}); } renderCheckout(); };
  window.vsoReset = function () { S.cart = {}; S.form = { name: '', email: '', phone: '', address: '', notes: '' }; S.orderRef = ''; S.orderTotal = 0; S.intent = null; S.step = 'menu'; S.activeCat = (S.categories[0] && S.categories[0].slug) || ''; render(); };
  window.vsoZoom = function (id) {
    const p = S.products.find((x) => x.id === id);
    if (!p || !p.image) return;
    const lb = document.createElement('div');
    lb.className = 'vso-lightbox';
    const img = document.createElement('img'); img.src = p.imageFull || p.image; img.alt = p.name;
    const cap = document.createElement('div'); cap.className = 'caption'; cap.textContent = p.name;
    const hint = document.createElement('div'); hint.className = 'close-hint'; hint.textContent = 'Tap anywhere to close';
    lb.append(img, cap, hint);
    lb.onclick = () => lb.remove();
    document.body.appendChild(lb);
  };

  render();
  load();
})();
