<?php
// panel/delete_designer_invoice.php — حذف فاکتور طراحی
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: designer_invoices.php');
    exit;
}

$id = (int) $_POST['id'];
$invoice = getDesignerInvoice($id);
if (!$invoice) {
    header('Location: designer_invoices.php?error=notfound');
    exit;
}

audit_log_delete('designer_invoice', $id, 'فاکتور طراحی');
deleteDesignerInvoice($id);
header('Location: designer_invoices.php?deleted=1');
