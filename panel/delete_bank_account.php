<?php
// panel\delete_bank_account.php

require_once __DIR__ . '/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: bank_accounts.php');
    exit;
}

$id = (int) $_POST['id'];
deleteBankAccount($id);
header('Location: bank_accounts.php');
