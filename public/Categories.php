<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
require_role_in_or_redirect(['admin','Manger']);

$db = db();

/* ==== Helpers ==== */
if (!function_exists('table_exists')) {
  function table_exists(PDO $db, $table){
    $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]); return (bool)$st->fetchColumn();
  }
}
if (!function_exists('column_exists')) {
  function column_exists(PDO $db, $table, $col){
    $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table,$col]); return (bool)$st->fetchColumn();
  }
}

/* ==== Schema flags ==== */
$hasCat         = table_exists($db,'categories');
$hasCatDesc     = $hasCat ? column_exists($db,'categories','description') : false;
$hasCatActive   = $hasCat ? column_exists($db,'categories','is_active')   : false;

$hasSub         = table_exists($db,'subcategories');
$hasSubActive   = $hasSub ? column_exists($db,'subcategories','is_active') : false;

$hasSubSub      = table_exists($db,'sub_subcategories');
$hasSubSubActive= $hasSubSub ? column_exists($db,'sub_subcategories','is_active') : false;

$hasItems       = table_exists($db,'items');
$hasItemCat     = $hasItems ? column_exists($db,'items','category_id')        : false;
$hasItemSub     = $hasItems ? column_exists($db,'items','subcategory_id')     : false;
$hasItemSubSub  = $hasItems ? column_exists($db,'items','sub_subcategory_id') : false;

$msg = null;
$err = null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* SQL لإنشاء جدول التصنيفات لو مش موجود */
if (!$hasCat) {
  $createCatSQL = "CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
}

/* ==== Actions ==== */
try {

  /* ---- Main categories ---- */
  if ($hasCat) {
    if ($action === 'cat_create') {
      $name = trim($_POST['name'] ?? '');
      if ($name === '') throw new Exception('اسم التصنيف مطلوب');

      $fields = ['name'];
      $vals   = [$name];

      if ($hasCatDesc) {
        $fields[]='description';
        $vals[] = ($_POST['description'] ?? null);
      }
      if ($hasCatActive) {
        $fields[]='is_active';
        $vals[] = isset($_POST['is_active']) ? 1 : 0;
      }

      $placeholders = implode(',', array_fill(0,count($vals),'?'));
      $sql = "INSERT INTO categories (".implode(',',$fields).") VALUES ($placeholders)";
      $db->prepare($sql)->execute($vals);

      $msg = '✅ تم إضافة التصنيف بنجاح.';
    }

    elseif ($action === 'cat_update') {
      $id   = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if (!$id || $name === '') throw new Exception('بيانات التصنيف غير مكتملة');

      $sets = ['name=?']; $vals = [$name];

      if ($hasCatDesc) {
        $sets[]='description=?'; $vals[] = ($_POST['description'] ?? null);
      }
      if ($hasCatActive) {
        $sets[]='is_active=?';   $vals[] = isset($_POST['is_active']) ? 1 : 0;
      }
      $vals[] = $id;

      $sql = "UPDATE categories SET ".implode(', ',$sets)." WHERE id=?";
      $db->prepare($sql)->execute($vals);

      $msg = '✏️ تم تحديث التصنيف.';
    }

    elseif ($action === 'cat_delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id) {
        // فك ارتباط الأصناف
        if ($hasItems) {
          if ($hasItemSubSub && $hasSubSub && $hasSub) {
            $st = $db->prepare("
              SELECT ss.id
              FROM sub_subcategories ss
              JOIN subcategories s ON s.id = ss.subcategory_id
              WHERE s.category_id = ?
            ");
            $st->execute([$id]);
            $subSubIds = $st->fetchAll(PDO::FETCH_COLUMN);
            if ($subSubIds) {
              $in = implode(',', array_fill(0,count($subSubIds),'?'));
              $db->prepare("UPDATE items SET sub_subcategory_id=NULL WHERE sub_subcategory_id IN ($in)")
                 ->execute($subSubIds);
            }
          }
          if ($hasItemSub && $hasSub) {
            $db->prepare("UPDATE items SET subcategory_id=NULL WHERE category_id=?")->execute([$id]);
          }
          if ($hasItemCat) {
            $db->prepare("UPDATE items SET category_id=NULL WHERE category_id=?")->execute([$id]);
          }
        }

        // حذف sub_sub ثم sub
        if ($hasSubSub && $hasSub) {
          $stS = $db->prepare("SELECT id FROM subcategories WHERE category_id=?");
          $stS->execute([$id]);
          $subIds = $stS->fetchAll(PDO::FETCH_COLUMN);
          if ($subIds) {
            $in = implode(',', array_fill(0,count($subIds),'?'));
            $db->prepare("DELETE FROM sub_subcategories WHERE subcategory_id IN ($in)")
               ->execute($subIds);
          }
        }
        if ($hasSub) {
          $db->prepare("DELETE FROM subcategories WHERE category_id=?")->execute([$id]);
        }

        $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
        $msg = '🗑️ تم حذف التصنيف وكل ما تحته.';
      }
    }
  }

  /* ---- Subcategories ---- */
  if ($hasCat && $hasSub) {
    if ($action === 'sub_create') {
      $cid  = (int)($_POST['category_id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if (!$cid || $name === '') throw new Exception('اسم الفرعي مطلوب');

      $fields = ['category_id','name'];
      $vals   = [$cid,$name];
      if ($hasSubActive) { $fields[]='is_active'; $vals[] = isset($_POST['is_active'])?1:0; }

      $placeholders = implode(',', array_fill(0,count($vals),'?'));
      $sql = "INSERT INTO subcategories (".implode(',',$fields).") VALUES ($placeholders)";
      $db->prepare($sql)->execute($vals);

      $msg = '✅ تم إضافة التصنيف الفرعي.';
    }

    elseif ($action === 'sub_update') {
      $id   = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if (!$id || $name === '') throw new Exception('بيانات الفرعي غير مكتملة');

      $sets=['name=?']; $vals=[$name];
      if ($hasSubActive) {
        $sets[]='is_active=?'; $vals[] = isset($_POST['is_active'])?1:0;
      }
      $vals[] = $id;

      $db->prepare("UPDATE subcategories SET ".implode(', ',$sets)." WHERE id=?")->execute($vals);
      $msg = '✏️ تم تحديث التصنيف الفرعي.';
    }

    elseif ($action === 'sub_delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id) {
        // فك ارتباط الأصناف من sub-sub
        if ($hasSubSub && $hasItemSubSub) {
          $st = $db->prepare("SELECT id FROM sub_subcategories WHERE subcategory_id=?");
          $st->execute([$id]);
          $subSubIds = $st->fetchAll(PDO::FETCH_COLUMN);
          if ($subSubIds) {
            $in = implode(',', array_fill(0,count($subSubIds),'?'));
            $db->prepare("UPDATE items SET sub_subcategory_id=NULL WHERE sub_subcategory_id IN ($in)")
               ->execute($subSubIds);
          }
        }
        // فك الأصناف من الفرعي
        if ($hasItems && $hasItemSub) {
          $db->prepare("UPDATE items SET subcategory_id=NULL WHERE subcategory_id=?")->execute([$id]);
        }
        // حذف sub_sub ثم الفرعي
        if ($hasSubSub) {
          $db->prepare("DELETE FROM sub_subcategories WHERE subcategory_id=?")->execute([$id]);
        }
        $db->prepare("DELETE FROM subcategories WHERE id=?")->execute([$id]);
        $msg = '🗑️ تم حذف التصنيف الفرعي وكل ما تحته.';
      }
    }
  }

  /* ---- Sub-Subcategories ---- */
  if ($hasSubSub && $hasSub) {
    if ($action === 'subsub_create') {
      $sid  = (int)($_POST['subcategory_id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if (!$sid || $name === '') throw new Exception('اسم الفرعي الفرعي مطلوب');

      $fields=['subcategory_id','name']; $vals=[$sid,$name];
      if ($hasSubSubActive) { $fields[]='is_active'; $vals[] = isset($_POST['is_active'])?1:0; }

      $placeholders = implode(',', array_fill(0,count($vals),'?'));
      $sql = "INSERT INTO sub_subcategories (".implode(',',$fields).") VALUES ($placeholders)";
      $db->prepare($sql)->execute($vals);

      $msg = '✅ تم إضافة التصنيف الفرعي الفرعي.';
    }

    elseif ($action === 'subsub_update') {
      $id   = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if (!$id || $name === '') throw new Exception('بيانات الفرعي الفرعي غير مكتملة');

      $sets=['name=?']; $vals=[$name];
      if ($hasSubSubActive) {
        $sets[]='is_active=?'; $vals[] = isset($_POST['is_active'])?1:0;
      }
      $vals[] = $id;

      $db->prepare("UPDATE sub_subcategories SET ".implode(', ',$sets)." WHERE id=?")->execute($vals);
      $msg = '✏️ تم تحديث التصنيف الفرعي الفرعي.';
    }

    elseif ($action === 'subsub_delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id) {
        if ($hasItems && $hasItemSubSub) {
          $db->prepare("UPDATE items SET sub_subcategory_id=NULL WHERE sub_subcategory_id=?")->execute([$id]);
        }
        $db->prepare("DELETE FROM sub_subcategories WHERE id=?")->execute([$id]);
        $msg = '🗑️ تم حذف التصنيف الفرعي الفرعي.';
      }
    }
  }

} catch (Throwable $e) {
  $err = $e->getMessage();
}

/* ==== Lists ==== */
$q = trim($_GET['q'] ?? '');
$categories = [];
if ($hasCat) {
  $st = $db->prepare("SELECT * FROM categories WHERE (?='' OR name LIKE ?) ORDER BY name");
  $like = "%$q%";
  $st->execute([$q,$like]);
  $categories = $st->fetchAll(PDO::FETCH_ASSOC);
}

$editing = null;
$subs    = [];
$subSubs = []; // sub_id => array of sub_sub

if ($hasCat && isset($_GET['edit'])) {
  $cid = (int)$_GET['edit'];
  $st  = $db->prepare("SELECT * FROM categories WHERE id=?");
  $st->execute([$cid]);
  $editing = $st->fetch(PDO::FETCH_ASSOC);

  if ($editing && $hasSub) {
    $st2 = $db->prepare("SELECT id,name,".($hasSubActive?'is_active':'1 AS is_active')." FROM subcategories WHERE category_id=? ORDER BY name");
    $st2->execute([$cid]);
    $subs = $st2->fetchAll(PDO::FETCH_ASSOC);

    if ($hasSubSub) {
      $st3 = $db->prepare("
        SELECT id,name,".($hasSubSubActive?'is_active':'1 AS is_active').",subcategory_id
        FROM sub_subcategories
        WHERE subcategory_id IN (SELECT id FROM subcategories WHERE category_id=?)
        ORDER BY name
      ");
      $st3->execute([$cid]);
      foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $ss) {
        $sid = (int)$ss['subcategory_id'];
        if (!isset($subSubs[$sid])) $subSubs[$sid] = [];
        $subSubs[$sid][] = $ss;
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>إدارة التصنيفات</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
:root{
  --bg:#f8fafc;
  --card:#ffffff;
  --ink:#0f172a;
  --muted:#64748b;
  --bd:#e2e8f0;
  --pri:#2563eb;
  --pri-soft:#eff6ff;
  --ok:#16a34a;
  --danger:#b91c1c;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  background:var(--bg);
  font-family:system-ui,-apple-system,"Noto Kufi Arabic","Cairo",sans-serif;
  color:var(--ink);
  line-height:1.55;
}
a{text-decoration:none;color:inherit}

.container{max-width:1200px;margin:20px auto;padding:0 14px}
.page-head{
  display:flex;justify-content:space-between;align-items:center;
  gap:10px;flex-wrap:wrap;margin-bottom:12px;
}
.breadcrumb{font-size:13px;color:var(--muted)}
.page-head h1{margin:4px 0 0;font-size:20px}

/* Layout */
.layout{
  display:grid;
  grid-template-columns: minmax(0,360px) minmax(0,1fr);
  gap:16px;
}
@media(max-width:980px){
  .layout{grid-template-columns:1fr}
}

/* Card */
.card{
  background:var(--card);
  border-radius:14px;
  border:1px solid var(--bd);
  padding:14px;
  box-shadow:0 8px 24px rgba(15,23,42,.04);
}
.card-header{
  display:flex;justify-content:space-between;align-items:center;
  gap:8px;flex-wrap:wrap;margin-bottom:8px;
}
.card-header h2,
.card-header h3{
  margin:0;font-size:15px;
}

/* Inputs */
.input,select,textarea{
  width:100%;
  padding:9px 10px;
  border-radius:10px;
  border:1px solid var(--bd);
  background:#fff;
  font-size:14px;
}
.input:focus,select:focus,textarea:focus{
  outline:none;
  border-color:#93c5fd;
  box-shadow:0 0 0 2px #dbeafe;
}

/* Buttons */
.btn{
  border:none;
  border-radius:10px;
  padding:9px 13px;
  font-size:13px;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  gap:6px;
}
.btn-primary{
  background:var(--pri);
  color:#fff;
}
.btn-secondary{
  background:#f1f5f9;
  color:#111827;
  border:1px solid var(--bd);
}
.btn-danger{
  background:var(--danger);
  color:#fff;
}
.btn-sm{padding:7px 10px;font-size:12px}

/* Badges */
.badge{
  display:inline-flex;
  align-items:center;
  padding:2px 8px;
  border-radius:999px;
  font-size:11px;
  border:1px solid var(--bd);
  background:#f1f5f9;
  color:#0f172a;
}
.badge-ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
.badge-off{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.badge-soft{background:var(--pri-soft);border-color:#bfdbfe;color:#1d4ed8}

/* Form grid */
.form-grid{
  display:grid;
  grid-template-columns:1fr;
  gap:8px;
}
.form-row{
  display:flex;
  flex-direction:column;
  gap:4px;
  font-size:13px;
}
.form-row label{font-weight:600;color:#0f172a}
.form-row small{color:var(--muted);font-size:11px}

/* Sub list */
.sub-list{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin-top:8px;
}
.sub-item{
  border-radius:10px;
  border:1px solid var(--bd);
  padding:8px 10px;
  background:#f9fafb;
}
.sub-item-header{
  display:flex;justify-content:space-between;align-items:center;
  gap:8px;flex-wrap:wrap;
}
.sub-item-title{font-size:13px;font-weight:600}

/* Collapsible details */
details{
  margin-top:6px;
}
details summary{
  list-style:none;
  cursor:pointer;
  font-size:12px;
  color:var(--muted);
}
details summary::-webkit-details-marker{display:none}
details summary::before{
  content:"▸";
  display:inline-block;
  margin-left:4px;
  font-size:11px;
}
details[open] summary::before{content:"▾"}

/* Table for categories list */
.table-wrap{overflow:auto;-webkit-overflow-scrolling:touch;margin-top:6px}
table{width:100%;border-collapse:collapse;font-size:13px}
thead th{
  text-align:right;
  padding:8px 6px;
  border-bottom:1px solid var(--bd);
  background:#f8fafc;
  white-space:nowrap;
}
tbody td{
  padding:8px 6px;
  border-bottom:1px solid #e5e7eb;
  white-space:nowrap;
}
tbody tr:last-child td{border-bottom:none}

/* Misc */
.tag-line{font-size:12px;color:var(--muted);margin-top:4px}
.alert-ok{background:#ecfdf5;border:1px solid #bbf7d0;border-radius:12px;padding:10px;margin-bottom:10px;font-size:13px}
.alert-err{background:#fee2e2;border:1px solid #fecaca;border-radius:12px;padding:10px;margin-bottom:10px;font-size:13px}
.empty{
  border-radius:10px;
  border:1px dashed var(--bd);
  padding:10px;
  font-size:12px;
  color:var(--muted);
  margin-top:6px;
}
.actions-row{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.search-row{
  display:flex;gap:8px;flex-wrap:wrap;align-items:center;
}
.search-row .input{min-width:200px;flex:1}
</style>
<link rel="stylesheet" href="/3zbawyh/assets/barcode_theme.css">
</head>
<body>
<div class="container">

  <div class="page-head">
    <div>
      <div class="breadcrumb">لوحة التحكم › المنتجات › التصنيفات</div>
      <h1>إدارة شجرة التصنيفات</h1>
      <div class="tag-line">تحكم في التصنيف الرئيسي، الفرعي، والفرعي الفرعي من شاشة واحدة.</div>
    </div>
    <div class="actions-row">
      <a class="btn btn-secondary" href="/3zbawyh/public/dashboard.php">← رجوع للوحة التحكم</a>
    </div>
  </div>

  <?php if(!$hasCat): ?>
    <div class="alert-err">
      جدول <code>categories</code> غير موجود.<br>
      شغّل الـ SQL التالي مرة واحدة:
      <pre style="white-space:pre-wrap;direction:ltr;margin-top:6px;font-size:11px"><?= $createCatSQL ?? '' ?></pre>
    </div>
  <?php endif; ?>

  <?php if($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert-err">❌ <?= e($err) ?></div><?php endif; ?>

  <?php if($hasCat): ?>
  <div class="layout">

    <!-- LEFT: Forms -->
    <div>
      <!-- Main category form -->
      <div class="card">
        <?php
          $isEdit = (bool)$editing;
          $catActive = $isEdit && $hasCatActive ? (int)($editing['is_active'] ?? 1) : 1;
        ?>
        <div class="card-header">
          <h2><?= $isEdit ? 'تعديل تصنيف رئيسي' : 'إضافة تصنيف رئيسي' ?></h2>
          <?php if($isEdit): ?>
            <?php if($hasCatActive): ?>
              <span class="badge <?= $catActive? 'badge-ok':'badge-off' ?>"><?= $catActive ? 'مفعّل' : 'متوقف' ?></span>
            <?php else: ?>
              <span class="badge badge-soft">ID: <?= (int)$editing['id'] ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <form method="post" class="form-grid">
          <input type="hidden" name="action" value="<?= $isEdit ? 'cat_update':'cat_create' ?>">
          <?php if($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
          <?php endif; ?>

          <div class="form-row">
            <label for="cat_name">اسم التصنيف</label>
            <input id="cat_name" class="input" name="name" required
                   value="<?= e($editing['name'] ?? '') ?>"
                   placeholder="مثال: موبايلات، لابتوبات، قطع غيار">
          </div>

          <div class="form-row">
            <label for="cat_desc">وصف (اختياري)</label>
            <?php if($hasCatDesc): ?>
              <input id="cat_desc" class="input" name="description"
                     value="<?= e($editing['description'] ?? '') ?>"
                     placeholder="وصف مختصر يساعدك تفتكر التصنيف">
            <?php else: ?>
              <input id="cat_desc" class="input" disabled value="لا يوجد عمود وصف في الجدول.">
            <?php endif; ?>
          </div>

          <div class="form-row">
            <label>الحالة</label>
            <?php if($hasCatActive): ?>
              <label style="display:flex;align-items:center;gap:6px;font-size:12px">
                <input type="checkbox" name="is_active" <?= $catActive ? 'checked' : '' ?>> مفعّل
              </label>
            <?php else: ?>
              <small>لا يوجد عمود حالة في جدول التصنيفات.</small>
            <?php endif; ?>
          </div>

          <div class="actions-row" style="margin-top:4px">
            <button class="btn btn-primary" type="submit">
              <?= $isEdit ? 'حفظ التعديلات' : 'إضافة التصنيف' ?>
            </button>
            <?php if($isEdit): ?>
              <a class="btn btn-secondary" href="?">إلغاء التعديل</a>
            <?php endif; ?>
          </div>

        </form>
      </div>

      <!-- Sub + Sub-Sub manager for selected category -->
      <?php if($isEdit && $hasSub): ?>
      <div class="card" style="margin-top:14px">
        <div class="card-header">
          <h3>التصنيفات الفرعية لـ «<?= e($editing['name']) ?>»</h3>
          <span class="badge badge-soft"><?= count($subs) ?> فرعي</span>
        </div>

        <!-- add subcategory -->
        <form method="post" class="search-row" style="margin-bottom:8px">
          <input type="hidden" name="action" value="sub_create">
          <input type="hidden" name="category_id" value="<?= (int)$editing['id'] ?>">
          <input class="input" name="name" required placeholder="اسم الفرعي — مثال: سامسونج، شاومي">
          <?php if($hasSubActive): ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:11px">
              <input type="checkbox" name="is_active" checked> مفعّل
            </label>
          <?php endif; ?>
          <button class="btn btn-primary btn-sm" type="submit">إضافة فرعي</button>
        </form>

        <?php if(empty($subs)): ?>
          <div class="empty">لا توجد تصنيفات فرعية بعد. أضف أول فرعي من النموذج بالأعلى.</div>
        <?php else: ?>
          <div class="sub-list">
            <?php foreach($subs as $s): ?>
              <?php
                $sid   = (int)$s['id'];
                $sname = $s['name'];
                $sAct  = (int)($s['is_active'] ?? 1);
                $mySubSubs = $subSubs[$sid] ?? [];
              ?>
              <div class="sub-item">
                <div class="sub-item-header">
                  <div class="sub-item-title"><?= e($sname) ?></div>
                  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <?php if($hasSubActive): ?>
                      <span class="badge <?= $sAct ? 'badge-ok':'badge-off' ?>"><?= $sAct?'مفعل':'متوقف' ?></span>
                    <?php endif; ?>
                    <span class="badge badge-soft">#<?= $sid ?></span>
                  </div>
                </div>

                <!-- edit / delete subcategory -->
                <div class="actions-row">
                  <form method="post" style="display:flex;gap:6px;flex-wrap:wrap">
                    <input type="hidden" name="action" value="sub_update">
                    <input type="hidden" name="id" value="<?= $sid ?>">
                    <input class="input" name="name" required style="max-width:220px"
                           value="<?= e($sname) ?>">
                    <?php if($hasSubActive): ?>
                      <label style="display:flex;align-items:center;gap:4px;font-size:11px">
                        <input type="checkbox" name="is_active" <?= $sAct?'checked':''; ?>> مفعّل
                      </label>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-sm" type="submit">حفظ الفرعي</button>
                  </form>

                  <form method="post"
                        onsubmit="return confirm('حذف التصنيف الفرعي «<?= e($sname) ?>» وكل الفرعي الفرعي تحته؟');">
                    <input type="hidden" name="action" value="sub_delete">
                    <input type="hidden" name="id" value="<?= $sid ?>">
                    <button class="btn btn-secondary btn-sm" type="submit">حذف الفرعي</button>
                  </form>
                </div>

                <!-- sub-sub manager -->
                <?php if($hasSubSub): ?>
                  <details>
                    <summary>إدارة التصنيفات الفرعية الفرعية (Sub-Sub) لهذا الفرع</summary>
                    <div style="margin-top:6px">

                      <!-- add sub-sub -->
                      <form method="post" class="search-row" style="margin-bottom:6px">
                        <input type="hidden" name="action" value="subsub_create">
                        <input type="hidden" name="subcategory_id" value="<?= $sid ?>">
                        <input class="input" name="name" required placeholder="اسم الفرعي الفرعي — مثال: S23 Ultra 256G">
                        <?php if($hasSubSubActive): ?>
                          <label style="display:flex;align-items:center;gap:4px;font-size:11px">
                            <input type="checkbox" name="is_active" checked> مفعّل
                          </label>
                        <?php endif; ?>
                        <button class="btn btn-primary btn-sm" type="submit">إضافة فرعي فرعي</button>
                      </form>

                      <?php if(empty($mySubSubs)): ?>
                        <div class="empty">لا توجد تصنيفات فرعية فرعية لهذا الفرع بعد.</div>
                      <?php else: ?>
                        <?php foreach($mySubSubs as $ss): ?>
                          <?php
                            $ssid  = (int)$ss['id'];
                            $ssnm  = $ss['name'];
                            $ssAct = (int)($ss['is_active'] ?? 1);
                          ?>
                          <div style="border-radius:9px;border:1px solid var(--bd);padding:6px 8px;margin-top:4px;background:#fff">
                            <div class="sub-item-header" style="margin-bottom:4px">
                              <div style="font-size:12px;font-weight:600"><?= e($ssnm) ?></div>
                              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <?php if($hasSubSubActive): ?>
                                  <span class="badge <?= $ssAct?'badge-ok':'badge-off' ?>"><?= $ssAct?'مفعل':'متوقف' ?></span>
                                <?php endif; ?>
                                <span class="badge badge-soft">#<?= $ssid ?></span>
                              </div>
                            </div>

                            <form method="post" class="actions-row" style="margin:0">
                              <input type="hidden" name="action" value="subsub_update">
                              <input type="hidden" name="id" value="<?= $ssid ?>">
                              <input class="input" name="name" required style="max-width:220px"
                                     value="<?= e($ssnm) ?>">
                              <?php if($hasSubSubActive): ?>
                                <label style="display:flex;align-items:center;gap:4px;font-size:11px">
                                  <input type="checkbox" name="is_active" <?= $ssAct?'checked':''; ?>> مفعّل
                                </label>
                              <?php endif; ?>
                              <button class="btn btn-primary btn-sm" type="submit">حفظ</button>
                            </form>

                            <form method="post" style="margin-top:4px"
                                  onsubmit="return confirm('حذف الفرعي الفرعي «<?= e($ssnm) ?>»؟ سيتم فك ارتباطه من الأصناف.');">
                              <input type="hidden" name="action" value="subsub_delete">
                              <input type="hidden" name="id" value="<?= $ssid ?>">
                              <button class="btn btn-secondary btn-sm" type="submit">حذف</button>
                            </form>
                          </div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </details>
                <?php endif; ?>

              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div>
      <?php elseif(!$isEdit): ?>
        <div class="card" style="margin-top:14px">
          <div class="card-header">
            <h3>ملاحظات سريعة</h3>
          </div>
          <ul style="margin:0 0 0 18px;padding:0;font-size:13px;color:var(--muted)">
            <li>ابدأ بإضافة تصنيف رئيسي (مثال: موبايلات).</li>
            <li>من قائمة «كل التصنيفات» على اليمين، اضغط «إدارة» عشان تضيف الفرعي و الفرعي الفرعي.</li>
            <li>الحذف يفك ارتباط الأصناف ثم يحذف التصنيف وما تحته بأمان.</li>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: list + search -->
    <div>
      <div class="card">
        <div class="card-header">
          <h2>بحث في التصنيفات</h2>
        </div>
        <form method="get" class="search-row">
          <input class="input" name="q" value="<?= e($q) ?>" placeholder="ابحث باسم التصنيف الرئيسي">
          <button class="btn btn-primary btn-sm" type="submit">بحث</button>
          <?php if($q!==''): ?>
            <a class="btn btn-secondary btn-sm" href="?">مسح</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="card" style="margin-top:14px">
        <div class="card-header">
          <h2>كل التصنيفات الرئيسية</h2>
          <span class="badge badge-soft"><?= count($categories) ?> تصنيف</span>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:60px">#</th>
                <th>الاسم</th>
                <?php if($hasCatDesc): ?><th>الوصف</th><?php endif; ?>
                <?php if($hasCatActive): ?><th style="width:90px">الحالة</th><?php endif; ?>
                <th style="width:190px">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($categories as $c): ?>
                <tr>
                  <td><?= (int)$c['id'] ?></td>
                  <td><?= e($c['name']) ?></td>
                  <?php if($hasCatDesc): ?>
                    <td style="max-width:260px;white-space:normal"><?= e($c['description'] ?? '') ?></td>
                  <?php endif; ?>
                  <?php if($hasCatActive): ?>
                    <td>
                      <?php if((int)($c['is_active'] ?? 1)): ?>
                        <span class="badge badge-ok">مفعّل</span>
                      <?php else: ?>
                        <span class="badge badge-off">متوقف</span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td>
                    <div class="actions-row">
                      <a class="btn btn-primary btn-sm" href="?edit=<?= (int)$c['id'] ?>">إدارة</a>
                      <form method="post"
                            onsubmit="return confirm('حذف التصنيف «<?= e($c['name']) ?>» وكل الفرعي والفرعي الفرعي تحته؟');">
                        <input type="hidden" name="action" value="cat_delete">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-secondary btn-sm" type="submit">حذف</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if(empty($categories)): ?>
                <tr><td colspan="<?= 3 + ($hasCatDesc?1:0) + ($hasCatActive?1:0) ?>">
                  <div class="empty">لا توجد تصنيفات بعد. أضف أول تصنيف من النموذج على اليسار.</div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
