<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

require_login();
if (function_exists('is_cashier') && is_cashier()) { header('Location: /3zbawyh/public/pos.php'); exit; }

date_default_timezone_set('Africa/Cairo');
$u  = current_user();
$db = db();

/* Helpers */
function safe_fetch($cb, $fallback=null){ try{ return $cb(); }catch(Throwable $e){ return $fallback; } }
function safe_count(PDO $db, $sql, $params=[],$fallback=0){ return safe_fetch(function()use($db,$sql,$params){ $st=$db->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }, $fallback); }
function safe_sum(PDO $db, $sql, $params=[],$fallback=0.0){ return safe_fetch(function()use($db,$sql,$params){ $st=$db->prepare($sql); $st->execute($params); return (float)($st->fetchColumn() ?: 0); }, $fallback); }
function nf($n){ return number_format((float)$n, (floor($n)===$n?0:2), '.', ','); }
function fmt_date($s){
  if (!$s) return '-';
  $ts = strtotime($s);
  return $ts ? date('Y-m-d H:i', $ts) : $s;
}
function cols_of(PDO $db, $table){ $st=$db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $st->execute([$table]); return $st->fetchAll(PDO::FETCH_COLUMN); }
function detect_date_col(PDO $db, $table){
  $st=$db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND DATA_TYPE IN ('datetime','timestamp','date','time')");
  $st->execute([$table]); $rows=$st->fetchAll(PDO::FETCH_KEY_PAIR);
  if(!$rows) return null; foreach(['created_at','created_on','invoice_date','date','ts','timestamp','time'] as $p){ if(isset($rows[$p])) return $p; }
  return array_key_first($rows);
}

/* Optional columns */
$uCols = safe_fetch(fn()=>cols_of($db,'users'),[]);
$cCols = safe_fetch(fn()=>cols_of($db,'customers'),[]);
$siCols = safe_fetch(fn()=>cols_of($db,'sales_invoices'),[]);

// Arabic labels as UTF-8 byte escapes to avoid encoding issues in source files
$cashierLabel    = "\xD9\x83\xD8\xA7\xD8\xB4\xD9\x8A\xD8\xB1 #";
$customerLabel   = "\xD8\xB9\xD9\x85\xD9\x8A\xD9\x84 #";
$cashierLabelSql = "'" . $cashierLabel . "'";
$customerLabelSql= "'" . $customerLabel . "'";

/* Names */
$CASHIER_NAME  = in_array('username',$uCols,true) ? 'u.username' : (in_array('name',$uCols,true)?'u.name':"CONCAT($cashierLabelSql, si.cashier_id)");
$CUSTOMER_NAME = in_array('name',$cCols,true)    ? 'c.name'      : (in_array('customer_name',$cCols,true)?'c.customer_name':"CONCAT($customerLabelSql, si.customer_id)");

/* Dates */
$dateCol    = safe_fetch(fn()=>detect_date_col($db,'sales_invoices'), null);
$todayStart = (new DateTime('today'))->format('Y-m-d 00:00:00');
$todayEnd   = (new DateTime('today'))->format('Y-m-d 23:59:59');

/* KPIs */
$totalProducts = safe_count($db, "SELECT COUNT(*) FROM items");
$LINE_TOT      = "COALESCE(it.line_total, it.qty * it.unit_price)";

if ($dateCol) {
  $todaySales    = safe_sum($db, "SELECT COALESCE(SUM($LINE_TOT),0)
                                  FROM sales_invoices si
                                  LEFT JOIN sales_items it ON it.invoice_id = si.id
                                  WHERE si.`$dateCol` BETWEEN ? AND ?", [$todayStart,$todayEnd], 0.0);
  $todayInvoices = safe_count($db, "SELECT COUNT(DISTINCT si.id)
                                   FROM sales_invoices si
                                   WHERE si.`$dateCol` BETWEEN ? AND ?", [$todayStart,$todayEnd], 0);
} else {
  $todaySales    = safe_sum($db, "SELECT COALESCE(SUM($LINE_TOT),0)
                                  FROM sales_invoices si
                                  LEFT JOIN sales_items it ON it.invoice_id = si.id", [], 0.0);
  $todayInvoices = safe_count($db, "SELECT COUNT(*) FROM sales_invoices", [], 0);
}

$totalCustomers = safe_count($db, "SELECT COUNT(*) FROM customers");
$from30         = (new DateTime('today -30 days'))->format('Y-m-d 00:00:00');
$topItems = safe_fetch(function() use($db,$dateCol,$from30,$todayEnd){
  $dateFilter = $dateCol ? "WHERE inv.`$dateCol` BETWEEN ? AND ?" : "";
  $params = $dateCol ? [$from30,$todayEnd] : [];
  $sql = "SELECT si.item_id, i.name AS item_name,
                 SUM(si.qty) AS qty_sold,
                 SUM(COALESCE(si.line_total, si.qty * si.unit_price)) AS total_amount
          FROM sales_items si
          LEFT JOIN items i ON i.id = si.item_id
          LEFT JOIN sales_invoices inv ON inv.id = si.invoice_id
          $dateFilter
          GROUP BY si.item_id, i.name
          ORDER BY qty_sold DESC
          LIMIT 8";
  $st = $db->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}, []);

/* Today's CASH total (fallback to all sales if no payment_method column) */
$hasPaymentMethod = in_array('payment_method', $siCols, true);
if ($dateCol && $hasPaymentMethod) {
  // Try to filter common cash values
  $pmFilter = "AND (LOWER(si.payment_method) IN ('cash','kash','naqdy','naqd'))";
  $todayCash = safe_sum($db, "SELECT COALESCE(SUM($LINE_TOT),0)
                              FROM sales_invoices si
                              LEFT JOIN sales_items it ON it.invoice_id = si.id
                              WHERE si.`$dateCol` BETWEEN ? AND ? $pmFilter",
                              [$todayStart,$todayEnd], 0.0);
} else {
  $todayCash = $todaySales; // safe fallback
}

/* Latest invoices */
$latestInvoices = safe_fetch(function() use($db,$dateCol,$LINE_TOT,$CUSTOMER_NAME,$CASHIER_NAME){
  $dateSelect = $dateCol ? "si.`$dateCol` AS created_at" : "NULL AS created_at";
  $orderBy    = $dateCol ? "si.`$dateCol` DESC" : "si.id DESC";
  $sql = "SELECT si.id, si.invoice_no, $dateSelect,
                 $CUSTOMER_NAME AS customer_name,
                 $CASHIER_NAME  AS cashier_name,
                 SUM($LINE_TOT) AS total_amount
          FROM sales_invoices si
          LEFT JOIN sales_items it ON it.invoice_id = si.id
          LEFT JOIN customers c ON c.id = si.customer_id
          LEFT JOIN users u     ON u.id = si.cashier_id
          GROUP BY si.id, si.invoice_no, created_at, customer_name, cashier_name
          ORDER BY $orderBy
          LIMIT 8";
  return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}, []);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
      <title>اللوحة الرئيسية - العزباوية</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/3zbawyh/assets/style.css">
  <link rel="icon" type="image/png" href="icons/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
    :root{
      --bg:#f6f8fb;
      --bg2:#eef2f7;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#6b7280;
      --primary:#0f172a;
      --accent:#2563eb;
      --accent-2:#10b981;
      --accent-warm:#f59e0b;
      --border:#e5e7eb;
      --chip:#1118270d;
      --shadow:0 14px 34px rgba(15,23,42,.08);
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background:
        radial-gradient(1200px 600px at 10% -15%, #dbeafe 0%, transparent 55%),
        radial-gradient(900px 500px at 95% -10%, #dcfce7 0%, transparent 55%),
        linear-gradient(180deg,var(--bg),var(--bg2));
      color:var(--text);
      font-family:"Cairo","Tajawal","Noto Naskh Arabic","Segoe UI",system-ui,sans-serif;
    }
    .container{max-width:1200px;margin:0 auto;padding:18px}

    /* NAV */
    .nav{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-radius:16px;background:#fff;border:1px solid var(--border);box-shadow:var(--shadow)}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800}
    .brand .logo{width:32px;height:32px;border-radius:10px;overflow:hidden;background:#f8fafc;border:1px solid var(--border);display:grid;place-items:center}
    .brand .logo img{width:100%;height:100%;object-fit:contain;display:block}
    .nav ul{display:flex;gap:10px;list-style:none;margin:0;padding:0;flex-wrap:wrap}
    .nav a{color:var(--text);text-decoration:none;padding:8px 12px;border-radius:10px}
    .nav a:hover{background:#f3f4f6}

    /* GRID */
    .grid{display:grid;gap:14px}
    @media(min-width:640px){.cols-2{grid-template-columns:repeat(2,1fr)}}
    @media(min-width:900px){.cols-3{grid-template-columns:repeat(3,1fr)} .cols-4{grid-template-columns:repeat(4,1fr)}}

    /* CARD */
    .card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:var(--shadow)}
    .section-title{font-weight:800;margin-bottom:10px;font-size:15px}
    .muted{color:var(--muted)}

    /* KPI */
    .kpi-grid{display:grid;gap:14px;margin-top:16px}
    @media(min-width:680px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
    @media(min-width:1000px){.kpi-grid{grid-template-columns:repeat(4,1fr)}}
    .kpi-card{position:relative;overflow:hidden}
    .kpi-card:before{content:"";position:absolute;inset:0 0 auto 0;height:6px;background:linear-gradient(90deg,var(--accent),var(--accent-2))}
    .kpi-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
    .kpi-label{font-size:12px;color:var(--muted)}
    .kpi-value{font-size:24px;font-weight:800;margin-top:4px}
    .currency{font-size:12px;color:var(--muted)}
    .kpi-sub{margin-top:6px;color:var(--muted);font-size:12px}
    .kpi-icon{width:40px;height:40px;border-radius:12px;background:#eff6ff;display:grid;place-items:center;border:1px solid var(--border);color:var(--accent)}

    /* TABLE */
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{padding:10px;border-bottom:1px solid var(--border);text-align:right;font-size:13px}
    .table th{color:var(--muted);font-weight:700}
    .table tbody tr:hover{background:#f8fafc}

    /* LINKS GRID */
    .qgrid{display:grid;gap:12px}
    @media(min-width:560px){ .qgrid{grid-template-columns:repeat(2,1fr)} }
    @media(min-width:880px){ .qgrid{grid-template-columns:repeat(4,1fr)} }
    .qcard{display:block;padding:14px;border:1px solid var(--border);border-radius:14px;background:linear-gradient(180deg,#ffffff,#f8fafc);text-decoration:none;color:inherit;transition:.2s}
    .qcard strong{display:block;margin-bottom:6px}
    .qcard:hover{box-shadow:var(--shadow);transform:translateY(-2px)}

    .btn{display:inline-flex;align-items:center;gap:8px;background:var(--primary);color:#fff;border:none;padding:10px 12px;border-radius:12px;text-decoration:none;font-weight:600}
    .btn:hover{background:#111827}
    .btn.ghost{background:transparent;color:var(--text);border:1px solid var(--border)}
    .chip{font-size:12px;padding:4px 10px;border-radius:999px;background:var(--chip);border:1px solid var(--border);display:inline-flex;gap:6px;align-items:center}

    .footer{margin-top:18px;color:var(--muted);text-align:center}
    .spacer{height:6px}
  </style>
</head>
<body>
<div class="container">
  <!-- Top bar -->
      <nav class="nav">
    <div class="brand">
      <span class="logo">
        <img src="/3zbawyh/public/icons/elezbawiya.png" alt="شعار العزباوية">
      </span>
      <span>العزباوية</span>
    </div>
    <ul>
      <li><a href="/3zbawyh/public/order_type.php">نقطة البيع</a></li>
      <li><a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a></li>
    </ul>
  </nav>

  <!-- KPIs -->
  <?php $topItem = $topItems[0] ?? null; ?>



  <div class="grid" style="margin-top:14px">
    <div class="qgrid">
      <a class="qcard" href="/3zbawyh/public/reports.php">
        <strong>التقارير</strong>
        <div class="muted">ملخصات يومية/شهرية</div>
      </a>
      <a class="qcard" href="/3zbawyh/public/barcode_only.php">
        <strong>باركود</strong>
        <div class="muted">توليد وطباعة باركود</div>
      </a>
      <a class="qcard" href="/3zbawyh/public/invoices_details.php">
        <strong>فواتير</strong>
        <div class="muted">طباعة الفاتورة</div>
      </a>
      <a class="qcard" href="/3zbawyh/public/clients.php">
        <strong>عملاء</strong>
        <div class="muted">إدارة العملاء</div>
      </a>
      <a class="qcard" href="/3zbawyh/public/categories.php">
        <strong>التصنيفات</strong>
        <div class="muted">إضافة/تعديل/حذف</div>
      </a>
      <a class="qcard" href="/3zbawyh/public/items_manage.php">
        <strong>الأصناف</strong>
        <div class="muted">إدارة وربط بتصنيف</div>
      </a>
      <a class="qcard" href="/3zbawyh/public/backup.php">
        <strong>النسخ الاحتياطي</strong>
        <div class="muted">إنشاء/استرجاع نسخة قاعدة البيانات</div>
      </a>
      <a class="qcard" href="/3zbawyh/public/users.php">
        <strong>إدارة المستخدمين</strong>
        <div class="muted">إدارة المستخدمين والمحاسبين</div>
      </a>
    </div>
  <section class="grid cols-" style="margin-top:14px">
    <div class="card">
      <div class="section-title">أحدث الفواتير</div>
      <table class="table">
        <thead>
          <tr>
            <th>رقم</th>
            <th>العميل</th>
            <th>الإجمالي</th>
            <th>التاريخ</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$latestInvoices): ?>
            <tr><td colspan="4" class="muted">لا توجد فواتير</td></tr>
          <?php else: foreach ($latestInvoices as $inv): ?>
            <tr>
              <td><?=e($inv['invoice_no'] ?? $inv['id'])?></td>
              <td><?=e($inv['customer_name'] ?? '-')?></td>
              <td><?=nf($inv['total_amount'] ?? 0)?> <span class="muted">جنيه</span></td>
              <td><?=e(fmt_date($inv['created_at'] ?? null))?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
    <div class="muted">الوقت الآن: <?=e(now_egypt())?></div>
  </div>

      <footer class="footer">
    <small>© <?=date('Y')?> العزباوية</small>
    <div>تم التطوير بواسطة سيستمايز</div>
    <div> Version 1.2.5</div>
  </footer>
</div>
</body>
</html>
