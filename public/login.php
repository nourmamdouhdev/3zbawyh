<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = login($_POST['username'] ?? '', $_POST['password'] ?? '');

    if ($ok) {
        $user = current_user();

        if (($user['role'] ?? null) === 'fake_viewer') {
            header('Location: /3zbawyh/public/fake_sales_report.php');
            exit;
        }

        if (is_cashier()) {
            header('Location: /3zbawyh/public/order_type.php');
            exit;
        }

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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول - العزباوية</title>
<link rel="stylesheet" href="/3zbawyh/assets/style.css">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" href="icons/favicon.png">
<style>
:root {
  --bg: #f6f7fb;
  --card: #ffffff;
  --ink: #111111;
  --muted: #6b7280;
  --line: #e6e7ee;
}
body {
  margin: 0;
  min-height: 100vh;
  background: var(--bg);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.login-shell {
  width: min(980px, 100%);
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  gap: 24px;
  align-items: stretch;
}
.login-panel {
  background: var(--card);
  border-radius: 20px;
  padding: 28px;
  box-shadow: 0 10px 26px rgba(0,0,0,0.08);
  border: 1px solid var(--line);
}
.brand {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}
.brand img {
  width: 52px;
  height: 52px;
  object-fit: contain;
  border-radius: 12px;
  border: 1px solid var(--line);
  background: #fff;
  padding: 6px;
}
.brand h1 {
  margin: 0;
  font-size: 22px;
  color: var(--ink);
}
.brand p {
  margin: 4px 0 0;
  color: var(--muted);
  font-size: 14px;
}
.login-panel h2 {
  margin: 16px 0 8px;
  font-size: 20px;
  color: var(--ink);
}
.field {
  display: block;
  margin-bottom: 14px;
  text-align: right;
}
.field span {
  display: block;
  margin-bottom: 6px;
  font-size: 13px;
  color: var(--muted);
}
.login-panel .input {
  width: 100%;
  font-size: 16px;
  border: 1px solid var(--line);
  background: #fff;
}
.login-panel .btn {
  width: 100%;
  font-size: 16px;
  padding: 12px 16px;
}
.login-side {
  background: #fff;
  border-radius: 20px;
  padding: 28px;
  border: 1px solid var(--line);
  position: relative;
  overflow: hidden;
}
.login-side::before,
.login-side::after {
  content: "";
  position: absolute;
  border-radius: 50%;
  background: rgba(17, 17, 17, 0.05);
  width: 180px;
  height: 180px;
}
.login-side::before {
  top: -60px;
  left: -60px;
}
.login-side::after {
  bottom: -80px;
  right: -80px;
}
.login-side h3 {
  margin: 0 0 12px;
  font-size: 20px;
  color: var(--ink);
}
.list {
  margin: 0;
  padding: 0;
  list-style: none;
  color: var(--muted);
  line-height: 1.9;
}
.error {
  background: #fff2f2;
  color: #b00020;
  border: 1px solid #f2c7c7;
  padding: 10px 12px;
  border-radius: 10px;
  margin: 12px 0;
  font-size: 14px;
}
@media (max-width: 900px) {
  .login-shell {
    grid-template-columns: 1fr;
  }
  .login-side {
    order: -1;
  }
}
@media (max-width: 520px) {
  body {
    padding: 16px;
  }
  .login-panel,
  .login-side {
    padding: 20px;
    border-radius: 16px;
  }
  .brand img {
    width: 46px;
    height: 46px;
  }
}
</style>
</head>
<body>
  <main class="login-shell">
    <section class="login-panel">
      <div class="brand">
        <img src="icons/elezbawiya.png" alt="العزباوية">
        <div>
          <h1>العزباوية</h1>
          <p>تجهيز الطلبات بسرعة ودقة</p>
        </div>
      </div>
      <h2>تسجيل الدخول</h2>
      <?php if ($error): ?>
        <div class="error"><?=e($error)?></div>
      <?php endif; ?>
      <form method="post">
        <label class="field">
          <span>اسم المستخدم</span>
          <input class="input" name="username" autocomplete="username" required>
        </label>
        <label class="field">
          <span>كلمة السر</span>
          <input class="input" name="password" type="password" autocomplete="current-password" required>
        </label>
        <button class="btn" type="submit">دخول</button>
      </form>
    </section>
    <aside class="login-side">
      <h3>مرحبا بك في نظام العزباوية</h3>
      <ul class="list">
        <li>تابع الطلبات والفواتير في واجهة واحدة.</li>
        <li>تنظيم سريع للجرد والسلع والتقارير.</li>
        <li>تجربة محسنة للتشغيل على الهاتف.</li>
      </ul>
    </aside>
  </main>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/3zbawyh/public/service-worker.js');
}
</script>
</body>

</html>
