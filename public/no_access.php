<?php
// لو الصفحة في جذر المشروع
require_once __DIR__ . '.../../lib/auth.php';
require_once __DIR__ . '.../../lib/helpers.php';

// لو حابب اللي يشوف الصفحة يكون مسجل دخول بس
// تقدر تشيل السطر ده لو عايزها تشتغل لأي حد
require_login();

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <title>لا تملك صلاحية الوصول</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .error-wrap{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:16px;
    }
    .error-icon{
      font-size:60px;
      margin-bottom:10px;
    }
    .error-title{
      font-size:22px;
      margin:0 0 8px;
    }
    .error-text{
      margin:0 0 16px;
      color:#555;
    }
    .error-actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      justify-content:flex-end;
    }
  </style>
</head>
<body>
  <div class="container error-wrap">
    <div class="card" style="max-width:420px;width:100%;text-align:right">
      <div class="error-icon">🔒</div>
      <h1 class="error-title">لا تملك صلاحية الوصول لهذه الصفحة</h1>
      <p class="error-text">
        حسابك لا يملك الصلاحيات المطلوبة لعرض هذه الصفحة.<br>
        لو تفتكر إن ده خطأ، كلم الأدمن.
      </p>
      <div class="error-actions">
        <a href="dashboard.php" class="btn">الرجوع للوحة التحكم</a>
        <a href="logout.php" class="btn secondary">تسجيل الخروج</a>
      </div>
    </div>
  </div>
</body>
</html>
