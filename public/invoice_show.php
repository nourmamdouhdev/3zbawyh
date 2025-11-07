<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();

$db = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('فاتورة غير موجودة'); }

date_default_timezone_set('Africa/Cairo');

/* ========== دوال مساعدة عامة ========== */
function e2($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, (floor($n)==$n?0:2), '.', ','); }

function columns_of(PDO $db, $table){
  $st=$db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->execute([$table]); return $st->fetchAll(PDO::FETCH_COLUMN);
}
function pick_col(array $cols, array $candidates){
  foreach ($candidates as $c){ if (in_array($c, $cols, true)) return $c; }
  return null;
}

/* ========== تحديد أسماء الجداول والأعمدة تلقائيًا ========== */
$invoicesTable = 'sales_invoices';
if (function_exists('table_exists')) {
  if (!table_exists($db, $invoicesTable)) {
    foreach (['invoices','sale_invoices','sales_invoice'] as $t){
      if (table_exists($db, $t)) { $invoicesTable = $t; break; }
    }
  }
}

$itemsTable = null;
foreach (['sales_items','invoice_items','sale_items','items_sold'] as $t){
  if (function_exists('table_exists') ? table_exists($db,$t) : true) {
    // لو table_exists مش متاحة هنفترض أول اسم موجود
    try{
      $db->query("SELECT 1 FROM `$t` LIMIT 1");
      $itemsTable = $t; break;
    }catch(Throwable $e){}
  }
}
if (!$itemsTable) { http_response_code(500); exit('لم يتم العثور على جدول البنود (sales_items / invoice_items).'); }

$customersTable = null;
try{
  $db->query("SELECT 1 FROM customers LIMIT 1");
  $customersTable = 'customers';
}catch(Throwable $e){}

/* أعمدة جدول الفواتير */
$invCols = columns_of($db, $invoicesTable);
$invIdCol      = pick_col($invCols, ['id','invoice_id']);
$invNoCol      = pick_col($invCols, ['invoice_no','number','invoice_number','code']) ?? $invIdCol;
$invDateCol    = pick_col($invCols, ['created_at','invoice_date','date','ts','timestamp','created_on']);
$invSubtotal   = pick_col($invCols, ['subtotal']);
$invDiscount   = pick_col($invCols, ['discount']);
$invTax        = pick_col($invCols, ['tax']);
$invTotalCol   = pick_col($invCols, ['total','grand_total']);
$invPayMethod  = pick_col($invCols, ['payment_method']);
$invPaidAmount = pick_col($invCols, ['paid_amount','amount_paid']);
$invChangeDue  = pick_col($invCols, ['change_due','change']);
$invPayRef     = pick_col($invCols, ['payment_ref','ref_no','reference']);
$invPayNote    = pick_col($invCols, ['payment_note','note','notes']); // هنراعي عدم تخريب notes العامة

if (!$invIdCol) { http_response_code(500); exit('لم يتم العثور على عمود معرف الفاتورة في جدول الفواتير.'); }

/* أعمدة جدول البنود */
$itemCols    = columns_of($db, $itemsTable);
$itemFkCol   = pick_col($itemCols, ['sales_invoice_id','invoice_id','inv_id']);
$itemNameCol = pick_col($itemCols, ['product_name','item_name','name','title','description']);
$itemQtyCol  = pick_col($itemCols, ['quantity','qty','qte','amount','count']);
$itemPriceCol= pick_col($itemCols, ['unit_price','price','sell_price','unitprice','rate']);
$itemLineCol = pick_col($itemCols, ['line_total','total','subtotal','lineamount','amount_total']);
if (!$itemFkCol) { http_response_code(500); exit('لم يتم العثور على عمود الربط بالفاتورة داخل جدول البنود (invoice_id).'); }

/* العملاء (اختياري) */
$customerNameExpr = "'عميل نقدي'";
if ($customersTable) {
  $custCols = columns_of($db, $customersTable);
  $custNameCol = pick_col($custCols, ['name','customer_name','full_name']);
  if ($custNameCol) $customerNameExpr = "COALESCE(c.`$custNameCol`, 'عميل نقدي')";
}

/* ========== تعبيرات البنود ========== */
$itAlias = 'it';
$qtyExpr   = $itemQtyCol   ? "$itAlias.`$itemQtyCol`"   : "1";
$priceExpr = $itemPriceCol ? "$itAlias.`$itemPriceCol`" : "0";
$lineExpr  = $itemLineCol  ? "COALESCE($itAlias.`$itemLineCol`, ($qtyExpr * $priceExpr))"
                           : "($qtyExpr * $priceExpr)";
$dateSelect = $invDateCol ? "si.`$invDateCol` AS created_at" : "NULL AS created_at";

/* ========== جلب الفاتورة ========== */
$selectExtra = [];
if ($invSubtotal)   $selectExtra[] = "si.`$invSubtotal`   AS subtotal";
if ($invDiscount)   $selectExtra[] = "si.`$invDiscount`   AS discount";
if ($invTax)        $selectExtra[] = "si.`$invTax`        AS tax";
if ($invTotalCol)   $selectExtra[] = "si.`$invTotalCol`   AS total_db";
if ($invPayMethod)  $selectExtra[] = "LOWER(TRIM(si.`$invPayMethod`)) AS payment_method";
if ($invPaidAmount) $selectExtra[] = "si.`$invPaidAmount` AS paid_amount";
if ($invChangeDue)  $selectExtra[] = "si.`$invChangeDue`  AS change_due";
if ($invPayRef)     $selectExtra[] = "si.`$invPayRef`     AS payment_ref";
if ($invPayNote)    $selectExtra[] = "si.`$invPayNote`    AS payment_note";

$sqlInv = "
  SELECT
    si.`$invIdCol`   AS id,
    si.`$invNoCol`   AS invoice_no,
    $customerNameExpr AS customer_name,
    $dateSelect,
    (
      SELECT COALESCE(SUM($lineExpr), 0)
      FROM `$itemsTable` $itAlias
      WHERE $itAlias.`$itemFkCol` = si.`$invIdCol`
    ) AS total_amount
    ".($selectExtra ? ",".implode(",", $selectExtra) : "")."
  FROM `$invoicesTable` si
  ".($customersTable ? "LEFT JOIN `$customersTable` c ON c.id = si.customer_id" : "")."
  WHERE si.`$invIdCol` = ?
  LIMIT 1
";
$st = $db->prepare($sqlInv);
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); exit('الفاتورة غير موجودة'); }

/* ========== جلب البنود ========== */
$nameExpr = $itemNameCol ? "$itAlias.`$itemNameCol`" : "CONCAT('بند #', $itAlias.id)";
$sqlItems = "
  SELECT
    $itAlias.id,
    $nameExpr AS product_name,
    ".($itemQtyCol   ? "$itAlias.`$itemQtyCol`"   : "1")."  AS quantity,
    ".($itemPriceCol ? "$itAlias.`$itemPriceCol`" : "0")."  AS unit_price,
    $lineExpr AS line_total
  FROM `$itemsTable` $itAlias
  WHERE $itAlias.`$itemFkCol` = ?
  ORDER BY $itAlias.id ASC
";
$sti = $db->prepare($sqlItems);
$sti->execute([$id]);
$items = $sti->fetchAll(PDO::FETCH_ASSOC);

/* ========== فك الـpayment_note (لو موجود) ========== */
function parse_distribution_note(?string $note): array {
  $res = ['cash'=>0,'visa'=>0,'instapay'=>0,'vodafone_cash'=>0,'agel'=>0,'other'=>0];
  if (!$note) return $res;

  $note = trim($note);

  // 1) MULTI;method,amount,ref,note;...
  if (stripos($note, 'MULTI;') === 0) {
    $parts = explode(';', $note);
    array_shift($parts); // remove MULTI
    foreach ($parts as $seg) {
      if ($seg === '') continue;
      $bits = explode(',', $seg);
      $method = isset($bits[0]) ? strtolower(trim(urldecode($bits[0]))) : '';
      $amount = isset($bits[1]) ? (float)urldecode($bits[1]) : 0;
      if ($method === 'agyl') $method = 'agel';
      if (!isset($res[$method])) $method = 'other';
      $res[$method] += $amount;
    }
    return $res;
  }

  // 2) Dist: cash=.., visa=.., instapay=.., vodafone_cash=.., agyl=..
  if (stripos($note, 'dist:') === 0) {
    $str = trim(substr($note, 5));
    foreach (explode(',', $str) as $pair) {
      $pair = trim($pair);
      if ($pair === '') continue;
      $kv = explode('=', $pair);
      if (count($kv) !== 2) continue;
      $k = strtolower(trim($kv[0]));
      $v = (float)trim($kv[1]);
      if ($k === 'agyl') $k = 'agel';
      if (!isset($res[$k])) $k = 'other';
      $res[$k] += $v;
    }
    return $res;
  }

  // أي نص تاني: هنظهره كما هو، بس مش هنقدر نوزع منه
  return $res;
}

/* تجهيز التاريخ للعرض */
$created_at_text = ($inv['created_at'] ?? null) ? date('Y-m-d H:i', strtotime($inv['created_at'])) : '—';

/* نحضر بيانات الدفع للعرض */
$pm   = strtolower(trim((string)($inv['payment_method'] ?? '')));
$ref  = trim((string)($inv['payment_ref'] ?? ''));
$note = (string)($inv['payment_note'] ?? '');

$dist = parse_distribution_note($note);

$byMethod = ['cash'=>0,'visa'=>0,'instapay'=>0,'vodafone_cash'=>0,'agel'=>0,'other'=>0];

if ($pm === 'mixed') {
  // لو mixed نستخدم التوزيع من الـnote (لو فاضي هتفضل صفار)
  $byMethod = $dist;
} else {
  // طريقة واحدة: نسجل إجمالي الفاتورة للطريقة دي
  $single = $pm;
  if ($single === 'agyl') $single = 'agel';
  if (!isset($byMethod[$single])) $single = 'other';
  $byMethod[$single] = (float)$inv['total_amount'];
}

/* لو total_db موجود نفضله على المحسوب من البنود */
$totalDisplay = isset($inv['total_db']) ? (float)$inv['total_db'] : (float)$inv['total_amount'];

$subtotal = isset($inv['subtotal']) ? (float)$inv['subtotal'] : null;
$discount = isset($inv['discount']) ? (float)$inv['discount'] : null;
$tax      = isset($inv['tax'])      ? (float)$inv['tax']      : null;
$paidAmt  = isset($inv['paid_amount']) ? (float)$inv['paid_amount'] : null;
$change   = isset($inv['change_due'])  ? (float)$inv['change_due']  : null;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>فاتورة #<?=e2($inv['invoice_no'] ?? $inv['id'])?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{background:#f6f7fb;color:#111;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,Arial}
    .container{max-width:900px;margin:20px auto;padding:16px}
    .card{background:#fff;border:1px solid #eee;border-radius:14px;padding:14px;margin-bottom:12px}
    .table{width:100%;border-collapse:separate;border-spacing:0 8px}
    .table th{font-size:13px;color:#6b7280;text-align:right}
    .table td,.table th{padding:8px 10px;background:#fff}
    .row{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
    .btn{display:inline-block;background:#111;color:#fff;border:none;padding:10px 12px;border-radius:10px;text-decoration:none}
    .muted{color:#6b7280}
    .pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;font-size:12px}
    .grid{display:grid;gap:10px}
    @media(min-width:820px){.cols-2{grid-template-columns:1fr 1fr}}
  </style>
</head>
<body>
<div class="container">

  <div class="row">
    <a class="btn" href="/3zbawyh/public/dashboard.php">رجوع</a>
    <a class="btn" href="javascript:window.print()">طباعة</a>
  </div>

  <div class="card">
    <h2 style="margin:0">فاتورة #<?=e2($inv['invoice_no'] ?? $inv['id'])?></h2>
    <div class="muted">
      العميل: <?=e2($inv['customer_name'] ?? 'عميل نقدي')?> —
      التاريخ: <?=e2($created_at_text)?>
    </div>
  </div>

  <div class="card">
    <table class="table" dir="rtl">
      <thead><tr><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?=e2($it['product_name'])?></td>
            <td><?=nf($it['quantity'])?></td>
            <td><?=nf($it['unit_price'])?> EGP</td>
            <td><?=nf($it['line_total'])?> EGP</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php if ($subtotal !== null): ?>
          <tr><td colspan="3" style="text-align:left">المجموع قبل الخصم/الضريبة</td><td><?=nf($subtotal)?> EGP</td></tr>
        <?php endif; ?>
        <?php if ($discount !== null): ?>
          <tr><td colspan="3" style="text-align:left">خصم</td><td><?=nf($discount)?> EGP</td></tr>
        <?php endif; ?>
        <?php if ($tax !== null): ?>
          <tr><td colspan="3" style="text-align:left">ضريبة</td><td><?=nf($tax)?> EGP</td></tr>
        <?php endif; ?>
        <tr>
          <td colspan="3" style="text-align:left"><strong>الإجمالي</strong></td>
          <td><strong><?=nf($totalDisplay)?> EGP</strong></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="grid cols-2">
    <div class="card">
      <h3 style="margin:4px 0 10px">بيانات الدفع</h3>
      <div class="muted" style="margin-bottom:6px">
        طريقة الدفع: <span class="pill"><?= e2($pm !== '' ? $pm : '—') ?></span>
        <?php if ($ref !== ''): ?>
          &nbsp; مرجع: <span class="pill"><?= e2($ref) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($paidAmt !== null || $change !== null): ?>
        <div class="muted" style="margin-bottom:6px">
          <?php if ($paidAmt !== null): ?>المدفوع: <strong><?=nf($paidAmt)?></strong> EGP<?php endif; ?>
          <?php if ($change !== null): ?>&nbsp; | &nbsp; الباقي (كاش): <strong><?=nf($change)?></strong> EGP<?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($note !== ''): ?>
        <div><strong>ملاحظة الدفع:</strong> <?= nl2br(e2($note)) ?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="margin:4px 0 10px">توزيع المبالغ حسب الطريقة</h3>
      <table class="table" dir="rtl">
        <thead><tr><th>الطريقة</th><th>المبلغ</th></tr></thead>
        <tbody>
          <tr><td>Cash</td><td><?=nf($byMethod['cash'])?> EGP</td></tr>
          <tr><td>Visa</td><td><?=nf($byMethod['visa'])?> EGP</td></tr>
          <tr><td>InstaPay</td><td><?=nf($byMethod['instapay'])?> EGP</td></tr>
          <tr><td>Vodafone Cash</td><td><?=nf($byMethod['vodafone_cash'])?> EGP</td></tr>
          <tr><td>آجل</td><td><?=nf($byMethod['agel'])?> EGP</td></tr>
          <?php if ($byMethod['other'] > 0.0001): ?>
            <tr><td>أخرى</td><td><?=nf($byMethod['other'])?> EGP</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <td style="text-align:left"><strong>إجمالي موزّع</strong></td>
            <td><strong>
              <?php
                $distSum = array_sum($byMethod);
                echo nf($distSum), ' EGP';
              ?>
            </strong></td>
          </tr>
        </tfoot>
      </table>
      <?php
      // تنبيه بسيط لو الإجمالي الموزّع مختلف عن إجمالي الفاتورة
      $distSum = array_sum($byMethod);
      if (abs($distSum - $totalDisplay) > 0.01):
      ?>
        <div class="muted">* تنبيه: إجمالي التوزيع (<?=nf($distSum)?>) لا يساوي إجمالي الفاتورة (<?=nf($totalDisplay)?>). قد تكون الملاحظة ناقصة.</div>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
