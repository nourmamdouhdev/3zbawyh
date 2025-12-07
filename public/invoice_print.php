<?php
// invoice_print.php — Thermal Receipt (80mm/58mm)

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_login();

$db = db();
$invoice_id = (int)($_GET['id'] ?? 0);

// ========== جلب بيانات الفاتورة ==========
$inv = $db->prepare("
  SELECT 
    i.id,
    i.invoice_no,
    i.invoice_date,
    i.subtotal,
    i.discount,
    i.tax,
    i.total,
    i.payment_method,
    i.paid_amount,
    i.change_due,
    i.created_at,
    u.username  AS cashier,
    c.phone     AS customer_phone ,
    c.name      AS customer
  FROM sales_invoices i
  LEFT JOIN users     u ON u.id = i.cashier_id
  LEFT JOIN customers c ON c.id = i.customer_id
  WHERE i.id = ?
  LIMIT 1
");
$inv->execute([$invoice_id]);
$invoice = $inv->fetch(PDO::FETCH_ASSOC);

// تطبيع الحقول
if ($invoice) {
  $invoice['invoice_number'] = $invoice['invoice_no'] ?? $invoice_id;
  $invoice['created_at']     = $invoice['invoice_date'] ?? $invoice['created_at'] ?? date('Y-m-d H:i:s');

  // تفكيك طريقة الدفع
  $pm = strtolower(trim((string)($invoice['payment_method'] ?? '')));
  $invoice['pay_cash']      = null;
  $invoice['pay_card']      = null;
  $invoice['pay_instapay']  = null;

  if ($pm === 'cash') {
    $invoice['pay_cash'] = (float)$invoice['paid_amount'];
  } elseif (in_array($pm, ['card','visa','mastercard','pos'], true)) {
    $invoice['pay_card'] = (float)$invoice['paid_amount'];
  } elseif (in_array($pm, ['instapay','instant','transfer','bank'], true)) {
    $invoice['pay_instapay'] = (float)$invoice['paid_amount'];
  }

  $invoice['change_amount'] = isset($invoice['change_due']) ? (float)$invoice['change_due'] : null;
} else {
  http_response_code(404);
  die('لم يتم العثور على الفاتورة المطلوبة.');
}

// ========== جلب تفاصيل الأصناف ==========
$it = $db->prepare("
  SELECT 
    itms.name AS name,
    si.qty    AS quantity,
    si.unit_price,
    (si.qty * si.unit_price) AS line_total
  FROM sales_items si
  JOIN items itms ON itms.id = si.item_id
  WHERE si.invoice_id = ?
  ORDER BY si.id
");
$it->execute([$invoice_id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);

// ========== دوال مساعدة ==========
function nf($n){ return number_format((float)$n, 2); }

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>إيصال #<?php echo e($invoice['invoice_number']); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
:root{
  --receipt-width: 80mm;
  --font-size: 12px;
  --line-height: 1.35;
}
*{ box-sizing:border-box; }
html, body{ margin:0; padding:0; }
body{
  width: var(--receipt-width);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Courier New", monospace;
  font-size: var(--font-size);
  line-height: var(--line-height);
  color:#000;
  background:#fff;
}

@page{
  size: var(--receipt-width) auto;
  margin: 0;
}

.wrapper{ padding:8px 8px 12px; }
.center{ text-align:center; }
.right{ text-align:right; }
.left{ text-align:left; }
.bold{ font-weight:700; }
.small{ font-size: 11px; }
.hr{ border-top:1px dashed #000; margin:6px 0; }

.items{ width:100%; border-collapse:collapse; margin-top:6px; table-layout:fixed; }
.items th, .items td{ padding:2px 0; word-wrap:break-word; }
.items th{ text-align:right; border-bottom:1px dashed #000; }

.totals .row{ display:flex; justify-content:space-between; margin:2px 0; }

.qr img{ width:140px; margin-top:10px; }
.no-print{ display:inline-flex; gap:6px; margin:8px; }
@media print{
  .no-print{ display:none !important; }
}
</style>
</head>

<body onload="autoPrint()">
<div class="wrapper">

  <div class="header center">
    <div class="title">العزباوية</div>
    <div class="hr"></div>

    <div class="right small">
      <div>رقم: <span class="bold"><?php echo e($invoice['invoice_number']); ?></span></div>
      <div>التاريخ: <?php echo e(date('Y-m-d H:i', strtotime($invoice['created_at']))); ?></div>
      <div>الكاشير: <?php echo e($invoice['cashier'] ?? ''); ?></div>
      <?php if(!empty($invoice['customer']) || !empty($invoice['customer_phone'])): ?>
        <div>
          العميل:
          <?php
            $name  = trim((string)($invoice['customer'] ?? ''));
            $phone = trim((string)($invoice['customer_phone'] ?? ''));
            echo e($name . ($phone !== '' ? ' — ' . $phone : ''));
          ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="hr"></div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th>الصنف</th>
        <th>الكمية</th>
        <th>السعر</th>
        <th>الإجمالي</th>
      </tr>
    </thead>
    <tbody>
      <?php if($items): foreach($items as $row): ?>
        <tr>
          <td><?php echo e($row['name']); ?></td>
          <td><?php echo e((int)$row['quantity']); ?></td>
          <td><?php echo nf($row['unit_price']); ?></td>
          <td><?php echo nf($row['line_total']); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="4" class="center small">لا توجد أصناف</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="hr"></div>

  <div class="totals">
    <div class="row"><span>الإجمالي قبل الخصم</span><span><?php echo nf($invoice['subtotal'] ?? 0); ?></span></div>

    <?php if(!empty($invoice['discount']) && (float)$invoice['discount']>0): ?>
      <div class="row"><span>خصم</span><span>-<?php echo nf($invoice['discount']); ?></span></div>
    <?php endif; ?>

    <?php if(!empty($invoice['tax']) && (float)$invoice['tax']>0): ?>
      <div class="row"><span>ضريبة</span><span><?php echo nf($invoice['tax']); ?></span></div>
    <?php endif; ?>

    <div class="row bold"><span>الإجمالي المستحق</span><span><?php echo nf($invoice['total'] ?? 0); ?></span></div>
  </div>

  <div class="hr"></div>

  <div class="totals">
    <?php if(isset($invoice['pay_cash'])): ?>
      <div class="row"><span>نقدًا</span><span><?php echo nf($invoice['pay_cash']); ?></span></div>
    <?php endif; ?>
    <?php if(isset($invoice['pay_card'])): ?>
      <div class="row"><span>بطاقة</span><span><?php echo nf($invoice['pay_card']); ?></span></div>
    <?php endif; ?>
    <?php if(isset($invoice['pay_instapay'])): ?>
      <div class="row"><span>InstaPay</span><span><?php echo nf($invoice['pay_instapay']); ?></span></div>
    <?php endif; ?>
    <?php if(isset($invoice['change_amount'])): ?>
      <div class="row"><span>الباقي</span><span><?php echo nf($invoice['change_amount']); ?></span></div>
    <?php endif; ?>
  </div>

  <!-- QR في آخر الفاتورة -->
  <div class="center qr">
    <img src="../assets/images/invoice-qr.png" alt="QR Code">
  </div>

</div>

<!-- أدوات المتصفح -->
<div class="no-print center">
  <button onclick="window.print()">طباعة</button>
  <button onclick="setWidth('58mm')">58mm</button>
  <button onclick="setWidth('80mm')">80mm</button>
  <label class="small">
    <input type="checkbox" id="closeAfterPrint" checked> إغلاق بعد الطباعة
  </label>
</div>

<script>
function setWidth(w){
  document.documentElement.style.setProperty('--receipt-width', w);
}
function autoPrint(){
  window.print();
  if (document.getElementById('closeAfterPrint').checked) {
    setTimeout(()=>{ window.close(); }, 400);
  }
}
</script>

</body>
</html>
