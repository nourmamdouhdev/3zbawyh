<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
require_role_in_or_redirect(['admin','Manger','owner']);

date_default_timezone_set('Africa/Cairo');
$db = db();
$u  = current_user();

$ROOT_DIR    = realpath(__DIR__ . '/..');
$DEFAULT_BACKUP_DIR  = $ROOT_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'backups';
$BACKUP_URL  = '/3zbawyh/uploads/backups/';
$SETTINGS_FILE = $DEFAULT_BACKUP_DIR . DIRECTORY_SEPARATOR . 'backup_settings.json';
$LOG_FILE_DEFAULT = $DEFAULT_BACKUP_DIR . DIRECTORY_SEPARATOR . 'backup_log.json';

if (!is_dir($DEFAULT_BACKUP_DIR)) { @mkdir($DEFAULT_BACKUP_DIR, 0775, true); }

function safe_basename(string $name): string {
  $name = basename($name);
  $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
  return $name ?: 'backup.sql';
}

function bytes_to_mb($bytes): string {
  return number_format($bytes / (1024 * 1024), 2) . ' MB';
}

function load_json_file(string $path, array $fallback): array {
  if (!is_file($path)) return $fallback;
  $raw = file_get_contents($path);
  $data = json_decode($raw, true);
  return is_array($data) ? array_merge($fallback, $data) : $fallback;
}

function normalize_path(string $path): string {
  $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
  $parts = explode(DIRECTORY_SEPARATOR, $path);
  $clean = [];
  foreach ($parts as $part) {
    if ($part === '' || $part === '.') continue;
    if ($part === '..') { array_pop($clean); continue; }
    $clean[] = $part;
  }
  $drive = '';
  if (isset($clean[0]) && preg_match('/^[A-Za-z]:$/', $clean[0])) {
    $drive = array_shift($clean) . DIRECTORY_SEPARATOR;
  }
  return $drive . implode(DIRECTORY_SEPARATOR, $clean);
}

function resolve_backup_dir(string $root, ?string $input, string $default, &$warning = ''): string {
  $warning = '';
  $input = trim((string)$input);
  if ($input === '') return $default;

  $isAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $input) || str_starts_with($input, '\\') || str_starts_with($input, '/');
  $path = $isAbsolute ? $input : ($root . DIRECTORY_SEPARATOR . $input);
  $path = normalize_path($path);

  $rootNorm = normalize_path($root);
  $prefixOk = stripos($path, $rootNorm) === 0;
  $boundaryOk = $path === $rootNorm || (strlen($path) > strlen($rootNorm) && $path[strlen($rootNorm)] === DIRECTORY_SEPARATOR);
  if (!$prefixOk || !$boundaryOk) {
    $warning = 'المسار لازم يكون داخل مجلد المشروع. تم الرجوع للمسار الافتراضي.';
    return $default;
  }
  return $path;
}

function save_json_file(string $path, array $data): void {
  file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function log_backup(string $logFile, array $entry): void {
  $list = [];
  if (is_file($logFile)) {
    $raw = file_get_contents($logFile);
    $list = json_decode($raw, true);
    if (!is_array($list)) $list = [];
  }
  array_unshift($list, $entry);
  $list = array_slice($list, 0, 50);
  save_json_file($logFile, $list);
}

function export_database(PDO $db, $fh): void {
  $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
  fwrite($fh, "-- Backup for {$dbName}\n");
  fwrite($fh, "-- Generated at: " . date('Y-m-d H:i:s') . "\n\n");
  fwrite($fh, "SET NAMES utf8mb4;\n");
  fwrite($fh, "SET foreign_key_checks=0;\n\n");

  $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($tables as $table) {
    $st = $db->query("SHOW CREATE TABLE `{$table}`");
    $row = $st->fetch(PDO::FETCH_NUM);
    $createSql = $row[1] ?? '';
    if ($createSql === '') continue;

    fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
    fwrite($fh, $createSql . ";\n\n");

    $cols = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $colList = '`' . implode('`,`', $cols) . '`';

    $stmt = $db->query("SELECT * FROM `{$table}`", PDO::FETCH_NUM);
    $batch = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
      $vals = [];
      foreach ($row as $val) {
        if ($val === null) $vals[] = "NULL";
        else $vals[] = $db->quote($val);
      }
      $batch[] = '(' . implode(',', $vals) . ')';
      if (count($batch) >= 100) {
        fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
      }
    }
    if ($batch) {
      fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
    }
    fwrite($fh, "\n");
  }
  fwrite($fh, "SET foreign_key_checks=1;\n");
}

function create_backup_file(PDO $db, string $dir, string $prefix): array {
  set_time_limit(0);
  $file = $prefix . '_' . date('Y-m-d_H-i-s') . '.sql';
  $path = $dir . DIRECTORY_SEPARATOR . $file;
  $fh = fopen($path, 'wb');
  if (!$fh) { throw new Exception('تعذر إنشاء ملف النسخة الاحتياطية.'); }
  export_database($db, $fh);
  fclose($fh);
  return ['file' => $file, 'path' => $path, 'size' => filesize($path)];
}

function execute_sql_file(PDO $db, string $filePath): int {
  set_time_limit(0);
  $count = 0;
  $fh = fopen($filePath, 'r');
  if (!$fh) { throw new Exception('تعذر قراءة ملف SQL.'); }

  $db->exec("SET foreign_key_checks=0");
  $templine = '';
  $inBlockComment = false;

  while (($line = fgets($fh)) !== false) {
    $trim = trim($line);
    if ($trim === '') continue;
    if ($inBlockComment) {
      if (strpos($trim, '*/') !== false) $inBlockComment = false;
      continue;
    }
    if (strpos($trim, '/*') === 0) {
      if (strpos($trim, '*/') === false) $inBlockComment = true;
      continue;
    }
    if (strpos($trim, '--') === 0 || strpos($trim, '#') === 0) continue;
    if (stripos($trim, 'DELIMITER ') === 0) continue;

    $templine .= $line;
    if (substr(rtrim($line), -1) === ';') {
      $db->exec($templine);
      $templine = '';
      $count++;
    }
  }
  if (trim($templine) !== '') {
    $db->exec($templine);
    $count++;
  }
  fclose($fh);
  $db->exec("SET foreign_key_checks=1");
  return $count;
}

$msg = '';
$err = '';
$autoMsg = '';
$lastBackupFile = '';
$lastBackupSize = 0;
$dirWarning = '';
$action = $_POST['action'] ?? '';

$settings = load_json_file($SETTINGS_FILE, [
  'auto_daily' => false,
  'backup_dir' => '',
  'last_auto' => null,
]);

$BACKUP_DIR = resolve_backup_dir($ROOT_DIR, $settings['backup_dir'] ?? '', $DEFAULT_BACKUP_DIR, $dirWarning);
if (!is_dir($BACKUP_DIR)) { @mkdir($BACKUP_DIR, 0775, true); }
$LOG_FILE = $BACKUP_DIR . DIRECTORY_SEPARATOR . 'backup_log.json';
if (!is_file($LOG_FILE) && is_file($LOG_FILE_DEFAULT) && $LOG_FILE !== $LOG_FILE_DEFAULT) {
  @copy($LOG_FILE_DEFAULT, $LOG_FILE);
}

// Auto backup on page load (daily) if enabled
try {
  if (!empty($settings['auto_daily'])) {
    $today = date('Y-m-d');
    if (($settings['last_auto'] ?? '') !== $today) {
      $auto = create_backup_file($db, $BACKUP_DIR, 'auto');
      $settings['last_auto'] = $today;
      save_json_file($SETTINGS_FILE, $settings);
      log_backup($LOG_FILE, [
        'at' => date('Y-m-d H:i:s'),
        'type' => 'تلقائي',
        'file' => $auto['file'],
        'size' => $auto['size'],
        'status' => 'نجح',
        'note' => 'نسخة يومية تلقائية'
      ]);
      $autoMsg = 'تم إنشاء نسخة تلقائية اليوم.';
    }
  }
} catch (Throwable $e) {
  log_backup($LOG_FILE, [
    'at' => date('Y-m-d H:i:s'),
    'type' => 'تلقائي',
    'file' => '',
    'size' => 0,
    'status' => 'فشل',
    'note' => $e->getMessage()
  ]);
}

try {
  if ($action === 'backup_now') {
    $b = create_backup_file($db, $BACKUP_DIR, 'manual');
    $lastBackupFile = $b['file'];
    $lastBackupSize = $b['size'];
    log_backup($LOG_FILE, [
      'at' => date('Y-m-d H:i:s'),
      'type' => 'يدوي',
      'file' => $b['file'],
      'size' => $b['size'],
      'status' => 'نجح',
      'note' => 'إنشاء نسخة الآن'
    ]);
    $msg = 'تم إنشاء نسخة احتياطية بنجاح.';
  }

  if ($action === 'save_settings') {
    $settings['auto_daily'] = isset($_POST['auto_daily']);
    $settings['backup_dir'] = trim($_POST['backup_dir'] ?? '');
    save_json_file($SETTINGS_FILE, $settings);
    $msg = 'تم حفظ الإعدادات.';
  }

  if ($action === 'restore') {
    if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
      throw new Exception('اختر ملف SQL صالح.');
    }
    $ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['sql','txt'], true)) {
      throw new Exception('الملف يجب أن يكون بصيغة SQL.');
    }

    // Pre-restore backup for safety
    $pre = create_backup_file($db, $BACKUP_DIR, 'pre_restore');
    log_backup($LOG_FILE, [
      'at' => date('Y-m-d H:i:s'),
      'type' => 'حفظ قبل الاسترجاع',
      'file' => $pre['file'],
      'size' => $pre['size'],
      'status' => 'نجح',
      'note' => 'نسخة تلقائية قبل الاسترجاع'
    ]);

    $origName = safe_basename($_FILES['sql_file']['name']);
    $destName = 'import_' . date('Y-m-d_H-i-s') . '_' . $origName;
    $destPath = $BACKUP_DIR . DIRECTORY_SEPARATOR . $destName;
    if (!move_uploaded_file($_FILES['sql_file']['tmp_name'], $destPath)) {
      throw new Exception('تعذر حفظ الملف المرفوع.');
    }
    $count = execute_sql_file($db, $destPath);
    log_backup($LOG_FILE, [
      'at' => date('Y-m-d H:i:s'),
      'type' => 'استرجاع',
      'file' => $destName,
      'size' => filesize($destPath),
      'status' => 'نجح',
      'note' => "تم تنفيذ {$count} أمر SQL"
    ]);
    $msg = 'تم استرجاع النسخة الاحتياطية بنجاح.';
  }

} catch (Throwable $e) {
  $err = $e->getMessage();
  log_backup($LOG_FILE, [
    'at' => date('Y-m-d H:i:s'),
    'type' => $action === 'restore' ? 'استرجاع' : 'يدوي',
    'file' => '',
    'size' => 0,
    'status' => 'فشل',
    'note' => $err
  ]);
}

// Download handler
if (!empty($_GET['download'])) {
  $file = safe_basename($_GET['download']);
  $path = $BACKUP_DIR . DIRECTORY_SEPARATOR . $file;
  if (is_file($path)) {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$file.'"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
  }
}

$logEntries = load_json_file($LOG_FILE, []);
$backupFiles = glob($BACKUP_DIR . DIRECTORY_SEPARATOR . '*.sql') ?: [];
usort($backupFiles, function($a,$b){ return filemtime($b) <=> filemtime($a); });

$uploadMax = ini_get('upload_max_filesize');
$postMax   = ini_get('post_max_size');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>النسخ الاحتياطي - العزباوية</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/3zbawyh/assets/style.css">
  <style>
    :root{
      --bg:#f6f7fb; --card:#fff; --bd:#e8e8ef; --ink:#111; --muted:#6b7280;
      --primary:#1f6feb; --primary-ink:#fff; --shadow:0 6px 16px rgba(0,0,0,.06);
      --ok:#137333; --warn:#b26a00; --danger:#b00020;
    }
    *{box-sizing:border-box}
    body{background:var(--bg);color:var(--ink);font-family:Tahoma,Arial,sans-serif;margin:0}
    .page{max-width:1100px;margin:0 auto;padding:16px}
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid var(--bd);border-radius:14px;padding:12px 16px;box-shadow:var(--shadow)}
    .topbar .title{font-weight:800}
    .topbar ul{list-style:none;display:flex;gap:10px;margin:0;padding:0;flex-wrap:wrap}
    .topbar a{text-decoration:none;color:var(--ink);padding:8px 12px;border-radius:10px;background:#f1f2f7}
    .grid{display:grid;gap:14px}
    @media(min-width:820px){.grid-2{grid-template-columns:1fr 1fr}}
    .card{background:var(--card);border:1px solid var(--bd);border-radius:14px;box-shadow:var(--shadow);padding:16px}
    .card h3{margin:0 0 8px}
    .note{font-size:13px;color:var(--muted)}
    .btn{padding:10px 14px;border:0;border-radius:10px;background:var(--primary);color:var(--primary-ink);cursor:pointer}
    .btn.secondary{background:#eef3fb;color:#0b4ea9}
    .btn.warn{background:#f6a623;color:#fff}
    .btn.file{display:inline-flex;align-items:center;gap:8px}
    .notice{padding:10px 12px;border-radius:12px;margin-bottom:10px;font-size:14px}
    .notice.success{background:#ecf7ee;color:#12632e;border:1px solid #cde9d3}
    .notice.error{background:#fff1f1;color:#9b1c1c;border:1px solid #f0c7c7}
    .notice.warn{background:#fff7e6;color:#8a5a00;border:1px dashed #e0b35a}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .input{padding:10px;border:1px solid #ddd;border-radius:10px;width:100%}
    .table-wrap{width:100%;overflow-x:auto;border-radius:12px;border:1px solid var(--bd)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:right;font-size:13px}
    thead th{background:#f8f8fb;font-weight:800}
    .badge{padding:2px 10px;border-radius:999px;background:#f0f0f0}
  </style>
</head>
<body>
<div class="page">
  <nav class="topbar">
    <div class="title">النسخ الاحتياطي — العزباوية</div>
    <ul>
      <li><a href="/3zbawyh/public/dashboard.php">اللوحة</a></li>
      <li><a href="/3zbawyh/public/logout.php">خروج (<?= e($u['username'] ?? '') ?>)</a></li>
    </ul>
  </nav>

  <?php if($autoMsg): ?><div class="card notice success"><?= e($autoMsg) ?></div><?php endif; ?>
  <?php if($msg): ?><div class="card notice success"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="card notice error"><?= e($err) ?></div><?php endif; ?>
  <?php if($dirWarning): ?><div class="card notice warn"><?= e($dirWarning) ?></div><?php endif; ?>

  <div class="grid grid-2" style="margin-top:12px">
    <div class="card">
      <h3>نسخ احتياطي فوري</h3>
      <div class="note">إنشاء نسخة كاملة من قاعدة البيانات الحالية.</div>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="action" value="backup_now">
        <button class="btn file" type="submit">إنشاء نسخة الآن</button>
      </form>
      <?php if($lastBackupFile): ?>
        <div class="note" style="margin-top:10px">
          آخر نسخة: <?= e($lastBackupFile) ?> (<?= e(bytes_to_mb($lastBackupSize)) ?>)
        </div>
        <div style="margin-top:8px">
          <a class="btn secondary" href="?download=<?= urlencode($lastBackupFile) ?>">تحميل النسخة</a>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>استيراد نسخة احتياطية</h3>
      <div class="note">اختر ملف SQL لعمل استرجاع كامل لقاعدة البيانات.</div>
      <form method="post" enctype="multipart/form-data" style="margin-top:12px"
            onsubmit="return confirm('سيتم استبدال البيانات الحالية. هل أنت متأكد؟');">
        <input type="hidden" name="action" value="restore">
        <input class="input" type="file" name="sql_file" accept=".sql,.txt" required>
        <div class="note" style="margin-top:6px">
          الحد الأقصى للرفع: <?= e($uploadMax) ?> — الحد الأقصى للطلب: <?= e($postMax) ?>
        </div>
        <div style="margin-top:10px">
          <button class="btn warn" type="submit">استيراد من ملف</button>
        </div>
      </form>
      <div class="note" style="margin-top:10px">يتم إنشاء نسخة تلقائية قبل الاسترجاع للحماية.</div>
    </div>
  </div>

  <div class="card" style="margin-top:14px">
    <h3>الإعدادات التلقائية</h3>
    <form method="post" class="row">
      <input type="hidden" name="action" value="save_settings">
      <div style="flex:1;min-width:260px">
        <label class="note">مسار الحفظ (داخل المشروع):</label>
        <input class="input" name="backup_dir" placeholder="uploads/backups" value="<?= e($settings['backup_dir'] ?? '') ?>">
        <div class="note" style="margin-top:6px">المسار الحالي: <?= e($BACKUP_DIR) ?></div>
      </div>
      <label style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="auto_daily" <?= !empty($settings['auto_daily']) ? 'checked' : '' ?>>
        تفعيل النسخ اليومي التلقائي
      </label>
      <button class="btn secondary" type="submit">حفظ الإعدادات</button>
    </form>
    <div class="note" style="margin-top:8px">
      ملاحظة: النسخ اليومي يتم عند فتح هذه الصفحة (مرة واحدة كل يوم).
    </div>
  </div>

  <div class="card" style="margin-top:14px">
    <h3>ملفات النسخ المحفوظة</h3>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>الملف</th>
            <th>الحجم</th>
            <th>التاريخ</th>
            <th>إجراء</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($backupFiles as $filePath): $file = basename($filePath); ?>
          <tr>
            <td><?= e($file) ?></td>
            <td><?= e(bytes_to_mb(filesize($filePath))) ?></td>
            <td><?= e(date('Y-m-d H:i', filemtime($filePath))) ?></td>
            <td><a class="btn secondary" href="?download=<?= urlencode($file) ?>">تحميل</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(empty($backupFiles)): ?>
          <tr><td colspan="4" class="note">لا توجد ملفات نسخ محفوظة حتى الآن.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-top:14px">
    <h3>سجل العمليات</h3>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>التاريخ</th>
            <th>النوع</th>
            <th>الحجم</th>
            <th>الحالة</th>
            <th>ملاحظات</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($logEntries as $entry): ?>
          <tr>
            <td><?= e($entry['at'] ?? '') ?></td>
            <td><span class="badge"><?= e($entry['type'] ?? '') ?></span></td>
            <td><?= e(bytes_to_mb((int)($entry['size'] ?? 0))) ?></td>
            <td><?= e($entry['status'] ?? '') ?></td>
            <td><?= e($entry['note'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(empty($logEntries)): ?>
          <tr><td colspan="5" class="note">لا توجد سجلات حتى الآن.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card notice warn" style="margin-top:14px">
    <strong>تنبيه:</strong> النسخة الاحتياطية هنا تخص قاعدة البيانات فقط. لو عندك صور أو ملفات داخل <code>/uploads</code> لازم تتاخد نسخة منفصلة لها.
  </div>
</div>
</body>
</html>
