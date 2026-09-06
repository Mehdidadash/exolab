<?php
// panel/save_designer_invoice.php — ذخیرهٔ ویرایش فاکتور طراحی
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: designer_invoices.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$invoice = getDesignerInvoice($id);
if (!$invoice) {
    header('Location: designer_invoices.php?error=notfound');
    exit;
}

$invoiceDateInput = trim((string) ($_POST['invoice_date'] ?? ''));
$invoiceDate = parseJalaliToGregorian($invoiceDateInput);
if ($invoiceDate === '') {
    header('Location: designer_invoice_form.php?id=' . $id . '&error=date');
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

saveDesignerInvoiceEdit($id, $invoiceDate, $periodLabel, $notes, $rows);
audit_log_save('designer_invoice', $id, 'فاکتور طراحی');
header('Location: designer_invoice_form.php?id=' . $id . '&ok=1');
