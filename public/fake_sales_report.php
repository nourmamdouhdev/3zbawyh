<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();



// =====================================================
// 1) تحديد اليوزر اللي عايز تشوفله الصفحة الـ fake بس
// =====================================================

// هنا بافترض إن عندك دالة اسمها current_user() بترجع بيانات اليوزر
// لو اسمها مختلف (مثلاً auth_user() أو get_current_user())
// عدّل السطر ده
$user = current_user(); 

// تقدر تتحكم بشرط العرض زي ما تحب:
// - عن طريق id
// - أو عن طريق username
//
// مثال 1: عن طريق الـ id
// $is_fake_user = ($user['id'] == 5);

// مثال 2: عن طريق الـ username
$is_fake_user = (isset($user['username']) && $user['username'] === 'fake_user');

// لو مش هو اليوزر المطلوب → رجّعه للصفحة الحقيقية


// =====================================================
// 2) أرقام static (غيّرها زي ما تحب)
// =====================================================

date_default_timezone_set('Africa/Cairo');

function e2($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, (floor($n)==$n?0:2), '.', ','); }

// طرق الدفع النهارده (static)
$payToday = [
  'cash'          => ['label'=>'Cash',           'sum'=>15.00,'cnt'=>12],
  'visa'          => ['label'=>'Visa',           'sum'=>8200.50,'cnt'=>7],
  'instapay'      => ['label'=>'InstaPay',       'sum'=>4300.00,'cnt'=>3],
  'vodafone_cash' => ['label'=>'Vodafone Cash',  'sum'=>2100.00,'cnt'=>4],
  'agel'          => ['label'=>'آجل',            'sum'=>5000.00,'cnt'=>5],
  'other'         => ['label'=>'أخرى',           'sum'=>0.00,   'cnt'=>0],
];

// إجمالي النهارده وعدد فواتير النهارده (static)
$totalToday  = 34600.50;
$countToday  = 31;

// آخر 8 فواتير (static)
$hasPaymentMethod = true; // علشان الكولمن يظهر في الجدول
$rowsLast = [
  [
    'id'            => 1001,
    'invoice_no'    => 'F-1001',
    'customer_name' => 'عميل تجريبي 1',
    'cashier_name'  => 'كاشير 1',
    'payment_method'=> 'cash',
    'total_amount'  => 1500.00,
    'created_at'    => '2025-12-01 10:15:00',
  ],
  [
    'id'            => 1002,
    'invoice_no'    => 'F-1002',
    'customer_name' => 'عميل تجريبي 2',
    'cashier_name'  => 'كاشير 2',
    'payment_method'=> 'visa',
    'total_amount'  => 2200.00,
    'created_at'    => '2025-12-01 11:20:00',
  ],
  [
    'id'            => 1003,
    'invoice_no'    => 'F-1003',
    'customer_name' => 'عميل تجريبي 3',
    'cashier_name'  => 'كاشير 1',
    'payment_method'=> 'instapay',
    'total_amount'  => 900.50,
    'created_at'    => '2025-12-01 12:05:00',
  ],
  [
    'id'            => 1004,
    'invoice_no'    => 'F-1004',
    'customer_name' => 'عميل تجريبي 4',
    'cashier_name'  => 'كاشير 3',
    'payment_method'=> 'vodafone_cash',
    'total_amount'  => 1750.00,
    'created_at'    => '2025-12-01 13:40:00',
  ],
  [
    'id'            => 1005,
    'invoice_no'    => 'F-1005',
    'customer_name' => 'عميل تجريبي 5',
    'cashier_name'  => 'كاشير 2',
    'payment_method'=> 'cash',
    'total_amount'  => 3000.00,
    'created_at'    => '2025-12-01 14:10:00',
  ],
  [
    'id'            => 1006,
    'invoice_no'    => 'F-1006',
    'customer_name' => 'عميل تجريبي 6',
    'cashier_name'  => 'كاشير 1',
    'payment_method'=> 'visa',
    'total_amount'  => 2650.00,
    'created_at'    => '2025-12-01 15:00:00',
  ],
  [
    'id'            => 1007,
    'invoice_no'    => 'F-1007',
    'customer_name' => 'عميل تجريبي 7',
    'cashier_name'  => 'كاشير 3',
    'payment_method'=> 'agel',
    'total_amount'  => 4100.00,
    'created_at'    => '2025-12-01 16:25:00',
  ],
  [
    'id'            => 1008,
    'invoice_no'    => 'F-1008',
    'customer_name' => 'عميل تجريبي 8',
    'cashier_name'  => 'كاشير 2',
    'payment_method'=> 'cash',
    'total_amount'  => 1800.00,
    'created_at'    => '2025-12-01 17:05:00',
  ],
];

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>تقارير المبيعات (نسخة شكلية)</title>
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
      <h2 style="margin:0">تقارير المبيعات (شكلية)</h2>
      <div class="row">
        <a class="btn" href="/3zbawyh/public/invoices_details.php">صفحة الفواتير التفصيلية</a>
        <a class="btn" href="/3zbawyh/public/dashboard.php">عودة للوحة</a>
      </div>
    </div>
  </div>

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
    <div class="kpi">
      <div class="h">عدد الفواتير — اليوم</div>
      <div class="v"><?=nf($countToday)?></div>
    </div>
    <div class="kpi">
      <div class="h">إجمالي مبيعات اليوم</div>
      <div class="v"><?=nf($totalToday)?> EGP</div>
    </div>
  </div>

  <!-- آخر 8 فواتير -->
  <div class="card" style="margin-top:12px">
    <div class="row" style="justify-content:space-between">
      <h3 class="section-title">آخر 8 فواتير (شكلية)</h3>
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
