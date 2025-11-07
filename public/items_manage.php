<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin']);
$db = db();

/** Helpers */
function has_col(PDO $db,$t,$c){
  $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}

/** مسارات الرفع */
$UPLOAD_DIR = realpath(__DIR__ . '/../') . '/uploads/items';   // مسار فعلي على السيرفر
$UPLOAD_URL = '/3zbawyh/uploads/items/';                       // مسار عام للعرض

// تأكد من وجود مجلد الرفع
if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0775, true); }

// دالة رفع الصورة: تعيد [ok, url|null, error|null]
function save_uploaded_image(array $file, string $uploadDir, string $uploadUrl): array {
  if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    return ['ok'=>false, 'url'=>null, 'error'=>'لم يتم اختيار ملف'];
  }

  // تحقق الحجم <= 3MB
  if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
    return ['ok'=>false, 'url'=>null, 'error'=>'حجم الصورة يتجاوز 3MB'];
  }

  // تحقق النوع عبر MIME
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);

  $allowed = [
    'image/jpeg' => '.jpg',
    'image/png'  => '.png',
    'image/webp' => '.webp',
    'image/gif'  => '.gif',
  ];
  if (!isset($allowed[$mime])) {
    return ['ok'=>false, 'url'=>null, 'error'=>'نوع الملف غير مدعوم (يُقبل JPG/PNG/WEBP/GIF)'];
  }

  // اسم آمن وفريد
  $name = bin2hex(random_bytes(8)) . $allowed[$mime];
  $dest = rtrim($uploadDir,'/\\') . DIRECTORY_SEPARATOR . $name;

  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    return ['ok'=>false, 'url'=>null, 'error'=>'تعذّر حفظ الملف'];
  }

  // صلاحيات ودية
  @chmod($dest, 0644);

  return ['ok'=>true, 'url'=> rtrim($uploadUrl,'/').'/'.$name, 'error'=>null];
}

/** Schema detection */
$hasItems       = table_exists($db,'items');
$hasCategories  = table_exists($db,'categories');
$hasSubcatsTbl  = table_exists($db,'subcategories');
if(!$hasItems){ die('جدول items غير موجود.'); }

$hasSKU         = has_col($db,'items','sku');
$hasPrice       = has_col($db,'items','unit_price');
$hasReorder     = has_col($db,'items','reorder_level');
$hasStock       = has_col($db,'items','stock');
$hasCatId       = has_col($db,'items','category_id') && $hasCategories;
$hasSubcatId    = has_col($db,'items','subcategory_id') && $hasSubcatsTbl;
$hasImage       = has_col($db,'items','image_url'); // عمود الصورة

/** AJAX: جلب التصنيفات الفرعية حسب التصنيف */
if(($_GET['ajax'] ?? '')==='subcats' && $hasSubcatsTbl){
  header('Content-Type: application/json; charset=utf-8');
  $cid = (int)($_GET['category_id'] ?? 0);
  if($cid>0){
    $rows = $db->prepare("SELECT id,name FROM subcategories WHERE category_id=? AND (is_active=1 OR is_active IS NULL) ORDER BY name");
    $rows->execute([$cid]);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
  } else {
    echo json_encode([]);
  }
  exit;
}

/** Load categories / subcats for filters & forms */
$cats = [];
if($hasCatId){
  $cats = $db->query("SELECT id,name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

/** Actions (CRUD) */
$msg=null; $err=null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try{
  if($action==='create'){
    // رفع صورة (اختياري)
    $newImageUrl = null;
    if ($hasImage && isset($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $up = save_uploaded_image($_FILES['image_file'], $UPLOAD_DIR, $UPLOAD_URL);
      if ($up['ok']) { $newImageUrl = $up['url']; }
      else { throw new RuntimeException($up['error']); }
    }

    $fields=['name']; $vals=[trim($_POST['name'])]; $qs=['?'];

    if($hasSKU){     $fields[]='sku';           $vals[] = trim($_POST['sku'] ?? '') ?: null; $qs[]='?'; }
    if($hasPrice){   $fields[]='unit_price';    $vals[] = (trim($_POST['unit_price'] ?? '')!=='')? trim($_POST['unit_price']) : null; $qs[]='?'; }
    if($hasReorder){ $fields[]='reorder_level'; $vals[] = (int)($_POST['reorder_level'] ?? 0); $qs[]='?'; }
    if($hasStock){   $fields[]='stock';         $vals[] = (int)($_POST['stock'] ?? 0); $qs[]='?'; }
    if($hasImage){   $fields[]='image_url';     $vals[] = $newImageUrl; $qs[]='?'; }

    $catId = null; $subId = null;
    if($hasCatId){ $catId = ($_POST['category_id']!=='')? (int)$_POST['category_id'] : null; $fields[]='category_id'; $vals[]=$catId; $qs[]='?'; }
    if($hasSubcatId){
      $tmpSub = ($_POST['subcategory_id']!=='')? (int)$_POST['subcategory_id'] : null;
      if($tmpSub && $catId){
        $ok=$db->prepare("SELECT 1 FROM subcategories WHERE id=? AND category_id=?");
        $ok->execute([$tmpSub,$catId]);
        if($ok->fetchColumn()){ $subId=$tmpSub; }
      }
      $fields[]='subcategory_id'; $vals[]=$subId; $qs[]='?';
    }

    $sql="INSERT INTO items (".implode(',',$fields).") VALUES (".implode(',',$qs).")";
    $db->prepare($sql)->execute($vals); $msg='تمت الإضافة.';
  }
  elseif($action==='update'){
    $id=(int)$_POST['id'];

    // ارفع صورة جديدة لو تم اختيار ملف
    $newImageUrl = null;
    if ($hasImage && isset($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $up = save_uploaded_image($_FILES['image_file'], $UPLOAD_DIR, $UPLOAD_URL);
      if ($up['ok']) { $newImageUrl = $up['url']; }
      else { throw new RuntimeException($up['error']); }
    }

    // احضر القديمة (لو محتاج نحذف)
    $old = null;
    if($hasImage){
      $stOld = $db->prepare("SELECT image_url FROM items WHERE id=?");
      $stOld->execute([$id]);
      $old = $stOld->fetchColumn();
    }

    $sets=['name=?']; $vals=[trim($_POST['name'])];

    if($hasSKU){     $sets[]='sku=?';           $vals[] = trim($_POST['sku'] ?? '') ?: null; }
    if($hasPrice){   $sets[]='unit_price=?';    $vals[] = (trim($_POST['unit_price'] ?? '')!=='')? trim($_POST['unit_price']) : null; }
    if($hasReorder){ $sets[]='reorder_level=?'; $vals[] = (int)($_POST['reorder_level'] ?? 0); }
    if($hasStock){   $sets[]='stock=?';         $vals[] = (int)($_POST['stock'] ?? 0); }

    // منطق الصورة:
    // - لو المختار "حذف الصورة" نضع NULL ونحذف الملف إن وُجد.
    // - وإلا لو فيه رفع جديد نكتب الجديد ونحذف القديم إن وُجد.
    // - وإلا نترك القديم كما هو.
    if($hasImage){
      $remove = isset($_POST['remove_image']) && $_POST['remove_image']=='1';
      if ($remove) {
        $sets[]='image_url=?'; $vals[] = null;
        if ($old && str_starts_with($old, $UPLOAD_URL)) {
          $full = $UPLOAD_DIR . '/' . basename($old);
          @unlink($full);
        }
      } elseif ($newImageUrl) {
        $sets[]='image_url=?'; $vals[] = $newImageUrl;
        if ($old && str_starts_with($old, $UPLOAD_URL)) {
          $full = $UPLOAD_DIR . '/' . basename($old);
          @unlink($full);
        }
      }
    }

    $catId = null; $subId = null;
    if($hasCatId){ $sets[]='category_id=?'; $catId = ($_POST['category_id']!=='')? (int)$_POST['category_id'] : null; $vals[]=$catId; }
    if($hasSubcatId){
      $tmpSub = ($_POST['subcategory_id']!=='')? (int)$_POST['subcategory_id'] : null;
      if($tmpSub && $catId){
        $ok=$db->prepare("SELECT 1 FROM subcategories WHERE id=? AND category_id=?");
        $ok->execute([$tmpSub,$catId]);
        if($ok->fetchColumn()){ $subId=$tmpSub; }
      }
      $sets[]='subcategory_id=?'; $vals[]=$subId;
    }

    $vals[]=$id;
    $sql="UPDATE items SET ".implode(',',$sets)." WHERE id=?";
    $db->prepare($sql)->execute($vals); $msg='تم التحديث.';
  }
  elseif($action==='delete'){
    // احذف الصورة الفعلية أيضاً لو موجودة
    if ($hasImage) {
      $st = $db->prepare("SELECT image_url FROM items WHERE id=?"); $st->execute([(int)$_POST['id']]);
      $url = $st->fetchColumn();
      if ($url && str_starts_with($url, $UPLOAD_URL)) {
        @unlink($UPLOAD_DIR . '/' . basename($url));
      }
    }
    $db->prepare("DELETE FROM items WHERE id=?")->execute([(int)$_POST['id']]);
    $msg='تم الحذف.';
  }
} catch(Throwable $e){ $err=$e->getMessage(); }

/** Filters */
$q   = trim($_GET['q'] ?? '');
$cat = ($hasCatId && isset($_GET['category_id']) && $_GET['category_id']!=='') ? (int)$_GET['category_id'] : null;
$sub = ($hasSubcatId && isset($_GET['subcategory_id']) && $_GET['subcategory_id']!=='') ? (int)$_GET['subcategory_id'] : null;

/** List query */
$select = "i.*";
$join   = "";
if($hasCatId){    $select.=", c.name AS category_name";     $join.=" LEFT JOIN categories c ON c.id=i.category_id "; }
if($hasSubcatId){ $select.=", s.name AS subcategory_name";  $join.=" LEFT JOIN subcategories s ON s.id=i.subcategory_id "; }

$where="1"; $params=[];
if($q!==''){
  $where.=" AND (i.name LIKE ?".($hasSKU?" OR i.sku LIKE ?":"").")";
  $params[]="%$q%"; if($hasSKU){ $params[]="%$q%"; }
}
if($cat!==null && $hasCatId){ $where.=" AND i.category_id=?"; $params[]=$cat; }
if($sub!==null && $hasSubcatId){ $where.=" AND i.subcategory_id=?"; $params[]=$sub; }

$sqlList = "SELECT $select FROM items i $join WHERE $where ORDER BY i.id DESC LIMIT 200";
$st=$db->prepare($sqlList); $st->execute($params);
$list=$st->fetchAll(PDO::FETCH_ASSOC);

/** Editing item */
$editing=null;
if(isset($_GET['edit'])){
  $st=$db->prepare("SELECT * FROM items WHERE id=?"); $st->execute([(int)$_GET['edit']]); $editing=$st->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>الأصناف</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
  :root{
    --bg:#f7f8fb;
    --card:#fff;
    --ink:#111;
    --muted:#667;
    --bd:#e8e8ef;
  }

  *{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{
    background: radial-gradient(1200px 600px at 50% -200px, #eef3ff, #f6f7fb);
    color: var(--ink);
    font-family: system-ui, -apple-system, Segoe UI, Roboto, "Noto Kufi Arabic", "Cairo", sans-serif;
    line-height:1.55;
  }

  .container{max-width:1100px;margin:18px auto;padding:0 14px}

  .card{
    background:var(--card);
    border:1px solid var(--bd);
    border-radius:14px;
    box-shadow:0 8px 24px rgba(0,0,0,.06);
    padding:14px;
    margin-block:12px;
  }

  .btn{
    border:0;background:#2261ee;color:#fff;
    padding:10px 14px;border-radius:12px;cursor:pointer;font-weight:600;
    transition:transform .15s,opacity .15s,box-shadow .15s;
    box-shadow:0 6px 16px rgba(34,97,238,.18);
  }
  .btn:hover{ transform: translateY(-1px); }
  .btn.secondary{ background:#eef3fb; color:#0b4ea9; box-shadow:none; }
  .btn.danger{ background:#b3261e; color:#fff; }

  .input, select, input[type="file"]{
    width:100%;
    border:1px solid var(--bd);
    background:#fff; color:var(--ink);
    border-radius:12px; padding:10px 12px; outline:0;
    transition:border-color .15s, box-shadow .15s;
  }
  .input:focus, select:focus, input[type="file"]:focus{
    border-color:#cfe2ff;
    box-shadow:0 0 0 3px #cfe2ff55;
  }

  /* فلاتر أعلى الجدول */
  .filters{
    display:grid;
    grid-template-columns: 1fr 220px 220px 120px;
    gap:8px; align-items:center;
  }

  /* شبكة الفورم مرتبة */
  .form-grid{
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:10px;
  }
  .form-grid > label{ display:block; font-size:13px; color:#555; }
  .form-grid > label > .input,
  .form-grid > label > select,
  .form-grid > label > input[type="file"]{ margin-top:6px; }

  .pill{
    display:inline-block; padding:4px 10px; border-radius:999px;
    background:#f6f7fb; border:1px solid #eee; color:#333; font-weight:600; font-size:12px;
  }

  /* جدول مرتب بدون تغيير شكل جذري */
  table.table{ width:100%; border-collapse:separate; border-spacing:0 8px; }
  .table thead th{
    text-align:start; font-size:12px; color:#667; font-weight:700;
    padding:0 10px 6px;
  }
  .table tbody tr{
    background:#fff; border:1px solid var(--bd); border-radius:12px;
  }
  .table tbody tr > td{
    padding:10px; border-top:1px solid var(--bd);
  }
  .table tbody tr > td:first-child{
    border-start-start-radius:12px; border-end-start-radius:12px; border-right:0;
  }
  .table tbody tr > td:last-child{
    border-start-end-radius:12px; border-end-end-radius:12px; border-left:0;
  }

  .img-thumb{width:44px;height:44px;object-fit:cover;border-radius:10px;border:1px solid var(--bd)}

  /* رسائل */
  .alert-ok{ background:#ecfdf5; border:1px solid #c7f3e3; }
  .alert-err{ background:#fef2f2; border:1px solid #f9cccc; }

  /* أزرار الإجراءات في الجدول مضغوطة */
  .row-actions{ display:flex; gap:8px; align-items:center; }
</style>

</head>
<body>
<div class="container">
  <h2>الأصناف</h2>
  <?php if($msg): ?><div class="card" style="background:#ecfdf5"><?=e($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="card" style="background:#fef2f2">خطأ: <?=e($err)?></div><?php endif; ?>

  <!-- Filters -->
  <form method="get" class="card" style="display:grid;grid-template-columns:1fr 220px 220px 120px;gap:8px;align-items:center">
    <input class="input" name="q" value="<?=e($q)?>" placeholder="بحث بالاسم<?= $hasSKU? '/الكود':'' ?>">
    <select class="input" id="f_category" name="category_id" <?= $hasCatId? '':'disabled' ?>>
      <option value=""><?= $hasCatId? 'كل التصنيفات':'التصنيفات غير مفعّلة' ?></option>
      <?php foreach($cats as $c): ?>
        <option value="<?=$c['id']?>" <?= ($cat===$c['id'])?'selected':'' ?>><?=e($c['name'])?></option>
      <?php endforeach; ?>
    </select>
    <select class="input" id="f_subcategory" name="subcategory_id" <?= $hasSubcatId? '':'disabled' ?>>
      <option value="">كل الفروع</option>
    </select>
    <button class="btn">بحث</button>
  </form>

  <!-- Form -->
  <div class="card">
    <h3><?= $editing? 'تعديل صنف':'إضافة صنف' ?></h3>
    <!-- مهم: enctype للرفع -->
    <form method="post" class="grid" enctype="multipart/form-data">
      <input type="hidden" name="action" value="<?= $editing? 'update':'create' ?>">
      <?php if($editing): ?><input type="hidden" name="id" value="<?=$editing['id']?>"><?php endif; ?>

      <label>الاسم
        <input class="input" name="name" required value="<?=e($editing['name'] ?? '')?>">
      </label>

      <?php if($hasImage): ?>
      <label>الصورة (JPG/PNG/WEBP/GIF) — حد 3MB
        <input class="input" type="file" name="image_file" accept="image/*">
        <?php if(!empty($editing['image_url'])): ?>
          <div style="margin-top:6px;display:flex;align-items:center;gap:10px">
            <img src="<?= e($editing['image_url']) ?>" class="img-thumb" alt="">
            <label style="display:flex;align-items:center;gap:6px">
              <input type="checkbox" name="remove_image" value="1"> حذف الصورة الحالية
            </label>
          </div>
        <?php endif; ?>
      </label>
      <?php endif; ?>

      <?php if($hasSKU): ?>
      <label>SKU/باركود
        <input class="input" name="sku" value="<?=e($editing['sku'] ?? '')?>">
      </label>
      <?php endif; ?>

      <?php if($hasPrice): ?>
      <label>السعر
        <input class="input" name="unit_price" type="number" step="0.01" value="<?=e($editing['unit_price'] ?? '')?>">
      </label>
      <?php endif; ?>

      <?php if($hasReorder): ?>
      <label>حد إعادة الطلب
        <input class="input" name="reorder_level" type="number" step="1" value="<?=e($editing['reorder_level'] ?? 0)?>">
      </label>
      <?php endif; ?>

      <?php if($hasStock): ?>
      <label>المخزون
        <input class="input" name="stock" type="number" step="1" min="0" value="<?= e($editing['stock'] ?? 0) ?>">
      </label>
      <?php endif; ?>

      <?php if($hasCatId): ?>
      <label>التصنيف
        <select class="input" id="i_category" name="category_id">
          <option value="">— بدون —</option>
          <?php foreach($cats as $c): ?>
            <option value="<?=$c['id']?>" <?= (isset($editing['category_id']) && (int)$editing['category_id']===(int)$c['id'])?'selected':'' ?>><?=e($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>

      <?php if($hasSubcatId): ?>
      <label>التصنيف الفرعي
        <select class="input" id="i_subcategory" name="subcategory_id">
          <option value="">— بدون —</option>
        </select>
      </label>
      <?php endif; ?>

      <div style="grid-column:1/-1">
        <button class="btn" type="submit"><?= $editing? 'تحديث':'إضافة' ?></button>
        <?php if($editing): ?><a class="btn secondary" href="?">إلغاء</a><?php endif; ?>
      </div>
    </form>
  </div>

  <!-- List -->
  <div class="card">
    <h3>قائمة الأصناف (<?=count($list)?>)</h3>
    <table class="table">
      <thead>
        <tr>
          <th>#</th><th>الاسم</th>
          <?php if($hasImage): ?><th>صورة</th><?php endif; ?>
          <?php if($hasSKU): ?><th>SKU</th><?php endif; ?>
          <?php if($hasPrice): ?><th>السعر</th><?php endif; ?>
          <?php if($hasStock): ?><th>المخزون</th><?php endif; ?>
          <?php if($hasCatId): ?><th>التصنيف</th><?php endif; ?>
          <?php if($hasSubcatId): ?><th>الفرعي</th><?php endif; ?>
          <?php if($hasReorder): ?><th>حد إعادة الطلب</th><?php endif; ?>
          <th>إجراءات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($list as $it): ?>
          <tr>
            <td><?=$it['id']?></td>
            <td><?=e($it['name'])?></td>

            <?php if($hasImage): ?>
              <td>
                <?php if(!empty($it['image_url'])): ?>
                  <img src="<?= e($it['image_url']) ?>" alt="" class="img-thumb">
                <?php else: ?>
                  <span class="pill">—</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>

            <?php if($hasSKU): ?><td><?=e($it['sku'])?></td><?php endif; ?>
            <?php if($hasPrice): ?><td><?= $it['unit_price']!==null ? number_format((float)$it['unit_price'],2) : '—' ?></td><?php endif; ?>
            <?php if($hasStock): ?>
              <td>
                <?php
                  $stk = (int)($it['stock'] ?? 0);
                  $low = ($hasReorder && isset($it['reorder_level']) && $stk <= (int)$it['reorder_level']);
                ?>
                <span class="pill" style="<?= $low ? 'background:#fff7ed;border-color:#fbbf24' : '' ?>">
                  <?= $stk ?>
                </span>
              </td>
            <?php endif; ?>
            <?php if($hasCatId): ?><td><span class="pill"><?=e($it['category_name'] ?? '—')?></span></td><?php endif; ?>
            <?php if($hasSubcatId): ?><td><span class="pill"><?=e($it['subcategory_name'] ?? '—')?></span></td><?php endif; ?>
            <?php if($hasReorder): ?><td><?= (int)($it['reorder_level'] ?? 0) ?></td><?php endif; ?>
            <td>
              <a class="btn" href="?edit=<?=$it['id']?>">تعديل</a>
              <form method="post" style="display:inline" onsubmit="return confirm('حذف الصنف؟');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$it['id']?>">
                <button class="btn" type="submit">حذف</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
/** تعبئة قائمة الفرعيات */
async function fillSubcats(selectCat, selectSub, selectedId){
  const cid = selectCat.value || '';
  selectSub.innerHTML = '';
  const opt0 = document.createElement('option');
  opt0.value = ''; opt0.textContent = cid ? '— اختر الفرعي —' : '— بدون —';
  selectSub.appendChild(opt0);
  if(!cid){ return; }

  try{
    const res = await fetch(`?ajax=subcats&category_id=${encodeURIComponent(cid)}`);
    const data = await res.json();
    data.forEach(sc=>{
      const o=document.createElement('option');
      o.value=sc.id; o.textContent=sc.name;
      if(selectedId && String(selectedId)===String(sc.id)) o.selected=true;
      selectSub.appendChild(o);
    });
  }catch(e){}
}

/** فلاتر أعلى القائمة */
const fCat = document.getElementById('f_category');
const fSub = document.getElementById('f_subcategory');
if(fCat && fSub){
  fCat.addEventListener('change', ()=> fillSubcats(fCat, fSub, ''));
  <?php if($sub && $cat): ?>
    fillSubcats(fCat, fSub, <?=json_encode($sub)?>);
  <?php else: ?>
    if(fCat.value){ fillSubcats(fCat, fSub, ''); }
  <?php endif; ?>
}

/** نموذج الإضافة/التعديل */
const iCat = document.getElementById('i_category');
const iSub = document.getElementById('i_subcategory');
if(iCat && iSub){
  iCat.addEventListener('change', ()=> fillSubcats(iCat, iSub, ''));
  <?php if($editing && !empty($editing['category_id'])): ?>
    fillSubcats(iCat, iSub, <?=json_encode((int)($editing['subcategory_id'] ?? 0))?>);
  <?php endif; ?>
}
</script>
</body>
</html>
