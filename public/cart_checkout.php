<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../app/config/db.php';

require_login();
require_role_in_or_redirect(['admin','cashier','Manger']);

if (!isset($_SESSION['pos_flow'])) { $_SESSION['pos_flow'] = []; }
$__customer_name    = $_SESSION['pos_flow']['customer_name']    ?? '';
$__customer_phone   = $_SESSION['pos_flow']['customer_phone']   ?? '';
$__customer_skipped = $_SESSION['pos_flow']['customer_skipped'] ?? false;

$u = current_user();
$role = strtolower($u['role'] ?? '');
$max_disc_percent = null;
$db = db();
if (column_exists($db, 'users', 'max_discount_percent')) {
  $st = $db->prepare("SELECT max_discount_percent FROM users WHERE id=?");
  $st->execute([$u['id']]);
  $val = $st->fetchColumn();
  if ($val !== null && $val !== '') {
    $max_disc_percent = max(0, min(100, (float)$val));
  }
}
if ($max_disc_percent === null) {
  if ($role === 'cashier' || $role === 'chasier') {
    $max_disc_percent = 20;
  } elseif ($role === 'manger' || $role === 'manager') {
    $max_disc_percent = 30;
  }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نقاط البيع - دفع عربة التسوق</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{
  --bg:#f6f7fb;--card:#fff;--bd:#e8e8ef;--ink:#111;
  --muted:#6b7280;
  --pri:#2261ee;--pri-ink:#fff;--ok:#137333;--danger:#b3261e;
  --shadow:0 12px 30px rgba(0,0,0,.06);
  --r:16px;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:radial-gradient(1200px 600px at 50% -200px,#eef3ff,#f6f7fb);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--ink)
}

/* ===== Topbar ===== */
.topbar{
  position:sticky;top:0;z-index:10;
  background:#fff;border-bottom:1px solid var(--bd);
}
.topbar .inner{
  max-width:1200px;margin:0 auto;
  padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;
}
.brand{display:flex;gap:10px;align-items:center}
.badge{
  display:inline-flex;align-items:center;gap:6px;
  background:#0b4ea914;border:1px solid #cfe2ff;color:#0b4ea9;
  padding:5px 10px;border-radius:999px;font-weight:700;font-size:13px
}
.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

/* ===== Controls ===== */
.btn{
  border:0;background:var(--pri);color:var(--pri-ink);
  padding:10px 14px;border-radius:12px;cursor:pointer;font-weight:700
}
.btn.ok{background:var(--ok)}
.btn.danger{background:var(--danger)}
.btn.secondary{background:#eef3fb;color:#0b4ea9}
.btn:active{transform:translateY(1px)}
.input{border:1px solid var(--bd);border-radius:12px;padding:10px 12px;background:#fff;outline:0}
.input:focus{border-color:#b7c8ff;box-shadow:0 0 0 3px rgba(34,97,238,.12)}
.pill{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--bd);padding:8px 10px;border-radius:999px;font-weight:800}
.warn{color:var(--danger);font-weight:900}
.muted{color:var(--muted)}
.card{
  background:#fff;border:1px solid var(--bd);border-radius:var(--r);
  box-shadow:var(--shadow);
}
.section-title{margin:0 0 10px;font-size:16px}

/* ===== Page layout ===== */
.page{
  max-width:1200px;margin:0 auto;padding:14px;
}
.grid{
  display:grid;grid-template-columns: 1.3fr .9fr; gap:12px; align-items:start;
}
.panel{padding:14px}
.panel-head{
  display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:10px
}
.customer-mini{
  display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap
}
.customer-mini .name{font-weight:900}
.customer-mini .line{font-size:13px;color:var(--muted)}
.divider{height:1px;background:var(--bd);margin:12px 0}

/* ===== Cart list ===== */
.list{
  max-height:56vh;overflow:auto;border:1px solid var(--bd);
  border-radius:14px;background:#fbfbfe
}
.empty{
  padding:16px;color:var(--muted)
}

/* item card */
.item{
  display:grid;grid-template-columns: 1fr 360px;
  gap:10px;padding:10px;border-bottom:1px solid var(--bd);background:#fff
}
.item:last-child{border-bottom:0}
.item .meta{display:flex;flex-direction:column;gap:2px}
.item .meta strong{font-size:14px}
.item .meta .sub{font-size:12px;color:var(--muted)}
.item .ctrl{
  display:grid;grid-template-columns: 90px 120px 1fr 40px;
  gap:8px;align-items:center
}
.item .ctrl .total{
  text-align:right;font-weight:900
}
.iconbtn{
  width:40px;height:40px;border-radius:12px;border:1px solid var(--bd);
  background:#fff;cursor:pointer
}
.iconbtn.danger{border-color:#ffd1cd;background:#fff5f5}
.iconbtn:active{transform:translateY(1px)}

/* ===== Summary (sticky) ===== */
.summary{
  position:sticky;top:74px;
}
.summary-grid{
  display:grid;grid-template-columns: 1fr 1fr;gap:8px
}
.summary .pill{justify-content:space-between}
.summary .pill b{font-size:14px}
.summary .pill span{font-weight:900}
.summary .inputs{
  display:grid;grid-template-columns: 1fr 1fr;gap:8px;margin-top:10px
}
.credit-box{
  margin-top:10px;
  border:1px dashed var(--bd);
  border-radius:12px;
  padding:10px;
  background:#f8fafc;
}
.credit-box .line{
  display:flex;align-items:center;gap:8px;flex-wrap:wrap
}
.credit-box .small{
  font-size:12px;color:var(--muted)
}
.credit-box .credit-fields{
  display:none;
  margin-top:8px;
  grid-template-columns: 1fr 1fr;
  gap:8px;
}
@media (max-width: 520px){
  .credit-box .credit-fields{grid-template-columns:1fr}
}

/* ===== Payments ===== */
.payments .payrow{
  display:grid;grid-template-columns: 1fr 140px 1.2fr 90px;
  gap:8px;align-items:center;margin-bottom:8px
}
.payments .payrow .remove{width:100%;padding:10px 12px;border-radius:12px}
#payWarn{margin:8px 0}

/* ===== Footer action ===== */
.footer-action{
  display:flex;justify-content:flex-end;gap:10px;margin-top:12px
}

/* ===== Responsive ===== */
@media (max-width: 980px){
  .grid{grid-template-columns: 1fr; }
  .summary{position:relative;top:auto}
  .list{max-height:52vh}
  .item{grid-template-columns:1fr}
  .item .ctrl{grid-template-columns: 1fr 1fr 1fr 44px}
}
@media (max-width: 520px){
  .page{padding:10px}
  .panel{padding:12px}
  .list{max-height:55vh}
  .item .ctrl{grid-template-columns: 1fr 1fr; grid-auto-rows:auto}
  .item .ctrl .total{grid-column:1 / -1;text-align:left}
  .payments .payrow{grid-template-columns: 1fr; }
  .payments .payrow .remove{width:100%}
}
</style>
</head>

<body>

<header class="topbar">
  <div class="inner">
    <div class="brand">
      <strong>POS</strong>
      <span class="badge">Cart Checkout</span>
    </div>
    <div class="actions">
      <a class="btn secondary" href="/3zbawyh/public/select_items.php?all=1">+ أضف أصناف</a>
      <a class="btn secondary" href="/3zbawyh/public/customer_name.php">تعديل العميل</a>
      <a href="/3zbawyh/public/logout.php" class="muted">خروج (<?=e($u['username'])?>)</a>
    </div>
  </div>
</header>

<main class="page">
  <div class="grid">

    <!-- ===== Cart Panel ===== -->
    <section class="card panel">
      <div class="panel-head">
        <div>
          <h3 class="section-title">العربة</h3>
          <div class="muted" style="font-size:13px">عدّل الكمية والسعر مباشرة. الحذف من زر ✖.</div>
        </div>

        <div class="customer-mini">
          <div>
            <div class="name">العميل</div>
            <div class="line">
              <?= htmlspecialchars($__customer_name !== '' ? $__customer_name : 'عميل نقدي') ?>
              <?php if ($__customer_phone !== ''): ?>
                — <?= htmlspecialchars($__customer_phone) ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div id="list" class="list"></div>
    </section>

    <!-- ===== Right Panel: Summary + Payments ===== -->
    <aside class="card panel summary">
      <h3 class="section-title">الملخّص والدفع</h3>

      <div class="summary-grid">
        <div class="pill"><b>الإجمالي</b><span><span id="grand">0.00</span> ج.م</span></div>
        <div class="pill"><b>المدفوع</b><span><span id="paidSum">0.00</span> ج.م</span></div>
        <div class="pill" style="grid-column:1 / -1"><b>الباقي (كاش)</b><span><span id="changePillText">0.00</span> ج.م</span></div>
      </div>

      <div class="inputs">
        <label class="muted" style="font-size:13px">
          خصم (%)
          <input id="discount" class="input" value="0" inputmode="decimal" style="width:100%;margin-top:6px">
        </label>
        <div id="discountHint" class="muted" style="font-size:12px;margin-top:-4px">
          <?php if ($max_disc_percent !== null): ?>
            الحد الأقصى للخصم: <?= (int)$max_disc_percent ?>%
          <?php else: ?>
            بدون حد أقصى للخصم
          <?php endif; ?>
        </div>
        <label class="muted" style="font-size:13px">
          ضريبة
          <input id="tax" class="input" value="0" inputmode="decimal" style="width:100%;margin-top:6px">
        </label>
      </div>

      <div class="credit-box">
        <div class="line">
          <label style="display:flex;align-items:center;gap:8px;font-weight:800">
            <input type="checkbox" id="creditToggle"> سداد جزئي + الباقي آجل
          </label>
          <span class="small">الآجل ليس طريقة دفع</span>
        </div>
        <div id="creditFields" class="credit-fields">
          <label class="muted" style="font-size:13px">
            موعد السداد (اختياري)
            <input id="creditDue" class="input" type="date" style="width:100%;margin-top:6px">
          </label>
          <label class="muted" style="font-size:13px">
            ملاحظة (اختياري)
            <input id="creditNote" class="input" type="text" placeholder="مثال: دفع على دفعات" style="width:100%;margin-top:6px">
          </label>
        </div>
        <div class="small" style="margin-top:6px">ادفع أي مبلغ الآن بأي طريقة، والباقي يُسجّل آجل تلقائيًا. يمكن ترك موعد السداد فارغًا ليظل مفتوحًا.</div>
        <div class="pill" style="margin-top:8px;justify-content:space-between">
          <b>المتبقي آجل</b><span id="creditRemaining">0.00</span>
        </div>
      </div>

      <div class="divider"></div>

      <div class="payments">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <h4 style="margin:0">طرق الدفع</h4>
          <button class="btn secondary" id="addPay" type="button">+ إضافة دفع</button>
        </div>

        <div id="payWarn" class="warn" style="display:none"></div>
        <div id="paymentsArea" style="margin-top:10px"></div>

        <div class="footer-action">
          <button class="btn ok" id="finish" type="button">حفظ + طباعة</button>
        </div>
      </div>
    </aside>

  </div>
</main>

<script>
// ============ قيم العميل من الـPHP (من الـSession) ============
const CUSTOMER_NAME  = <?= json_encode($__customer_name, JSON_UNESCAPED_UNICODE) ?>;
const CUSTOMER_PHONE = <?= json_encode($__customer_phone, JSON_UNESCAPED_UNICODE) ?>;
const MAX_DISC_PERCENT = <?= $max_disc_percent === null ? 'null' : (int)$max_disc_percent ?>;

const el  = s=>document.querySelector(s);
const fmt = n=>{ n=parseFloat(n||0); return isNaN(n)?'0.00':n.toFixed(2); };

const CREDIT_TOGGLE = el('#creditToggle');
const CREDIT_FIELDS = el('#creditFields');
const CREDIT_DUE = el('#creditDue');
const CREDIT_NOTE = el('#creditNote');
const CREDIT_REMAINING = el('#creditRemaining');
const isCredit = ()=> CREDIT_TOGGLE && CREDIT_TOGGLE.checked;

if (CREDIT_TOGGLE) {
  CREDIT_TOGGLE.addEventListener('change', ()=>{
    if (CREDIT_FIELDS) CREDIT_FIELDS.style.display = CREDIT_TOGGLE.checked ? 'grid' : 'none';
    sumPayments();
  });
}

function api(action, params={}){
  const q = new URLSearchParams(params).toString();
  return fetch('/3zbawyh/public/pos_api.php?' + (q? q+'&':'') + 'action=' + action).then(r=>r.json());
}
function apiPost(action, body={}){
  return fetch('/3zbawyh/public/pos_api.php?action='+action, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(body)
  }).then(r=>r.json());
}

let CART = [];
let LAST_SUBTOTAL = 0;
let LAST_DISCOUNT_AMOUNT = 0;
function loadCart(){
  api('cart_get').then(r=>{
    if(!r.ok){ alert(r.error||'خطأ'); return; }
    CART = r.cart||[];
    renderCart();
    refreshTotals();
  });
}

function renderCart(){
  const box = el('#list'); box.innerHTML='';
  if (!CART.length){
    box.innerHTML='<div class="empty">العربة فارغة — أضف أصناف من صفحة الأصناف.</div>';
    return;
  }

  CART.forEach(l=>{
    const d = document.createElement('div');
    d.className='item';
    const stock = (l.stock==null ? '-' : l.stock);

    d.innerHTML = `
      <div class="meta">
        <strong>${l.name}</strong>
        <div class="sub">مخزون: ${stock}</div>
      </div>

      <div class="ctrl">
        <div>
          <input class="input qty" style="width:100%" value="${l.qty}" inputmode="decimal" placeholder="الكمية">
        </div>
        <div>
          <input class="input price" style="width:100%" value="${l.unit_price}" inputmode="decimal" placeholder="السعر">
        </div>
        <div class="total">${fmt(l.qty*l.unit_price)} ج.م</div>
        <div style="text-align:right">
          <button class="iconbtn danger rm" type="button" title="حذف">✖</button>
        </div>
      </div>
    `;

    d.querySelector('.qty').addEventListener('input', e=>{
      let q = Math.max(0, parseFloat(e.target.value||0));
      api('cart_update', {item_id:l.item_id, qty:q}).then(loadCart);
    });
    d.querySelector('.price').addEventListener('input', e=>{
      const p = Math.max(0, parseFloat(e.target.value||0));
      api('cart_update', {item_id:l.item_id, unit_price:p}).then(loadCart);
    });
    d.querySelector('.rm').onclick = ()=> api('cart_update', {item_id:l.item_id, remove:1}).then(loadCart);

    box.appendChild(d);
  });
}

function refreshTotals(){
  let subtotal=0;
  CART.forEach(l=> subtotal += (+l.qty)*(+l.unit_price) );
  let discPercent = +el('#discount').value||0;
  if (discPercent < 0) discPercent = 0;
  if (discPercent > 100) discPercent = 100;
  if (MAX_DISC_PERCENT !== null && discPercent > MAX_DISC_PERCENT) {
    discPercent = MAX_DISC_PERCENT;
  }
  el('#discount').value = discPercent.toString();
  const discAmount = subtotal * (discPercent / 100);
  const tx   = +el('#tax').value||0;
  LAST_SUBTOTAL = subtotal;
  LAST_DISCOUNT_AMOUNT = discAmount;
  el('#grand').textContent = fmt(subtotal - discAmount + tx);
  sumPayments();
}
el('#discount').addEventListener('input', refreshTotals);
el('#tax').addEventListener('input', refreshTotals);

/* ========= Payments ========= */
function paymentRow(method='cash', amount='', ref=''){
  const wrap = document.createElement('div');
  wrap.className='payrow';
  wrap.innerHTML = `
    <select class="input method">
      <option value="cash">نقدي (Cash)</option>
      <option value="visa">Visa / بطاقة</option>
      <option value="instapay">InstaPay</option>
      <option value="vodafone_cash">Vodafone Cash</option>
    </select>
    <input class="input amount" placeholder="المبلغ" inputmode="decimal">
    <input class="input ref" placeholder="رقم العملية / المرجع">
    <button class="btn danger remove" type="button">حذف</button>
  `;
  wrap.querySelector('.method').value = method;
  wrap.querySelector('.amount').value = amount;
  wrap.querySelector('.ref').value    = ref;

  function tuneRow() {
    const m = wrap.querySelector('.method').value;
    const refEl = wrap.querySelector('.ref');
    const needsRef = (m === 'instapay' || m === 'vodafone_cash');
    refEl.disabled = !needsRef;
    refEl.style.opacity = needsRef ? 1 : .4;
    if (!needsRef) refEl.value = '';
    if (needsRef && !refEl.value) {
      refEl.placeholder = (m === 'instapay')
        ? 'أدخل رقم العملية (إجباري)'
        : 'رقم العملية / هاتف المُحوِّل (إجباري)';
    } else if (!needsRef) {
      refEl.placeholder = 'رقم العملية / المرجع';
    }
  }

  wrap.querySelector('.method').addEventListener('change', ()=>{ tuneRow(); sumPayments(); });
  wrap.querySelector('.amount').addEventListener('input', sumPayments);
  wrap.querySelector('.ref').addEventListener('input', sumPayments);
  wrap.querySelector('.remove').onclick = ()=>{ wrap.remove(); sumPayments(); };

  tuneRow();
  return wrap;
}

function addPayment(method='cash', amount='', ref=''){
  el('#paymentsArea').appendChild(paymentRow(method, amount, ref));
  sumPayments();
}

function getPaymentsFromUI(){
  const rows = Array.from(document.querySelectorAll('#paymentsArea .payrow'));
  return rows.map(r=>({
    method: r.querySelector('.method').value,
    amount: +r.querySelector('.amount').value || 0,
    ref_no: (r.querySelector('.ref').value||'').trim()
  })).filter(p => p.amount > 0);
}

function sumPayments(){
  const total = parseFloat(document.querySelector('#grand').textContent||'0') || 0;
  const pays = getPaymentsFromUI();
  const credit = isCredit();

  // مرجع إجباري لمدفوعات Instapay و Vodafone Cash
  const missingRef = pays.find(p =>
    (p.method === 'instapay' || p.method === 'vodafone_cash') && !p.ref_no
  );
  if (missingRef) {
    showPayError('من فضلك أدخل رقم العملية/المرجع لمدفوعات InstaPay/Vodafone Cash.');
    updatePaidSum(pays, total);
    return;
  }

  const sum = pays.reduce((a,p)=> a + (+p.amount||0), 0);

  // حساب الباقي (Change) من الكاش فقط
  const cashSum = pays.filter(p=>p.method==='cash').reduce((a,p)=>a+(+p.amount||0),0);
  const nonCash = sum - cashSum;
  const remainingAfterNonCash = total - nonCash;
  const changeDue = Math.max(0, cashSum - Math.max(0, remainingAfterNonCash));

  // الزيادة مسموحة فقط = قيمة الباقي من الكاش
  const overpay = sum - total;
  const overpayAllowed = Math.abs(overpay - changeDue) < 0.01;

  if (!credit) {
    if (Math.abs(sum - total) > 0.009 && !overpayAllowed) {
      showPayError(`المدفوع (${sum.toFixed(2)}) لا يساوي الإجمالي (${total.toFixed(2)}). الزيادة مسموحة فقط لو من "كاش" وتتحول لباقي.`);
    } else {
      clearPayError();
    }
  } else {
    if ((sum - total) > 0.009 && !overpayAllowed) {
      showPayError(`المدفوع (${sum.toFixed(2)}) أكبر من الإجمالي (${total.toFixed(2)}). الزيادة مسموحة فقط لو من "كاش" وتتحول لباقي.`);
    } else {
      clearPayError();
    }
  }

  updatePaidSum(pays, total, changeDue);
  if (CREDIT_REMAINING) {
    const rem = credit ? Math.max(0, total - sum) : 0;
    CREDIT_REMAINING.textContent = rem.toFixed(2);
  }
}

function updatePaidSum(pays, total, changeDue=0){
  const sum = pays.reduce((a,p)=> a + (+p.amount||0), 0);
  el('#paidSum').textContent = (sum||0).toFixed(2);

  // بدل ما نحقن pill، بنعرضه في الملخص الثابت
  el('#changePillText').textContent = (changeDue||0).toFixed(2);
}

function showPayError(msg){ const w=el('#payWarn'); w.style.display='block'; w.textContent=msg; }
function clearPayError(){ const w=el('#payWarn'); w.style.display='none'; w.textContent=''; }

/* ========= Finish ========= */
el('#finish').onclick = ()=>{
  if (!CART.length) return alert('العربة فارغة');

  const total = parseFloat(el('#grand').textContent||'0') || 0;
  const pays  = getPaymentsFromUI();
  const credit = isCredit();
  if (!credit && !pays.length) return alert('أضف طريقة دفع واحدة على الأقل.');

  // تحقق من المراجع المطلوبة
  for (const p of pays) {
    if ((p.method === 'instapay' || p.method === 'vodafone_cash') && !p.ref_no) {
      return alert('من فضلك أدخل رقم العملية/المرجع لمدفوعات InstaPay/Vodafone Cash.');
    }
  }

  // السماح بالباقي من الكاش فقط
  const sum = pays.reduce((a,p)=> a + (+p.amount||0), 0);
  const cashSum = pays.filter(p=>p.method==='cash').reduce((a,p)=>a+(+p.amount||0),0);
  const nonCash = sum - cashSum;
  const remainingAfterNonCash = total - nonCash;
  const changeDue = Math.max(0, cashSum - Math.max(0, remainingAfterNonCash));
  const overpay = sum - total;
  const overpayAllowed = Math.abs(overpay - changeDue) < 0.01;
  if (!credit) {
    if (Math.abs(sum - total) > 0.009 && !overpayAllowed) {
      return alert('مجموع المدفوعات لا يساوي الإجمالي (الزيادة مسموحة فقط لو كاش وتتحول لباقي).');
    }
  } else {
    if ((sum - total) > 0.009 && !overpayAllowed) {
      return alert('مجموع المدفوعات أكبر من الإجمالي (الزيادة مسموحة فقط لو كاش وتتحول لباقي).');
    }
  }

  apiPost('cart_checkout_multi_legacy', {
    discount: LAST_DISCOUNT_AMOUNT || 0,
    tax: +el('#tax').value||0,
    payments: pays,
    customer_name:  CUSTOMER_NAME || '',
    customer_phone: CUSTOMER_PHONE || '',
    credit: credit ? 1 : 0,
    credit_due_date: CREDIT_DUE ? (CREDIT_DUE.value||'') : '',
    credit_note: CREDIT_NOTE ? (CREDIT_NOTE.value||'') : ''
  }).then(r=>{
    if(!r.ok){ alert(r.error||'فشل الحفظ'); return; }
    if (r.print_url) window.open(r.print_url,'_blank');
    alert('تم حفظ الفاتورة: ' + (r.invoice?.invoice_no || ''));

    loadCart();
    el('#paymentsArea').innerHTML=''; addPayment('cash','', '');
    el('#discount').value='0'; el('#tax').value='0'; refreshTotals();
    if (CREDIT_TOGGLE) {
      CREDIT_TOGGLE.checked = false;
      if (CREDIT_FIELDS) CREDIT_FIELDS.style.display = 'none';
      if (CREDIT_DUE) CREDIT_DUE.value = '';
      if (CREDIT_NOTE) CREDIT_NOTE.value = '';
    }
  });
};

el('#addPay').onclick = ()=> addPayment('cash','', '');
addPayment('cash','', '');
loadCart();
</script>

</body>
</html>
