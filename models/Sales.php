<?php
// models/Sales.php
require_once __DIR__ . '/../lib/helpers.php';

class Sales
{
    // احسب رقم الفاتورة: AZB-YYYYMMDD-XXXX
    private static function generateInvoiceNo(PDO $db): string {
        $today = date('Ymd');
        $pref  = "AZB-$today-";
        $stmt  = $db->prepare("SELECT invoice_no FROM sales_invoices WHERE invoice_no LIKE :pfx ORDER BY id DESC LIMIT 1");
        $stmt->execute([':pfx' => $pref.'%']);
        $last = $stmt->fetchColumn();
        $n = 1;
        if ($last) {
            $n = (int)substr($last, -4) + 1;
        }
        return $pref . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    // تطبيع اسم وسماح بالقيم الجديدة (agyl -> agel)
    private static function normalizePM(?string $pm): string {
        $pm = strtolower(trim((string)$pm));
        if ($pm === 'agyl') $pm = 'agel';
        $allowed = ['cash','visa','instapay','vodafone_cash','agel','credit','mixed'];
        return in_array($pm, $allowed, true) ? $pm : 'mixed';
    }

    // البحث أو إنشاء عميل بسيط بالاسم/التليفون
    private static function upsertCustomer(PDO $db, string $name, string $phone): ?int {
        $name  = trim($name);
        $phone = trim($phone);
        if ($name === '' && $phone === '') return null;

        if ($phone !== '') {
            $digits = preg_replace('/\D+/', '', $phone);
            $variants = [];
            if ($digits !== '') $variants[] = $digits;
            if (strlen($digits) === 11 && strpos($digits, '01') === 0) {
                $variants[] = '2'.$digits; // 010... -> 2010...
            }
            if (strlen($digits) === 12 && strpos($digits, '20') === 0) {
                $variants[] = '0'.substr($digits, 2); // 2010... -> 010...
            }
            $variants = array_values(array_unique($variants));

            $expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')','')";
            foreach ($variants as $v) {
                $q = $db->prepare("SELECT id FROM customers WHERE phone IS NOT NULL AND $expr = ? LIMIT 1");
                $q->execute([$v]);
                $id = $q->fetchColumn();
                if ($id) {
                    if ($name !== '') {
                        $upd = $db->prepare("UPDATE customers SET name = COALESCE(NULLIF(?,''), name) WHERE id=?");
                        $upd->execute([$name, $id]);
                    }
                    return (int)$id;
                }
            }
        }
        $ins = $db->prepare("INSERT INTO customers (name, phone, created_at) VALUES (?, ?, NOW())");
        $ins->execute([$name ?: 'عميل نقدي', $phone ?: null]);
        return (int)$db->lastInsertId();
    }

    public static function saveInvoice(
        int $cashier_id,
        string $customer_name,
        string $customer_phone,
        array $lines,
        float $discount,
        float $tax,
        string $notes = '',
        array $payment = []
    ): array {
        $db = db();
        $db->beginTransaction();
        try {
            // حساب الإجماليات
            $subtotal = 0.0;
            foreach ($lines as $ln) {
                $qty = (float)$ln['qty'];
                $up  = (float)$ln['unit_price'];
                $subtotal += ($qty * $up);
            }
            $total = $subtotal - $discount + $tax;

            // عميل (اختياري)
            $customer_id = self::upsertCustomer($db, $customer_name, $customer_phone);

            // الدفع
            $pm = self::normalizePM($payment['payment_method'] ?? 'cash');
            $is_credit = !empty($payment['is_credit']) || $pm === 'agel' || $pm === 'credit';
            $paid_amount_override = isset($payment['paid_amount_override']) ? (float)$payment['paid_amount_override'] : null;

            $paid_amount  = 0.0;          // إجمالي المقبوض
            $change_due   = 0.0;          // الباقي نقدًا
            $payment_ref  = null;         // مرجع التحويل (لو موجود)
            $payment_note = null;         // ملاحظات (قد تحتوي MULTI;...)

            if ($is_credit) {
                $paid_amount = $paid_amount_override !== null ? $paid_amount_override : (float)($payment['paid_cash'] ?? 0);
                if ($paid_amount < 0) $paid_amount = 0.0;
                if ($paid_amount > $total) $paid_amount = $total;
                $change_due   = max(0, (float)($payment['change_due'] ?? 0));
                $payment_ref  = trim((string)($payment['payment_ref'] ?? '')) ?: null;
                $payment_note = trim((string)($payment['payment_note'] ?? '')) ?: null;

            } elseif ($pm === 'cash') {
                // ??? ???: ???? ???? >= ????????? ??????? ?????? change
                $paid_cash  = (float)($payment['paid_cash']  ?? 0);
                $change_due = max(0, (float)($payment['change_due'] ?? ($paid_cash - $total)));
                if ($paid_cash + 1e-6 < $total) {
                    throw new Exception('?????? ?????? ??? ?? ????????.');
                }
                $paid_amount = $paid_cash;

            } elseif ($pm === 'instapay' || $pm === 'visa' || $pm === 'vodafone_cash') {
                // ????? ????? ??? ???: ????? ???????? Paid ??????? (?????? ?? ?? ???API)
                // ???? ????? ?? Instapay ? Vodafone Cash
                if ($pm === 'instapay' || $pm === 'vodafone_cash') {
                    $payment_ref = trim((string)($payment['payment_ref'] ?? ''));
                    if ($payment_ref === '') {
                        throw new Exception('???? ??????? ????? ?? InstaPay/Vodafone Cash.');
                    }
                } else {
                    $payment_ref = trim((string)($payment['payment_ref'] ?? '')) ?: null;
                }
                $payment_note = trim((string)($payment['payment_note'] ?? '')) ?: null;
                $paid_amount  = $total;
                $change_due   = 0.0;

            } else { // mixed
                // ??? ??????: ????? ????? ??????? ?? ???API ??? ?????? ????
                $paid_cash    = (float)($payment['paid_cash'] ?? 0);
                $change_due   = max(0, (float)($payment['change_due'] ?? 0));
                $payment_ref  = trim((string)($payment['payment_ref'] ?? '')) ?: null;  // ??? ???? ??? ??? ?? ???
                $payment_note = trim((string)($payment['payment_note'] ?? '')) ?: null; // ?????? MULTI;method,amount,...

                // ??? ?? ???API ????? ?? ?????? ????????? ????? ???????? (+/? ?????? ?? ????? ???)
                // ????? ???????? Paid ???????:
                $paid_amount  = $total;
            }

            // رأس الفاتورة
            $invoice_no = self::generateInvoiceNo($db);
            $ins = $db->prepare("
                INSERT INTO sales_invoices
                  (invoice_no, invoice_date, cashier_id, customer_id,
                   subtotal, discount, tax, total,
                   payment_method, paid_amount, change_due, payment_ref, payment_note, notes, created_at)
                VALUES
                  (:invoice_no, NOW(), :cashier_id, :customer_id,
                   :subtotal, :discount, :tax, :total,
                   :payment_method, :paid_amount, :change_due, :payment_ref, :payment_note, :notes, NOW())
            ");
            $ins->execute([
                ':invoice_no'     => $invoice_no,
                ':cashier_id'     => $cashier_id,
                ':customer_id'    => $customer_id,
                ':subtotal'       => $subtotal,
                ':discount'       => $discount,
                ':tax'            => $tax,
                ':total'          => $total,
                ':payment_method' => $pm,           // ✅ هتكون cash/visa/instapay/vodafone_cash/agel/mixed
                ':paid_amount'    => $paid_amount,
                ':change_due'     => $change_due,
                ':payment_ref'    => $payment_ref,
                ':payment_note'   => $payment_note,
                ':notes'          => $notes ?: null,
            ]);
            $invoice_id = (int)$db->lastInsertId();

            if ($is_credit) {
                try {
                    $sets = [];
                    $vals = [];
                    if (column_exists($db, 'sales_invoices', 'is_credit')) {
                        $sets[] = "is_credit=1";
                    }
                    if (column_exists($db, 'sales_invoices', 'credit_due_date')) {
                        $due = $payment['credit_due_date'] ?? null;
                        $due = ($due === '' ? null : $due);
                        $sets[] = "credit_due_date=?";
                        $vals[] = $due;
                    }
                    if ($sets) {
                        $vals[] = $invoice_id;
                        $db->prepare("UPDATE sales_invoices SET ".implode(',', $sets)." WHERE id=?")
                           ->execute($vals);
                    }
                } catch (Throwable $e) { }
            }

            // تفاصيل الأصناف + تحديث المخزون
            $insLine = $db->prepare("
                INSERT INTO sales_items (invoice_id, item_id, qty, unit_price, line_total, price_overridden)
                VALUES (:invoice_id, :item_id, :qty, :unit_price, :line_total, :price_overridden)
            ");
            $decStock = $db->prepare("UPDATE items SET stock = stock - :qty WHERE id = :id");

            foreach ($lines as $ln) {
                $item_id = (int)$ln['item_id'];
                $qty     = (float)$ln['qty'];
                $up      = (float)$ln['unit_price'];
                $over    = (int)($ln['price_overridden'] ?? 0);
                $lt      = $qty * $up;

                // تحقق مخزون بسيط (اختياري)
                $chk = $db->prepare("SELECT stock FROM items WHERE id=?");
                $chk->execute([$item_id]);
                $stk = (float)$chk->fetchColumn();
                if ($stk < $qty) {
                    throw new Exception("المخزون غير كافٍ للصنف ID=$item_id");
                }

                $insLine->execute([
                    ':invoice_id' => $invoice_id,
                    ':item_id'    => $item_id,
                    ':qty'        => $qty,
                    ':unit_price' => $up,
                    ':line_total' => $lt,
                    ':price_overridden' => $over,
                ]);
                $decStock->execute([':qty'=>$qty, ':id'=>$item_id]);
            }

            $db->commit();
            return [
                'invoice_id'     => $invoice_id,
                'invoice_no'     => $invoice_no,
                'total'          => $total,
                'paid_amount'    => $paid_amount,
                'change_due'     => $change_due,
                'payment_method' => $pm,
            ];
        } catch(Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

// (اختياري قديم عندك)
function table_has_col(PDO $db, string $table, string $col): bool {
  $st=$db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}
