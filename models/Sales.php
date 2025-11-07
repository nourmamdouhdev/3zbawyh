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
        $allowed = ['cash','visa','instapay','vodafone_cash','agel','mixed'];
        return in_array($pm, $allowed, true) ? $pm : 'mixed';
    }

    // البحث أو إنشاء عميل بسيط بالاسم/التليفون
    private static function upsertCustomer(PDO $db, string $name, string $phone): ?int {
        $name  = trim($name);
        $phone = trim($phone);
        if ($name === '' && $phone === '') return null;

        if ($phone !== '') {
            $q = $db->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
            $q->execute([$phone]);
            $id = $q->fetchColumn();
            if ($id) {
                if ($name !== '') {
                    $upd = $db->prepare("UPDATE customers SET name = COALESCE(NULLIF(?,''), name) WHERE id=?");
                    $upd->execute([$name, $id]);
                }
                return (int)$id;
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

            $paid_amount  = 0.0;          // إجمالي المقبوض
            $change_due   = 0.0;          // الباقي نقدًا
            $payment_ref  = null;         // مرجع التحويل (لو موجود)
            $payment_note = null;         // ملاحظات (قد تحتوي MULTI;...)

            if ($pm === 'cash') {
                // كاش فقط: لازم يدفع >= الإجمالي، والباقي يسجّل change
                $paid_cash  = (float)($payment['paid_cash']  ?? 0);
                $change_due = max(0, (float)($payment['change_due'] ?? ($paid_cash - $total)));
                if ($paid_cash + 1e-6 < $total) {
                    throw new Exception('المبلغ النقدي أقل من الإجمالي.');
                }
                $paid_amount = $paid_cash;

            } elseif ($pm === 'instapay' || $pm === 'visa' || $pm === 'vodafone_cash' || $pm === 'agel') {
                // طريقة واحدة غير كاش (أو آجل): نعتبر الفاتورة Paid بالكامل (التحقق تم في الـAPI)
                // مرجع مطلوب لِـ Instapay و Vodafone Cash
                if ($pm === 'instapay' || $pm === 'vodafone_cash') {
                    $payment_ref = trim((string)($payment['payment_ref'] ?? ''));
                    if ($payment_ref === '') {
                        throw new Exception('مرجع التحويل مطلوب لـ InstaPay/Vodafone Cash.');
                    }
                } else {
                    $payment_ref = trim((string)($payment['payment_ref'] ?? '')) ?: null;
                }
                $payment_note = trim((string)($payment['payment_note'] ?? '')) ?: null;
                $paid_amount  = $total;
                $change_due   = 0.0;

            } else { // mixed
                // دفع مُجزّأ: نعتمد القيم القادمة من الـAPI بعد التحقق هناك
                $paid_cash    = (float)($payment['paid_cash'] ?? 0);
                $change_due   = max(0, (float)($payment['change_due'] ?? 0));
                $payment_ref  = trim((string)($payment['payment_ref'] ?? '')) ?: null;  // أول مرجع غير كاش إن وجد
                $payment_note = trim((string)($payment['payment_note'] ?? '')) ?: null; // غالبًا MULTI;method,amount,...

                // بما إن الـAPI متأكد إن إجمالي المدفوعات يساوي الإجمالي (+/− الباقي من الكاش فقط)
                // فنسجل الفاتورة Paid بالكامل:
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
