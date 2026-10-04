<?php
// panel/save_outsource_invoice.php — ذخیرهٔ ویرایش فاکتور برون‌سپاری
// آینهٔ save_designer_invoice.php برای فاکتورهای مخارج (برون‌سپاری).
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: outsource_invoices.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$invoice = getOutsourceInvoice($id);
if (!$invoice) {
    header('Location: outsource_invoices.php?error=notfound');
    exit;
}

$invoiceDateInput = trim((string) ($_POST['invoice_date'] ?? ''));
$invoiceDate = parseJalaliToGregorian($invoiceDateInput);
if ($invoiceDate === '') {
    header('Location: outsource_invoice_form.php?id=' . $id . '&error=date');
    exit;
}
$periodLabel = trim((string) ($_POST['period_label'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

$rows = [];
foreach (($_POST['items'] ?? []) as $k => $r) {
    if (!is_array($r) || !empty($r['remove'])) {
        continue;
    }
    $rows[] = [
        'item_id' => (int) $k,
        'qty' => (int) ($r['qty'] ?? 1),
        'unit' => (float) ($r['unit'] ?? 0),
    ];
}

saveOutsourceInvoiceEdit($id, $invoiceDate, $periodLabel, $notes, $rows);

// افزودن کیس‌های انتخاب‌شده به فاکتور (در صورت وجود)
$addCaseIds = array_values(array_filter(array_map('intval', (array) ($_POST['add_case_ids'] ?? []))));
if (!empty($addCaseIds)) {
    addCasesToOutsourceInvoice($id, $addCaseIds);
}

audit_log_save('outsource_invoice', $id, 'فاکتور برون‌سپاری');
header('Location: outsource_invoice_form.php?id=' . $id . '&ok=1');
