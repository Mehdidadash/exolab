<?php
// panel\delete_invoice.php

require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: invoices.php');
    exit;
}

$id = (int) $_POST['id'];
audit_log_delete('invoice', $id, 'فاکتور');
deleteInvoice($id);
header('Location: invoices.php');
