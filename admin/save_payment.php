<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: payments.php');
    exit;
}

$invoice_ids = !empty($_POST['invoice_ids']) && is_array($_POST['invoice_ids']) ? array_map('intval', $_POST['invoice_ids']) : [];
$doctor_id = !empty($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : null;
$doctor_name = trim($_POST['doctor_name'] ?? '');
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$payment_method = $_POST['payment_method'] ?? '';
$payment_date = $_POST['payment_date'] ?? date('Y-m-d');
$transaction_number = trim($_POST['transaction_number'] ?? '');
$bank_account_id = !empty($_POST['bank_account_id']) ? (int) $_POST['bank_account_id'] : null;
$notes = trim($_POST['notes'] ?? '');

if ((empty($doctor_id) && empty($doctor_name)) || empty($payment_method) || $amount <= 0) {
    header('Location: payment_form.php?error=missing');
    exit;
}

$data = [
    'invoice_ids' => $invoice_ids,
    'doctor_id' => $doctor_id,
    'doctor_name' => $doctor_name ?: null,
    'amount' => $amount,
    'payment_method' => $payment_method,
    'payment_date' => $payment_date,
    'transaction_number' => $transaction_number ?: null,
    'bank_account_id' => $bank_account_id,
    'notes' => $notes ?: null,
];

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $data['id'] = (int) $_POST['id'];
}

savePayment($data);
header('Location: payments.php');
