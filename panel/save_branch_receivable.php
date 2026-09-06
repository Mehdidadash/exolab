<?php
// panel/save_branch_receivable.php — ذخیرهٔ ویرایش فاکتور طلب از شعبه
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: branch_receivables.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$invoice = getBranchReceivable($id);
if (!$invoice) {
    header('Location: branch_receivables.php?error=notfound');
    exit;
}

$invoiceDateInput = trim((string) ($_POST['invoice_date'] ?? ''));
$invoiceDate = parseJalaliToGregorian($invoiceDateInput);
if ($invoiceDate === '') {
    header('Location: branch_receivable_form.php?id=' . $id . '&error=date');
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

saveBranchReceivableEdit($id, $invoiceDate, $periodLabel, $notes, $rows);
audit_log_save('branch_receivable', $id, 'فاکتور طلب از شعبه');
header('Location: branch_receivable_form.php?id=' . $id . '&ok=1');
