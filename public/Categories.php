<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
require_role_in_or_redirect(['admin']);

$db = db();

/* Helpers (احتياط لو مش في helpers) */
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

/* Schema flags */
$hasCategories = table_exists($db,'categories');
$hasDesc   = $hasCategories ? column_exists($db,'categories','description') : false;
$hasActive = $hasCategories ? column_exists($db,'categories','is_active')   : false;
$hasSubcats = table_exists($db,'subcategories');

$msg=null; $err=null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* SQL لإنشاء جدول التصنيفات لو مش موجود */
if (!$hasCategories) {
  $createSQL = "CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
}

/* المعالجات */
try {
  if ($hasCategories) {
    if ($action==='create') {
      $fields=['name']; $vals=[trim($_POST['name'] ?? '')];
      if ($hasDesc)   { $fields[]='description'; $vals[] = ($_POST['description'] ?? null); }
      if ($hasActive) { $fields[]='is_active';   $vals[] = isset($_POST['is_active']) ? 1 : 0; }
      if ($vals[0]==='') throw new Exception('الاسم مطلوب');
      $placeholders = implode(',', array_fill(0,count($vals),'?'));
      $db->prepare("INSERT INTO categories (".implode(',',$fields).") VALUES ($placeholders)")->execute($vals);
      $msg='✅ تمت إضافة التصنيف بنجاح.';
    }
    elseif ($action==='update') {
      $id=(int)($_POST['id']??0); $name=trim($_POST['name']??'');
      if(!$id || $name==='') throw new Exception('بيانات غير مكتملة');
      $sets=['name=?']; $vals=[$name];
      if ($hasDesc)   { $sets[]='description=?'; $vals[] = ($_POST['description'] ?? null); }
      if ($hasActive) { $sets[]='is_active=?';   $vals[] = isset($_POST['is_active']) ? 1 : 0; }
      $vals[]=$id;
      $db->prepare("UPDATE categories SET ".implode(', ',$sets)." WHERE id=?")->execute($vals);
      $msg='✏️ تم تحديث التصنيف.';
    }
    elseif ($action==='delete') {
      $id=(int)($_POST['id']??0);
      if ($id) {
        if (table_exists($db,'items')) {
          $db->prepare("UPDATE items SET category_id=NULL WHERE category_id=?")->execute([$id]);
        }
        if ($hasSubcats) {
          $db->prepare("DELETE FROM subcategories WHERE category_id=?")->execute([$id]);
        }
        $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
        $msg='🗑️ تم حذف التصنيف.';
      }
    }
  }

  /* Subcategories CRUD */
  if ($hasSubcats) {
    if ($action==='sub_create') {
      $cid=(int)($_POST['category_id']??0);
      $name=trim($_POST['name']??'');
      $active=isset($_POST['is_active'])?1:0;
      if ($cid && $name!=='') {
        $db->prepare("INSERT INTO subcategories (category_id,name,is_active) VALUES (?,?,?)")
           ->execute([$cid,$name,$active]);
        $msg='✅ تمت إضافة التصنيف الفرعي.';
      } else throw new Exception('اسم الفرعي مطلوب');
    }
    elseif ($action==='sub_update') {
      $id=(int)($_POST['id']??0);
      $name=trim($_POST['name']??'');
      $active=isset($_POST['is_active'])?1:0;
      if ($id && $name!=='') {
        $db->prepare("UPDATE subcategories SET name=?, is_active=? WHERE id=?")
           ->execute([$name,$active,$id]);
        $msg='✏️ تم تحديث التصنيف الفرعي.';
      } else throw new Exception('بيانات الفرعي غير مكتملة');
    }
    elseif ($action==='sub_delete') {
      $id=(int)($_POST['id']??0);
      if ($id) {
        $db->prepare("DELETE FROM subcategories WHERE id=?")->execute([$id]);
        $msg='🗑️ تم حذف التصنيف الفرعي.';
      }
    }
  }
} catch(Throwable $e){ $err=$e->getMessage(); }

/* قراءة القوائم */
$q = trim($_GET['q'] ?? '');
$list=[];
if ($hasCategories) {
  $st=$db->prepare("SELECT * FROM categories WHERE (?='' OR name LIKE ?) ORDER BY name");
  $like="%$q%"; $st->execute([$q,$like]);
  $list=$st->fetchAll(PDO::FETCH_ASSOC);
}

/* تحرير تصنيف + فرعياته */
$editing=null; $subs=[]; $subq=trim($_GET['sq']??'');
if ($hasCategories && isset($_GET['edit'])) {
  $st=$db->prepare("SELECT * FROM categories WHERE id=?");
  $st->execute([(int)$_GET['edit']]); $editing=$st->fetch(PDO::FETCH_ASSOC);
  if ($editing && $hasSubcats) {
    if ($subq!=='') {
      $like="%$subq%";
      $st=$db->prepare("SELECT id,name,is_active FROM subcategories WHERE category_id=? AND name LIKE ? ORDER BY name");
      $st->execute([(int)$editing['id'],$like]);
    } else {
      $st=$db->prepare("SELECT id,name,is_active FROM subcategories WHERE category_id=? ORDER BY name");
      $st->execute([(int)$editing['id']]);
    }
    $subs=$st->fetchAll(PDO::FETCH_ASSOC);
  }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>إدارة التصنيفات</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
/* ——— واجهة واضحة ومقسّمة ——— */
:root{
  --ink:#0f172a; --muted:#64748b; --bd:#e2e8f0; --card:#ffffff;
  --ok:#16a34a; --warn:#b91c1c; --pri:#111827; --bg:#f8fafc;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font-family:system-ui}
.container{max-width:1180px;margin:24px auto;padding:0 14px}
.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.breadcrumb{color:var(--muted);font-size:14px}
.helper{font-size:13px;color:var(--muted)}
.layout{display:grid;grid-template-columns:380px 1fr;gap:16px}
@media (max-width: 1100px){ .layout{grid-template-columns:1fr} }
.card{background:var(--card);border:1px solid var(--bd);border-radius:14px;padding:14px}
.card h3{margin:0 0 6px}
.section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.step-title{font-weight:700}
.badge{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--bd);padding:4px 8px;border-radius:999px;font-size:12px;background:#f1f5f9}
.badge.ok{background:#ecfdf5;border-color:#bbf7d0}
.badge.off{background:#fee2e2;border-color:#fecaca}
.kv{display:grid;grid-template-columns:130px 1fr;gap:10px;align-items:center}
.kv .hint{grid-column:2/span 1;color:var(--muted);font-size:12px;margin-top:-6px}
.input, select, textarea{width:100%;padding:10px;border:1px solid var(--bd);border-radius:10px;background:#fff}
.input:focus{outline:none;border-color:#94a3b8;box-shadow:0 0 0 3px #e2e8f0}
.btn{background:#111;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
.btn.secondary{background:#f1f5f9;color:#111;border:1px solid var(--bd)}
.btn.min{padding:8px 10px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.divider{height:1px;background:var(--bd);margin:12px 0}
.table-wrap{overflow:auto}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table thead th{background:#f8fafc;border-bottom:1px solid var(--bd);text-align:right;padding:10px;font-size:13px;color:#0f172a}
.table tbody td{padding:10px;border-bottom:1px solid #eef2f7;vertical-align:middle}
.caption{font-size:12px;color:var(--muted);margin-bottom:8px}
.empty{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px dashed var(--bd);padding:12px;border-radius:12px;color:#475569}
.note{font-size:12px;color:var(--muted)}
.search-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.tooltip{color:#334155;border-bottom:1px dotted #94a3b8;cursor:help}
.small{font-size:12px;color:var(--muted)}
</style>
</head>
<body>
<div class="container">

  <!-- رأس الصفحة: ماذا تفعل هذه الصفحة؟ -->
  <div class="page-head">
    <div>
      <div class="breadcrumb">لوحة التحكم › المنتجات › <strong>التصنيفات</strong></div>
      <h2 style="margin:4px 0 6px">إدارة التصنيفات</h2>
      
    </div>
    <div class="actions">
      <a class="btn secondary" href="/3zbawyh/public/dashboard.php" title="رجوع للوحة التحكم">← رجوع</a>
    </div>
  </div>

  <?php if(!$hasCategories): ?>
    <div class="card" style="background:#fff7ed">
      <h3>⚠️ جدول التصنيفات غير موجود</h3>
      <p class="small">انسخ وشغّل SQL مرة واحدة لإنشاء الجدول:</p>
      <pre style="white-space:pre-wrap;direction:ltr"><?= $createSQL ?></pre>
    </div>
  <?php endif; ?>

  <?php if($msg): ?><div class="card" style="background:#ecfdf5;border:1px solid #bbf7d0"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="card" style="background:#fee2e2;border:1px solid #fecaca">❌ خطأ: <?= e($err) ?></div><?php endif; ?>

  <?php if($hasCategories): ?>
  <div class="layout">

    <!-- العمود الأيسر: خطوات واضحة -->
    <div class="stack" aria-label="خطوات إدارة التصنيفات">

      <!-- الخطوة (1): أضف/عدّل التصنيف الرئيسي -->
      <div class="card">
        <?php $isEdit = (bool)$editing; $activeVal = $isEdit && $hasActive ? (int)($editing['is_active'] ?? 1) : 1; ?>
        <div class="section-title">
          <div>
            <div class="step-title">الخطوة (1): <?= $isEdit? 'تعديل تصنيف رئيسي' : 'إضافة تصنيف رئيسي' ?></div>

          </div>
          <?php if($isEdit && $hasActive): ?>
            <span class="badge <?= $activeVal? 'ok':'off' ?>"><?= $activeVal? 'مفعل':'متوقف' ?></span>
          <?php endif; ?>
        </div>

        <form method="post" class="kv" aria-label="نموذج التصنيف الرئيسي">
          <input type="hidden" name="action" value="<?= $isEdit? 'update':'create' ?>">
          <?php if($isEdit): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

          <label for="cat_name">اسم التصنيف</label>
          <div>
            <input id="cat_name" class="input" name="name" required value="<?= e($editing['name'] ?? '') ?>" aria-describedby="cat_name_hint" placeholder="مثال: موبايلات">

          </div>

          <?php if ($hasDesc): ?>
            <label for="cat_desc">وصف (اختياري)</label>
            <div>
              <input id="cat_desc" class="input" name="description" value="<?= e($editing['description'] ?? '') ?>" placeholder="مثال: جميع الهواتف المحمولة">
              <div class="hint">يساعدك تميّز التصنيف عن غيره عند كثرة الفئات.</div>
            </div>
          <?php else: ?>
            <div></div><div class="small">لا يوجد عمود وصف في الجدول الحالي.</div>
          <?php endif; ?>

          <?php if ($hasActive): ?>
            <label>الحالة</label>
            <label style="display:flex; gap:8px; align-items:center">
              <input type="checkbox" name="is_active" <?= $activeVal? 'checked':''; ?>> مفعل

            </label>
          <?php else: ?>
            <div></div><div class="small">لا يوجد عمود حالة في الجدول الحالي.</div>
          <?php endif; ?>

          <div></div>
          <div class="actions">
            <button class="btn" type="submit"><?= $isEdit? 'حفظ التعديلات':'إضافة التصنيف' ?></button>
            <?php if($isEdit): ?><a class="btn secondary" href="?" title="إلغاء التعديل والعودة للوضع العادي">إلغاء</a><?php endif; ?>
          </div>
        </form>

        <?php if($isEdit): ?>
          <div class="divider"></div>
          <div class="small">رقم التصنيف: <b><?= (int)$editing['id'] ?></b> • أنشئ: <?= e($editing['created_at'] ?? '-') ?> • آخر تحديث: <?= e($editing['updated_at'] ?? '-') ?></div>
        <?php endif; ?>
      </div>

      <!-- الخطوة (2): إدارة التصنيفات الفرعية للتصنيف المحدد -->
      <?php if($editing && $hasSubcats): ?>
      <div class="card">
        <div class="section-title">
          <div>
            <div class="step-title">الخطوة (2): التصنيفات الفرعية لـ <b><?= e($editing['name']) ?></b></div>

          </div>
          <span class="badge"><?= count($subs) ?> فرعي</span>
        </div>

        <!-- إضافة فرعي -->
        <form method="post" class="search-row" style="margin-bottom:10px" aria-label="إضافة تصنيف فرعي">
          <input type="hidden" name="action" value="sub_create">
          <input type="hidden" name="category_id" value="<?= (int)$editing['id'] ?>">
          <input class="input" name="name" required placeholder="اسم الفرعي — مثال: سامسونج">
          <label class="small" style="display:flex;align-items:center;gap:6px">
            <input type="checkbox" name="is_active" checked> مفعل
          </label>
          <button class="btn min" type="submit">إضافة فرعي</button>
        </form>



        <!-- جدول الفرعيات -->
        <div class="caption">قائمة الفرعيات المرتبطة بالتصنيف الحالي.</div>
        <div class="table-wrap">
          <table class="table" aria-label="جدول التصنيفات الفرعية">
            <thead><tr><th style="width:70px">#</th><th>الاسم</th><th style="width:130px">الحالة</th><th style="width:260px">إجراءات</th></tr></thead>
            <tbody>
              <?php foreach($subs as $s): ?>
              <tr>
                <td><?= (int)$s['id'] ?></td>
                <td><?= e($s['name']) ?></td>
                <td>
                  <?php if((int)$s['is_active']): ?>
                    <span class="badge ok">مفعل</span>
                  <?php else: ?>
                    <span class="badge off">متوقف</span>
                  <?php endif; ?>
                </td>
                <td>
                  <details>
                    <summary class="btn min" title="تعديل هذا الفرعي">تعديل</summary>
                    <form method="post" class="search-row" style="margin-top:8px" aria-label="تعديل تصنيف فرعي">
                      <input type="hidden" name="action" value="sub_update">
                      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                      <input class="input" name="name" required value="<?= e($s['name']) ?>">
                      <label class="small" style="display:flex;align-items:center;gap:6px">
                        <input type="checkbox" name="is_active" <?= ((int)$s['is_active'])?'checked':''; ?>> مفعل
                      </label>
                      <button class="btn min" type="submit">حفظ</button>
                    </form>
                  </details>
                  <form method="post" style="display:inline" onsubmit="return confirm('هتحذف الفرعي «<?= e($s['name']) ?>»؟');" aria-label="حذف تصنيف فرعي">
                    <input type="hidden" name="action" value="sub_delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn secondary min" type="submit" title="حذف الفرعي">حذف</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($subs)): ?>
              <tr><td colspan="4">
                <div class="empty">لا توجد تصنيفات فرعية بعد. أضف أول فرعي من النموذج بالأعلى.</div>
              </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        
      </div>
      <?php endif; ?>

    </div>

    <!-- العمود الأيمن: البحث العام + القائمة -->
    <div class="stack" aria-label="بحث وقائمة كل التصنيفات">

      <div class="card">
        <div class="section-title">
          <h3>بحث سريع</h3>
          <span class="small">ابحث باسم التصنيف الرئيسي.</span>
        </div>
        <form method="get" class="search-row" aria-label="بحث في التصنيفات">
          <input name="q" value="<?= e($q) ?>" placeholder="مثال: أجهزة" class="input" style="flex:1">
          <button class="btn min">بحث</button>
          <?php if($q!==''): ?><a class="btn secondary min" href="?">مسح</a><?php endif; ?>
        </form>
      </div>

      <div class="card">
        <div class="section-title">
          <h3>كل التصنيفات</h3>
          <span class="badge"><?= count($list) ?> عنصر</span>
        </div>
       

        <div class="table-wrap">
          <table class="table" aria-label="جدول كل التصنيفات">
            <thead>
              <tr>
                <th style="width:70px">#</th>
                <th>الاسم</th>
                <?php if ($hasDesc): ?><th>الوصف</th><?php endif; ?>
                <?php if ($hasActive): ?><th style="width:130px">الحالة</th><?php endif; ?>
                <th style="width:240px">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($list as $c): ?>
              <tr>
                <td><?= (int)$c['id'] ?></td>
                <td><?= e($c['name']) ?></td>
                <?php if ($hasDesc): ?><td><?= e($c['description'] ?? '') ?></td><?php endif; ?>
                <?php if ($hasActive): ?>
                  <td>
                    <?php if((int)($c['is_active'] ?? 1)): ?>
                      <span class="badge ok">مفعل</span>
                    <?php else: ?>
                      <span class="badge off">متوقف</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td class="actions">
                  <a class="btn min" href="?edit=<?= (int)$c['id'] ?>" title="تعديل التصنيف وإدارة الفرعيات">تعديل</a>
                  <form method="post" onsubmit="return confirm('هتحذف التصنيف «<?= e($c['name']) ?>»؟ هيتم فك ارتباطه من الأصناف وحذف فرعياته.');" aria-label="حذف تصنيف">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button class="btn secondary min" type="submit">حذف</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($list)): ?>
              <tr><td colspan="<?= 3 + ($hasDesc?1:0) + ($hasActive?1:0) ?>">
                <div class="empty">لا توجد تصنيفات بعد. ابدأ من «الخطوة (1)» لإنشاء أول تصنيف.</div>
              </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        
  </div>
  <?php endif; ?>

  <div class="small" style="margin-top:10px;color:#94a3b8">واجهة مبسّطة • مفهومة • متناسقة مع الثيم</div>
</div>
</body>
</html>
