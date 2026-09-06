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
$doctorId = $isDoctor ? $user['id'] : null;

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
    $params = [$id];
    if ($isDoctor) {
        $sql .= ' AND c.doctor_id = ?';
        $params[] = $doctorId;
    } elseif ($isDesigner) {
        $sql .= ' AND c.designer_id = ?';
        $params[] = $user['id'];
    } elseif (!has_permission('view_all_cases')) {
        die('دسترسی غیرمجاز');
    }
    // Branch scoping: any branch-scoped user (including branch_admin) may only
    // access cases of their own branch or where their branch is the partner.
    // مدیر کل (نقش admin) نیز به‌عنوان شعبهٔ مرکزی (۱) رفتار می‌کند.
    if (is_branch_scoped() || is_root_admin()) {
        $scopeBranch = currentBranchId();
        if ($scopeBranch === null) $scopeBranch = 1;
        $bScope = branchCaseScope('c', $scopeBranch);
        $sql .= ' AND ' . $bScope['sql'];
        $params = array_merge($params, $bScope['params']);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $case = $stmt->fetch();

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

$statuses = db()->query('SELECT * FROM case_statuses ORDER BY name ASC')->fetchAll();
$designers = getAllDesigners();
$labs = getAllLabs();
$doctors = getAllDoctors();
$prices = getAllPrices();
// Whether the current user may edit this case (admin / staff / secretary …)
$canEditCase = has_role('admin') || has_role('branch_admin') || has_permission('edit_cases');

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
                || ($isDesigner && designerCanAccessUser($did))
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
        // Fetch the partner branch name for display
        $partnerBranchName = '';
        if (($inboundPartner || $isLabInCross || $outboundPartner) && !empty($case['source_branch_id'])) {
            $pb = db()->prepare('SELECT name FROM branches WHERE id = ?');
            $pb->execute([(int) $case['source_branch_id']]);
            $partnerBranchName = (string) $pb->fetchColumn();
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

        <table class="case-info-table" style="width:100%; border-collapse:collapse; margin:12px 0;">
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>نوع کیس:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;">
                    <?= htmlspecialchars($caseTypeLabel) ?>
                    <?php if ($branchBadge): ?> <?= $branchBadge ?><?php endif; ?>
                </td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>پزشک:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?php if ($canViewDoctorProfile): ?><a href="doctor_view.php?id=<?= (int) $case['doctor_id'] ?>"><?= htmlspecialchars($case['doctor_name'] ?? '—') ?></a><?php else: ?><?= htmlspecialchars($case['doctor_name'] ?? '—') ?><?php endif; ?></td>
            </tr>
            <?php if ($branchBadge && $partnerBranchName): ?>
            <tr style="background:#f8fafc;">
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong><?= htmlspecialchars($branchLabel) ?>:</strong></td>
                <td colspan="3" style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($partnerBranchName) ?></td>
            </tr>
            <?php endif; ?>
            <?php if (!$hideFinancial && ($inboundPartner || $outboundPartner)): $crossAmt = getInboundReceivableAmount($case); ?>
            <tr style="background:<?= $inboundPartner ? '#f0fdf4' : '#fffbeb' ?>;">
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong><?= $inboundPartner ? 'طلب ما از شعبه مبدا' : 'بدهی ما به شعبه گیرنده' ?>:</strong></td>
                <td colspan="3" style="padding:6px 8px; border-bottom:1px solid #eee; color:<?= $inboundPartner ? '#166534' : '#92400e' ?>; font-weight:bold;">
                    <?= formatAmountToman($crossAmt) ?> تومان
                    <small style="font-weight:normal; color:#525252; display:block; margin-top:2px;">
                        <?= $inboundPartner ? 'این مبلغ همان هزینه برون‌سپاری است که شعبه مبدا برای این کیس به ما پرداخت می‌کند.' : 'این مبلغ همان هزینه برون‌سپاری است که ما برای این کیس به شعبه گیرنده می‌پردازیم.' ?>
                    </small>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>شماره قبض:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['receipt_number'] ?? '—') ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>خدمت:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['service_title'] ?? '—') ?></td>
            </tr>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>تاریخ دریافت:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars(toJalaliDateFormatted($case['received_date'])) ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>مکان / دندان:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars(formatCaseLocation($case['location_type'], $case['teeth'])) ?></td>
            </tr>
            <?php if (($case['location_type'] ?? '') === 'teeth' && !empty($case['teeth'])): ?>
            <tr>
                <td colspan="4" style="padding:6px 8px; border-bottom:1px solid #eee;">
                    <strong>دندان‌های انتخاب‌شده:</strong>
                    <div style="margin-top:8px;"><?= renderTeethChart($case['teeth']) ?></div>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>سایه:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['shade'] ?? '—') ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>تعداد:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= toPersianDigits((int)($case['quantity'] ?? 1)) ?></td>
            </tr>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>وضعیت:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><span class="badge"><?= htmlspecialchars($case['status_name'] ?? '—') ?></span></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"></td>
            </tr>
            <?php if (!$isRestricted && !$isDesigner): ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>لابراتوار:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['lab_name'] ?? '—') ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"></td>
            </tr>
            <?php endif; ?>
            <?php if (canSeeDesignerInfo()): ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>طراح:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['designer_name'] ?? '—') ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"></td>
            </tr>
            <?php endif; ?>
            <?php if (!$isRestricted && !empty($case['outsourced_lab_name']) && !empty($case['outsourced_qty'])): ?>
            <tr style="background:#f0fdf4;">
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>برون‌سپاری جانبی:</strong></td>
                <td colspan="3" style="padding:6px 8px; border-bottom:1px solid #eee;">
                    <?= htmlspecialchars($case['outsourced_lab_name']) ?>
                    — <?= htmlspecialchars($case['outsourced_service_title'] ?? 'خدمت') ?>
                    (تعداد: <?= toPersianDigits((int) $case['outsourced_qty']) ?>)
                    <?php if (isset($case['outsourced_rate']) && $case['outsourced_rate'] !== null): ?>
                        — نرخ: <?= toPersianDigits(number_format((float) $case['outsourced_rate'])) ?> تومان
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php if (!$hideFinancial): ?>
            <?php if ($viewerProviderFee !== null): ?>
            <tr style="background:#f0fdf4;">
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>سهم لابراتوار (برون‌سپاری):</strong></td>
                <td colspan="3" style="padding:6px 8px; border-bottom:1px solid #eee; color:#166534; font-weight:bold;">
                    <?= formatAmountToman($viewerProviderFee) ?> تومان
                    <small style="font-weight:normal; color:#525252; display:block; margin-top:2px;">مبلغ قابل دریافت بابت انجام این کیس (نرخ برون‌سپاری × تعداد).</small>
                </td>
            </tr>
            <?php else: ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>فی (تومان):</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= $case['unit_price'] ? formatAmountToman($case['unit_price']) : '—' ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>هزینه طراحی:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= !empty($case['design_fee']) ? formatAmountToman($case['design_fee']) : '—' ?></td>
            </tr>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>جمع کل:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= $case['total_price'] ? formatAmountToman($case['total_price']) : '—' ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"></td>
            </tr>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($case['doctor_notes'])): ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>توضیحات پزشک:</strong></td>
                <td colspan="3" style="padding:6px 8px; border-bottom:1px solid #eee;"><div style="white-space:pre-wrap; direction:rtl; text-align:right; unicode-bidi:plaintext; line-height:1.9;"><?= nl2br(htmlspecialchars($case['doctor_notes'])) ?></div></td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($case['description'])): ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>توضیحات:</strong></td>
                <td colspan="3" style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['description']) ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php
        $allowedStatusIds = getAllowedStatusIdsForUser();
        $canChangeStatus = has_role('admin') || has_permission('edit_case_status') || has_permission('update_case_status');
        if ($canChangeStatus):
        ?>
        <div class="form-card" style="margin-top:20px;">
            <h4>تغییر وضعیت کیس</h4>
            <form id="case-status-form" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px;">
                <input type="hidden" name="case_id" value="<?= (int) $case['id'] ?>">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <select id="case-status-change" name="status_id" style="min-width:220px;">
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

        <?php if (canSeeDesignerInfo() && (has_role('admin') || has_permission('edit_cases'))): ?>
        <div class="form-card" style="margin-top:20px;">
            <h4>تغییر طراح کیس</h4>
            <?php if (!empty($case['designer_invoice_id'])): ?>
                <p style="color:#b91c1c;">این کیس قبلاً در فاکتور طراحی ثبت شده و نمی‌توان طراح آن را تغییر داد.</p>
            <?php else: ?>
            <form id="case-change-designer-form" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px;">
                <input type="hidden" name="case_id" value="<?= (int) $case['id'] ?>">
                <select id="case-change-designer-select" style="min-width:220px;">
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

        <?php if ($parentCase): ?>
            <p><strong>کیس اصلی:</strong> <a href="view_case.php?id=<?= $parentCase['id'] ?>">#<?= $parentCase['id'] ?> - <?= htmlspecialchars($parentCase['patient_name']) ?> (<?= htmlspecialchars($parentCase['service_title']) ?>)</a></p>
        <?php endif; ?>

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
            <?php if (!empty($caseComments)): ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($caseComments as $comment): ?>
                        <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                                <strong><?= htmlspecialchars($comment['user_name'] ?? 'کاربر') ?></strong>
                                <span style="font-size:0.8rem; color:#6b7280;"><?= toJalaliDateFormatted($comment['created_at']) ?></span>
                            </div>
                            <div style="white-space:pre-wrap; line-height:1.8;"><?= htmlspecialchars($comment['message']) ?></div>
                            <?php if ((int) $comment['user_id'] === (int) $user['id'] || has_role('admin')): ?>
                                <form method="post" action="save_comment.php" style="margin-top:8px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="comment_id" value="<?= (int) $comment['id'] ?>">
                                    <button type="submit" class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px; font-size:0.8rem;">حذف</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty">هنوز پیامی ثبت نشده است.</p>
            <?php endif; ?>
        </div>

        <div class="form-card" style="margin-top:20px;">
            <h4>تاریخچه فعالیت‌های کیس</h4>
            <?php $activityLog = getCaseActivityLog($case['id']); ?>
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
                            <span style="color:#6b7280; font-size:0.78rem;"><?= toJalaliDateFormatted($log['created_at']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty">هنوز فعالیتی برای این کیس ثبت نشده است.</p>
            <?php endif; ?>
        </div>

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

        <?php if (has_role('admin') || has_permission('upload_design_files') || has_permission('upload_files') || has_permission('edit_cases')): ?>
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
                    } else {
                        if (progressWrap) progressWrap.style.display = 'none';
                        if (msgEl) { msgEl.textContent = 'خطا در آپلود: ' + ((resp && resp.errors && resp.errors.length) ? resp.errors.join(', ') : 'نامشخص'); msgEl.style.color = '#b91c1c'; }
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
                        <div style="font-size:0.72rem; color:#6b7280; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <?php if (!empty($f['uploader_name'])): ?><span>👤 <?= htmlspecialchars($f['uploader_name']) ?></span><?php endif; ?>
                            <?php if (!empty($f['size'])): ?><span>💾 <?= formatFileSize($f['size']) ?></span><?php endif; ?>
                            <span><?= $typeBadge ?></span>
                        </div>
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
    <?php endif; ?>
</div>

<?php if ($canEditCase): ?>
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>

<link rel="stylesheet" href="../assets/css/case-teeth-picker.css">
<script src="../assets/js/case-teeth-picker.js"></script>
<div id="edit-case-modal" class="modal" style="display:none;">
    <div class="modal-content form-card" style="width:820px; max-width:95%; padding:20px; box-sizing:border-box;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 style="margin:0;">✏️ ویرایش کیس #<?= (int) $case['id'] ?> - <?= htmlspecialchars($case['patient_name']) ?></h3>
            <button type="button" id="edit-case-close" class="btn" style="background:#E5E7EB; color:#0F172A; padding:4px 12px;">✕</button>
        </div>
        <form id="edit-case-form">
            <input type="hidden" name="id" value="<?= (int) $case['id'] ?>">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
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
                        <?php foreach ($labs as $lab): ?>
                            <option value="<?= (int) $lab['id'] ?>" <?= (int) ($case['lab_id'] ?? 0) === (int) $lab['id'] ? 'selected' : '' ?>><?= htmlspecialchars($lab['full_name']) ?> (<?= htmlspecialchars($lab['role']) ?><?= !empty($lab['branch_name']) ? ' — ' . htmlspecialchars($lab['branch_name']) : '' ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>طراح</label>
                    <select name="designer_id">
                        <option value="">بدون طراح</option>
                        <?php foreach ($designers as $des): ?>
                            <option value="<?= (int) $des['id'] ?>" <?= (int) ($case['designer_id'] ?? 0) === (int) $des['id'] ? 'selected' : '' ?>><?= htmlspecialchars($des['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="form-group" style="grid-column:1 / -1;">
                    <label>دندان</label>
                    <div id="case-teeth-picker" class="case-teeth-picker" aria-label="انتخاب دندان‌ها"></div>
                    <input type="hidden" id="case-teeth" name="teeth" value="<?= htmlspecialchars($case['teeth'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>سایه</label>
                    <input type="text" name="shade" value="<?= htmlspecialchars($case['shade'] ?? '') ?>">
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
                    <input type="number" name="design_fee" min="0" step="1" value="<?= (int) ($case['design_fee'] ?? 0) ?>">
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
                                    <?php foreach ($labs as $lab): ?>
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
    function closeModal(){ if (modal) modal.style.display = 'none'; if (window.CaseTeethPicker) { CaseTeethPicker.setValue(originalTeeth); } }

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
                if (msgEl) { msgEl.textContent = errMsg; msgEl.style.color = '#b91c1c'; }
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