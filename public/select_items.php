<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
if (!isset($_SESSION['pos_flow'])) { $_SESSION['pos_flow'] = []; }
$__customer_name    = $_SESSION['pos_flow']['customer_name']    ?? '';
$__customer_phone   = $_SESSION['pos_flow']['customer_phone']   ?? '';
$__customer_skipped = $_SESSION['pos_flow']['customer_skipped'] ?? false;

require_role_in_or_redirect(['admin','cashier','Manger']);

if (empty($_SESSION['pos_flow']['category_id'])) {
  header('Location: /3zbawyh/public/select_category.php'); exit;
}
if (empty($_SESSION['pos_flow']['subcategory_id'])) {
  header('Location: /3zbawyh/public/select_subcategory.php'); exit;
}
$category_id    = (int)$_SESSION['pos_flow']['category_id'];
$subcategory_id = (int)$_SESSION['pos_flow']['subcategory_id'];
$u = current_user();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"> <!-- مهم للموبايل -->
<title>POS — الأصناف</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{
  --bg1:#f6f7fb; --bg2:#eef3ff; --card:#fff; --ink:#111; --muted:#667;
  --pri:#2261ee; --pri-ink:#fff; --bd:#e8e8ef; --badge:#eef2f8; --accent:#0b4ea9;
  --ok:#137333;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:radial-gradient(1200px 600px at 50% -200px,var(--bg2),var(--bg1));
  color:var(--ink);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
}
a{color:var(--pri)}

/* ===== Nav ===== */
.nav{
  position:sticky; top:0; z-index:10;
  display:flex; justify-content:space-between; align-items:center;
  padding:12px 14px; gap:10px; flex-wrap:wrap;      /* يلف على الموبايل */
  background:linear-gradient(#ffffffdd,#ffffffcc);
  backdrop-filter: blur(6px); border-bottom:1px solid var(--bd);
}
.nav .right{display:flex; gap:8px; flex-wrap:wrap; align-items:center}
.nav .right .btn, .nav .right a{
  text-decoration:none;
}

/* ===== Layout ===== */
.center{min-height:calc(100% - 64px); display:grid; place-items:start center; padding:16px}
.box{
  width:min(1100px,96vw); background:var(--card); border:1px solid var(--bd);
  border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.06); padding:16px
}
.row{display:flex; gap:10px; flex-wrap:wrap; align-items:center}
.input{
  border:1px solid var(--bd); border-radius:12px; padding:10px 12px; background:#fff; min-width:220px
}
.btn{
  border:0; background:var(--pri); color:var(--pri-ink);
  padding:10px 14px; border-radius:12px; cursor:pointer; transition:.15s; box-shadow:0 2px 8px rgba(34,97,238,.18)
}
.btn:hover{transform:translateY(-1px)}
.btn.secondary{background:#eef3fb; color:var(--accent)}
.badge{font-size:11px; padding:2px 8px; border-radius:999px; background:var(--badge); color:#345}
.pill{display:inline-block; background:#0b4ea914; border:1px solid #cfe2ff; color:#0b4ea9; padding:4px 10px; border-radius:999px; font-weight:600}

/* ===== Items grid ===== */
.list{
  margin-top:10px; display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px
}
.card{
  border:1px solid var(--bd); border-radius:14px; padding:12px; background:#fff;
  transition:.15s; box-shadow:0 2px 10px rgba(0,0,0,.04); display:flex; flex-direction:column; gap:10px;
}
.card:hover{transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.08)}
.card .name{font-weight:700; margin-bottom:2px}
.card .meta{display:flex; gap:8px; flex-wrap:wrap; margin-bottom:4px}
.price-badge{font-size:13px; padding:4px 10px; border-radius:999px; background:#eef6ff; color:#0b4ea9; font-weight:700}
.stock-badge{font-size:13px; padding:4px 10px; border-radius:999px; background:#f6f6f6; color:#333; font-weight:700}
.stock-low{color:#b3261e !important; background:#fdecec !important}

.card .actions{display:flex; justify-content:space-between; align-items:center; gap:10px}
.add-btn{display:inline-flex; align-items:center; gap:6px}
.add-btn .plus{
  width:22px; height:22px; display:grid; place-items:center; border-radius:8px; background:#edf2ff; color:#1b4ed8;
  font-weight:900; transition:transform .15s
}
.add-btn:active .plus{transform:scale(0.92)}

.qty{
  width:84px; display:flex; align-items:center; gap:6px;
  border:1px solid var(--bd); border-radius:10px; padding:6px 8px; background:#fff;
}
.qty input{width:40px; border:0; outline:0; text-align:center; font-weight:700}
.qty button{border:0; background:#f2f5ff; padding:4px 8px; border-radius:8px; cursor:pointer}

/* Thumbnail */
.thumb{
  width:100%; height:150px; border-radius:10px; background:#f5f7fb;
  display:grid; place-items:center; overflow:hidden; border:1px solid var(--bd)
}
.thumb img{width:100%; height:100%; object-fit:cover}
.thumb small{color:#99a; font-size:12px}

/* Floating cart button */
.fab{
  position:fixed; inset-inline-end:20px; inset-block-end:20px; z-index:12;
  display:flex; align-items:center; gap:10px;
  background:var(--pri); color:#fff; border-radius:999px; padding:10px 14px;
  box-shadow:0 10px 24px rgba(34,97,238,.25); text-decoration:none
}
.fab b{background:#ffffff22; border:1px solid #ffffff55; padding:2px 10px; border-radius:999px}

/* Toast */
#toasts{
  position:fixed; inset-block-start:14px; inset-inline-end:14px; z-index:9999;
  display:flex; flex-direction:column; gap:8px; pointer-events:none;
}
.toast{
  pointer-events:auto; min-width:240px; max-width:360px;
  background:#111; color:#fff; border-radius:12px; padding:10px 12px;
  box-shadow:0 10px 28px rgba(0,0,0,.25); display:flex; gap:10px; align-items:flex-start;
  animation:slideIn .18s ease-out
}
.toast.ok{background:#174e2f}
.toast .t-title{font-weight:700; margin-bottom:2px}
.toast .t-meta{font-size:12px; opacity:.85}
.toast .t-close{margin-inline-start:auto; cursor:pointer; opacity:.8}
@keyframes slideIn{from{transform:translateY(-6px); opacity:0} to{transform:translateY(0); opacity:1}}

/* ====== Responsive tweaks ====== */
@media (max-width: 900px){
  .list{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}
}
@media (max-width: 768px){
  .box{padding:14px; border-radius:14px}
  .list{grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:10px}
  .thumb{height:135px}
  .input{min-width:180px}
}
@media (max-width: 520px){
  .nav{padding:10px}
  .box{width:100%; border-radius:12px; padding:12px}
  .row{gap:8px}
  .input{min-width:0; width:100%}          /* حقل البحث ياخد العرض كله */
  .btn{width:auto}
  .list{grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:8px}
  .thumb{height:120px}
  .card{gap:8px}
  .fab{inset-inline-end:12px; inset-block-end:12px}
}
@media (max-width: 380px){
  .list{grid-template-columns:repeat(auto-fill,minmax(140px,1fr))}
  .fab{
    inset-inline-start:50%; transform:translateX(-50%);  /* وسّط زر الكارت */
    inset-inline-end:auto;
  }
}
</style>
</head>
<body>
<nav class="nav">
  <div><strong>POS — صفحة الأصناف</strong></div>
  <div class="right">
    <span class="pill">التصنيف: <span id="catName">#<?=$category_id?></span></span>
    <span class="pill">الفرعي: <span id="subName">#<?=$subcategory_id?></span></span>
    <a class="btn secondary" href="/3zbawyh/public/select_subcategory.php">← الفرعي</a>
    <a class="btn" href="/3zbawyh/public/cart_checkout.php" id="cartBtnTop">الكارت (0)</a>
    <a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a>
  </div>

  <?php if($__customer_name !== '' || $__customer_phone !== ''): ?>
    <span class="badge" style="background:#eef2ff;border-color:#dbe1ff;color:#1d3bd1">
      <?= htmlspecialchars($__customer_name ?: 'عميل') ?><?php if ($__customer_phone !== ''): ?> — <?= htmlspecialchars($__customer_phone) ?><?php endif; ?>
    </span>
  <?php elseif($__customer_skipped): ?>
    <span class="badge" style="background:#fafafa;border-color:#e5e7eb;color:#555">عميل نقدي</span>
  <?php endif; ?>
</nav>

<div id="toasts"></div>

<div class="center">
  <div class="box">
    <div class="row" style="justify-content:space-between">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input id="q" class="input" placeholder="ابحث باسم الصنف (Enter)">
        <button class="btn" id="btnSearch" type="button">بحث</button>
      </div>
      <div class="pill" id="countHint">— النتائج: 0 عنصر</div>
    </div>

    <div id="itemsGrid" class="list" aria-live="polite"></div>
  </div>
</div>

<!-- زر كارت عائم -->
<a class="fab" href="/3zbawyh/public/cart_checkout.php" id="cartFab">
  <span>اذهب للكارت</span>
  <b id="cartCount">0</b>
</a>

<script>
const cid = <?=$category_id?>, sid = <?=$subcategory_id?>;
const el = s=>document.querySelector(s);
const fmt = n=>{ n=parseFloat(n||0); return isNaN(n)?'0.00':n.toFixed(2); };

/* API */
function api(a,p={}){const q=new URLSearchParams(p).toString();return fetch('/3zbawyh/public/pos_api.php?'+(q? q+'&':'')+'action='+a).then(r=>r.json());}
function postForm(a, body={}) {
  return fetch('/3zbawyh/public/pos_api.php?action='+a, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams(body)
  }).then(r=>r.json());
}

/* Toast */
function toast({title='تم', msg='', ok=false, timeout=2200}={}){
  const wrap = el('#toasts');
  const t = document.createElement('div');
  t.className = 'toast' + (ok?' ok':'');
  t.innerHTML = `
    <div>
      <div class="t-title">${title}</div>
      <div class="t-meta">${msg}</div>
    </div>
    <div class="t-close">✖</div>
  `;
  t.querySelector('.t-close').onclick = ()=> t.remove();
  wrap.appendChild(t);
  setTimeout(()=>{ t.style.opacity='.0'; t.style.transform='translateY(-6px)'; setTimeout(()=>t.remove(), 180); }, timeout);
}

/* Breadcrumb names */
api('search_categories').then(r=>{ if(r?.ok){ const c=(r.categories||[]).find(x=>+x.id===cid); if(c) el('#catName').textContent=c.name; }});
api('search_subcategories',{category_id:cid}).then(r=>{ if(r?.ok){ const s=(r.subcategories||[]).find(x=>+x.id===sid); if(s) el('#subName').textContent=s.name; }});

/* Img field */
function getItemImage(it){ return it.image_url || it.photo || it.image || ''; }

/* Render */
function renderItems(items){
  const grid = el('#itemsGrid'); grid.innerHTML='';
  el('#countHint').textContent = '— النتائج: ' + (items?.length || 0) + ' عنصر';
  if (!items?.length){
    grid.innerHTML = `<div style="grid-column:1/-1;padding:16px;color:#666">لا توجد نتائج ضمن هذا الفرعي.</div>`;
    return;
  }
  items.forEach(it=>{
    const stock = (it.stock==null || it.stock==='') ? '-' : it.stock;
    const low = (stock!== '-' && parseFloat(stock)<=0);
    const img = getItemImage(it);
    const d = document.createElement('div');
    d.className='card';
    d.innerHTML = `
      <div class="thumb">
        ${img ? `<img src="${img}" loading="lazy" alt="">` : `<small>لا توجد صورة</small>`}
      </div>
      <div class="name" title="${it.name||''}">${it.name||''}</div>
      <div class="meta">
        <span class="price-badge">السعر: ${fmt(it.unit_price)} ج.م</span>
        <span class="stock-badge ${low?'stock-low':''}">المخزون: ${stock}</span>
      </div>
      <div class="actions">
        <div style="display:flex; align-items:center; gap:8px">
          <div class="qty" data-id="${it.id}">
            <button type="button" class="dec" aria-label="نقص الكمية">−</button>
            <input type="number" class="val" min="1" value="1" inputmode="numeric" aria-label="الكمية">
            <button type="button" class="inc" aria-label="زود الكمية">＋</button>
          </div>
          <button class="btn add-btn" data-id="${it.id}" type="button">
            <span class="plus">＋</span> أضِف
          </button>
        </div>
      </div>
    `;
    grid.appendChild(d);
  });

  // Quantity controls
  grid.querySelectorAll('.qty').forEach(box=>{
    const val = box.querySelector('.val');
    box.querySelector('.inc').addEventListener('click', ()=>{ val.value = Math.max(1, (+val.value||1)+1); });
    box.querySelector('.dec').addEventListener('click', ()=>{ val.value = Math.max(1, (+val.value||1)-1); });
    val.addEventListener('keydown', e=>{
      if(e.key==='Enter'){
        const id = +box.dataset.id; const qty = Math.max(1, parseInt(val.value||'1',10));
        addToCart(id, qty, e.target.closest('.card').querySelector('.add-btn'));
      }
    });
  });

  // Add buttons
  grid.querySelectorAll('.add-btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = +btn.dataset.id;
      const qtyBox = btn.closest('.actions').querySelector('.qty .val');
      const qty = Math.max(1, parseInt(qtyBox?.value || '1', 10));
      addToCart(id, qty, btn);
    });
  });
}

function addToCart(id, qty, btnEl){
  postForm('cart_add', {item_id:id, qty:qty}).then(res=>{
    if(!res.ok){ alert(res.error||'خطأ'); return; }
    refreshCartCount();
    toast({ok:true, title:'تمت الإضافة', msg:`أُضيف (${qty}) × صنف #${id} إلى العربة.`});
    if (btnEl){
      btnEl.style.transform='scale(0.96)';
      setTimeout(()=>{ btnEl.style.transform=''; },120);
    }
  });
}

function searchItems(){
  const q = el('#q').value.trim();
  api('search_items',{q, category_id: cid, subcategory_id: sid}).then(r=>{
    if(!r.ok){ alert(r.error||'خطأ'); return; }
    renderItems(r.items||[]);
  });
}

/* Cart count */
function refreshCartCount(){
  api('cart_get').then(r=>{
    if(!r.ok) return;
    const c = (r.cart||[]).reduce((sum, line)=> sum + (parseFloat(line.qty||1)||1), 0);
    const top = el('#cartBtnTop');
    const fab = el('#cartCount');
    if (top) top.textContent = `الكارت (${c})`;
    if (fab) fab.textContent = c;
  });
}

/* Init */
searchItems();
refreshCartCount();
</script>
</body>
</html>
