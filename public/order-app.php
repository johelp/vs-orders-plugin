<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&display=swap');

/* Prevent double-tap zoom on the entire ordering widget */
#vso-order-app{font-family:'DM Sans',sans-serif;color:#2C1810;max-width:680px;margin:0 auto;min-height:60vh;touch-action:manipulation}
#vso-order-app *{box-sizing:border-box}
#vso-order-app button,#vso-order-app a,#vso-order-app input,#vso-order-app select,#vso-order-app textarea{touch-action:manipulation}

/* How it works */
.vso-how{background:#fff;padding:16px;border:1px solid rgba(44,24,16,0.08);border-radius:10px;margin-bottom:12px}
.vso-how.collapsed .vso-how-inner{display:none}
.vso-how.collapsed .vso-how-arr{transform:rotate(-90deg)}
.vso-how-hd{cursor:pointer;display:flex;align-items:center;justify-content:space-between;-webkit-tap-highlight-color:transparent}
.vso-how-hd h3{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:700;color:#2C1810;margin:0}
.vso-how-arr{font-size:12px;color:#8B7355;transition:transform .2s}
.vso-how-inner{margin-top:12px}
.vso-how-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px}
.vso-how-step{background:#FAF6F1;border-radius:8px;padding:10px;text-align:center}
.vso-how-step .num{display:inline-flex;width:22px;height:22px;border-radius:50%;background:#2C1810;color:#D4AF37;align-items:center;justify-content:center;font-size:10px;font-weight:700;margin-bottom:4px}
.vso-how-step .t{font-size:12px;font-weight:600;color:#2C1810;margin-bottom:1px}
.vso-how-step .d{font-size:11px;color:#8B7355;line-height:1.3}
.vso-how-note{text-align:center;margin-top:10px;font-size:11px;color:#8B7355;line-height:1.5}

/* Zone banners */
.vso-zone-on{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#eafaf1;border:1.5px solid #27ae60;border-radius:10px;margin-bottom:12px}
.vso-zone-on .dot{width:10px;height:10px;border-radius:50%;background:#27ae60;box-shadow:0 0 6px rgba(39,174,96,.4);flex-shrink:0}
.vso-zone-on .area{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:700;color:#2C1810}
.vso-zone-on .meta{font-size:12px;color:#4A2C20;display:flex;gap:12px;flex-wrap:wrap;margin-top:2px}
.vso-zone-off{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#fdedec;border:1.5px solid #e74c3c;border-radius:10px;margin-bottom:12px;font-size:13px;color:#c0392b}
.vso-zone-off .dot{width:10px;height:10px;border-radius:50%;background:#e74c3c;flex-shrink:0}

/* Min progress */
.vso-min{display:flex;align-items:center;gap:10px;padding:8px 12px;background:rgba(212,175,55,0.06);border-radius:8px;margin-bottom:8px}
.vso-min .lbl{font-size:11px;color:#8B7355;white-space:nowrap}.vso-min .lbl.ok{color:#27ae60;font-weight:600}
.vso-min .track{flex:1;height:6px;background:rgba(44,24,16,0.08);border-radius:3px;overflow:hidden}
.vso-min .fill{height:100%;background:#D4AF37;border-radius:3px;transition:width .3s}

/* Categories — wrap on desktop, scroll on mobile */
.vso-cats{display:flex;gap:6px;padding:10px 0;position:sticky;top:0;z-index:10;border-bottom:1px solid rgba(44,24,16,0.08);background:inherit;flex-wrap:wrap}
@media(max-width:600px){.vso-cats{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}.vso-cats::-webkit-scrollbar{display:none}}
.vso-cat{flex-shrink:0;padding:7px 14px;border-radius:20px;border:1.5px solid rgba(44,24,16,0.12);background:#fff;font-size:13px;font-weight:500;color:#4A2C20;cursor:pointer;transition:all .2s;white-space:nowrap;font-family:'DM Sans',sans-serif;-webkit-tap-highlight-color:transparent}
.vso-cat:hover{border-color:#D4AF37}.vso-cat.on{background:#2C1810;color:#D4AF37;border-color:#2C1810}

/* Products */
.vso-body{padding:0 4px 140px;min-height:200px}
.vso-stitle{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:700;color:#2C1810;padding:16px 4px 10px;margin:0}
.vso-item{display:flex;align-items:center;gap:12px;padding:12px 14px;background:#fff;border-radius:10px;margin-bottom:6px;box-shadow:0 1px 3px rgba(44,24,16,0.04);transition:all .2s}
.vso-item:hover{box-shadow:0 2px 8px rgba(44,24,16,0.08)}.vso-item.ic{border-left:3px solid #D4AF37}
.vso-info{flex:1;min-width:0}
.vso-item-img{width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;cursor:pointer;transition:transform .15s}
.vso-item-img:hover{transform:scale(1.08)}
.vso-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;cursor:pointer;animation:vso-fade-in .2s;-webkit-tap-highlight-color:transparent}
.vso-lightbox img{max-width:90vw;max-height:75vh;border-radius:12px;object-fit:contain;box-shadow:0 8px 40px rgba(0,0,0,.4)}
.vso-lightbox .caption{color:rgba(255,255,255,.8);font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600}
.vso-lightbox .close-hint{color:rgba(255,255,255,.35);font-size:12px}
@keyframes vso-fade-in{from{opacity:0}to{opacity:1}}
.vso-name{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;line-height:1.2}
.vso-desc{font-size:12px;color:#8B7355;margin-top:2px;line-height:1.3}
.vso-price{font-size:14px;font-weight:600;color:#4A2C20;margin-top:3px}
.vso-acts{display:flex;align-items:center;gap:0;flex-shrink:0}
.vso-qb{width:34px;height:34px;border-radius:50%;border:none;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;transition:all .15s;font-family:'DM Sans',sans-serif;-webkit-tap-highlight-color:transparent}
.vso-qb.add{background:#2C1810;color:#D4AF37}.vso-qb.add:hover{background:#4A2C20;transform:scale(1.05)}
.vso-qb.rm{background:#F0EBE4;color:#8B7355}.vso-qb.rm:hover{background:#E5DDD3}
.vso-qty{width:28px;text-align:center;font-size:15px;font-weight:700}

/* Cart bar */
.vso-bar{position:fixed;bottom:0;left:0;right:0;background:#2C1810;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;z-index:100;box-shadow:0 -4px 20px rgba(0,0,0,0.15);transition:transform .3s cubic-bezier(.4,0,.2,1)}
.vso-bar.hide{transform:translateY(100%)}
.vso-bar-l{display:flex;align-items:center;gap:12px}
.vso-badge{background:#D4AF37;color:#2C1810;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
.vso-bar-total{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:700;color:#D4AF37}
.vso-bar-min{font-size:10px;color:rgba(212,175,55,0.45);margin-top:1px}
.vso-bar-btn{background:#D4AF37;color:#2C1810;border:none;padding:10px 22px;border-radius:22px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif;-webkit-tap-highlight-color:transparent}
.vso-bar-btn:hover{background:#E5C84B}.vso-bar-btn:disabled{opacity:.5;cursor:not-allowed}

/* Closed bar */
.vso-cbar{position:fixed;bottom:0;left:0;right:0;background:#2C1810;z-index:100;box-shadow:0 -4px 20px rgba(0,0,0,0.15)}
.vso-cbar-msg{padding:14px 16px 8px;text-align:center;font-size:13px;color:rgba(212,175,55,0.7);line-height:1.5}
.vso-cbar-btns{display:flex;gap:8px;padding:8px 16px 14px;justify-content:center;flex-wrap:wrap}
.vso-cbar-btns .b1{flex:1;min-width:140px;background:#D4AF37;color:#2C1810;border:none;padding:11px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif}
.vso-cbar-btns .b2{flex:1;min-width:140px;background:rgba(212,175,55,0.12);color:#D4AF37;border:1.5px solid rgba(212,175,55,0.3);padding:11px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;text-align:center;font-family:'DM Sans',sans-serif}
.vso-subf{padding:4px 16px 6px}
.vso-subf input,.vso-subf select{width:100%;padding:9px 12px;border:1.5px solid rgba(212,175,55,0.2);border-radius:8px;font-size:16px;background:rgba(255,255,255,0.06);color:#D4AF37;outline:none;font-family:'DM Sans',sans-serif;margin-bottom:6px}
.vso-subf input::placeholder{color:rgba(212,175,55,0.35)}
.vso-subf select option{color:#2C1810}
.vso-subf .row{display:flex;gap:6px}
.vso-subf .row input{flex:1}
.vso-subf button{width:100%;padding:10px;background:#D4AF37;color:#2C1810;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer}
.vso-sub-ok{padding:10px 16px 14px;text-align:center;color:#D4AF37;font-size:14px;font-weight:600}

/* Checkout */
.vso-chk-back{display:flex;align-items:center;gap:10px;padding:12px 0;margin-bottom:8px;border-bottom:1px solid rgba(44,24,16,0.1)}
.vso-chk-back button{background:none;border:none;font-size:16px;cursor:pointer;color:#D4AF37;padding:4px 8px;font-family:'DM Sans',sans-serif}
.vso-chk-back span{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:700;color:#2C1810}
.vso-card{background:#fff;border-radius:10px;padding:14px;margin-bottom:16px;box-shadow:0 1px 4px rgba(44,24,16,0.06)}
.vso-card-title{font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:2px;margin:0 0 10px}
.vso-srow{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(44,24,16,0.05)}
.vso-srow:last-child{border-bottom:none}
.vso-srow-name{font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600}
.vso-srow-qty{font-size:12px;color:#8B7355;margin-left:4px}
.vso-srow-price{font-size:14px;font-weight:600;color:#4A2C20}
.vso-stotal{display:flex;justify-content:space-between;padding-top:10px;margin-top:6px;border-top:2px solid #D4AF37}
.vso-stotal span{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:700}
.vso-stotal span:last-child{color:#D4AF37}
.vso-note{background:rgba(212,175,55,0.08);border:1px solid rgba(212,175,55,0.2);border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;line-height:1.5;color:#4A2C20}
.vso-fg{margin-bottom:14px}
.vso-fg label{display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:4px}
.vso-fg label .req{color:#C0392B}
/* FIX: font-size 16px prevents iOS zoom on focus */
.vso-fg input,.vso-fg select,.vso-fg textarea{width:100%;padding:10px 12px;border:1.5px solid rgba(44,24,16,0.12);border-radius:8px;font-size:16px;color:#2C1810;background:#fff;outline:none;font-family:'DM Sans',sans-serif;transition:border-color .2s}
.vso-fg input:focus,.vso-fg select:focus,.vso-fg textarea:focus{border-color:#D4AF37}
.vso-fg textarea{resize:vertical;min-height:60px}
#vso-payment-el{min-height:40px}#vso-express-el{min-height:0}#vso-express-el:empty{display:none}
.vso-submit{width:100%;background:#2C1810;color:#D4AF37;border:none;padding:14px;border-radius:10px;font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:700;cursor:pointer;letter-spacing:1px;transition:all .2s;margin-bottom:30px}
.vso-submit:hover{background:#4A2C20}.vso-submit:disabled{opacity:.4;cursor:not-allowed}
.vso-submit.ld{position:relative;color:transparent}
.vso-submit.ld::after{content:'';position:absolute;top:50%;left:50%;width:20px;height:20px;margin:-10px 0 0 -10px;border:2px solid #D4AF37;border-top-color:transparent;border-radius:50%;animation:vso-sp .6s linear infinite}
@keyframes vso-sp{to{transform:rotate(360deg)}}

/* Confirmation */
.vso-confirm{text-align:center;padding:40px 20px;border-radius:12px}
.vso-confirm-icon{font-size:48px;margin-bottom:12px}
.vso-confirm h3{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:700;margin:0 0 6px}
.vso-confirm .sub{font-size:14px;color:#8B7355;line-height:1.6;margin-bottom:20px}
.vso-confirm .ref-label{font-size:11px;color:#8B7355;text-transform:uppercase;letter-spacing:2px;margin-bottom:6px}
.vso-confirm .ref-box{display:inline-block;background:#2C1810;color:#D4AF37;padding:12px 28px;border-radius:8px;font-size:22px;font-weight:700;letter-spacing:2px;font-family:'DM Sans',sans-serif;margin-bottom:20px}
.vso-confirm-det{background:#fff;border-radius:8px;padding:14px;text-align:left;margin-bottom:20px;font-size:13px;line-height:1.8}
.vso-confirm-det strong{color:#2C1810}
.vso-confirm-tot{font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:700;color:#D4AF37;margin-bottom:16px}
.vso-neworder{background:#2C1810;color:#D4AF37;border:none;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif}
.vso-loading{text-align:center;padding:60px 20px;color:#8B7355;font-size:16px}
.vso-err{background:#fdedec;border:1px solid #e74c3c;border-radius:8px;padding:10px 14px;margin-bottom:12px;color:#c0392b;font-size:13px}
</style>

<script>
(function(){
const CFG=window.VSO_CONFIG||{};const API=CFG.apiBase||'/wp-json/vs-order/v1';const C=CFG.content||{};
const app=document.getElementById('vso-order-app');
let S={step:'loading',products:[],categories:[],cart:{},activeCat:'',form:{name:'',email:'',phone:'',address:'',notes:''},error:'',orderRef:'',submitting:false,
  deliveryActive:false,atCapacity:false,uberEatsUrl:'',closedMessage:'',minimumOrder:100,weeklyZone:'',weeklyDeadline:'',weeklyDeliveryDay:'',
  showSubForm:false,subDone:false,stripePi:''};

async function load(){
  try{const r=await fetch(API+'/products?_t='+Date.now());const d=await r.json();
    S.products=d.products||[];S.categories=d.categories||[];S.activeCat=S.categories[0]?.slug||'';
    S.deliveryActive=d.deliveryActive;S.atCapacity=d.atCapacity;S.uberEatsUrl=d.uberEatsUrl;S.closedMessage=d.closedMessage;
    S.minimumOrder=d.minimumOrder||100;S.weeklyZone=d.weeklyZone;S.weeklyDeadline=d.weeklyDeadline;S.weeklyDeliveryDay=d.weeklyDeliveryDay;
    S.step='menu';
  }catch(e){S.step='menu';S.error='Could not load menu.';}
  render();
}

function cA(id){if(!S.deliveryActive)return;S.cart[id]=(S.cart[id]||0)+1;render();}
function cR(id){if(S.cart[id]>1)S.cart[id]--;else delete S.cart[id];render();}
function cc(){return Object.values(S.cart).reduce((s,q)=>s+q,0);}
function ct(){return Object.entries(S.cart).reduce((s,[id,q])=>{const p=S.products.find(x=>x.id==id);return s+(p?p.price*q:0);},0);}
function ci(){return Object.entries(S.cart).filter(([,q])=>q>0).map(([id,qty])=>{const p=S.products.find(x=>x.id==id);return p?{...p,qty:Number(qty)}:null;}).filter(Boolean);}
function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

// FIX: Use oninput instead of onchange so button enables immediately as user types
function fi(n,l,v,t,r){return `<div class="vso-fg"><label>${l} ${r?'<span class="req">*</span>':''}</label><input type="${t}" value="${esc(v)}" oninput="vsoF('${n}',this.value)" placeholder="${l}"></div>`;}

// FIX: Update button state without full re-render (preserves Stripe elements)
function updateSubmitBtn(){
  const btn=document.querySelector('.vso-submit');
  if(!btn)return;
  const f=S.form;const ok=f.name&&f.email&&f.phone&&f.address;
  btn.disabled=!ok||S.submitting;
}

function render(){
  if(S.step==='loading')return app.innerHTML='<div class="vso-loading">Loading menu...</div>';
  if(S.step==='menu')return renderMenu();
  if(S.step==='checkout')return renderCheckout();
  if(S.step==='confirm')return renderConfirm();
}

function renderMenu(){
  const cats=S.categories;const prods=S.products.filter(p=>p.category===S.activeCat);
  const count=cc();const total=ct();const cn=cats.find(c=>c.slug===S.activeCat)?.name||'';
  const isOpen=S.deliveryActive;const minMet=total>=S.minimumOrder;const pct=Math.min(100,total/S.minimumOrder*100);
  const steps=C.howSteps||[];

  let h='';
  if(steps.length){
    h+=`<div class="vso-how"><div class="vso-how-hd" onclick="this.parentElement.classList.toggle('collapsed')"><h3>${esc(C.howTitle||'')}</h3><span class="vso-how-arr">▾</span></div><div class="vso-how-inner"><div class="vso-how-steps">`;
    steps.forEach((s,i)=>{h+=`<div class="vso-how-step"><div class="num">${i+1}</div><div class="t">${esc(s.title)}</div><div class="d">${esc(s.desc)}</div></div>`;});
    h+=`</div>`;if(C.howNote)h+=`<div class="vso-how-note">${esc(C.howNote)}</div>`;h+=`</div></div>`;
  }
  if(isOpen&&S.weeklyZone){
    h+=`<div class="vso-zone-on"><span class="dot"></span><div style="flex:1"><div class="area">📍 ${esc(S.weeklyZone)}</div><div class="meta">`;
    if(S.weeklyDeliveryDay)h+=`<span>🗓 ${esc(S.weeklyDeliveryDay)}</span>`;if(S.weeklyDeadline)h+=`<span>⏰ Order by ${esc(S.weeklyDeadline)}</span>`;
    h+=`</div></div></div>`;
  }else if(!isOpen){h+=`<div class="vso-zone-off"><span class="dot"></span><span>Orders are currently closed — browse the menu and subscribe to get notified</span></div>`;}
  if(S.atCapacity)h+=`<div class="vso-err" style="text-align:center;font-weight:600;">⚠ Capacity reached. Subscribe for the next round!</div>`;
  if(isOpen&&count>0)h+=`<div class="vso-min"><span class="lbl ${minMet?'ok':''}">${minMet?'✓ Minimum met':'$'+total.toFixed(0)+' / $'+S.minimumOrder}</span><div class="track"><div class="fill" style="width:${pct}%"></div></div></div>`;

  h+=`<div class="vso-cats">`;cats.forEach(c=>{h+=`<button class="vso-cat ${c.slug===S.activeCat?'on':''}" onclick="vsoCat('${c.slug}')">${c.name}</button>`;});h+=`</div>`;
  h+=`<div class="vso-body"><h3 class="vso-stitle">${cn}</h3>`;
  if(S.error)h+=`<div class="vso-err">${S.error}</div>`;
  prods.forEach(p=>{const q=S.cart[p.id]||0;
    h+=`<div class="vso-item ${q?'ic':''}">`;
    if(p.image) h+=`<img src="${p.image}" class="vso-item-img" alt="" onclick="vsoZoom('${p.imageFull||p.image}','${esc(p.name)}')">`;
    h+=`<div class="vso-info"><div class="vso-name">${esc(p.name)}</div><div class="vso-desc">${esc(p.description)}</div><div class="vso-price">$${p.price.toFixed(2)}</div></div>`;
    if(isOpen)h+=`<div class="vso-acts">${q?`<button class="vso-qb rm" onclick="vsoRm(${p.id})">−</button><span class="vso-qty">${q}</span>`:''}
      <button class="vso-qb add" onclick="vsoAdd(${p.id})">+</button></div>`;
    h+=`</div>`;});
  if(!prods.length)h+='<p style="text-align:center;color:#8B7355;padding:30px;">No items in this category.</p>';
  h+=`</div>`;

  if(isOpen){
    h+=`<div class="vso-bar ${count===0?'hide':''}"><div class="vso-bar-l"><div class="vso-badge">${count}</div><div><div class="vso-bar-total">$${total.toFixed(2)}</div>${!minMet?`<div class="vso-bar-min">$${(S.minimumOrder-total).toFixed(0)} more</div>`:''}</div></div>
      <button class="vso-bar-btn" ${!minMet?'disabled':''} onclick="vsoGo('checkout')">${minMet?'Order Now →':'Min $'+S.minimumOrder}</button></div>`;
  }else{
    h+=`<div class="vso-cbar"><div class="vso-cbar-msg">${esc(S.closedMessage)}</div>`;
    if(S.subDone){h+=`<div class="vso-sub-ok">✓ Subscribed! We'll notify you.</div>`;}
    else if(S.showSubForm){
      h+=`<div class="vso-subf"><div class="row"><input type="text" id="vso-sn" placeholder="Your name"><input type="email" id="vso-se" placeholder="your@email.com"></div>
        <select id="vso-sz"><option value="">Your area (optional)</option><option>Manhattan</option><option>Brooklyn</option><option>Queens</option><option>The Bronx</option><option>Staten Island</option><option>Westchester</option><option>Long Island</option><option>New Jersey</option><option>Connecticut</option><option>Other</option></select>
        <button onclick="vsoSub()">📩 Notify Me</button></div>`;
    }else{
      h+=`<div class="vso-cbar-btns"><button class="b1" onclick="vsoShowSub()">📩 Get Notified</button>`;
      if(S.uberEatsUrl)h+=`<a class="b2" href="${esc(S.uberEatsUrl)}" target="_blank">🛵 Order on Uber Eats</a>`;
      h+=`</div>`;}
    h+=`</div>`;}
  app.innerHTML=h;
}

function renderCheckout(){
  const items=ci();const total=ct();const f=S.form;const note=C.checkoutNote||'';
  let h=`<div class="vso-chk-back"><button onclick="vsoGo('menu')">← Back</button><span>Your Order</span></div>`;
  if(S.weeklyZone)h+=`<div class="vso-note" style="text-align:center;font-size:14px;">📍 <strong>${esc(S.weeklyZone)}</strong>${S.weeklyDeliveryDay?' · 🗓 '+esc(S.weeklyDeliveryDay):''}</div>`;
  h+=`<div class="vso-card"><div class="vso-card-title">Order Summary</div>`;
  items.forEach(i=>{h+=`<div class="vso-srow"><div><span class="vso-srow-name">${esc(i.name)}</span><span class="vso-srow-qty">× ${i.qty}</span></div><span class="vso-srow-price">$${(i.price*i.qty).toFixed(2)}</span></div>`;});
  h+=`<div class="vso-stotal"><span>Total</span><span>$${total.toFixed(2)}</span></div></div>`;
  if(note)h+=`<div class="vso-note">🚗 ${esc(note)}</div>`;
  if(S.error)h+=`<div class="vso-err">${S.error}</div>`;
  h+=`<div class="vso-card"><div class="vso-card-title">Your Details</div>`;
  h+=fi('name','Full Name',f.name,'text',1)+fi('email','Email',f.email,'email',1)+fi('phone','Phone / WhatsApp',f.phone,'tel',1)+fi('address','Delivery Address',f.address,'text',1);
  h+=`<div class="vso-fg"><label>Notes</label><textarea placeholder="Buzzer code, allergies…" oninput="vsoF('notes',this.value)">${esc(f.notes)}</textarea></div></div>`;
  if(CFG.stripeEnabled)h+=`<div class="vso-card"><div class="vso-card-title">Payment${CFG.stripeTestMode?' <span style="color:#e67e22;">(TEST)</span>':''}</div><div id="vso-express-el" style="margin-bottom:12px;"></div><div id="vso-payment-el"></div></div>`;
  const ok=f.name&&f.email&&f.phone&&f.address;
  h+=`<button class="vso-submit ${S.submitting?'ld':''}" ${(!ok||S.submitting)?'disabled':''} onclick="vsoSubmit()">${CFG.stripeEnabled?'Pay & Place Order':'Place Order'} — $${total.toFixed(2)}</button>`;
  app.innerHTML=h;
  if(CFG.stripeEnabled&&window.Stripe)initStripe();
}

function renderConfirm(){
  const f=S.form;const count=cc();const total=ct();const title=C.confirmTitle||'Thank you!';const msg=C.confirmMessage||'Your order has been received.';
  app.innerHTML=`<div class="vso-confirm"><div class="vso-confirm-icon">🇮🇹</div><h3>${esc(title)}</h3>
    <p class="sub">${esc(msg)}${S.weeklyDeliveryDay?' We\'ll see you on <strong>'+esc(S.weeklyDeliveryDay)+'</strong>.':''}</p>
    <div class="ref-label">Your order number</div><div class="ref-box">${S.orderRef}</div>
    <div class="vso-confirm-det"><strong>Name:</strong> ${esc(f.name)}<br><strong>Email:</strong> ${esc(f.email)}<br><strong>Phone:</strong> ${esc(f.phone)}<br><strong>Address:</strong> ${esc(f.address)}<br>${S.weeklyZone?'<strong>Area:</strong> '+esc(S.weeklyZone)+'<br>':''}<strong>Items:</strong> ${count}</div>
    <div class="vso-confirm-tot">Total: $${total.toFixed(2)}</div>
    <p style="font-size:12px;color:#8B7355;margin-bottom:16px;">Confirmation sent to <strong>${esc(f.email)}</strong></p>
    <button class="vso-neworder" onclick="vsoReset()">New Order</button></div>`;
}

async function vsoSub(){const em=document.getElementById('vso-se')?.value?.trim();const n=document.getElementById('vso-sn')?.value?.trim();const z=document.getElementById('vso-sz')?.value;if(!em||!em.includes('@'))return;try{await fetch(API+'/subscribe',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({email:em,name:n,zone:z})});}catch(x){}S.subDone=true;render();}

let stripe,elements,stripeInitialized=false;
async function initStripe(){
  if(!CFG.stripePk||!window.Stripe||stripeInitialized)return;
  stripeInitialized=true;
  try{
    stripe=Stripe(CFG.stripePk);
  }catch(x){
    S.error='Invalid Stripe key. Check Settings → Stripe.';render();return;
  }
  try{
    const r=await fetch(API+'/stripe/create-intent',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({amount:ct(),name:S.form.name,email:S.form.email})});
    const d=await r.json();
    if(d.code){S.error='Stripe error: '+(d.message||'Could not create payment.');stripeInitialized=false;render();return;}
    S.stripePi=d.paymentIntentId;
    elements=stripe.elements({clientSecret:d.clientSecret,appearance:{theme:'stripe',variables:{colorPrimary:'#2C1810',colorText:'#2C1810',fontFamily:"'DM Sans',sans-serif",borderRadius:'8px'}}});

    // Clear containers before mounting (prevents "child nodes" warning)
    const ex=document.getElementById('vso-express-el');
    if(ex){ex.innerHTML='';const ee=elements.create('expressCheckout',{buttonType:{applePay:'buy',googlePay:'buy',paypal:'buynow'},layout:{maxColumns:3,maxRows:1}});ee.mount(ex);ee.on('confirm',async()=>{await postOrder('paid');});ee.on('ready',({availablePaymentMethods})=>{if(!availablePaymentMethods)ex.style.display='none';});}

    const pe=document.getElementById('vso-payment-el');
    if(pe){pe.innerHTML='';const pm=elements.create('payment',{layout:'tabs'});pm.mount(pe);}
  }catch(x){
    S.error='Payment setup failed: '+x.message;
    stripeInitialized=false;
    render();
  }
}

async function vsoSubmit(){const f=S.form;if(!f.name||!f.email||!f.phone||!f.address)return;S.submitting=true;S.error='';const btn=document.querySelector('.vso-submit');if(btn){btn.disabled=true;btn.classList.add('ld');}try{let ps='pending';if(CFG.stripeEnabled&&stripe&&elements){const{error}=await stripe.confirmPayment({elements,confirmParams:{return_url:window.location.href,payment_method_data:{billing_details:{name:f.name,email:f.email,phone:f.phone}}},redirect:'if_required'});if(error)throw new Error(error.message);ps='paid';}await postOrder(ps);}catch(x){S.error=x.message;S.submitting=false;stripeInitialized=false;render();if(CFG.stripeEnabled)setTimeout(initStripe,200);}}

async function postOrder(ps){const f=S.form;const items=ci().map(i=>({id:i.id,qty:i.qty}));const r=await fetch(API+'/orders',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({...f,zone:S.weeklyZone,items,payment_status:ps,stripe_pi:S.stripePi||''})});const d=await r.json();if(d.code)throw new Error(d.message||'Order failed');S.orderRef=d.ref;S.step='confirm';S.submitting=false;render();}

window.vsoCat=function(s){S.activeCat=s;render();};
window.vsoAdd=function(id){cA(id);};window.vsoRm=function(id){cR(id);};
window.vsoGo=function(s){S.step=s;S.error='';render();};
// FIX: Update form state AND button immediately
window.vsoF=function(k,v){S.form[k]=v;updateSubmitBtn();};
window.vsoShowSub=function(){S.showSubForm=true;render();setTimeout(()=>document.getElementById('vso-sn')?.focus(),50);};
window.vsoSub=vsoSub;window.vsoSubmit=vsoSubmit;
window.vsoReset=function(){S.cart={};S.form={name:'',email:'',phone:'',address:'',notes:''};S.orderRef='';S.step='menu';S.activeCat=S.categories[0]?.slug||'';render();};
window.vsoZoom=function(src,name){
  const lb=document.createElement('div');lb.className='vso-lightbox';
  lb.innerHTML='<img src="'+src+'"><div class="caption">'+name+'</div><div class="close-hint">Tap anywhere to close</div>';
  lb.onclick=function(){lb.remove();};
  document.body.appendChild(lb);
};

load();
})();
</script>
