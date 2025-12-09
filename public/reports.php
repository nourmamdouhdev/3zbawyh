<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();
require_admin_or_redirect();

date_default_timezone_set('Africa/Cairo');
$db = db();

function e2($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, (floor($n)==$n?0:2), '.', ','); }

/* ---------- Helpers ---------- */
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

/* أعمدة متاحة */
$cCols = cols_of($db, 'customers');
$uCols = cols_of($db, 'users');
$siCols= cols_of($db, 'sales_invoices');

$CUST_NAME_EXPR    = first_existing_expr($cCols, ['name','customer_name','full_name','title'], 'c.') ?: "CONCAT('عميل #', si.customer_id)";
$CASHIER_NAME_EXPR = first_existing_expr($uCols, ['username','name','full_name','display_name'], 'u.') ?: "CONCAT('كاشير #', si.cashier_id)";
$dateCol = detect_date_col($db, 'sales_invoices');
$hasPaymentMethod = in_array('payment_method', $siCols, true);
$hasPaymentNote   = in_array('payment_note',   $siCols, true);

/* ---------- فلاتر عامة ---------- */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$fromTs = $from.' 00:00:00';
$toTs   = $to  .' 23:59:59';

$cashierId  = (int)($_GET['cashier_id']  ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$catId      = (int)($_GET['category_id'] ?? 0);

$where=[]; $params=[];
if ($dateCol)        { $where[]="si.`$dateCol` BETWEEN ? AND ?"; $params[]=$fromTs; $params[]=$toTs; }
if ($cashierId>0)    { $where[]="si.cashier_id=?"; $params[]=$cashierId; }
if ($customerId>0)   { $where[]="si.customer_id=?"; $params[]=$customerId; }
if ($catId>0)        { $where[]="i.category_id=?"; $params[]=$catId; }
$whereSql = $where? implode(' AND ',$where) : '1=1';

/* ---------- إجمالي الفترة وعدد فواتيرها ---------- */
$LINE_TOT = "COALESCE(it.line_total, it.qty * it.unit_price)";
$sqlSum = "
  SELECT COALESCE(SUM($LINE_TOT),0) AS total_sales, COUNT(DISTINCT si.id) AS invoices_count
  FROM sales_invoices si
  LEFT JOIN sales_items it ON it.invoice_id = si.id
  LEFT JOIN items i        ON i.id = it.item_id
  ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
  ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
  WHERE $whereSql";
$st=$db->prepare($sqlSum); $st->execute($params);
list($totalSalesRange, $countInvoicesRange) = $st->fetch(PDO::FETCH_NUM);

/* ---------- لوحة النهارده ---------- */
if ($dateCol) {
  $whereToday = "DATE(si.`$dateCol`) = CURDATE()";
  $paramsToday = [];
} else {
  $whereToday = "DATE(NOW()) = DATE(NOW())";
  $paramsToday = [];
}

/* إجمالي النهارده وعدد فواتيره */
$sqlToday = "
  SELECT COALESCE(SUM($LINE_TOT),0) AS total_today, COUNT(DISTINCT si.id) AS cnt_today
  FROM sales_invoices si
  LEFT JOIN sales_items it ON it.invoice_id = si.id
  WHERE $whereToday";
$st=$db->prepare($sqlToday); $st->execute($paramsToday);
list($totalToday, $countToday) = $st->fetch(PDO::FETCH_NUM);

/* ---------- طرق الدفع النهارده (يدعم mixed بتفكيك payment_note) ---------- */
$payToday = [
  'cash'          => ['label'=>'Cash',           'sum'=>0.0,'cnt'=>0],
  'visa'          => ['label'=>'Visa',           'sum'=>0.0,'cnt'=>0],
  'instapay'      => ['label'=>'InstaPay',       'sum'=>0.0,'cnt'=>0],
  'vodafone_cash' => ['label'=>'Vodafone Cash',  'sum'=>0.0,'cnt'=>0],
  'agel'          => ['label'=>'آجل',            'sum'=>0.0,'cnt'=>0],
  'other'         => ['label'=>'أخرى',           'sum'=>0.0,'cnt'=>0],
];

function parse_distribution_note_today(?string $note): array {
  $res = ['cash'=>0,'visa'=>0,'instapay'=>0,'vodafone_cash'=>0,'agel'=>0,'other'=>0];
  if (!$note) return $res;
  $note = trim($note);

  // MULTI;method,amount,ref,note;...
  if (stripos($note,'MULTI;')===0) {
    $parts = explode(';',$note); array_shift($parts);
    foreach ($parts as $seg) {
      if ($seg==='') continue;
      $bits = explode(',',$seg);
      $m = isset($bits[0]) ? strtolower(trim(urldecode($bits[0]))) : '';
      $a = isset($bits[1]) ? (float)urldecode($bits[1]) : 0.0;
      if ($m==='agyl') $m='agel';
      if (!isset($res[$m])) $m='other';
      $res[$m]+= $a;
    }
    return $res;
  }

  // Dist: cash=.., visa=.., instapay=.., vodafone_cash=.., agyl=..
  if (stripos($note,'dist:')===0) {
    $str = trim(substr($note,5));
    foreach (explode(',', $str) as $pair) {
      $pair = trim($pair); if ($pair==='') continue;
      $kv = explode('=',$pair); if (count($kv)!==2) continue;
      $k = strtolower(trim($kv[0])); $v = (float)trim($kv[1]);
      if ($k==='agyl') $k='agel';
      if (!isset($res[$k])) $k='other';
      $res[$k]+= $v;
    }
  }
  return $res;
}

if ($dateCol && $hasPaymentMethod) {
  $sqlPayToday = "
    SELECT 
      si.id,
      LOWER(TRIM(si.payment_method)) AS pm,
      ".($hasPaymentNote ? "si.payment_note," : "NULL AS payment_note,")."
      COALESCE(SUM($LINE_TOT),0) AS inv_total
    FROM sales_invoices si
    LEFT JOIN sales_items it ON it.invoice_id = si.id
    WHERE $whereToday
    GROUP BY si.id, pm, payment_note
  ";
  $rows = $db->query($sqlPayToday)->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r) {
    $pm   = strtolower(trim((string)($r['pm'] ?? '')));
    $note = (string)($r['payment_note'] ?? '');
    $invT = (float)$r['inv_total'];

    if (in_array($pm, ['cash','visa','instapay','vodafone_cash','agel'], true)) {
      $payToday[$pm]['sum'] += $invT;
      $payToday[$pm]['cnt'] += 1;
    } elseif ($pm==='mixed') {
      $dist = parse_distribution_note_today($note);
      foreach (['cash','visa','instapay','vodafone_cash','agel','other'] as $k) {
        if (!empty($dist[$k])) $payToday[$k]['sum'] += (float)$dist[$k];
      }
      // عدّ الفاتورة ضمن أخرى (أو ممكن تزود عدّاد كل طريقة لو عايز)
      $payToday['other']['cnt'] += 1;
    } else {
      $payToday['other']['sum'] += $invT;
      $payToday['other']['cnt'] += 1;
    }
  }
}

/* ---------- جدول آخر 8 فواتير ---------- */
$sqlLast = "
  SELECT 
    si.id, si.invoice_no, ".($dateCol ? "si.`$dateCol`" : "NULL")." AS created_at,
    $CASHIER_NAME_EXPR AS cashier_name,
    $CUST_NAME_EXPR    AS customer_name,
    ".($hasPaymentMethod ? "si.payment_method," : "")."
    COALESCE(SUM($LINE_TOT),0) AS total_amount
  FROM sales_invoices si
  LEFT JOIN sales_items it ON it.invoice_id = si.id
  ".($cCols ? "LEFT JOIN customers c ON c.id = si.customer_id" : "")."
  ".($uCols ? "LEFT JOIN users u ON u.id = si.cashier_id" : "")."
  GROUP BY si.id
  ORDER BY ".($dateCol ? "si.`$dateCol`" : "si.id")." DESC
  LIMIT 8";
$rowsLast = $db->query($sqlLast)->fetchAll(PDO::FETCH_ASSOC);

/* روابط اختيارية */
$cashiers  = $db->query("SELECT id, ".(in_array('username',$uCols,true)?'username':(in_array('name',$uCols,true)?'name':'id'))." AS label FROM users ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query("SELECT id, ".(in_array('name',$cCols,true)?'name':(in_array('full_name',$cCols,true)?'full_name':'id'))." AS label FROM customers ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>تقارير المبيعات  </title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{background:#f6f7fb;color:#111;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,Arial}
    .container{max-width:1200px;margin:0 auto;padding:16px}
    .card{background:#fff;border:1px solid #eee;border-radius:14px;padding:14px}
    .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    .grid{display:grid;gap:12px}
    @media(min-width:940px){.grid.cols-4{grid-template-columns:1fr 1fr 1fr 1fr}}
    .btn{display:inline-block;background:#111;color:#fff;border:none;padding:10px 12px;border-radius:10px;text-decoration:none}
    .btn.secondary{background:#6b7280}
    .muted{color:#6b7280}
    .kpi{display:flex;flex-direction:column;gap:6px;background:#fff;border:1px solid #eee;border-radius:12px;padding:12px}
    .kpi .h{font-size:13px;color:#6b7280}
    .kpi .v{font-size:22px;font-weight:700}
    .table{width:100%;border-collapse:separate;border-spacing:0 8px}
    .table th{font-size:13px;color:#6b7280;text-align:right}
    .table td,.table th{padding:8px 10px;background:#fff}
    .pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;font-size:12px}
    .section-title{margin:0 0 8px}
  </style>
</head>
<body>
<div class="container">

  <div class="card" style="margin-bottom:12px">
    <div class="row" style="justify-content:space-between">
      <h2 style="margin:0">تقارير المبيعات  </h2>
      <div class="row">
        <a class="btn" href="/3zbawyh/public/invoices_details.php">صفحة الفواتير التفصيلية</a>
        <a class="btn" href="/3zbawyh/public/dashboard.php">عودة للوحة</a>
      </div>
    </div>
  </div>

  <!-- KPIs النهارده -->


  <!-- طرق الدفع النهارده -->
  <div class="grid cols-4" style="margin-top:12px">
    <div class="kpi">
      <div class="h">Cash — اليوم</div>
      <div class="v"><?=nf($payToday['cash']['sum'])?> EGP</div>
      <div class="h"><span class="pill"><?=nf($payToday['cash']['cnt'])?> فاتورة</span></div>
    </div>
    <div class="kpi">
      <div class="h">Visa — اليوم</div>
      <div class="v"><?=nf($payToday['visa']['sum'])?> EGP</div>
      <div class="h"><span class="pill"><?=nf($payToday['visa']['cnt'])?> فاتورة</span></div>
    </div>
    <div class="kpi">
      <div class="h">InstaPay — اليوم</div>
      <div class="v"><?=nf($payToday['instapay']['sum'])?> EGP</div>
      <div class="h"><span class="pill"><?=nf($payToday['instapay']['cnt'])?> فاتورة</span></div>
    </div>
    <div class="kpi">
      <div class="h">Vodafone Cash — اليوم</div>
      <div class="v"><?=nf($payToday['vodafone_cash']['sum'])?> EGP</div>
      <div class="h"><span class="pill"><?=nf($payToday['vodafone_cash']['cnt'])?> فاتورة</span></div>
    </div>
  </div>

  <div class="grid cols-4" style="margin-top:12px">
    <div class="kpi">
      <div class="h">آجل — اليوم</div>
      <div class="v"><?=nf($payToday['agel']['sum'])?> EGP</div>
      <div class="h"><span class="pill"><?=nf($payToday['agel']['cnt'])?> فاتورة</span></div>
    </div>
    <?php if($payToday['other']['cnt']>0 || $payToday['other']['sum']>0): ?>
    <div class="kpi">
      <div class="h">طرق دفع أخرى — اليوم</div>
      <div class="v"><?=nf($payToday['other']['sum'])?> EGP</div>
      <div class="h"><span class="pill"><?=nf($payToday['other']['cnt'])?> فاتورة</span></div>
    </div>
    <?php endif; ?>
          <div class="kpi"><div class="h">عدد الفواتير — اليوم</div><div class="v"><?=nf($countToday)?></div></div>
    <div class="kpi"><div class="h">إجمالي مبيعات اليوم</div><div class="v"><?=nf($totalToday)?> EGP</div></div>
  </div>

  <!-- آخر 8 فواتير -->
  <div class="card" style="margin-top:12px">
    <div class="row" style="justify-content:space-between">
      <h3 class="section-title">آخر 8 فواتير</h3>
      <a class="btn" href="/3zbawyh/public/invoices_details.php">عرض كل الفواتير</a>
    </div>
    <?php if(!$rowsLast): ?>
      <p class="muted">لا توجد بيانات.</p>
    <?php else: ?>
      <table class="table" dir="rtl">
        <thead>
          <tr>
            <th>#</th><th>العميل</th><th>الكاشير</th>
            <?php if($hasPaymentMethod): ?><th>طريقة الدفع</th><?php endif; ?>
            <th>الإجمالي</th><th>التاريخ</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rowsLast as $r): ?>
          <tr>
            <td><?=e2($r['invoice_no'] ?? $r['id'])?></td>
            <td><?=e2($r['customer_name'])?></td>
            <td><?=e2($r['cashier_name'])?></td>
            <?php if($hasPaymentMethod): ?><td><?=e2($r['payment_method'] ?? '')?></td><?php endif; ?>
            <td><?=nf($r['total_amount'])?> EGP</td>
            <td><?=e2($r['created_at'] ?? '—')?></td>
            <td><a class="btn" href="/3zbawyh/public/invoice_show.php?id=<?=$r['id']?>">عرض</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
