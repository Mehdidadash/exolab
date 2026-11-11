<?php
// panel/payment_party_invoices.php
// فهرستِ فاکتورهای «قابل دریافت» یک طرف‌حساب برای بخشِ انتخاب فاکتور در
// payment_form.php. به‌صورت AJAX صدا زده می‌شود تا فهرستِ انتخاب فقط بعد از
// مشخص‌شدنِ طرف‌حساب (و صرفاً فاکتورهای همان طرف) نمایش داده شود.
//
// پارامترها (GET):
//   party_type : doctor | clinic | lab | designer | branch
//   party_id   : شناسهٔ کاربر (یا شناسهٔ شعبه در نوعِ branch)
//   payment_id : (اختیاری) پرداختِ در حال ویرایش، تا فاکتورهای همان پرداخت هم بیایند
//
// خروجی: {ok:true, party_label, multiple, invoices:[{id, invoice_number,
//           total_amount, applied, remaining, selected}]}

require_once __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$partyType = (string) ($_GET['party_type'] ?? 'doctor');
if (!array_key_exists($partyType, paymentPartyTypeLabels())) {
    echo json_encode(['ok' => false, 'error' => 'نوع طرف حساب نامعتبر است.', 'invoices' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
$partyId   = (int) ($_GET['party_id'] ?? 0);
$paymentId = (int) ($_GET['payment_id'] ?? 0);
$multiple  = ($partyType !== 'branch');

if ($partyId <= 0) {
    echo json_encode([
        'ok'          => true,
        'party_label' => '',
        'multiple'    => $multiple,
        'invoices'    => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$invoices = [];
foreach (getPayableInvoicesForParty($partyType, $partyId, $paymentId) as $r) {
    $remaining = (float) $r['remaining'];
    $label = $r['invoice_number']
        . ($remaining > 0 ? ' — مانده: ' . number_format($remaining) . ' تومان' : ' — تسویه‌شده');
    $invoices[] = [
        'id'             => (int) $r['id'],
        'invoice_number' => (string) $r['invoice_number'],
        'total_amount'   => (float) $r['total_amount'],
        'applied'        => (float) $r['applied'],
        'remaining'      => $remaining,
        'selected'       => (bool) $r['selected'],
        'label'          => $label,
    ];
}

$out = [
    'ok'          => true,
    'party_label' => paymentPartyTypeLabel($partyType),
    'multiple'    => $multiple,
    'invoices'    => $invoices,
];
if (empty($invoices)) {
    $out['message'] = 'برای این طرف حساب فاکتورِ پرداخت‌نشده‌ای وجود ندارد؛ مبلغ را می‌توانید دستی وارد کنید.';
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
