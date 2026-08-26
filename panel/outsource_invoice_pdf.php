<?php
// panel/outsource_invoice_pdf.php
// PDF for an outsourcing invoice (what we pay a lab for outsourced cases).
require_once __DIR__ . '/auth.php';
require_admin();

use Mpdf\Mpdf;

$invoiceId = (int) ($_GET['id'] ?? 0);
$invoice = getOutsourceInvoice($invoiceId);
if (!$invoice) {
    die('فاکتور یافت نشد.');
}
$items = getOutsourceInvoiceItems($invoiceId);

$labName = $invoice['lab_name'] ?? 'لابراتوار';
$periodLabel = $invoice['period_label'] ?? '';
$invoiceTitle = 'صورت حساب برون‌سپاری ' . $labName . ($periodLabel ? ' — ' . $periodLabel : '');

// ─── Build items rows ───
$itemsRowsHtml = '';
$total = 0.0;
foreach ($items as $item) {
    $amount = (float) $item['total_amount'];
    $total += $amount;
    $itemsRowsHtml .= '<tr>' .
        '<td>' . htmlspecialchars($item['patient_name'] ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . htmlspecialchars($item['doctor_name'] ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . htmlspecialchars($item['service_title'] ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>' .
        '<td>' . toPersianDigits((int) $item['quantity']) . '</td>' .
        '<td>' . formatAmountToman((float) $item['unit_rate']) . '</td>' .
        '<td>' . formatAmountToman($amount) . '</td>' .
        '</tr>';
}
if ($itemsRowsHtml === '') {
    $itemsRowsHtml = '<tr><td colspan="6">بدون آیتم</td></tr>';
}

// ─── Header info ───
$invoiceNumber = htmlspecialchars($invoice['invoice_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$invoiceDate = toJalaliDateWithMonth($invoice['invoice_date']);
$invoiceTotal = toPersianDigits(number_format(round($total), 0));
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
$mpdf->SetDirectionality('rtl');

$logo = '<img src="../assets/icons/EXOLAB_LOGO_FULL_Background.svg" alt="EXOLAB" style="height: 100px; margin-bottom: 10px;">';

$html = <<<HTML
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: dejavusans; direction: rtl; text-align: right; margin: 0; padding: 0; }
        .header { text-align: center; margin-bottom: 30px; padding: 20px; background-color: #0F172A; color: #FFFFFF; }
        .invoice-title { font-size: 18px; font-weight: bold; color: #06B6D4; margin-top: 5px; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 12px; font-weight: bold; color: #0F172A; margin-bottom: 8px; border-bottom: 1px solid #E5E7EB; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th { background-color: #0F172A; color: white; padding: 8px; text-align: right; font-size: 11px; }
        td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; text-align: right; }
        tr:last-child td { border-bottom: 2px solid #0F172A; }
        .total-row { font-weight: bold; background-color: #E5E7EB; }
        .info-label { font-weight: bold; color: #0F172A; display: inline-block; width: 150px; }
        .footer { margin-top: 40px; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 15px; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        $logo
        <div class="invoice-title">{$invoiceTitle}</div>
    </div>

    <table style="width: 100%; border: none; margin-bottom: 20px;">
        <tr>
            <td style="width: 50%; vertical-align: top; border: none;">
                <div class="section" style="margin-bottom: 0;">
                    <div class="section-title">اطلاعات صورت‌حساب</div>
                    <p style="margin: 5px 0;">
                        <span class="info-label">شماره صورت‌حساب:</span> {$invoiceNumber}<br>
                        <span class="info-label">لابراتوار:</span> {$labName}<br>
                        <span class="info-label">بازه:</span> {$periodLabel}<br>
                        <span class="info-label">تاریخ:</span> {$invoiceDate}
                    </p>
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; border: none;"></td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">آیتم‌های فاکتور</div>
        <table>
            <thead>
                <tr>
                    <th>بیمار</th>
                    <th>پزشک</th>
                    <th>خدمت</th>
                    <th>تعداد</th>
                    <th>نرخ برون‌سپاری (تومان)</th>
                    <th>جمع (تومان)</th>
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
$filename = 'outsource_invoice_' . $invoice['invoice_number'] . '.pdf';
$mpdf->Output($filename, 'D');
