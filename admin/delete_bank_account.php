<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: bank_accounts.php');
    exit;
}

$id = (int) $_POST['id'];
deleteBankAccount($id);
header('Location: bank_accounts.php');
