<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: invoices.php');
    exit;
}

$invoice_number = trim($_POST['invoice_number'] ?? '');
$doctor_id = !empty($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : null;
$doctor_name = trim($_POST['doctor_name'] ?? '');
$doctor_phone = trim($_POST['doctor_phone'] ?? '');
$doctor_email = trim($_POST['doctor_email'] ?? '');
$payment_status = $_POST['payment_status'] ?? 'unpaid';
$invoice_date = parseDateInput($_POST['invoice_date'] ?? '') ?: date('Y-m-d');
$due_date = parseDateInput(trim($_POST['due_date'] ?? ''));
$notes = trim($_POST['notes'] ?? '');

if ($doctor_id && empty($doctor_name)) {
    $selectedDoctor = getDoctor($doctor_id);
    if ($selectedDoctor) {
        $doctor_name = $selectedDoctor['name'];
        $doctor_phone = $doctor_phone ?: $selectedDoctor['phone'];
        $doctor_email = $doctor_email ?: $selectedDoctor['email'];
    }
}

$items = [];
if (!empty($_POST['items']) && is_array($_POST['items'])) {
    foreach ($_POST['items'] as $item) {
        $item_description = trim($item['item_description'] ?? '');
        $item_title = trim($item['item_title'] ?? '');
        if ($item_title === '' && $item_description !== '') {
            $item_title = $item_description;
        }
        if ($item_title === '' && $item_description === '') {
            continue;
        }
        $items[] = [
            'price_id' => !empty($item['price_id']) ? (int) $item['price_id'] : null,
            'item_title' => $item_title,
            'item_description' => $item_description,
            'patient_name' => trim($item['patient_name'] ?? ''),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'unit_price' => (float) ($item['unit_price'] ?? 0),
        ];
    }
}

if (empty($invoice_number) || (empty($doctor_id) && empty($doctor_name)) || empty($items)) {
    header('Location: invoice_form.php?error=missing');
    exit;
}

$data = [
    'invoice_number' => $invoice_number,
    'doctor_id' => $doctor_id,
    'doctor_name' => $doctor_name ?: null,
    'doctor_phone' => $doctor_phone ?: null,
    'doctor_email' => $doctor_email ?: null,
    'payment_status' => $payment_status,
    'invoice_date' => $invoice_date,
    'due_date' => $due_date ?: null,
    'notes' => $notes ?: null,
    'items' => $items,
];

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $data['id'] = (int) $_POST['id'];
}

saveInvoice($data);
header('Location: invoices.php');
exit;
