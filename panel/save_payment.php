<?php
// panel/save_payment.php
//
// ثبت/ویرایش «دریافت» از هر نوع طرف‌حساب (پزشک / کلینیک / لابراتوار همکار /
// طراح / شعبه همکار). نوع از فیلدِ party_type می‌آید و ذخیره‌سازیِ نهایی در
// db.php::savePayment() انجام می‌شود (برای شعبه → saveBranchReceiptPayment).

require_once __DIR__ . '/auth.php';
require_admin();

require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: payments.php');
    exit;
}

$partyType = (string) ($_POST['party_type'] ?? 'doctor');
if (!array_key_exists($partyType, paymentPartyTypeLabels())) {
    $partyType = 'doctor';
}

$invoice_ids = !empty($_POST['invoice_ids']) && is_array($_POST['invoice_ids'])
    ? array_map('intval', $_POST['invoice_ids'])
    : [];

$party_id = !empty($_POST['party_id']) ? (int) $_POST['party_id'] : null;
if (!$party_id && !empty($_POST['doctor_id'])) {
    $party_id = (int) $_POST['doctor_id'];   // سازگاری با لینک‌های قدیمی
}

$doctor_name = trim($_POST['doctor_name'] ?? '');
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$payment_method = trim((string) ($_POST['payment_method'] ?? ''));
$payment_date_input = trim((string) ($_POST['payment_date'] ?? ''));
$payment_date = parseJalaliToGregorian($payment_date_input);
if ($payment_date === '') {
    $payment_date = date('Y-m-d');
}
$transaction_number = trim((string) ($_POST['transaction_number'] ?? ''));
$bank_account_id = !empty($_POST['bank_account_id']) ? (int) $_POST['bank_account_id'] : null;
$notes = trim((string) ($_POST['notes'] ?? ''));

$editingId = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
if ((empty($party_id) && $doctor_name === '') || $payment_method === '' || $amount <= 0) {
    header('Location: payment_form.php' . ($editingId ? '?id=' . $editingId . '&' : '?') . 'error=missing');
    exit;
}

$isBranch = ($partyType === 'branch');
$receivableId = 0;
if ($isBranch) {
    $receivableId = !empty($invoice_ids) ? (int) reset($invoice_ids) : 0;
    if ($receivableId > 0) {
        // فاکتورِ انتخاب‌شده باید متعلق به همان شعبه باشد (و برای مدیرِ شعبه، از
        // فاکتورهای همان شعبه) — جلوگیری از دست‌کاریِ طلبِ شعب دیگر.
        $myBranch = currentBranchId();
        $sql = 'SELECT partner_branch_id, branch_id FROM branch_receivables WHERE id = ?';
        $params = [$receivableId];
        if ($myBranch !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $myBranch;
        }
        $chk = db()->prepare($sql);
        $chk->execute($params);
        $rec = $chk->fetch();
        $owner = (int) ($rec['partner_branch_id'] ?? 0);
        if ($owner > 0) {
            $party_id = $owner;
        } else {
            $receivableId = 0;
        }
    }
    if (empty($party_id)) {
        header('Location: payment_form.php?party_type=branch&error=party');
        exit;
    }
}

$data = [
    'party_type'         => $partyType,
    'party_id'           => $isBranch ? null : $party_id,
    'doctor_id'          => $isBranch ? null : $party_id,
    'doctor_name'        => $isBranch ? '' : $doctor_name,
    'invoice_ids'        => $isBranch ? [] : $invoice_ids,
    'amount'             => $amount,
    'payment_method'     => $payment_method,
    'payment_date'       => $payment_date,
    'transaction_number' => $transaction_number ?: null,
    'bank_account_id'    => $bank_account_id,
    'notes'              => $notes ?: null,
];

if ($isBranch) {
    $data['partner_branch_id']    = (int) $party_id;
    $data['branch_receivable_id'] = $receivableId ?: null;
}

if ($editingId) {
    $data['id'] = $editingId;
}

$savedId = savePayment($data);
audit_log_save('payment', $savedId, $isBranch ? 'دریافت از شعبه همکار' : 'دریافت از طرف حساب');
header('Location: payments.php');
