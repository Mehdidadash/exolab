<?php
// panel/save_expense_payment.php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: expense_payments.php');
    exit;
}

$expenseType = $_POST['expense_type'] ?? '';
if (!in_array($expenseType, ['designer', 'outsource'], true)) {
    header('Location: expense_payment_form.php?error=type');
    exit;
}
$invoiceId = !empty($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0;
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$paymentDate = parseJalaliToGregorian(trim($_POST['payment_date'] ?? ''));
if ($paymentDate === '') $paymentDate = date('Y-m-d');
$recipientBank = trim($_POST['recipient_bank'] ?? '');
$recipientCard = trim($_POST['recipient_card'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$transactionNumber = trim($_POST['transaction_number'] ?? '');

if ($invoiceId <= 0 || $amount <= 0) {
    header('Location: expense_payment_form.php?error=missing');
    exit;
}

// Verify the invoice exists of the right type
$table = $expenseType === 'outsource' ? 'outsource_invoices' : 'designer_invoices';
$chk = db()->prepare("SELECT id, total_amount FROM {$table} WHERE id = ?");
$chk->execute([$invoiceId]);
if (!$chk->fetch()) {
    header('Location: expense_payment_form.php?error=invoice');
    exit;
}

$data = [
    'expense_type' => $expenseType,
    'invoice_id' => $invoiceId,
    'amount' => $amount,
    'payment_method' => $_POST['payment_method'] ?? null,
    'payment_date' => $paymentDate,
    'transaction_number' => $transactionNumber !== '' ? $transactionNumber : null,
    'recipient_bank' => $recipientBank !== '' ? $recipientBank : null,
    'recipient_card' => $recipientCard !== '' ? $recipientCard : null,
    'notes' => $notes !== '' ? $notes : null,
];
if (!empty($_POST['id'])) {
    $data['id'] = (int) $_POST['id'];
}

$savedId = saveExpensePayment($data);
audit_log_save('expense_payment', $savedId, 'پرداخت هزینه');
header('Location: expense_payments.php');
exit;
