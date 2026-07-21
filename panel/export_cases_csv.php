<?php
// panel/export_cases_csv.php
// Export selected cases to CSV for lab (patient, qty, service, teeth, shade – NO doctor name)

require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

// CSRF via header or POST
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf_token'] ?? '');
$sessionToken = $_SESSION['_csrf_token'] ?? '';
if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    die('CSRF invalid');
}

$caseIds = $_POST['case_ids'] ?? [];
if (empty($caseIds) || !is_array($caseIds)) {
    http_response_code(400);
    die('No cases selected');
}

$caseIds = array_map('intval', $caseIds);
$placeholders = implode(',', array_fill(0, count($caseIds), '?'));

// Fetch case data – explicitly exclude doctor name
$stmt = db()->prepare("
    SELECT c.id, c.patient_name, c.quantity, p.title AS service_title,
           c.location_type, c.teeth, c.shade
    FROM cases c
    LEFT JOIN site_prices p ON c.service_id = p.id
    WHERE c.id IN ($placeholders)
    ORDER BY c.id ASC
");
$stmt->execute($caseIds);
$cases = $stmt->fetchAll();

// Build CSV
$filename = 'cases_export_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Output UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

// Persian number helper
function toPersianCsv($str) {
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($english, $persian, (string)$str);
}

$locationLabels = [
    'teeth' => 'دندان',
    'upper' => 'فک بالا',
    'lower' => 'فک پایین',
    'both' => 'هر دو فک',
];

// Header row
$out = fopen('php://output', 'w');
fputcsv($out, ['ردیف', 'نام بیمار', 'تعداد', 'نوع کار', 'مکان', 'شماره دندان(ها)', 'سایه']);

// Aggregate quantities per service
$serviceTotals = [];

$index = 1;
foreach ($cases as $c) {
    $locationLabel = $locationLabels[$c['location_type']] ?? $c['location_type'] ?? '—';
    fputcsv($out, [
        toPersianCsv($index),
        $c['patient_name'] ?? '',
        toPersianCsv($c['quantity'] ?? 1),
        $c['service_title'] ?? '',
        $locationLabel,
        $c['teeth'] ?? '—',
        $c['shade'] ?? '—',
    ]);
    $index++;

    // Aggregate
    $svc = $c['service_title'] ?? 'سایر';
    if (!isset($serviceTotals[$svc])) $serviceTotals[$svc] = 0;
    $serviceTotals[$svc] += (int)($c['quantity'] ?? 1);
}

// Empty row separator
fputcsv($out, []);

// Summary rows
fputcsv($out, ['', 'جمع کل بر اساس نوع کار:', '', '', '', '', '']);
foreach ($serviceTotals as $svcName => $totalQty) {
    fputcsv($out, ['', $svcName, toPersianCsv($totalQty) . ' واحد', '', '', '', '']);
}

fclose($out);
exit;
