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
    // ردیف‌هایی که کیسشان حذف شده («ردیف یتیم») نباید تعداد/نرخشان صفر شود؛
    // جمع کل و جزء‌به‌جزء فاکتور باید حفظ شود، پس آن‌ها را دست‌نخورده رد می‌کنیم.
    $itemId = (int) $k;
    $chk = db()->prepare('SELECT i.case_id, (SELECT COUNT(*) FROM cases c WHERE c.id = i.case_id) AS case_exists
                          FROM branch_receivable_items i WHERE i.id = ? AND i.receivable_id = ?');
    $chk->execute([$itemId, $id]);
    $row = $chk->fetch();
    if ($row && (int) $row['case_id'] > 0 && (int) $row['case_exists'] === 0) {
        $rows[] = ['item_id' => $itemId, 'skip' => true];
        continue;
    }
    $rows[] = [
        'item_id' => $itemId,
        'qty' => (int) ($r['qty'] ?? 1),
        'unit' => (float) ($r['unit'] ?? 0),
    ];
}

saveBranchReceivableEdit($id, $invoiceDate, $periodLabel, $notes, $rows);
audit_log_save('branch_receivable', $id, 'فاکتور طلب از شعبه');
header('Location: branch_receivable_form.php?id=' . $id . '&ok=1');
