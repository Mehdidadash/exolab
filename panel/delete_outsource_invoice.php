<?php
// panel/delete_outsource_invoice.php
// حذف یک فاکتور برون‌سپاری (هزینه) — POST + CSRF.
// کیس‌های متصل آزاد می‌شوند تا بتوان دوباره فاکتور صادر کرد.
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: outsource_invoices.php');
    exit;
}

$id = (int) $_POST['id'];
$inv = getOutsourceInvoice($id);
if (!$inv) {
    header('Location: outsource_invoices.php?deleted=0&reason=notfound');
    exit;
}

// ⚠️ اگر پرداختی ثبت شده باشد، حذفِ بی‌هشدار خطرناک است: کاربر باید صریحاً تأیید کند
// (چک‌باکس confirm_payments در فرم). سرور هم مستقل همین را بررسی می‌کند.
$paid = getExpenseInvoicePaid('outsource', $id);
if ($paid > 0 && empty($_POST['confirm_payments'])) {
    header('Location: outsource_invoices.php?deleted=0&reason=has_payments');
    exit;
}

$summary = deleteOutsourceInvoice($id);

audit_log_delete(
    'outsource_invoice',
    $id,
    'فاکتور برون‌سپاری ' . (string) ($inv['invoice_number'] ?? ('#' . $id))
        . ' — آیتم: ' . (int) $summary['items']
        . '، کیس آزادشده: ' . (int) $summary['cases']
        . ($summary['payments'] > 0 ? '، پرداخت حذف‌شده: ' . formatAmountToman((float) $summary['payments']) . ' تومان' : '')
);

header('Location: outsource_invoices.php?deleted=' . (int) $summary['items']
    . '&cases=' . (int) $summary['cases']
    . ($summary['payments'] > 0 ? '&payments=' . rawurlencode((string) $summary['payments']) : ''));
exit;
