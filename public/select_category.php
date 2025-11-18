<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier','Manger']);
if (!isset($_SESSION['pos_flow'])) { $_SESSION['pos_flow'] = []; }
$__customer_name    = $_SESSION['pos_flow']['customer_name']    ?? '';
$__customer_phone   = $_SESSION['pos_flow']['customer_phone']   ?? '';
$__customer_skipped = $_SESSION['pos_flow']['customer_skipped'] ?? false;



if (isset($_GET['reset'])) {
  $_SESSION['pos_flow'] = ['category_id'=>null,'subcategory_id'=>null];
} elseif (!isset($_SESSION['pos_flow'])) {
  $_SESSION['pos_flow'] = ['category_id'=>null,'subcategory_id'=>null];
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $cid = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
  $_SESSION['pos_flow']['category_id'] = $cid ?: null;
  $_SESSION['pos_flow']['subcategory_id'] = null;
  header('Location: /3zbawyh/public/select_subcategory.php'); exit;
}
$u = current_user();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"> <!-- مهم للموبايل -->
<title>POS — اختيار التصنيف</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{
  --bg:#f6f7fb;--card:#fff;--bd:#e8e8ef;--pri:#2261ee;--pri-ink:#fff;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;background:radial-gradient(1200px 600px at 50% -200px,#eef3ff,#f6f7fb);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
  color:#111;
}

/* ====== Nav ====== */
.nav{
  display:flex;align-items:center;justify-content:space-between;
  padding:12px 14px;gap:10px;flex-wrap:wrap; /* يلف على الموبايل */
  background:transparent;
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

/* ====== Grid of categories ====== */
.grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
  gap:12px;margin-top:12px
}
.card{
  border:1px solid var(--bd);border-radius:12px;padding:14px;cursor:pointer;transition:.15s;
  min-height:56px; /* لمس مريح */
  display:flex;align-items:center;justify-content:space-between;gap:8px;background:#fff;
}
.card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.06)}
.card:active{transform:translateY(0) scale(.99)}
.btn{border:0;background:var(--pri);color:var(--pri-ink);padding:10px 14px;border-radius:12px;cursor:pointer}

/* ====== Responsive tweaks ====== */
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
  <div><strong>POS — صفحة 1: اختيار التصنيف</strong></div>

  <div class="right">
    <a href="/3zbawyh/public/cart_checkout.php">الكارت / الدفع</a>
    <a href="/3zbawyh/public/select_category.php?reset=1">تصفير</a>
    <a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a>
  </div>

  <?php if($__customer_name !== '' || $__customer_phone !== ''): ?>
    <span class="badge" style="background:#eef2ff;border-color:#dbe1ff;color:#1d3bd1">
      <?= htmlspecialchars($__customer_name ?: 'عميل') ?>
      <?php if ($__customer_phone !== ''): ?>
        — <?= htmlspecialchars($__customer_phone) ?>
      <?php endif; ?>
    </span>
  <?php elseif($__customer_skipped): ?>
    <span class="badge" style="background:#fafafa;border-color:#e5e7eb;color:#555">عميل نقدي</span>
  <?php endif; ?>
</nav>

<div class="center">
  <div class="box">
    <h3 style="margin:0 0 8px">اختر التصنيف</h3>
    <p style="margin:0;color:#666">اضغط على التصنيف للانتقال إلى صفحة الفرعي.</p>

    <form id="catForm" method="post" style="display:none">
      <input type="hidden" name="category_id" id="category_id">
    </form>

    <div id="cats" class="grid" aria-live="polite"></div>
  </div>
</div>

<script>
const box = document.getElementById('cats');

function api(action, params={}) {
  const q = new URLSearchParams(params).toString();
  return fetch('/3zbawyh/public/pos_api.php?' + (q? q+'&':'') + 'action=' + action)
    .then(r=>r.json());
}

function renderCats(list){
  box.innerHTML='';
  list.forEach(c=>{
    const d = document.createElement('button');
    d.type='button';
    d.className='card';
    d.innerHTML = `
      <strong style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${c.name}</strong>
      <span class="badge">#${c.id}</span>`;
    d.onclick = ()=>{
      document.getElementById('category_id').value = c.id;
      document.getElementById('catForm').submit();
    };
    box.appendChild(d);
  });
}

api('search_categories').then(r=>{
  if(!r.ok){ alert(r.error||'خطأ'); return; }
  renderCats(r.categories||[]);
});
</script>
</body>
</html>
