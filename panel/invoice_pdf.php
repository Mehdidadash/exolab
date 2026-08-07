<?php
// panel/invoice_pdf.php
require_once __DIR__ . '/auth.php';
require_login();

use Mpdf\Mpdf;
use Morilog\Jalali\Jalalian;

$invoiceId = (int) $_GET['id'];
$user = current_user();
$isAdmin = ($user['role'] === 'admin');
$isDoctor = ($user['role'] === 'doctor');
$doctorId = $isDoctor ? $user['id'] : null;

if ($isDoctor) {
    $invoice = getInvoiceForDoctor($invoiceId, $doctorId);
} else {
    $invoice = getInvoice($invoiceId);
}
if (!$invoice) {
    die('فاکتور یافت نشد.');
}

$invoiceItems = getInvoiceItems($invoice['id']);

// ---- استخراج ماه و سال شمسی ----
$jalaliDate = Jalalian::fromDateTime($invoice['invoice_date']);
$monthNumber = $jalaliDate->getMonth();
$yearNumber = $jalaliDate->getYear();
$monthNames = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
];
$monthName = $monthNames[$monthNumber - 1];
$year = toPersianDigits($yearNumber);

$doctorName = $invoice['doctor_name'] ?? 'پزشک';
$invoiceTitle = "صورت حساب {$monthName} {$year} دکتر {$doctorName}";

// ---- ساخت ردیف‌های جدول ----
$itemsRowsHtml = '';
foreach ($invoiceItems as $item) {
    $type = $item['price_title'] ?: $item['item_title'] ?: '—';
    $description = $item['item_description'] ?: $item['item_title'];
    $patient = $item['patient_name'] ?: '—';
    $quantity = toPersianDigits(number_format($item['quantity'], 0));
    $totalPrice = formatAmountToman($item['total_amount']);

    $receivedDate = !empty($item['case_received_date']) ? toJalaliDateFormatted($item['case_received_date']) : '—';
    $itemsRowsHtml .= '<tr>' .
        '<td>' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . htmlspecialchars($patient, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . $quantity . '</td>' .
        '<td>' . $receivedDate . '</td>' .
        '<td>' . $totalPrice . '</td>' .
        '</tr>';
}

if ($itemsRowsHtml === '') {
    $itemsRowsHtml = '<tr><td colspan="6">بدون آیتم</td></tr>';
}

// ---- متغیرهای دیگر ----
$invoiceNumber = htmlspecialchars($invoice['invoice_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$invoiceDate = toJalaliDateWithMonth($invoice['invoice_date']);
$invoiceDueDate = $invoice['due_date'] ? toJalaliDateWithMonth($invoice['due_date']) : '—';
$doctorPhone = htmlspecialchars($invoice['doctor_phone'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$doctorEmail = htmlspecialchars($invoice['doctor_email'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$invoiceTotal = toPersianDigits(number_format(round($invoice['total_amount']), 0));
$paymentStatus = $invoice['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده';
$invoiceNotes = htmlspecialchars($invoice['notes'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$generatedDate = toJalaliDateWithMonth(date('Y-m-d'));
$generatedTime = toPersianDigits(date('H:i'));

// ---- اطلاعات حساب بانکی ----
$bankInfoSection = '';
if (!empty($invoice['bank_owner'])) {
    $bankOwner = htmlspecialchars($invoice['bank_owner'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $bankName = htmlspecialchars($invoice['bank_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $accountNumber = htmlspecialchars($invoice['account_number'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $cardNumber = htmlspecialchars($invoice['card_number'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $ibanSheba = htmlspecialchars($invoice['iban_sheba'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $bankInfoSection = '<div class="section">
        <div class="section-title">اطلاعات حساب بانکی</div>
        <p>';
    if ($bankName) $bankInfoSection .= "<span class=\"info-label\">بانک:</span> {$bankName}<br>";
    if ($bankOwner) $bankInfoSection .= "<span class=\"info-label\">صاحب حساب:</span> {$bankOwner}<br>";
    if ($accountNumber) $bankInfoSection .= "<span class=\"info-label\">شماره حساب:</span> {$accountNumber}<br>";
    if ($cardNumber) $bankInfoSection .= "<span class=\"info-label\">شماره کارت:</span> {$cardNumber}<br>";
    if ($ibanSheba) $bankInfoSection .= "<span class=\"info-label\">شبا:</span> {$ibanSheba}<br>";
    $bankInfoSection .= '</p></div>';
}

// ---- تنظیمات mPDF ----
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
$mpdf->SetDirectionality('rtl');

// ---- لوگو ----
$logo = '<img src="../assets/icons/EXOLAB_LOGO_FULL_Background.svg" alt="EXOLAB" style="height: 100px; margin-bottom: 10px;">';

$html = <<<HTML
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: dejavusans; direction: rtl; text-align: right; margin: 0; padding: 0; }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            padding: 20px; 
            background-color: #0F172A; 
            color: #FFFFFF; 
            border-bottom: none; 
        }
        .logo { height: 100px; margin-bottom: 10px; }
        .invoice-title { 
            font-size: 18px; 
            font-weight: bold; 
            color: #06B6D4; 
            margin-top: 5px; 
        }
        .section { margin-bottom: 20px; }
        .section-title { 
            font-size: 12px; 
            font-weight: bold; 
            color: #0F172A; 
            margin-bottom: 8px; 
            border-bottom: 1px solid #E5E7EB; 
            padding-bottom: 5px; 
        }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th { background-color: #0F172A; color: white; padding: 8px; text-align: right; font-size: 11px; }
        td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; text-align: right; }
        tr:last-child td { border-bottom: 2px solid #0F172A; }
        .total-row { font-weight: bold; background-color: #E5E7EB; }
        .info-label { font-weight: bold; color: #0F172A; display: inline-block; width: 120px; }
        .footer { margin-top: 40px; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 15px; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        $logo
        <div class="invoice-title">{$invoiceTitle}</div>
    </div>

    <!-- دو ستون اطلاعات -->
    <table style="width: 100%; border: none; margin-bottom: 20px;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-left: 10px; border: none;">
                <div class="section" style="margin-bottom: 0;">
                    <div class="section-title">اطلاعات صورت‌حساب</div>
                    <p style="margin: 5px 0;">
                        <span class="info-label">شماره صورت‌حساب:</span> {$invoiceNumber}<br>
                        <span class="info-label">تاریخ:</span> {$invoiceDate}<br>
                        <span class="info-label">سررسید:</span> {$invoiceDueDate}
                    </p>
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; padding-right: 10px; border: none;">
                <div class="section" style="margin-bottom: 0;">
                    <div class="section-title">اطلاعات پزشک</div>
                    <p style="margin: 5px 0;">
                        <span class="info-label">نام:</span> {$doctorName}<br>
                        <span class="info-label">تلفن:</span> {$doctorPhone}<br>
                        <span class="info-label">ایمیل:</span> {$doctorEmail}
                    </p>
                </div>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">آیتم‌های فاکتور</div>
        <table>
            <thead>
                <tr>
                    <th>نوع</th>
                    <th>شرح</th>
                    <th>نام بیمار</th>
                    <th>تعداد</th>
                    <th>تاریخ دریافت</th>
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

    {$bankInfoSection}

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

$mpdf->WriteHTML($html);

$filename = 'invoice_' . $invoice['invoice_number'] . '.pdf';
$mpdf->Output($filename, 'D');