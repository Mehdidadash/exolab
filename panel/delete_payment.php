<?php
// panel\delete_payment.php

require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: payments.php');
    exit;
}

$id = (int) $_POST['id'];
audit_log_delete('payment', $id, 'پرداخت');
deletePayment($id);
header('Location: payments.php');
