<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
require_role_in_or_redirect(['admin','Manger','manager']);

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

/* ===== Schema checks ===== */
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
$hasCollectorCol = table_exists($db, 'customers') && column_exists($db, 'customers', 'collector_id');
if (!$hasCollectorCol) {
  $schemaErrors[] = "عمود collector_id غير موجود في جدول customers. افتح صفحة العملاء لإضافته تلقائيًا.";
}

/* ===== Collectors list ===== */
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

/* ===== Filters ===== */
$search = trim((string)($_GET['q'] ?? ''));
$collectorId = (int)($_GET['collector_id'] ?? 0);
$collectorLabel = $collectorId ? ($collectorOptions[$collectorId] ?? ('مستخدم #'.$collectorId)) : '';
$today = date('d/m/Y');
$todayFile = date('Y-m-d');

/* ===== Clients list by collector ===== */
$clients = [];
if ($collectorId > 0 && table_exists($db,'customers')) {
  $like = "%$search%";
  $sql = "SELECT c.id, c.`$custNameCol` AS name, c.`$custPhoneCol` AS phone
          FROM customers c
          WHERE c.collector_id = ?
            AND (?='' OR c.`$custNameCol` LIKE ? OR c.`$custPhoneCol` LIKE ?)
          ORDER BY c.`$custNameCol` ASC";
  $st = $db->prepare($sql);
  $st->execute([$collectorId, $search, $like, $like]);
  $clients = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ===== Totals ===== */
$creditTotals = [];
$creditPaidAtSale = [];
$paymentTotals = [];
$manualDebtTotals = [];

$clientIds = array_map(fn($c)=> (int)$c['id'], $clients);
$clientIds = array_values(array_filter($clientIds, fn($id)=>$id>0));

if (!empty($clientIds) && table_exists($db,'sales_invoices')) {
  $invCols = cols_of($db, 'sales_invoices');
  $invIdCol   = pick_col($invCols, ['id','invoice_id']) ?? 'id';
  $invDateCol = detect_date_col($db, 'sales_invoices') ?? $invIdCol;
  $invTotalCol = pick_col($invCols, ['total','grand_total','amount','invoice_total']) ?? null;
  $invPaidCol = pick_col($invCols, ['paid_amount','paid','payment_total']) ?? null;
  $invPayMethodCol = pick_col($invCols, ['payment_method']) ?? null;
  $invIsCreditCol = in_array('is_credit', $invCols, true) ? 'is_credit' : null;

  $dateExpr = "si.`$invDateCol`";
  $totalExpr = $invTotalCol ? "si.`$invTotalCol`" : "0";
  $joinItems = '';
  if (!$invTotalCol && table_exists($db,'sales_items')) {
    $LINE_TOT = "COALESCE(it.line_total, it.qty*it.unit_price)";
    $totalExpr = "SUM($LINE_TOT)";
    $joinItems = "LEFT JOIN sales_items it ON it.invoice_id = si.`$invIdCol`";
  }
  $creditWhere = [];
  if ($invIsCreditCol) $creditWhere[] = "si.`$invIsCreditCol` = 1";
  if ($invPayMethodCol) $creditWhere[] = "LOWER(TRIM(si.`$invPayMethodCol`)) IN ('agel','agyl')";
  $creditWhereSql = $creditWhere ? ('('.implode(' OR ', $creditWhere).')') : "0";
  $paidSaleExpr = ($invIsCreditCol && $invPaidCol) ? "COALESCE(SUM(CASE WHEN si.`$invIsCreditCol`=1 THEN COALESCE(si.`$invPaidCol`,0) ELSE 0 END),0)" : "0";

  $in = implode(',', array_fill(0, count($clientIds), '?'));
  $sql = "SELECT si.customer_id AS client_id,
                 COALESCE(SUM($totalExpr),0) AS credit_total,
                 $paidSaleExpr AS paid_at_sale
          FROM sales_invoices si
          $joinItems
          WHERE si.customer_id IN ($in)
            AND $creditWhereSql
          GROUP BY si.customer_id";
  $st = $db->prepare($sql);
  $st->execute($clientIds);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $creditTotals[(int)$r['client_id']] = (float)$r['credit_total'];
    $creditPaidAtSale[(int)$r['client_id']] = (float)($r['paid_at_sale'] ?? 0);
  }
}

if (!empty($clientIds) && table_exists($db,'client_payments')) {
  $in = implode(',', array_fill(0, count($clientIds), '?'));
  $sql = "SELECT client_id,
                 COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END),0) AS paid_total,
                 COALESCE(SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END),0) AS debt_total
          FROM client_payments
          WHERE voided_at IS NULL
            AND client_id IN ($in)
          GROUP BY client_id";
  $st = $db->prepare($sql);
  $st->execute($clientIds);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $paymentTotals[(int)$r['client_id']] = (float)$r['paid_total'];
    $manualDebtTotals[(int)$r['client_id']] = (float)$r['debt_total'];
  }
}

$rows = [];
foreach ($clients as $idx=>$c) {
  $cid = (int)$c['id'];
  $credit = $creditTotals[$cid] ?? 0.0;
  $extraDebt = $manualDebtTotals[$cid] ?? 0.0;
  $paid = ($paymentTotals[$cid] ?? 0.0) + ($creditPaidAtSale[$cid] ?? 0.0);
  $totalDebt = $credit + $extraDebt;
  $remaining = $totalDebt - $paid;
  $rows[] = [
    'idx' => $idx + 1,
    'name' => $c['name'] ?? '',
    'phone' => $c['phone'] ?? '',
    'debt' => $totalDebt,
    'paid' => $paid,
    'remaining' => $remaining,
  ];
}

$totalRows = count($rows);
$perCol = $totalRows > 0 ? (int)ceil($totalRows / 3) : 0;
$blocks = [
  array_slice($rows, 0, $perCol),
  array_slice($rows, $perCol, $perCol),
  array_slice($rows, $perCol * 2, $perCol),
];

/* ===== Export ===== */
if (isset($_GET['export']) && $collectorId > 0) {
  header("Content-Type: application/vnd.ms-excel; charset=utf-8");
  header("Content-Disposition: attachment; filename=collector_sheet_{$collectorId}_{$todayFile}.xls");
  echo "<html><head><meta charset='utf-8'></head><body dir='rtl'>";
  echo "<table border='1' cellspacing='0' cellpadding='6' style='border-collapse:collapse'>";
  echo "<tr style='background:#ffef6b;font-weight:bold;text-align:center'>";
  echo "<td colspan='3'>اسم المحصل</td><td colspan='2'>".e($collectorLabel)."</td>";
  echo "<td colspan='3'>التاريخ</td><td colspan='2'>".e($today)."</td>";
  echo "</tr>";
  echo "<tr style='background:#ffef6b;font-weight:bold;text-align:center'>";
  for ($b=0;$b<3;$b++) {
    echo "<td>م</td><td>العميل</td><td>رصيد</td><td>مسدد</td><td>متبقي</td>";
  }
  echo "</tr>";
  if ($perCol > 0) {
    for ($i=0; $i<$perCol; $i++) {
      echo "<tr style='text-align:center'>";
      for ($b=0;$b<3;$b++) {
        $r = $blocks[$b][$i] ?? null;
        if ($r) {
          echo "<td>".$r['idx']."</td>";
          echo "<td style='text-align:right'>".e($r['name'])."</td>";
          echo "<td>".nf($r['debt'])."</td>";
          echo "<td>".nf($r['paid'])."</td>";
          echo "<td>".nf($r['remaining'])."</td>";
        } else {
          echo "<td></td><td></td><td></td><td></td><td></td>";
        }
      }
      echo "</tr>";
    }
  }
  echo "</table></body></html>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>كشف المحصلين - العزباوية</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/3zbawyh/assets/style.css">
  <style>
    :root{--bd:#e5e7eb;--yl:#ffef6b}
    body{background:#f7f7fb}
    .page{max-width:1200px;margin:0 auto;padding:16px}
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .card{background:#fff;border:1px solid var(--bd);border-radius:12px;padding:14px}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .sheet{width:100%;border-collapse:collapse;margin-top:10px}
    .sheet th,.sheet td{border:1px solid #111;padding:6px 8px;font-size:13px;text-align:center}
    .sheet thead th{background:var(--yl);font-weight:800}
    .sheet .meta td{background:var(--yl);font-weight:800}
    .sheet .name{text-align:right}
    .note{font-size:12px;color:#6b7280}
    .btn{padding:8px 12px;border-radius:10px;border:0;background:#111;color:#fff;text-decoration:none}
    .btn.secondary{background:#f1f2f7;color:#111}
    .filters{display:grid;grid-template-columns: 1fr 1fr auto;gap:10px}
    @media (max-width: 900px){.filters{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="page">
    <div class="topbar" style="margin-bottom:12px">
      <div>
        <div class="note">لوحة التحكم › كشف المحصلين</div>
        <div style="font-weight:800;font-size:18px">كشف العملاء حسب المحصل</div>
      </div>
      <div class="row">
        <a class="btn secondary" href="/3zbawyh/public/clients.php">الرجوع للعملاء</a>
        <a class="btn secondary" href="/3zbawyh/public/dashboard.php">الرجوع للوحة التحكم</a>
      </div>
    </div>

    <?php if($schemaErrors): ?>
      <div class="card" style="border-color:#fecaca;background:#fff5f5;margin-bottom:12px">
        <div style="font-weight:700;margin-bottom:6px">ملاحظات بخصوص قاعدة البيانات</div>
        <?php foreach($schemaErrors as $se): ?>
          <div class="note"><?= e($se) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <form method="get" class="filters">
        <label class="note">المحصل
          <select class="input" name="collector_id" required>
            <option value="">اختر محصل</option>
            <?php foreach($collectorOptions as $cid=>$clabel): ?>
              <option value="<?= (int)$cid ?>" <?= $collectorId===(int)$cid ? 'selected':'' ?>><?= e($clabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="note">بحث بالاسم أو الموبايل
          <input class="input" name="q" value="<?= e($search) ?>" placeholder="بحث">
        </label>
        <div style="display:flex;align-items:end;gap:8px">
          <button class="btn" type="submit">عرض</button>
          <?php if($collectorId > 0): ?>
            <a class="btn secondary" href="?collector_id=<?=$collectorId?>&q=<?=urlencode($search)?>&export=1">تصدير Excel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card" style="margin-top:12px">
      <?php if(!$collectorId): ?>
        <div class="note">اختر محصلًا لعرض العملاء.</div>
      <?php else: ?>
        <table class="sheet">
          <thead>
            <tr class="meta">
              <td colspan="3">اسم المحصل</td>
              <td colspan="2"><?= e($collectorLabel) ?></td>
              <td colspan="3">التاريخ</td>
              <td colspan="2"><?= e($today) ?></td>
            </tr>
            <tr>
              <?php for($b=0;$b<3;$b++): ?>
                <th>م</th>
                <th>العميل</th>
                <th>رصيد</th>
                <th>مسدد</th>
                <th>متبقي</th>
              <?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php if($perCol > 0): ?>
              <?php for($i=0;$i<$perCol;$i++): ?>
                <tr>
                  <?php for($b=0;$b<3;$b++): ?>
                    <?php $r = $blocks[$b][$i] ?? null; ?>
                    <?php if($r): ?>
                      <td><?= (int)$r['idx'] ?></td>
                      <td class="name"><?= e($r['name']) ?></td>
                      <td><?= nf($r['debt']) ?></td>
                      <td><?= nf($r['paid']) ?></td>
                      <td><?= nf($r['remaining']) ?></td>
                    <?php else: ?>
                      <td></td><td></td><td></td><td></td><td></td>
                    <?php endif; ?>
                  <?php endfor; ?>
                </tr>
              <?php endfor; ?>
            <?php else: ?>
              <tr><td colspan="15" class="note">لا يوجد عملاء للمحصل المختار.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
