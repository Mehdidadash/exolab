<?php
// panel/delete_expense_payment.php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

$id = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id > 0) {
    deleteExpensePayment($id);
}
header('Location: expense_payments.php');
exit;
