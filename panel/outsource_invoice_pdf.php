<?php
// panel/outsource_invoice_pdf.php
// PDF for an outsourcing invoice (what we pay a lab for outsourced cases).
require_once __DIR__ . '/auth.php';
require_login();

use Mpdf\Mpdf;

$invoiceId = (int) ($_GET['id'] ?? 0);
$user = current_user();
// Access: admins / finance (view_invoices), or a lab with view_own_invoices.
if (!is_admin() && !has_permission('view_invoices') && !has_permission('view_own_invoices')) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}
$invoice = getOutsourceInvoice($invoiceId);
if (!$invoice) {
    die('فاکتور یافت نشد.');
}
// A lab may only open PDF of its OWN invoice.
if (!is_admin() && !has_permission('view_invoices') && (int) $invoice['lab_id'] !== (int) $user['id']) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
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

// ---- تعداد هر خدمت به تفکیک (خلاصه زیر فاکتور) ----
$serviceSummary = [];
foreach ($items as $item) {
    $svc = $item['service_title'] ?: '—';
    $qty = (int) ($item['quantity'] ?? 0);
    if (!isset($serviceSummary[$svc])) {
        $serviceSummary[$svc] = ['title' => $svc, 'qty' => 0];
    }
    $serviceSummary[$svc]['qty'] += $qty;
}
usort($serviceSummary, function ($a, $b) { return $b['qty'] <=> $a['qty']; });

$serviceSummaryHtml = '';
if (!empty($serviceSummary)) {
    $serviceSummaryHtml .= '<div class="section">'
        . '<div class="section-title">تعداد خدمات به تفکیک</div>'
        . '<table>'
        . '<thead><tr><th>خدمت</th><th>تعداد</th></tr></thead>'
        . '<tbody>';
    foreach ($serviceSummary as $s) {
        $serviceSummaryHtml .= '<tr>'
            . '<td>' . htmlspecialchars($s['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
            . '<td>' . toPersianDigits(number_format($s['qty'], 0)) . '</td>'
            . '</tr>';
    }
    $serviceSummaryHtml .= '</tbody></table></div>';
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

// لوگو به‌صورت متن (مثل فاکتور معمولی) تا بدون وابستگی به فایل SVG چاپ شود.
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
                        <span class="info-label">تاریخ:</span> {$invoiceDate}
                    </p>
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; padding-right: 10px; border: none;">
                <div class="section" style="margin-bottom: 0;">
                    <div class="section-title">اطلاعات لابراتوار</div>
                    <p style="margin: 5px 0;">
                        <span class="info-label">نام:</span> {$labName}<br>
                        <span class="info-label">بازه:</span> {$periodLabel}
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

    {$serviceSummaryHtml}

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
