<?php
// public/order_type.php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier','Manger']);

if (!isset($_SESSION['pos_flow'])) {
  $_SESSION['pos_flow'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  if ($act === 'invoice') {

    // ✅ سعر عادي (فاتورة باسم عميل) - لازم بيانات كاملة ومفيش تخطي
    $_SESSION['pos_sale_type'] = 'normal';

    $_SESSION['pos_flow']['customer_skipped'] = false;
    header('Location: /3zbawyh/public/customer_name.php?mode=invoice');
    exit;

  } elseif ($act === 'retail') {

    // ✅ قطاعي: يا إمّا يكتب اسم/موبايل أو يعمل تخطي
    $_SESSION['pos_sale_type'] = 'wholesale';

    // هنسيب الاسم والموبايل يتحددوا في صفحة customer_name.php
    unset($_SESSION['pos_flow']['customer_name'], $_SESSION['pos_flow']['customer_phone']);
    $_SESSION['pos_flow']['customer_skipped'] = false;

    header('Location: /3zbawyh/public/customer_name.php?mode=retail');
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>POS — اختيار نوع المعاملة</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
body {
  background: radial-gradient(1200px 600px at center, #f6f7fb, #e8eaf6);
  font-family: "Cairo", sans-serif;
  margin: 0;
}
.container{
  display:flex;
  flex-direction:column;
  justify-content:center;
  align-items:center;
  min-height:100vh;
  text-align:center;
}
.card{
  background:#fff;
  padding:25px;
  border-radius:14px;
  box-shadow:0 2px 10px rgba(0,0,0,0.05);
  max-width:420px;
  width:92%;
}
.btn{
  background:#2261ee;
  color:#fff;
  border:none;
  padding:12px 20px;
  border-radius:8px;
  cursor:pointer;
  font-size:15px;
  min-width:180px;
}
.btn:hover{ opacity:.9; }
.btn.secondary{
  background:#e8e8ef;
  color:#111;
}
.choice-row{
  display:flex;
  flex-direction:column;
  gap:10px;
  margin-top:18px;
}
@media(min-width:520px){
  .choice-row{
    flex-direction:row;
    justify-content:center;
  }
}
.help{
  color:#666;
  font-size:13px;
  margin-top:8px;
}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h2 style="margin-top:0; color:#111;">اختيار نوع المعاملة</h2>
    <p class="help">اختار لو هتصدر فاتورة باسم عميل، أو بيع قطاعي (بيانات اختيارية).</p>

    <form method="post">
      <div class="choice-row">
        <button class="btn" type="submit" name="act" value="invoice">
          فاتورة باسم عميل  
        </button>
        <button class="btn secondary" type="submit" name="act" value="retail">
          قطاعي   
        </button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
