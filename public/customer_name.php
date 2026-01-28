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
        $_SESSION['pos_flow']['customer_name']    = $name;
        $_SESSION['pos_flow']['customer_phone']   = $phone;
        $_SESSION['pos_flow']['customer_skipped'] = false;
        header('Location: /3zbawyh/public/select_items.php?all=1'); 
        exit;
      }

    } else {
      // 🟢 مود قطاعي: اسم أو موبايل أو الاتنين .. أو يقدر يعمل تخطي من الزر
      if ($name === '' && $phone === '') {
        $err = 'اكتب اسم العميل أو رقم الموبايل، أو اضغط "تخطي" للمتابعة بدون بيانات.';
      } elseif ($phone !== '' && !valid_phone($phone)) {
        $err = 'صيغة رقم الموبايل غير صحيحة. مثال: 01012345678 أو +201012345678';
      } else {
        $_SESSION['pos_flow']['customer_name']    = $name;
        $_SESSION['pos_flow']['customer_phone']   = $phone;
        $_SESSION['pos_flow']['customer_skipped'] = false;
        header('Location: /3zbawyh/public/select_items.php?all=1'); 
        exit;
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
          <input class="input" type="text" name="customer_phone" placeholder="مثال: 01012345678 أو +201012345678"
                 value="<?= htmlspecialchars($_SESSION['pos_flow']['customer_phone'] ?? '') ?>"
                 <?= ($mode === 'invoice') ? 'required' : '' ?>>
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
</body>
</html>
