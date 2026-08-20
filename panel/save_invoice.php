<?php
// panel\save_invoice.php

require_once __DIR__ . '/auth.php';
require_role('admin');

require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: invoices.php');
    exit;
}

$invoice_number = trim($_POST['invoice_number'] ?? '');
$doctor_id = !empty($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : null;
$doctor_name = trim($_POST['doctor_name'] ?? '');
$doctor_phone = trim($_POST['doctor_phone'] ?? '');
$doctor_email = trim($_POST['doctor_email'] ?? '');
$bank_account_id = !empty($_POST['bank_account_id']) ? (int) $_POST['bank_account_id'] : null;
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
$caseIds = [];
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
        $caseId = !empty($item['case_id']) ? (int) $item['case_id'] : null;
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $items[] = [
            'price_id' => !empty($item['price_id']) ? (int) $item['price_id'] : null,
            'case_id' => $caseId,
            'item_title' => $item_title,
            'item_description' => $item_description,
            'patient_name' => trim($item['patient_name'] ?? ''),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'unit_price' => $unitPrice,
            'total_amount' => isset($item['total_amount']) ? $item['total_amount'] : null,
        ];
        if ($caseId) {
            $caseIds[] = $caseId;
        }
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
    'payment_status' => 'unpaid',
    'bank_account_id' => $bank_account_id,
    'invoice_date' => $invoice_date,
    'due_date' => $due_date ?: null,
    'notes' => $notes ?: null,
    'items' => $items,
    'case_ids' => $caseIds,
];

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $data['id'] = (int) $_POST['id'];
}

$savedId = saveInvoice($data);
audit_log_save('invoice', $savedId, 'فاکتور');

// Update case-invoice links: set invoice_id for new cases, clear for removed ones
if ($doctor_id) {
    // Clear invoice_id for cases that were unlinked (only when editing)
    if (isset($data['id'])) {
        $oldItems = getInvoiceItems($data['id']);
        $oldCaseIds = array_filter(array_map(function($i) { return $i['case_id']; }, $oldItems));
        $removedCaseIds = array_diff($oldCaseIds, $caseIds);
        if (!empty($removedCaseIds)) {
            $placeholders = implode(',', array_fill(0, count($removedCaseIds), '?'));
            $clearStmt = db()->prepare("UPDATE cases SET invoice_id = NULL WHERE id IN ($placeholders)");
            $clearStmt->execute(array_values($removedCaseIds));
        }
    }
    // Set invoice_id for new cases
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $linkStmt = db()->prepare("UPDATE cases SET invoice_id = ? WHERE id IN ($placeholders)");
        $linkParams = array_merge([$savedId], $caseIds);
        $linkStmt->execute($linkParams);
    }
}

header('Location: invoices.php');
exit;
