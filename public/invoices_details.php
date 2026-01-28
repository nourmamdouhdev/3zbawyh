<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','Manger']);

date_default_timezone_set('Africa/Cairo');
$db = db();

function e2($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, (floor($n)===$n?0:2), '.', ','); }

/* ---------- Helpers لاكتشاف الأعمدة ---------- */
function cols_of(PDO $db, string $table): array {
  try{
    $st = $db->prepare("
      SELECT COLUMN_NAME 
      FROM INFORMATION_SCHEMA.COLUMNS 
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
  }catch(Throwable $e){ return []; }
}

function first_existing_expr(array $have, array $prefs, string $aliasPrefix): ?string {
  $parts = [];
  foreach ($prefs as $p) {
    if (in_array($p, $have, true)) {
      $parts[] = $aliasPrefix . '`' . $p . '`';
    }
  }
  if (!$parts) return null;
  return 'COALESCE('.implode(',', $parts).')';
}

function detect_date_col(
  PDO $db,
  string $table,
  array $prefs=['created_at','invoice_date','created_on','date','ts','timestamp','time']
): ?string {
  try{
    $st = $db->prepare("
      SELECT COLUMN_NAME, DATA_TYPE 
      FROM INFORMATION_SCHEMA.COLUMNS 
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        AND DATA_TYPE IN ('datetime','timestamp','date','time')
    ");
    $st->execute([$table]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$rows) return null;
    foreach ($prefs as $p) if (isset($rows[$p])) return $p;
    return array_key_first($rows);
  }catch(Throwable $e){ return null; }
}

/* ---------- أعمدة الجداول ---------- */
$cCols  = cols_of($db, 'customers');
$uCols  = cols_of($db, 'users');
$siCols = cols_of($db, 'sales_invoices');
$itCols = cols_of($db, 'sales_items');

/* تعبيرات اسم الكاشير / العميل / الموبايل */
$CUST_NAME_EXPR    = first_existing_expr($cCols, ['name','customer_name','full_name','title'], 'c.')
                  ?: "CONCAT('عميل #', si.customer_id)";
$CASHIER_NAME_EXPR = first_existing_expr($uCols, ['username','name','full_name','display_name'], 'u.')
                  ?: "CONCAT('كاشير #', si.cashier_id)";
$CUST_PHONE_EXPR   = in_array('phone', $cCols, true) ? 'c.phone' : "NULL";

/* عمود التاريخ */
$dateCol = detect_date_col($db, 'sales_invoices');

/* هل يوجد payment_method؟ */
$hasPaymentMethod = in_array('payment_method', $siCols, true);

/* أعمدة مبالغ اختيارية لو موجودة */
$colSubtotal = in_array('subtotal',    $siCols, true) ? 'si.subtotal'    : null;
$colDiscount = in_array('discount',    $siCols, true) ? 'si.discount'    : null;
$colTax      = in_array('tax',         $siCols, true) ? 'si.tax'         : null;
$colTotal    = in_array('total',       $siCols, true) ? 'si.total'       : null;
$colPaid     = in_array('paid_amount', $siCols, true) ? 'si.paid_amount' : null;
$colChange   = in_array('change_due',  $siCols, true) ? 'si.change_due'  : null;

/* ---------- فلاتر GET ---------- */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$fromTs = $from.' 00:00:00';
$toTs   = $to  .' 23:59:59';

$limit = max(20, (int)($_GET['limit'] ?? 200));
$page  = max(1, (int)($_GET['page'] ?? 1));

$phone = trim($_GET['phone'] ?? '');
$pay   = trim($_GET['payment_method'] ?? '');

/* ثابت لطرق الدفع */
$paymentOptions = [
  ''              => 'كل الطرق',
  'cash'          => 'نقدي (Cash)',
  'visa'          => 'Visa / بطاقة',
  'instapay'      => 'InstaPay',
  'vodafone_cash' => 'Vodafone Cash',
  'agel'          => 'آجل',
  'mixed'         => 'Mixed',
];

/* ---------- WHERE ---------- */
$where  = [];
$params = [];

if ($dateCol) {
  $where[]  = "si.`$dateCol` BETWEEN ? AND ?";
  $params[] = $fromTs;
  $params[] = $toTs;
}

/* فلتر برقم الموبايل */
if ($phone !== '' && in_array('phone', $cCols, true)) {
  $where[]  = "c.phone LIKE ?";
  $params[] = "%$phone%";
}

/* فلتر بطريقة الدفع */
if ($hasPaymentMethod && $pay !== '') {
  $where[]  = "si.payment_method = ?";
  $params[] = $pay;
}

$whereSql = $where ? implode(' AND ', $where) : '1=1';

$LINE_TOT = "COALESCE(it.line_total, it.qty * it.unit_price)";

/* ---------- Export CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="invoices_'.$from.'_to_'.$to.'.csv');
  $out = fopen('php://output','w');

  $hdr = ['ID','InvoiceNo','Date','Customer','Phone','Cashier'];
  if ($hasPaymentMethod) $hdr[] = 'PaymentMethod';
  array_push($hdr,'Subtotal','Discount','Tax','Total','Paid','Change');
  fputcsv($out, $hdr);

  $sql = "
    SELECT 
      si.id,
      si.invoice_no,
      ".($dateCol ? "si.`$dateCol`" : "NULL")." AS created_at,
      $CUST_NAME_EXPR   AS customer_name,
      $CUST_PHONE_EXPR  AS customer_phone,
      $CASHIER_NAME_EXPR AS cashier_name
      ".($hasPaymentMethod ? ", si.payment_method" : "").",
      ".($colSubtotal ?: "NULL")." AS subtotal,
      ".($colDiscount ?: "NULL")." AS discount,
      ".($colTax      ?: "NULL")." AS tax,
      ".($colTotal    ?: "SUM($LINE_TOT)")." AS total,
      ".($colPaid     ?: "NULL")." AS paid_amount,
      ".($colChange   ?: "NULL")." AS change_due
    FROM sales_invoices si
    LEFT JOIN sales_items it ON it.invoice_id = si.id
    ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
    ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
    WHERE $whereSql
    GROUP BY si.id
    ORDER BY ".($dateCol ? "si.`$dateCol`" : "si.id")." DESC
    LIMIT $limit
  ";

  $st = $db->prepare($sql);
  $st->execute($params);
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $row = [
      $r['id'],
      $r['invoice_no'],
      $r['created_at'],
      $r['customer_name'],
      $r['customer_phone'],
      $r['cashier_name'],
    ];
    if ($hasPaymentMethod) $row[] = $r['payment_method'] ?? '';
    $row[] = $r['subtotal'];
    $row[] = $r['discount'];
    $row[] = $r['tax'];
    $row[] = $r['total'];
    $row[] = $r['paid_amount'];
    $row[] = $r['change_due'];
    fputcsv($out, $row);
  }
  fclose($out); 
  exit;
}

/* ---------- عدد الفواتير للـ Pagination ---------- */
$countSql = "
  SELECT COUNT(DISTINCT si.id)
  FROM sales_invoices si
  ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
  WHERE $whereSql
";
$stCount = $db->prepare($countSql);
$stCount->execute($params);
$totalRows  = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

/* ---------- الاستعلام الرئيسي ---------- */
$sql = "
  SELECT 
    si.id,
    si.invoice_no,
    ".($dateCol ? "si.`$dateCol`" : "NULL")." AS created_at,
    $CUST_NAME_EXPR    AS customer_name,
    $CUST_PHONE_EXPR   AS customer_phone,
    $CASHIER_NAME_EXPR AS cashier_name
    ".($hasPaymentMethod ? ", si.payment_method" : "").",
    ".($colSubtotal ?: "NULL")." AS subtotal,
    ".($colDiscount ?: "NULL")." AS discount,
    ".($colTax      ?: "NULL")." AS tax,
    ".($colTotal    ?: "SUM($LINE_TOT)")." AS total,
    ".($colPaid     ?: "NULL")." AS paid_amount,
    ".($colChange   ?: "NULL")." AS change_due
  FROM sales_invoices si
  LEFT JOIN sales_items it ON it.invoice_id = si.id
  ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
  ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
  WHERE $whereSql
  GROUP BY si.id
  ORDER BY ".($dateCol ? "si.`$dateCol`" : "si.id")." DESC
  LIMIT $limit OFFSET $offset
";
$st  = $db->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>لوحة الفواتير</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#f3f4f6;
    --card:#ffffff;
    --border:#e5e7eb;
    --accent:#3b82f6;
    --accent-soft:#eff6ff;
    --text-main:#111827;
    --text-muted:#6b7280;
  }

  *{box-sizing:border-box;}

  body{
    margin:0;
    background:var(--bg);
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,Arial;
    color:var(--text-main);
  }

  /* ===== Layout عام ===== */
  .shell{
    min-height:100vh;
    display:flex;
    flex-direction:column;
  }

  .topbar{
    background:#ffffff;
    border-bottom:1px solid var(--border);
    padding:10px 18px;
    display:flex;
    justify-content:space-between;
    align-items:center;
  }

  .topbar-title{
    font-size:15px;
    font-weight:600;
    color:var(--text-main);
  }

  .topbar-sub{
    font-size:12px;
    color:var(--text-muted);
  }

  .topbar-actions a{
    color:var(--text-main);
    text-decoration:none;
    font-size:13px;
    padding:6px 12px;
    border-radius:999px;
    border:1px solid var(--border);
    background:#fff;
  }

  .topbar-actions a:hover{
    background:var(--accent-soft);
    border-color:var(--accent);
  }

  .main{
    flex:1;
    padding:18px 14px 24px;
  }

  .container{
    max-width:1200px;
    margin:0 auto;
  }

  /* ===== Cards / Boxes ===== */
  .card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:16px;
    padding:16px 18px;
    margin-bottom:14px;
    box-shadow:0 10px 30px rgba(15,23,42,0.04);
  }

  .row{
    display:flex;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
  }

  /* ===== Buttons ===== */
  .btn{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:var(--accent);
    color:#fff;
    border:none;
    padding:8px 16px;
    border-radius:999px;
    text-decoration:none;
    font-size:13px;
    cursor:pointer;
    white-space:nowrap;
    transition:background .15s, box-shadow .15s, transform .1s;
  }

  .btn:hover{
    background:#2563eb;
    box-shadow:0 4px 10px rgba(37,99,235,0.25);
    transform:translateY(-1px);
  }

  .btn.secondary{
    background:#e5e7eb;
    color:var(--text-main);
  }

  .btn.secondary:hover{
    background:#d1d5db;
  }

  .btn.sm{
    padding:6px 12px;
    font-size:12px;
  }

  /* ===== Inputs / Selects ===== */
  input,select{
    padding:8px 10px;
    border:1px solid #d1d5db;
    border-radius:999px;
    font-size:13px;
    background:#ffffff;
    min-width:150px;
  }

  input:focus,select:focus{
    outline:none;
    border-color:var(--accent);
    box-shadow:0 0 0 1px rgba(59,130,246,0.4);
  }

  /* ===== Filters ===== */
  .filter-box{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:12px;
  }

  .filter-box label{
    display:flex;
    flex-direction:column;
    font-size:12px;
    color:var(--text-muted);
  }

  .filter-actions{
    align-self:flex-end;
  }

  .title-main{
    margin:0;
    font-size:20px;
    font-weight:600;
    color:var(--text-main);
  }

  .subtitle{
    font-size:13px;
    color:var(--text-muted);
    margin-top:4px;
  }

  .pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:3px 10px;
    border-radius:999px;
    font-size:11px;
    border:1px solid var(--accent-soft);
    background:var(--accent-soft);
    color:#1d4ed8;
    margin-right:6px;
  }

  .muted{
    color:var(--text-muted);
    font-size:13px;
  }

  /* ===== Table ===== */
  .table-wrap{
    overflow:auto;
    margin-top:4px;
  }

  table.table{
    width:100%;
    border-collapse:separate;
    border-spacing:0 6px;
  }

  .table thead th{
    font-size:11px;
    color:var(--text-muted);
    text-align:right;
    padding:6px 8px;
    font-weight:500;
    background:#f9fafb;
  }

  .table tbody tr{
    background:#fff;
    box-shadow:0 1px 3px rgba(15,23,42,0.06);
  }

  .table td{
    padding:8px 8px;
    font-size:12px;
    border-top:1px solid #e5e7eb;
    border-bottom:1px solid #e5e7eb;
  }

  .table tr td:first-child,
  .table tr th:first-child{
    border-radius:10px 0 0 10px;
    border-right:1px solid #e5e7eb;
  }

  .table tr td:last-child,
  .table tr th:last-child{
    border-radius:0 10px 10px 0;
    border-left:1px solid #e5e7eb;
  }

  /* ===== Pagination ===== */
  .pagination{
    margin-top:12px;
    display:flex;
    gap:6px;
    flex-wrap:wrap;
  }

  .page-link{
    padding:6px 10px;
    border-radius:999px;
    border:1px solid #d1d5db;
    text-decoration:none;
    color:var(--text-main);
    font-size:11px;
    background:#fff;
  }

  .page-link:hover{
    border-color:var(--accent);
  }

  .page-link.active{
    background:var(--accent);
    color:#fff;
    border-color:var(--accent);
  }

  @media (max-width:720px){
    .card{padding:12px;}
    .title-main{font-size:18px;}
    input,select{min-width:130px;}
    table.table{font-size:11px;}
  }
</style>
<link rel="stylesheet" href="/3zbawyh/assets/barcode_theme.css">

</head>
<body>
<div class="shell">

  <div class="topbar">
    <div>
      <div class="topbar-title">لوحة التحكم — المبيعات</div>
      <div class="topbar-sub">عرض وتحليل فواتير المبيعات</div>
    </div>
    <div class="topbar-actions">
      <a href="/3zbawyh/public/dashboard.php">العودة للداشبورد</a>
    </div>
  </div>

  <div class="main">
    <div class="container">

      <!-- Header + فلاتر -->
      <div class="card">
        <div class="row" style="justify-content:space-between;">
          <div>
            <h2 class="title-main">فواتير المبيعات</h2>
            <div class="subtitle">
              إجمالي النتائج: <?= nf($totalRows) ?>
              <?php if($dateCol): ?>
                <span class="pill">من <?=e2($from)?> إلى <?=e2($to)?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="row">
            <?php
              $qs = $_GET; 
              $qs['export'] = 'csv';
              $csvHref = '?'.http_build_query($qs);
            ?>
            <a class="btn secondary" href="/3zbawyh/public/reports.php">ملخص المبيعات</a>
            <a class="btn" href="<?=e2($csvHref)?>">تصدير CSV</a>
          </div>
        </div>

        <?php if(!$dateCol): ?>
          <p class="muted" style="margin-top:8px;">
            ملاحظة: جدول الفواتير لا يحتوي عمود تاريخ واضح؛ فلترة التاريخ قد تكون غير دقيقة.
          </p>
        <?php endif; ?>

        <form class="filter-box" method="get">
          <label>بحث برقم الموبايل:
            <input type="text" name="phone" placeholder="مثال: 01012345678"
                   value="<?=e2($phone)?>">
          </label>

          <label>من:
            <input type="date" name="from" value="<?=e2($from)?>" <?=(!$dateCol ? 'disabled' : '')?>>
          </label>

          <label>إلى:
            <input type="date" name="to" value="<?=e2($to)?>" <?=(!$dateCol ? 'disabled' : '')?>>
          </label>

          <?php if($hasPaymentMethod): ?>
          <label>طريقة الدفع:
            <select name="payment_method">
              <?php foreach($paymentOptions as $val => $label): ?>
                <option value="<?=e2($val)?>" <?=$pay===$val?'selected':''?>>
                  <?=e2($label)?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php endif; ?>

          <label>Limit:
            <input type="number" name="limit" min="20" max="2000" step="10" value="<?=e2($limit)?>">
          </label>

          <div class="filter-actions">
            <button class="btn sm" type="submit">تطبيق الفلتر</button>
          </div>
        </form>
      </div>

      <!-- جدول الفواتير -->
      <div class="card">
        <?php if(!$rows): ?>
          <p class="muted">لا توجد نتائج مطابقة للفلاتر الحالية.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table" dir="rtl">
              <thead>
                <tr>
                  <th>#</th>
                  <th>العميل</th>
                  <th>موبايل</th>
                  <th>الكاشير</th>
                  <?php if($hasPaymentMethod): ?><th>طريقة الدفع</th><?php endif; ?>
                  <th>المجموع الفرعي</th>
                  <th>خصم</th>
                  <th>طرايب</th>
                  <th>اجمالي</th>
                  <th>دفع</th>
                  <th>باقي</th>
                  <th>التاريخ</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($rows as $r): ?>
                <tr>
                  <td><?=e2($r['invoice_no'] ?? $r['id'])?></td>
                  <td><?=e2($r['customer_name'])?></td>
                  <td><?=e2($r['customer_phone'] ?? '')?></td>
                  <td><?=e2($r['cashier_name'])?></td>
                  <?php if($hasPaymentMethod): ?>
                    <td><?=e2($r['payment_method'] ?? '')?></td>
                  <?php endif; ?>
                  <td><?= $r['subtotal'] !== null ? nf($r['subtotal']) : '—' ?></td>
                  <td><?= $r['discount'] !== null ? nf($r['discount']) : '—' ?></td>
                  <td><?= $r['tax']      !== null ? nf($r['tax'])      : '—' ?></td>
                  <td><?= nf($r['total']) ?></td>
                  <td><?= $r['paid_amount'] !== null ? nf($r['paid_amount']) : '—' ?></td>
                  <td><?= $r['change_due']  !== null ? nf($r['change_due'])  : '—' ?></td>
                  <td><?=e2($r['created_at'] ?? '—')?></td>
                  <td>
                    <a class="btn sm" href="/3zbawyh/public/invoice_show.php?id=<?=$r['id']?>">
                      عرض
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if($totalPages > 1): ?>
            <div class="pagination">
              <?php for($p = 1; $p <= $totalPages; $p++):
                $qs = $_GET; 
                $qs['page'] = $p;
                $href = '?'.http_build_query($qs);
              ?>
                <a href="<?=e2($href)?>" class="page-link <?=$p==$page?'active':''?>">
                  <?=$p?>
                </a>
              <?php endfor; ?>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>

    </div>
  </div>

</div>
</body>
</html>
