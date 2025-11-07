<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../models/Items.php';
require_once __DIR__ . '/../models/Sales.php';
require_role_in_or_redirect(['admin','cashier']);
require_login();

header('Content-Type: application/json; charset=utf-8');

$u = current_user();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ================= Helpers ================= */

function &pos_cart_ref(){
  if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = []; // item_id,name,qty,unit_price,default_price,stock,price_overridden
  }
  return $_SESSION['pos_cart'];
}

function fetch_item_by_id($id){
  $db = db();
  $st = $db->prepare("SELECT id, name, unit_price, stock, image_url FROM items WHERE id = ?");
  $st->execute([(int)$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  return [
    'id'         => (int)$row['id'],
    'name'       => $row['name'],
    'unit_price' => (float)$row['unit_price'],
    'stock'      => isset($row['stock']) ? (float)$row['stock'] : null,
    'image_url'  => $row['image_url'] ?? '',
  ];
}

$enc = function($s){ return rawurlencode((string)$s); };
$encodeMultiPayments = function(array $pays) use ($enc){
  // MULTI;method,amount,ref,note;...
  $parts = ['MULTI'];
  foreach ($pays as $p) {
    $m = $enc($p['method'] ?? '');
    $a = $enc($p['amount'] ?? 0);
    $r = $enc($p['ref_no'] ?? '');
    $n = $enc($p['note'] ?? '');
    $parts[] = "{$m},{$a},{$r},{$n}";
  }
  return implode(';', $parts);
};

function calc_total_from_lines($lines, $discount, $tax){
  $subtotal = 0.0;
  foreach ($lines as $l) {
    $qty  = max(0, (float)$l['qty']);
    $unit = max(0, (float)$l['unit_price']);
    $subtotal += $qty * $unit;
  }
  return $subtotal - (float)$discount + (float)$tax;
}

// ✅ تطبيع أسماء طرق الدفع للـDB (agyl → agel) والسماح بالقيم الجديدة
function normalize_method($m){
  $m = strtolower(trim((string)$m));
  if ($m === 'agyl') return 'agel';
  $allowed = ['cash','visa','instapay','vodafone_cash','agel','mixed'];
  return in_array($m, $allowed, true) ? $m : 'mixed';
}

/* ===== قواعد الدفع:
   - أي توليفة طرق دفع.
   - InstaPay و Vodafone Cash مرجع إجباري.
   - الزيادة مسموحة فقط لو من الكاش (بترجع كباقي).
*/
function validate_payments_rules(array &$pays, float $total){
  foreach ($pays as $p) {
    $method = strtolower(trim((string)($p['method'] ?? '')));
    $ref    = trim((string)($p['ref_no'] ?? ''));
    if (($method === 'instapay' || $method === 'vodafone_cash') && $ref === '') {
      throw new Exception('رقم العملية/المرجع مطلوب لـ InstaPay/Vodafone Cash.');
    }
  }
  $sum = 0.0; foreach ($pays as $p) $sum += (float)($p['amount'] ?? 0);
  $cashSum = 0.0; foreach ($pays as $p) if (strtolower((string)($p['method'] ?? ''))==='cash') $cashSum += (float)($p['amount'] ?? 0);
  $nonCash = $sum - $cashSum;
  $remainingAfterNonCash = $total - $nonCash;
  $changeDue = max(0, $cashSum - max(0, $remainingAfterNonCash));
  $overpay = $sum - $total;
  $overpayAllowed = abs($overpay - $changeDue) < 0.01;
  if (abs($sum - $total) > 0.009 && !$overpayAllowed) {
    throw new Exception('مجموع المدفوعات لا يساوي الإجمالي. الزيادة مسموحة فقط لو كاش (تُحسب كباقي).');
  }
  return $changeDue;
}

/* ================== Router ================== */
try {
  switch ($action) {

    /* --------- فلاتر --------- */
    case 'load_filters': {
      $cats = ItemsModel::categories();
      $subs = method_exists('ItemsModel','subcategories') ? ItemsModel::subcategories(null) : [];
      echo json_encode(['ok'=>1, 'categories'=>$cats, 'subcategories'=>$subs]);
      break;
    }

    case 'search_categories': {
      echo json_encode(['ok'=>1, 'categories'=> ItemsModel::categories() ]);
      break;
    }

    case 'search_subcategories': {
      $cid = (isset($_GET['category_id']) && $_GET['category_id']!=='') ? (int)$_GET['category_id'] : null;
      $subs = method_exists('ItemsModel','subcategories') ? ItemsModel::subcategories($cid) : [];
      if ($cid !== null) {
        $subs = array_values(array_filter($subs ?? [], function($s) use ($cid){
          $key = isset($s['category_id']) ? 'category_id' : (isset($s['cat_id'])?'cat_id':null);
          return $key ? ((int)$s[$key] === $cid) : true;
        }));
      }
      echo json_encode(['ok'=>1, 'subcategories'=>$subs]);
      break;
    }

    /* --------- بحث الأصناف --------- */
    case 'search_items': {
      $q   = trim($_GET['q'] ?? '');
      $cid = (int)($_GET['category_id'] ?? 0);
      $sid = (int)($_GET['subcategory_id'] ?? 0);

      $sql = "SELECT i.id, i.name, i.unit_price, i.stock, i.image_url
              FROM items i
              WHERE 1=1";
      $p = [];

      if ($cid > 0) { $sql .= " AND i.category_id = ?";    $p[] = $cid; }
      if ($sid > 0) { $sql .= " AND i.subcategory_id = ?"; $p[] = $sid; }
      if ($q !== '') {
        $sql .= " AND (i.name LIKE ? OR i.sku LIKE ?)";
        $p[] = "%$q%"; $p[] = "%$q%";
      }

      $sql .= " ORDER BY i.name ASC LIMIT 200";
      $st = db()->prepare($sql);
      $st->execute($p);

      $items = [];
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
          'id'         => (int)$r['id'],
          'name'       => $r['name'],
          'unit_price' => (float)$r['unit_price'],
          'stock'      => isset($r['stock']) ? (float)$r['stock'] : null,
          'image_url'  => $r['image_url'] ?? '',
        ];
      }

      echo json_encode(['ok'=>true, 'items'=>$items], JSON_UNESCAPED_UNICODE);
      exit;
    }

    /* --------- كارت Session --------- */
    case 'cart_add': {
      $item_id = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
      $qty     = max(1, (float)($_POST['qty'] ?? $_GET['qty'] ?? 1));
      if ($item_id <= 0) throw new Exception('item_id مطلوب');

      $it = fetch_item_by_id($item_id);
      if (!$it) throw new Exception('الصنف غير موجود');

      $unit = isset($it['unit_price']) ? (float)$it['unit_price'] : null;
      $def  = isset($it['default_price']) ? (float)$it['default_price'] : null;
      $price= $unit !== null && $unit !== 0.0 ? $unit : ($def ?? 0.0);
      $stock= isset($it['stock']) ? (float)$it['stock'] : null;

      $cart =& pos_cart_ref();
      $ids  = array_column($cart, 'item_id');
      $idx  = array_search($item_id, $ids);

      if ($idx !== false) {
        $next = (float)$cart[$idx]['qty'] + $qty;
        if ($stock !== null && $next > $stock) throw new Exception('الكمية أكبر من المتاح');
        $cart[$idx]['qty'] = $next;
      } else {
        if ($stock !== null && $qty > $stock) throw new Exception('الكمية أكبر من المتاح');
        $cart[] = [
          'item_id'=>$item_id,
          'name'=>$it['name'] ?? ('#'.$item_id),
          'qty'=>$qty,
          'unit_price'=>$price,
          'default_price'=> $def ?? $price,
          'stock'=>$stock,
          'price_overridden'=>0,
        ];
      }
      echo json_encode(['ok'=>1, 'cart'=>$cart]);
      break;
    }

    case 'cart_update': {
      $item_id = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
      if ($item_id <= 0) throw new Exception('item_id مطلوب');
      $cart =& pos_cart_ref();
      $ids  = array_column($cart, 'item_id');
      $idx  = array_search($item_id, $ids);
      if ($idx === false) throw new Exception('الصنف غير موجود في العربة');

      if (isset($_POST['remove']) || isset($_GET['remove'])) {
        array_splice($cart, $idx, 1);
      } else {
        if (isset($_POST['qty']) || isset($_GET['qty'])) {
          $q = max(0, (float)($_POST['qty'] ?? $_GET['qty']));
          if ($cart[$idx]['stock'] !== null && $q > (float)$cart[$idx]['stock']) throw new Exception('أكبر من المتاح');
          if ($q == 0) array_splice($cart, $idx, 1);
          else $cart[$idx]['qty'] = $q;
        }
        if (isset($_POST['unit_price']) || isset($_GET['unit_price'])) {
          $p = max(0, (float)($_POST['unit_price'] ?? $_GET['unit_price']));
          $cart[$idx]['unit_price'] = $p;
          $cart[$idx]['price_overridden'] = ($p != (float)$cart[$idx]['default_price']) ? 1 : 0;
        }
      }
      echo json_encode(['ok'=>1, 'cart'=>$cart]);
      break;
    }

    case 'cart_clear': {
      $_SESSION['pos_cart'] = [];
      echo json_encode(['ok'=>1, 'cart'=>[]]);
      break;
    }

    case 'cart_get': {
      $cart = pos_cart_ref();
      $subtotal = 0.0;
      foreach ($cart as $l) $subtotal += ((float)$l['qty']) * ((float)$l['unit_price']);
      echo json_encode(['ok'=>1, 'cart'=>$cart, 'subtotal'=>$subtotal]);
      break;
    }

    /* --------- حفظ بدفع واحد (توافق قديم) --------- */
    case 'save_invoice': {
      $body = json_decode(file_get_contents('php://input'), true);
      if (!$body) throw new Exception('Bad JSON');

      $cust_name  = trim($body['customer_name']  ?? '');
      $cust_phone = trim($body['customer_phone'] ?? '');
      $lines      = $body['lines'] ?? [];
      $discount   = (float)($body['discount'] ?? 0);
      $tax        = (float)($body['tax'] ?? 0);
      $notes      = trim($body['notes'] ?? '');

      if (!$lines || !is_array($lines)) throw new Exception('No lines');
      foreach ($lines as $ln) { if (!isset($ln['item_id'],$ln['qty'],$ln['unit_price'])) throw new Exception('Bad line'); }

      // ✅ سماح بالقيم الجديدة + تطبيع
      $pm = normalize_method($body['payment_method'] ?? 'cash');

      $payment = [
        'payment_method' => $pm,
        'paid_cash'      => (float)($body['paid_cash'] ?? 0),
        'change_due'     => (float)($body['change_due'] ?? 0),
        'payment_ref'    => trim($body['payment_ref'] ?? ''),
        'payment_note'   => trim($body['payment_note'] ?? ''),
      ];
      if (($pm === 'instapay' || $pm === 'vodafone_cash') && $payment['payment_ref']==='') {
        throw new Exception('مرجع مطلوب لمدفوعات InstaPay/Vodafone Cash');
      }

      $res = Sales::saveInvoice($u['id'], $cust_name, $cust_phone, $lines, $discount, $tax, $notes, $payment);
      echo json_encode(['ok'=>1, 'invoice'=>$res, 'print_url'=>"/3zbawyh/public/invoice_print.php?id=".$res['invoice_id']]);
      break;
    }

    /* --------- حفظ دفع مُجزّأ (بدون تغيير DB) --------- */
    case 'save_invoice_multi_legacy': {
      $data = json_decode(file_get_contents('php://input'), true) ?: [];
      $lines = $data['lines'] ?? [];
      if (!$lines || !is_array($lines)) throw new Exception('لا توجد أصناف');
      foreach ($lines as $ln) { if (!isset($ln['item_id'],$ln['qty'],$ln['unit_price'])) throw new Exception('سطر غير صالح'); }

      $discount = (float)($data['discount'] ?? 0);
      $tax      = (float)($data['tax'] ?? 0);
      $pays     = $data['payments'] ?? [];
      if (!is_array($pays) || !count($pays)) throw new Exception('لا توجد مدفوعات');

      $total = calc_total_from_lines($lines, $discount, $tax);
      $change_due = validate_payments_rules($pays, $total);

      // 🔁 Override من الواجهة لو متوفر
      $save_override           = normalize_method($data['save_payment_method'] ?? '');
      $payment_note_override   = trim((string)($data['payment_note'] ?? ''));

      // الوضع الافتراضي كما هو
      $normMethod   = null; $paid_cash=0.0; $payment_ref=''; $payment_note='';

      if (count($pays) === 1) {
        $p = $pays[0];
        $normMethod = normalize_method($p['method'] ?? 'cash');
        if ($normMethod === 'cash') $paid_cash = (float)($p['amount'] ?? 0);
        else $payment_ref = trim((string)($p['ref_no'] ?? ''));
        $payment_note = trim((string)($p['note'] ?? ''));
      } else {
        $normMethod = 'mixed';
        foreach ($pays as $p) if (normalize_method($p['method'] ?? '')==='cash') $paid_cash += (float)($p['amount'] ?? 0);
        foreach ($pays as $p) { if (normalize_method($p['method'] ?? '')!=='cash') { $payment_ref = trim((string)($p['ref_no'] ?? '')); if ($payment_ref) break; } }
        $payment_note = $encodeMultiPayments($pays);
      }

      // ✅ احترام override القادم من الواجهة
      if ($save_override && $save_override !== 'mixed') {
        $normMethod = $save_override;
      }
      if ($payment_note_override !== '') {
        $payment_note = $payment_note_override;
      }

      $res = Sales::saveInvoice(
        $u['id'],
        trim((string)($data['customer_name'] ?? '')),
        trim((string)($data['customer_phone'] ?? '')),
        $lines, $discount, $tax, '',
        [
          'payment_method' => $normMethod,
          'paid_cash'      => $paid_cash,
          'change_due'     => $change_due,
          'payment_ref'    => $payment_ref,
          'payment_note'   => $payment_note,
        ]
      );

      echo json_encode(['ok'=>1, 'invoice'=>$res, 'print_url'=>"/3zbawyh/public/invoice_print.php?id=".$res['invoice_id']]);
      break;
    }

    /* --------- حفظ من الكارت (Checkout) — (معدّل ليخزن بيانات العميل) --------- */
    case 'cart_checkout_multi_legacy': {
      $body = json_decode(file_get_contents('php://input'), true) ?: [];
      $discount = (float)($body['discount'] ?? 0);
      $tax      = (float)($body['tax'] ?? 0);
      $pays     = $body['payments'] ?? [];
      if (!is_array($pays) || !count($pays)) throw new Exception('لا توجد مدفوعات');

      // 👇 الجديد: قراءة اسم ورقم العميل
      $cust_name  = trim((string)($body['customer_name']  ?? ''));
      $cust_phone = trim((string)($body['customer_phone'] ?? ''));

      $cart = pos_cart_ref();
      if (!$cart) throw new Exception('العربة فارغة');

      $lines = array_map(function($l){
        return [
          'item_id' => (int)$l['item_id'],
          'qty' => (float)$l['qty'],
          'unit_price' => (float)$l['unit_price'],
          'price_overridden' => (int)$l['price_overridden'],
        ];
      }, $cart);

      $total = calc_total_from_lines($lines, $discount, $tax);
      $change_due = validate_payments_rules($pays, $total);

      // 🔁 Override من الواجهة لو متوفر
      $save_override           = normalize_method($body['save_payment_method'] ?? '');
      $payment_note_override   = trim((string)($body['payment_note'] ?? ''));

      $normMethod   = null; $paid_cash=0.0; $payment_ref=''; $payment_note='';

      if (count($pays)===1) {
        $p = $pays[0];
        $normMethod = normalize_method($p['method'] ?? 'cash');
        if ($normMethod==='cash') $paid_cash = (float)($p['amount'] ?? 0);
        else $payment_ref = trim((string)($p['ref_no'] ?? ''));
        $payment_note = trim((string)($p['note'] ?? ''));
      } else {
        $normMethod = 'mixed';
        foreach ($pays as $p) if (normalize_method($p['method'] ?? '')==='cash') $paid_cash += (float)($p['amount'] ?? 0);
        foreach ($pays as $p) { if (normalize_method($p['method'] ?? '')!=='cash') { $payment_ref = trim((string)($p['ref_no'] ?? '')); if ($payment_ref) break; } }
        $payment_note = $encodeMultiPayments($pays);
      }

      // ✅ احترام override القادم من الواجهة
      if ($save_override && $save_override !== 'mixed') {
        $normMethod = $save_override;
      }
      if ($payment_note_override !== '') {
        $payment_note = $payment_note_override;
      }

      // 👇 تم تمرير اسم/موبايل العميل هنا
      $res = Sales::saveInvoice(
        $u['id'], $cust_name, $cust_phone, $lines, $discount, $tax, '',
        [
          'payment_method' => $normMethod,
          'paid_cash'      => $paid_cash,
          'change_due'     => $change_due,
          'payment_ref'    => $payment_ref,
          'payment_note'   => $payment_note,
        ]
      );

      $_SESSION['pos_cart'] = []; // تفريغ الكارت
      echo json_encode(['ok'=>1, 'invoice'=>$res, 'print_url'=>"/3zbawyh/public/invoice_print.php?id=".$res['invoice_id']]);
      break;
    }

    default:
      echo json_encode(['ok'=>0,'error'=>'Unknown action']);
  }
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>0, 'error'=>$e->getMessage()]);
}
