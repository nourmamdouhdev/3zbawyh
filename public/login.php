<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ok = login($_POST['username'] ?? '', $_POST['password'] ?? '');

    if ($ok) {

        // نجيب بيانات اليوزر
        $user = current_user();

        // 🔹 أولاً: لو دوره fake_viewer يروح لصفحة الـ fake
        if (($user['role'] ?? null) === 'fake_viewer') {   // لو عندك role_name بدل role غيّر السطر ده
            header('Location: /3zbawyh/public/fake_sales_report.php');
            exit;
        }

        // 🔹 ثانياً: لو كاشير → order_type
        if (is_cashier()) {
            header('Location: /3zbawyh/public/order_type.php');
            exit;
        }

        // 🔹 باقي الناس → dashboard
        header('Location: /3zbawyh/public/dashboard.php');
        exit;

    } else {
        $error = 'بيانات غير صحيحة';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>تسجيل الدخول - العزباوية</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<style>
body {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100vh;
}
.login-box {
  width: 100%;
  max-width: 400px;
  background: #fff;
  padding: 24px;
  border-radius: 16px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  text-align: center;
}
.login-box h2 {
  margin-bottom: 20px;
  font-size: 22px;
}
.login-box .input {
  margin-bottom: 12px;
  font-size: 16px;
}
.login-box .btn {
  width: 100%;
  font-size: 16px;
}
@media (max-width: 480px) {
  .login-box {
    padding: 16px;
    border-radius: 12px;
  }
  .login-box h2 {
    font-size: 20px;
  }
}
</style>
</head>
<body>
  <div class="login-box">
    <h2>تسجيل الدخول</h2>
    <?php if($error): ?>
      <div style="color:#b00;margin-bottom:10px"><?=e($error)?></div>
    <?php endif; ?>
    <form method="post">
      <input class="input" name="username" placeholder="اسم المستخدم" required>
      <input class="input" name="password" type="password" placeholder="كلمة السر" required>
      <button class="btn" type="submit">دخول</button>
    </form>
  </div>
</body>
</html>
