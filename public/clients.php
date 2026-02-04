
<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
require_role_in_or_redirect(['admin','Manger']);

date_default_timezone_set('Africa/Cairo');
$db = db();
$u  = current_user();
$role = strtolower((string)($u['role'] ?? ''));
$can_manage = in_array($role, ['admin','manager','manger'], true);

/* ===== Helpers ===== */
function cols_of(PDO $db, string $table): array {
  $st=$db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->execute([$table]);
  return $st->fetchAll(PDO::FETCH_COLUMN);
}
function pick_col(array $cols, array $prefs): ?string {
  foreach ($prefs as $p) { if (in_array($p, $cols, true)) return $p; }
  return null;
}
function detect_date_col(PDO $db, string $table, array $prefs=['created_at','invoice_date','created_on','date','ts','timestamp','time']): ?string {
  $st=$db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND DATA_TYPE IN ('datetime','timestamp','date','time')");
  $st->execute([$table]);
  $rows=$st->fetchAll(PDO::FETCH_KEY_PAIR);
  if(!$rows) return null;
  foreach ($prefs as $p) { if (isset($rows[$p])) return $p; }
  return array_key_first($rows);
}
function nf($n){ return number_format((float)$n, (floor($n)===(float)$n?0:2), '.', ','); }
function e2($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function normalize_phone_for_wa(string $phone): string {
  $p = trim($phone);
  $p = preg_replace('/[^\d\+]/', '', $p);
  if ($p !== '' && $p[0] === '+') return substr($p, 1);
  return $p;
}
function wa_link(string $phone, string $msg): string {
  $p = normalize_phone_for_wa($phone);
  if ($p === '') return '#';
  return 'https://wa.me/'.$p.'?text='.rawurlencode($msg);
}
function normalize_phone_digits($s){
  return preg_replace('/\D+/', '', (string)$s);
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
function normalize_collector_id(int $rawId, array $allowed, ?int $existingId=null): ?int {
  if ($rawId <= 0) return null;
  if (isset($allowed[$rawId])) return $rawId;
  if ($existingId !== null && $rawId === $existingId) return $rawId;
  return null;
}
function method_label(string $m): string {
  $m = strtolower(trim((string)$m));
  $map = [
    'cash' => 'نقدي',
    'visa' => 'بطاقة / فيزا',
    'instapay' => 'InstaPay',
    'vodafone_cash' => 'Vodafone Cash',
    'transfer' => 'تحويل',
    'card' => 'بطاقة',
    'other' => 'أخرى',
    'adjustment' => 'تعديل رصيد',
    'debt' => 'مديونية خارجية',
  ];
  return $map[$m] ?? ($m !== '' ? $m : '—');
}

/* ===== Schema checks / ensure ===== */
$schemaErrors = [];
try {
  if (!table_exists($db, 'customers')) {
    $schemaErrors[] = "جدول customers غير موجود.";
  }
} catch (Throwable $e) {
  $schemaErrors[] = "تعذر فحص جدول customers: ".$e->getMessage();
}

$customerCols = table_exists($db, 'customers') ? cols_of($db, 'customers') : [];
$custNameCol  = pick_col($customerCols, ['name','customer_name','full_name']) ?? 'name';
$custPhoneCol = pick_col($customerCols, ['phone','mobile','customer_phone']) ?? 'phone';

$alterSQLs = [];
if (table_exists($db, 'customers')) {
  if (!column_exists($db, 'customers', 'address')) {
    $alterSQLs['address'] = "ALTER TABLE customers ADD COLUMN address VARCHAR(255) NULL";
  }
  if (!column_exists($db, 'customers', 'notes')) {
    $alterSQLs['notes'] = "ALTER TABLE customers ADD COLUMN notes TEXT NULL";
  }
  if (!column_exists($db, 'customers', 'credit_limit')) {
    $alterSQLs['credit_limit'] = "ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(12,2) NULL";
  }
  if (!column_exists($db, 'customers', 'status')) {
    $alterSQLs['status'] = "ALTER TABLE customers ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'";
  }
  if (!column_exists($db, 'customers', 'collector_id')) {
    $alterSQLs['collector_id'] = "ALTER TABLE customers ADD COLUMN collector_id INT UNSIGNED NULL";
  }
}

function find_customer_by_phone(PDO $db, string $custPhoneCol, string $custNameCol, string $phone, ?int $excludeId=null): ?array {
  if (!table_exists($db, 'customers')) return null;
  $digits = normalize_phone_digits($phone);
  if ($digits === '') return null;
  $variants = phone_variants($digits);
  $expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.`$custPhoneCol`,'+',''),' ',''),'-',''),'(',''),')','')";
  foreach ($variants as $d) {
    $sql = "SELECT c.id, c.`$custNameCol` AS name, c.`$custPhoneCol` AS phone
            FROM customers c
            WHERE c.`$custPhoneCol` IS NOT NULL AND $expr = ?";
    $params = [$d];
    if ($excludeId) { $sql .= " AND c.id <> ?"; $params[] = $excludeId; }
    $sql .= " LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  return null;
}

foreach ($alterSQLs as $k => $sql) {
  try { $db->exec($sql); } catch (Throwable $e) { $schemaErrors[] = $sql." — ".$e->getMessage(); }
}

$createPaymentsSQL = "
CREATE TABLE IF NOT EXISTS client_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(32) NOT NULL,
  reference VARCHAR(120) NULL,
  notes TEXT NULL,
  cashier_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at DATETIME NULL,
  voided_by INT UNSIGNED NULL,
  void_reason VARCHAR(255) NULL,
  KEY idx_client (client_id),
  KEY idx_invoice (invoice_id),
  KEY idx_cashier (cashier_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$createSettingsSQL = "
CREATE TABLE IF NOT EXISTS client_settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` VARCHAR(255) NULL,
  updated_at DATETIME NULL,
  updated_by INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$createAuditSQL = "
CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NULL,
  action VARCHAR(32) NOT NULL,
  user_id INT UNSIGNED NULL,
  before_json TEXT NULL,
  after_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity (entity, entity_id),
  KEY idx_user (user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

foreach ([$createPaymentsSQL,$createSettingsSQL,$createAuditSQL] as $sql) {
  try { $db->exec($sql); } catch (Throwable $e) { $schemaErrors[] = $sql." — ".$e->getMessage(); }
}

/* ===== Collectors (المحصل) ===== */
$hasCollectorCol = table_exists($db, 'customers') && column_exists($db, 'customers', 'collector_id');
$userCols = table_exists($db, 'users') ? cols_of($db, 'users') : [];
$collectorOptions = [];
if ($userCols) {
  $nameExpr = in_array('username', $userCols, true) ? 'u.username' : (in_array('name',$userCols,true) ? 'u.name' : "CONCAT('مستخدم #', u.id)");
  $joins = '';
  $roleExpr = "''";
  $rolesTable = table_exists($db, 'roles');
  $rolesHasName = $rolesTable && column_exists($db, 'roles', 'name');
  $usersHasRoleId = in_array('role_id', $userCols, true);
  if ($rolesHasName && $usersHasRoleId) {
    $joins = " LEFT JOIN roles r ON r.id = u.role_id ";
    $roleExpr = "r.name";
  } elseif (in_array('role', $userCols, true)) {
    $roleExpr = "u.role";
  }

  $where = [];
  if (in_array('is_active', $userCols, true)) $where[] = "u.is_active = 1";
  if ($roleExpr !== "''") $where[] = "LOWER($roleExpr) IN ('admin','manager','manger')";
  $sql = "SELECT u.id, $nameExpr AS name, $roleExpr AS role
          FROM users u $joins
          ".($where ? ("WHERE ".implode(' AND ', $where)) : "")."
          ORDER BY name ASC, u.id ASC";
  try {
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      $label = trim((string)$r['name']);
      if ($label === '') $label = 'مستخدم #'.(int)$r['id'];
      $roleLabel = trim((string)($r['role'] ?? ''));
      if ($roleLabel !== '') $label .= ' ('.$roleLabel.')';
      $collectorOptions[(int)$r['id']] = $label;
    }
  } catch (Throwable $e) {
    $schemaErrors[] = "تعذر جلب المحصلين: ".$e->getMessage();
  }
}

/* ===== Settings ===== */
function setting_get(PDO $db, string $key, string $default=''): string {
  try {
    $st = $db->prepare("SELECT `value` FROM client_settings WHERE `key`=? LIMIT 1");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : (string)$v;
  } catch (Throwable $e) {
    return $default;
  }
}
function setting_set(PDO $db, string $key, string $value, int $userId): void {
  try {
    $st = $db->prepare("INSERT INTO client_settings (`key`,`value`,updated_at,updated_by) VALUES (?,?,NOW(),?)
                        ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW(), updated_by=VALUES(updated_by)");
    $st->execute([$key,$value,$userId]);
  } catch (Throwable $e) {
    // ignore if table missing
  }
}
function audit_log(PDO $db, string $entity, ?int $entityId, string $action, ?int $userId, $before, $after): void {
  if (!table_exists($db, 'audit_log')) return;
  try {
    $st = $db->prepare("INSERT INTO audit_log (entity, entity_id, action, user_id, before_json, after_json, created_at)
                        VALUES (?,?,?,?,?,?,NOW())");
    $st->execute([
      $entity,
      $entityId,
      $action,
      $userId,
      $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
      $after  === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
    ]);
  } catch (Throwable $e) {
    // ignore if table missing
  }
}

$settings = [
  'inactive_enabled' => (int)setting_get($db, 'inactive_enabled', '1'),
  'inactive_days' => (int)setting_get($db, 'inactive_days', '30'),
  'inactive_exclude_blocked' => (int)setting_get($db, 'inactive_exclude_blocked', '1'),
  'inactive_exclude_no_phone' => (int)setting_get($db, 'inactive_exclude_no_phone', '1'),
];

/* ===== Actions ===== */
$msg = null; $err = null;
$action = $_POST['action'] ?? '';
try {
  if ($action === 'client_create') {
    $name  = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $collectorRaw = (int)($_POST['collector_id'] ?? 0);
    $collectorId = null;
    if ($hasCollectorCol) {
      $collectorId = normalize_collector_id($collectorRaw, $collectorOptions, null);
      if ($collectorRaw > 0 && $collectorId === null) throw new Exception('المحصل غير صحيح.');
    }
    if ($name === '' || $phone === '') throw new Exception('اسم العميل والموبايل مطلوبين.');

    $dup = find_customer_by_phone($db, $custPhoneCol, $custNameCol, $phone, null);
    if ($dup) {
      $cid = (int)$dup['id'];
      $sets = [];
      $vals = [];
      if ($name !== '') { $sets[] = "$custNameCol=?"; $vals[] = $name; }
      if (column_exists($db, 'customers', 'address') && $address !== '') { $sets[]='address=?'; $vals[]=$address; }
      if (column_exists($db, 'customers', 'notes') && $notes !== '') { $sets[]='notes=?'; $vals[]=$notes; }
      if ($hasCollectorCol && $collectorId !== null) { $sets[]='collector_id=?'; $vals[]=$collectorId; }
      if ($sets) {
        $vals[] = $cid;
        $db->prepare("UPDATE customers SET ".implode(',', $sets)." WHERE id=?")->execute($vals);
      }
      $msg = 'الرقم مسجّل بالفعل، تم اختيار العميل الموجود.';
      $_GET['id'] = $cid;
      $action = '';
    } else {
    $fields = [$custNameCol, $custPhoneCol];
    $vals   = [$name, $phone];
    if (column_exists($db, 'customers', 'address')) { $fields[]='address'; $vals[]=$address !== '' ? $address : null; }
    if (column_exists($db, 'customers', 'notes'))   { $fields[]='notes';   $vals[]=$notes !== '' ? $notes : null; }
    if (column_exists($db, 'customers', 'status'))  { $fields[]='status';  $vals[]='active'; }
    if ($hasCollectorCol) { $fields[]='collector_id'; $vals[]=$collectorId; }

    $placeholders = implode(',', array_fill(0, count($vals), '?'));
    $sql = "INSERT INTO customers (".implode(',', $fields).") VALUES ($placeholders)";
    $db->prepare($sql)->execute($vals);
    $newId = (int)$db->lastInsertId();
    audit_log($db, 'customer', $newId, 'create', (int)$u['id'], null, ['name'=>$name,'phone'=>$phone,'collector_id'=>$collectorId]);
    $msg = 'تم إضافة العميل بنجاح.';
    $_GET['id'] = $newId;
    }
  }

  if ($action === 'client_update') {
    $cid = (int)($_POST['client_id'] ?? 0);
    if ($cid <= 0) throw new Exception('العميل غير صحيح.');
    $name  = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $credit_limit = trim((string)($_POST['credit_limit'] ?? ''));
    $status = ($_POST['status'] ?? 'active') === 'blocked' ? 'blocked' : 'active';
    $collectorRaw = (int)($_POST['collector_id'] ?? 0);

    if ($name === '' || $phone === '') throw new Exception('اسم العميل والموبايل مطلوبين.');

    $dup = find_customer_by_phone($db, $custPhoneCol, $custNameCol, $phone, $cid);
    if ($dup) {
      throw new Exception('هذا الرقم مسجّل لعميل آخر بالفعل.');
    }

    $before = $db->prepare("SELECT * FROM customers WHERE id=?");
    $before->execute([$cid]);
    $beforeRow = $before->fetch(PDO::FETCH_ASSOC);
    $existingCollectorId = $hasCollectorCol ? (int)($beforeRow['collector_id'] ?? 0) : null;
    $collectorId = null;
    if ($hasCollectorCol) {
      $collectorId = normalize_collector_id($collectorRaw, $collectorOptions, $existingCollectorId);
      if ($collectorRaw > 0 && $collectorId === null) throw new Exception('المحصل غير صحيح.');
    }

    $sets = ["$custNameCol=?", "$custPhoneCol=?"];
    $vals = [$name, $phone];
    if (column_exists($db, 'customers', 'address')) { $sets[]='address=?'; $vals[]=$address !== '' ? $address : null; }
    if (column_exists($db, 'customers', 'notes'))   { $sets[]='notes=?';   $vals[]=$notes !== '' ? $notes : null; }
    if ($hasCollectorCol) { $sets[]='collector_id=?'; $vals[]=$collectorId; }
    if ($can_manage && column_exists($db, 'customers', 'credit_limit')) {
      $sets[]='credit_limit=?'; $vals[]=$credit_limit !== '' ? (float)$credit_limit : null;
    }
    if ($can_manage && column_exists($db, 'customers', 'status')) {
      $sets[]='status=?'; $vals[]=$status;
    }
    $vals[] = $cid;

    $sql = "UPDATE customers SET ".implode(',', $sets)." WHERE id=?";
    $db->prepare($sql)->execute($vals);
    audit_log($db, 'customer', $cid, 'update', (int)$u['id'], $beforeRow, ['name'=>$name,'phone'=>$phone,'address'=>$address,'notes'=>$notes,'credit_limit'=>$credit_limit,'status'=>$status,'collector_id'=>$collectorId]);
    $msg = 'تم تحديث بيانات العميل.';
  }

  if ($action === 'payment_add') {
    $cid = (int)($_POST['client_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = strtolower(trim((string)($_POST['method'] ?? 'cash')));
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($cid <= 0) throw new Exception('العميل غير صحيح.');
    if ($amount == 0) throw new Exception('قيمة الدفعة مطلوبة.');
    if ($method === 'adjustment' && !$can_manage) throw new Exception('غير مسموح بتعديلات الرصيد.');

    $allowed = ['cash','visa','instapay','vodafone_cash','transfer','card','other','adjustment','debt'];
    if (!in_array($method, $allowed, true)) $method = 'other';
    if ($method === 'debt') throw new Exception('استخدم نموذج المديونية الخارجية.');

    if ($method !== 'adjustment' && $amount < 0) {
      throw new Exception('قيمة الدفعة يجب أن تكون موجبة.');
    }

    if ($invoice_id > 0) {
      $st = $db->prepare("SELECT id FROM sales_invoices WHERE id=? AND customer_id=? LIMIT 1");
      $st->execute([$invoice_id, $cid]);
      if (!$st->fetchColumn()) throw new Exception('الفاتورة غير مرتبطة بهذا العميل.');
    }

    $db->prepare("INSERT INTO client_payments (client_id, invoice_id, amount, method, reference, notes, cashier_id, created_at)
                  VALUES (?,?,?,?,?,?,?,NOW())")
       ->execute([$cid, $invoice_id ?: null, $amount, $method, $reference ?: null, $notes ?: null, (int)$u['id']]);
    $pid = (int)$db->lastInsertId();
    audit_log($db, 'client_payment', $pid, 'create', (int)$u['id'], null, ['client_id'=>$cid,'amount'=>$amount,'method'=>$method,'invoice_id'=>$invoice_id]);
    $msg = 'تم تسجيل الدفعة بنجاح.';
  }

  if ($action === 'debt_add') {
    if (!$can_manage) throw new Exception('غير مسموح بإضافة مديونية.');
    $cid = (int)($_POST['client_id'] ?? 0);
    $amount = (float)($_POST['debt_amount'] ?? 0);
    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($cid <= 0) throw new Exception('العميل غير صحيح.');
    if ($amount <= 0) throw new Exception('قيمة المديونية مطلوبة.');

    $debtAmount = -abs($amount);
    $db->prepare("INSERT INTO client_payments (client_id, invoice_id, amount, method, reference, notes, cashier_id, created_at)
                  VALUES (?,?,?,?,?,?,?,NOW())")
       ->execute([$cid, null, $debtAmount, 'debt', $reference ?: null, $notes ?: null, (int)$u['id']]);
    $pid = (int)$db->lastInsertId();
    audit_log($db, 'client_payment', $pid, 'create', (int)$u['id'], null, ['client_id'=>$cid,'amount'=>$debtAmount,'method'=>'debt','invoice_id'=>null]);
    $msg = 'تم إضافة مديونية خارجية.';
  }

  if ($action === 'payment_void') {
    if (!$can_manage) throw new Exception('غير مسموح بإلغاء الدفعات.');
    $pid = (int)($_POST['payment_id'] ?? 0);
    $reason = trim((string)($_POST['void_reason'] ?? ''));
    if ($pid <= 0) throw new Exception('الدفعة غير صحيحة.');

    $before = $db->prepare("SELECT * FROM client_payments WHERE id=?");
    $before->execute([$pid]);
    $row = $before->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('الدفعة غير موجودة.');

    $db->prepare("UPDATE client_payments SET voided_at=NOW(), voided_by=?, void_reason=? WHERE id=?")
       ->execute([(int)$u['id'], $reason ?: 'إلغاء إداري', $pid]);
    audit_log($db, 'client_payment', $pid, 'void', (int)$u['id'], $row, ['void_reason'=>$reason]);
    $msg = 'تم إلغاء الدفعة.';
  }

  if ($action === 'settings_save') {
    if (!$can_manage) throw new Exception('غير مسموح بتعديل الإعدادات.');
    $inactive_enabled = isset($_POST['inactive_enabled']) ? '1' : '0';
    $inactive_days = max(1, (int)($_POST['inactive_days'] ?? 30));
    $exclude_blocked = isset($_POST['inactive_exclude_blocked']) ? '1' : '0';
    $exclude_no_phone = isset($_POST['inactive_exclude_no_phone']) ? '1' : '0';
    setting_set($db, 'inactive_enabled', $inactive_enabled, (int)$u['id']);
    setting_set($db, 'inactive_days', (string)$inactive_days, (int)$u['id']);
    setting_set($db, 'inactive_exclude_blocked', $exclude_blocked, (int)$u['id']);
    setting_set($db, 'inactive_exclude_no_phone', $exclude_no_phone, (int)$u['id']);
    $settings['inactive_enabled'] = (int)$inactive_enabled;
    $settings['inactive_days'] = (int)$inactive_days;
    $settings['inactive_exclude_blocked'] = (int)$exclude_blocked;
    $settings['inactive_exclude_no_phone'] = (int)$exclude_no_phone;
    $msg = 'تم حفظ الإعدادات.';
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

/* ===== Data ===== */
$search = trim((string)($_GET['q'] ?? ''));
$clientId = (int)($_GET['id'] ?? 0);

$invCols = table_exists($db, 'sales_invoices') ? cols_of($db, 'sales_invoices') : [];
$invIdCol   = pick_col($invCols, ['id','invoice_id']) ?? 'id';
$invNoCol   = pick_col($invCols, ['invoice_no','number','invoice_number','code']) ?? $invIdCol;
$invDateCol = detect_date_col($db, 'sales_invoices') ?? $invIdCol;
$invTotalCol = pick_col($invCols, ['total','grand_total','amount','invoice_total']) ?? null;
$invPaidCol = pick_col($invCols, ['paid_amount','paid','payment_total']) ?? null;
$invPayMethodCol = pick_col($invCols, ['payment_method']) ?? null;
$invIsCreditCol = in_array('is_credit', $invCols, true) ? 'is_credit' : null;
$invDueCol = in_array('credit_due_date', $invCols, true) ? 'credit_due_date' : null;

$cashierNameExpr = in_array('username', $userCols, true) ? 'u.username' : (in_array('name',$userCols,true) ? 'u.name' : "CONCAT('كاشير #', u.id)");

/* Clients list */
$clients = [];
if (table_exists($db,'customers')) {
  $like = "%$search%";
  $sql = "SELECT c.id, c.`$custNameCol` AS name, c.`$custPhoneCol` AS phone,
                 ".(column_exists($db,'customers','status') ? "c.status" : "'active' AS status").",
                 ".(column_exists($db,'customers','credit_limit') ? "c.credit_limit" : "NULL AS credit_limit").",
                 ".($hasCollectorCol ? "c.collector_id" : "NULL AS collector_id")."
          FROM customers c
          WHERE (?='' OR c.`$custNameCol` LIKE ? OR c.`$custPhoneCol` LIKE ?)
          ORDER BY c.`$custNameCol` ASC
          LIMIT 300";
  $st = $db->prepare($sql);
  $st->execute([$search,$like,$like]);
  $clients = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Credit totals per client */
$creditTotals = [];
$oldestCredit = [];
$creditPaidAtSale = [];
if (table_exists($db,'sales_invoices')) {
  $dateExpr = "si.`$invDateCol`";
  $totalExpr = $invTotalCol ? "si.`$invTotalCol`" : "0";
  if (!$invTotalCol && table_exists($db,'sales_items')) {
    $LINE_TOT = "COALESCE(it.line_total, it.qty*it.unit_price)";
    $totalExpr = "SUM($LINE_TOT)";
  }
  $creditWhere = [];
  if ($invIsCreditCol) $creditWhere[] = "si.`$invIsCreditCol` = 1";
  if ($invPayMethodCol) $creditWhere[] = "LOWER(TRIM(si.`$invPayMethodCol`)) IN ('agel','agyl')";
  $creditWhereSql = $creditWhere ? ('('.implode(' OR ', $creditWhere).')') : "0";
  $paidSaleExpr = ($invIsCreditCol && $invPaidCol) ? "COALESCE(SUM(CASE WHEN si.`$invIsCreditCol`=1 THEN COALESCE(si.`$invPaidCol`,0) ELSE 0 END),0)" : "0";

  $sql = "SELECT si.customer_id AS client_id,
                 COALESCE(SUM($totalExpr),0) AS credit_total,
                 MIN($dateExpr) AS oldest_date,
                 $paidSaleExpr AS paid_at_sale
          FROM sales_invoices si
          ".(!$invTotalCol && table_exists($db,'sales_items') ? "LEFT JOIN sales_items it ON it.invoice_id = si.`$invIdCol`" : "")."
          WHERE si.customer_id IS NOT NULL
            AND $creditWhereSql
          GROUP BY si.customer_id";
  $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $creditTotals[(int)$r['client_id']] = (float)$r['credit_total'];
    $oldestCredit[(int)$r['client_id']] = $r['oldest_date'] ?: null;
    $creditPaidAtSale[(int)$r['client_id']] = (float)($r['paid_at_sale'] ?? 0);
  }
}

/* Payments totals per client */
$paymentTotals = [];
if (table_exists($db,'client_payments')) {
  $rows = $db->query("SELECT client_id, COALESCE(SUM(amount),0) AS paid_total
                      FROM client_payments
                      WHERE voided_at IS NULL
                      GROUP BY client_id")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $paymentTotals[(int)$r['client_id']] = (float)$r['paid_total'];
  }
}

/* Selected client details */
$client = null;
if ($clientId > 0 && table_exists($db,'customers')) {
  $st = $db->prepare("SELECT * FROM customers WHERE id=?");
  $st->execute([$clientId]);
  $client = $st->fetch(PDO::FETCH_ASSOC);
  if (!$client) $clientId = 0;
}

/* Last purchase + summary for selected client */
$lastInvoice = null;
$total30 = 0.0;
$daysSince = null;
$clientInvoices = [];
$invoicePayments = [];
$creditInvoices = [];
$paymentsList = [];
$outstanding = 0.0;
$lastPurchaseDate = null;
$oldestCreditDate = null;

if ($clientId > 0 && table_exists($db,'sales_invoices')) {
  $dateExpr = "si.`$invDateCol`";
  $totalExpr = $invTotalCol ? "si.`$invTotalCol`" : "0";
  $joinItems = '';
  if (!$invTotalCol && table_exists($db,'sales_items')) {
    $LINE_TOT = "COALESCE(it.line_total, it.qty*it.unit_price)";
    $totalExpr = "SUM($LINE_TOT)";
    $joinItems = "LEFT JOIN sales_items it ON it.invoice_id = si.`$invIdCol`";
  }

  // last invoice
  $sql = "SELECT si.`$invIdCol` AS id, si.`$invNoCol` AS invoice_no,
                 $dateExpr AS invoice_date, $totalExpr AS total,
                 ".($invPayMethodCol ? "si.`$invPayMethodCol` AS payment_method" : "NULL AS payment_method")."
          FROM sales_invoices si
          $joinItems
          WHERE si.customer_id = ?
          GROUP BY si.`$invIdCol`
          ORDER BY $dateExpr DESC
          LIMIT 1";
  $st = $db->prepare($sql);
  $st->execute([$clientId]);
  $lastInvoice = $st->fetch(PDO::FETCH_ASSOC);
  $lastPurchaseDate = $lastInvoice['invoice_date'] ?? null;

  if ($lastPurchaseDate) {
    $d1 = new DateTime($lastPurchaseDate);
    $d2 = new DateTime();
    $daysSince = (int)$d1->diff($d2)->format('%a');
  }

  // total purchases in last 30 days
  $from = (new DateTime())->modify('-30 days')->format('Y-m-d 00:00:00');
  $to   = (new DateTime())->format('Y-m-d 23:59:59');
  $sql = "SELECT COALESCE(SUM($totalExpr),0) AS total_30
          FROM sales_invoices si
          $joinItems
          WHERE si.customer_id = ?
            AND $dateExpr BETWEEN ? AND ?";
  $st = $db->prepare($sql);
  $st->execute([$clientId, $from, $to]);
  $total30 = (float)$st->fetchColumn();

  // invoices list
  $sql = "SELECT si.`$invIdCol` AS id, si.`$invNoCol` AS invoice_no,
                 $dateExpr AS invoice_date, $totalExpr AS total,
                 ".($invPayMethodCol ? "si.`$invPayMethodCol` AS payment_method" : "NULL AS payment_method").",
                 ".($invPaidCol ? "si.`$invPaidCol` AS paid_amount" : "NULL AS paid_amount").",
                 ".($invIsCreditCol ? "si.`$invIsCreditCol` AS is_credit" : "NULL AS is_credit").",
                 ".($invDueCol ? "si.`$invDueCol` AS credit_due_date" : "NULL AS credit_due_date").",
                 $cashierNameExpr AS cashier_name
          FROM sales_invoices si
          $joinItems
          LEFT JOIN users u ON u.id = si.cashier_id
          WHERE si.customer_id = ?
          GROUP BY si.`$invIdCol`
          ORDER BY $dateExpr DESC";
  $st = $db->prepare($sql);
  $st->execute([$clientId]);
  $clientInvoices = $st->fetchAll(PDO::FETCH_ASSOC);

  // payments by invoice
  if (table_exists($db,'client_payments')) {
    $st = $db->prepare("SELECT invoice_id, COALESCE(SUM(amount),0) AS paid
                        FROM client_payments
                        WHERE client_id=? AND invoice_id IS NOT NULL AND voided_at IS NULL
                        GROUP BY invoice_id");
    $st->execute([$clientId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $invoicePayments[(int)$r['invoice_id']] = (float)$r['paid'];
    }
  }

  // credit invoices list
  foreach ($clientInvoices as $inv) {
    $pm = strtolower(trim((string)($inv['payment_method'] ?? '')));
    if ($pm === 'agyl') $pm = 'agel';
    $isCreditInv = ((int)($inv['is_credit'] ?? 0) === 1) || ($pm === 'agel');
    $total = (float)$inv['total'];
    $paidFromPayments = $invoicePayments[(int)$inv['id']] ?? 0.0;
    $paidAtSale = ((int)($inv['is_credit'] ?? 0) === 1) ? (float)($inv['paid_amount'] ?? 0) : 0.0;
    $paidTotal = $paidAtSale + $paidFromPayments;
    if ($isCreditInv) {
      $remaining = $total - $paidTotal;
      if ($remaining > 0.009) {
        $creditInvoices[] = $inv + [
          'remaining' => $remaining,
          'paid_from_payments' => $paidFromPayments,
          'paid_at_sale' => $paidAtSale
        ];
      }
    }
  }
  $oldestCreditDate = null;
  foreach ($creditInvoices as $ci) {
    $d = $ci['invoice_date'] ?? null;
    if ($d && (!$oldestCreditDate || $d < $oldestCreditDate)) {
      $oldestCreditDate = $d;
    }
  }

  $creditTotal = $creditTotals[$clientId] ?? 0.0;
  $paidTotal = ($paymentTotals[$clientId] ?? 0.0) + ($creditPaidAtSale[$clientId] ?? 0.0);
  $outstanding = $creditTotal - $paidTotal;

  // payments list
  $manualPays = [];
  if (table_exists($db,'client_payments')) {
    $sql = "SELECT cp.*, si.`$invNoCol` AS invoice_no, si.`$invDateCol` AS invoice_date,
                   $cashierNameExpr AS cashier_name
            FROM client_payments cp
            LEFT JOIN sales_invoices si ON si.`$invIdCol` = cp.invoice_id
            LEFT JOIN users u ON u.id = cp.cashier_id
            WHERE cp.client_id = ?
            ORDER BY cp.created_at DESC";
    $st = $db->prepare($sql);
    $st->execute([$clientId]);
    $manualPays = $st->fetchAll(PDO::FETCH_ASSOC);
  }

  $invoicePays = [];
  if ($invPayMethodCol) {
    $pmCol = "si.`$invPayMethodCol`";
    $amountExpr = $invPaidCol ? "si.`$invPaidCol`" : ($invTotalCol ? "si.`$invTotalCol`" : "0");
    $sql = "SELECT si.`$invIdCol` AS invoice_id, si.`$invNoCol` AS invoice_no,
                   $dateExpr AS paid_at, $pmCol AS method, $amountExpr AS amount,
                   $cashierNameExpr AS cashier_name
            FROM sales_invoices si
            LEFT JOIN users u ON u.id = si.cashier_id
            WHERE si.customer_id = ?
              AND $pmCol IS NOT NULL
              AND LOWER(TRIM($pmCol)) NOT IN ('agel','agyl')";
    $st = $db->prepare($sql);
    $st->execute([$clientId]);
    $invoicePays = $st->fetchAll(PDO::FETCH_ASSOC);
  }

  foreach ($manualPays as $p) {
    $amt = (float)($p['amount'] ?? 0);
    $src = ($amt < 0) ? 'debt' : 'collection';
    $paymentsList[] = [
      'source' => $src,
      'id' => (int)$p['id'],
      'amount' => $amt,
      'method' => $p['method'],
      'date' => $p['created_at'],
      'cashier_name' => $p['cashier_name'] ?? '',
      'invoice_no' => $p['invoice_no'] ?? '',
      'invoice_id' => $p['invoice_id'] ?? null,
      'reference' => $p['reference'] ?? '',
      'notes' => $p['notes'] ?? '',
      'voided_at' => $p['voided_at'] ?? null,
    ];
  }
  foreach ($invoicePays as $p) {
    $paymentsList[] = [
      'source' => 'invoice',
      'id' => (int)$p['invoice_id'],
      'amount' => (float)$p['amount'],
      'method' => $p['method'],
      'date' => $p['paid_at'],
      'cashier_name' => $p['cashier_name'] ?? '',
      'invoice_no' => $p['invoice_no'] ?? '',
      'invoice_id' => $p['invoice_id'] ?? null,
      'reference' => '',
      'notes' => '',
      'voided_at' => null,
    ];
  }

  usort($paymentsList, function($a,$b){
    return strcmp((string)$b['date'], (string)$a['date']);
  });
}

/* Inactive clients notifications */
$inactiveList = [];
if ($settings['inactive_enabled'] && table_exists($db,'sales_invoices')) {
  $dateExpr = "si.`$invDateCol`";
  $sql = "SELECT si.customer_id AS client_id, MAX($dateExpr) AS last_purchase
          FROM sales_invoices si
          WHERE si.customer_id IS NOT NULL
          GROUP BY si.customer_id";
  $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  $lastMap = [];
  foreach ($rows as $r) { $lastMap[(int)$r['client_id']] = $r['last_purchase']; }

  foreach ($clients as $c) {
    $cid = (int)$c['id'];
    $last = $lastMap[$cid] ?? null;
    if (!$last) continue;
    $days = (int)(new DateTime($last))->diff(new DateTime())->format('%a');
    if ($days < $settings['inactive_days']) continue;
    if ($settings['inactive_exclude_blocked'] && (($c['status'] ?? 'active') === 'blocked')) continue;
    if ($settings['inactive_exclude_no_phone'] && trim((string)($c['phone'] ?? '')) === '') continue;
    $inactiveList[] = [
      'id' => $cid,
      'name' => $c['name'] ?? '',
      'phone' => $c['phone'] ?? '',
      'last' => $last,
      'days' => $days,
    ];
  }
}

/* Reports */
$reportCredit = [];
$reportOverdue = [];
foreach ($clients as $c) {
  $cid = (int)$c['id'];
  $credit = $creditTotals[$cid] ?? 0.0;
  $paid = ($paymentTotals[$cid] ?? 0.0) + ($creditPaidAtSale[$cid] ?? 0.0);
  $balance = $credit - $paid;
  if ($balance > 0.009) {
    $reportCredit[] = [
      'id'=>$cid,'name'=>$c['name'],'phone'=>$c['phone'],'balance'=>$balance
    ];
    $old = $oldestCredit[$cid] ?? null;
    if ($old) {
      $days = (int)(new DateTime($old))->diff(new DateTime())->format('%a');
      if ($days >= max(30, $settings['inactive_days'])) {
        $reportOverdue[] = [
          'id'=>$cid,'name'=>$c['name'],'phone'=>$c['phone'],'balance'=>$balance,'oldest'=>$old,'days'=>$days
        ];
      }
    }
  }
}
usort($reportCredit, fn($a,$b)=> $b['balance'] <=> $a['balance']);
usort($reportOverdue, fn($a,$b)=> $b['days'] <=> $a['days']);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>صفحة العملاء</title>
  <link rel="stylesheet" href="/3zbawyh/assets/style.css">
  <style>
    :root{
      --bg:#f6f7fb;--card:#fff;--ink:#0f172a;--muted:#6b7280;--bd:#e5e7eb;
      --pri:#0f172a;--pri-2:#1f2937;--ok:#16a34a;--warn:#f59e0b;--danger:#b91c1c;
      --chip:#1118270d;--shadow:0 12px 30px rgba(0,0,0,.06);--r:16px;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);font-family:system-ui,-apple-system,"Noto Naskh Arabic",Tahoma,Arial;color:var(--ink)}
    .container{max-width:1300px;margin:0 auto;padding:16px}
    .page-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
    .breadcrumb{font-size:12px;color:var(--muted)}
    .title{margin:4px 0 0;font-size:20px}
    .layout{display:grid;grid-template-columns: 320px 1fr; gap:14px}
    @media(max-width:1050px){.layout{grid-template-columns:1fr}}
    .card{background:var(--card);border:1px solid var(--bd);border-radius:var(--r);padding:14px;box-shadow:var(--shadow)}
    .btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:10px;padding:9px 12px;background:var(--pri);color:#fff;text-decoration:none;font-weight:700;cursor:pointer}
    .btn.secondary{background:#eef2f7;color:#111827;border:1px solid var(--bd)}
    .btn.ok{background:var(--ok)}
    .btn.warn{background:var(--warn)}
    .btn.danger{background:var(--danger)}
    .input, select, textarea{width:100%;padding:9px 10px;border-radius:10px;border:1px solid var(--bd)}
    .muted{color:var(--muted)}
    .chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:var(--chip);border:1px solid var(--bd);font-size:12px}
    .client-list{display:flex;flex-direction:column;gap:8px;max-height:70vh;overflow:auto}
    .client-item{border:1px solid var(--bd);border-radius:12px;padding:10px;background:#fff;text-decoration:none;color:inherit}
    .client-item.active{border-color:#cbd5e1;box-shadow:0 6px 18px rgba(0,0,0,.06)}
    .row{display:flex;gap:8px;flex-wrap:wrap}
    .grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
    .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
    @media(max-width:880px){.grid-3{grid-template-columns:1fr}}
    @media(max-width:680px){.grid-2{grid-template-columns:1fr}}
    .kpi{display:flex;flex-direction:column;gap:6px}
    .kpi .value{font-size:20px;font-weight:800}
    .tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .tab{border:1px solid var(--bd);background:#fff;border-radius:999px;padding:6px 12px;cursor:pointer}
    .tab.active{background:#111827;color:#fff;border-color:#111827}
    .tab-panel{display:none;margin-top:10px}
    .tab-panel.active{display:block}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th,td{padding:8px;border-bottom:1px solid var(--bd);text-align:right;white-space:nowrap}
    .table-wrap{overflow:auto}
    .status-ok{color:var(--ok);font-weight:700}
    .status-warn{color:var(--warn);font-weight:700}
    .status-bad{color:var(--danger);font-weight:700}
    .note{font-size:12px;color:var(--muted)}
  </style>
</head>
<body>
<div class="container">

  <div class="page-head">
    <div>
      <div class="breadcrumb">لوحة التحكم › العملاء</div>
      <div class="title">إدارة العملاء ومتابعة الحسابات</div>
      <div class="muted" style="font-size:12px">المركز الرئيسي لمتابعة فواتير العملاء، المديونية، والتحصيلات.</div>
    </div>
    <div class="row">
      <a class="btn secondary" href="/3zbawyh/public/dashboard.php">الرجوع للوحة التحكم</a>
      <a class="btn secondary" href="/3zbawyh/public/order_type.php">نقطة البيع</a>
      <?php if($can_manage): ?>
        <a class="btn secondary" href="/3zbawyh/public/collector_sheet.php">كشف المحصلين</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#ecfdf5;margin-bottom:10px"><?=e2($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="card" style="border-color:#fecaca;background:#fee2e2;margin-bottom:10px">❌ <?=e2($err)?></div><?php endif; ?>

  <?php if($schemaErrors): ?>
    <div class="card" style="border-color:#fecaca;background:#fff5f5;margin-bottom:12px">
      <div style="font-weight:700;margin-bottom:6px">ملاحظات بخصوص قاعدة البيانات</div>
      <?php foreach($schemaErrors as $se): ?>
        <div class="note"><?= e2($se) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if($settings['inactive_enabled']): ?>
    <div class="card" style="margin-bottom:12px">
      <div class="row" style="justify-content:space-between;align-items:center">
        <div>
          <strong>تنبيهات العملاء غير النشطين</strong>
          <div class="note">الحد الحالي: <?= (int)$settings['inactive_days'] ?> يوم بدون شراء.</div>
        </div>
        <?php if($can_manage): ?>
          <button class="btn secondary" type="button" onclick="document.getElementById('settingsBox').scrollIntoView({behavior:'smooth'})">الإعدادات</button>
        <?php endif; ?>
      </div>
      <?php if(empty($inactiveList)): ?>
        <div class="note" style="margin-top:6px">لا يوجد عملاء غير نشطين ضمن الشروط الحالية.</div>
      <?php else: ?>
        <div class="table-wrap" style="margin-top:8px">
          <table>
            <thead>
              <tr>
                <th>العميل</th>
                <th>آخر شراء</th>
                <th>الأيام</th>
                <th>إجراء</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach(array_slice($inactiveList,0,10) as $it): ?>
                <tr>
                  <td><a href="?id=<?=$it['id']?>"><?= e2($it['name']) ?></a></td>
                  <td><?= e2(date('Y-m-d', strtotime($it['last']))) ?></td>
                  <td class="status-warn"><?= (int)$it['days'] ?> يوم</td>
                  <td>
                    <?php if(!empty($it['phone'])): ?>
                      <?php
                        $msgWa = "مرحبًا، لم نرك منذ ".date('Y-m-d', strtotime($it['last'])).". هل ترغب في طلب جديد؟";
                      ?>
                      <a class="btn" target="_blank" rel="noopener" href="<?= e2(wa_link((string)$it['phone'], $msgWa)) ?>">واتساب</a>
                    <?php else: ?>
                      <span class="note">لا يوجد رقم</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="layout">

    <!-- Sidebar -->
    <aside class="card">
      <form method="get" class="row" style="align-items:center">
        <input class="input" name="q" value="<?=e2($search)?>" placeholder="بحث بالاسم أو الموبايل">
        <button class="btn secondary" type="submit">بحث</button>
      </form>

      <div class="row" style="justify-content:space-between;align-items:center;margin-top:10px">
        <strong>العملاء</strong>
        <span class="chip"><?= count($clients) ?> عميل</span>
      </div>

      <div class="client-list" style="margin-top:8px">
        <?php foreach($clients as $c): ?>
          <?php
            $cid = (int)$c['id'];
            $isActive = $cid === $clientId;
            $balance = ($creditTotals[$cid] ?? 0) - ($paymentTotals[$cid] ?? 0) - ($creditPaidAtSale[$cid] ?? 0);
          ?>
          <a class="client-item <?= $isActive ? 'active':'' ?>" href="?id=<?=$cid?>">
            <div style="font-weight:700"><?= e2($c['name']) ?></div>
            <div class="note"><?= e2($c['phone'] ?? '') ?></div>
            <div class="note">الرصيد: <?= nf($balance) ?> ج.م</div>
            <?php if($hasCollectorCol && !empty($c['collector_id'])): ?>
              <div class="note">المحصل: <?= e2($collectorOptions[(int)$c['collector_id']] ?? ('مستخدم #'.(int)$c['collector_id'])) ?></div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if(empty($clients)): ?>
          <div class="note">لا يوجد عملاء مطابقين للبحث.</div>
        <?php endif; ?>
      </div>

      <hr style="border:none;border-top:1px solid var(--bd);margin:12px 0">
      <strong>إضافة عميل جديد</strong>
      <form method="post" style="margin-top:8px">
        <input type="hidden" name="action" value="client_create">
        <label class="note">الاسم *</label>
        <input class="input" name="name" required>
        <label class="note" style="margin-top:6px">الموبايل *</label>
        <input class="input" name="phone" required>
        <label class="note" style="margin-top:6px">العنوان (اختياري)</label>
        <input class="input" name="address">
        <label class="note" style="margin-top:6px">ملاحظات</label>
        <textarea class="input" name="notes" rows="2"></textarea>
        <?php if($hasCollectorCol): ?>
          <label class="note" style="margin-top:6px">المحصل</label>
          <select class="input" name="collector_id">
            <option value="">بدون محصل</option>
            <?php foreach($collectorOptions as $cid=>$clabel): ?>
              <option value="<?= (int)$cid ?>"><?= e2($clabel) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if(empty($collectorOptions)): ?>
            <div class="note" style="margin-top:4px">لا يوجد مستخدمون بدور مدير/إدارة.</div>
          <?php endif; ?>
        <?php endif; ?>
        <button class="btn ok" type="submit" style="margin-top:8px">إضافة</button>
      </form>
    </aside>

    <!-- Main -->
    <main>
      <?php if(!$clientId): ?>
        <div class="card">
          <strong>اختر عميلًا من القائمة لعرض التفاصيل.</strong>
        </div>
      <?php else: ?>
        <?php
          $cname = $client[$custNameCol] ?? '';
          $cphone = $client[$custPhoneCol] ?? '';
          $cstatus = $client['status'] ?? 'active';
          $climit = $client['credit_limit'] ?? null;
          $caddress = $client['address'] ?? '';
          $cnotes = $client['notes'] ?? '';
          $collectorId = $hasCollectorCol ? (int)($client['collector_id'] ?? 0) : 0;
          $collectorLabel = $collectorId ? ($collectorOptions[$collectorId] ?? ('مستخدم #'.$collectorId)) : 'بدون محصل';
          $collectorOptionsForForm = $collectorOptions;
          if ($collectorId && !isset($collectorOptionsForForm[$collectorId])) {
            $collectorOptionsForForm[$collectorId] = $collectorLabel;
          }
          $inactiveFlag = ($settings['inactive_enabled'] && $daysSince !== null && $daysSince >= $settings['inactive_days']);
        ?>
        <div class="card">
          <div class="row" style="justify-content:space-between;align-items:center">
            <div>
              <div style="font-size:18px;font-weight:800"><?= e2($cname) ?></div>
              <div class="note"><?= e2($cphone) ?></div>
              <?php if($hasCollectorCol): ?>
                <div class="note">المحصل: <?= e2($collectorLabel) ?></div>
              <?php endif; ?>
              <?php if($inactiveFlag): ?>
                <div class="status-warn" style="margin-top:4px">⚠️ العميل غير نشط منذ <?= (int)$daysSince ?> يوم.</div>
              <?php endif; ?>
            </div>
            <div class="row">
              <?php
                $msgWa = $lastPurchaseDate
                  ? "مرحبًا، لم نرك منذ ".date('Y-m-d', strtotime($lastPurchaseDate)).". هل ترغب في طلب جديد؟"
                  : "مرحبًا، نرحب بزيارتك. هل ترغب في طلب جديد؟";
              ?>
              <?php if(trim((string)$cphone) !== ''): ?>
                <a class="btn" target="_blank" rel="noopener" href="<?= e2(wa_link((string)$cphone, $msgWa)) ?>">واتساب</a>
              <?php endif; ?>
              <span class="chip"><?= $cstatus === 'blocked' ? 'محظور' : 'نشط' ?></span>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:10px">
          <strong>بيانات العميل</strong>
          <form method="post" style="margin-top:8px">
            <input type="hidden" name="action" value="client_update">
            <input type="hidden" name="client_id" value="<?=$clientId?>">
            <div class="grid-2">
              <label class="note">الاسم *
                <input class="input" name="name" required value="<?= e2($cname) ?>">
              </label>
              <label class="note">الموبايل *
                <input class="input" name="phone" required value="<?= e2($cphone) ?>">
              </label>
              <label class="note">العنوان/المنطقة
                <input class="input" name="address" value="<?= e2($caddress) ?>">
              </label>
              <label class="note">ملاحظات
                <input class="input" name="notes" value="<?= e2($cnotes) ?>">
              </label>
              <?php if($hasCollectorCol): ?>
              <label class="note">المحصل
                <select class="input" name="collector_id">
                  <option value="">بدون محصل</option>
                  <?php foreach($collectorOptionsForForm as $cid=>$clabel): ?>
                    <option value="<?= (int)$cid ?>" <?= $collectorId===(int)$cid ? 'selected':'' ?>><?= e2($clabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php endif; ?>
              <label class="note">حد الائتمان
                <input class="input" name="credit_limit" <?= $can_manage ? '' : 'disabled' ?> value="<?= e2((string)$climit) ?>">
              </label>
              <label class="note">الحالة
                <select class="input" name="status" <?= $can_manage ? '' : 'disabled' ?>>
                  <option value="active" <?= $cstatus !== 'blocked' ? 'selected':'' ?>>نشط</option>
                  <option value="blocked" <?= $cstatus === 'blocked' ? 'selected':'' ?>>محظور</option>
                </select>
              </label>
            </div>
            <div class="note" style="margin-top:6px">تعديل حدود الائتمان والحالة متاح للمدير/الإدارة فقط.</div>
            <button class="btn ok" type="submit" style="margin-top:8px">حفظ البيانات</button>
          </form>
        </div>

        <div class="card" style="margin-top:10px">
          <strong>ملخص سريع</strong>
          <div class="grid-3" style="margin-top:8px">
            <div class="kpi">
              <div class="note">الرصيد المستحق</div>
              <div class="value <?= $outstanding > 0 ? 'status-bad' : 'status-ok' ?>"><?= nf($outstanding) ?> ج.م</div>
              <?php if($climit): ?>
                <div class="note">حد الائتمان: <?= nf((float)$climit) ?> ج.م</div>
              <?php endif; ?>
            </div>
            <div class="kpi">
              <div class="note">آخر فاتورة</div>
              <?php if($lastInvoice): ?>
                <div class="value">#<?= e2($lastInvoice['invoice_no']) ?></div>
                <div class="note"><?= e2(date('Y-m-d', strtotime($lastInvoice['invoice_date']))) ?> — <?= nf((float)$lastInvoice['total']) ?> ج.م</div>
              <?php else: ?>
                <div class="value">—</div>
                <div class="note">لا يوجد فواتير بعد.</div>
              <?php endif; ?>
            </div>
            <div class="kpi">
              <div class="note">مشتريات آخر 30 يوم</div>
              <div class="value"><?= nf($total30) ?> ج.م</div>
              <div class="note">الأيام منذ آخر شراء: <?= $daysSince !== null ? (int)$daysSince.' يوم' : '—' ?></div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:10px">
          <div class="tabs">
            <button class="tab active" data-tab="invoices">الفواتير</button>
            <button class="tab" data-tab="credit">الآجل/المديونية</button>
            <button class="tab" data-tab="payments">المدفوعات والتحصيل</button>
            <button class="tab" data-tab="reports">تقارير</button>
          </div>

          <!-- Invoices -->
          <div class="tab-panel active" id="tab-invoices">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>رقم</th>
                    <th>تاريخ</th>
                    <th>الإجمالي</th>
                    <th>المدفوع</th>
                    <th>المتبقي</th>
                    <th>الحالة</th>
                    <th>عرض</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($clientInvoices as $inv): ?>
                    <?php
                      $pm = strtolower(trim((string)($inv['payment_method'] ?? '')));
                      if ($pm === 'agyl') $pm = 'agel';
                      $isCreditInv = ((int)($inv['is_credit'] ?? 0) === 1) || ($pm === 'agel');
                      $total = (float)$inv['total'];
                      $paidFromPayments = $invoicePayments[(int)$inv['id']] ?? 0.0;
                      $paidAtSale = ((int)($inv['is_credit'] ?? 0) === 1) ? (float)($inv['paid_amount'] ?? 0) : 0.0;
                      $paid  = $isCreditInv ? ($paidAtSale + $paidFromPayments) : $total;
                      $remaining = max(0, $total - $paid);
                      $status = ($remaining <= 0.009) ? 'مسددة' : ($paid > 0 ? 'اجل' : 'غير مسددة');
                      $statusClass = ($remaining <= 0.009) ? 'status-ok' : ($paid > 0 ? 'status-warn' : 'status-bad');
                    ?>
                    <tr>
                      <td><?= e2($inv['invoice_no']) ?></td>
                      <td><?= e2(date('Y-m-d', strtotime($inv['invoice_date']))) ?></td>
                      <td><?= nf($total) ?></td>
                      <td><?= nf($paid) ?></td>
                      <td><?= nf($remaining) ?></td>
                      <td class="<?= $statusClass ?>"><?= $status ?></td>
                      <td><a class="btn secondary" href="/3zbawyh/public/invoice_show.php?id=<?= (int)$inv['id'] ?>">عرض</a></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if(empty($clientInvoices)): ?>
                    <tr><td colspan="7" class="note">لا توجد فواتير.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Credit -->
          <div class="tab-panel" id="tab-credit">
            <div class="row" style="justify-content:space-between;align-items:center">
              <strong>إجمالي الرصيد الآجل: <?= nf($outstanding) ?> ج.م</strong>
              <?php if(!empty($oldestCreditDate)): ?>
                <span class="chip">أقدم فاتورة: <?= e2(date('Y-m-d', strtotime($oldestCreditDate))) ?></span>
              <?php endif; ?>
            </div>
            <div class="table-wrap" style="margin-top:8px">
              <table>
                <thead>
                  <tr>
                    <th>الفاتورة</th>
                    <th>التاريخ</th>
                    <th>موعد السداد</th>
                    <th>الإجمالي</th>
                    <th>مدفوع</th>
                    <th>المتبقي</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($creditInvoices as $inv): ?>
                    <?php $paidTotal = (float)($inv['paid_at_sale'] ?? 0) + (float)($inv['paid_from_payments'] ?? 0); ?>
                    <tr>
                      <td>#<?= e2($inv['invoice_no']) ?></td>
                      <td><?= e2(date('Y-m-d', strtotime($inv['invoice_date']))) ?></td>
                      <td><?= !empty($inv['credit_due_date']) ? e2(date('Y-m-d', strtotime($inv['credit_due_date']))) : 'مفتوح' ?></td>
                      <td><?= nf((float)$inv['total']) ?></td>
                      <td><?= nf($paidTotal) ?></td>
                      <td class="status-bad"><?= nf((float)$inv['remaining']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if(empty($creditInvoices)): ?>
                    <tr><td colspan="6" class="note">لا توجد فواتير آجلة غير مسددة.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Payments -->
          <div class="tab-panel" id="tab-payments">
            <strong>تسجيل تحصيل جديد</strong>
            <form method="post" style="margin-top:8px">
              <input type="hidden" name="action" value="payment_add">
              <input type="hidden" name="client_id" value="<?=$clientId?>">
              <div class="grid-2">
                <label class="note">الطريقة
                  <select class="input" name="method">
                    <option value="cash">نقدي</option>
                    <option value="visa">بطاقة / فيزا</option>
                    <option value="instapay">InstaPay</option>
                    <option value="vodafone_cash">Vodafone Cash</option>
                    <option value="transfer">تحويل</option>
                    <option value="other">أخرى</option>
                    <?php if($can_manage): ?><option value="adjustment">تعديل رصيد</option><?php endif; ?>
                  </select>
                </label>
                <label class="note">المبلغ
                  <input class="input" name="amount" inputmode="decimal" required>
                </label>
              </div>
              <div class="grid-2" style="margin-top:8px">
                <label class="note">مرجع/رقم عملية
                  <input class="input" name="reference">
                </label>
                <label class="note">ملاحظات
                  <input class="input" name="notes">
                </label>
              </div>
              <button class="btn ok" type="submit" style="margin-top:8px">تسجيل التحصيل</button>
            </form>

            <?php if($can_manage): ?>
              <div class="card" style="margin-top:12px;border:1px dashed var(--bd);background:#fafafa">
                <strong>إضافة مديونية خارجية</strong>
                <form method="post" style="margin-top:8px">
                  <input type="hidden" name="action" value="debt_add">
                  <input type="hidden" name="client_id" value="<?=$clientId?>">
                  <div class="grid-2">
                    <label class="note">قيمة المديونية
                      <input class="input" name="debt_amount" inputmode="decimal" required>
                    </label>
                    <label class="note">مرجع/سبب
                      <input class="input" name="reference" placeholder="اختياري">
                    </label>
                  </div>
                  <div class="grid-2" style="margin-top:8px">
                    <label class="note">ملاحظات
                      <input class="input" name="notes">
                    </label>
                  </div>
                  <button class="btn danger" type="submit" style="margin-top:8px">إضافة المديونية</button>
                </form>
              </div>
            <?php endif; ?>

            <div class="table-wrap" style="margin-top:12px">
              <table>
                <thead>
                  <tr>
                    <th>التاريخ</th>
                    <th>المصدر</th>
                    <th>الطريقة</th>
                    <th>المبلغ</th>
                    <th>الفاتورة</th>
                    <th>الكاشير</th>
                    <th>ملاحظات</th>
                    <th>إجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($paymentsList as $p): ?>
                    <?php
                      $pAmount = (float)($p['amount'] ?? 0);
                      $isDebtRow = ($p['source'] === 'debt' || $pAmount < 0);
                      $amountDisplay = $isDebtRow ? nf(abs($pAmount)) : nf($pAmount);
                    ?>
                    <tr>
                      <td><?= e2($p['date'] ? date('Y-m-d H:i', strtotime($p['date'])) : '') ?></td>
                      <td>
                        <?php if($p['source'] === 'collection'): ?>
                          تحصيل
                        <?php elseif($p['source'] === 'debt'): ?>
                          مديونية
                        <?php else: ?>
                          مدفوعات الفاتورة
                        <?php endif; ?>
                      </td>
                      <td><?= e2(method_label((string)$p['method'])) ?></td>
                      <td class="<?= $isDebtRow ? 'status-bad' : 'status-ok' ?>"><?= $amountDisplay ?></td>
                      <td><?= $p['invoice_no'] ? '#'.e2($p['invoice_no']) : '—' ?></td>
                      <td><?= e2($p['cashier_name']) ?></td>
                      <td><?= e2($p['notes'] ?? '') ?></td>
                      <td>
                        <?php if($p['source']!=='invoice' && $can_manage && empty($p['voided_at'])): ?>
                          <form method="post" onsubmit="return confirm('إلغاء هذه الدفعة؟');">
                            <input type="hidden" name="action" value="payment_void">
                            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="void_reason" value="إلغاء من العميل">
                            <button class="btn danger" type="submit">إلغاء</button>
                          </form>
                        <?php else: ?>
                          <span class="note">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if(empty($paymentsList)): ?>
                    <tr><td colspan="8" class="note">لا توجد مدفوعات مسجلة.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Reports -->
          <div class="tab-panel" id="tab-reports">
            <div class="grid-2">
              <div>
                <strong>عملاء لديهم رصيد آجل</strong>
                <div class="table-wrap" style="margin-top:6px">
                  <table>
                    <thead><tr><th>العميل</th><th>الرصيد</th></tr></thead>
                    <tbody>
                      <?php foreach(array_slice($reportCredit,0,10) as $r): ?>
                        <tr>
                          <td><a href="?id=<?=$r['id']?>"><?= e2($r['name']) ?></a></td>
                          <td class="status-bad"><?= nf((float)$r['balance']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if(empty($reportCredit)): ?>
                        <tr><td colspan="2" class="note">لا توجد أرصدة آجلة.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <div>
                <strong>عملاء متأخرون في السداد</strong>
                <div class="table-wrap" style="margin-top:6px">
                  <table>
                    <thead><tr><th>العميل</th><th>أقدم فاتورة</th><th>الأيام</th></tr></thead>
                    <tbody>
                      <?php foreach(array_slice($reportOverdue,0,10) as $r): ?>
                        <tr>
                          <td><a href="?id=<?=$r['id']?>"><?= e2($r['name']) ?></a></td>
                          <td><?= e2(date('Y-m-d', strtotime($r['oldest']))) ?></td>
                          <td class="status-warn"><?= (int)$r['days'] ?> يوم</td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if(empty($reportOverdue)): ?>
                        <tr><td colspan="3" class="note">لا يوجد تأخير واضح.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <div style="margin-top:12px">
              <strong>عملاء غير نشطين</strong>
              <div class="table-wrap" style="margin-top:6px">
                <table>
                  <thead><tr><th>العميل</th><th>آخر شراء</th><th>الأيام</th></tr></thead>
                  <tbody>
                    <?php foreach(array_slice($inactiveList,0,10) as $r): ?>
                      <tr>
                        <td><a href="?id=<?=$r['id']?>"><?= e2($r['name']) ?></a></td>
                        <td><?= e2(date('Y-m-d', strtotime($r['last']))) ?></td>
                        <td class="status-warn"><?= (int)$r['days'] ?> يوم</td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if(empty($inactiveList)): ?>
                      <tr><td colspan="3" class="note">لا يوجد عملاء غير نشطين.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <?php if($can_manage): ?>
          <div class="card" id="settingsBox" style="margin-top:10px">
            <strong>إعدادات التنبيهات</strong>
            <form method="post" style="margin-top:8px">
              <input type="hidden" name="action" value="settings_save">
              <label class="note">
                <input type="checkbox" name="inactive_enabled" <?= $settings['inactive_enabled'] ? 'checked':'' ?>> تفعيل تنبيهات عدم النشاط
              </label>
              <div class="grid-2" style="margin-top:8px">
                <label class="note">عدد الأيام
                  <input class="input" name="inactive_days" value="<?= (int)$settings['inactive_days'] ?>" min="1" type="number">
                </label>
                <label class="note">استبعاد العملاء المحظورين
                  <input type="checkbox" name="inactive_exclude_blocked" <?= $settings['inactive_exclude_blocked'] ? 'checked':'' ?>>
                </label>
                <label class="note">استبعاد العملاء بدون رقم
                  <input type="checkbox" name="inactive_exclude_no_phone" <?= $settings['inactive_exclude_no_phone'] ? 'checked':'' ?>>
                </label>
              </div>
              <button class="btn ok" type="submit" style="margin-top:8px">حفظ الإعدادات</button>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </main>
  </div>
</div>

<script>
  document.querySelectorAll('.tab').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
      btn.classList.add('active');
      const id = 'tab-'+btn.dataset.tab;
      const panel = document.getElementById(id);
      if(panel) panel.classList.add('active');
    });
  });
</script>
</body>
</html>
