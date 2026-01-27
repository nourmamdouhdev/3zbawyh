<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();

/**
 * صلاحيات دخول الصفحة (عدّلها براحتك)
 * لو عايز الكاشير يدخل كمان: ضيف 'cashier'
 */
require_role_in_or_redirect(['admin','Manger','owner']);

$db = db();

/** Helpers */
function has_col(PDO $db,$t,$c){
  $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}

if(!table_exists($db,'items')){ die('جدول items غير موجود.'); }
$hasSKU = has_col($db,'items','sku');

// ===== AJAX: search by name only =====
if(($_GET['ajax'] ?? '') === 'search'){
  header('Content-Type: application/json; charset=utf-8');
  $q = trim((string)($_GET['q'] ?? ''));
  if($q === ''){
    echo json_encode(['ok'=>true,'items'=>[]]); exit;
  }

  // بحث بالاسم فقط
  $st = $db->prepare("
    SELECT id, name, ".($hasSKU ? "sku" : "NULL AS sku")."
    FROM items
    WHERE name LIKE ?
    ORDER BY name
    LIMIT 60
  ");
  $st->execute(['%'.$q.'%']);
  echo json_encode(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

// ===== AJAX: get one item =====
if(($_GET['ajax'] ?? '') === 'get_item'){
  header('Content-Type: application/json; charset=utf-8');
  $id = (int)($_GET['id'] ?? 0);
  if($id <= 0){ echo json_encode(['ok'=>false,'error'=>'invalid id']); exit; }

  $st = $db->prepare("
    SELECT id, name, ".($hasSKU ? "sku" : "NULL AS sku")."
    FROM items
    WHERE id=?
    LIMIT 1
  ");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if(!$row){ echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
  echo json_encode(['ok'=>true,'item'=>$row]);
  exit;
}

// ===== Big Menu items (أول شاشة) =====
// هنا اخترت أحدث 120 صنف عشان تكون منيو كبيرة (تقدر تغيّرها ORDER BY name لو تحب)
$menu = $db->query("
  SELECT id, name, ".($hasSKU ? "sku" : "NULL AS sku")."
  FROM items
  ORDER BY id DESC
  LIMIT 120
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>طباعة باركود فقط</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
  :root{ --bg:#f7f8fb; --card:#fff; --ink:#111; --muted:#667; --bd:#e8e8ef; --pri:#2261ee; }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{
    background: radial-gradient(1200px 600px at 50% -200px, #eef3ff, #f6f7fb);
    color:var(--ink);
    font-family: system-ui, -apple-system, Segoe UI, Roboto, "Noto Kufi Arabic", "Cairo", sans-serif;
    line-height:1.55;
  }
  .container{max-width:1100px;margin:18px auto;padding:0 14px}

  .topbar{
    display:flex; gap:10px; align-items:center; justify-content:space-between;
    margin-bottom:12px;
  }
  .title{margin:0;font-size:20px}
  .btn{
    border:0;background:var(--pri);color:#fff;
    padding:10px 14px;border-radius:12px;cursor:pointer;font-weight:700;
    transition:transform .15s,opacity .15s,box-shadow .15s;
    box-shadow:0 6px 16px rgba(34,97,238,.18);
    text-decoration:none; display:inline-flex; align-items:center; gap:8px;
  }
  .btn:hover{ transform: translateY(-1px); }
  .btn.secondary{ background:#eef3fb; color:#0b4ea9; box-shadow:none; }
  .card{
    background:var(--card);
    border:1px solid var(--bd);
    border-radius:14px;
    box-shadow:0 8px 24px rgba(0,0,0,.06);
    padding:14px;
    margin-block:12px;
  }
  .input{
    width:100%;
    border:1px solid var(--bd);
    background:#fff; color:var(--ink);
    border-radius:12px; padding:12px 12px; outline:0;
    transition:border-color .15s, box-shadow .15s;
    font-size:16px;
  }
  .input:focus{
    border-color:#cfe2ff;
    box-shadow:0 0 0 3px #cfe2ff55;
  }

  .grid{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap:10px;
  }
  .item-btn{
    width:100%;
    border:1px solid var(--bd);
    background:#fff;
    border-radius:14px;
    padding:14px 12px;
    cursor:pointer;
    text-align:right;
    font-weight:800;
    transition:transform .12s, box-shadow .12s, border-color .12s;
    box-shadow:0 6px 18px rgba(0,0,0,.05);
  }
  .item-btn:hover{
    transform: translateY(-1px);
    border-color:#cfe2ff;
    box-shadow:0 10px 22px rgba(0,0,0,.07);
  }
  .item-sub{
    display:block;
    margin-top:6px;
    font-weight:600;
    font-size:12px;
    color:var(--muted);
  }

  .result-head{
    display:flex; gap:10px; align-items:center; justify-content:space-between;
    flex-wrap:wrap;
  }

  .barcode-area{
    display:grid;
    grid-template-columns: 1fr;
    gap:12px;
  }
  @media(min-width:900px){
    .barcode-area{ grid-template-columns: 1.1fr .9fr; align-items:start; }
  }
  .preview{
    border:1px dashed #d7dbe8;
    border-radius:14px;
    padding:12px;
    background:#fff;
    text-align:center;
    min-height:180px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    gap:8px;
  }
  .preview svg{ max-width:100%; height:auto; }
  .muted{ color:var(--muted); font-size:13px; }

  .empty{
    text-align:center;
    color:var(--muted);
    padding:22px 10px;
  }

  :root{
    --barcode-page-size: 3in;
  }

  @media print{
    @page{ size: var(--barcode-page-size) var(--barcode-page-size); margin: 0; }
    html, body{ width: var(--barcode-page-size); height: var(--barcode-page-size); }
    body{ background:#fff; margin:0; }
    .container > *:not(.print-only){ display:none !important; }
    .print-only{ box-shadow:none; border:0; }
    .print-only{ width: var(--barcode-page-size); height: var(--barcode-page-size); margin:0 auto; }
    .barcode-area{ height:100%; align-content:center; }
    .preview{ border:0; padding:0; min-height:auto; }
    .preview svg{ width:100%; height:auto; max-height:1.6in; }
    #previewHint{ display:none !important; }
  }
</style>
</head>
<body>

<div class="container">
  <div class="topbar">
    <h1 class="title">طباعة باركود فقط</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn secondary" href="/3zbawyh/public/dashboard.php">عودة للوحة</a>
      <button class="btn secondary" id="clearBtn" type="button">تفريغ</button>
    </div>
  </div>

  <div class="card">
    <div class="result-head">
      <div style="flex:1;min-width:240px">
        <input class="input" id="q" placeholder="ابحث باسم الصنف فقط...">
        <div class="muted" style="margin-top:6px">اختار من المنيو أو اكتب اسم الصنف.</div>
      </div>
      <div style="min-width:220px">
        <button class="btn" type="button" id="printBtn" disabled>طباعة الباركود فقط</button>
      </div>
    </div>
  </div>

  <!-- Preview -->
  <div class="card print-only">
    <div class="barcode-area">
      <div>
        <div style="font-weight:900;font-size:18px;margin-bottom:6px" id="itemName">—</div>
        <div class="muted">قيمة الباركود (SKU): <span id="itemSku">—</span></div>
        <div class="muted" style="margin-top:8px">
          ملاحظة: الصفحة دي بتطبع الباركود فقط (بدون أسعار/تفاصيل تانية) بمقاس 3×3 إنش.
        </div>
      </div>

      <div class="preview">
        <svg id="barcode_preview"></svg>
        <div class="muted" id="previewHint">اختار صنف عشان يظهر الباركود</div>
      </div>
    </div>
  </div>

  <!-- Search results -->
  <div class="card" id="searchCard" style="display:none">
    <div style="font-weight:900;margin-bottom:10px">نتائج البحث</div>
    <div class="grid" id="searchGrid"></div>
    <div class="empty" id="searchEmpty" style="display:none">مفيش نتائج</div>
  </div>

  <!-- Big menu -->
  <div class="card" id="menuCard">
    <div style="font-weight:900;margin-bottom:10px">منيو الأصناف</div>
    <div class="grid" id="menuGrid">
      <?php foreach($menu as $it): ?>
        <button class="item-btn" type="button"
          data-id="<?=$it['id']?>"
          data-name="<?=e($it['name'])?>"
          data-sku="<?=e((string)($it['sku'] ?? ''))?>">
          <?=e($it['name'])?>
          <span class="item-sub"><?= $hasSKU ? ('SKU: '.e((string)($it['sku'] ?? '—'))) : 'SKU غير مفعّل' ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
const hasSKU = <?= json_encode($hasSKU) ?>;

const q = document.getElementById('q');
const menuCard = document.getElementById('menuCard');
const menuGrid = document.getElementById('menuGrid');

const searchCard = document.getElementById('searchCard');
const searchGrid = document.getElementById('searchGrid');
const searchEmpty = document.getElementById('searchEmpty');

const itemName = document.getElementById('itemName');
const itemSku  = document.getElementById('itemSku');
const printBtn = document.getElementById('printBtn');

const barcodePreview = document.getElementById('barcode_preview');
const previewHint = document.getElementById('previewHint');

let currentSKU = '';
let currentName = '';

function renderBarcode(val){
  if(!barcodePreview) return;
  if(!val){
    barcodePreview.innerHTML = '';
    return;
  }
  if(window.JsBarcode){
    window.JsBarcode(barcodePreview, val, {
      format: 'CODE128',
      lineColor: '#111',
      width: 2,
      height: 70,
      displayValue: true,
      fontSize: 16,
      margin: 8
    });
  }
}

function selectItem(name, sku){
  currentName = name || '';
  currentSKU  = (sku || '').trim();

  itemName.textContent = currentName || '—';
  itemSku.textContent  = currentSKU || '—';

  if(!hasSKU){
    renderBarcode('');
    previewHint.textContent = 'SKU غير مفعّل في جدول items';
    printBtn.disabled = true;
    return;
  }

  if(!currentSKU){
    renderBarcode('');
    previewHint.textContent = 'الصنف ده ملوش SKU';
    printBtn.disabled = true;
    return;
  }

  previewHint.textContent = '';
  renderBarcode(currentSKU);
  printBtn.disabled = false;
}

async function getItem(id){
  const res = await fetch(`?ajax=get_item&id=${encodeURIComponent(id)}`);
  const data = await res.json();
  if(data && data.ok && data.item){
    selectItem(data.item.name, data.item.sku);
  }
}

function makeBtn(it){
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'item-btn';
  b.innerHTML = `
    ${escapeHtml(it.name)}
    <span class="item-sub">${hasSKU ? ('SKU: ' + escapeHtml(it.sku || '—')) : 'SKU غير مفعّل'}</span>
  `;
  b.addEventListener('click', ()=> selectItem(it.name, it.sku || ''));
  return b;
}

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

let t = null;
q.addEventListener('input', ()=>{
  clearTimeout(t);
  t = setTimeout(runSearch, 220);
});

async function runSearch(){
  const term = (q.value || '').trim();

  if(term.length === 0){
    searchCard.style.display = 'none';
    menuCard.style.display = 'block';
    searchGrid.innerHTML = '';
    searchEmpty.style.display = 'none';
    return;
  }

  menuCard.style.display = 'none';
  searchCard.style.display = 'block';
  searchGrid.innerHTML = '';
  searchEmpty.style.display = 'none';

  try{
    const res = await fetch(`?ajax=search&q=${encodeURIComponent(term)}`);
    const data = await res.json();
    const items = (data && data.ok && Array.isArray(data.items)) ? data.items : [];

    if(items.length === 0){
      searchEmpty.style.display = 'block';
      return;
    }

    items.forEach(it => searchGrid.appendChild(makeBtn(it)));
  }catch(e){
    searchEmpty.style.display = 'block';
    searchEmpty.textContent = 'حصل خطأ في البحث';
  }
}

// menu click (fast select)
menuGrid.addEventListener('click', (e)=>{
  const btn = e.target.closest('.item-btn');
  if(!btn) return;
  selectItem(btn.dataset.name || '', btn.dataset.sku || '');
});

document.getElementById('clearBtn').addEventListener('click', ()=>{
  q.value = '';
  searchCard.style.display = 'none';
  menuCard.style.display = 'block';
  searchGrid.innerHTML = '';
  searchEmpty.style.display = 'none';
  selectItem('', '');
});

function printBarcodeOnly(){
  if(!currentSKU) return;

  renderBarcode(currentSKU);
  window.print();
}

printBtn.addEventListener('click', printBarcodeOnly);

// init
selectItem('', '');
</script>
</body>
</html>
