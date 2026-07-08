<?php
// panel\delete_payment.php

require_once __DIR__ . '/auth.php';
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: payments.php');
    exit;
}

$id = (int) $_POST['id'];
deletePayment($id);
header('Location: payments.php');
