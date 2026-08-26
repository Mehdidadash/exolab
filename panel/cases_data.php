<?php
// panel/cases_data.php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$isDoctor = ($user['role'] === 'doctor');
$isClinicOwner = $isDoctor && !empty(getClinicDoctorIds());
$isLab = in_array($user['role'] ?? '', ['lab', 'outsource_lab', 'customer_lab', 'partner_lab']);
$isDesigner = ($user['role'] === 'designer');
$doctorId = $isDoctor ? $user['id'] : null; // direct user id as doctor id

$columns = [
    0 => 'c.id',
    1 => 'c.id',                // Case ID
    2 => 'u.full_name',         // doctor name from users
    3 => 'des.full_name',       // designer name
    4 => 'c.patient_name',
    5 => 'p.title',
    6 => 'c.location_type',
    7 => 'c.shade',
    8 => 'c.total_price',
    9 => 'cs.name',
    10 => 'c.received_date',
    11 => 'di.invoice_number',
    12 => 'c.id',               // lab
    13 => 'c.id',               // files count
    14 => 'c.id'                // actions
];

// Client-side mode: return ALL scoped rows (no pagination) for DataTables
// client-side + SearchPanes.
$clientAll = isset($_GET['mode']) && $_GET['mode'] === 'all';

$draw = isset($_GET['draw']) ? (int) $_GET['draw'] : 1;
$start = isset($_GET['start']) ? (int) $_GET['start'] : 0;
$length = isset($_GET['length']) ? (int) $_GET['length'] : 25;
$searchValue = trim($_GET['search']['value'] ?? '');
$orderColumn = isset($_GET['order'][0]['column']) ? (int) $_GET['order'][0]['column'] : 10;
$orderDir = isset($_GET['order'][0]['dir']) && in_array(strtolower($_GET['order'][0]['dir']), ['asc', 'desc']) ? $_GET['order'][0]['dir'] : 'desc';

$whereClauses = ['1=1'];
$params = [];

// Branch scoping: a branch-scoped user sees only their branch's cases
// (owned by their branch OR where their branch is the partner/source branch).
if (is_branch_scoped()) {
    $bScope = branchCaseScope('c');
    $whereClauses[] = $bScope['sql'];
    $params = array_merge($params, $bScope['params']);
}

// Scope enforcement
// Doctors see only their own cases, labs their assigned cases,
// clinics their subordinate doctors' cases, designers only the cases
// they are assigned to; only admins/staff see all.
if ($isDoctor && !$isClinicOwner) {
    $whereClauses[] = 'c.doctor_id = ?';
    $params[] = $doctorId;
} elseif ($isLab) {
    // Lab users only see cases assigned to their lab
    $whereClauses[] = 'c.lab_id = ?';
    $params[] = $user['id'];
} elseif ($isDesigner) {
    // Designers only see cases where they are assigned as the designer
    $whereClauses[] = 'c.designer_id = ?';
    $params[] = $user['id'];
} elseif (has_permission('view_clinic_cases')) {
    // Clinic users see cases of their subordinate doctors
    $clinicScope = getClinicScope('c');
    $whereClauses[] = $clinicScope['sql'];
    $params = array_merge($params, $clinicScope['params']);
} elseif (!has_permission('view_all_cases')) {
    die('دسترسی غیرمجاز');
}

// Filter by doctor
if (!empty($_GET['doctor_id'])) {
    $whereClauses[] = 'c.doctor_id = ?';
    $params[] = (int) $_GET['doctor_id'];
}

// Filter by status (with optional reverse/exclude)
if (!empty($_GET['status_id'])) {
    $statusNot = !empty($_GET['status_not']);
    $whereClauses[] = 'c.status_id ' . ($statusNot ? '<>' : '=') . ' ?';
    $params[] = (int) $_GET['status_id'];
}
// Filter by designer
if (!empty($_GET['designer_id'])) {
    $whereClauses[] = 'c.designer_id = ?';
    $params[] = (int) $_GET['designer_id'];
}
// Filter by service (type of work)
if (!empty($_GET['service_id'])) {
    $whereClauses[] = 'c.service_id = ?';
    $params[] = (int) $_GET['service_id'];
}
// Filter by shade
if (isset($_GET['shade']) && trim((string) $_GET['shade']) !== '') {
    $whereClauses[] = 'c.shade LIKE ?';
    $params[] = '%' . trim((string) $_GET['shade']) . '%';
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

// Total records (scoped)
if ($isDoctor && !$isClinicOwner) {
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases WHERE doctor_id = ?');
    $totalStmt->execute([$doctorId]);
} elseif ($isLab) {
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases WHERE lab_id = ?');
    $totalStmt->execute([$user['id']]);
} elseif ($isDesigner) {
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases WHERE designer_id = ?');
    $totalStmt->execute([$user['id']]);
} elseif (has_permission('view_clinic_cases') && $user['role'] === 'clinic') {
    $clinicScope = getClinicScope('c');
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases WHERE ' . $clinicScope['sql']);
    $totalStmt->execute($clinicScope['params']);
} elseif (is_branch_scoped()) {
    $bScope = branchCaseScope('c');
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases c WHERE ' . $bScope['sql']);
    $totalStmt->execute($bScope['params']);
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
if ($clientAll) {
    $length = 0;   // no limit
    $start = 0;
} else {
    $length = max(1, (int) $length);
    $start = max(0, (int) $start);
}

$dataSql = "SELECT c.*, u.full_name AS doctor_name, p.title AS service_title, cs.name AS status_name,
        di.invoice_number, di.id AS invoice_id, lab.full_name AS lab_name, des.full_name AS designer_name,
        b.name AS branch_name, sb.name AS source_branch_name,
        (SELECT COUNT(*) FROM case_files cf WHERE cf.case_id = c.id) AS file_count
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    LEFT JOIN case_statuses cs ON c.status_id = cs.id
    LEFT JOIN doctor_invoices di ON c.invoice_id = di.id
    LEFT JOIN users lab ON c.lab_id = lab.id
    LEFT JOIN users des ON c.designer_id = des.id
    LEFT JOIN branches b ON c.branch_id = b.id
    LEFT JOIN branches sb ON c.source_branch_id = sb.id
    WHERE " . implode(' AND ', $whereClauses) . "
    ORDER BY $orderBy $orderDir";

if (!$clientAll) {
    $dataSql .= " LIMIT $length OFFSET $start";
}

$stmt = $db->prepare($dataSql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$data = [];
foreach ($rows as $r) {
    $received = toJalaliDateFormatted($r['received_date']);
    $price = formatAmountToman($r['total_price'] ?? $r['unit_price'] ?? 0);
    $invoiceHtml = !empty($r['invoice_number']) ? '<a href="invoice_form.php?id=' . htmlspecialchars($r['invoice_id']) . '">' . htmlspecialchars($r['invoice_number']) . '</a>' : '—';
    if ($isDesigner) {
        // Designers must not see prices, totals, or invoice info
        $price = '—';
        $invoiceHtml = '—';
    }

    if ($isAdmin) {
        $eye = svg_icon('eye', 'icon-sm');
        $edit = svg_icon('edit', 'icon-sm');
        $trash = svg_icon('trash', 'icon-sm');

        $tid = htmlspecialchars($r['id'], ENT_QUOTES, 'UTF-8');
        $actionDropdown = '<div class="action-dropdown" style="position:relative; display:inline-block;">'
            . '<button class="btn action-toggle" data-id="' . $tid . '" style="padding:4px 8px;" onclick="var x=this.nextElementSibling;var o=x.style.display===\'block\';document.querySelectorAll(\'.action-menu\').forEach(function(m){m.style.display=\'none\';});if(!o)x.style.display=\'block\';event.stopPropagation();">⋯</button>'
            . '<div class="action-menu" style="display:none; position:absolute; right:0; background:#fff; border:1px solid #e5e7eb; padding:4px; border-radius:6px; min-width:40px; box-shadow:0 6px 18px rgba(0,0,0,0.08); z-index:999;">'
                . '<a class="btn action-icon" href="view_case.php?id=' . htmlspecialchars($r['id']) . '" target="_blank" onclick="event.stopPropagation(); window.open(this.href, \'_blank\'); return false;" style="display:block; padding:4px 6px; text-align:center;">' . $eye . '</a>'
                . '<a href="#" class="btn action-icon edit-case" data-id="' . htmlspecialchars($r['id']) . '" style="display:block; padding:4px 6px; text-align:center;">' . $edit . '</a>'
                . '<a href="#" class="btn action-icon delete-case" data-id="' . htmlspecialchars($r['id']) . '" style="display:block; padding:4px 6px; text-align:center; color:#b91c1c;">' . $trash . '</a>'
            . '</div>'
        . '</div>';
    } else {
        $actionDropdown = '<a class="btn" href="view_case.php?id=' . htmlspecialchars($r['id']) . '">مشاهده</a>';
    }

    // ─── Cross-branch indicator ───
    // A branch user sees cases either owned by their branch (branch_id = theirs)
    // or shared from a partner branch (source_branch_id = theirs). Show a
    // perspective-aware badge so the RECEIVING branch sees outsourced work as
    // "کار از لابراتوار همکار" and the OWNING branch sees it as "برون‌سپاری".
    $myBranch = currentBranchId();
    $caseBranch   = !empty($r['branch_id']) ? (int) $r['branch_id'] : 0;
    $caseSrcBranch = !empty($r['source_branch_id']) ? (int) $r['source_branch_id'] : 0;
    $inboundPartner = $myBranch !== null && $caseSrcBranch === $myBranch && $caseBranch !== $myBranch;
    $outboundPartner = $myBranch !== null && $caseBranch === $myBranch && $caseSrcBranch !== 0 && $caseSrcBranch !== $myBranch;
    $isLabInCase = ($r['case_type'] ?? '') === 'lab_in' && $caseSrcBranch !== 0 && $caseSrcBranch !== $caseBranch;

    $labHtml = '';
    if ($isAdmin) {
        if ($inboundPartner) {
            // We received this work from a partner branch → کار از لابراتوار همکار
            $recvAmt = getInboundReceivableAmount($r);
            $labHtml = '<span class="badge" style="background:#dcfce7; color:#166534;" title="این کیس توسط یک شعبه/لابراتوار همکار به ما ارسال شده است">کار از لابراتوار همکار</span>'
                     . ($r['source_branch_name'] ? ' <small style="color:#166534;">(' . htmlspecialchars($r['source_branch_name']) . ')</small>' : '')
                     . ' <div style="margin-top:4px; font-size:0.8rem; color:#166534;">طلب از شعبه مبدا: <b>' . formatAmountToman($recvAmt) . '</b></div>';
        } elseif ($outboundPartner) {
            // We outsourced this case to a partner branch
            $ownAmt = getInboundReceivableAmount($r);
            $labHtml = '<span class="badge" style="background:#fef3c7; color:#92400e;" title="بخشی از این کیس به شعبه/لابراتوار همکار برون‌سپاری شده است">برون‌سپاری</span>'
                     . ($r['source_branch_name'] ? ' <small style="color:#92400e;">(' . htmlspecialchars($r['source_branch_name']) . ')</small>' : '')
                     . ' <div style="margin-top:4px; font-size:0.8rem; color:#92400e;">بدهی به شعبه گیرنده: <b>' . formatAmountToman($ownAmt) . '</b></div>';
        } elseif ($isLabInCase) {
            // lab_in received from a partner lab (owned by us, originated there)
            $labHtml = '<span class="badge" style="background:#dcfce7; color:#166534;">کار از لابراتوار همکار</span>'
                     . ($r['source_branch_name'] ? ' <small style="color:#166534;">(' . htmlspecialchars($r['source_branch_name']) . ')</small>' : '');
        } else {
            $labHtml = !empty($r['lab_name']) ? htmlspecialchars($r['lab_name']) : '—';
        }
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
        $labHtml,
        $actionDropdown,
        canSeeDesignerInfo() ? ($r['designer_name'] ?: '—') : '—',
        $r['file_count'] ?: 0,
        $r['label_printed_at'] ?? null,
        $r['status_name'] ?: '—',
        $r['receipt_number'] ?: ''
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data
], JSON_UNESCAPED_UNICODE);