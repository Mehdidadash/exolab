<?php
// panel/view_case.php
require_once __DIR__ . '/auth.php';
require_login();

// CSRF token for AJAX actions
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];
session_write_close();

$id = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
$case = null;
$files = [];
$user = current_user();
$isDoctor = ($user['role'] === 'doctor');
$isDesigner = ($user['role'] === 'designer');

if ($id) {
    $sql = 'SELECT c.*, u.full_name AS doctor_name, u.notes AS doctor_notes, p.title AS service_title, d.full_name AS designer_name,
                   cs.name AS status_name, lab.full_name AS lab_name,
                   olab.full_name AS outsourced_lab_name, os.title AS outsourced_service_title
            FROM cases c
            LEFT JOIN users u ON c.doctor_id = u.id
            LEFT JOIN site_prices p ON c.service_id = p.id
            LEFT JOIN users d ON c.designer_id = d.id
            LEFT JOIN case_statuses cs ON c.status_id = cs.id
            LEFT JOIN users lab ON c.lab_id = lab.id
            LEFT JOIN users olab ON c.outsourced_lab_id = olab.id
            LEFT JOIN site_prices os ON c.outsourced_service_id = os.id
            WHERE c.id = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    $case = $stmt->fetch() ?: null;

    // ---- کنترل دسترسی -------------------------------------------------------
    // ملاک، «رابطهٔ کاربر با کیس» است، نه فقط نامِ نقش: پزشکِ کیس، طراحِ کیس،
    // لابراتوارِ کیس، لابراتوارِ برون‌سپاری، پزشکانِ زیرمجموعهٔ کلینیک، و در نهایت
    // کارکنان با مجوزِ view_all_cases در محدودهٔ شعبه (منطق در userCanViewCase()).
    // بنابراین کاربری که هم‌زمان «لابراتوار برون‌سپاری» و «طراح» است، اگر هر کدام
    // از این دو رابطه برقرار باشد، اجازهٔ دیدن دارد.
    if ($case && !userCanViewCase((int) $id, $user, $case)) {
        die('دسترسی غیرمجاز — این کیس به حساب کاربری شما مرتبط نیست (کیس #' . (int) $id . ').');
    }

    if ($case) {
        $fstmt = db()->prepare('SELECT cf.*, u.full_name AS uploader_name FROM case_files cf LEFT JOIN users u ON u.id = cf.uploader_id WHERE cf.case_id = ? ORDER BY cf.id ASC');
        $fstmt->execute([$id]);
        $files = $fstmt->fetchAll();

        // Get sub-cases (children)
        $subStmt = db()->prepare(
            'SELECT c.*, p.title AS service_title, cs.name AS status_name
             FROM cases c
             LEFT JOIN site_prices p ON c.service_id = p.id
             LEFT JOIN case_statuses cs ON c.status_id = cs.id
             WHERE c.parent_id = ?
             ORDER BY c.id ASC'
        );
        $subStmt->execute([$id]);
        $subCases = $subStmt->fetchAll();

        // Get parent case if this is a sub-case
        $parentCase = null;
        if ($case['parent_id']) {
            $pStmt = db()->prepare(
                'SELECT c.*, p.title AS service_title
                 FROM cases c
                 LEFT JOIN site_prices p ON c.service_id = p.id
                 WHERE c.id = ?'
            );
            $pStmt->execute([$case['parent_id']]);
            $parentCase = $pStmt->fetch();
        }

        // لاگ مشاهده صفحه‌ی مشاهده کیس (چه کسی و چه زمانی)
        log_case_activity((int) $case['id'], 'view', 'مشاهده صفحه کیس');
    }
}

$statuses = getAllCaseStatuses();
$designers = getAllDesigners();
// طراح پیش‌فرض (برای کیس‌هایی که خدمتشان طراحی لازم دارد)
$defaultDesignerForForm = getDefaultDesigner();
$defaultDesignerId = $defaultDesignerForForm ? (int) $defaultDesignerForForm['id'] : 0;
// طراحِ همین کیس ممکن است غیرفعال یا از شعبه‌ای دیگر باشد و در لیستِ فرم نباشد؛
// در آن صورت گزینه‌اش را اضافه می‌کنیم تا هنگام ویرایش، طراحِ کیس از دست نرود.
$caseDesignerId = (int) ($case['designer_id'] ?? 0);
$caseDesignerInList = false;
foreach ($designers as $d) {
    if ((int) $d['id'] === $caseDesignerId) { $caseDesignerInList = true; break; }
}
if ($caseDesignerId && !$caseDesignerInList) {
    $ddStmt = db()->prepare('SELECT id, full_name, is_default_designer FROM users WHERE id = ? LIMIT 1');
    $ddStmt->execute([$caseDesignerId]);
    $ddRow = $ddStmt->fetch();
    if ($ddRow) { array_unshift($designers, $ddRow); }
}
$labs = getAllLabs();
// لیست لابراتوارهای «مقصدِ برون‌سپاری» بدون لابراتوارِ خودِ شعبه (مقصد برای کیسِ فعلی)
$editLabs    = getOutsourceLabOptions((int) ($case['lab_id'] ?? 0));
$editOutLabs = getOutsourceLabOptions((int) ($case['outsourced_lab_id'] ?? 0));
$doctors = getAllDoctors();
// پزشکِ همین کیس ممکن است در لیستِ فیلترشدهٔ شعبه نباشد (کیس‌های بین‌شعبه‌ای).
// در آن صورت گزینه‌اش را به لیست اضافه می‌کنیم تا در فرم ویرایش انتخابی وجود داشته باشد
// و doctor_id خالی/نامعتبر ارسال نشود.
$caseDoctorId = (int) ($case['doctor_id'] ?? 0);
$caseDoctorInList = false;
foreach ($doctors as $d) {
    if ((int) $d['id'] === $caseDoctorId) { $caseDoctorInList = true; break; }
}
if ($caseDoctorId && !$caseDoctorInList) {
    $dStmt = db()->prepare('SELECT id, full_name AS name FROM users WHERE id = ? LIMIT 1');
    $dStmt->execute([$caseDoctorId]);
    $dRow = $dStmt->fetch();
    if ($dRow) { array_unshift($doctors, $dRow); }
}
$prices = getAllPrices();
// Whether the current user may edit this case (admin / staff / secretary …)
$canEditCase = has_role('admin') || has_role('branch_admin') || has_permission('edit_cases');
// چه کسی می‌تواند متن کامنت/توضیح فایل را به یادداشت پزشک اضافه کند
// (طراح = نقشِ designer یا کاربری که تیکِ «طراح» دارد)
$canAppendNote = is_admin() || is_designer_user($user) || in_array($user['role'] ?? '', ['technician'], true);
// آیا کاربر می‌تواند فایل‌ها را بین کیس‌ها وصل/جدا کند؟
// (هم‌ارزِ گیتِ permission در link_case_file_to_case.php و unlink_case_file_from_case.php)
$canLinkFiles = is_admin()
    || has_permission('upload_files') || has_permission('upload_design_files') || has_permission('edit_cases')
    || is_designer_user($user) || in_array($user['role'] ?? '', ['doctor'], true);

panel_layout_start('مشاهده کیس');
?>
<div class="form-card">
    <?php if (!$case): ?>
        <p>کیسی یافت نشد.</p>
    <?php else: ?>
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
            <h3 style="margin:0;">کیس #<?= htmlspecialchars($case['id']) ?> - <?= htmlspecialchars($case['patient_name']) ?><?= !empty($case['receipt_number']) ? ' / ' . htmlspecialchars($case['receipt_number']) : '' ?></h3>
            <?php if ($canEditCase): ?>
                <a href="#" id="edit-case-btn" class="btn" style="background:#06B6D4; color:#fff;">✏️ ویرایش کیس</a>
            <?php endif; ?>
        </div>

        <?php
        $isRestricted = in_array($user['role'] ?? '', ['doctor', 'clinic']);
        $isDesigner = ($user['role'] === 'designer');
        $hideFinancial = $isRestricted || $isDesigner;

        // Whether the viewer can open this case's doctor profile (designers need it to see the gallery)
        $canViewDoctorProfile = false;
        if (!empty($case['doctor_id'])) {
            $did = (int) $case['doctor_id'];
            $canViewDoctorProfile = has_role('admin')
                || (is_designer_user($user) && designerCanAccessUser($did))
                || (has_role('doctor') && $did === (int) $user['id'])
                || (has_role('clinic') && canAccessDoctor($did));
        }
        ?>

        <?php
        $typeLabels = [
            'doctor' => 'کیس دکتر',
            'lab_in' => 'کار از لابراتوار همکار',
            'lab_out' => 'برونسپاری به لابراتوار',
        ];
        $caseTypeLabel = $typeLabels[$case['case_type'] ?? 'doctor'] ?? 'کیس دکتر';

        // Cross-branch perspective: when a branch user views a case, show whether
        // it is work received from a partner branch ("کار از لابراتوار همکار")
        // or work we outsourced to a partner branch ("برون‌سپاری").
        $myBranchId = currentBranchId();
        if ($myBranchId === null && is_root_admin()) $myBranchId = 1;   // مدیر کل = شعبهٔ مرکزی
        $caseBranchId   = !empty($case['branch_id']) ? (int) $case['branch_id'] : 0;
        $caseSrcBranchId = !empty($case['source_branch_id']) ? (int) $case['source_branch_id'] : 0;
        $inboundPartner = $myBranchId !== null && $caseSrcBranchId === $myBranchId && $caseBranchId !== $myBranchId;
        $outboundPartner = $myBranchId !== null && $caseBranchId === $myBranchId && $caseSrcBranchId !== 0 && $caseSrcBranchId !== $myBranchId;
        $isLabInCross = ($case['case_type'] ?? '') === 'lab_in' && $caseSrcBranchId !== 0 && $caseSrcBranchId !== $caseBranchId;

        $branchLabel = '';
        $branchBadge = '';
        if ($inboundPartner) {
            $branchBadge = '<span class="badge" style="background:#dcfce7; color:#166534;">کار از لابراتوار همکار</span>';
            $branchLabel = 'شعبه/لابراتوار ارسال‌کننده';
        } elseif ($outboundPartner) {
            $branchBadge = '<span class="badge" style="background:#fef3c7; color:#92400e;">برون‌سپاری به همکار</span>';
            $branchLabel = 'شعبه/لابراتوار گیرنده';
        } elseif ($isLabInCross) {
            $branchBadge = '<span class="badge" style="background:#dcfce7; color:#166534;">کار از لابراتوار همکار</span>';
            $branchLabel = 'شعبه/لابراتوار مبدا';
        }
        // Fetch the partner branch name for display (طرفِ مقابل = شعبهٔ مالکِ کیس برای گیرنده، وگرنه شعبهٔ مقصد)
        $partnerBranchName = '';
        if ($inboundPartner || $isLabInCross || $outboundPartner) {
            $partnerBranchId = $inboundPartner ? $caseBranchId : $caseSrcBranchId;
            if ($partnerBranchId > 0) {
                $pb = db()->prepare('SELECT name FROM branches WHERE id = ?');
                $pb->execute([$partnerBranchId]);
                $partnerBranchName = (string) $pb->fetchColumn();
            }
        }

        // پزشک‌ها همیشه کیس خود را «کیس دکتر» می‌بینند — جزئیات برون‌سپاری/همکار برایشان
        // نمایش داده نمی‌شود (نه عنوان نوع کیس، نه برچسب و نه نام شعبهٔ همکار).
        if ($isDoctor) {
            $caseTypeLabel = 'کیس دکتر';
            $branchBadge = '';
            $branchLabel = '';
            $partnerBranchName = '';
        }
        ?>

        <?php
        // اگر بیننده «انجام‌دهنده/گیرندهٔ کارِ برون‌سپاری» باشد، به‌جای قیمتِ خرده‌فروشیِ
        // شعبهٔ مالک، مبلغِ خودش (نرخ برون‌سپاری × تعداد) نمایش داده شود.
        // مثال: لابراتوار مرکزی روی کیسِ lab_outِ قزوین → ۵٬۰۰۰٬۰۰۰ (نه ۹٬۵۰۰٬۰۰۰).
        $viewerProviderFee = null;
        $provLabId = 0;
        $cTypeP = $case['case_type'] ?? '';
        if ($cTypeP === 'lab_out') {
            $provLabId = (int) ($case['lab_id'] ?? 0);
        } elseif (!empty($case['outsourced_lab_id']) && (int) ($case['outsourced_qty'] ?? 0) > 0) {
            $provLabId = (int) $case['outsourced_lab_id'];
        }
        if ($provLabId > 0) {
            $isCaseProvider = in_array($user['role'] ?? '', ['lab', 'outsource_lab', 'customer_lab', 'partner_lab'], true)
                && $provLabId === (int) $user['id'];
            if (!$isCaseProvider) {
                $plb = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
                $plb->execute([$provLabId]);
                $plbId = (int) $plb->fetchColumn();
                $mbx = currentBranchId();
                if ($mbx === null && is_root_admin()) $mbx = 1;   // مدیر کل = شعبهٔ مرکزی
                $isCaseProvider = ($mbx !== null && $plbId > 0 && $plbId === $mbx);
            }
            if ($isCaseProvider) {
                $viewerProviderFee = getInboundReceivableAmount($case);
            }
        }
        ?>

        <?php
        // ─── اطلاعات کیس: هر آیتم یک جفتِ «برچسب/مقدار» است. روی مانیتورهای بزرگ در
        // گریدِ ۴ستونه (= ۸ ستونِ برچسب/مقدار) چیده می‌شود و روی موبایل به یک ستون
        // می‌رسد؛ آیتم‌های بلند (دندان‌ها، توضیحات، مبالغ بین‌شعبه‌ای) تمام‌عرض‌اند.
        $infoItems = [];

        $infoItems[] = [
            'label' => 'نوع کیس',
            'value' => htmlspecialchars($caseTypeLabel) . ($branchBadge ? ' ' . $branchBadge : ''),
        ];
        $infoItems[] = [
            'label' => 'پزشک',
            'value' => $canViewDoctorProfile
                ? '<a href="doctor_view.php?id=' . (int) $case['doctor_id'] . '">' . htmlspecialchars($case['doctor_name'] ?? '—') . '</a>'
                : htmlspecialchars($case['doctor_name'] ?? '—'),
        ];

        if ($branchBadge && $partnerBranchName) {
            $infoItems[] = ['label' => $branchLabel, 'value' => htmlspecialchars($partnerBranchName), 'wide' => true, 'style' => 'background:#f8fafc;'];
        }

        if (!$hideFinancial && ($inboundPartner || $outboundPartner)) {
            $crossAmt = getInboundReceivableAmount($case);
            $crossNote = $inboundPartner
                ? 'این مبلغ همان هزینه برون‌سپاری است که شعبه مبدا برای این کیس به ما پرداخت می‌کند.'
                : 'این مبلغ همان هزینه برون‌سپاری است که ما برای این کیس به شعبه گیرنده می‌پردازیم.';
            $infoItems[] = [
                'label' => $inboundPartner ? 'طلب ما از شعبه مبدا' : 'بدهی ما به شعبه گیرنده',
                'value' => formatAmountToman($crossAmt) . ' تومان'
                    . '<small style="display:block; font-weight:400; color:#525252; margin-top:2px;">' . $crossNote . '</small>',
                'wide' => true,
                'style' => 'background:' . ($inboundPartner ? '#f0fdf4' : '#fffbeb') . ';',
                'valueStyle' => 'color:' . ($inboundPartner ? '#166534' : '#92400e') . '; font-weight:700;',
            ];
        }

        $infoItems[] = ['label' => 'شماره قبض', 'value' => htmlspecialchars($case['receipt_number'] ?? '—')];
        $infoItems[] = ['label' => 'خدمت', 'value' => htmlspecialchars($case['service_title'] ?? '—')];
        $infoItems[] = ['label' => 'تاریخ دریافت', 'value' => htmlspecialchars(toJalaliDateFormatted($case['received_date']))];
        $infoItems[] = ['label' => 'مکان / دندان', 'value' => htmlspecialchars(formatCaseLocation($case['location_type'], $case['teeth']))];

        if (($case['location_type'] ?? '') === 'teeth' && !empty($case['teeth'])) {
            $infoItems[] = [
                'label' => 'دندان‌های انتخاب‌شده',
                'value' => '<div style="margin-top:8px;">' . renderTeethChart($case['teeth']) . '</div>',
                'wide' => true,
                'stack' => true,
            ];
        }

        $shCode = trim((string) ($case['shade'] ?? ''));
        $shHex  = caseShadeColor($shCode);
        $infoItems[] = [
            'label' => 'سایه',
            'value' => $shCode === '' ? '—' : htmlspecialchars($shCode),
            'style' => $shHex !== '' ? 'background:' . $shHex . ';' : '',
            'valueStyle' => $shHex !== '' ? 'font-weight:700; color:#1f2937;' : '',
        ];
        $infoItems[] = ['label' => 'تعداد', 'value' => toPersianDigits((int) ($case['quantity'] ?? 1))];
        $infoItems[] = ['label' => 'وضعیت', 'value' => '<span class="badge">' . htmlspecialchars($case['status_name'] ?? '—') . '</span>'];

        if (!$isRestricted && !$isDesigner) {
            $labInfoLabel = ($case['case_type'] ?? '') === 'lab_in' ? 'کار از لابراتوار'
                : ((($case['case_type'] ?? '') === 'lab_out') ? 'برون‌سپاری به لابراتوار' : 'لابراتوار');
            $infoItems[] = ['label' => $labInfoLabel, 'value' => htmlspecialchars($case['lab_name'] ?? '—')];
        }
        if (canSeeDesignerInfo()) {
            $infoItems[] = ['label' => 'طراح', 'value' => htmlspecialchars($case['designer_name'] ?? '—')];
        }
        if (!$isRestricted && !empty($case['outsourced_lab_name']) && !empty($case['outsourced_qty'])) {
            $outsourcedTxt = htmlspecialchars($case['outsourced_lab_name'])
                . ' — ' . htmlspecialchars($case['outsourced_service_title'] ?? 'خدمت')
                . ' (تعداد: ' . toPersianDigits((int) $case['outsourced_qty']) . ')';
            if (isset($case['outsourced_rate']) && $case['outsourced_rate'] !== null) {
                $outsourcedTxt .= ' — نرخ: ' . toPersianDigits(number_format((float) $case['outsourced_rate'])) . ' تومان';
            }
            $infoItems[] = ['label' => 'برون‌سپاری جانبی', 'value' => $outsourcedTxt, 'wide' => true, 'style' => 'background:#f0fdf4;'];
        }
        if (!$hideFinancial) {
            if ($viewerProviderFee !== null) {
                $infoItems[] = [
                    'label' => 'سهم لابراتوار (برون‌سپاری)',
                    'value' => formatAmountToman($viewerProviderFee) . ' تومان'
                        . '<small style="display:block; font-weight:400; color:#525252; margin-top:2px;">مبلغ قابل دریافت بابت انجام این کیس (نرخ برون‌سپاری × تعداد).</small>',
                    'wide' => true,
                    'style' => 'background:#f0fdf4;',
                    'valueStyle' => 'color:#166534; font-weight:700;',
                ];
            } else {
                $infoItems[] = ['label' => 'فی (تومان)', 'value' => $case['unit_price'] ? formatAmountToman($case['unit_price']) : '—'];
                $infoItems[] = ['label' => 'هزینه طراحی', 'value' => !empty($case['design_fee']) ? formatAmountToman($case['design_fee']) : '—'];
                $infoItems[] = ['label' => 'جمع کل', 'value' => $case['total_price'] ? formatAmountToman($case['total_price']) : '—', 'valueStyle' => 'font-weight:700;'];
            }
        }
        if (!empty($case['doctor_notes'])) {
            $infoItems[] = [
                'label' => 'توضیحات پزشک',
                'value' => '<div style="white-space:pre-wrap; direction:rtl; text-align:right; unicode-bidi:plaintext; line-height:1.9;">' . nl2br(htmlspecialchars($case['doctor_notes'])) . '</div>',
                'wide' => true,
            ];
        }
        if (!empty($case['description'])) {
            $infoItems[] = [
                'label' => 'توضیحات',
                'value' => '<div style="white-space:pre-wrap; line-height:1.9;">' . nl2br(htmlspecialchars($case['description'])) . '</div>',
                'wide' => true,
            ];
        }
        ?>
        <style>
            /* اطلاعات کیس: ۴ جفت (۸ ستون) روی مانیتور بزرگ، ۳/۲/۱ ستون روی صفحه‌های کوچک‌تر */
            .case-info-grid{ display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#fff; margin:12px 0; }
            .case-info-grid .ci-item{ display:flex; align-items:flex-start; gap:6px; padding:8px 10px; min-width:0; border-bottom:1px solid #eef2f7; border-left:1px solid #eef2f7; }
            .case-info-grid .ci-item.ci-wide{ grid-column:1 / -1; }
            .case-info-grid .ci-item.ci-stack{ flex-direction:column; gap:2px; }
            .case-info-grid .ci-label{ color:#475569; font-weight:700; white-space:nowrap; }
            .case-info-grid .ci-value{ min-width:0; overflow-wrap:anywhere; }
            @media (max-width:1300px){ .case-info-grid{ grid-template-columns:repeat(3, minmax(0, 1fr)); } }
            @media (max-width:1000px){ .case-info-grid{ grid-template-columns:repeat(2, minmax(0, 1fr)); } }
            @media (max-width:560px){ .case-info-grid{ grid-template-columns:1fr; } }
        </style>
        <div class="case-info-grid">
            <?php foreach ($infoItems as $it): ?>
                <div class="ci-item<?= !empty($it['wide']) ? ' ci-wide' : '' ?><?= !empty($it['stack']) ? ' ci-stack' : '' ?>"<?= !empty($it['style']) ? ' style="' . htmlspecialchars($it['style'], ENT_QUOTES) . '"' : '' ?>>
                    <span class="ci-label"><?= htmlspecialchars($it['label']) ?>:</span>
                    <span class="ci-value"<?= !empty($it['valueStyle']) ? ' style="' . htmlspecialchars($it['valueStyle'], ENT_QUOTES) . '"' : '' ?>><?= $it['value'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php
        $allowedStatusIds = getAllowedStatusIdsForUser();
        $canChangeStatus = has_role('admin') || has_permission('edit_case_status') || has_permission('update_case_status');
        $canChangeDesigner = canSeeDesignerInfo() && (has_role('admin') || has_permission('edit_cases'));
        ?>
        <?php if ($canChangeStatus || $canChangeDesigner): ?>
        <style>
            /* تغییر وضعیت و تغییر طراح در یک ردیف؛ فیلد و دکمه کنار هم می‌مانند */
            .vc-actions-row{ display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:20px; }
            .vc-actions-row .form-card{ margin-top:0 !important; }
            .vc-actions-row h4{ margin:0 0 6px; }
            .vc-action-form{ display:flex; gap:8px; align-items:center; flex-wrap:nowrap; margin-top:8px; }
            .vc-action-form select{ flex:1 1 auto; min-width:0; }
            .vc-action-form .btn{ flex:0 0 auto; white-space:nowrap; }
            @media (max-width:900px){ .vc-actions-row{ grid-template-columns:1fr; } }
        </style>
        <div class="vc-actions-row">
            <?php if ($canChangeStatus): ?>
            <div class="form-card">
                <h4>تغییر وضعیت کیس</h4>
                <form id="case-status-form" class="vc-action-form">
                <input type="hidden" name="case_id" value="<?= (int) $case['id'] ?>">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <select id="case-status-change" name="status_id">
                    <option value="">انتخاب وضعیت...</option>
                    <?php foreach ($statuses as $s):
                        if (!empty($allowedStatusIds) && !in_array((int)$s['id'], $allowedStatusIds, true)) continue;
                    ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int)($case['status_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ثبت وضعیت</button>
            </form>
            <div id="case-status-msg" style="margin-top:8px; font-weight:bold;"></div>
        </div>
        <script>
        (function(){
            var form = document.getElementById('case-status-form');
            if (!form) return;
            var csrf = '<?= htmlspecialchars($csrf_token) ?>';
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var msgEl = document.getElementById('case-status-msg');
                var sel = document.getElementById('case-status-change');
                var statusId = sel ? sel.value : '';
                if (!statusId) { if (msgEl) { msgEl.textContent = 'لطفاً یک وضعیت انتخاب کنید.'; msgEl.style.color = '#b91c1c'; } return; }
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'update_case_status.php', true);
                xhr.setRequestHeader('X-CSRF-Token', csrf);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function(){
                    var resp = null;
                    try { resp = JSON.parse(xhr.responseText); } catch(e){}
                    if (xhr.status === 200 && resp && resp.success) {
                        if (msgEl) { msgEl.textContent = 'وضعیت کیس با موفقیت تغییر کرد.'; msgEl.style.color = '#166534'; }
                        var badge = document.querySelector('.badge');
                        if (badge && sel && sel.selectedIndex >= 0) {
                            var opt = sel.options[sel.selectedIndex];
                            if (opt) badge.textContent = opt.text;
                        }
                    } else {
                        if (msgEl) { msgEl.textContent = (resp && resp.message) ? resp.message : 'خطا در تغییر وضعیت.'; msgEl.style.color = '#b91c1c'; }
                    }
                };
                xhr.onerror = function(){ if (msgEl) { msgEl.textContent = 'خطا در اتصال به سرور.'; msgEl.style.color = '#b91c1c'; } };
                xhr.send('case_id=' + encodeURIComponent(form.case_id.value) + '&status_id=' + encodeURIComponent(statusId) + '&_csrf_token=' + encodeURIComponent(csrf));
            });
        })();
        </script>
        <?php endif; ?>

            <?php if ($canChangeDesigner): ?>
            <div class="form-card">
                <h4>تغییر طراح کیس</h4>
            <?php if (!empty($case['designer_invoice_id'])): ?>
                <p style="color:#b91c1c;">این کیس قبلاً در فاکتور طراحی ثبت شده و نمی‌توان طراح آن را تغییر داد.</p>
            <?php else: ?>
            <form id="case-change-designer-form" class="vc-action-form">
                <input type="hidden" name="case_id" value="<?= (int) $case['id'] ?>">
                <select id="case-change-designer-select">
                    <option value="">بدون طراح (حذف طراح)</option>
                    <?php foreach ($designers as $des): ?>
                        <option value="<?= $des['id'] ?>" <?= (int)($case['designer_id'] ?? 0) === (int) $des['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($des['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn" style="background:#d97706; color:#fff;">تغییر طراح</button>
            </form>
            <div id="case-change-designer-msg" style="margin-top:8px; font-weight:bold;"></div>
            <small style="display:block; margin-top:6px; color:#525252;">هزینه طراحی و جمع کل بر اساس نرخ طراح جدید به‌صورت خودکار محاسبه می‌شود.</small>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            var form = document.getElementById('case-change-designer-form');
            if (!form) return;
            var csrf = '<?= htmlspecialchars($csrf_token) ?>';
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var msgEl = document.getElementById('case-change-designer-msg');
                var sel = document.getElementById('case-change-designer-select');
                var designerId = sel ? sel.value : '';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'batch_update_designer.php', true);
                xhr.setRequestHeader('X-CSRF-Token', csrf);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function(){
                    var resp = null;
                    try { resp = JSON.parse(xhr.responseText); } catch(e){}
                    if (xhr.status === 200 && resp && resp.success) {
                        if (msgEl) { msgEl.textContent = 'طراح کیس با موفقیت تغییر کرد.'; msgEl.style.color = '#166534'; }
                        setTimeout(function(){ location.reload(); }, 600);
                    } else {
                        var errMsg = 'خطا در تغییر طراح.';
                        if (resp && resp.errors && resp.errors.length) errMsg = resp.errors.join('، ');
                        else if (resp && resp.error) errMsg = 'خطا: ' + resp.error;
                        if (msgEl) { msgEl.textContent = errMsg; msgEl.style.color = '#b91c1c'; }
                    }
                };
                xhr.onerror = function(){ if (msgEl) { msgEl.textContent = 'خطا در اتصال به سرور.'; msgEl.style.color = '#b91c1c'; } };
                xhr.send('case_ids[]=' + encodeURIComponent(form.case_id.value) + '&designer_id=' + encodeURIComponent(designerId) + '&_csrf_token=' + encodeURIComponent(csrf));
            });
        })();
        </script>
        <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($parentCase): ?>
            <p><strong>کیس اصلی:</strong> <a href="view_case.php?id=<?= $parentCase['id'] ?>">#<?= $parentCase['id'] ?> - <?= htmlspecialchars($parentCase['patient_name']) ?> (<?= htmlspecialchars($parentCase['service_title']) ?>)</a></p>
        <?php endif; ?>

        <?php
        // ── نوبت‌های اسکن این کیس (نوبت‌دهی اسکن) ──
        $caseAppts      = getCaseScanAppointments((int) $case['id']);
        $saTypes        = scanAppointmentTypes();
        $saStatuses     = scanAppointmentStatuses();
        $canManageAppts = canManageScanAppointments();
        ?>
        <div class="form-card" style="margin-top:20px;">
            <h4 style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 8px;">
                <span>🗓 نوبت‌های اسکن این کیس</span>
                <span style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <?php if (count($caseAppts)): ?>
                        <span class="badge" style="background:#e0f2fe; color:#0369a1;"><?= toPersianDigits((string) count($caseAppts)) ?> نوبت</span>
                    <?php endif; ?>
                    <?php if ($canManageAppts): ?>
                        <a class="btn" href="scan_appointments.php?case_id=<?= (int) $case['id'] ?>&new=1" style="background:#0F172A; color:#fff; padding:5px 12px;">➕ ثبت نوبت اسکن</a>
                    <?php endif; ?>
                    <a class="btn" href="scan_appointments.php" style="background:#E5E7EB; color:#0F172A; padding:5px 12px;">تقویم نوبت‌ها</a>
                </span>
            </h4>
            <?php if (empty($caseAppts)): ?>
                <p class="empty" style="margin:0;">برای این کیس نوبتی ثبت نشده است.</p>
            <?php else: ?>
                <div style="display:grid; gap:8px;">
                    <?php foreach ($caseAppts as $ap):
                        $tMeta = $saTypes[(string) $ap['appt_type']] ?? ['label' => '—', 'icon' => '📌', 'color' => '#64748b'];
                        $sMeta = $saStatuses[(string) $ap['status']] ?? ['label' => '—', 'color' => '#64748b'];
                    ?>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:8px 10px;">
                            <span style="background:<?= htmlspecialchars($tMeta['color']) ?>; color:#fff; border-radius:6px; padding:2px 8px; font-size:.78rem;"><?= $tMeta['icon'] ?> <?= htmlspecialchars($tMeta['label']) ?></span>
                            <strong><?= toJalaliDateFormatted((string) $ap['appt_date']) ?></strong>
                            <span><?= toPersianDigits(substr((string) $ap['start_time'], 0, 5)) ?><?= !empty($ap['end_time']) ? ' تا ' . toPersianDigits(substr((string) $ap['end_time'], 0, 5)) : '' ?></span>
                            <?php if (!empty($ap['needs_scan_body'])): ?>
                                <span style="background:#fef3c7; color:#92400e; border-radius:6px; padding:2px 8px; font-size:.78rem; font-weight:700;">🧩 اسکن‌بادی</span>
                            <?php endif; ?>
                            <span style="background:<?= htmlspecialchars($sMeta['color']) ?>; color:#fff; border-radius:6px; padding:2px 8px; font-size:.78rem;"><?= htmlspecialchars($sMeta['label']) ?></span>
                            <?php if (!empty($ap['doctor_name'])): ?><span style="color:#475569; font-size:.85rem;">👨‍⚕️ <?= htmlspecialchars((string) $ap['doctor_name']) ?></span><?php endif; ?>
                            <?php if (!empty($ap['address'])): ?><span style="color:#64748b; font-size:.82rem;">📍 <?= htmlspecialchars((string) $ap['address']) ?></span><?php endif; ?>
                            <?php if (!empty($ap['notes'])): ?><span style="color:#64748b; font-size:.82rem; white-space:pre-wrap;">📝 <?= htmlspecialchars((string) $ap['notes']) ?></span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-card" style="margin-top:20px;">
            <h4>پیام‌ها و کامنت‌ها</h4>
            <form method="post" action="save_comment.php" style="margin-bottom:16px;">
                <?= csrf_field() ?>
                <input type="hidden" name="entity_type" value="case">
                <input type="hidden" name="entity_id" value="<?= (int) $case['id'] ?>">
                <textarea name="message" rows="3" placeholder="پیام یا یادداشت برای این کیس..." required style="width:100%;"></textarea>
                <button type="submit" class="btn" style="margin-top:8px; background:#06B6D4; color:#fff;">ارسال پیام</button>
            </form>

            <?php $caseComments = getEntityComments('case', $case['id']); ?>
            <style>
                /* پیام‌ها و کامنت‌ها: دو ستونه در دسکتاپ، یک ستونه در موبایل */
                .vc-comments-grid{ display:grid; gap:10px; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); align-items:start; }
                @media (max-width:700px){ .vc-comments-grid{ grid-template-columns:1fr; } }
                .vc-comment{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px; }
            </style>
            <?php if (!empty($caseComments)): ?>
                <div class="vc-comments-grid">
                    <?php foreach ($caseComments as $comment):
                        $likeData = getCommentLikeData((int) $comment['id'], (int) $user['id']);
                        $canDelete = ((int) $comment['user_id'] === (int) $user['id'] || has_role('admin'));
                        ?>
                        <div class="vc-comment">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                                <strong><?= htmlspecialchars($comment['user_name'] ?? 'کاربر') ?></strong>
                                <span style="font-size:0.8rem; color:#6b7280;"><?= toJalaliDateTimeFormatted($comment['created_at']) ?></span>
                            </div>
                            <div style="white-space:pre-wrap; line-height:1.8;"><?= htmlspecialchars($comment['message']) ?></div>
                            <div style="margin-top:8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <button type="button" class="btn js-comment-like"
                                        data-comment="<?= (int) $comment['id'] ?>"
                                        data-liked="<?= $likeData['liked'] ? '1' : '0' ?>"
                                        style="background:<?= $likeData['liked'] ? '#fee2e2' : '#f3f4f6' ?>; color:<?= $likeData['liked'] ? '#b91c1c' : '#374151' ?>; padding:3px 10px; font-size:0.82rem;"
                                        title="<?= $likeData['names'] ? htmlspecialchars(implode('، ', $likeData['names'])) : 'هنوز کسی لایک نکرده' ?>">
                                    ❤️ <span class="like-count"><?= toPersianDigits((string) $likeData['count']) ?></span>
                                </button>
                                <span class="like-names" style="font-size:0.78rem; color:#6b7280;"><?= $likeData['names'] ? '❤️ ' . htmlspecialchars(implode('، ', $likeData['names'])) : '' ?></span>
                                <?php if ($canDelete): ?>
                                    <form method="post" action="save_comment.php" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="comment_id" value="<?= (int) $comment['id'] ?>">
                                        <button type="submit" class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px; font-size:0.8rem;">حذف</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canAppendNote): ?>
                                    <button type="button" class="btn js-append-note" data-text="<?= htmlspecialchars($comment['message']) ?>" style="background:#eef2ff; color:#3730a3; padding:3px 10px; font-size:0.8rem;" title="افزودن این کامنت به یادداشت پزشک">➕ به یادداشت پزشک</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <script>
                (function(){
                    var csrf = '<?= htmlspecialchars($csrf_token) ?>';
                    document.addEventListener('click', function(e){
                        var btn = e.target.closest && e.target.closest('.js-comment-like');
                        if (!btn) return;
                        e.preventDefault();
                        var id = btn.getAttribute('data-comment');
                        var fd = new FormData();
                        fd.append('comment_id', id);
                        fd.append('_csrf_token', csrf);
                        fetch('toggle_comment_like.php', { method:'POST', headers:{ 'X-CSRF-Token': csrf }, body: fd })
                            .then(function(r){ return r.json().catch(function(){ return {success:false}; }); })
                            .then(function(res){
                                if (!res || !res.success) return;
                                var cnt = btn.querySelector('.like-count');
                                if (cnt) cnt.textContent = toPersianDigitsJs(res.count);
                                btn.setAttribute('data-liked', res.liked ? '1' : '0');
                                btn.style.background = res.liked ? '#fee2e2' : '#f3f4f6';
                                btn.style.color = res.liked ? '#b91c1c' : '#374151';
                                btn.title = (res.names && res.names.length) ? res.names.join('، ') : 'هنوز کسی لایک نکرده';
                                var nm = btn.parentNode ? btn.parentNode.querySelector('.like-names') : null;
                                if (nm) nm.textContent = (res.names && res.names.length) ? ('❤️ ' + res.names.join('، ')) : '';
                            });
                    });
                    function toPersianDigitsJs(n){
                        return String(n).replace(/[0-9]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; });
                    }
                })();
                </script>
            <?php else: ?>
                <p class="empty">هنوز پیامی ثبت نشده است.</p>
            <?php endif; ?>
        </div>

        <script>
        (function(){
            var csrf = '<?= htmlspecialchars($csrf_token) ?>';
            document.addEventListener('click', function(e){
                var b = e.target.closest && e.target.closest('.js-append-note');
                if (!b) return;
                e.preventDefault();
                var text = b.getAttribute('data-text') || '';
                if (!text) { alert('متنی برای افزودن وجود ندارد.'); return; }
                if (!confirm('این متن به یادداشت پزشک این کیس اضافه شود؟')) return;
                var fd = new FormData();
                fd.append('case_id', '<?= (int) $case['id'] ?>');
                fd.append('text', text);
                fd.append('_csrf_token', csrf);
                fetch('append_doctor_note.php', { method:'POST', headers:{ 'X-CSRF-Token': csrf }, body: fd })
                    .then(function(r){ return r.json().catch(function(){ return {success:false}; }); })
                    .then(function(res){ alert(res && res.success ? '✅ به یادداشت پزشک اضافه شد.' : '❌ خطا در افزودن.'); })
                    .catch(function(){ alert('❌ خطا در ارتباط با سرور.'); });
            });
        })();
        </script>

        <div class="form-card" style="margin-top:20px;">
            <details id="case-activity-history" style="--bg:#fff;">
                <summary style="cursor:pointer; list-style:none; outline:none; font-size:1.05rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <span id="activity-caret" style="transition:transform .2s; display:inline-block;">▶</span>
                    تاریخچه فعالیت‌های کیس
                    <?php $activityLog = getCaseActivityLog($case['id']); ?>
                    <?php if (!empty($activityLog)): ?>
                        <span class="badge" style="background:#e0e7ff; color:#3730a3;"><?= count($activityLog) ?></span>
                    <?php endif; ?>
                </summary>
                <div style="margin-top:10px;">
                    <?php if (!empty($activityLog)): ?>
                        <div style="max-height:340px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; padding-left:4px;">
                            <?php
                            $actLabels = [
                                'create'        => ['ایجاد کیس', '#dcfce7', '#166534'],
                                'update'        => ['ویرایش کیس', '#fef9c3', '#854d0e'],
                                'file_upload'   => ['آپلود فایل', '#cffafe', '#155e75'],
                                'file_download' => ['دانلود فایل', '#ede9fe', '#5b21b6'],
                                'view'          => ['مشاهده صفحه', '#f3f4f6', '#374151'],
                                'comment'       => ['کامنت', '#ffe4e6', '#9f1239'],
                                'status_change' => ['تغییر وضعیت', '#e0e7ff', '#3730a3'],
                            ];
                            foreach ($activityLog as $log):
                                $act = $actLabels[$log['action']] ?? [$log['action'], '#f3f4f6', '#374151'];
                            ?>
                                <div style="display:flex; align-items:center; gap:8px; font-size:0.85rem; padding:6px 8px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; flex-wrap:wrap;">
                                    <span class="badge" style="background:<?= $act[1] ?>; color:<?= $act[2] ?>;"><?= htmlspecialchars($act[0]) ?></span>
                                    <span style="flex:1; min-width:120px;"><?= htmlspecialchars($log['user_name'] ?? 'سیستم') ?></span>
                                    <span style="color:#6b7280; font-size:0.78rem;">🕒 <?= toJalaliDateTimeFormatted($log['created_at']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty">هنوز فعالیتی برای این کیس ثبت نشده است.</p>
                    <?php endif; ?>
                </div>
            </details>
        </div>
        <script>
        (function(){
            var d = document.getElementById('case-activity-history');
            if (!d) return;
            d.addEventListener('toggle', function(){
                var c = document.getElementById('activity-caret');
                if (c) c.style.transform = d.open ? 'rotate(90deg)' : '';
            });
        })();
        </script>

        <?php if (!empty($subCases)): ?>
            <h4>کیس‌های وابسته (زیرمجموعه)</h4>
            <ul>
                <?php foreach ($subCases as $sc): ?>
                    <li><a href="view_case.php?id=<?= $sc['id'] ?>">#<?= $sc['id'] ?> - <?= htmlspecialchars($sc['patient_name']) ?> (<?= htmlspecialchars($sc['service_title']) ?>)</a> – <span class="badge"><?= htmlspecialchars($sc['status_name']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (has_permission('create_cases') || has_role('admin')): ?>
            <p style="margin-top:12px;"><a class="btn" href="cases.php?add_sub=<?= $case['id'] ?>" style="background:#0F172A; color:#fff;">➕ افزودن کیس زیرمجموعه</a></p>
        <?php endif; ?>

        <h4>فایل‌ها</h4>

        <?php
        // ── Scan-file timing / follow-up panel ──
        // The designer must add design files within 48h of the first scan upload.
        if (!empty($files)) {
            $firstFile = $files[0];
            $firstScanAt = $firstFile['created_at'] ?? null;
            $nowTs = time();
            $scanTs = $firstScanAt ? strtotime($firstScanAt) : 0;
            $elapsedHours = $scanTs ? ($nowTs - $scanTs) / 3600 : null;

            $designExts = ['stl', 'ply', 'stp', 'step', 'obj', '3mf'];
            $hasDesignFile = false;
            $designAt = null;
            foreach ($files as $f) {
                $ext = strtolower(pathinfo($f['filename'], PATHINFO_EXTENSION));
                if (in_array($ext, $designExts, true)) {
                    $hasDesignFile = true;
                    $designAt = $f['created_at'] ?? null;
                    break;
                }
            }
            $isOverdue = $elapsedHours !== null && $elapsedHours > 48;
            ?>
            <div style="margin:12px 0; padding:12px 14px; border-radius:10px; border:1px solid <?= $hasDesignFile ? '#bbf7d0' : ($isOverdue ? '#fca5a5' : '#fde68a') ?>; background:<?= $hasDesignFile ? '#f0fdf4' : ($isOverdue ? '#fef2f2' : '#fffbeb') ?>;">
                <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:center;">
                    <div>
                        <div style="font-size:0.78rem; color:#525252;">اولین فایل (اسکن) اضافه‌شده</div>
                        <strong><?= $firstScanAt ? toJalaliDateTimeFormatted($firstScanAt) : '—' ?></strong>
                        <div style="font-size:0.85rem; color:#525252; margin-top:2px;">
                            <?= $scanTs ? 'مدت گذشته: <strong>' . htmlspecialchars(formatElapsedTime($firstScanAt)) . '</strong>' : '' ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:0.78rem; color:#525252;">فایل طراحی</div>
                        <?php if ($hasDesignFile): ?>
                            <strong style="color:#15803d;">✓ اضافه شده</strong>
                            <?php if ($designAt): ?><div style="font-size:0.8rem; color:#525252;"><?= toJalaliDateTimeFormatted($designAt) ?> (<?= htmlspecialchars(formatElapsedTime($designAt)) ?>)</div><?php endif; ?>
                        <?php else: ?>
                            <strong style="color:<?= $isOverdue ? '#b91c1c' : '#b45309' ?>;">هنوز اضافه نشده</strong>
                        <?php endif; ?>
                    </div>
                    <?php if (!$hasDesignFile && $elapsedHours !== null): ?>
                        <div style="flex:1; min-width:180px;">
                            <?php if ($isOverdue): ?>
                                <strong style="color:#b91c1c;">⚠️ بیش از ۴۸ ساعت گذشته — لطفاً پیگیری شود!</strong>
                            <?php else: ?>
                                <div style="font-size:0.85rem; color:#525252;">مهلت ۴۸ ساعته طراحی</div>
                                <?php $remaining = 48 - $elapsedHours; ?>
                                <strong style="color:#b45309;"><?= $remaining >= 1 ? toPersianDigits(floor($remaining)) . ' ساعت مانده' : 'کمتر از یک ساعت مانده' ?></strong>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php } ?>

        <?php if (has_role('admin') || has_permission('upload_design_files') || has_permission('upload_files') || has_permission('edit_cases') || ($isDoctor && (int) ($case['doctor_id'] ?? 0) === (int) $user['id'])): ?>
        <div style="margin:12px 0; padding:12px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
            <strong>آپلود فایل</strong>
            <form id="case-upload-form" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px;">
                <input type="file" id="case-upload-input" name="case_files[]" accept=".stl,.ply,.stp,.step,.obj,.3mf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.rar,.zip" multiple>
                <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">آپلود</button>
                <span id="case-upload-selection" style="display:none; font-weight:bold; color:#0369a1; background:#e0f2fe; padding:4px 10px; border-radius:6px; font-size:0.85rem;"></span>
                <label style="display:flex; align-items:center; gap:6px; width:100%; margin:4px 0 0; font-weight:600; font-size:0.9rem;">
                    نوع فایل:
                    <select id="case-upload-type" name="file_type" style="padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; font-family:inherit;">
                        <?php foreach (caseFileTypeConfig()['options'] as $ftKey => $ftLabel): ?>
                            <option value="<?= $ftKey ?>" <?= $ftKey === caseFileTypeDefault($user) ? 'selected' : '' ?>><?= $ftLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:flex; align-items:center; gap:6px; width:100%; margin:4px 0 0; font-weight:600; font-size:0.9rem; cursor:pointer;">
                    <input type="checkbox" id="case-upload-compress" style="width:auto;"> همه فایل‌ها را یکجا به‌صورت ZIP ذخیره کن
                </label>
                <textarea id="case-upload-description" name="description" rows="2" style="width:100%; margin-top:6px;" placeholder="توضیحات (اختیاری) – مثلاً: اصلاح طراحی، نوع پرسلن، ..."></textarea>
                <?php $uplLimits = uploadLimits(); ?>
                <small style="width:100%; margin-top:6px; color:#6b7280;">
                    محدودیت سرور: حداکثر حجم هر فایل <strong><?= $uplLimits['max_file'] ? formatFileSize($uplLimits['max_file']) : 'نامحدود' ?></strong>
                    · مجموع هر ارسال <strong><?= $uplLimits['post_max'] ? formatFileSize($uplLimits['post_max']) : 'نامحدود' ?></strong>
                    · حداکثر <strong><?= toPersianDigits((string) $uplLimits['max_files']) ?></strong> فایل در هر ارسال
                </small>
            </form>
            <div id="case-upload-msg" style="margin-top:6px; font-weight:bold;"></div>
            <div id="case-upload-progress" style="display:none; margin-top:8px;">
                <div style="background:#e5e7eb; border-radius:6px; overflow:hidden; height:16px;">
                    <div id="case-upload-bar" style="width:0%; height:100%; background:#06B6D4; transition:width .2s;"></div>
                </div>
                <div id="case-upload-percent" style="font-size:0.8rem; color:#555; margin-top:4px;"></div>
            </div>
        </div>
        <script>
        (function(){
            var form = document.getElementById('case-upload-form');
            if (!form) return;
            var csrf = '<?= htmlspecialchars($csrf_token) ?>';
            var UPLOAD_LIMITS = <?= json_encode(uploadLimits()) ?>;
            // بررسی حجم قبل از ارسال (خطای رایج هاست: err=3 = فایل ناقص/حجیم)
            function checkUploadSizes(files) {
                var maxFile = Number(UPLOAD_LIMITS.max_file) || 0;
                var postMax = Number(UPLOAD_LIMITS.post_max) || 0;
                var maxFiles = Number(UPLOAD_LIMITS.max_files) || 20;
                var total = 0, tooBig = [];
                for (var i = 0; i < files.length; i++) {
                    total += files[i].size;
                    if (maxFile > 0 && files[i].size > maxFile) tooBig.push(files[i].name);
                }
                if (files.length > maxFiles) return 'تعداد فایل‌ها (' + files.length + ') از حد مجاز (' + maxFiles + ') بیشتر است.';
                if (tooBig.length) return 'این فایل‌ها از حد مجاز هر فایل بزرگ‌ترند: ' + tooBig.join('، ');
                if (postMax > 0 && total > postMax) return 'مجموع حجم انتخابی از حد مجاز این ارسال بیشتر است؛ فایل‌ها را دسته‌دسته آپلود کنید.';
                return '';
            }

            // ── Selection bar: show file count + total size on selection ──
            var uploadInput = document.getElementById('case-upload-input');
            var selectionEl = document.getElementById('case-upload-selection');
            if (uploadInput && selectionEl) {
                function formatSelSize(bytes){
                    if (bytes <= 0) return '0';
                    var u = ['B','KB','MB','GB'];
                    var i = Math.floor(Math.log(bytes) / Math.log(1024));
                    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
                }
                uploadInput.addEventListener('change', function(){
                    var files = uploadInput.files;
                    if (!files.length) { selectionEl.style.display = 'none'; return; }
                    var total = 0;
                    for (var i = 0; i < files.length; i++) total += files[i].size || 0;
                    var compressCb = document.getElementById('case-upload-compress');
                    var compressNote = (compressCb && compressCb.checked && files.length > 1) ? ' — به‌صورت یک فایل ZIP ذخیره می‌شود' : '';
                    selectionEl.textContent = files.length + ' فایل انتخاب شد — مجموع ' + formatSelSize(total) + compressNote;
                    selectionEl.style.display = 'inline-block';
                });
                var compressCb = document.getElementById('case-upload-compress');
                if (compressCb) {
                    compressCb.addEventListener('change', function(){
                        if (uploadInput.files.length) uploadInput.dispatchEvent(new Event('change'));
                    });
                }
            }

            form.addEventListener('submit', function(e){
                e.preventDefault();
                var msgEl = document.getElementById('case-upload-msg');
                var input = document.getElementById('case-upload-input');
                var progressWrap = document.getElementById('case-upload-progress');
                var bar = document.getElementById('case-upload-bar');
                var pct = document.getElementById('case-upload-percent');
                if (!input || !input.files.length) { if (msgEl) { msgEl.textContent = 'فایلی انتخاب نشده است.'; msgEl.style.color = '#b91c1c'; } return; }
                var sizeErr = checkUploadSizes(input.files);
                if (sizeErr) { if (msgEl) { msgEl.textContent = '⚠️ ' + sizeErr; msgEl.style.color = '#b91c1c'; } return; }
                var fd = new FormData();
                fd.append('case_id', '<?= (int) $case['id'] ?>');
                for (var i = 0; i < input.files.length; i++) fd.append('case_files[]', input.files[i]);
                var compressCb = document.getElementById('case-upload-compress');
                if (compressCb && compressCb.checked) fd.append('compress', '1');
                var descEl = document.getElementById('case-upload-description');
                if (descEl && descEl.value.trim()) fd.append('description', descEl.value.trim());
                var typeEl = document.getElementById('case-upload-type');
                if (typeEl) fd.append('file_type', typeEl.value);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'upload_case_files.php', true);
                xhr.setRequestHeader('X-CSRF-Token', csrf);
                xhr.setRequestHeader('X-Case-Id', '<?= (int) $case['id'] ?>');
                if (msgEl) { msgEl.textContent = 'در حال آپلود...'; msgEl.style.color = '#525252'; }
                if (progressWrap) progressWrap.style.display = 'block';
                if (bar) bar.style.width = '0%';
                if (pct) pct.textContent = '0%';
                xhr.upload.onprogress = function(ev){
                    if (ev.lengthComputable) {
                        var p = Math.round((ev.loaded / ev.total) * 100);
                        if (bar) bar.style.width = p + '%';
                        if (pct) pct.textContent = p + '%';
                    }
                };
                xhr.onload = function(){
                    var resp = null;
                    try { resp = JSON.parse(xhr.responseText); } catch(e){}
                    if (xhr.status === 200 && resp && resp.success) {
                        if (bar) bar.style.width = '100%';
                        if (pct) pct.textContent = '100%';
                        if (msgEl) { msgEl.textContent = ((resp.uploaded || 0) + ' فایل با موفقیت آپلود شد.'); msgEl.style.color = '#166534'; }
                        setTimeout(function(){ location.reload(); }, 800);
                    } else if (resp && resp.partial) {
                        // بعضی فایل‌ها ذخیره شده‌اند → پیام واضح بده و لیست را به‌روز کن
                        if (progressWrap) progressWrap.style.display = 'none';
                        var pm = (resp.error_messages && resp.error_messages.length) ? resp.error_messages.join(' | ') : 'برخی فایل‌ها آپلود نشدند.';
                        if (msgEl) { msgEl.textContent = '⚠️ ' + (resp.uploaded || 0) + ' فایل ذخیره شد اما: ' + pm; msgEl.style.color = '#b45309'; }
                        setTimeout(function(){ location.reload(); }, 2500);
                    } else {
                        if (progressWrap) progressWrap.style.display = 'none';
                        var list = (resp && resp.error_messages && resp.error_messages.length)
                            ? resp.error_messages.join(' | ')
                            : ((resp && resp.errors && resp.errors.length) ? resp.errors.join(' | ') : 'نامشخص');
                        if (msgEl) { msgEl.textContent = 'خطا در آپلود: ' + list; msgEl.style.color = '#b91c1c'; }
                    }
                };
                xhr.onerror = function(){ if (progressWrap) progressWrap.style.display = 'none'; if (msgEl) { msgEl.textContent = 'خطا در اتصال به سرور.'; msgEl.style.color = '#b91c1c'; } };
                xhr.send(fd);
            });
        })();
        </script>
        <?php endif; ?>
        <?php if (empty($files)): ?>
            <p>هیچ فایلی آپلود نشده است.</p>
        <?php else: ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
                <?php 
                $imageExts = ['jpg','jpeg','png','gif','webp','bmp'];
                foreach ($files as $f): 
                    $ext = strtolower(pathinfo($f['filename'], PATHINFO_EXTENSION));
                    $isImage = in_array($ext, $imageExts);
                    $fileUrl = 'serve_case_file.php?id='.$f['id'].'&n='.rawurlencode($f['original_name']);
                    $fileDesc = trim((string) ($f['description'] ?? ''));
                    // نوع فایل: اول نوع ذخیره‌شده، وگرنه تشخیص خودکار از پسوند
                    $typeBadge = caseFileBadge($f['file_type'] ?? null, $ext);
                ?>
                    <div class="file-chip" style="display:flex; flex-direction:column; gap:4px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:6px 8px;">
                        <div style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">
                        <?php if ($isImage): ?>
                            <button class="btn file-image" data-file="<?= htmlspecialchars($fileUrl) ?>" style="background:#e0f2fe; color:#0369a1; padding:4px 10px; font-size:0.85rem;" title="<?= htmlspecialchars($fileDesc) ?>">
                                🖼 <?= htmlspecialchars($f['original_name']) ?>
                            </button>
                        <?php else: ?>
                            <button class="btn file-load" data-file="<?= htmlspecialchars($fileUrl) ?>" style="padding:4px 10px; font-size:0.85rem;" title="<?= htmlspecialchars($fileDesc) ?>">
                                <?= htmlspecialchars($f['original_name']) ?>
                            </button>
                        <?php endif; ?>
                        <?php if (!in_array($user['role'] ?? '', ['doctor', 'clinic'])): ?>
                            <a class="btn" href="download_case_file.php?id=<?= $f['id'] ?>" style="background:#E5E7EB; color:#0F172A; padding:4px 6px; font-size:0.8rem; text-decoration:none;" title="دانلود">⬇️</a>
                        <?php endif; ?>
                        <?php if (in_array($ext, ['zip', 'rar'], true)): ?>
                            <button class="btn file-action-archive" style="background:#fef3c7; color:#92400e; padding:4px 6px; font-size:0.8rem;" data-id="<?= (int) $f['id'] ?>" data-name="<?= htmlspecialchars($f['original_name']) ?>">🗜 محتوا</button>
                        <?php endif; ?>
                        <?php if (has_permission('edit_cases') || has_permission('upload_files')): ?>
                            <button class="btn file-action-rename" style="background:#F3F4F6; color:#111; padding:4px 6px; font-size:0.8rem;" data-id="<?= $f['id'] ?>" data-name="<?= htmlspecialchars($f['original_name']) ?>" data-desc="<?= htmlspecialchars($fileDesc) ?>">✏️</button>
                            <button class="btn file-action-delete" style="background:#fee2e2; color:#a00; padding:4px 6px; font-size:0.8rem;" data-id="<?= $f['id'] ?>" data-name="<?= htmlspecialchars($f['original_name']) ?>">🗑</button>
                        <?php endif; ?>
                        </div>
                        <?php if ($fileDesc !== ''): ?>
                            <div class="file-desc" style="font-size:0.8rem; color:#374151; background:#f3f4f6; border-radius:6px; padding:4px 6px; white-space:pre-wrap; line-height:1.6;"><?= htmlspecialchars($fileDesc) ?></div>
                        <?php endif; ?>
                        <?php if ($canAppendNote && $fileDesc !== ''): ?>
                            <button class="btn js-append-note" data-text="<?= htmlspecialchars($fileDesc) ?>" style="background:#eef2ff; color:#3730a3; padding:4px 6px; font-size:0.78rem; align-self:flex-start;" title="افزودن این توضیح به یادداشت پزشک">📝 به یادداشت پزشک</button>
                        <?php endif; ?>
                        <div style="font-size:0.72rem; color:#6b7280; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <?php if (!empty($f['uploader_name'])): ?><span>👤 <?= htmlspecialchars($f['uploader_name']) ?></span><?php endif; ?>
                            <?php if (!empty($f['size'])): ?><span>💾 <?= formatFileSize($f['size']) ?></span><?php endif; ?>
                            <span><?= $typeBadge ?></span>
                        </div>
                        <?php $chipLinks = caseFileLinkTargets((int) $f['id']); ?>
                        <?php if (!empty($chipLinks)): ?>
                            <div class="file-links" style="font-size:0.72rem; color:#5b21b6; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                <?php foreach ($chipLinks as $lt): ?>
                                    <span style="background:#ede9fe; border-radius:999px; padding:2px 8px; display:inline-flex; gap:4px; align-items:center;">
                                        🔗 <a href="view_case.php?id=<?= (int) $lt['case_id'] ?>" style="color:#5b21b6; text-decoration:none;" title="<?= htmlspecialchars($lt['patient_name'] ?? '') ?>">کیس #<?= (int) $lt['case_id'] ?></a>
                                        <?php if ($canLinkFiles): ?>
                                            <button type="button" class="btn js-vc-unlink-file" data-file="<?= (int) $f['id'] ?>" data-case="<?= (int) $lt['case_id'] ?>" style="background:transparent; color:#991b1b; padding:0 3px; font-size:0.75rem; line-height:1;" title="حذف اتصال این فایل از کیس #<?= (int) $lt['case_id'] ?>">✕</button>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($f['created_at'])): ?>
                            <div style="font-size:0.72rem; color:#6b7280;">📅 <?= toJalaliDateTimeFormatted($f['created_at']) ?> — <?= htmlspecialchars(formatElapsedTime($f['created_at'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Image Viewer (hidden by default) -->
            <div id="image-viewer-container" style="display:none; width:100%; margin-bottom:16px;">
                <img id="image-viewer" src="" alt="preview" style="max-width:100%; max-height:600px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); display:block; margin:0 auto;">
                <div style="text-align:center; margin-top:8px;">
                    <button id="close-image-viewer" class="btn" style="background:#E5E7EB; color:#0F172A;">بستن تصویر</button>
                </div>
            </div>

            <!-- 3D Viewer (hidden until a 3D file is selected) -->
            <div id="viewer" style="display:none; width:100%; height:520px; background:linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border:1px solid #d1d5db; border-radius:12px; position:relative; overflow:hidden;">
                <div id="viewer-overlay" style="position:absolute; right:8px; top:8px; background:rgba(255,255,255,0.95); border-radius:10px; padding:8px; box-shadow:0 4px 16px rgba(0,0,0,0.1); z-index:1000; font-size:13px; backdrop-filter:blur(4px);">
                    <div style="display:flex; gap:4px; margin-bottom:6px;">
                        <button id="rot-left" class="btn" style="padding:4px 8px; font-size:1rem;" title="چرخش به چپ">⟲</button>
                        <button id="rot-right" class="btn" style="padding:4px 8px; font-size:1rem;" title="چرخش به راست">⟳</button>
                        <button id="rot-up" class="btn" style="padding:4px 8px; font-size:1rem;" title="چرخش به بالا">↑</button>
                        <button id="rot-down" class="btn" style="padding:4px 8px; font-size:1rem;" title="چرخش به پایین">↓</button>
                    </div>
                    <div style="display:flex; gap:4px; margin-bottom:4px;">
                        <button id="zoom-in" class="btn" style="padding:4px 8px; font-size:1rem;">＋</button>
                        <button id="zoom-out" class="btn" style="padding:4px 8px; font-size:1rem;">−</button>
                        <button id="reset-view" class="btn" style="padding:4px 8px; font-size:0.8rem;">⟲ reset</button>
                    </div>
                    <div style="display:flex; gap:4px;">
                        <button id="view-top" class="btn" style="padding:4px 8px; font-size:0.8rem;">نمای بالا</button>
                        <button id="view-front" class="btn" style="padding:4px 8px; font-size:0.8rem;">نمای جلو</button>
                        <button id="view-side" class="btn" style="padding:4px 8px; font-size:0.8rem;">نمای کنار</button>
                    </div>
                    <div style="font-size:10px; color:#666; margin-top:4px; text-align:center;">کلیک+درگ = چرخش | اسکرول = زوم</div>
                </div>
                <div id="viewer-placeholder" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:1.1rem; pointer-events:none;">
                    روی فایل کلیک کنید تا نمایش داده شود
                </div>
            </div>
            <!-- Rename modal -->
            <style>
                /* Modals must be fixed/centered overlays (view_case has no style block) */
                .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.45); z-index: 9999; }
                .modal .modal-content { max-height: 90vh; overflow: auto; box-shadow: 0 8px 24px rgba(0,0,0,0.2); background: #fff; border-radius: 4px; }
            </style>
            <div id="rename-modal" class="modal" style="display:none;">
                <div class="modal-content form-card" style="max-width:460px; margin:auto;">
                    <h3>ویرایش فایل</h3>
                    <form id="rename-form">
                        <input type="hidden" id="rename-file-id" name="id">
                        <div class="form-group">
                            <label for="rename-file-name">نام فایل</label>
                            <input type="text" id="rename-file-name" name="name" style="width:100%; margin-top:8px;">
                        </div>
                        <div class="form-group" style="margin-top:10px;">
                            <label for="rename-file-desc">توضیحات</label>
                            <textarea id="rename-file-desc" name="description" rows="3" style="width:100%; margin-top:8px;" placeholder="توضیحات این فایل..."></textarea>
                        </div>
                        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                            <button type="button" id="rename-cancel" class="btn">انصراف</button>
                            <button type="submit" class="btn" style="background:#06B6D4;">ذخیره</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete modal for files -->
            <div id="delete-modal-file" class="modal" style="display:none;">
                <div class="modal-content form-card" style="max-width:420px; margin:auto;">
                    <h3>حذف فایل</h3>
                    <p>آیا از حذف فایل <strong id="delete-file-name"></strong> مطمئن هستید؟</p>
                    <form id="delete-form">
                        <input type="hidden" id="delete-file-id" name="id">
                        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                            <button type="button" id="delete-cancel-file" class="btn">انصراف</button>
                            <button type="submit" class="btn" style="background:#f87171; color:#fff;">حذف</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Archive contents modal -->
            <div id="archive-modal" class="modal" style="display:none;">
                <div class="modal-content form-card" style="max-width:560px; margin:auto;">
                    <h3>محتویات آرشیو: <span id="archive-file-name" style="font-size:0.9rem;"></span></h3>
                    <div id="archive-loading" style="padding:16px; color:#525252;">در حال بارگذاری...</div>
                    <div id="archive-content" style="max-height:380px; overflow:auto; margin-top:8px;"></div>
                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                        <button type="button" id="archive-cancel" class="btn">بستن</button>
                    </div>
                </div>
            </div>
            <script>window.CSRF_TOKEN = '<?= htmlspecialchars($csrf_token) ?>';</script>

            <!-- Image viewer script -->
            <script>
            (function() {
                var imgContainer = document.getElementById('image-viewer-container');
                var imgEl = document.getElementById('image-viewer');
                var closeBtn = document.getElementById('close-image-viewer');

                document.querySelectorAll('.file-image').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var url = btn.getAttribute('data-file');
                        imgEl.src = url;
                        imgContainer.style.display = 'block';
                        // Hide the 3D viewer while showing an image
                        var v3d = document.getElementById('viewer');
                        if (v3d) v3d.style.display = 'none';
                        // Scroll to image
                        imgContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                });

                if (closeBtn) {
                    closeBtn.addEventListener('click', function() {
                        imgContainer.style.display = 'none';
                        imgEl.src = '';
                    });
                }
            })();
            </script>

            <script type="module">
                import * as THREE from '../assets/js/three/three.module.js';
                import { STLLoader } from '../assets/js/three/STLLoader.module.js';
                import { PLYLoader } from '../assets/js/three/PLYLoader.module.js';
                import { OrbitControls } from '../assets/js/three/OrbitControls.module.js';

                const container = document.getElementById('viewer');
                const placeholder = document.getElementById('viewer-placeholder');
                const scene = new THREE.Scene();
                scene.background = new THREE.Color(0xf0f2f5);

                const camera = new THREE.PerspectiveCamera(40, container.clientWidth / container.clientHeight, 0.1, 1000);
                const renderer = new THREE.WebGLRenderer({ antialias: true });
                renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
                renderer.setSize(container.clientWidth, container.clientHeight);
                renderer.shadowMap.enabled = true;
                container.appendChild(renderer.domElement);

                // Lighting
                const ambientLight = new THREE.AmbientLight(0x404060);
                scene.add(ambientLight);

                const keyLight = new THREE.DirectionalLight(0xffffff, 1.2);
                keyLight.position.set(2, 3, 4);
                scene.add(keyLight);

                const fillLight = new THREE.DirectionalLight(0x8888ff, 0.5);
                fillLight.position.set(-2, 1, -3);
                scene.add(fillLight);

                const rimLight = new THREE.DirectionalLight(0xffffff, 0.4);
                rimLight.position.set(0, -2, 2);
                scene.add(rimLight);

                // Ground grid
                const gridHelper = new THREE.GridHelper(200, 20, 0x444444, 0x888888);
                gridHelper.position.y = -20;
                scene.add(gridHelper);

                camera.position.set(100, 50, 100);
                camera.lookAt(0, 0, 0);

                let currentMesh = null;
                let currentScale = 1;
                const controls = new OrbitControls(camera, renderer.domElement);
                controls.enableDamping = true;
                controls.dampingFactor = 0.1;
                controls.minDistance = 5;
                controls.maxDistance = 500;
                controls.target.set(0, 0, 0);
                controls.update();

                function isGeometryValid(geometry) {
                    if (!geometry || !geometry.attributes || !geometry.attributes.position) return false;
                    const arr = geometry.attributes.position.array;
                    for (let i = 0; i < arr.length; i++) if (!isFinite(arr[i])) return false;
                    return true;
                }

                function centerMesh(mesh) {
                    mesh.geometry.computeBoundingBox();
                    const bbox = mesh.geometry.boundingBox;
                    const center = new THREE.Vector3();
                    bbox.getCenter(center);
                    mesh.position.sub(center);
                    const size = new THREE.Vector3();
                    bbox.getSize(size);
                    const max = Math.max(size.x, size.y, size.z) || 1;
                    currentScale = 80 / max;
                    mesh.scale.set(currentScale, currentScale, currentScale);
                    // Auto-position camera
                    const dist = max * 2.5;
                    camera.position.set(dist * 0.7, dist * 0.4, dist);
                    controls.target.set(0, 0, 0);
                    controls.update();
                }

                function loadFile(url) {
                    // Show the 3D viewer only when a model is actually selected
                    container.style.display = 'block';
                    var imgCont = document.getElementById('image-viewer-container');
                    if (imgCont) imgCont.style.display = 'none';
                    if (placeholder) placeholder.style.display = 'none';
                    if (currentMesh) {
                        scene.remove(currentMesh);
                        currentMesh.geometry.dispose();
                        currentMesh.material.dispose();
                        currentMesh = null;
                    }

                    const ext = url.split('.').pop().toLowerCase();

                    function onGeometry(geometry, useVertexColors) {
                        if (!isGeometryValid(geometry)) {
                            alert('خطا: هندسه فایل نامعتبر است.');
                            return;
                        }
                        geometry.computeVertexNormals();
                        const material = new THREE.MeshStandardMaterial({
                            color: useVertexColors ? 0xffffff : 0x88aacc,
                            vertexColors: useVertexColors,
                            roughness: 0.4,
                            metalness: 0.1,
                            side: THREE.DoubleSide
                        });
                        const mesh = new THREE.Mesh(geometry, material);
                        mesh.castShadow = true;
                        mesh.receiveShadow = true;
                        centerMesh(mesh);
                        currentMesh = mesh;
                        scene.add(mesh);
                    }

                    if (ext === 'stl') {
                        const loader = new STLLoader();
                        loader.load(url, function(geometry) {
                            const hasColors = geometry.attributes.color !== undefined;
                            onGeometry(geometry, hasColors);
                        });
                    } else if (ext === 'ply') {
                        const loader = new PLYLoader();
                        loader.load(url, function(geometry) {
                            const hasColors = geometry.attributes.color !== undefined;
                            onGeometry(geometry, hasColors);
                        });
                    }
                }

                // Animation loop
                function animate() {
                    requestAnimationFrame(animate);
                    controls.update();
                    renderer.render(scene, camera);
                }
                animate();

                // Handle resize
                window.addEventListener('resize', function() {
                    const w = container.clientWidth;
                    const h = container.clientHeight;
                    camera.aspect = w / h;
                    camera.updateProjectionMatrix();
                    renderer.setSize(w, h);
                });

                // Wire 3D file buttons
                document.querySelectorAll('.file-load').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        loadFile(btn.getAttribute('data-file'));
                    });
                });

                // Overlay controls
                function rotateModel(axis, angle) {
                    if (!currentMesh) return;
                    const rad = THREE.MathUtils.degToRad(angle);
                    const q = new THREE.Quaternion().setFromAxisAngle(axis, rad);
                    currentMesh.quaternion.multiply(q);
                }

                document.getElementById('rot-left')?.addEventListener('click', function() {
                    rotateModel(new THREE.Vector3(0, 1, 0), -15);
                });
                document.getElementById('rot-right')?.addEventListener('click', function() {
                    rotateModel(new THREE.Vector3(0, 1, 0), 15);
                });
                document.getElementById('rot-up')?.addEventListener('click', function() {
                    rotateModel(new THREE.Vector3(1, 0, 0), -15);
                });
                document.getElementById('rot-down')?.addEventListener('click', function() {
                    rotateModel(new THREE.Vector3(1, 0, 0), 15);
                });
                document.getElementById('zoom-in')?.addEventListener('click', function() {
                    camera.position.multiplyScalar(0.85);
                    controls.update();
                });
                document.getElementById('zoom-out')?.addEventListener('click', function() {
                    camera.position.multiplyScalar(1.15);
                    controls.update();
                });
                document.getElementById('reset-view')?.addEventListener('click', function() {
                    if (!currentMesh) return;
                    currentMesh.quaternion.identity();
                    const size = currentMesh.geometry.boundingBox ? 
                        currentMesh.geometry.boundingBox.getSize(new THREE.Vector3()) : new THREE.Vector3(1,1,1);
                    const max = Math.max(size.x, size.y, size.z) || 1;
                    const dist = max * 2.5 / currentScale;
                    camera.position.set(dist * 0.7, dist * 0.4, dist);
                    controls.target.set(0, 0, 0);
                    controls.update();
                });
                document.getElementById('view-top')?.addEventListener('click', function() {
                    const dist = camera.position.length();
                    camera.position.set(0, dist, 0.01);
                    controls.target.set(0, 0, 0);
                    controls.update();
                });
                document.getElementById('view-front')?.addEventListener('click', function() {
                    const dist = camera.position.length();
                    camera.position.set(0, 0, dist);
                    controls.target.set(0, 0, 0);
                    controls.update();
                });
                document.getElementById('view-side')?.addEventListener('click', function() {
                    const dist = camera.position.length();
                    camera.position.set(dist, 0, 0.01);
                    controls.target.set(0, 0, 0);
                    controls.update();
                });

                // viewer overlay controls
                function rotateAroundY(angle) {
                    const pivot = controls.target.clone();
                    const pos = camera.position.clone().sub(pivot);
                    pos.applyAxisAngle(new THREE.Vector3(0,1,0), angle);
                    camera.position.copy(pivot.clone().add(pos));
                    camera.lookAt(pivot);
                    controls.update();
                }
                function rotateAroundX(angle) {
                    const pivot = controls.target.clone();
                    const pos = camera.position.clone().sub(pivot);
                    pos.applyAxisAngle(new THREE.Vector3(1,0,0), angle);
                    camera.position.copy(pivot.clone().add(pos));
                    camera.lookAt(pivot);
                    controls.update();
                }
                function zoomBy(factor) {
                    const dir = camera.position.clone().sub(controls.target).multiplyScalar(factor);
                    camera.position.copy(controls.target.clone().add(dir));
                    controls.update();
                }
                function resetView() {
                    camera.position.set(0,0,100);
                    controls.target.set(0,0,0);
                    controls.update();
                }
                document.getElementById('rot-left').addEventListener('click', ()=> rotateAroundY(0.2));
                document.getElementById('rot-right').addEventListener('click', ()=> rotateAroundY(-0.2));
                document.getElementById('rot-up').addEventListener('click', ()=> rotateAroundX(0.15));
                document.getElementById('rot-down').addEventListener('click', ()=> rotateAroundX(-0.15));
                document.getElementById('zoom-in').addEventListener('click', ()=> zoomBy(0.8));
                document.getElementById('zoom-out').addEventListener('click', ()=> zoomBy(1.25));
                document.getElementById('reset-view').addEventListener('click', resetView);

                document.querySelectorAll('.file-action-rename').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const id = btn.dataset.id; const name = btn.dataset.name;
                        document.getElementById('rename-file-id').value = id;
                        document.getElementById('rename-file-name').value = name;
                        document.getElementById('rename-file-desc').value = (btn.dataset.desc || '');
                        document.getElementById('rename-modal').style.display = 'flex';
                    });
                });

                document.querySelectorAll('.file-action-delete').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const id = btn.dataset.id; const name = btn.dataset.name;
                        document.getElementById('delete-file-id').value = id;
                        document.getElementById('delete-file-name').textContent = name;
                        document.getElementById('delete-modal-file').style.display = 'flex';
                    });
                });

                document.getElementById('rename-cancel').addEventListener('click', function(){ document.getElementById('rename-modal').style.display='none'; });
                document.getElementById('delete-cancel-file').addEventListener('click', function(){ document.getElementById('delete-modal-file').style.display='none'; });
                document.getElementById('archive-cancel').addEventListener('click', function(){ document.getElementById('archive-modal').style.display='none'; });

                function formatSize(bytes){
                    if (bytes === 0) return '0';
                    var units = ['B','KB','MB','GB'];
                    var i = Math.floor(Math.log(bytes) / Math.log(1024));
                    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
                }
                function escapeHtml(str){
                    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
                }
                document.querySelectorAll('.file-action-archive').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var id = btn.dataset.id;
                        var name = btn.dataset.name;
                        document.getElementById('archive-file-name').textContent = name;
                        document.getElementById('archive-loading').style.display = 'block';
                        document.getElementById('archive-content').innerHTML = '';
                        document.getElementById('archive-modal').style.display = 'flex';
                        fetch('view_archive.php?id=' + id, { cache: 'no-store' }).then(function(r){ return r.json(); }).then(function(data){
                            document.getElementById('archive-loading').style.display = 'none';
                            var box = document.getElementById('archive-content');
                            if (!data || !data.success) {
                                box.innerHTML = '<p style="color:#b91c1c;">' + escapeHtml(data && data.message ? data.message : 'خطا در خواندن آرشیو') + '</p>';
                                return;
                            }
                            if (!data.entries || data.entries.length === 0) {
                                box.innerHTML = '<p>این آرشیو خالی است.</p>';
                                return;
                            }
                            var html = '<table style="width:100%; border-collapse:collapse; font-size:0.85rem;"><thead><tr>' +
                                '<th style="border-bottom:1px solid #e5e7eb; padding:6px; text-align:right;">نام</th>' +
                                '<th style="border-bottom:1px solid #e5e7eb; padding:6px; text-align:right;">حجم</th>' +
                                '</tr></thead><tbody>';
                            data.entries.forEach(function(en){
                                var icon = en.is_dir ? '📁' : '📄';
                                var size = en.is_dir ? '—' : formatSize(en.size || 0);
                                html += '<tr><td style="border-bottom:1px solid #f3f4f6; padding:5px 6px; direction:ltr; text-align:left;">' + icon + ' ' + escapeHtml(en.name) + '</td>' +
                                    '<td style="border-bottom:1px solid #f3f4f6; padding:5px 6px; text-align:right;">' + size + '</td></tr>';
                            });
                            html += '</tbody></table>';
                            box.innerHTML = html;
                        }).catch(function(){ document.getElementById('archive-loading').style.display = 'none'; document.getElementById('archive-content').innerHTML = '<p style="color:#b91c1c;">خطا در ارتباط با سرور</p>'; });
                    });
                });

                document.getElementById('rename-form').addEventListener('submit', function(e){
                    e.preventDefault();
                    var id = document.getElementById('rename-file-id').value;
                    var name = document.getElementById('rename-file-name').value.trim();
                    var desc = document.getElementById('rename-file-desc').value.trim();
                    if (!name) return alert('نام نباید خالی باشد');
                    var body = new URLSearchParams({ id: id, name: name, description: desc, _csrf_token: window.CSRF_TOKEN });
                    fetch('rename_case_file.php', { method: 'POST', headers: {'Accept':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN }, body: body }).then(r=>r.json()).then(function(data){
                        if (data && data.success) {
                            document.querySelectorAll('.file-action-rename, .file-action-delete, .file-load').forEach(function(el){
                                if (el.dataset.id == id) el.dataset.name = name;
                            });
                            document.querySelectorAll('.file-action-rename').forEach(function(el){
                                if (el.dataset.id == id) el.dataset.desc = desc;
                            });
                            document.querySelectorAll('.file-load').forEach(function(b){ if (b.dataset.file && b.nextElementSibling && b.nextElementSibling.dataset && b.nextElementSibling.dataset.id == id) { b.textContent = name; } });
                            // Update the visible description block for this file
                            document.querySelectorAll('.file-action-rename').forEach(function(el){
                                if (el.dataset.id == id) {
                                    var chip = el.closest('.file-chip');
                                    if (chip) {
                                        var oldDesc = chip.querySelector('.file-desc');
                                        if (oldDesc) oldDesc.remove();
                                        if (desc) {
                                            var d = document.createElement('div');
                                            d.className = 'file-desc';
                                            d.style.cssText = 'font-size:0.8rem; color:#374151; background:#f3f4f6; border-radius:6px; padding:4px 6px; white-space:pre-wrap; line-height:1.6;';
                                            d.textContent = desc;
                                            chip.appendChild(d);
                                        }
                                    }
                                }
                            });
                            document.getElementById('rename-modal').style.display='none';
                        } else {
                            alert('خطا در تغییر فایل');
                        }
                    }).catch(function(){ alert('خطا در درخواست'); });
                });

                document.getElementById('delete-form').addEventListener('submit', function(e){
                    e.preventDefault();
                    var id = document.getElementById('delete-file-id').value;
                    fetch('delete_case_file.php', { method: 'POST', headers: {'Accept':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN }, body: new URLSearchParams({ id: id, _csrf_token: window.CSRF_TOKEN }) }).then(r=>r.json()).then(function(data){
                        if (data && data.success) {
                            document.querySelectorAll('.file-action-rename, .file-action-delete, .file-load').forEach(function(el){ if (el.dataset.id == id) { var wrapper = el.closest('div'); if (wrapper) wrapper.remove(); } });
                            document.getElementById('delete-modal-file').style.display='none';
                        } else {
                            alert('خطا در حذف فایل');
                        }
                    }).catch(function(){ alert('خطا در درخواست'); });
                });
            </script>
        <?php endif; ?>

        <?php
        // ── فایل‌های مرتبط: فایل‌هایی از کیس‌های دیگر که به این کیس وصل شده‌اند ──
        // فایل اصلی در کیسِ خودش می‌ماند و این‌جا فقط «نمایش داده» می‌شود.
        $caseLinkedFiles = getLinkedCaseFilesForCase((int) $case['id']);
        ?>
        <div class="form-card" style="margin-top:20px;">
            <h4 style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 6px;">
                <span>🔗 فایل‌های مرتبط از کیس‌های دیگر</span>
                <span style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <?php if (count($caseLinkedFiles)): ?>
                        <span class="badge" style="background:#ede9fe; color:#5b21b6;"><?= toPersianDigits((string) count($caseLinkedFiles)) ?> فایل</span>
                    <?php endif; ?>
                    <?php if ($canLinkFiles): ?>
                        <button type="button" id="vc-link-open" class="btn" style="background:#0F172A; color:#fff; padding:5px 12px;">➕ افزودن فایل از کیس دیگر</button>
                    <?php endif; ?>
                </span>
            </h4>
            <p style="margin:0 0 8px; color:#525252; font-size:0.85rem;">
                فایل‌های کیس‌های دیگر (اسکن/طراحی/…) که به این کیس هم وصل شده‌اند. فایل روی کیسِ اصلی خودش باقی می‌ماند.
            </p>
            <?php if (empty($caseLinkedFiles)): ?>
                <p class="empty" style="margin:0;">فایل مرتبطی به این کیس وصل نشده است.</p>
            <?php else: ?>
                <table class="datatable display" style="width:100%; font-size:0.9rem;">
                    <thead>
                    <tr>
                        <th>فایل</th>
                        <th>کیس مبدأ</th>
                        <th>بیمار</th>
                        <th>نوع</th>
                        <th>اندازه</th>
                        <th>زمان اتصال</th>
                        <th>عملیات</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($caseLinkedFiles as $lf):
                        $lfExt = strtolower(pathinfo((string) $lf['original_name'], PATHINFO_EXTENSION));
                        $lfDesc = trim((string) ($lf['description'] ?? ''));
                        // فقط سازندهٔ اتصال، آپلودکنندهٔ فایل یا مدیر می‌تواند اتصال را بردارد
                        $canUnlinkThis = $canLinkFiles && (is_admin() || has_permission('edit_cases')
                            || (int) ($lf['link_created_by'] ?? 0) === (int) $user['id']
                            || (int) ($lf['uploader_id'] ?? 0) === (int) $user['id']);
                    ?>
                        <tr>
                            <td>
                                <a href="serve_case_file.php?id=<?= (int) $lf['id'] ?>&n=<?= rawurlencode((string) $lf['original_name']) ?>" target="_blank"><?= htmlspecialchars($lf['original_name']) ?></a>
                                <?php if ($lfDesc !== ''): ?>
                                    <div style="font-size:0.78rem; color:#6b7280; white-space:pre-wrap;"><?= htmlspecialchars($lfDesc) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><a href="view_case.php?id=<?= (int) $lf['src_case_id'] ?>">#<?= (int) $lf['src_case_id'] ?></a></td>
                            <td><?= htmlspecialchars($lf['src_patient_name'] ?? '—') ?></td>
                            <td><?= caseFileBadge($lf['file_type'] ?? null, $lfExt) ?></td>
                            <td><?= !empty($lf['size']) ? formatFileSize($lf['size']) : '—' ?></td>
                            <td style="font-size:0.78rem; color:#6b7280;">
                                <?= !empty($lf['linked_at']) ? toJalaliDateTimeFormatted($lf['linked_at']) : '—' ?>
                                <?= !empty($lf['linker_name']) ? '<br>' . htmlspecialchars($lf['linker_name']) : '' ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <a class="btn" href="serve_case_file.php?id=<?= (int) $lf['id'] ?>" target="_blank" style="background:#e0f2fe; color:#0369a1; padding:3px 8px; text-decoration:none;" title="باز کردن / پیش‌نمایش">باز کردن</a>
                                <?php if (!in_array($user['role'] ?? '', ['doctor', 'clinic'], true)): ?>
                                    <a class="btn" href="download_case_file.php?id=<?= (int) $lf['id'] ?>" style="background:#E5E7EB; color:#0F172A; padding:3px 8px; text-decoration:none;">دانلود</a>
                                <?php endif; ?>
                                <?php if ($canUnlinkThis): ?>
                                    <button type="button" class="btn js-vc-unlink-file" data-file="<?= (int) $lf['id'] ?>" style="background:#fee2e2; color:#991b1b; padding:3px 8px;" title="حذف اتصال این فایل از این کیس">✕</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if ($canLinkFiles): ?>
        <style>
            .vc-modal{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.45); z-index:9999; }
            .vc-modal .vc-modal-box{ background:#fff; border-radius:10px; max-height:92vh; overflow:auto; width:920px; max-width:96%; padding:18px; box-shadow:0 10px 30px rgba(0,0,0,0.25); }
            #vc-link-results tr.vc-hit{ cursor:pointer; }
            #vc-link-results tr.vc-hit:hover{ background:#f1f5f9; }
        </style>
        <div id="vc-link-modal" class="vc-modal">
            <div class="vc-modal-box">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                    <h4 style="margin:0;">➕ افزودن فایل از کیس‌های دیگر</h4>
                    <button type="button" id="vc-link-close" class="btn" style="background:#E5E7EB; color:#0F172A; padding:4px 12px;">✕</button>
                </div>
                <div style="display:flex; gap:8px; margin:12px 0; flex-wrap:wrap; align-items:center;">
                    <input type="text" id="vc-link-search" placeholder="جست‌وجو: نام فایل، نام بیمار یا شمارهٔ کیس…" style="flex:1; min-width:220px; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px;">
                    <button type="button" id="vc-link-search-btn" class="btn" style="background:#0F172A; color:#fff;">جست‌وجو</button>
                    <button type="button" id="vc-link-attach-btn" class="btn" style="background:#16A34A; color:#fff;">اتصال انتخاب‌شده‌ها</button>
                </div>
                <div id="vc-link-msg" style="font-weight:bold; margin-bottom:6px;"></div>
                <div style="max-height:52vh; overflow:auto; border:1px solid #e5e7eb; border-radius:8px;">
                    <table style="width:100%; font-size:0.88rem; border-collapse:collapse;">
                        <thead>
                        <tr style="background:#f8fafc;">
                            <th style="padding:6px; width:34px;"><input type="checkbox" id="vc-link-all"></th>
                            <th style="padding:6px; text-align:right;">فایل</th>
                            <th style="padding:6px; text-align:right;">کیس</th>
                            <th style="padding:6px; text-align:right;">بیمار</th>
                            <th style="padding:6px; text-align:right;">نوع</th>
                            <th style="padding:6px; text-align:right;">اندازه</th>
                            <th style="padding:6px; text-align:right;">تاریخ</th>
                        </tr>
                        </thead>
                        <tbody id="vc-link-results">
                        <tr><td colspan="7" style="padding:12px; color:#6b7280;">برای دیدن فایل‌ها، حداقل ۲ حرف از نام فایل/بیمار یا شمارهٔ کیس را بنویسید.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var csrf = '<?= htmlspecialchars($csrf_token) ?>';
            var caseId = '<?= (int) $case['id'] ?>';
            var modal = document.getElementById('vc-link-modal');
            var input = document.getElementById('vc-link-search');
            var results = document.getElementById('vc-link-results');
            var msgEl = document.getElementById('vc-link-msg');
            function setMsg(text, color){ if (msgEl){ msgEl.textContent = text || ''; msgEl.style.color = color || '#334155'; } }
            function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

            function openModal(){ if (!modal) return; modal.style.display = 'flex'; setMsg(''); input.focus(); }
            function closeModal(){ if (modal) modal.style.display = 'none'; }
            var openBtn = document.getElementById('vc-link-open');
            if (openBtn) openBtn.addEventListener('click', openModal);
            var closeBtn = document.getElementById('vc-link-close');
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', function(e){ if ((e.key === 'Escape' || e.key === 'Esc') && modal && modal.style.display === 'flex') closeModal(); });

            function render(rows){
                if (!results) return;
                if (!rows.length){
                    results.innerHTML = '<tr><td colspan="7" style="padding:12px; color:#6b7280;">فایلی یافت نشد (یا قبلاً به این کیس وصل شده است).</td></tr>';
                    return;
                }
                var html = '';
                rows.forEach(function(r){
                    html += '<tr class="vc-hit">' +
                        '<td style="padding:6px;"><input type="checkbox" class="vc-link-cb" value="' + r.file_id + '"></td>' +
                        '<td style="padding:6px;">' + esc(r.name) + (r.description ? '<div style="color:#6b7280; font-size:0.78rem; white-space:pre-wrap;">' + esc(r.description) + '</div>' : '') + '</td>' +
                        '<td style="padding:6px;"><a href="view_case.php?id=' + r.case_id + '" target="_blank">#' + r.case_id + '</a></td>' +
                        '<td style="padding:6px;">' + esc(r.patient_name || '—') + '</td>' +
                        '<td style="padding:6px;">' + esc(r.type_label || '') + '</td>' +
                        '<td style="padding:6px;">' + esc(r.size || '') + '</td>' +
                        '<td style="padding:6px; font-size:0.78rem; color:#6b7280;">' + esc(r.created || '') + '</td>' +
                    '</tr>';
                });
                results.innerHTML = html;
            }

            function doSearch(){
                var q = (input && input.value ? input.value : '').trim();
                if (q.length < 2){ setMsg('حداقل ۲ حرف وارد کنید.', '#b45309'); return; }
                setMsg('در حال جست‌وجو...', '#334155');
                fetch('search_case_files.php?q=' + encodeURIComponent(q) + '&exclude_case_id=' + encodeURIComponent(caseId), { headers: { 'Accept': 'application/json' } })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || !res.success){ setMsg((res && res.message) || 'خطا در جست‌وجو.', '#b91c1c'); return; }
                        setMsg((res.count || 0) + ' فایل پیدا شد.', '#334155');
                        render(res.results || []);
                    })
                    .catch(function(){ setMsg('خطا در ارتباط با سرور.', '#b91c1c'); });
            }
            var searchBtn = document.getElementById('vc-link-search-btn');
            if (searchBtn) searchBtn.addEventListener('click', doSearch);
            if (input) input.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); doSearch(); } });

            var allCb = document.getElementById('vc-link-all');
            if (allCb) allCb.addEventListener('change', function(){
                document.querySelectorAll('.vc-link-cb').forEach(function(cb){ cb.checked = allCb.checked; });
            });
            // کلیک روی ردیف = تیک/برداشتن تیک
            if (results) results.addEventListener('click', function(e){
                if (e.target && e.target.classList && e.target.classList.contains('vc-link-cb')) return;
                var tr = e.target.closest ? e.target.closest('tr.vc-hit') : null;
                if (!tr) return;
                var cb = tr.querySelector('.vc-link-cb');
                if (cb) cb.checked = !cb.checked;
            });

            var attachBtn = document.getElementById('vc-link-attach-btn');
            if (attachBtn) attachBtn.addEventListener('click', function(){
                var ids = [];
                document.querySelectorAll('.vc-link-cb:checked').forEach(function(cb){ ids.push(cb.value); });
                if (!ids.length){ setMsg('هیچ فایلی انتخاب نشده است.', '#b45309'); return; }
                var fd = new FormData();
                fd.append('case_id', caseId);
                ids.forEach(function(id){ fd.append('file_ids[]', id); });
                fd.append('_csrf_token', csrf);
                setMsg('در حال اتصال...', '#334155');
                fetch('link_case_file_to_case.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd })
                    .then(function(r){ return r.json().catch(function(){ return { success:false, message:'پاسخ نامعتبر سرور' }; }); })
                    .then(function(res){
                        if (res && res.success){
                            setMsg('✅ ' + (res.linked || 0) + ' فایل وصل شد. در حال بازنشانی...', '#166534');
                            setTimeout(function(){ location.reload(); }, 700);
                        } else {
                            var extra = (res && res.errors && res.errors.length) ? ' — ' + res.errors.join('؛ ') : '';
                            setMsg('❌ ' + ((res && res.message) || 'اتصال انجام نشد.') + extra, '#b91c1c');
                        }
                    })
                    .catch(function(){ setMsg('خطا در ارتباط با سرور.', '#b91c1c'); });
            });

            // حذف اتصال فایل از این کیس
            document.addEventListener('click', function(e){
                var btn = e.target.closest && e.target.closest('.js-vc-unlink-file');
                if (!btn) return;
                e.preventDefault();
                if (!confirm('اتصال این فایل از این کیس حذف شود؟ (فایل در کیسِ اصلی خودش می‌ماند)')) return;
                var fd = new FormData();
                fd.append('case_id', btn.getAttribute('data-case') || caseId);
                fd.append('file_id', btn.getAttribute('data-file'));
                fd.append('_csrf_token', csrf);
                fetch('unlink_case_file_from_case.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd })
                    .then(function(r){ return r.json().catch(function(){ return { success:false }; }); })
                    .then(function(res){
                        if (res && res.success) location.reload();
                        else alert((res && res.message) || 'حذف اتصال انجام نشد.');
                    })
                    .catch(function(){ alert('خطا در ارتباط با سرور.'); });
            });
        })();
        </script>
        <?php endif; ?>

        <?php
        // ── فایل‌های مشترک/کتابخانه: فایل‌هایی که در «آپلود فایل» گذاشته شده و به این کیس
        //    وصل شده‌اند (هر فایل می‌تواند به چند کیس وصل باشد و در همه دیده شود).
        $caseSharedFiles = getUserUploadsForCase((int) $case['id']);
        // استخرِ فایل‌های کتابخانه برای اتصال: مدیر همهٔ فایل‌ها، کاربران دیگر فقط فایل‌های خودشان
        $attachPool = [];
        $pool = [];
        if (is_admin()) {
            $pool = db()->query('SELECT u.id, u.original_name, u.created_at, u.user_id, uu.full_name AS uploader_name FROM user_uploads u LEFT JOIN users uu ON uu.id = u.user_id ORDER BY u.created_at DESC')->fetchAll();
        } elseif ($canLinkFiles) {
            $stPool = db()->prepare('SELECT u.id, u.original_name, u.created_at, u.user_id, uu.full_name AS uploader_name FROM user_uploads u LEFT JOIN users uu ON uu.id = u.user_id WHERE u.user_id = ? ORDER BY u.created_at DESC');
            $stPool->execute([(int) $user['id']]);
            $pool = $stPool->fetchAll();
        }
        if (!empty($pool)) {
            $linkedIds = array_map('intval', array_column($caseSharedFiles, 'id'));
            $attachPool = array_values(array_filter($pool, fn($f) => !in_array((int) $f['id'], $linkedIds, true)));
        }
        if (!empty($caseSharedFiles) || !empty($attachPool)):
        ?>
        <div class="form-card" style="margin-top:20px;">
            <h4 style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; margin:0 0 8px;">
                <span>📎 فایل‌های مشترک این کیس (کتابخانه)</span>
                <?php if (count($caseSharedFiles)): ?>
                    <span class="badge" style="background:#ede9fe; color:#5b21b6;"><?= count($caseSharedFiles) ?> فایل</span>
                <?php endif; ?>
            </h4>
            <?php if (!empty($attachPool)): ?>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; background:#f5f3ff; border:1px dashed #c4b5fd; border-radius:8px; padding:8px 10px; margin-bottom:10px;">
                    <span style="font-weight:600; font-size:0.9rem;">اتصال فایل از کتابخانه به این کیس:</span>
                    <select id="vc-attach-pool" style="flex:1; min-width:220px; padding:6px 8px; border:1px solid #d1d5db; border-radius:6px;">
                        <option value="">انتخاب فایل…</option>
                        <?php foreach ($attachPool as $f): ?>
                            <option value="<?= (int) $f['id'] ?>"><?= htmlspecialchars($f['original_name']) ?><?= !empty($f['uploader_name']) ? ' — ' . htmlspecialchars($f['uploader_name']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="vc-attach-btn" class="btn" style="background:#0F172A; color:#fff;">اتصال</button>
                    <span id="vc-attach-msg" style="font-weight:bold;"></span>
                </div>
            <?php endif; ?>
            <?php if (empty($caseSharedFiles)): ?>
                <p class="empty">فایل مشترکی به این کیس وصل نشده است.</p>
            <?php else: ?>
                <table class="datatable display" style="width:100%; font-size:0.9rem;">
                    <thead>
                    <tr>
                        <th style="text-align:right;">فایل</th>
                        <th style="text-align:right;">فرستنده</th>
                        <th style="text-align:right;">توضیحات</th>
                        <th style="text-align:right;">اندازه</th>
                        <th style="text-align:right;">تاریخ</th>
                        <th style="text-align:right;">عملیات</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($caseSharedFiles as $sf): ?>
                        <tr>
                            <td><?= htmlspecialchars($sf['original_name']) ?></td>
                            <td><?= htmlspecialchars($sf['uploader_name'] ?? '—') ?></td>
                            <td style="white-space:pre-wrap; max-width:260px;"><?= htmlspecialchars($sf['description'] ?? '') ?: '—' ?></td>
                            <td><?= !empty($sf['size']) ? toPersianDigits(round((int) $sf['size'] / 1024)) . ' KB' : '—' ?></td>
                            <td data-order="<?= !empty($sf['created_at']) ? htmlspecialchars((string) $sf['created_at']) : '' ?>"><?= toJalaliDateTimeFormatted($sf['created_at']) ?></td>
                            <td class="actions" style="white-space:nowrap;">
                                <a class="btn" href="serve_user_upload.php?id=<?= (int) $sf['id'] ?>" target="_blank" style="background:#e0f2fe; color:#0369a1; padding:3px 8px; text-decoration:none;" title="باز کردن / پیش‌نمایش">باز کردن</a>
                                <a class="btn" href="download_user_upload.php?id=<?= (int) $sf['id'] ?>" style="background:#E5E7EB; color:#0F172A; padding:3px 8px; text-decoration:none;">دانلود</a>
                                <?php if (is_admin() || (int) ($sf['user_id'] ?? 0) === (int) $user['id']): ?>
                                    <button type="button" class="btn js-vc-unlink" data-upload="<?= (int) $sf['id'] ?>" style="background:#fee2e2; color:#991b1b; padding:3px 8px;" title="حذف اتصال این فایل از این کیس">✕</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            var csrf = '<?= htmlspecialchars($csrf_token) ?>';
            function vcPost(url, data, cb){
                var fd = new FormData();
                for (var k in data) fd.append(k, data[k]);
                fd.append('_csrf_token', csrf);
                fetch(url, { method:'POST', headers:{ 'X-CSRF-Token': csrf }, body: fd })
                    .then(function(r){ return r.json().catch(function(){ return {success:false}; }); })
                    .then(cb)
                    .catch(function(){ cb({success:false}); });
            }
            var attBtn = document.getElementById('vc-attach-btn');
            if (attBtn) attBtn.addEventListener('click', function(){
                var sel = document.getElementById('vc-attach-pool');
                var msg = document.getElementById('vc-attach-msg');
                if (!sel || !sel.value) return;
                vcPost('link_user_upload_to_case.php', { upload_id: sel.value, case_id: '<?= (int) $case['id'] ?>' }, function(r){
                    if (msg) { msg.textContent = r && r.success ? '✅ وصل شد' : '❌ خطا'; msg.style.color = r && r.success ? '#166534' : '#b91c1c'; }
                    if (r && r.success) setTimeout(function(){ location.reload(); }, 600);
                });
            });
            document.addEventListener('click', function(e){
                var unl = e.target.closest && e.target.closest('.js-vc-unlink');
                if (!unl) return;
                e.preventDefault();
                if (!confirm('اتصال این فایل از این کیس حذف شود؟ (فایل در کتابخانه می‌ماند)')) return;
                vcPost('unlink_user_upload_from_case.php', { upload_id: unl.getAttribute('data-upload'), case_id: '<?= (int) $case['id'] ?>' }, function(){ location.reload(); });
            });
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($canEditCase): ?>
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>

<link rel="stylesheet" href="../assets/css/case-teeth-picker.css">
<script src="../assets/js/case-teeth-picker.js"></script>
<script src="../assets/js/shade-picker.js"></script>
<div id="edit-case-modal" class="modal" style="display:none;">
    <div class="modal-content form-card" style="width:1000px; max-width:95%; padding:20px; box-sizing:border-box;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 style="margin:0;">✏️ ویرایش کیس #<?= (int) $case['id'] ?> - <?= htmlspecialchars($case['patient_name']) ?></h3>
            <button type="button" id="edit-case-close" class="btn" style="background:#E5E7EB; color:#0F172A; padding:4px 12px;">✕</button>
        </div>
        <form id="edit-case-form">
            <input type="hidden" name="id" value="<?= (int) $case['id'] ?>">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
                <div class="form-group">
                    <label>نوع کیس</label>
                    <select name="case_type" id="ec-case-type">
                        <option value="doctor" <?= ($case['case_type'] ?? 'doctor') === 'doctor' ? 'selected' : '' ?>>کیس دکتر</option>
                        <option value="lab_in" <?= ($case['case_type'] ?? '') === 'lab_in' ? 'selected' : '' ?>>کار از لابراتوار (ورودی)</option>
                        <option value="lab_out" <?= ($case['case_type'] ?? '') === 'lab_out' ? 'selected' : '' ?>>برون‌سپاری به لابراتوار</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>پزشک</label>
                    <select name="doctor_id">
                        <option value="">— انتخاب —</option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?= (int) $doctor['id'] ?>" <?= (int) ($case['doctor_id'] ?? 0) === (int) $doctor['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doctor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="ec-lab-group">
                    <label id="ec-lab-label">لابراتوار</label>
                    <select name="lab_id">
                        <option value="">— انتخاب —</option>
                        <?php foreach ($editLabs as $lab): ?>
                            <option value="<?= (int) $lab['id'] ?>" <?= (int) ($case['lab_id'] ?? 0) === (int) $lab['id'] ? 'selected' : '' ?>><?= htmlspecialchars($lab['full_name']) ?> (<?= htmlspecialchars($lab['role']) ?><?= !empty($lab['branch_name']) ? ' — ' . htmlspecialchars($lab['branch_name']) : '' ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>طراح</label>
                    <select name="designer_id" id="ec-designer-id">
                        <option value="">بدون طراح</option>
                        <?php foreach ($designers as $des): ?>
                            <option value="<?= (int) $des['id'] ?>" <?= (int) ($case['designer_id'] ?? 0) === (int) $des['id'] ? 'selected' : '' ?>><?= htmlspecialchars($des['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small id="ec-design-note" style="display:none; color:#0369a1; background:#e0f2fe; border-radius:6px; padding:4px 8px; margin-top:6px;"></small>
                </div>
                <div class="form-group">
                    <label>نام بیمار *</label>
                    <input type="text" name="patient_name" value="<?= htmlspecialchars($case['patient_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>شماره قبض</label>
                    <input type="text" name="receipt_number" value="<?= htmlspecialchars($case['receipt_number'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>خدمت</label>
                    <select name="service_id">
                        <option value="">— انتخاب —</option>
                        <?php foreach ($prices as $price): ?>
                            <option value="<?= (int) $price['id'] ?>" <?= (int) ($case['service_id'] ?? 0) === (int) $price['id'] ? 'selected' : '' ?>><?= htmlspecialchars($price['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>مکان</label>
                    <select name="location_type">
                        <option value="">— انتخاب —</option>
                        <option value="upper" <?= ($case['location_type'] ?? '') === 'upper' ? 'selected' : '' ?>>فک بالا</option>
                        <option value="lower" <?= ($case['location_type'] ?? '') === 'lower' ? 'selected' : '' ?>>فک پایین</option>
                        <option value="both" <?= ($case['location_type'] ?? '') === 'both' ? 'selected' : '' ?>>هر دو فک</option>
                        <option value="teeth" <?= ($case['location_type'] ?? '') === 'teeth' ? 'selected' : '' ?>>دندان</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>سایه (رنگ استاندارد)</label>
                    <div class="shade-picker" data-target="#ec-shade">
                        <input type="hidden" id="ec-shade" name="shade" value="<?= htmlspecialchars($case['shade'] ?? '') ?>">
                        <div class="shade-groups">
                            <?php foreach (caseShadeGroups() as $shGroup => $shRow): ?>
                                <div class="shade-group" aria-label="<?= htmlspecialchars($shGroup) ?>">
                                    <?php foreach ($shRow as $shCode => $shHex): ?>
                                        <button type="button" class="shade-swatch" data-shade="<?= htmlspecialchars($shCode) ?>" style="--sw:<?= $shHex ?>" title="<?= htmlspecialchars($shCode) ?>"><span class="shade-code"><?= htmlspecialchars($shCode) ?></span></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="shade-group">
                                <button type="button" class="shade-swatch shade-clear" data-shade="" title="بدون سایه"><span class="shade-code">—</span></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="grid-column:1 / -1;">
                    <label>دندان</label>
                    <div id="case-teeth-picker" class="case-teeth-picker" aria-label="انتخاب دندان‌ها"></div>
                    <input type="hidden" id="case-teeth" name="teeth" value="<?= htmlspecialchars($case['teeth'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>تعداد <small style="color:#64748b; font-weight:400;">(خودکار)</small></label>
                    <input type="number" name="quantity" min="1" value="<?= (int) ($case['quantity'] ?? 1) ?>" readonly style="background:#f3f4f6; cursor:not-allowed;">
                </div>
                <div class="form-group">
                    <label>فی واحد (تومان)</label>
                    <input type="number" name="unit_price" min="0" step="1" value="<?= (int) ($case['unit_price'] ?? 0) ?>">
                </div>
                <div class="form-group">
                    <label>هزینه طراحی (تومان)</label>
                    <input type="number" name="design_fee" id="ec-design-fee" min="0" step="1" value="<?= (int) ($case['design_fee'] ?? 0) ?>">
                    <small id="ec-design-fee-note" style="display:none; color:#b45309; background:#fffbeb; border-radius:6px; padding:4px 8px; margin-top:6px;"></small>
                </div>
                <div class="form-group">
                    <label>تاریخ دریافت</label>
                    <input type="text" id="ec-received-date" name="received_date" value="<?= htmlspecialchars(toJalaliDateFormatted($case['received_date'] ?? date('Y-m-d'))) ?>" style="cursor:pointer;">
                </div>
                <div class="form-group">
                    <label>وضعیت</label>
                    <select name="status_id">
                        <?php foreach ($statuses as $st): ?>
                            <option value="<?= (int) $st['id'] ?>" <?= (int) ($case['status_id'] ?? 0) === (int) $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1 / -1; border:1px solid #bbf7d0; border-radius:8px; overflow:hidden; padding:0; margin-bottom:0;">
                    <button type="button" id="ec-side-outsource-toggle" class="btn" style="width:100%; background:#f0fdf4; color:#15803d; border:none; border-radius:0; text-align:right; display:flex; justify-content:space-between; align-items:center; padding:11px 14px; font-weight:700; cursor:pointer;">
                        <span>🔄 برون‌سپاری جانبی (بدهی به لابراتوار)</span>
                        <span class="ec-side-caret" style="font-size:0.8rem;">▾</span>
                    </button>
                    <div id="ec-side-outsource-body" style="display:none; background:#f0fdf4; padding:12px;">
                        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                            <div style="flex:1; min-width:160px;">
                                <label>برون‌سپاری جانبی: لابراتوار</label>
                                <select name="outsourced_lab_id">
                                    <option value="">ندارد</option>
                                    <?php foreach ($editOutLabs as $lab): ?>
                                        <option value="<?= (int) $lab['id'] ?>" <?= (int) ($case['outsourced_lab_id'] ?? 0) === (int) $lab['id'] ? 'selected' : '' ?>><?= htmlspecialchars($lab['full_name']) ?><?= !empty($lab['branch_name']) ? ' (' . htmlspecialchars($lab['branch_name']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="flex:1; min-width:160px;">
                                <label>برون‌سپاری جانبی: خدمت</label>
                                <select name="outsourced_service_id">
                                    <option value="">— انتخاب —</option>
                                    <?php foreach ($prices as $price): ?>
                                        <option value="<?= (int) $price['id'] ?>" <?= (int) ($case['outsourced_service_id'] ?? 0) === (int) $price['id'] ? 'selected' : '' ?>><?= htmlspecialchars($price['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="width:110px;">
                                <label>تعداد برون‌سپاری</label>
                                <input type="number" name="outsourced_qty" min="0" value="<?= (int) ($case['outsourced_qty'] ?? 0) ?>">
                            </div>
                            <div style="width:140px;">
                                <label>نرخ برون‌سپاری (تومان)</label>
                                <input type="number" id="ec-outsourced-rate" name="outsourced_rate" min="0" step="1" value="<?= isset($case['outsourced_rate']) && $case['outsourced_rate'] !== null ? (float) $case['outsourced_rate'] : '' ?>" placeholder="خودکار از نرخ‌ها">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="grid-column:1 / -1;">
                    <label>توضیحات</label>
                    <textarea name="description" rows="3" style="width:100%;"><?= htmlspecialchars($case['description'] ?? '') ?></textarea>
                </div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
                <button type="button" id="edit-case-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
                <button type="submit" id="edit-case-save" class="btn" style="background:#06B6D4; color:#fff;">💾 ذخیره تغییرات</button>
            </div>
            <div id="edit-case-msg" style="margin-top:10px; font-weight:bold;"></div>
        </form>
    </div>
</div>

<script>
(function(){
    var modal = document.getElementById('edit-case-modal');
    var openBtn = document.getElementById('edit-case-btn');
    var closeBtn = document.getElementById('edit-case-close');
    var cancelBtn = document.getElementById('edit-case-cancel');
    var form = document.getElementById('edit-case-form');
    var msgEl = document.getElementById('edit-case-msg');
    var csrf = window.CSRF_TOKEN || '<?= htmlspecialchars($csrf_token) ?>';
    var dp = null;
    var originalTeeth = <?= json_encode($case['teeth'] ?? '') ?>;
    var originalShade = <?= json_encode((string) ($case['shade'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    var shadeInput = document.getElementById('ec-shade');

    // سایه را به مقدار اولیهٔ کیس برگردان (و رنگِ فعالِ سواچ‌ها را هم به‌روز کن)
    function ecRestoreShade(){
        if (shadeInput) shadeInput.value = originalShade;
        if (window.ShadePickerSync) window.ShadePickerSync();
    }

    // ── پیش‌فرضِ طراح و هزینهٔ طراحی بر اساس خدمت ──
    // خدمات بدون طراحی (design_required = 0) مثل پست NPG / پرینت کست / الاینر شفاف:
    // کیس به‌صورت پیش‌فرض بدون طراح و با هزینهٔ طراحیِ ۰ ثبت می‌شود.
    var SVC_DESIGN_REQUIRED = <?= json_encode(
        array_map(function ($p) { return (int) ($p['design_required'] ?? 1); }, array_column($prices, null, 'id')),
        JSON_UNESCAPED_UNICODE
    ) ?>;
    var DEFAULT_DESIGNER_ID = <?= (int) $defaultDesignerId ?>;
    var ecDesignerEl = document.getElementById('ec-designer-id');
    var ecDesignFeeEl = document.getElementById('ec-design-fee');
    var ecDesignNoteEl = document.getElementById('ec-design-note');
    var ecDesignFeeNoteEl = document.getElementById('ec-design-fee-note');

    function ecServiceEl(){ return document.querySelector('#edit-case-form [name="service_id"]'); }
    function ecServiceRequiresDesign(){
        var el = ecServiceEl();
        var v = el ? el.value : '';
        if (!v) return true;
        return (SVC_DESIGN_REQUIRED[v] === undefined) ? true : !!SVC_DESIGN_REQUIRED[v];
    }
    function ecSetNote(el, text){
        if (!el) return;
        el.textContent = text || '';
        el.style.display = text ? 'block' : 'none';
    }
    function ecApplyServiceDesignDefault(serviceChanged){
        if (!ecServiceRequiresDesign()) {
            if (serviceChanged && ecDesignerEl) ecDesignerEl.value = '';
            ecSetNote(ecDesignNoteEl, 'برای این خدمت طراحی لازم نیست؛ کیس به‌صورت پیش‌فرض بدون طراح و با هزینهٔ طراحیِ ۰ ثبت می‌شود.');
        } else {
            ecSetNote(ecDesignNoteEl, '');
            if (serviceChanged && ecDesignerEl && !ecDesignerEl.value && DEFAULT_DESIGNER_ID) {
                ecDesignerEl.value = String(DEFAULT_DESIGNER_ID);
            }
        }
    }
    function ecReloadDesignFee(){
        if (!ecDesignFeeEl) return;
        if (!ecServiceRequiresDesign()) { ecDesignFeeEl.value = 0; ecSetNote(ecDesignFeeNoteEl, ''); return; }
        var designerId = ecDesignerEl ? ecDesignerEl.value : '';
        var svcEl = ecServiceEl();
        var serviceId = svcEl ? svcEl.value : '';
        if (!designerId) { ecDesignFeeEl.value = 0; ecSetNote(ecDesignFeeNoteEl, ''); return; }
        if (!serviceId) { ecSetNote(ecDesignFeeNoteEl, ''); return; }
        fetch('get_price.php?doctor_id=' + encodeURIComponent(designerId) + '&service_id=' + encodeURIComponent(serviceId) + '&price_type=design_fee', { headers: { 'Accept': 'application/json' } })
            .then(function(r){ return r.json(); })
            .then(function(resp){
                if (!resp || resp.price === null) {
                    ecSetNote(ecDesignFeeNoteEl, '⚠️ برای این طراح و خدمت نرخی ثبت نشده است؛ هزینهٔ طراحی ۰ می‌ماند. مبلغ درست را دستی وارد یا در «نقشه قیمت‌گذاری» ثبت کنید.');
                    return;
                }
                var qtyEl = document.querySelector('#edit-case-form [name="quantity"]');
                var qty = parseInt(qtyEl ? qtyEl.value : '1', 10) || 1;
                ecDesignFeeEl.value = Math.round(parseFloat(resp.price) * qty);
                ecSetNote(ecDesignFeeNoteEl, '');
            })
            .catch(function(){});
    }
    var ecServiceField = ecServiceEl();
    if (ecServiceField) ecServiceField.addEventListener('change', function(){ ecApplyServiceDesignDefault(true); ecReloadDesignFee(); });
    if (ecDesignerEl) ecDesignerEl.addEventListener('change', ecReloadDesignFee);
    ecApplyServiceDesignDefault(false);

    // ── Teeth ↔ location ↔ quantity auto logic (edit modal) ──
    function ecCountTeeth(v){
        if (!v) return 0;
        var n = 0;
        String(v).split(',').forEach(function(g){ g.split('_').forEach(function(t){ if (String(t).trim()) n++; }); });
        return n;
    }
    function ecUpdateTeethForLocation(){
        var loc = document.querySelector('#edit-case-form [name="location_type"]');
        var picker = document.getElementById('case-teeth-picker');
        if (!picker) return;
        var lv = loc ? loc.value : '';
        if (lv === 'upper' || lv === 'lower' || lv === 'both') {
            // A whole jaw → no individual teeth selectable
            picker.classList.add('is-disabled');
            if (window.CaseTeethPicker) { CaseTeethPicker.reset(); }
        } else {
            picker.classList.remove('is-disabled');
        }
    }
    function ecUpdateQuantity(){
        var loc = document.querySelector('#edit-case-form [name="location_type"]');
        var qty = document.querySelector('#edit-case-form [name="quantity"]');
        if (!qty) return;
        var lv = loc ? loc.value : '';
        if (lv === 'both') { qty.value = 2; }
        else if (lv === 'upper' || lv === 'lower') { qty.value = 1; }
        else {
            var v = (window.CaseTeethPicker ? CaseTeethPicker.getValue() : '') || '';
            var n = ecCountTeeth(v);
            qty.value = n > 0 ? n : 1;
        }
    }
    document.addEventListener('change', function(e){
        if (e.target && e.target.name === 'location_type') {
            ecUpdateTeethForLocation();
            ecUpdateQuantity();
        }
    });
    document.addEventListener('click', function(e){
        if (e.target.closest && e.target.closest('#case-teeth-picker .case-tooth-button, #case-teeth-picker .case-bridge-key, #case-teeth-picker .case-teeth-picker__clear')) {
            setTimeout(ecUpdateQuantity, 10);
        }
    });

    function toggleLabGroup(){
        var typeSel = document.getElementById('ec-case-type');
        var labLabel = document.getElementById('ec-lab-label');
        var labGroup = document.getElementById('ec-lab-group');
        if (!labGroup) return;
        // For doctor-type cases the lab field is not needed.
        if (typeSel && typeSel.value === 'doctor') labGroup.style.display = 'none';
        else labGroup.style.display = '';
        if (labLabel && typeSel){
            if (typeSel.value === 'lab_in') labLabel.textContent = 'لابراتوار همکار (پرداخت‌کننده)';
            else if (typeSel.value === 'lab_out') labLabel.textContent = 'لابراتوار برون‌سپاری (گیرنده کار)';
            else labLabel.textContent = 'لابراتوار';
        }
    }
    document.addEventListener('change', function(e){
        if (e.target && e.target.id === 'ec-case-type') toggleLabGroup();
    });

    // Auto-fill the side-outsourcing rate from outsource_rates when lab+service are chosen.
    var ecOsLab = null, ecOsSvc = null;
    function ecFetchOutsourceRate(){
        ecOsLab = document.querySelector('[name="outsourced_lab_id"]');
        ecOsSvc = document.querySelector('[name="outsourced_service_id"]');
        var rateInput = document.getElementById('ec-outsourced-rate');
        if (!ecOsLab || !ecOsSvc || !rateInput) return;
        var lab = ecOsLab.value, svc = ecOsSvc.value;
        if (!lab || !svc) return;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'get_outsource_rate.php?lab_id=' + encodeURIComponent(lab) + '&service_id=' + encodeURIComponent(svc), true);
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function(){
            var resp = null;
            try { resp = JSON.parse(xhr.responseText); } catch(e){}
            if (resp && resp.rate != null) {
                rateInput.value = resp.rate;
            } else if (rateInput.value === '' || rateInput.value == null) {
                // No dedicated rate and nothing saved → default to 0 (manually editable).
                rateInput.value = 0;
            }
        };
        xhr.send();
    }
    document.addEventListener('change', function(e){
        if (e.target && (e.target.name === 'outsourced_lab_id' || e.target.name === 'outsourced_service_id')) {
            ecFetchOutsourceRate();
        }
    });

    function openModal(){
        if (!modal) return;
        modal.style.display = 'flex';
        if (msgEl) msgEl.textContent = '';
        if (window.CaseTeethPicker) { CaseTeethPicker.setValue(originalTeeth); }
        toggleLabGroup();
        ecRestoreShade();
        ecUpdateTeethForLocation();
        ecUpdateQuantity();
        if (typeof $.fn !== 'undefined' && $.fn.persianDatepicker) {
            try {
                var $rec = $('#ec-received-date');
                // مقدار معتبر «تاریخ دریافت» از سرور (دیتابیس). هنگام هر بار باز شدنِ مودال
                // دوباره اعمال می‌شود تا پلاگین تقویم نتواند آن را به «امروز» تغییر دهد.
                var recOrig = '<?= htmlspecialchars(toJalaliDateFormatted($case['received_date'] ?? ''), ENT_QUOTES) ?>';
                if (!dp) {
                    dp = $rec.persianDatepicker({
                        format: 'YYYY/MM/DD',
                        calendarType: 'persian',
                        initialValue: false,
                        persianDigit: true,
                        autoClose: true
                    });
                }
                if (recOrig) {
                    $rec.val(recOrig);
                }
            } catch(e) {}
        }
    }
    function closeModal(){
        if (modal) modal.style.display = 'none';
        if (window.CaseTeethPicker) { CaseTeethPicker.setValue(originalTeeth); }
        // با بستن فرم، سایهٔ انتخاب‌شده (که ذخیره نشده) باید به مقدار اصلیِ کیس برگردد
        ecRestoreShade();
    }

    if (openBtn) openBtn.addEventListener('click', function(e){ e.preventDefault(); openModal(); });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

    // Toggle the collapsible side-outsourcing section (robust open/close)
    (function(){
        var ecSoOpen = false;
        var ecSoBtn = document.getElementById('ec-side-outsource-toggle');
        var ecSoBody = document.getElementById('ec-side-outsource-body');
        var ecSoCaret = document.querySelector('.ec-side-caret');
        if (ecSoBtn && ecSoBody) ecSoBtn.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            ecSoOpen = !ecSoOpen;
            ecSoBody.style.display = ecSoOpen ? 'block' : 'none';
            if (ecSoCaret) ecSoCaret.textContent = ecSoOpen ? '▴' : '▾';
        });
    })();

    if (form) form.addEventListener('submit', function(e){
        e.preventDefault();
        if (msgEl) { msgEl.textContent = 'در حال ذخیره...'; msgEl.style.color = '#0F172A'; }
        var saveBtn = document.getElementById('edit-case-save');
        if (saveBtn) saveBtn.disabled = true;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'save_case.php', true);
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function(){
            var resp = null;
            try { resp = JSON.parse(xhr.responseText); } catch(e){}
            if (xhr.status === 200 && resp && resp.success) {
                if (msgEl) { msgEl.textContent = '✅ ذخیره شد. در حال بازنشانی صفحه...'; msgEl.style.color = '#166534'; }
                setTimeout(function(){ location.reload(); }, 500);
            } else {
                var errMsg = 'خطا در ذخیره تغییرات.';
                if (resp && resp.message) errMsg = resp.message;
                else if (resp && resp.error) errMsg = 'خطا: ' + resp.error;
                if (resp && Array.isArray(resp.errors) && resp.errors.length) errMsg += ' — ' + resp.errors.join('؛ ');
                if (resp && Array.isArray(resp.upload_errors) && resp.upload_errors.length) errMsg += ' — ' + resp.upload_errors.join('؛ ');
                if (msgEl) {
                    msgEl.innerHTML = '❌ ' + String(errMsg).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; });
                    msgEl.style.color = '#b91c1c';
                }
                if (saveBtn) saveBtn.disabled = false;
            }
        };
        xhr.onerror = function(){
            if (msgEl) { msgEl.textContent = 'خطا در اتصال به سرور.'; msgEl.style.color = '#b91c1c'; }
            if (saveBtn) saveBtn.disabled = false;
        };
        var fd = new FormData(form);
        xhr.send(fd);
    });
})();
</script>
<?php endif; ?>

<?php panel_layout_end(); ?>