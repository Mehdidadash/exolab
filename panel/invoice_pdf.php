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

// ─── دسترسی: فقط فاکتور خودِ هر طرف ───
// مدیر/مالی (view_invoices) همه‌ی فاکتورهای دکتر را می‌بینند؛ پزشک فقط فاکتور خودش
// (بالا اسکوپ شده)؛ کلینیک فقط فاکتور کلینیک خودش یا پزشک‌های زیرمجموعه؛
// طراح و لابراتوار به فاکتور دکتر دسترسی ندارند (فاکتور خودشان فایل PDF جدا دارد).
if (!is_admin() && !has_permission('view_invoices')) {
    if ($user['role'] === 'clinic') {
        if (!has_permission('view_clinic_invoices')) {
            http_response_code(403);
            die('دسترسی غیرمجاز');
        }
        $clinicOk = (int) $invoice['doctor_id'] === (int) $user['id']; // فاکتور خود کلینیک (INV-CLN)
        if (!$clinicOk) {
            $clinicScope = getClinicScope('i');
            $st = db()->prepare('SELECT id FROM doctor_invoices i WHERE i.id = ? AND ' . $clinicScope['sql']);
            $st->execute(array_merge([$invoiceId], $clinicScope['params']));
            if (!$st->fetchColumn()) {
                http_response_code(403);
                die('دسترسی غیرمجاز');
            }
        }
    } elseif (in_array($user['role'] ?? '', ['designer', 'outsource_lab', 'partner_lab', 'customer_lab', 'lab'], true)) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    } elseif (!has_permission('view_own_invoices')) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
}

$invoiceItems = getInvoiceItems($invoice['id']);

// ---- استخراج ماه/سال از تاریخ دریافت کیس‌های این فاکتور ----
$monthNames = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
];

$caseMonths = [];
foreach ($invoiceItems as $item) {
    $d = $item['case_received_date'] ?? '';
    if ($d === '' || $d === null) continue;
    try {
        $jd = Jalalian::fromDateTime($d);
        $ym = [$jd->getYear(), $jd->getMonth()];
        $caseMonths[$ym[0] * 100 + $ym[1]] = $ym;
    } catch (\Throwable $e) {
        // تاریخ نامعتبر را نادیده بگیر
    }
}
ksort($caseMonths);
$caseMonths = array_values($caseMonths);

// اگر هیچ کیسی تاریخ دریافت نداشت، ماه صدور فاکتور را بنویس
if (empty($caseMonths)) {
    $jalaliDate = Jalalian::fromDateTime($invoice['invoice_date']);
    $caseMonths = [[$jalaliDate->getYear(), $jalaliDate->getMonth()]];
}

$years = array_unique(array_column($caseMonths, 0));
$sameYear = count($years) === 1;

$monthPart = '';
$totalMonths = count($caseMonths);
foreach ($caseMonths as $i => $ym) {
    if ($i > 0) {
        $monthPart .= ($i === $totalMonths - 1) ? ' و ' : '، ';
    }
    $monthPart .= $monthNames[$ym[1] - 1];
    if (!$sameYear) {
        $monthPart .= ' ' . toPersianDigits($ym[0]);
    }
}
if ($sameYear && $totalMonths === 1) {
    $monthPart .= ' ' . toPersianDigits($years[0]);
}

$invNum = (string) ($invoice['invoice_number'] ?? '');
$isLabInvoice = str_starts_with($invNum, 'INV-LAB');
$isClinicInvoice = str_starts_with($invNum, 'INV-CLN');
$isGroupedInvoice = $isLabInvoice || $isClinicInvoice;
$partyType = $isLabInvoice ? 'لابراتوار' : ($isClinicInvoice ? 'کلینیک' : 'پزشک');
$titlePartyLabel = $isLabInvoice ? 'لابراتوار' : ($isClinicInvoice ? 'کلینیک' : 'دکتر');

// نام طرف مقابل (پزشک / کلینیک / لابراتوار) بدون پیشوند تکراری
$partyName = trim((string) ($invoice['doctor_name'] ?? ''));
if ($isClinicInvoice) {
    $partyName = preg_replace('/^کلینیک\s+/u', '', $partyName);
} elseif ($isLabInvoice) {
    $partyName = preg_replace('/^لابراتوار\s+/u', '', $partyName);
} else {
    $partyName = preg_replace('/^دکتر\s+/u', '', $partyName);
}
if ($partyName === '') {
    $partyName = $partyType;
}

if ($isGroupedInvoice) {
    // Clinic/lab invoices can be monthly, weekly, or daily – show the period if recorded
    $periodPart = '';
    if (!empty($invoice['notes']) && preg_match('/بازه:\s*([^—]+)/u', $invoice['notes'], $m)) {
        $periodPart = ' — ' . trim($m[1]);
    }
    $invoiceTitle = "صورت حساب {$monthPart} {$titlePartyLabel} {$partyName}{$periodPart}";
} else {
    $invoiceTitle = "صورت حساب {$monthPart} {$titlePartyLabel} {$partyName}";
}

// ---- ساخت ردیف‌های جدول ----
$itemsRowsHtml = '';
$currentDoctor = null;

// For lab invoices, group items by doctor with section headers
foreach ($invoiceItems as $item) {
    $type = $item['price_title'] ?: $item['item_title'] ?: '—';
    $description = $item['item_description'] ?: $item['item_title'];
    $patient = $item['patient_name'] ?: '—';
    $quantity = toPersianDigits(number_format($item['quantity'], 0));
    $amount = (float)$item['total_amount'];
    $isNeg = $amount < 0;
    $totalPrice = formatAmountToman(abs($amount));
    $totalStyle = $isNeg ? ' style="color:#b91c1c; font-weight:bold;"' : '';

    $receivedDate = !empty($item['case_received_date']) ? toJalaliDateFormatted($item['case_received_date']) : '—';

    // Doctor grouping header for lab/clinic invoices
    if ($isGroupedInvoice) {
        $groupDoctor = $item['case_doctor_name'] ?: 'بدون پزشک';
        if ($groupDoctor !== $currentDoctor) {
            $currentDoctor = $groupDoctor;
            $itemsRowsHtml .= '<tr><td colspan="6" style="background:#E5E7EB; color:#0F172A; font-weight:bold; padding:6px 10px;">پزشک: ' . htmlspecialchars($groupDoctor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>';
        }
        // For lab_out show a marker
        if (($item['case_type'] ?? '') === 'lab_out') {
            $description = 'برونسپاری - ' . $description;
        }
    }

    $itemsRowsHtml .= '<tr>' .
        '<td>' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . htmlspecialchars($patient, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . $quantity . '</td>' .
        '<td>' . $receivedDate . '</td>' .
        '<td' . $totalStyle . '>' . $totalPrice . '</td>' .
        '</tr>';
}

if ($itemsRowsHtml === '') {
    $itemsRowsHtml = '<tr><td colspan="6">بدون آیتم</td></tr>';
}

// ---- تعداد هر خدمت به تفکیک (خلاصه زیر فاکتور) — تخفیف‌ها به‌صورت یک ردیفِ جمع ----
$serviceSummaryHtml = invoiceServiceSummaryHtml($invoiceItems);

// ---- متغیرهای دیگر ----
$invoiceNumber = htmlspecialchars($invoice['invoice_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$invoiceDate = toJalaliDateWithMonth($invoice['invoice_date']);
$invoiceDueDate = $invoice['due_date'] ? toJalaliDateWithMonth($invoice['due_date']) : '—';
$doctorPhone = htmlspecialchars($invoice['doctor_phone'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$invoiceTotal = toPersianDigits(number_format(round($invoice['total_amount']), 0));
$paymentStatus = $invoice['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده';
$invoiceNotes = htmlspecialchars($invoice['notes'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$partySectionTitle = $isGroupedInvoice ? ('اطلاعات ' . $partyType) : 'اطلاعات پزشک';
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
    'default_font' => 'vazir',
    'fontDir' => [__DIR__ . '/../assets/fonts'],
    'fontdata' => [
        'vazir' => [
            'R' => 'Vazir.ttf',
            'B' => 'Vazir-Bold.ttf',
            'useOTL' => 0xFF,
        ],
    ],
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'default_font_size' => 10,
    'autoScriptToLang' => true,
    'autoLangToFont' => true,
]);
$mpdf->SetDirectionality('rtl');

// ---- لوگو ----
$logo = '<div style="font-size:22px; font-weight:bold; color:#003D9A; letter-spacing:2px; margin-bottom:2px;">EXOLAB</div>';

$html = <<<HTML
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: vazir; direction: rtl; text-align: right; margin: 0; padding: 0; }
        .header { 
            text-align: center; 
            margin-bottom: 25px; 
            padding: 5px 5px 12px; 
            border-bottom: 1px solid #E5E7EB; 
        }
        .invoice-title { 
            font-size: 16px; 
            font-weight: bold; 
            color: #0F172A; 
            margin-top: 4px; 
        }
        .section { margin-bottom: 20px; }
        .section-title { 
            font-size: 11px; 
            font-weight: bold; 
            color: #0F172A; 
            margin-bottom: 8px; 
            border-bottom: 1px solid #E5E7EB; 
            padding-bottom: 5px; 
        }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th { background-color: #E5E7EB; color: #0F172A; padding: 8px; text-align: right; font-size: 10px; }
        td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; text-align: right; }
        tr:last-child td { border-bottom: 2px solid #0F172A; }
        .total-row { font-weight: bold; background-color: #E5E7EB; }
        .info-label { font-weight: bold; color: #0F172A; display: inline-block; width: 120px; }
        .footer { margin-top: 40px; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 15px; font-size: 9px; color: #666; }
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
                    <div class="section-title">{$partySectionTitle}</div>
                    <p style="margin: 5px 0;">
                        <span class="info-label">نام:</span> {$partyName}<br>
                        <span class="info-label">تلفن:</span> {$doctorPhone}
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

    {$serviceSummaryHtml}

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