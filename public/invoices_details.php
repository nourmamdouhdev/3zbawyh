<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_role_in_or_redirect(['admin','cashier']);

date_default_timezone_set('Africa/Cairo');
$db = db();

function e2($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, (floor($n)==$n?0:2), '.', ','); }

function cols_of(PDO $db, string $table): array {
  try{
    $st = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
  }catch(Throwable $e){ return []; }
}
function first_existing_expr(array $have, array $prefs, string $aliasPrefix): ?string {
  $parts = [];
  foreach ($prefs as $p) { if (in_array($p, $have, true)) $parts[] = "$aliasPrefix`$p`"; }
  if (!$parts) return null;
  return 'COALESCE('.implode(',', $parts).')';
}
function detect_date_col(PDO $db, string $table, array $prefs=['created_at','invoice_date','created_on','date','ts','timestamp','time']): ?string {
  try{
    $st = $db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE IN ('datetime','timestamp','date','time')");
    $st->execute([$table]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$rows) return null;
    foreach ($prefs as $p) if (isset($rows[$p])) return $p;
    return array_key_first($rows);
  }catch(Throwable $e){ return null; }
}

$cCols = cols_of($db, 'customers');
$uCols = cols_of($db, 'users');
$siCols= cols_of($db, 'sales_invoices');
$itCols= cols_of($db, 'sales_items');

$CUST_NAME_EXPR   = first_existing_expr($cCols, ['name','customer_name','full_name','title'], 'c.') ?: "CONCAT('عميل #', si.customer_id)";
$CASHIER_NAME_EXPR= first_existing_expr($uCols, ['username','name','full_name','display_name'], 'u.') ?: "CONCAT('كاشير #', si.cashier_id)";
$dateCol = detect_date_col($db, 'sales_invoices');
$hasPaymentMethod = in_array('payment_method', $siCols, true);

/* أعمدة مبالغ اختيارية لو موجودة */
$colSubtotal = in_array('subtotal', $siCols, true) ? 'si.subtotal' : null;
$colDiscount = in_array('discount', $siCols, true) ? 'si.discount' : null;
$colTax      = in_array('tax',      $siCols, true) ? 'si.tax'      : null;
$colTotal    = in_array('total',    $siCols, true) ? 'si.total'    : null;
$colPaid     = in_array('paid_amount',$siCols,true)? 'si.paid_amount' : null;
$colChange   = in_array('change_due',$siCols,true) ? 'si.change_due'  : null;

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$fromTs = $from.' 00:00:00';
$toTs   = $to  .' 23:59:59';

$cashierId  = (int)($_GET['cashier_id']  ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$pay        = trim($_GET['payment_method'] ?? '');
$limit      = max(20, (int)($_GET['limit'] ?? 200)); // حماية
$page       = max(1, (int)($_GET['page'] ?? 1));     // رقم الصفحة الحالية

$where=[]; $params=[];
if ($dateCol)            { $where[]="si.`$dateCol` BETWEEN ? AND ?"; $params[]=$fromTs; $params[]=$toTs; }
if ($cashierId>0)        { $where[]="si.cashier_id=?"; $params[]=$cashierId; }
if ($customerId>0)       { $where[]="si.customer_id=?"; $params[]=$customerId; }
if ($hasPaymentMethod && $pay!==''){ $where[]="si.payment_method=?"; $params[]=$pay; }
$whereSql = $where? implode(' AND ',$where) : '1=1';

$LINE_TOT = "COALESCE(it.line_total, it.qty * it.unit_price)";

if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="invoices_'.$from.'_to_'.$to.'.csv"');
  $out = fopen('php://output','w');
  $hdr = ['ID','InvoiceNo','Date','Customer','Cashier'];
  if($hasPaymentMethod) $hdr[]='PaymentMethod';
  array_push($hdr,'Subtotal','Discount','Tax','Total','Paid','Change');
  fputcsv($out,$hdr);

  $sql = "
    SELECT si.id, si.invoice_no, ".($dateCol? "si.`$dateCol`":"NULL")." AS created_at,
           $CUST_NAME_EXPR AS customer_name,
           $CASHIER_NAME_EXPR AS cashier_name
           ".($hasPaymentMethod? ", si.payment_method":"").",
           ".($colSubtotal?: "NULL")." AS subtotal,
           ".($colDiscount?: "NULL")." AS discount,
           ".($colTax?:      "NULL")." AS tax,
           ".($colTotal?:    "SUM($LINE_TOT)")." AS total,
           ".($colPaid?:     "NULL")." AS paid_amount,
           ".($colChange?:   "NULL")." AS change_due
    FROM sales_invoices si
    LEFT JOIN sales_items it ON it.invoice_id = si.id
    ".($cCols? "LEFT JOIN customers c ON c.id = si.customer_id":"")."
    ".($uCols? "LEFT JOIN users u     ON u.id = si.cashier_id":"")."
    WHERE $whereSql
    GROUP BY si.id
    ORDER BY ".($dateCol? "si.`$dateCol`":"si.id")." DESC
    LIMIT $limit";
  $st=$db->prepare($sql); $st->execute($params);
  while($r=$st->fetch(PDO::FETCH_ASSOC)){
    $row=[ $r['id'], $r['invoice_no'], $r['created_at'], $r['customer_name'], $r['cashier_name'] ];
    if($hasPaymentMethod) $row[] = $r['payment_method'] ?? '';
    $row[] = $r['subtotal'];
    $row[] = $r['discount'];
    $row[] = $r['tax'];
    $row[] = $r['total'];
    $row[] = $r['paid_amount'];
    $row[] = $r['change_due'];
    fputcsv($out,$row);
  }
  fclose($out); exit;
}

/* حساب عدد الفواتير الكلي لنفس الفلتر للـ pagination */
$countSql = "SELECT COUNT(*) FROM sales_invoices si WHERE $whereSql";
$stCount = $db->prepare($countSql);
$stCount->execute($params);
$totalRows   = (int)$stCount->fetchColumn();
$totalPages  = max(1, (int)ceil($totalRows / $limit));

if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$sql = "
  SELECT si.id, si.invoice_no, ".($dateCol? "si.`$dateCol`":"NULL")." AS created_at,
         $CUST_NAME_EXPR AS customer_name,
         $CASHIER_NAME_EXPR AS cashier_name
         ".($hasPaymentMethod? ", si.payment_method":"").",
         ".($colSubtotal?: "NULL")." AS subtotal,
         ".($colDiscount?: "NULL")." AS discount,
         ".($colTax?:      "NULL")." AS tax,
         ".($colTotal?:    "SUM($LINE_TOT)")." AS total,
         ".($colPaid?:     "NULL")." AS paid_amount,
         ".($colChange?:   "NULL")." AS change_due
  FROM sales_invoices si
  LEFT JOIN sales_items it ON it.invoice_id = si.id
  ".($cCols? "LEFT JOIN customers c ON c.id = si.customer_id":"")."
  ".($uCols? "LEFT JOIN users u     ON u.id = si.cashier_id":"")."
  WHERE $whereSql
  GROUP BY si.id
  ORDER BY ".($dateCol? "si.`$dateCol`":"si.id")." DESC
  LIMIT $limit OFFSET $offset";
$st=$db->prepare($sql); $st->execute($params);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);

/* قوائم فلاتر */
$cashiers  = $db->query("SELECT id, ".(in_array('username',$uCols,true)?'username':(in_array('name',$uCols,true)?'name':'id'))." AS label FROM users ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query("SELECT id, ".(in_array('name',$cCols,true)?'name':(in_array('full_name',$cCols,true)?'full_name':'id'))." AS label FROM customers ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);
$pays = [];
if ($hasPaymentMethod){
  $pays = $db->query("SELECT DISTINCT payment_method FROM sales_invoices WHERE payment_method IS NOT NULL AND payment_method <> '' ORDER BY payment_method ASC")->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>فواتير المبيعات — تفاصيل</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{background:#f6f7fb;color:#111;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,Arial}
  .container{max-width:1200px;margin:0 auto;padding:16px}
  .card{background:#fff;border:1px solid #eee;border-radius:14px;padding:14px}
  .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .btn{display:inline-block;background:#111;color:#fff;border:none;padding:10px 12px;border-radius:10px;text-decoration:none}
  input,select{padding:8px;border:1px solid #ddd;border-radius:8px}
  .table{width:100%;border-collapse:separate;border-spacing:0 8px}
  .table th{font-size:13px;color:#6b7280;text-align:right}
  .table td,.table th{padding:8px 10px;background:#fff}
  .muted{color:#6b7280}
  .pagination{margin-top:12px;display:flex;gap:6px;flex-wrap:wrap}
  .page-link{padding:6px 10px;border-radius:8px;border:1px solid #ddd;text-decoration:none;color:#111;font-size:13px}
  .page-link.active{background:#111;color:#fff;border-color:#111}
</style>
</head>
<body>
<div class="container">
  <div class="card" style="margin-bottom:12px">
    <div class="row" style="justify-content:space-between">
      <h2 style="margin:0">فواتير المبيعات — تفاصيل</h2>
      <div class="row">
        <a class="btn" href="/3zbawyh/public/reports.php">رجوع للـ Summary</a>
        <a class="btn" href="?from=<?=e2($from)?>&to=<?=e2($to)?>&cashier_id=<?=$cashierId?>&customer_id=<?=$customerId?>&payment_method=<?=e2($pay)?>&limit=<?=$limit?>&export=csv">تصدير CSV</a>
      </div>
    </div>

    <?php if(!$dateCol): ?>
      <p class="muted">ملاحظة: لا يوجد عمود تاريخ بجدول الفواتير؛ الفلترة بالتاريخ ستتعطل.</p>
    <?php endif; ?>

    <form class="row" method="get" style="margin-top:10px">
      <label>من: <input type="date" name="from" value="<?=e2($from)?>" <?=(!$dateCol?'disabled':'')?>></label>
      <label>إلى: <input type="date" name="to" value="<?=e2($to)?>" <?=(!$dateCol?'disabled':'')?>></label>

      <label>الكاشير:
        <select name="cashier_id">
          <option value="0">الكل</option>
          <?php foreach($cashiers as $c): ?>
            <option value="<?=$c['id']?>" <?=$cashierId==$c['id']?'selected':''?>><?=e2($c['label'])?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>العميل:
        <select name="customer_id">
          <option value="0">الكل</option>
          <?php foreach($customers as $c): ?>
            <option value="<?=$c['id']?>" <?=$customerId==$c['id']?'selected':''?>><?=e2($c['label'])?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <?php if($hasPaymentMethod): ?>
      <label>طريقة الدفع:
        <select name="payment_method">
          <option value="">الكل</option>
          <?php foreach($pays as $p2): ?>
            <option value="<?=e2($p2)?>" <?=$pay===$p2?'selected':''?>><?=e2($p2)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>

      <label>Limit: <input type="number" name="limit" min="20" max="2000" step="10" value="<?=e2($limit)?>"></label>

      <button class="btn" type="submit">تطبيق</button>
    </form>
  </div>

  <div class="card">
    <?php if(!$rows): ?>
      <p class="muted">لا توجد بيانات.</p>
    <?php else: ?>
      <table class="table" dir="rtl">
        <thead>
          <tr>
            <th>#</th><th>العميل</th><th>الكاشير</th>
            <?php if($hasPaymentMethod): ?><th>الدفع</th><?php endif; ?>
            <th>Subtotal</th><th>Discount</th><th>Tax</th><th>Total</th><th>Paid</th><th>Change</th>
            <th>التاريخ</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=e2($r['invoice_no'] ?? $r['id'])?></td>
            <td><?=e2($r['customer_name'])?></td>
            <td><?=e2($r['cashier_name'])?></td>
            <?php if($hasPaymentMethod): ?><td><?=e2($r['payment_method'] ?? '')?></td><?php endif; ?>
            <td><?= $r['subtotal'] !== null ? nf($r['subtotal']) : '—' ?></td>
            <td><?= $r['discount'] !== null ? nf($r['discount']) : '—' ?></td>
            <td><?= $r['tax']      !== null ? nf($r['tax'])      : '—' ?></td>
            <td><?= nf($r['total']) ?></td>
            <td><?= $r['paid_amount'] !== null ? nf($r['paid_amount']) : '—' ?></td>
            <td><?= $r['change_due']  !== null ? nf($r['change_due'])  : '—' ?></td>
            <td><?=e2($r['created_at'] ?? '—')?></td>
            <td><a class="btn" href="/3zbawyh/public/invoice_show.php?id=<?=$r['id']?>">عرض</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if($rows && $totalPages > 1): ?>
        <div class="pagination">
          <?php
            for($p = 1; $p <= $totalPages; $p++):
              $qs = $_GET;
              $qs['page'] = $p;
              $href = '?'.http_build_query($qs);
          ?>
            <a href="<?=e2($href)?>" class="page-link <?=$p == $page ? 'active' : ''?>">
              <?=$p?>
            </a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
