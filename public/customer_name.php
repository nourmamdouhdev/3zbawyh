<?php
// public/customer_name.php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier','Manger']);

if (!isset($_SESSION['pos_flow'])) {
  $_SESSION['pos_flow'] = [];
}

$msg = '';
$err = '';

// نحدد المود: فاتورة ولا قطاعي
$mode = $_GET['mode'] ?? ($_SESSION['pos_sale_type'] ?? 'invoice');
$mode = ($mode === 'retail') ? 'retail' : 'invoice'; // default invoice

function valid_phone($s){
  $s = trim($s);
  if ($s === '') return false;
  if (!preg_match('/^\+?\d{7,15}$/', $s)) return false;
  return true;
}

function normalize_phone_digits($s){
  return preg_replace('/\D+/', '', (string)$s);
}
function normalize_name($s){
  $s = trim((string)$s);
  if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
  return strtolower($s);
}
function phone_variants($digits){
  $digits = (string)$digits;
  $v = [];
  if ($digits !== '') $v[] = $digits;
  if (strlen($digits) === 11 && substr($digits, 0, 2) === '01') {
    $v[] = '2'.$digits; // 010... -> 2010...
  }
  if (strlen($digits) === 12 && substr($digits, 0, 2) === '20') {
    $v[] = '0'.substr($digits, 2); // 2010... -> 010...
  }
  return array_values(array_unique($v));
}
function find_customer_by_phone(PDO $db, string $phone): ?array {
  if (!function_exists('table_exists') || !table_exists($db, 'customers')) return null;
  $digits = normalize_phone_digits($phone);
  if ($digits === '') return null;
  $variants = phone_variants($digits);

  $nameCol  = column_exists($db, 'customers', 'name') ? 'name' :
              (column_exists($db, 'customers', 'customer_name') ? 'customer_name' :
              (column_exists($db, 'customers', 'full_name') ? 'full_name' : 'name'));
  $phoneCol = column_exists($db, 'customers', 'phone') ? 'phone' :
              (column_exists($db, 'customers', 'customer_phone') ? 'customer_phone' : 'phone');

  $expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($phoneCol,'+',''),' ',''),'-',''),'(',''),')','')";
  foreach ($variants as $d) {
    $st = $db->prepare("SELECT id, `$nameCol` AS name, `$phoneCol` AS phone FROM customers WHERE $phoneCol IS NOT NULL AND $expr = ? LIMIT 1");
    $st->execute([$d]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  return null;
}

function search_customers_by_phone(PDO $db, string $phone, int $limit=6): array {
  if (!function_exists('table_exists') || !table_exists($db, 'customers')) return [];
  $digits = normalize_phone_digits($phone);
  if ($digits === '' || strlen($digits) < 4) return [];

  $nameCol  = column_exists($db, 'customers', 'name') ? 'name' :
              (column_exists($db, 'customers', 'customer_name') ? 'customer_name' :
              (column_exists($db, 'customers', 'full_name') ? 'full_name' : 'name'));
  $phoneCol = column_exists($db, 'customers', 'phone') ? 'phone' :
              (column_exists($db, 'customers', 'customer_phone') ? 'customer_phone' : 'phone');

  $expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($phoneCol,'+',''),' ',''),'-',''),'(',''),')','')";
  $like = "%$digits%";
  $sql = "SELECT id, `$nameCol` AS name, `$phoneCol` AS phone
          FROM customers
          WHERE $phoneCol IS NOT NULL AND ($expr LIKE ? OR $phoneCol LIKE ?)
          ORDER BY `$nameCol` ASC
          LIMIT ".max(1, (int)$limit);
  $st = $db->prepare($sql);
  $st->execute([$like, "%$phone%"]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'phone_lookup') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $phone = trim((string)($_GET['phone'] ?? ''));
    $rows = search_customers_by_phone(db(), $phone, 6);
    echo json_encode(['ok'=>1, 'items'=>$rows], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>0, 'items'=>[], 'error'=>$e->getMessage()]);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act   = $_POST['act'] ?? '';
  $name  = trim($_POST['customer_name'] ?? '');
  $phone = trim($_POST['customer_phone'] ?? '');

  // ✅ زر التخطي متاح فقط في قطاعي
  if ($act === 'skip' && $mode === 'retail') {
    $_SESSION['pos_flow']['customer_name']    = '';
    $_SESSION['pos_flow']['customer_phone']   = '';
    $_SESSION['pos_flow']['customer_skipped'] = true;

    header('Location: /3zbawyh/public/select_items.php?all=1'); 
    exit;
  }

  if ($act === 'save') {

    if ($mode === 'invoice') {
      // 🔒 مود فاتورة: لازم اسم + موبايل
      if ($name === '') {
        $err = 'اسم العميل مطلوب.';
      } elseif ($phone === '') {
        $err = 'رقم الموبايل مطلوب.';
      } elseif (!valid_phone($phone)) {
        $err = 'صيغة رقم الموبايل غير صحيحة. مثال: 01012345678 أو +201012345678';
      } else {
        $existing = find_customer_by_phone(db(), $phone);
        if ($existing) {
          $existingName = trim((string)($existing['name'] ?? ''));
          if ($existingName !== '' && $name !== '' && normalize_name($name) !== normalize_name($existingName)) {
            $err = 'هذا الرقم مسجل باسم عميل آخر: ' . $existingName;
          } else {
            $name  = $existingName ?: $name;
            $phone = trim((string)($existing['phone'] ?? $phone));
            $msg = 'تم اختيار عميل مسجل بنفس الرقم.';
          }
        }
        if ($err === '') {
          $_SESSION['pos_flow']['customer_name']    = $name;
          $_SESSION['pos_flow']['customer_phone']   = $phone;
          $_SESSION['pos_flow']['customer_skipped'] = false;
          header('Location: /3zbawyh/public/select_items.php?all=1'); 
          exit;
        }
      }

    } else {
      // 🟢 مود قطاعي: اسم أو موبايل أو الاتنين .. أو يقدر يعمل تخطي من الزر
      if ($name === '' && $phone === '') {
        $err = 'اكتب اسم العميل أو رقم الموبايل، أو اضغط "تخطي" للمتابعة بدون بيانات.';
      } elseif ($phone !== '' && !valid_phone($phone)) {
        $err = 'صيغة رقم الموبايل غير صحيحة. مثال: 01012345678 أو +201012345678';
      } else {
        if ($phone !== '') {
          $existing = find_customer_by_phone(db(), $phone);
          if ($existing) {
            $existingName = trim((string)($existing['name'] ?? ''));
            if ($existingName !== '' && $name !== '' && normalize_name($name) !== normalize_name($existingName)) {
              $err = 'هذا الرقم مسجل باسم عميل آخر: ' . $existingName;
            } else {
              if ($name === '') $name = $existingName ?: $name;
              $phone = trim((string)($existing['phone'] ?? $phone));
              $msg = 'تم اختيار عميل مسجل بنفس الرقم.';
            }
          }
        }
        if ($err === '') {
          $_SESSION['pos_flow']['customer_name']    = $name;
          $_SESSION['pos_flow']['customer_phone']   = $phone;
          $_SESSION['pos_flow']['customer_skipped'] = false;
          header('Location: /3zbawyh/public/select_items.php?all=1'); 
          exit;
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>POS — بيانات العميل</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
body { background: radial-gradient(1200px 600px at center, #f6f7fb, #e8eaf6); font-family: "Cairo", sans-serif; }
.card { background:#fff; padding:25px; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
.btn { background:#2261ee; color:#fff; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-size:15px; }
.btn:hover{ opacity:.9; }
.btn.secondary{ background:#e8e8ef; color:#111; }
.input{ width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px; font-size:15px; }
.row{ display:flex; gap:10px; }
.row .col{ flex:1; }
.badge{ display:inline-flex; align-items:center; gap:8px; background:#fff1f2; border:1px solid #ffd4d8; color:#b3261e; padding:6px 10px; border-radius:999px; font-size:14px; margin-bottom:10px; }
.help{ color:#666; font-size:13px; margin-top:6px; }
.container{ display:flex; flex-direction:column; justify-content:center; align-items:center; min-height:100vh; text-align:center; }
.suggest{ position:relative; }
.suggest-list{
  position:absolute; z-index:10; top:100%; right:0; left:0;
  background:#fff; border:1px solid #e5e7eb; border-radius:8px;
  box-shadow:0 8px 20px rgba(0,0,0,.08); max-height:220px; overflow:auto;
}
.suggest-item{ padding:8px 10px; cursor:pointer; text-align:right; }
.suggest-item:hover{ background:#f3f4f6; }
.suggest-name{ font-weight:700; font-size:14px; }
.suggest-phone{ color:#666; font-size:12px; }
@media(max-width:520px){ .row{ flex-direction:column; } }
</style>
</head>
<body>
<div class="container">

  <div class="card" style="max-width:520px; width:92%;">

    <h2 style="margin-top:0; color:#111;">
      <?= ($mode === 'invoice') ? 'بيانات العميل (فاتورة)' : 'بيانات العميل (قطاعي)' ?>
    </h2>

    <?php if($err): ?>
      <div class="badge"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>
    <?php if($msg): ?>
      <div class="badge" style="background:#ecfdf5;border-color:#bbf7d0;color:#166534"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="row" style="margin-bottom:12px;">
        <div class="col">
          <label style="display:block; text-align:right; font-size:13px; margin-bottom:6px;">اسم العميل <?= ($mode==='invoice' ? '*' : '(اختياري)') ?></label>
          <input class="input" type="text" name="customer_name" placeholder="مثال: أحمد علي"
                 value="<?= htmlspecialchars($_SESSION['pos_flow']['customer_name'] ?? '') ?>"
                 <?= ($mode === 'invoice') ? 'required' : '' ?>>
        </div>
        <div class="col">
          <label style="display:block; text-align:right; font-size:13px; margin-bottom:6px;">موبايل <?= ($mode==='invoice' ? '*' : '(اختياري)') ?></label>
          <div class="suggest">
            <input class="input" type="text" id="customer_phone" name="customer_phone" placeholder="مثال: 01012345678 أو +201012345678"
                 value="<?= htmlspecialchars($_SESSION['pos_flow']['customer_phone'] ?? '') ?>"
                 <?= ($mode === 'invoice') ? 'required' : '' ?>>
            <div id="phoneSuggest" class="suggest-list" style="display:none"></div>
          </div>
        </div>
      </div>

      <div class="help">
        <?php if ($mode === 'invoice'): ?>
          يجب إدخال اسم العميل ورقم الموبايل للمتابعة.
        <?php else: ?>
          يمكنك إدخال اسم العميل أو رقم الموبايل (أو كليهما)، أو الضغط على "تخطي" للمتابعة بدون بيانات.
        <?php endif; ?>
      </div>

      <div style="margin-top:16px; display:flex; gap:8px; flex-wrap:wrap; justify-content:center;">
        <button class="btn" type="submit" name="act" value="save">متابعة</button>

        <?php if ($mode === 'retail'): ?>
          <button class="btn secondary" type="submit" name="act" value="skip">
            تخطي (بدون بيانات)
          </button>
        <?php endif; ?>
      </div>
    </form>
  </div>

</div>
<script>
  const phoneInput = document.getElementById('customer_phone');
  const suggestBox = document.getElementById('phoneSuggest');
  const nameInput = document.querySelector('input[name="customer_name"]');
  let tmr = null;

  function hideSuggest(){ if (suggestBox) suggestBox.style.display='none'; }
  function showSuggest(items){
    if (!suggestBox) return;
    if (!items.length){ hideSuggest(); return; }
    suggestBox.innerHTML = items.map(it =>
      `<div class="suggest-item" data-name="${(it.name||'').replace(/"/g,'&quot;')}" data-phone="${(it.phone||'').replace(/"/g,'&quot;')}">
         <div class="suggest-name">${it.name||''}</div>
         <div class="suggest-phone">${it.phone||''}</div>
       </div>`
    ).join('');
    suggestBox.style.display='block';
  }

  if (phoneInput) {
    phoneInput.addEventListener('input', ()=>{
      clearTimeout(tmr);
      const q = phoneInput.value.trim();
      if (q.length < 4) { hideSuggest(); return; }
      tmr = setTimeout(()=>{
        fetch(`/3zbawyh/public/customer_name.php?ajax=phone_lookup&phone=${encodeURIComponent(q)}`)
          .then(r=>r.json())
          .then(d=>{ if (d && d.ok) showSuggest(d.items||[]); else hideSuggest(); })
          .catch(()=> hideSuggest());
      }, 180);
    });
  }
  if (suggestBox) {
    suggestBox.addEventListener('click', (e)=>{
      const item = e.target.closest('.suggest-item');
      if (!item) return;
      const name = item.getAttribute('data-name') || '';
      const phone = item.getAttribute('data-phone') || '';
      if (nameInput && name) nameInput.value = name;
      if (phoneInput && phone) phoneInput.value = phone;
      hideSuggest();
    });
  }
  document.addEventListener('click', (e)=>{
    if (!suggestBox) return;
    if (!e.target.closest('.suggest')) hideSuggest();
  });
</script>
</body>
</html>
