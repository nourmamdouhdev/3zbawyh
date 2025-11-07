<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
require_role_in_or_redirect(['admin','cashier']);

if (!isset($_SESSION['pos_flow'])) { $_SESSION['pos_flow'] = []; }
$__customer_name    = $_SESSION['pos_flow']['customer_name']    ?? '';
$__customer_phone   = $_SESSION['pos_flow']['customer_phone']   ?? '';
$__customer_skipped = $_SESSION['pos_flow']['customer_skipped'] ?? false;

$u = current_user();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"> <!-- مهم للموبايل -->
<title>POS — Cart Checkout</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{
  --bg:#f6f7fb;--card:#fff;--bd:#e8e8ef;--ink:#111;
  --pri:#2261ee;--pri-ink:#fff;--ok:#137333;--danger:#b3261e
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:radial-gradient(1200px 600px at 50% -200px,#eef3ff,#f6f7fb);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--ink)
}

/* ===== Nav ===== */
.nav{
  display:flex;justify-content:space-between;align-items:center;
  padding:12px 14px;gap:10px;flex-wrap:wrap; /* يلف على الموبايل */
  background:#fff;border-bottom:1px solid var(--bd)
}

/* ===== Layout ===== */
.center{min-height:calc(100% - 60px);display:grid;place-items:center;padding:16px}
.box{
  width:min(1100px,96vw);background:var(--card);border:1px solid var(--bd);
  border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:16px
}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.btn{
  border:0;background:var(--pri);color:var(--pri-ink);
  padding:10px 14px;border-radius:12px;cursor:pointer
}
.btn.ok{background:var(--ok)}
.btn.danger{background:var(--danger)}
.btn.secondary{background:#eef3fb;color:#0b4ea9}
.input{border:1px solid var(--bd);border-radius:10px;padding:9px 10px;background:#fff}
.pill{display:inline-block;background:#0b4ea914;border:1px solid #cfe2ff;color:#0b4ea9;padding:4px 10px;border-radius:999px;font-weight:600}
.warn{color:var(--danger);font-weight:700}
.right{text-align:right}
.card{background:#fff;border:1px solid var(--bd);border-radius:12px;padding:12px}

/* ===== Cart list ===== */
.list{
  max-height:420px;overflow:auto;border:1px solid var(--bd);
  border-radius:10px;margin-top:8px
}
.line{
  display:grid;grid-template-columns:2fr 90px 120px 120px 36px;
  gap:8px;align-items:center;padding:8px 10px;border-bottom:1px solid var(--bd)
}
.line:last-child{border-bottom:0}

/* ===== Payments ===== */
#paymentsArea .payrow .input{min-width:160px}

/* ===== Responsive tweaks ===== */
@media (max-width: 900px){
  .line{grid-template-columns:1.5fr 80px 110px 100px 32px}
}
@media (max-width: 720px){
  .box{padding:14px;border-radius:14px}
  .list{max-height:55vh}
  .line{grid-template-columns:1.2fr 70px 100px 90px 30px}
  #paymentsArea .payrow .input{min-width:120px}
}
@media (max-width: 520px){
  .nav{padding:10px}
  .box{width:100%;padding:12px;border-radius:12px}
  .list{max-height:58vh}

  /* خط العربة يتحول لسطرين: الاسم فوق، وتحت: كمية/سعر/إجمالي/حذف */
  .line{
    grid-template-columns:1fr 1fr; grid-auto-rows:auto;
    gap:8px 10px; align-items:center;
  }
  .line > div:nth-child(1){grid-column:1 / -1}          /* الاسم */
  .line > div:nth-child(2){grid-column:1 / 2}           /* الكمية */
  .line > div:nth-child(3){grid-column:2 / 3}           /* السعر */
  .line > div:nth-child(4){grid-column:1 / 2}           /* الإجمالي */
  .line > div:nth-child(5){grid-column:2 / 3; justify-self:end} /* حذف */

  .input{width:100%}
  .row.payrow{width:100%}
  .row.payrow .input,
  .row.payrow select{flex:1 1 100%;width:100%}
  .row.payrow .remove{width:100%}
}
@media (max-width: 360px){
  /* خفّض الحواف وخلي الأزرار أسهل */
  .btn{padding:10px 12px;border-radius:10px}
}
</style>
</head>
<body>
<nav class="nav">
  <div><strong>POS — Cart Checkout</strong></div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn secondary" href="/3zbawyh/public/select_category.php">+ أضف أصناف</a>
    <a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a>
  </div>
</nav>

<div class="center">
  <!-- كارت بيانات العميل -->
  <div class="card" style="margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:8px;width:min(1100px,96vw);flex-wrap:wrap">
    <div>
      <div style="font-weight:bold">العميل</div>
      <div>
        <?= htmlspecialchars($__customer_name !== '' ? $__customer_name : 'عميل نقدي') ?>
        <?php if ($__customer_phone !== ''): ?>
          — <?= htmlspecialchars($__customer_phone) ?>
        <?php endif; ?>
      </div>
    </div>
    <a class="btn secondary" href="/3zbawyh/public/customer_name.php">تعديل</a>
  </div>

  <div class="box">
    <h3 style="margin:0">العربة</h3>
    
    <div id="list" class="list"></div>

    <div class="row" style="margin-top:10px">
      خصم: <input id="discount" class="input" style="width:120px" value="0" inputmode="decimal">
      ضريبة: <input id="tax" class="input" style="width:120px" value="0" inputmode="decimal">
      <span class="pill">الإجمالي: <span id="grand">0.00</span> ج.م</span>
    </div>

    <hr>

    <h4>طرق الدفع</h4>
    <div id="payWarn" class="warn" style="display:none;margin-bottom:6px"></div>
    <div id="paymentsArea"></div>
    <div class="row" style="margin-top:6px">
      <button class="btn secondary" id="addPay" type="button">+ إضافة دفع</button>
      <span class="pill">المدفوع: <span id="paidSum">0.00</span> ج.م</span>
      <!-- سيتم حقن Pill للباقي تلقائياً -->
    </div>

    <div class="row" style="justify-content:flex-end;margin-top:12px">
      <button class="btn ok" id="finish" type="button">حفظ + طباعة</button>
    </div>
  </div>
  
</div>

<script>
// ============ قيم العميل من الـPHP (من الـSession) ============
const CUSTOMER_NAME  = <?= json_encode($__customer_name, JSON_UNESCAPED_UNICODE) ?>;
const CUSTOMER_PHONE = <?= json_encode($__customer_phone, JSON_UNESCAPED_UNICODE) ?>;

const el  = s=>document.querySelector(s);
const fmt = n=>{ n=parseFloat(n||0); return isNaN(n)?'0.00':n.toFixed(2); };

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
    box.innerHTML='<div style="padding:16px;color:#666">العربة فارغة — أضف أصناف من صفحة الأصناف.</div>';
    return;
  }
  CART.forEach(l=>{
    const d = document.createElement('div');
    d.className='line';
    const stock = (l.stock==null ? '-' : l.stock);
    d.innerHTML = `
      <div><strong>${l.name}</strong><div style="color:#666;font-size:12px">مخزون: ${stock}</div></div>
      <div><input class="input qty" style="width:100%" value="${l.qty}" inputmode="decimal"></div>
      <div><input class="input price" style="width:100%" value="${l.unit_price}" inputmode="decimal"></div>
      <div class="right">${fmt(l.qty*l.unit_price)}</div>
      <div class="right"><button class="btn danger rm" type="button">✖</button></div>
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
  const disc = +el('#discount').value||0;
  const tx   = +el('#tax').value||0;
  el('#grand').textContent = fmt(subtotal - disc + tx);
  sumPayments();
}
el('#discount').addEventListener('input', refreshTotals);
el('#tax').addEventListener('input', refreshTotals);

/* ========= Payments ========= */
function paymentRow(method='cash', amount='', ref=''){
  const wrap = document.createElement('div');
  wrap.className='row payrow';
  wrap.innerHTML = `
    <select class="input method">
      <option value="cash">نقدي (Cash)</option>
      <option value="visa">Visa / بطاقة</option>
      <option value="instapay">InstaPay</option>
      <option value="vodafone_cash">Vodafone Cash</option>
      <option value="agyl">آجل (دفعة مؤجلة)</option>
    </select>
    <input class="input amount" style="width:140px" placeholder="المبلغ" inputmode="decimal">
    <input class="input ref" style="width:220px" placeholder="رقم العملية / المرجع">
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

  if (Math.abs(sum - total) > 0.009 && !overpayAllowed) {
    showPayError(`المدفوع (${sum.toFixed(2)}) لا يساوي الإجمالي (${total.toFixed(2)}). الزيادة مسموحة فقط لو من "كاش" وتتحول لباقي.`);
  } else {
    clearPayError();
  }

  updatePaidSum(pays, total, changeDue);
}

function updatePaidSum(pays, total, changeDue=0){
  const sum = pays.reduce((a,p)=> a + (+p.amount||0), 0);
  el('#paidSum').textContent = (sum||0).toFixed(2);

  let pill = document.querySelector('#changePill');
  if (!pill) {
    pill = document.createElement('span');
    pill.id = 'changePill';
    pill.className = 'pill';
    // اتأكد إن فيه Row بعد زرار إضافة الدفع
    const rows = document.querySelectorAll('.row');
    const lastRow = rows[rows.length-1];
    lastRow.appendChild(pill);
  }
  pill.textContent = `الباقي (كاش): ${ (changeDue||0).toFixed(2) } ج.م`;
}

function showPayError(msg){ const w=el('#payWarn'); w.style.display='block'; w.textContent=msg; }
function clearPayError(){ const w=el('#payWarn'); w.style.display='none'; w.textContent=''; }

function summarizeForSave(pays){
  const validPays = pays.filter(p => (+p.amount || 0) > 0.0001);

  const sumBy = { cash:0, visa:0, instapay:0, vodafone_cash:0, agyl:0 };
  for (const p of validPays) {
    if (sumBy.hasOwnProperty(p.method)) sumBy[p.method] += (+p.amount || 0);
  }

  if (validPays.length === 0) {
    return { save_payment_method: 'cash', payment_note: 'no payments' };
  }

  const distinct = Object.entries(sumBy).filter(([_,v]) => v > 0.0001);
  if (distinct.length === 1) {
    const onlyKey = distinct[0][0];
    const mapForSave = { cash:'cash', visa:'visa', instapay:'instapay', vodafone_cash:'vodafone_cash', agyl:'agel' };
    return {
      save_payment_method: mapForSave[onlyKey] || 'mixed',
      payment_note: `Single: ${onlyKey}=${distinct[0][1].toFixed(2)}`
    };
  }

  const note =
    `Dist: cash=${sumBy.cash.toFixed(2)}, visa=${sumBy.visa.toFixed(2)}, ` +
    `instapay=${sumBy.instapay.toFixed(2)}, vodafone_cash=${sumBy.vodafone_cash.toFixed(2)}, ` +
    `agyl=${sumBy.agyl.toFixed(2)}`;

  return { save_payment_method: 'mixed', payment_note: note };
}

/* ========= Finish ========= */
el('#finish').onclick = ()=>{
  if (!CART.length) return alert('العربة فارغة');

  const total = parseFloat(el('#grand').textContent||'0') || 0;
  const pays  = getPaymentsFromUI();
  if (!pays.length) return alert('أضف طريقة دفع واحدة على الأقل.');

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
  if (Math.abs(sum - total) > 0.009 && !overpayAllowed) {
    return alert('مجموع المدفوعات لا يساوي الإجمالي (الزيادة مسموحة فقط لو كاش وتتحول لباقي).');
  }

  apiPost('cart_checkout_multi_legacy', {
    discount: +el('#discount').value||0,
    tax: +el('#tax').value||0,
    payments: pays,
    customer_name:  CUSTOMER_NAME || '',
    customer_phone: CUSTOMER_PHONE || ''
  }).then(r=>{
    if(!r.ok){ alert(r.error||'فشل الحفظ'); return; }
    if (r.print_url) window.open(r.print_url,'_blank');
    alert('تم حفظ الفاتورة: ' + (r.invoice?.invoice_no || ''));

    loadCart();
    el('#paymentsArea').innerHTML=''; addPayment('cash','', '');
    el('#discount').value='0'; el('#tax').value='0'; refreshTotals();
  });
};

el('#addPay').onclick = ()=> addPayment('cash','', '');
addPayment('cash','', '');
loadCart();
</script>
</body>
</html>
