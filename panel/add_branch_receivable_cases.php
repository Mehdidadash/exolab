<?php
// panel/add_branch_receivable_cases.php
// افزودن کیس از ماه‌های دیگر به یک فاکتور طلبِ موجود (فاکتور طلب از شعبه همکار).
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

$caseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids'])
    ? array_map('intval', $_POST['case_ids'])
    : [];

if (empty($caseIds)) {
    header('Location: branch_receivable_form.php?id=' . $id . '&error=nopick');
    exit;
}

$added = addCasesToBranchReceivable($id, $caseIds);
audit_log_save('branch_receivable', $id, 'افزودن ' . $added . ' کیس به فاکتور طلب از شعبه');

header('Location: branch_receivable_form.php?id=' . $id . '&added=' . $added);
