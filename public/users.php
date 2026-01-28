<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../app/config/db.php';

require_admin_or_redirect();
$pdo = db();
$hasMaxDisc = column_exists($pdo, 'users', 'max_discount_percent');

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';
  try {
    if ($act === 'create') {
      $username = trim($_POST['username'] ?? '');
      $password = trim($_POST['password'] ?? '');
      $role = $_POST['role'] ?? 'cashier';

      if ($username === '' || $password === '') {
        throw new Exception('أدخل اسم مستخدم وباسورد');
      }

      $st = $pdo->prepare("SELECT id FROM roles WHERE name=?");
      $st->execute([$role]);
      $role_id = $st->fetchColumn();
      if (!$role_id) throw new Exception('الدور غير موجود');

      $maxDisc = null;
      if ($hasMaxDisc) {
        $rawDisc = trim($_POST['max_discount_percent'] ?? '');
        if ($rawDisc !== '') {
          if (!is_numeric($rawDisc)) throw new Exception('حد الخصم لازم رقم');
          $maxDisc = (float)$rawDisc;
          if ($maxDisc < 0 || $maxDisc > 100) throw new Exception('حد الخصم يكون بين 0 و 100');
        }
      }

      if ($hasMaxDisc) {
        $st = $pdo->prepare("
          INSERT INTO users (username, password, password_hash, role_id, is_active, max_discount_percent)
          VALUES (?,?,?,?,1,?)
        ");
        $st->execute([$username, $password, $password, $role_id, $maxDisc]);
      } else {
        $st = $pdo->prepare("
          INSERT INTO users (username, password, password_hash, role_id, is_active)
          VALUES (?,?,?,?,1)
        ");
        $st->execute([$username, $password, $password, $role_id]);
      }

      $msg = "تم إنشاء المستخدم: $username";
    } elseif ($act === 'toggle') {
      $id = (int)$_POST['id'];
      $st = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=?");
      $st->execute([$id]);
      $msg = 'تم تغيير حالة المستخدم.';
    } elseif ($act === 'resetpw') {
      $id = (int)$_POST['id'];
      $newpw = trim($_POST['newpw'] ?? '');
      if ($newpw === '') throw new Exception('أدخل باسورد جديد');

      $st = $pdo->prepare("UPDATE users SET password=?, password_hash=? WHERE id=?");
      $st->execute([$newpw, $newpw, $id]);

      $msg = 'تم تعيين باسورد جديد.';
    } elseif ($act === 'set_disc') {
      if (!$hasMaxDisc) throw new Exception('عمود حد الخصم غير موجود في قاعدة البيانات.');
      $id = (int)$_POST['id'];
      $rawDisc = trim($_POST['max_discount_percent'] ?? '');
      if ($rawDisc === '') {
        $maxDisc = null;
      } else {
        if (!is_numeric($rawDisc)) throw new Exception('حد الخصم لازم رقم');
        $maxDisc = (float)$rawDisc;
        if ($maxDisc < 0 || $maxDisc > 100) throw new Exception('حد الخصم يكون بين 0 و 100');
      }
      $st = $pdo->prepare("UPDATE users SET max_discount_percent=? WHERE id=?");
      $st->execute([$maxDisc, $id]);
      $msg = 'تم تحديث حد الخصم.';
    } elseif ($act === 'delete') {
      $id = (int)$_POST['id'];
      if ($id === (int)current_user()['id']) {
        throw new Exception('لا يمكن حذف المستخدم الحالي.');
      }
      $st = $pdo->prepare("DELETE FROM users WHERE id=?");
      $st->execute([$id]);
      $msg = 'تم حذف المستخدم.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$discCol = $hasMaxDisc ? 'u.max_discount_percent' : 'NULL';
$rows = $pdo->query("
  SELECT u.id, u.username, u.is_active, r.name AS role_name, u.created_at, $discCol AS max_discount_percent
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/3zbawyh/assets/style.css">
  <style>
    :root{--bg:#f6f7fb;--card:#fff;--bd:#e8e8ef;--ink:#111;--muted:#6b7280;--shadow:0 6px 16px rgba(0,0,0,.06);--r:14px}
    *{box-sizing:border-box}
    body{background:var(--bg);color:var(--ink);font-family:Tahoma,Arial,sans-serif}
    .page{max-width:1100px;margin:0 auto;padding:16px}
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid var(--bd);border-radius:var(--r);padding:12px 16px;box-shadow:var(--shadow)}
    .topbar .title{font-weight:800}
    .topbar ul{list-style:none;display:flex;gap:10px;margin:0;padding:0;flex-wrap:wrap}
    .topbar a{text-decoration:none;color:var(--ink);padding:8px 12px;border-radius:10px;background:#f1f2f7}
    .card{background:var(--card);border:1px solid var(--bd);border-radius:var(--r);box-shadow:var(--shadow);padding:16px;margin-top:12px}
    .notice{padding:10px 12px;border-radius:12px;margin-bottom:10px;font-size:14px}
    .notice.success{background:#ecf7ee;color:#12632e;border:1px solid #cde9d3}
    .notice.error{background:#fff1f1;color:#9b1c1c;border:1px solid #f0c7c7}
    .notice.warn{background:#fff7e6;color:#8a5a00;border:1px dashed #e0b35a}
    .form-grid{display:grid;grid-template-columns: repeat(4, minmax(140px,1fr));gap:12px;align-items:end}
    .form-grid label{font-size:13px;color:var(--muted)}
    .form-grid .input{width:100%}
    .btn{padding:10px 14px;border:0;border-radius:10px;background:#111;color:#fff;cursor:pointer}
    .btn.secondary{background:#f2f2f2;color:#111}
    .table-wrapper{width:100%;overflow-x:auto;border-radius:12px;border:1px solid var(--bd)}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{padding:12px;border-bottom:1px solid #eee;text-align:right;font-size:14px}
    .table thead th{background:#f8f8fb;font-weight:800}
    .badge{padding:2px 10px;border-radius:999px;background:#f0f0f0}
    .muted{color:var(--muted)}
    input.input[type=password]{direction:ltr}
    .actions-cell{display:grid;gap:8px}
    .action-form{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}
    .action-form input{width:100%}
    @media (max-width: 980px){.form-grid{grid-template-columns: 1fr 1fr}.action-form{grid-template-columns:1fr}}
    @media (max-width: 600px){.page{padding:10px}.form-grid{grid-template-columns:1fr}}
  </style>
  <link rel="stylesheet" href="/3zbawyh/assets/barcode_theme.css">
</head>
<body>
<div class="page">
  <nav class="topbar">
    <div class="title">العزباوية — إدارة المستخدمين</div>
    <ul>
      <li><a href="/3zbawyh/public/dashboard.php">اللوحة</a></li>

      <li><a href="/3zbawyh/public/logout.php">خروج (<?=e(current_user()['username'])?>)</a></li>
    </ul>
  </nav>

  <?php if (!$hasMaxDisc): ?>
    <div class="card notice warn">
      <strong>تنبيه:</strong> عشان تحدد حد الخصم لكل مستخدم، أضف العمود التالي في قاعدة البيانات:
      <div style="direction:ltr;margin-top:6px;font-family:monospace">ALTER TABLE users ADD COLUMN max_discount_percent DECIMAL(5,2) NULL;</div>
    </div>
  <?php endif; ?>

  <div class="card">
    <h3 style="margin-top:0">إضافة مستخدم جديد</h3>
    <?php if($msg): ?><div class="notice success"><?=e($msg)?></div><?php endif; ?>
    <?php if($err): ?><div class="notice error"><?=e($err)?></div><?php endif; ?>
    <form method="post" class="form-grid">
      <input type="hidden" name="act" value="create">
      <div>
        <label>اسم المستخدم</label>
        <input class="input" name="username" required>
      </div>
      <div>
        <label>الباسورد</label>
        <input class="input" type="password" name="password" required>
      </div>
      <div>
        <label>الدور</label>
        <select class="input" name="role">
          <option value="cashier">cashier</option>
          <option value="admin">admin</option>
          <option value="Manger">Manger</option>
        </select>
      </div>
      <?php if ($hasMaxDisc): ?>
      <div>
        <label>حد الخصم (%)</label>
        <input class="input" name="max_discount_percent" inputmode="decimal" placeholder="اختياري">
      </div>
      <?php endif; ?>
      <div>
        <button class="btn" type="submit">إنشاء</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-top:0">قائمة المستخدمين</h3>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>المستخدم</th>
            <th>الدور</th>
            <?php if ($hasMaxDisc): ?>
            <th>حد الخصم (%)</th>
            <?php endif; ?>
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
            <?php if ($hasMaxDisc): ?>
            <td><?= $r['max_discount_percent'] !== null ? e($r['max_discount_percent']).'%' : 'افتراضي' ?></td>
            <?php endif; ?>
            <td><?= $r['is_active'] ? 'مفعل' : 'معطل' ?></td>
            <td class="muted"><?=e($r['created_at'])?></td>
            <td class="actions-cell">
              <form method="post" onsubmit="return confirm('تأكيد تغيير الحالة؟')">
                <input type="hidden" name="act" value="toggle">
                <input type="hidden" name="id" value="<?=e($r['id'])?>">
                <button class="btn secondary" type="submit"><?= $r['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
              </form>
              <form method="post" onsubmit="return confirm('تأكيد حذف المستخدم نهائيًا؟')" >
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?=e($r['id'])?>">
                <button class="btn secondary" type="submit">حذف نهائي</button>
              </form>
              <form method="post" onsubmit="return confirm('تأكيد إعادة الباسورد؟')" class="action-form">
                <input type="hidden" name="act" value="resetpw">
                <input type="hidden" name="id" value="<?=e($r['id'])?>">
                <input class="input" type="password" name="newpw" placeholder="باسورد جديد">
                <button class="btn" type="submit">تعيين باسورد</button>
              </form>
              <?php if ($hasMaxDisc): ?>
              <form method="post" class="action-form">
                <input type="hidden" name="act" value="set_disc">
                <input type="hidden" name="id" value="<?=e($r['id'])?>">
                <input class="input" name="max_discount_percent" inputmode="decimal" placeholder="افتراضي" value="<?= $r['max_discount_percent'] !== null ? e($r['max_discount_percent']) : '' ?>">
                <button class="btn" type="submit">حد الخصم</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <footer class="footer">
    <small>© <?=date('Y')?> العزباوية</small>
  </footer>
</div>
</body>
</html>
