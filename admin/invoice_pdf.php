<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/auth.php';

use Mpdf\Mpdf;

if (empty($_GET['id'])) {
    die('Invoice ID not provided');
}

$invoice = getInvoice((int) $_GET['id']);
if (!$invoice) {
    die('Invoice not found');
}

$invoiceItems = getInvoiceItems($invoice['id']);
$itemsRowsHtml = '';

foreach ($invoiceItems as $item) {
    $type = $item['price_title'] ?: ($item['price_id'] ? 'شناسه ' . $item['price_id'] : '—');
    $description = $item['item_description'] ?: $item['item_title'];
    $patient = $item['patient_name'] ?: '—';
    $quantity = toPersianDigits(number_format($item['quantity'], 0));
    $unitPrice = formatAmountToman($item['unit_price']);
    $totalPrice = formatAmountToman($item['quantity'] * $item['unit_price']);

    $itemsRowsHtml .= '<tr>' .
        '<td>' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . htmlspecialchars($patient, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . $quantity . '</td>' .
        '<td>' . $unitPrice . '</td>' .
        '<td>' . $totalPrice . '</td>' .
        '</tr>';
}

if ($itemsRowsHtml === '') {
    $itemsRowsHtml = '<tr><td colspan="6">بدون آیتم</td></tr>';
}

$invoiceNumber = htmlspecialchars($invoice['invoice_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$invoiceDate = toJalaliDateWithMonth($invoice['invoice_date']);
$invoiceDueDate = $invoice['due_date'] ? toJalaliDateWithMonth($invoice['due_date']) : '—';
$doctorName = htmlspecialchars($invoice['doctor_name'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$doctorPhone = htmlspecialchars($invoice['doctor_phone'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$doctorEmail = htmlspecialchars($invoice['doctor_email'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$invoiceTotal = toPersianDigits(number_format($invoice['total_amount'], 0));
$paymentStatus = $invoice['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده';
$invoiceNotes = htmlspecialchars($invoice['notes'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$generatedDate = toJalaliDateWithMonth(date('Y-m-d'));
$generatedTime = toPersianDigits(date('H:i'));

$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'default_font' => 'dejavusans',
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'default_font_size' => 11,
]);

// Set RTL direction
$mpdf->SetDirectionality('rtl');

// Build HTML content
$logo = '<img src="../assets/icons/EXOLAB_LOGO_FULL.svg" alt="EXOLAB" style="height: 50px; margin-bottom: 20px;">';

$html = <<<HTML
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: dejavusans; direction: rtl; text-align: right; margin: 0; padding: 0; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0F172A; padding-bottom: 15px; }
        .logo { height: 50px; margin-bottom: 10px; }
        .company-name { font-size: 24px; font-weight: bold; color: #0F172A; margin-bottom: 5px; }
        .invoice-title { font-size: 18px; font-weight: bold; color: #06B6D4; margin-top: 15px; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 12px; font-weight: bold; color: #0F172A; margin-bottom: 10px; border-bottom: 1px solid #E5E7EB; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th { background-color: #0F172A; color: white; padding: 8px; text-align: right; font-size: 11px; }
        td { padding: 10px; border-bottom: 1px solid #E5E7EB; text-align: right; }
        tr:last-child td { border-bottom: 2px solid #0F172A; }
        .total-row { font-weight: bold; background-color: #E5E7EB; }
        .info-label { font-weight: bold; color: #0F172A; display: inline-block; width: 120px; }
        .footer { margin-top: 40px; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 15px; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        $logo
        <div class="company-name">EXOLAB</div>
        <div class="invoice-title">صورت‌حساب</div>
    </div>

    <div class="section">
        <div class="section-title">اطلاعات صورت‌حساب</div>
        <p>
            <span class="info-label">شماره صورت‌حساب:</span>
            {$invoiceNumber}<br>
            <span class="info-label">تاریخ:</span>
            {$invoiceDate}<br>
            <span class="info-label">سررسید:</span>
            {$invoiceDueDate}<br>
        </p>
    </div>

    <div class="section">
        <div class="section-title">اطلاعات دکتر</div>
        <p>
            <span class="info-label">نام:</span>
            {$doctorName}<br>
            <span class="info-label">تلفن:</span>
            {$doctorPhone}<br>
            <span class="info-label">ایمیل:</span>
            {$doctorEmail}<br>
        </p>
    </div>

    <div class="section">
        <div class="section-title">آیتم‌های فاکتور</div>
        <table>
            <thead>
                <tr>
                    <th>نوع</th>
                    <th>شرح</th>
                    <th>نام بیمار</th>
                    <th>تعداد</th>
                    <th>فی</th>
                    <th>جمع</th>
                </tr>
            </thead>
            <tbody>
                {$itemsRowsHtml}
                <tr class="total-row">
                    <td colspan="5">مبلغ کل</td>
                    <td>{$invoiceTotal} تومان</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">وضعیت</div>
        <p>
            <span class="info-label">وضعیت پرداخت:</span>
            {$paymentStatus}<br>
        </p>
    </div>

    <div class="section">
        <div class="section-title">یادداشت‌ها</div>
        <p>{$invoiceNotes}</p>
    </div>

    <div class="footer">
        <p>این صورت‌حساب توسط سیستم EXOLAB تولید شده است.</p>
        <p>تاریخ تولید: {$generatedDate} ساعت {$generatedTime}</p>
    </div>
</body>
</html>
HTML;

// Generate PDF
$mpdf->WriteHTML($html);

// Output as download
$filename = 'invoice_' . $invoice['invoice_number'] . '.pdf';
$mpdf->Output($filename, 'D');
