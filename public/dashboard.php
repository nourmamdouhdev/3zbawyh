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

/* Names */
$CASHIER_NAME  = in_array('username',$uCols,true) ? 'u.username' : (in_array('name',$uCols,true)?'u.name':"CONCAT('كاشير #', si.cashier_id)");
$CUSTOMER_NAME = in_array('name',$cCols,true)    ? 'c.name'      : (in_array('customer_name',$cCols,true)?'c.customer_name':"CONCAT('عميل #', si.customer_id)");

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

/* Today's CASH total (fallback to all sales if no payment_method column) */
$hasPaymentMethod = in_array('payment_method', $siCols, true);
if ($dateCol && $hasPaymentMethod) {
  // نحاول نفلتر القيم الشائعة للكاش
  $pmFilter = "AND (LOWER(si.payment_method) IN ('cash','كاش','نقدي','نقدى'))";
  $todayCash = safe_sum($db, "SELECT COALESCE(SUM($LINE_TOT),0)
                              FROM sales_invoices si
                              LEFT JOIN sales_items it ON it.invoice_id = si.id
                              WHERE si.`$dateCol` BETWEEN ? AND ? $pmFilter",
                              [$todayStart,$todayEnd], 0.0);
} else {
  $todayCash = $todaySales; // بديل آمن
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
  <style>
    :root{
      --bg:#f6f7fb; --card:#fff; --text:#0f172a; --muted:#6b7280;
      --primary:#111827; --primary-600:#1f2937;
      --accent:#4f46e5; --accent-weak:#eef2ff;
      --success:#10b981; --warning:#f59e0b; --border:#e5e7eb; --chip:#1118270d;
      --shadow:0 10px 25px rgba(0,0,0,.06);
    }
    @media (prefers-color-scheme: dark){
      :root{ --bg:#0b1020; --card:#0f162b; --text:#e5e7eb; --muted:#9ca3af; --primary:#e5e7eb; --primary-600:#cbd5e1; --accent:#6366f1; --accent-weak:#1f254d; --border:#1f2a44; --chip:#ffffff14; --shadow:0 10px 25px rgba(0,0,0,.35); }
      .btn{color:#fff}
    }
    *{box-sizing:border-box}
    body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,Arial;margin:0}
    .container{max-width:1200px;margin:0 auto;padding:16px}

    /* NAV */
    .nav{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-radius:16px;background:linear-gradient(120deg,#0f172a,#1f2937);color:#fff;box-shadow:var(--shadow)}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800}
    .brand .logo{width:30px;height:30px;border-radius:8px;overflow:hidden;background:#fff1;border:1px solid #ffffff22;display:grid;place-items:center}
    .brand .logo img{width:100%;height:100%;object-fit:contain;display:block}
    .nav ul{display:flex;gap:10px;list-style:none;margin:0;padding:0;flex-wrap:wrap}
    .nav a{color:#fff;text-decoration:none;padding:8px 12px;border-radius:10px}
    .nav a:hover{background:#ffffff1a}

    /* GRID */
    .grid{display:grid;gap:14px}
    @media(min-width:640px){.cols-2{grid-template-columns:repeat(2,1fr)}}
    @media(min-width:940px){.cols-3{grid-template-columns:repeat(3,1fr)} .cols-4{grid-template-columns:repeat(4,1fr)}}

    /* CARD */
    .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:var(--shadow)}
    .card.hover{transition:transform .2s ease, box-shadow .2s ease}
    .card.hover:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(0,0,0,.10)}

    /* KPI */
    .kpi{display:flex;flex-direction:column;gap:8px}
    .kpi .row{display:flex;align-items:center;justify-content:space-between}
    .kpi .icon{width:36px;height:36px;border-radius:12px;background:var(--accent-weak);display:grid;place-items:center;border:1px solid var(--border)}
    .kpi .label{color:var(--muted);font-size:13px}
    .kpi .value{font-size:24px;font-weight:800}
    .kpi .sub{color:var(--muted);font-size:12px}

    /* LINKS GRID */
    .qcard{display:block;padding:14px;border:1px solid var(--border);border-radius:14px;background:var(--card);text-decoration:none;color:inherit}
    .qcard strong{display:block;margin-bottom:6px}
    .qcard:hover{box-shadow:var(--shadow);transform:translateY(-2px);transition:.2s}
    .box{display:grid;gap:12px}
    @media(min-width:560px){ .box{grid-template-columns:repeat(2,1fr)} }
    @media(min-width:880px){ .box{grid-template-columns:repeat(4,1fr)} }

    .btn{display:inline-flex;align-items:center;gap:8px;background:var(--primary);color:#fff;border:none;padding:10px 12px;border-radius:12px;text-decoration:none;font-weight:600}
    .btn:hover{background:var(--primary-600)}
    .btn.ghost{background:transparent;color:var(--text);border:1px solid var(--border)}
    .chip{font-size:12px;padding:4px 10px;border-radius:999px;background:var(--chip);border:1px solid var(--border);display:inline-flex;gap:6px;align-items:center}

    .footer{margin-top:18px;color:var(--muted);text-align:center}
    .muted{color:var(--muted)}
    .spacer{height:6px}
  </style>
</head>
<body>
<div class="container">

  <!-- Top bar -->
  <nav class="nav">
    <div class="brand">
      <span class="logo">
        <img src="/3zbawyh/public/icons/elezbawiya.png" alt="Elezbawiya logo">
      </span>
      <span>العزباوية</span>
    </div>
    <ul>
      <li><a href="/3zbawyh/public/order_type.php">نقطة البيع </a></li>
      <li><a href="/3zbawyh/public/logout.php">خروج (<?=e($u['username'])?>)</a></li>
    </ul>
  </nav>

  <!-- KPIs -->
  <div class="grid cols-3" style="margin-top:14px">
    <!-- مبيعات اليوم -->
    <div class="card kpi hover">
      <div class="row">
        <div class="label">مبيعات  اليوم</div>
        <div class="icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 12h18M7 12v7m5-7v7m5-7v7M6 8h12l-1-3H7l-1 3Z" stroke="currentColor" stroke-width="1.5"/></svg></div>
      </div>
      <div class="value"><?=nf($todaySales)?> <span class="muted" style="font-size:12px">EGP</span></div>
      <div class="sub">عدد فواتير اليوم: <?=nf($todayInvoices)?></div>
    </div>

    <!-- مرحباً -->
    <div class="card kpi hover">
      <div class="row">
        <div class="label">مرحباً</div>
        <div class="icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.5"/><path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="1.5"/></svg></div>
      </div>
      <div class="value"><?=e($u['username'])?></div>
      <div class="sub">دورك: <?=e($u['role'] ?? '')?></div>
    </div>

    <!-- ملخص اليوم: إجمالي النقدي + إجمالي الفواتير اليوم -->
    <div class="card kpi hover">
      <div class="row">
        <div class="label"> فواتير</div>
        <div class="icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 7h12M6 12h12M6 17h12" stroke="currentColor" stroke-width="1.5"/></svg>
        </div>
      </div>
      <div class="value"><?=nf($todayInvoices)?> <span class="muted" style="font-size:12px"></span></div>

    </div>
  </div>

  <!-- روابط سريعة + الوقت -->
  <div class="grid" style="margin-top:14px">
    <div class="box">
      <a class="qcard" href="/3zbawyh/public/reports.php">
        <strong>التقارير</strong>
        <div class="muted">ملخصات يومية/شهرية</div>
      </a>
            <a class="qcard" href="/3zbawyh/public/barcode_only.php">
        <strong>Barcode</strong>
        <div class="muted">ملخصات يومية/شهرية</div>
      </a>
      <a class="qcard" href="/3zbawyh/public/invoices_details.php">
        <strong>فواتير</strong>
        <div class="muted"> طبع الفاتورة </div>
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
        <div class="muted">لإدارة المستخدمين والمحاسبين</div>
      </a>
    </div>

    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
    <div class="muted">الوقت الآن: <?=e(now_egypt())?></div>
  </div>

  <footer class="footer">
    <small>© <?=date('Y')?> العزباوية</small>
    <div>Made by Systemize</div>
  </footer>
</div>
</body>
</html>
