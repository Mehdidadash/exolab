<?php
// panel/cases_data.php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$isAdmin = ($user['role'] === 'admin');
$isDoctor = ($user['role'] === 'doctor');
$doctorId = $isDoctor ? $user['id'] : null; // direct user id as doctor id

$columns = [
    0 => 'c.id',
    1 => 'u.full_name',         // doctor name from users
    2 => 'c.patient_name',
    3 => 'p.title',
    4 => 'c.location_type',
    5 => 'c.shade',
    6 => 'c.total_price',
    7 => 'cs.name',
    8 => 'c.received_date',
    9 => 'di.invoice_number',
    10 => 'c.id'
];

$draw = isset($_GET['draw']) ? (int) $_GET['draw'] : 1;
$start = isset($_GET['start']) ? (int) $_GET['start'] : 0;
$length = isset($_GET['length']) ? (int) $_GET['length'] : 25;
$searchValue = trim($_GET['search']['value'] ?? '');
$orderColumn = isset($_GET['order'][0]['column']) ? (int) $_GET['order'][0]['column'] : 8;
$orderDir = isset($_GET['order'][0]['dir']) && in_array(strtolower($_GET['order'][0]['dir']), ['asc', 'desc']) ? $_GET['order'][0]['dir'] : 'desc';

$whereClauses = ['1=1'];
$params = [];

// Scope enforcement
if ($isDoctor) {
    $whereClauses[] = 'c.doctor_id = ?';
    $params[] = $doctorId;
} elseif (!$isAdmin) {
    // For staff with view_all_cases permission, we allow all
    if (!has_permission('view_all_cases')) {
        die('دسترسی غیرمجاز');
    }
}

// Filter by status
error_log('date_from raw: ' . $_GET['date_from']);
$d = parseJalaliToGregorian($_GET['date_from']);
error_log('date_from converted: ' . $d);

if (!empty($_GET['status_id'])) {
    $whereClauses[] = 'c.status_id = ?';
    $params[] = (int) $_GET['status_id'];
}
// Filter by date range
if (!empty($_GET['date_from'])) {
    $d = parseJalaliToGregorian($_GET['date_from']);
    if ($d !== '') { $whereClauses[] = 'c.received_date >= ?'; $params[] = $d; }
}
if (!empty($_GET['date_to'])) {
    $d = parseJalaliToGregorian($_GET['date_to']);
    if ($d !== '') { $whereClauses[] = 'c.received_date <= ?'; $params[] = $d; }
}


// Global search
if ($searchValue !== '') {
    $whereClauses[] = '(c.patient_name LIKE ? OR c.description LIKE ? OR c.teeth LIKE ? OR c.shade LIKE ? OR u.full_name LIKE ? OR p.title LIKE ? OR di.invoice_number LIKE ?)';
    $s = '%' . $searchValue . '%';
    for ($i = 0; $i < 7; $i++) $params[] = $s;
}

$db = db();

// Total records (scoped for doctor)
if ($isDoctor) {
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases WHERE doctor_id = ?');
    $totalStmt->execute([$doctorId]);
} else {
    $totalStmt = $db->query('SELECT COUNT(*) FROM cases');
}
$recordsTotal = (int) $totalStmt->fetchColumn();

// Count filtered
$countSql = 'SELECT COUNT(*) FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    LEFT JOIN case_statuses cs ON c.status_id = cs.id
    LEFT JOIN doctor_invoices di ON c.invoice_id = di.id
    WHERE ' . implode(' AND ', $whereClauses);
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$recordsFiltered = (int) $countStmt->fetchColumn();

$orderBy = $columns[$orderColumn] ?? 'c.received_date';
$length = max(1, (int) $length);
$start = max(0, (int) $start);

$dataSql = "SELECT c.*, u.full_name AS doctor_name, p.title AS service_title, cs.name AS status_name,
        di.invoice_number, di.id AS invoice_id
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    LEFT JOIN case_statuses cs ON c.status_id = cs.id
    LEFT JOIN doctor_invoices di ON c.invoice_id = di.id
    WHERE " . implode(' AND ', $whereClauses) . "
    ORDER BY $orderBy $orderDir
    LIMIT $length OFFSET $start";

$stmt = $db->prepare($dataSql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$data = [];
foreach ($rows as $r) {
    $received = toJalaliDateFormatted($r['received_date']);
    $price = formatAmountToman($r['total_price'] ?? $r['unit_price'] ?? 0);
    $invoiceHtml = !empty($r['invoice_number']) ? '<a href="invoice_form.php?id=' . htmlspecialchars($r['invoice_id']) . '">' . htmlspecialchars($r['invoice_number']) . '</a>' : '—';

    if ($isAdmin) {
        $eye = svg_icon('eye', 'icon-sm');
        $edit = svg_icon('edit', 'icon-sm');
        $trash = svg_icon('trash', 'icon-sm');

        $actionDropdown = '<div class="action-dropdown" style="position:relative; display:inline-block;">'
            . '<button class="btn action-toggle" data-id="' . htmlspecialchars($r['id']) . '" style="padding:4px 8px;">⋯</button>'
            . '<div class="action-menu" style="display:none; position:absolute; right:0; background:#fff; border:1px solid #e5e7eb; padding:4px; border-radius:6px; min-width:40px; box-shadow:0 6px 18px rgba(0,0,0,0.08); z-index:999;">'
                . '<a class="btn action-icon" href="view_case.php?id=' . htmlspecialchars($r['id']) . '" target="_blank" onclick="event.stopPropagation(); window.open(this.href, \'_blank\'); return false;" style="display:block; padding:4px 6px; text-align:center;">' . $eye . '</a>'
                . '<a href="#" class="btn action-icon edit-case" data-id="' . htmlspecialchars($r['id']) . '" style="display:block; padding:4px 6px; text-align:center;">' . $edit . '</a>'
                . '<a href="#" class="btn action-icon delete-case" data-id="' . htmlspecialchars($r['id']) . '" style="display:block; padding:4px 6px; text-align:center; color:#b91c1c;">' . $trash . '</a>'
            . '</div>'
        . '</div>';
    } else {
        $actionDropdown = '<a class="btn" href="view_case.php?id=' . htmlspecialchars($r['id']) . '">مشاهده</a>';
    }

    $data[] = [
        $r['id'],
        $r['doctor_name'] ?: '—',
        $r['patient_name'] ?: '—',
        $r['service_title'] ?: '—',
        formatCaseLocation($r['location_type'], $r['teeth']),
        $r['shade'] ?: '—',
        $price,
        '<span class="badge">' . htmlspecialchars($r['status_name'] ?: 'نامشخص') . '</span>',
        $received,
        $invoiceHtml,
        $actionDropdown
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data
], JSON_UNESCAPED_UNICODE);