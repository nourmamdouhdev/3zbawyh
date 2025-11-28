<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier','Manger']);

$db = db();

/*
  نتأكد إن فيه:
  - category_id
  - subcategory_id
  في الـ POS flow
*/
if (
  !isset($_SESSION['pos_flow']) ||
  empty($_SESSION['pos_flow']['category_id']) ||
  empty($_SESSION['pos_flow']['subcategory_id'])
) {
  header('Location: /3zbawyh/public/select_category.php');
  exit;
}

$category_id    = (int)$_SESSION['pos_flow']['category_id'];
$subcategory_id = (int)$_SESSION['pos_flow']['subcategory_id'];

/* ========== حفظ الإختيار ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ssid = isset($_POST['sub_subcategory_id']) ? (int)$_POST['sub_subcategory_id'] : 0;
  $_SESSION['pos_flow']['sub_subcategory_id'] = $ssid ?: null;

  // بعد اختيار القسم الفرعي الفرعي نروح على صفحة الأصناف
  header('Location: /3zbawyh/public/select_items.php');
  exit;
}

/* ========== أسماء التصنيفات ========== */
$catName  = '#'.$category_id;
$subName  = '#'.$subcategory_id;

try {
  $st = $db->prepare("SELECT name FROM categories WHERE id = ?");
  $st->execute([$category_id]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $catName = $row['name'];
  }
} catch (Throwable $e) {}

try {
  $st = $db->prepare("SELECT name FROM subcategories WHERE id = ?");
  $st->execute([$subcategory_id]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $subName = $row['name'];
  }
} catch (Throwable $e) {}

/* ========== جلب sub-sub-categories ========== */
$subSubCats = [];
try {
  $st = $db->prepare("SELECT id, name FROM sub_subcategories WHERE subcategory_id = ? ORDER BY name");
  $st->execute([$subcategory_id]);
  $subSubCats = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $subSubCats = [];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>اختيار القسم الفرعي الفرعي</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{
  --bg1:#f6f7fb; --bg2:#eef3ff; --card:#fff; --ink:#111; --muted:#667;
  --pri:#2261ee; --pri-ink:#fff; --bd:#e8e8ef; --badge:#eef2f8; --accent:#0b4ea9;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:radial-gradient(1200px 600px at 50% -200px,var(--bg2),var(--bg1));
  color:var(--ink);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,"Cairo",sans-serif;
}

/* شريط أعلى */
.nav{
  position:sticky; top:0; z-index:10;
  display:flex; justify-content:space-between; align-items:center;
  padding:12px 14px; gap:10px; flex-wrap:wrap;
  background:linear-gradient(#ffffffdd,#ffffffcc);
  backdrop-filter: blur(6px); border-bottom:1px solid var(--bd);
}
.nav .right{display:flex; gap:8px; flex-wrap:wrap; align-items:center}
.nav a{text-decoration:none}

/* Layout */
.main-wrap{
  min-height:calc(100% - 60px);
  display:grid;
  place-items:start center;
  padding:16px;
}
.box{
  width:min(900px,96vw);
  background:var(--card);
  border-radius:16px;
  border:1px solid var(--bd);
  box-shadow:0 10px 30px rgba(0,0,0,.06);
  padding:16px;
}

/* Cards grid */
.grid{
  margin-top:14px;
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
  gap:10px;
}
.card{
  border-radius:14px;
  border:1px solid var(--bd);
  padding:10px 12px;
  background:#fff;
  box-shadow:0 2px 8px rgba(0,0,0,.04);
  cursor:pointer;
  transition:.15s;
}
.card:hover{
  transform:translateY(-2px);
  box-shadow:0 8px 18px rgba(0,0,0,.08);
}
.card strong{font-size:15px}
.badge{
  font-size:11px;
  padding:2px 8px;
  border-radius:999px;
  background:var(--badge);
  color:#345;
}
.pill{
  display:inline-block;
  padding:4px 10px;
  border-radius:999px;
  background:#0b4ea910;
  border:1px solid #cfe2ff;
  color:#0b4ea9;
  font-size:12px;
  font-weight:600;
}

/* زر رجوع */
.btn{
  border:0;
  background:var(--pri);
  color:var(--pri-ink);
  padding:8px 12px;
  border-radius:10px;
  cursor:pointer;
  font-size:14px;
}
.btn.secondary{
  background:#eef3fb;
  color:#0b4ea9;
  box-shadow:none;
}

/* رسالة لا يوجد بيانات */
.empty{
  padding:20px;
  text-align:center;
  color:#666;
}
@media (max-width:520px){
  .box{padding:12px;border-radius:12px}
}
</style>
</head>
<body>

<nav class="nav">
  <div><strong>POS — اختيار القسم الفرعي الفرعي</strong></div>
  <div class="right">
    <span class="pill">التصنيف: <?= e($catName) ?></span>
    <span class="pill">الفرعي: <?= e($subName) ?></span>
    <a class="btn secondary" href="/3zbawyh/public/select_subcategory.php">← الرجوع للفرعي</a>
  </div>
</nav>

<div class="main-wrap">
  <div class="box">
    <h3 style="margin-top:0; margin-bottom:6px;">اختيار القسم الفرعي الفرعي</h3>
    <p style="margin:0;color:#666;font-size:13px">
      اختر القسم الفرعي الفرعي المناسب، ليتم فلترة الأصناف عليه.
    </p>

    <?php if (empty($subSubCats)): ?>
      <div class="empty">
        لا توجد أقسام فرعية فرعية مسجّلة لهذا الفرع.<br>
        يمكنك الرجوع واختيار فرع آخر أو إضافة Sub-Sub من لوحة الإدارة.
      </div>
    <?php else: ?>
      <form method="post" id="subsubForm">
        <input type="hidden" name="sub_subcategory_id" id="sub_subcategory_id" value="">
        <div class="grid" id="grid">
          <?php foreach ($subSubCats as $ssc): ?>
            <div class="card" data-id="<?= (int)$ssc['id'] ?>">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <strong><?= e($ssc['name']) ?></strong>

              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
// ربط الكروت بالفورم
document.querySelectorAll('.card[data-id]').forEach(card => {
  card.addEventListener('click', () => {
    const id = card.getAttribute('data-id');
    const inp = document.getElementById('sub_subcategory_id');
    inp.value = id;
    document.getElementById('subsubForm').submit();
  });
});
</script>
</body>
</html>
