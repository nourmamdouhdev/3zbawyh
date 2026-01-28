<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
require_role_in_or_redirect(['admin','Manger','owner']);

$db = db();

/** Helpers */
function has_col(PDO $db,$t,$c){
  $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}

if(!table_exists($db,'items')){ die('جدول items غير موجود.'); }
$hasSKU = has_col($db,'items','sku');

/* ===== AJAX search ===== */
if(($_GET['ajax'] ?? '') === 'search'){
  header('Content-Type: application/json; charset=utf-8');
  $q = trim($_GET['q'] ?? '');
  if($q===''){ echo json_encode(['ok'=>true,'items'=>[]]); exit; }

  $st=$db->prepare("
    SELECT id,name,".($hasSKU?"sku":"NULL AS sku")."
    FROM items WHERE name LIKE ? LIMIT 60
  ");
  $st->execute(['%'.$q.'%']);
  echo json_encode(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

/* ===== Menu ===== */
$menu=$db->query("
  SELECT id,name,".($hasSKU?"sku":"NULL AS sku")."
  FROM items ORDER BY id DESC LIMIT 120
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>طباعة ليبل China Post 40×20</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
  --label-w:40mm;
  --label-h:20mm;
  --pri:#ff7a1a;
  --pri-2:#1c4ed8;
  --bg:#f7f3ea;
  --card:#ffffff;
  --ink:#141414;
  --muted:#6b6b7a;
  --bd:#e8e3da;
  --shadow:0 14px 40px rgba(18,28,45,.08);
}
*{box-sizing:border-box}
body{
  margin:0;
  font-family:"Tajawal","Noto Kufi Arabic","Cairo",sans-serif;
  background:
    radial-gradient(700px 380px at 80% -10%, #ffe3c2 0%, transparent 60%),
    radial-gradient(700px 380px at -10% 10%, #d8e5ff 0%, transparent 55%),
    var(--bg);
  color:var(--ink);
  min-height:100vh;
  position:relative;
  overflow-x:hidden;
}
.container{
  max-width:1100px;
  margin:24px auto;
  padding:0 16px 28px;
  display:grid;
  grid-template-columns:1.1fr .9fr;
  grid-template-areas:
    "topbar topbar"
    "controls controls"
    "preview list";
  gap:12px;
}
@media (max-width:900px){
  .container{
    grid-template-columns:1fr;
    grid-template-areas:
      "topbar"
      "controls"
      "preview"
      "list";
  }
}

.btn{
  background:var(--pri);
  color:#111;
  border:0;
  padding:10px 14px;
  border-radius:12px;
  cursor:pointer;
  font-weight:800;
  transition:transform .12s ease, box-shadow .12s ease, opacity .12s ease;
  box-shadow:0 10px 24px rgba(255,122,26,.25);
}
.btn:disabled{opacity:.5}
.btn:hover{transform:translateY(-1px)}
.btn.secondary{
  background:#fff;
  color:var(--pri-2);
  border:1px solid #d9e3ff;
  box-shadow:none;
}
.btn.ghost{
  background:transparent;
  color:var(--ink);
  border:1px solid var(--bd);
  box-shadow:none;
}

.card{
  background:var(--card);
  border:1px solid var(--bd);
  border-radius:16px;
  padding:14px;
  box-shadow:var(--shadow);
  animation:fadeUp .35s ease;
}
@keyframes fadeUp{
  from{opacity:0;transform:translateY(6px)}
  to{opacity:1;transform:translateY(0)}
}

.topbar{grid-area:topbar;display:flex;align-items:center;justify-content:space-between;gap:12px}
.title{font-size:28px;margin:0;font-weight:900;letter-spacing:.2px}
.subtitle{color:var(--muted);font-size:13px;margin-top:4px}

.controls{grid-area:controls;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.search{flex:1 1 260px}
.search label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
.search input{
  width:100%;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid var(--bd);
  background:#fff;
  outline:0;
  transition:border-color .15s ease, box-shadow .15s ease;
}
.search input:focus{
  border-color:#ffd3b0;
  box-shadow:0 0 0 3px rgba(255,122,26,.15);
}
.chip{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:8px 10px;
  border-radius:999px;
  background:#fff2e6;
  color:#a14500;
  font-size:12px;
  font-weight:800;
  border:1px solid #ffd3b0;
}

.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
  gap:8px;
  max-height:520px;
  overflow:auto;
  padding-right:4px;
}
.item-btn{
  padding:12px;
  border:1px solid var(--bd);
  background:#fff;
  border-radius:12px;
  cursor:pointer;
  font-weight:800;
  text-align:right;
  transition:border-color .12s ease, box-shadow .12s ease, transform .12s ease;
}
.item-btn:hover{transform:translateY(-1px)}
.item-btn.active{
  border-color:#9bb7ff;
  box-shadow:0 0 0 3px rgba(28,78,216,.12);
}
.item-btn small{color:var(--muted)}

.preview-card{grid-area:preview;display:flex;flex-direction:column;gap:10px}
.preview-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
.eyebrow{font-size:12px;color:var(--muted);font-weight:700}
.size-pill{
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:800;
  color:#1847d0;
  background:#e7efff;
  border:1px solid #cfe0ff;
}
.list-card{grid-area:list}
.list-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;font-weight:800}
.muted{color:var(--muted);font-size:12px}
.empty-state{
  display:none;
  text-align:center;
  color:var(--muted);
  border:1px dashed var(--bd);
  border-radius:12px;
  padding:14px;
  margin-top:8px;
}

/* ===== LABEL PREVIEW ===== */
.label-preview{
  border:1px dashed #cfd3de;
  border-radius:14px;
  padding:12px;
  text-align:center;
  background:linear-gradient(180deg,#ffffff 0%,#f9f9ff 100%);
  min-height:220px;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
}
.label-preview svg{
  width:100%;
  height:auto;
  max-width:420px;
}
.label-code{
  margin-top:6px;
  font-size:18px;
  font-weight:900;
  direction:ltr;
  letter-spacing:.6px;
}
.label-name{
  margin-top:6px;
  font-size:14px;
  font-weight:700;
  color:var(--muted);
}

/* ===== PRINT (40×20mm) ===== */
@media print{
  @page{ size:var(--label-w) var(--label-h); margin:0 }
  html,body{
    width:var(--label-w);
    height:var(--label-h);
    margin:0;
    background:#fff;
    -webkit-print-color-adjust:exact;
    print-color-adjust:exact;
    overflow:hidden;
  }
  .container>*:not(.print-only){display:none!important}
  .print-only{
    width:var(--label-w);
    height:var(--label-h);
    display:flex;
    align-items:center;
    justify-content:center;
  }

  .preview-head{
    display:none!important;
  }

  .label-preview{
    border:0;
    width:100%;
    height:100%;
    padding:1mm 1.5mm;
    gap:.6mm;
    box-sizing:border-box;
    justify-content:center;
    align-items:center;
    text-align:center;
  }

  svg{
    width:100%;
    height:auto;
    max-height:8.5mm;
    display:block;
    margin:0 auto;
  }
  .label-code{
    font-size:3.2mm;
    line-height:1.1;
    font-weight:800;
    direction:ltr;
  }
  .label-name{
    font-size:2.4mm;
    line-height:1.1;
    font-weight:700;
  }
}
</style>
</head>

<body>
<div class="container">

  <div class="topbar">
    <div>
      <h1 class="title">طباعة الباركود</h1>
  <div class="subtitle">اختيار سريع للصنف وطباعة ليبل 40×20 مم</div>
    </div>
    <a class="btn ghost" href="/3zbawyh/public/dashboard.php">رجوع للوحة التحكم</a>
  </div>

  <div class="card controls">
    <div class="search">
      <label for="q">بحث سريع</label>
      <input id="q" placeholder="ابحث باسم الصنف أو SKU..." autocomplete="off">
    </div>
    <button class="btn" id="printBtn" disabled>طباعة الليبل</button>
    <span class="chip" id="selectedSku">SKU: —</span>
  </div>

  <div class="card print-only preview-card">
    <div class="preview-head">
      <div>
        <div class="eyebrow">معاينة الباركود</div>
        <div class="muted">جاهز للطباعة 40×20 مم</div>
      </div>
      <div class="size-pill">40×20</div>
    </div>
    <div class="label-preview">
      <svg id="barcode"></svg>
      <div class="label-code" id="labelCode">—</div>
      <div class="label-name" id="labelName">—</div>
    </div>
  </div>

  <div class="card list-card">
    <div class="list-head">
      <div>اختيار الصنف</div>
      <div class="muted">عدد الأصناف: <?= count($menu) ?></div>
    </div>
    <div class="grid" id="menu">
      <?php foreach($menu as $m): ?>
        <button class="item-btn"
          data-name="<?=e($m['name'])?>"
          data-sku="<?=e($m['sku']??'')?>">

          <?=e($m['name'])?><br>
          <small><?= $hasSKU?'SKU: '.e($m['sku']??'—'):'SKU غير مفعّل' ?></small>
        </button>
      <?php endforeach ?>
    </div>
    <div class="empty-state" id="emptyState">لا توجد نتائج مطابقة</div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
const menu = document.getElementById('menu');
const barcode = document.getElementById('barcode');
const printBtn = document.getElementById('printBtn');
const labelCode = document.getElementById('labelCode');
const labelName = document.getElementById('labelName');
const searchInput = document.getElementById('q');
const selectedSku = document.getElementById('selectedSku');
const emptyState = document.getElementById('emptyState');
const menuButtons = menu ? Array.from(menu.querySelectorAll('.item-btn')) : [];

let currentSKU = '', currentName = '';

function draw(code){
  if(!code){ barcode.innerHTML=''; return; }

  JsBarcode(barcode, code.toUpperCase(), {
    format:'CODE39',
    width:2.0,
    height:45,
    displayValue:false,
    margin:0
  });
}

function updateLabels(){
  labelCode.textContent = currentSKU || '—';
  labelName.textContent = currentName || '—';
  if(selectedSku){
    selectedSku.textContent = currentSKU ? `SKU: ${currentSKU}` : 'SKU: —';
  }
  printBtn.disabled = !currentSKU;
}

function setActive(btn){
  menuButtons.forEach(b => b.classList.toggle('active', b === btn));
}

function filterMenu(){
  const q = (searchInput?.value || '').trim().toLowerCase();
  let visible = 0;
  menuButtons.forEach(b => {
    const hay = (b.dataset.name + ' ' + (b.dataset.sku || '')).toLowerCase();
    const show = !q || hay.includes(q);
    b.style.display = show ? '' : 'none';
    if(show) visible++;
  });
  if(emptyState) emptyState.style.display = visible ? 'none' : 'block';
}

menu.addEventListener('click', e => {
  const b = e.target.closest('.item-btn');
  if(!b) return;
  currentSKU = b.dataset.sku || '';
  currentName = b.dataset.name || '';
  draw(currentSKU);
  updateLabels();
  setActive(b);
});

if(searchInput) searchInput.addEventListener('input', filterMenu);
filterMenu();
updateLabels();
printBtn.onclick = () => window.print();
</script>
</body>
</html>
