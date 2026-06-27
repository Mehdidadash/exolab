<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: invoices.php');
    exit;
}

$id = (int) $_POST['id'];
deleteInvoice($id);
header('Location: invoices.php');
