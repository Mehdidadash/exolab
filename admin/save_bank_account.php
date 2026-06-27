<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bank_accounts.php');
    exit;
}

$account_owner_name = trim($_POST['account_owner_name'] ?? '');
$bank_name = trim($_POST['bank_name'] ?? '');
$account_number = trim($_POST['account_number'] ?? '');
$card_number = trim($_POST['card_number'] ?? '');
$iban_sheba = trim($_POST['iban_sheba'] ?? '');
$is_active = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;
$notes = trim($_POST['notes'] ?? '');

if (empty($account_owner_name) || empty($bank_name) || (empty($account_number) && empty($card_number) && empty($iban_sheba))) {
    header('Location: bank_account_form.php?error=missing');
    exit;
}

$data = [
    'account_owner_name' => $account_owner_name,
    'bank_name' => $bank_name,
    'account_number' => $account_number ?: null,
    'card_number' => $card_number ?: null,
    'iban_sheba' => $iban_sheba ?: null,
    'is_active' => $is_active,
    'notes' => $notes ?: null,
];

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $data['id'] = (int) $_POST['id'];
}

saveBankAccount($data);
header('Location: bank_accounts.php');
