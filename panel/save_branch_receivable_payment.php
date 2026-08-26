<?php
// panel/save_branch_receivable_payment.php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: branch_receivables.php');
    exit;
}

$receivableId = !empty($_POST['receivable_id']) ? (int) $_POST['receivable_id'] : 0;
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$paymentDate = parseJalaliToGregorian(trim($_POST['payment_date'] ?? ''));
if ($paymentDate === '') $paymentDate = date('Y-m-d');

if ($receivableId <= 0 || $amount <= 0) {
    header('Location: branch_receivables.php?error=missing');
    exit;
}

// Verify the receivable exists
$chk = db()->prepare('SELECT id FROM branch_receivables WHERE id = ?');
$chk->execute([$receivableId]);
if (!$chk->fetch()) {
    header('Location: branch_receivables.php?error=invoice');
    exit;
}

$data = [
    'receivable_id' => $receivableId,
    'amount' => $amount,
    'payment_date' => $paymentDate,
    'method' => $_POST['method'] ?? null,
    'reference' => trim((string) ($_POST['reference'] ?? '')) !== '' ? trim($_POST['reference']) : null,
    'notes' => trim((string) ($_POST['notes'] ?? '')) !== '' ? trim($_POST['notes']) : null,
];
$savedId = saveBranchReceivablePayment($data);
audit_log_save('branch_receivable_payment', $savedId, 'دریافت از شعبه همکار');
header('Location: branch_receivables.php');
exit;
