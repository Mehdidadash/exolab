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
// مدیر کل (نقش admin) نیز به‌عنوان شعبهٔ مرکزی (۱) رفتار می‌کند.
if (is_branch_scoped() || is_root_admin()) {
    $scopeBranch = currentBranchId();
    if ($scopeBranch === null) $scopeBranch = 1;
    $bScope = branchCaseScope('c', $scopeBranch);
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
    // کارهای لابراتوارِ خودشان + کارهایی که (به‌عنوان طراح) به آن‌ها سپرده شده؛
    // یک کاربر می‌تواند هم‌زمان «لابراتوار برون‌سپاری» و «طراح» باشد (مثال: کیس ۱۲۲۴).
    $whereClauses[] = '(c.lab_id = ? OR c.outsourced_lab_id = ? OR c.designer_id = ?)';
    $params[] = $user['id'];
    $params[] = $user['id'];
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

// کیس‌های «تحویل شد» (status 4) به‌صورت پیش‌فرض مخفی هستند؛ با ?show_delivered=1 یا
// وقتی فیلتر وضعیت صریح انتخاب شده باشد، نمایش داده می‌شوند.
if (empty($_GET['status_id']) && empty($_GET['show_delivered'])) {
    $whereClauses[] = 'c.status_id <> 4';
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
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases WHERE lab_id = ? OR outsourced_lab_id = ? OR designer_id = ?');
    $totalStmt->execute([$user['id'], $user['id'], $user['id']]);
} elseif ($isDesigner) {
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases WHERE designer_id = ?');
    $totalStmt->execute([$user['id']]);
} elseif (has_permission('view_clinic_cases') && $user['role'] === 'clinic') {
    $clinicScope = getClinicScope('c');
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM cases WHERE ' . $clinicScope['sql']);
    $totalStmt->execute($clinicScope['params']);
} elseif (is_branch_scoped() || is_root_admin()) {
    $scopeBranch = currentBranchId();
    if ($scopeBranch === null) $scopeBranch = 1;
    $bScope = branchCaseScope('c', $scopeBranch);
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

$dataSql = "SELECT c.*, u.full_name AS doctor_name, p.title AS service_title, p.short_name AS service_short, cs.name AS status_name,
        cs.icon AS status_icon, cs.color AS status_color,
        di.invoice_number, di.id AS invoice_id, lab.full_name AS lab_name, olab.full_name AS outsourced_lab_name,
        des.full_name AS designer_name,
        b.name AS branch_name, sb.name AS source_branch_name,
        (SELECT COUNT(*) FROM case_files cf WHERE cf.case_id = c.id) AS file_count,
        (SELECT COUNT(*) FROM case_activity_log l WHERE l.case_id = c.id AND l.action = 'file_download' AND l.details LIKE '%\"type\":\"design\"%') AS design_downloaded,
        (SELECT COUNT(*) FROM case_activity_log l WHERE l.case_id = c.id AND l.action = 'file_download' AND l.details LIKE '%\"type\":\"raw\"%') AS raw_downloaded,
        (SELECT MAX(lv.created_at) FROM case_activity_log lv WHERE lv.case_id = c.id AND lv.user_id = " . (int) $user['id'] . " AND lv.action = 'view') AS my_last_view,
        (SELECT MAX(ec.created_at) FROM entity_comments ec WHERE ec.entity_type = 'case' AND ec.entity_id = c.id) AS last_comment,
        (SELECT MAX(cf2.created_at) FROM case_files cf2 WHERE cf2.case_id = c.id) AS last_file
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    LEFT JOIN case_statuses cs ON c.status_id = cs.id
    LEFT JOIN doctor_invoices di ON c.invoice_id = di.id
    LEFT JOIN users lab ON c.lab_id = lab.id
    LEFT JOIN users olab ON c.outsourced_lab_id = olab.id
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

// نقشهٔ وضعیت‌ها برای نمایش (شماره‌ی مرحله + آیکون + رنگ) — به ترتیب تعیین‌شده.
$statusMeta = [];
$stOrdinal = 0;
foreach (getAllCaseStatuses() as $st) {
    $stOrdinal++;
    $statusMeta[(int) $st['id']] = [
        'name' => (string) $st['name'],
        'icon' => (string) ($st['icon'] ?? ''),
        'color' => (string) ($st['color'] ?? ''),
        'num' => $stOrdinal,
    ];
}

foreach ($rows as $r) {
    $received = toJalaliDateFormatted($r['received_date']);
    $price = formatAmountToman($r['total_price'] ?? $r['unit_price'] ?? 0);

    // اگر بیننده «انجام‌دهنده/گیرندهٔ کارِ برون‌سپاری» است، مبلغِ مربوط به خودش را نشان بده
    // (نرخ برون‌سپاری × تعداد)، نه قیمتِ خرده‌فروشیِ شعبهٔ مالک.
    // مثال: لابراتوار مرکزی روی کیسِ lab_outِ قزوین → ۵٬۰۰۰٬۰۰۰ (نه ۹٬۵۰۰٬۰۰۰).
    $providerLabId = 0;
    if (($r['case_type'] ?? '') === 'lab_out') {
        $providerLabId = (int) ($r['lab_id'] ?? 0);
    } elseif (!empty($r['outsourced_lab_id']) && (int) ($r['outsourced_qty'] ?? 0) > 0) {
        $providerLabId = (int) $r['outsourced_lab_id'];
    }
    if ($providerLabId > 0) {
        $isProvider = $isLab && $providerLabId === (int) $user['id'];
        if (!$isProvider) {
            $plabSt = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
            $plabSt->execute([$providerLabId]);
            $plabBranch = (int) $plabSt->fetchColumn();
            $myBr = currentBranchId();
            if ($myBr === null && is_root_admin()) $myBr = 1;   // مدیر کل = شعبهٔ مرکزی
            $isProvider = ($myBr !== null && $plabBranch > 0 && $plabBranch === $myBr);
        }
        if ($isProvider) {
            $price = formatAmountToman(getInboundReceivableAmount($r));
        }
    }
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
        $myBr = currentBranchId();
        if ($myBr === null && is_root_admin()) $myBr = 1;   // مدیر کل = شعبهٔ مرکزی
        $ownerBranchName = !empty($r['branch_name']) ? $r['branch_name'] : '';

        $label = '';
        $partnerName = '';
        $isCross = ($caseSrcBranch !== 0 && $caseBranch !== 0 && $caseSrcBranch !== $caseBranch && $myBr !== null
                    && ($myBr === $caseBranch || $myBr === $caseSrcBranch));
        if ($isCross) {
            if ($myBr === $caseBranch) {
                // ما مالکیم و کار را به شعبهٔ دیگر داده‌ایم
                $label = 'برون‌سپاری به';
                $partnerName = $r['source_branch_name'] ?: ('شعبه #' . $caseSrcBranch);
            } else {
                // کار از شعبهٔ دیگر به ما رسیده — طرفِ مقابل = شعبهٔ مالکِ کیس
                $label = 'کار از لابراتوار';
                $partnerName = $ownerBranchName !== '' ? $ownerBranchName : ('شعبه #' . $caseBranch);
            }
        } elseif (!empty($r['lab_id']) && !empty($r['lab_name'])) {
            $ct = $r['case_type'] ?? '';
            $label = $ct === 'lab_in' ? 'کار از لابراتوار' : ($ct === 'lab_out' ? 'برون‌سپاری به' : '');
            $partnerName = $r['lab_name'];
        }

        $lines = [];
        if ($partnerName !== '') {
            $lines[] = '<div>' . ($label !== '' ? htmlspecialchars($label) . ': ' : '') . htmlspecialchars($partnerName) . '</div>';
        }
        // برون‌سپاری جانبی (فقط اگر با طرف بالا یکی نباشد)
        if (!empty($r['outsourced_lab_id']) && !empty($r['outsourced_lab_name']) && (int) $r['outsourced_lab_id'] !== (int) $r['lab_id']) {
            $lines[] = '<div>جانبی به: ' . htmlspecialchars($r['outsourced_lab_name'])
                . ((int) ($r['outsourced_qty'] ?? 0) > 0 ? ' (×' . toPersianDigits((int) $r['outsourced_qty']) . ')' : '') . '</div>';
        }
        $labHtml = $lines ? implode('', $lines) : '—';
    }

    // نمایش وضعیت: شماره‌ی مرحله + آیکون + متن (+ رنگ)
    $stM = $statusMeta[(int) ($r['status_id'] ?? 0)] ?? ['name' => ($r['status_name'] ?: 'نامشخص'), 'icon' => '', 'color' => '', 'num' => 0];
    $stStyle = $stM['color'] !== '' ? 'background:' . $stM['color'] . '; color:#fff;' : '';
    $statusHtml = '<span class="case-status-badge" title="' . htmlspecialchars($stM['name'], ENT_QUOTES, 'UTF-8') . '" style="display:inline-flex; flex-wrap:wrap; align-items:center; gap:4px; border-radius:6px; padding:2px 8px; font-weight:600; max-width:135px; overflow:hidden; max-height:2.9em; line-height:1.3; ' . $stStyle . '">'
        . ($stM['num'] > 0 ? '<span class="st-num">' . toPersianDigits((string) $stM['num']) . '</span>' : '')
        . ($stM['icon'] !== '' ? '<span class="st-icon">' . htmlspecialchars($stM['icon']) . '</span>' : '')
        . '<span class="st-text">' . htmlspecialchars($stM['name']) . '</span>'
        . '</span>';

    // سایه: سلول با رنگ زمینهٔ همان سایه
    $shadeCode = trim((string) ($r['shade'] ?? ''));
    $shadeHex = caseShadeColor($shadeCode);
    $shadeHtml = '—';
    if ($shadeCode !== '') {
        $shadeHtml = '<span data-shade-color="' . htmlspecialchars($shadeHex, ENT_QUOTES) . '">' . htmlspecialchars($shadeCode) . '</span>';
    }

    // نشانگر «کامنت/فایل جدید» از آخرین بازدید این کاربر از صفحهٔ کیس
    $hasUpdates = 0;
    $lv = $r['my_last_view'] ?? null;
    if (!empty($lv)) {
        if ((!empty($r['last_comment']) && $r['last_comment'] > $lv) || (!empty($r['last_file']) && $r['last_file'] > $lv)) {
            $hasUpdates = 1;
        }
    }

    $data[] = [
        $r['id'],
        $r['doctor_name'] ?: '—',
        $r['patient_name'] ?: '—',
        $r['service_title'] ?: '—',
        formatCaseLocation($r['location_type'], $r['teeth']),
        $shadeHtml,
        $price,
        $statusHtml,
        $received,
        $invoiceHtml,
        $labHtml,
        $actionDropdown,
        canSeeDesignerInfo() ? ($r['designer_name'] ?: '—') : '—',
        $r['file_count'] ?: 0,
        $r['label_printed_at'] ?? null,
        $r['status_name'] ?: '—',
        $r['receipt_number'] ?: '',
        $r['raw_downloaded'] ? 1 : 0,
        $r['design_downloaded'] ? 1 : 0,
        $hasUpdates,
        $r['service_short'] ?? ''
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data
], JSON_UNESCAPED_UNICODE);