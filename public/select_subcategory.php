<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
if (!isset($_SESSION['pos_flow'])) { $_SESSION['pos_flow'] = []; }
$__customer_name    = $_SESSION['pos_flow']['customer_name']    ?? '';
$__customer_phone   = $_SESSION['pos_flow']['customer_phone']   ?? '';
$__customer_skipped = $_SESSION['pos_flow']['customer_skipped'] ?? false;

require_role_in_or_redirect(['admin','cashier']);

if (!isset($_SESSION['pos_flow']) || empty($_SESSION['pos_flow']['category_id'])) {
  header('Location: /3zbawyh/public/select_category.php'); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $sid = isset($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : 0;
  $_SESSION['pos_flow']['subcategory_id'] = $sid ?: null;
  header('Location: /3zbawyh/public/select_items.php'); exit;
}

$category_id = (int)$_SESSION['pos_flow']['category_id'];
$u = current_user();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"> <!-- مهم للموبايل -->
<title>POS — اختيار الفرعي</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{--bg:#f6f7fb;--card:#fff;--bd:#e8e8ef;--pri:#2261ee;--pri-ink:#fff}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;background:radial-gradient(1200px 600px at 50% -200px,#eef3ff,#f6f7fb);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;color:#111;
}

/* ====== Nav ====== */
.nav{
  display:flex;justify-content:space-between;align-items:center;
  padding:12px 14px;gap:10px;flex-wrap:wrap; /* يلف على الموبايل */
}
.nav .right{display:flex;gap:8px;flex-wrap:wrap}
.nav a{
  text-decoration:none;color:#1a1a1a;background:#fff;border:1px solid var(--bd);
  padding:8px 10px;border-radius:10px;display:inline-flex;align-items:center;
}
.nav a:active{transform:scale(.98)}
.badge{
  font-size:12px;padding:4px 8px;border-radius:999px;background:#eef2f8;color:#345;
  border:1px solid #e3e8f2;display:inline-flex;align-items:center;gap:4px
}

/* ====== Layout ====== */
.center{min-height:calc(100% - 64px);display:grid;place-items:center;padding:16px}
.box{
  width:min(900px,96vw);background:var(--card);border:1px solid var(--bd);
  border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:18px
}

/* ====== Grid ====== */
.grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
  gap:12px;margin-top:12px
}
.card{
  border:1px solid var(--bd);border-radius:12px;padding:14px;
  cursor:pointer;transition:.15s;background:#fff;
  min-height:56px;display:flex;align-items:center;justify-content:space-between;gap:8px
}
.card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.06)}
.card:active{transform:translateY(0) scale(.99)}

/* ====== Responsive ====== */
@media (max-width: 768px){
  .box{padding:14px;border-radius:14px}
  .grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
}
@media (max-width: 480px){
  .nav{padding:10px}
  .box{width:100%;border-radius:12px;padding:12px}
  .grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px}
  .card{padding:12px;min-height:52px}
  .badge{font-size:11px}
}
</style>
</head>
<body>
<nav class="nav">
  <div><strong>POS — صفحة 2: اختيار الفرعي</strong></div>
  <div class="right">
    <a href="/3zbawyh/public/select_category.php">← التصنيف</a>
    <a href="/3zbawyh/public/cart_checkout.php">الكارت / الدفع</a>
    <a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a>
  </div>

  <?php if($__customer_name !== '' || $__customer_phone !== ''): ?>
    <span class="badge" style="background:#eef2ff;border-color:#dbe1ff;color:#1d3bd1">
      <?= htmlspecialchars($__customer_name ?: 'عميل') ?>
      <?php if ($__customer_phone !== ''): ?> — <?= htmlspecialchars($__customer_phone) ?> <?php endif; ?>
    </span>
  <?php elseif($__customer_skipped): ?>
    <span class="badge" style="background:#fafafa;border-color:#e5e7eb;color:#555">عميل نقدي</span>
  <?php endif; ?>
</nav>

<div class="center">
  <div class="box">
    <div style="color:#555;margin-bottom:8px">التصنيف المختار: <span id="catName">#<?=$category_id?></span></div>
    <h3 style="margin:0 0 8px">اختر التصنيف الفرعي</h3>

    <form id="subForm" method="post" style="display:none">
      <input type="hidden" name="subcategory_id" id="subcategory_id">
    </form>

    <div id="subs" class="grid" aria-live="polite"></div>
  </div>
</div>

<script>
const cid = <?=$category_id?>;
const grid = document.getElementById('subs');

function api(a,p={}){
  const q = new URLSearchParams(p).toString();
  return fetch('/3zbawyh/public/pos_api.php?' + (q? q+'&':'') + 'action=' + a).then(r=>r.json());
}

function selectSub(id){
  document.getElementById('subcategory_id').value = id;
  document.getElementById('subForm').submit();
}

function render(list){
  grid.innerHTML='';
  list.forEach(s=>{
    const btn = document.createElement('button');
    btn.type='button';
    btn.className='card';
    btn.innerHTML = `
      <strong style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${s.name}</strong>`;
    btn.onclick = ()=>selectSub(s.id);
    grid.appendChild(btn);
  });
}

api('search_categories').then(r=>{
  if(r?.ok){
    const c=(r.categories||[]).find(x=>+x.id===cid);
    if(c) document.getElementById('catName').textContent=c.name;
  }
});
api('search_subcategories',{category_id:cid}).then(r=>{
  if(!r?.ok){ alert(r.error||'خطأ'); return; }
  render(r.subcategories||[]);
});
</script>
</body>
</html>
  