<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../app/config/db.php';

require_admin_or_redirect(); // 🔒 أدمن فقط
$pdo = db();

$msg = ''; 
$err = '';

// عمليات POST: إنشاء/تعطيل/تفعيل/إعادة باسورد
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $act = $_POST['act'] ?? '';
  try {
    if ($act === 'create') {
      $username = trim($_POST['username'] ?? '');
      $password = trim($_POST['password'] ?? '');
      $role = $_POST['role'] ?? 'cashier';

      if ($username==='' || $password==='') 
        throw new Exception('أدخل اسم مستخدم وباسورد');

      // جِب الدور
      $st = $pdo->prepare("SELECT id FROM roles WHERE name=?");
      $st->execute([$role]);
      $role_id = $st->fetchColumn();
      if (!$role_id) throw new Exception('Role not found');

      // إنشاء المستخدم: حفظ الباسورد في العمودين password و password_hash
      $st = $pdo->prepare("
        INSERT INTO users (username, password, password_hash, role_id, is_active)
        VALUES (?,?,?,?,1)
      ");
      $st->execute([$username, $password, $password, $role_id]);

      $msg = "✅ تم إنشاء المستخدم: $username";
    }

    elseif ($act === 'toggle') {
      $id = (int)$_POST['id'];
      $st = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=?");
      $st->execute([$id]);
      $msg = "تم تغيير حالة المستخدم.";
    }

    elseif ($act === 'resetpw') {
      $id = (int)$_POST['id'];
      $newpw = trim($_POST['newpw'] ?? '');
      if ($newpw==='') throw new Exception('أدخل باسورد جديد');

      // تحديث الباسورد في العمودين
      $st = $pdo->prepare("UPDATE users SET password=?, password_hash=? WHERE id=?");
      $st->execute([$newpw, $newpw, $id]);

      $msg = "تم تعيين باسورد جديد.";
    }

  } catch(Throwable $e) { 
    $err = $e->getMessage(); 
  }
}

// قراءة القائمة
$rows = $pdo->query("
  SELECT u.id, u.username, u.is_active, r.name AS role_name, u.created_at
  FROM users u 
  JOIN roles r ON r.id=u.role_id
  ORDER BY u.id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>إدارة المستخدمين - العزباوية</title>
  <link rel="stylesheet" href="/3zbawyh/assets/style.css">
  <style>
    .muted{color:#666}
    .badge{padding:2px 6px;border-radius:6px;background:#eee}
    input.input[type=password]{direction:ltr}
  </style>
</head>
<body>
<div class="container">
  <nav class="nav">
    <div class="brand">العزباوية — إدارة المستخدمين</div>
    <ul>
      <li><a href="/3zbawyh/public/dashboard.php">اللوحة</a></li>
      <li><a href="/3zbawyh/public/select_category.php">POS</a></li>
      <li><a href="/3zbawyh/public/logout.php">خروج (<?=e(current_user()['username'])?>)</a></li>
    </ul>
  </nav>

  <div class="card">
    <h3>إضافة مستخدم جديد</h3>
    <?php if($msg): ?><div style="color:#060;margin-bottom:8px"><?=e($msg)?></div><?php endif; ?>
    <?php if($err): ?><div style="color:#b00;margin-bottom:8px"><?=e($err)?></div><?php endif; ?>
    <form method="post" class="form-row" style="align-items:flex-end">
      <input type="hidden" name="act" value="create">
      <div style="flex:1">
        <label>اسم المستخدم</label>
        <input class="input" name="username" required>
      </div>
      <div style="flex:1">
        <label>الباسورد</label>
        <input class="input" type="password" name="password" required>
      </div>
      <div>
        <label>الدور</label>
        <select class="input" name="role">
          <option value="cashier">cashier</option>
          <option value="admin">admin</option>
        </select>
      </div>
      <button class="btn" type="submit">إنشاء</button>
    </form>
  </div>

  <div class="card" style="margin-top:12px">
    <h3>قائمة المستخدمين</h3>
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>المستخدم</th>
          <th>الدور</th>
          <th>الحالة</th>
          <th>أُنشئ في</th>
          <th>إجراءات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=e($r['id'])?></td>
          <td><?=e($r['username'])?></td>
          <td><span class="badge"><?=e($r['role_name'])?></span></td>
          <td><?= $r['is_active'] ? '✅ مفعل' : '❌ معطل' ?></td>
          <td class="muted"><?=e($r['created_at'])?></td>
          <td style="display:flex;gap:6px">
            <form method="post" onsubmit="return confirm('تأكيد تغيير الحالة؟')">
              <input type="hidden" name="act" value="toggle">
              <input type="hidden" name="id" value="<?=e($r['id'])?>">
              <button class="btn secondary" type="submit"><?= $r['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
            </form>
            <form method="post" onsubmit="return confirm('تأكيد إعادة الباسورد؟')">
              <input type="hidden" name="act" value="resetpw">
              <input type="hidden" name="id" value="<?=e($r['id'])?>">
              <input class="input" type="password" name="newpw" placeholder="باسورد جديد" style="width:150px">
              <button class="btn" type="submit">إعادة تعيين</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <footer class="footer">
    <small>© <?=date('Y')?> العزباوية</small>
  </footer>
</div>
</body>
</html>
