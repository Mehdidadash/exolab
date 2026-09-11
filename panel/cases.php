<?php
// panel/cases.php
require_once __DIR__ . '/auth.php';
require_login();

if (!has_permission('view_all_cases') && !has_permission('view_own_cases') && !has_permission('view_assigned_cases') && !has_permission('view_clinic_cases') && !has_role('designer')) {
    die('دسترسی غیرمجاز');
}

// CSRF token
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];
// Ensure session is saved before AJAX calls read it
session_write_close();

$doctors = getAllDoctors(); // now returns from users table
$statusesStmt = db()->query('SELECT * FROM case_statuses ORDER BY name ASC');
$statuses = $statusesStmt->fetchAll();
$allowedStatusIds = getAllowedStatusIdsForUser();
$user = current_user();
$isDoctor = ($user['role'] === 'doctor');
$isDesigner = ($user['role'] === 'designer');
// کاربرانی که «ستون چک‌باکسِ انتخاب» را در لیست کیس‌ها می‌بینند تا بتوانند با دکمه‌های
// گروهی (پرینت برچسب، تغییر وضعیت گروهی، خروجی CSV، دانلود...) کار کنند — بسته به دسترسی‌شان.
$canSelectCases = in_array($user['role'] ?? '', ['admin', 'branch_admin', 'staff', 'secretary', 'technician', 'designer', 'outsource_lab', 'partner_lab', 'customer_lab', 'lab'], true)
    || has_permission('batch_print_labels') || has_permission('batch_update_status')
    || has_permission('export_csv') || has_permission('edit_cases')
    || has_permission('upload_files') || has_permission('upload_design_files') || has_permission('delete_files');
$designers = getAllDesigners();
$defaultDesigner = getDefaultDesigner();
$defaultDesignerId = $defaultDesigner ? (int) $defaultDesigner['id'] : 0;
$prices = getAllPrices();
// At the very top, after require_once
date_default_timezone_set('Asia/Tehran');

// Default: 30 days ago
$defaultDateFromGregorian = date('Y-m-d', strtotime('-30 days'));
$defaultDateFromJalali = toJalaliDateFormatted($defaultDateFromGregorian);

// Filters
$filterDoctorId = !empty($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : 0;
$filterStatusId = !empty($_GET['status_id']) ? (int) $_GET['status_id'] : 0;
$filterDesignerId = !empty($_GET['designer_id']) ? (int) $_GET['designer_id'] : 0;
$filterServiceId = !empty($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
$filterShade = trim((string) ($_GET['shade'] ?? ''));

// Parse date_from
if (!empty($_GET['date_from'])) {
    $filterDateFrom = parseJalaliToGregorian($_GET['date_from']);
    if ($filterDateFrom === '') {
        $filterDateFrom = $defaultDateFromGregorian;
    }
} else {
    $filterDateFrom = $defaultDateFromGregorian;
}

// Parse date_to
// Set date_to to today if not provided
if (empty($_GET['date_to'])) {
    $filterDateTo = date('Y-m-d');
} else {
    $filterDateTo = parseJalaliToGregorian($_GET['date_to']);
    if ($filterDateTo === '') $filterDateTo = date('Y-m-d');
}

// When creating a SUB-CASE (?add_sub=PARENT_ID) we prefill the new case's form
// from the parent case to save the user's time.
$addSubParent = null;
if (!empty($_GET['add_sub'])) {
    $ps = db()->prepare('SELECT c.*, p.title AS service_title FROM cases c LEFT JOIN site_prices p ON c.service_id = p.id WHERE c.id = ?');
    $ps->execute([(int) $_GET['add_sub']]);
    $addSubParent = $ps->fetch();
    if ($addSubParent) {
        $addSubParent['received_date_jalali'] = toJalaliDateFormatted($addSubParent['received_date'] ?? date('Y-m-d'));
    }
}

panel_layout_start('مدیریت کیس‌ها');

?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="cases.php">لیست کیس‌ها</a>
        <?php if (has_permission('create_cases')): ?>
            <a id="add-case-btn" class="btn" href="#" style="background: #0F172A; color: #fff;">افزودن کیس جدید</a>
        <?php endif; ?>
    </div>
</div>

<div class="form-card" style="margin-bottom: 20px;">
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-bottom: 12px;">
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" id="toggle-filters-btn" class="btn" style="background: #0891b2; color: #fff;" onclick="toggleFilters()">🔍 فیلترها</button>
            <?php if (has_permission('batch_print_labels')): ?>
            <button type="button" id="print-labels-btn" class="btn" style="background: #059669; color: #fff;" onclick="printSelectedLabels()">🖨 پرینت برچسب</button>
            <?php endif; ?>
            <?php if (has_permission('batch_update_status')): ?>
            <button type="button" id="batch-status-btn" class="btn" style="background: #7c3aed; color: #fff;" onclick="openBatchStatusModal()">📋 تغییر وضعیت گروهی</button>
            <?php endif; ?>
            <?php if (has_permission('export_csv')): ?>
            <button type="button" id="export-csv-btn" class="btn" style="background: #0891b2; color: #fff;" onclick="exportSelectedCSV()">📥 خروجی CSV</button>
            <?php endif; ?>
            <?php if (has_permission('edit_cases') || has_role('admin')): ?>
            <button type="button" id="change-designer-btn" class="btn" style="background: #d97706; color: #fff;" onclick="openChangeDesignerModal()">🔁 تغییر طراح</button>
            <?php endif; ?>
            <?php if (has_permission('edit_cases') || has_role('admin')): ?>
            <button type="button" id="download-designs-btn" class="btn" style="background: #0F172A; color: #fff;" onclick="downloadDesignFiles()" title="دانلود فایل‌های RAR طراحی که طراح کیس‌ها آپلود کرده است">⬇ دانلود طراحی‌ها</button>
            <?php endif; ?>
            <?php if (has_permission('edit_cases') || has_role('admin') || has_role('designer') || in_array($user['role'] ?? '', ['outsource_lab','partner_lab','customer_lab','lab'])): ?>
            <button type="button" id="download-files-btn" class="btn" style="background: #0F172A; color: #fff;" onclick="downloadCaseFiles()" title="دانلود هم‌زمان فایل‌های خام (اسکن) کیس‌های انتخاب‌شده — فایل‌های طراحی از دکمه‌ی «دانلود طراحی‌ها» دانلود می‌شوند">⬇ دانلود فایل‌های خام</button>
            <?php endif; ?>
        </div>
    </div>
    <div id="filters-area" style="display:none;">
        <form id="date-filter-form" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; border-top:1px solid #e5e7eb; padding-top:12px;">
            <div class="form-group" style="margin:0; min-width:150px;">
                <label for="date_from">از تاریخ دریافت</label>
                <input type="text" id="date_from" name="date_from" value="<?= htmlspecialchars($filterDateFrom ? toJalaliDateFormatted($filterDateFrom) : '') ?>" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;">
            </div>
            <div class="form-group" style="margin:0; min-width:150px;">
                <label for="date_to">تا تاریخ دریافت</label>
                <input type="text" id="date_to" name="date_to" value="<?= htmlspecialchars($filterDateTo ? toJalaliDateFormatted($filterDateTo) : '') ?>" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;">
            </div>
            <button type="submit" class="btn" style="background:#0F172A; color:#fff;">اعمال بازه تاریخ</button>
            <button type="button" id="clear-date-filter" class="btn" style="background:#E5E7EB; color:#0F172A;">پاک کردن بازه</button>
        </form>
        <div id="searchpanes-host" style="border-top:1px solid #e5e7eb; margin-top:12px; padding-top:8px;"></div>
    </div>
</div>

<p style="margin-bottom: 16px; font-weight: 700;">تعداد کیس‌ها: <span id="cases-count">—</span></p>
<p style="margin-bottom: 4px; font-size: 12px; color: #166534;">🟩 شماره کیس سبز = برچسب این کیس قبلاً چاپ شده است.</p>
<p style="margin-bottom: 16px; font-size: 12px; color: #525252;">🟦 «خام» و 🟪 «طراحی» در ستون فایل‌ها = فایل(های) خام یا طراحی این کیس قبلاً دانلود شده‌اند.</p>

<table id="cases-table" class="display" style="width:100%">
    <thead>
    <tr>
        <th><input type="checkbox" id="select-all-cases" onchange="toggleAllCases(this.checked)"></th>
        <th>Case ID</th>
        <th>پزشک</th>
        <th>طراح</th>
        <th>بیمار</th>
        <th>خدمت</th>
        <th>مکان</th>
        <th>سایه</th>
        <th>قیمت</th>
        <th>وضعیت</th>
        <th>تاریخ دریافت</th>
        <th>فاکتور</th>
        <th>لابراتوار</th>
        <th>فایل‌ها</th>
        <th>شماره قبض</th>
        <th>عملیات</th>
        <th style="display:none;">وضعیت (متن)</th>
    </tr>
    </thead>
    <tbody></tbody>
</table>

<!-- Modal for add/edit case (same as before) -->
<div id="case-modal" class="modal" style="display:none;">
    <div class="modal-content form-card" style="max-width:760px; margin:auto;">
        <h3 id="case-modal-title">افزودن کیس جدید</h3>
        <form id="case-form">
            <input type="hidden" name="id" id="case-id">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="parent_id" id="case-parent-id" value="">
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap:10px;">
                <?php if ($isDoctor): ?>
                <input type="hidden" id="case-type" name="case_type" value="doctor">
                <input type="hidden" id="case-doctor-id" name="doctor_id" value="<?= (int) $user['id'] ?>">
                <div class="form-group">
                    <label>پزشک</label>
                    <input type="text" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" disabled style="background:#f3f4f6;">
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label for="case-type">نوع کیس</label>
                    <select id="case-type" name="case_type" onchange="toggleCaseType(this.value)">
                        <option value="doctor">کیس دکتر</option>
                        <option value="lab_in">کار از لابراتوار همکار</option>
                        <option value="lab_out">برونسپاری به لابراتوار</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="case-doctor-id">پزشک</label>
                    <select id="case-doctor-id" name="doctor_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($doctors as $doctor): ?>
                        <option value="<?= $doctor['id'] ?>"><?= htmlspecialchars($doctor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group" id="lab-group" style="display:none;">
                    <label for="case-lab-id" id="lab-label">لابراتوار</label>
                    <select id="case-lab-id" name="lab_id">
                        <option value="">انتخاب لابراتوار...</option>
                        <?php $allLabs = getOutsourceLabOptions(); foreach ($allLabs as $lab): ?>
                        <option value="<?= $lab['id'] ?>" data-role="<?= $lab['role'] ?>"><?= htmlspecialchars($lab['full_name']) ?><?= !empty($lab['branch_name']) ? ' (' . htmlspecialchars($lab['branch_name']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="designer-group">
                    <label for="case-designer-id">طراح</label>
                    <select id="case-designer-id" name="designer_id">
                        <option value="">بدون طراح</option>
                        <?php $caseModalDesigners = getAllDesigners(); foreach ($caseModalDesigners as $des): ?>
                        <option value="<?= $des['id'] ?>" <?= (int) $des['id'] === $defaultDesignerId ? 'selected' : '' ?>><?= htmlspecialchars($des['full_name']) ?><?= !empty($des['is_default_designer']) ? ' (پیش‌فرض)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$isDoctor): ?>
                <div class="form-group" id="side-outsource-group" style="grid-column:1/-1; border:1px solid #bbf7d0; border-radius:8px; overflow:hidden; padding:0;">
                    <button type="button" id="side-outsource-toggle" class="btn" style="width:100%; background:#f0fdf4; color:#15803d; border:none; border-radius:0; text-align:right; display:flex; justify-content:space-between; align-items:center; padding:11px 14px; font-weight:700; cursor:pointer;">
                        <span>🔄 برون‌سپاری جانبی (بدهی به لابراتوار)</span>
                        <span class="side-outsource-caret" style="font-size:0.8rem;">▾</span>
                    </button>
                    <div id="side-outsource-body" style="display:none; background:#f0fdf4; padding:12px;">
                        <small style="display:block; color:#525252; margin:0 0 8px;">اگر بخشی از این کیس توسط لابراتوار همکار/برون‌سپاری/مشتری انجام شود (مثلاً ۵ واحد روکش کار خودتان است ولی پرینت کست یک فک را لابراتوار دیگر انجام می‌دهد)، این‌جا مشخص کنید تا در فاکتور مخارج همان لابراتوار لحاظ شود.</small>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                            <div style="flex:1; min-width:150px;">
                                <label for="case-outsourced-lab">لابراتوار (گیرنده بخشی از کار)</label>
                                <select id="case-outsourced-lab" name="outsourced_lab_id">
                                    <option value="">ندارد</option>
                                    <?php foreach ($allLabs as $lab): ?>
                                    <option value="<?= $lab['id'] ?>"><?= htmlspecialchars($lab['full_name']) ?><?= !empty($lab['branch_name']) ? ' (' . htmlspecialchars($lab['branch_name']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="flex:1; min-width:150px;">
                                <label for="case-outsourced-service">خدمت برون‌سپاری‌شده</label>
                                <select id="case-outsourced-service" name="outsourced_service_id">
                                    <option value="">انتخاب...</option>
                                    <?php foreach ($prices as $price): ?>
                                    <option value="<?= $price['id'] ?>"><?= htmlspecialchars($price['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="width:110px;">
                                <label for="case-outsourced-qty">تعداد برون‌سپاری</label>
                                <input type="number" id="case-outsourced-qty" name="outsourced_qty" min="0" step="1" value="0">
                            </div>
                            <div style="width:130px;">
                                <label for="case-outsourced-rate">نرخ (تومان)</label>
                                <input type="number" id="case-outsourced-rate" name="outsourced_rate" min="0" step="1" placeholder="خودکار از نرخ‌ها">
                            </div>
                        </div>
                        <small style="display:block; color:#525252; margin-top:6px;">با انتخاب لابراتوار و خدمت، نرخ اختصاصی آن‌ها به‌صورت خودکار وارد می‌شود. اگر نرخی ثبت نشده باشد، می‌توانید به‌صورت دستی وارد کنید.</small>
                    </div>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="case-patient-name">نام بیمار</label>
                    <input id="case-patient-name" name="patient_name" required>
                </div>
                <?php if (!$isDoctor): ?>
                <div class="form-group">
                    <label for="case-receipt-number">شماره قبض</label>
                    <input type="number" id="case-receipt-number" name="receipt_number" min="0" placeholder="مثلاً 01020">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="case-service-id">خدمت</label>
                    <select id="case-service-id" name="service_id">
                        <option value="">انتخاب...</option>
                        <?php $prices = getAllPrices(); foreach ($prices as $price): ?>
                        <option value="<?= $price['id'] ?>"><?= htmlspecialchars($price['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="case-location-type">مکان</label>
                    <select id="case-location-type" name="location_type">
                        <option value="">انتخاب...</option>
                        <option value="teeth">دندان</option>
                        <option value="upper">فک بالا</option>
                        <option value="lower">فک پایین</option>
                        <option value="both">هر دو</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label for="case-teeth-picker">دندان</label>
                    <div id="case-teeth-picker" class="case-teeth-picker" aria-label="انتخاب دندان‌ها"></div>
                    <input type="hidden" id="case-teeth" name="teeth" value="">
                </div>
                <div class="form-group">
                    <label for="case-shade">سایه</label>
                    <input id="case-shade" name="shade">
                </div>
                <div class="form-group">
                    <label for="case-quantity">تعداد <small style="color:#64748b; font-weight:400;">(خودکار)</small></label>
                    <input type="number" id="case-quantity" name="quantity" value="1" readonly style="background:#f3f4f6; cursor:not-allowed;">
                </div>
                <?php if ($isDoctor): ?>
                <input type="hidden" id="case-unit-price" name="unit_price" step="1">
                <?php else: ?>
                <div class="form-group">
                    <label for="case-unit-price">فی (تومان)</label>
                    <input type="number" id="case-unit-price" name="unit_price" step="1">
                </div>
                <div class="form-group">
                    <label for="case-design-fee">هزینه طراحی (تومان)</label>
                    <input type="number" id="case-design-fee" name="design_fee" step="1" min="0" value="0">
                </div>
                <?php endif; ?>
                <?php if ($isDoctor): ?>
                <input type="hidden" id="case-received-date" name="received_date" value="<?= htmlspecialchars(toJalaliDateFormatted(date('Y-m-d'))) ?>">
                <?php else: ?>
                <div class="form-group">
                    <label for="case-received-date">تاریخ دریافت</label>
                    <input type="text" id="case-received-date" name="received_date" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;">
                </div>
                <?php endif; ?>
                <?php if ($isDoctor): ?>
                <input type="hidden" id="case-status-id" name="status_id" value="1">
                <?php else: ?>
                <div class="form-group">
                    <label for="case-status-id">وضعیت</label>
                    <select id="case-status-id" name="status_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($statuses as $status): ?>
                        <?php if (!empty($allowedStatusIds) && !in_array((int)$status['id'], $allowedStatusIds, true)) continue; ?>
                        <option value="<?= $status['id'] ?>"><?= htmlspecialchars($status['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group" style="grid-column:1/-1;">
                    <label for="case-description">توضیحات</label>
                    <textarea id="case-description" name="description" rows="3"></textarea>
                </div>
                <?php if (!$isDoctor): ?>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>فایل‌های کیس <small style="color:#64748b; font-weight:400;">(اختیاری)</small></label>
                    <small style="display:block; color:#525252; margin:0 0 8px; line-height:1.8;">
                        می‌توانید چند «گروه فایل» بسازید؛ برای هر گروه <b>نوع فایل</b>، <b>توضیحات</b> و <b>بسته‌بندی ZIP</b> را جداگانه تعیین کنید
                        (مثلاً یک گروه «طراحی نهایی» و یک گروه «عکس بیمار»).
                    </small>
                    <div id="cf-groups"></div>
                    <button type="button" id="cf-add-group" class="btn" style="background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0;">＋ افزودن گروه فایل دیگر</button>
                    <div id="cf-formats" style="margin-top:8px; font-size:.8rem; color:#64748b; line-height:1.8;">
                        فرمت‌های مجاز: <b>STL، PLY، STP، STEP، OBJ، 3MF</b> (اسکن/مدل سه‌بعدی) ·
                        <b>JPG، JPEG، PNG، GIF، WEBP، BMP</b> (تصویر) ·
                        <b>RAR، ZIP</b> (بایگانی)
                    </div>
                    <div id="case-files-progress" style="display:none; margin-top:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:.82rem; color:#333; margin-bottom:4px;">
                            <span id="cf-progress-label">در حال آپلود...</span>
                            <span id="case-files-percent"></span>
                        </div>
                        <div style="background:#e5e7eb; border-radius:6px; overflow:hidden; height:16px;">
                            <div id="case-files-bar" style="width:0%; height:100%; background:#06B6D4; transition:width .2s;"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px; justify-content:flex-end;">
                <button type="button" id="case-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
                <button type="submit" id="case-save" class="btn" style="background:#06B6D4;">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<style>
    /* ── گروه‌های فایل کیس (drag & drop) ── */
    #cf-groups{ margin-bottom:6px; }
    .cf-group{ border:1px solid #dbe3ee; border-radius:10px; margin-bottom:10px; background:#fff; overflow:hidden; }
    .cf-group-head{ display:flex; align-items:center; gap:8px; padding:8px 10px; background:#f1f5f9; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; }
    .cf-group-title{ font-weight:700; font-size:.85rem; color:#0f172a; }
    .cf-type{ flex:1 1 120px; min-width:0; }
    .cf-group-remove{ border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; border-radius:6px; padding:3px 10px; cursor:pointer; font-size:.8rem; }
    .cf-dropzone{ border:2px dashed #cbd5e1; border-radius:8px; margin:10px; padding:18px 14px; text-align:center; cursor:pointer; background:#f8fafc; transition:border-color .15s, background .15s; }
    .cf-dropzone:hover{ border-color:#94a3b8; }
    .cf-dropzone.cf-drag{ border-color:#06B6D4; background:#ecfeff; }
    .cf-dz-hint{ display:flex; align-items:center; justify-content:center; gap:8px; color:#64748b; font-size:.88rem; }
    .cf-dz-files{ margin-top:8px; text-align:right; max-height:150px; overflow:auto; }
    .cf-file{ display:flex; align-items:center; gap:8px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:4px 8px; margin:4px 0; font-size:.8rem; }
    .cf-file-name{ flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; direction:ltr; text-align:left; }
    .cf-file-size{ color:#64748b; white-space:nowrap; }
    .cf-file-remove{ border:none; background:none; color:#dc2626; cursor:pointer; font-size:.9rem; line-height:1; padding:0 2px; }
    .cf-group-foot{ display:flex; gap:8px; align-items:center; padding:0 10px 12px; flex-wrap:wrap; }
    .cf-desc{ flex:1 1 180px; min-width:0; }
    .cf-zip-label{ display:flex; align-items:center; gap:5px; font-size:.82rem; font-weight:600; cursor:pointer; white-space:nowrap; color:#334155; }
    .cf-zip-label input{ width:auto; }
</style>

<script>
    /* ── گروه‌های فایل کیس: drag & drop + نوع/توضیح/زیپِ جداگانه برای هر گروه ── */
    (function () {
        'use strict';
        if (window.CaseFiles) return;
        var TYPE_OPTIONS = <?= json_encode(caseFileTypeConfig()['options'], JSON_UNESCAPED_UNICODE) ?>;
        var TYPE_DEFAULT = <?= json_encode(caseFileTypeDefault($user)) ?>;
        var ALLOWED = ['stl', 'ply', 'stp', 'step', 'obj', '3mf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'rar', 'zip'];
        var ACCEPT = '.' + ALLOWED.join(',.');
        var host = document.getElementById('cf-groups');
        var groups = [];
        function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }
        function fmtSize(bytes) {
            if (!(bytes > 0)) return '۰';
            var u = ['B', 'KB', 'MB', 'GB'];
            var i = Math.min(u.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
            var n = bytes / Math.pow(1024, i);
            return (i === 0 || n >= 10 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
        }
        function validExt(name) { return ALLOWED.indexOf((name.split('.').pop() || '').toLowerCase()) !== -1; }
        function renumber() { groups.forEach(function (g, i) { var t = g.querySelector('.cf-group-title'); if (t) t.textContent = 'گروه ' + fa(i + 1); }); }

        function addGroup() {
            if (!host) return null;
            var files = [];
            var g = document.createElement('div'); g.className = 'cf-group';

            var head = document.createElement('div'); head.className = 'cf-group-head';
            var title = document.createElement('span'); title.className = 'cf-group-title'; title.textContent = 'گروه ' + fa(groups.length + 1);
            var typeSel = document.createElement('select'); typeSel.className = 'cf-type';
            Object.keys(TYPE_OPTIONS).forEach(function (k) {
                var o = document.createElement('option'); o.value = k; o.textContent = TYPE_OPTIONS[k];
                if (k === TYPE_DEFAULT) o.selected = true;
                typeSel.appendChild(o);
            });
            var del = document.createElement('button'); del.type = 'button'; del.className = 'cf-group-remove'; del.textContent = 'حذف گروه';
            del.addEventListener('click', function () { var i = groups.indexOf(g); if (i !== -1) groups.splice(i, 1); g.remove(); renumber(); });
            head.appendChild(title); head.appendChild(typeSel); head.appendChild(del);

            var dz = document.createElement('div'); dz.className = 'cf-dropzone';
            var input = document.createElement('input'); input.type = 'file'; input.className = 'cf-input'; input.multiple = true; input.accept = ACCEPT; input.hidden = true;
            var hint = document.createElement('div'); hint.className = 'cf-dz-hint'; hint.textContent = '📁 کلیک کنید یا فایل‌ها را اینجا رها کنید';
            var listEl = document.createElement('div'); listEl.className = 'cf-dz-files';
            dz.appendChild(input); dz.appendChild(hint); dz.appendChild(listEl);

            function render() {
                listEl.innerHTML = '';
                files.forEach(function (f, fi) {
                    var row = document.createElement('div'); row.className = 'cf-file';
                    var nm = document.createElement('span'); nm.className = 'cf-file-name'; nm.textContent = f.name;
                    var sz = document.createElement('span'); sz.className = 'cf-file-size'; sz.textContent = fmtSize(f.size);
                    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'cf-file-remove'; rm.title = 'حذف'; rm.textContent = '✕';
                    rm.addEventListener('click', function (e) { e.stopPropagation(); files.splice(fi, 1); render(); });
                    row.appendChild(nm); row.appendChild(sz); row.appendChild(rm);
                    listEl.appendChild(row);
                });
                hint.style.display = files.length ? 'none' : 'flex';
            }
            function acceptFiles(fileList) {
                var bad = [], any = 0;
                for (var i = 0; i < fileList.length; i++) {
                    var f = fileList[i];
                    if (!validExt(f.name)) { bad.push(f.name); continue; }
                    var dup = files.some(function (x) { return x.name === f.name && x.size === f.size; });
                    if (!dup) { files.push(f); any++; }
                }
                if (bad.length) alert('این فرمت‌ها مجاز نیستند:\n' + bad.join('\n') + '\n\nفرمت‌های مجاز: ' + ALLOWED.join(', '));
                if (any) render();
            }
            dz.addEventListener('click', function (e) { if (e.target && e.target.classList && e.target.classList.contains('cf-file-remove')) return; input.click(); });
            dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add('cf-drag'); });
            dz.addEventListener('dragleave', function () { dz.classList.remove('cf-drag'); });
            dz.addEventListener('drop', function (e) {
                e.preventDefault(); e.stopPropagation(); dz.classList.remove('cf-drag');
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) acceptFiles(e.dataTransfer.files);
            });
            input.addEventListener('change', function () { acceptFiles(input.files); input.value = ''; });

            var foot = document.createElement('div'); foot.className = 'cf-group-foot';
            var desc = document.createElement('input'); desc.type = 'text'; desc.className = 'cf-desc'; desc.placeholder = 'توضیحات (اختیاری) — مثلاً: اسکن فک بالا';
            var zipLbl = document.createElement('label'); zipLbl.className = 'cf-zip-label';
            var zip = document.createElement('input'); zip.type = 'checkbox'; zip.className = 'cf-zip';
            zipLbl.appendChild(zip); zipLbl.appendChild(document.createTextNode(' ذخیره به‌صورت یک فایل ZIP'));
            foot.appendChild(desc); foot.appendChild(zipLbl);

            g.appendChild(head); g.appendChild(dz); g.appendChild(foot);
            host.appendChild(g);
            groups.push(g);
            g._files = files;
            g._zip = zip;
            renumber();
            return g;
        }

        function countFiles() { return groups.reduce(function (s, g) { return s + g._files.length; }, 0); }
        function reset() { groups.forEach(function (g) { g.remove(); }); groups = []; if (host) addGroup(); }

        function uploadAll(caseId, csrf, onAllDone) {
            var prog = document.getElementById('case-files-progress');
            var bar = document.getElementById('case-files-bar');
            var label = document.getElementById('cf-progress-label');
            var total = countFiles();
            if (total === 0) { if (onAllDone) onAllDone(); return; }
            if (prog) prog.style.display = 'block';
            if (bar) bar.style.width = '0%';
            if (label) label.textContent = 'در حال آپلود...';
            var done = 0;
            var todo = groups.slice();
            function startGroup() {
                var g = null;
                for (var i = 0; i < todo.length; i++) { if (todo[i]._files.length) { g = todo[i]; todo.splice(i, 1); break; } }
                if (!g) {
                    if (bar) bar.style.width = '100%';
                    if (label) label.textContent = 'آپلود کامل شد.';
                    if (onAllDone) onAllDone();
                    return;
                }
                var fd = new FormData();
                fd.set('case_id', caseId);
                fd.append('file_type', g.querySelector('.cf-type').value);
                var d = (g.querySelector('.cf-desc').value || '').trim();
                if (d) fd.set('description', d);
                if (g._zip.checked) fd.set('compress', '1');
                g._files.forEach(function (f) { fd.append('case_files[]', f); });
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'upload_case_files.php?case_id=' + encodeURIComponent(caseId), true);
                xhr.setRequestHeader('X-CSRF-Token', csrf);
                xhr.setRequestHeader('X-Case-Id', String(caseId));
                xhr.upload.onprogress = function (ev) {
                    if (ev.lengthComputable && label) {
                        var p = Math.round(ev.loaded / ev.total * 100);
                        label.textContent = 'در حال آپلود گروه... ' + p + '%';
                        if (bar) bar.style.width = Math.round((done + (ev.loaded / ev.total) * g._files.length) / total * 100) + '%';
                    }
                };
                xhr.onload = function () {
                    var ok = true, msgs = [];
                    try { var r = JSON.parse(xhr.responseText); if (!r.success) { ok = false; msgs = r.errors || ['خطای سرور']; } } catch (e) { ok = false; msgs = ['پاسخ نامعتبر سرور']; }
                    if (!ok) alert('آپلود گروه ناموفق بود:\n' + msgs.join('\n'));
                    done += g._files.length;
                    if (bar) bar.style.width = Math.round(done / total * 100) + '%';
                    if (label) label.textContent = 'در حال آپلود گروه بعدی...';
                    startGroup();
                };
                xhr.onerror = function () { alert('خطا در آپلود فایل‌ها.'); done += g._files.length; startGroup(); };
                xhr.send(fd);
            }
            startGroup();
        }

        window.CaseFiles = { addGroup: addGroup, reset: reset, countFiles: countFiles, uploadAll: uploadAll };
        var addBtn = document.getElementById('cf-add-group');
        if (addBtn) addBtn.addEventListener('click', function () { addGroup(); });
        if (host) addGroup();
    })();
</script>

<!-- Delete confirmation modal -->
<div id="delete-modal" class="modal" style="display:none;">
    <div class="modal-content form-card" style="max-width:400px; margin:auto; padding:24px;">
        <h3 style="margin-bottom:12px;">تایید حذف</h3>
        <p style="margin-bottom:24px; color:#555;">آیا از حذف این کیس مطمئن هستید؟ این عملیات قابل برگشت نیست.</p>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button id="delete-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
            <button id="delete-confirm" class="btn" style="background:#f87171; color:#fff;">حذف</button>
        </div>
    </div>
</div>

<!-- Batch Status Update Modal -->
<div id="batch-status-modal" class="modal" style="display:none;">
    <div class="modal-content form-card" style="max-width:420px; margin:auto; padding:24px;">
        <h3 style="margin-bottom:12px;">تغییر وضعیت گروهی کیس‌ها</h3>
        <p id="batch-status-count" style="margin-bottom:16px; color:#555;"></p>
        <div class="form-group">
            <label for="batch-status-select">وضعیت جدید</label>
            <select id="batch-status-select" style="width:100%;">
                <option value="">انتخاب کنید...</option>
                <?php foreach ($statuses as $s): ?>
                    <?php if (!empty($allowedStatusIds) && !in_array((int)$s['id'], $allowedStatusIds, true)) continue; ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
            <button id="batch-status-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
            <button id="batch-status-confirm" class="btn" style="background:#7c3aed; color:#fff;">تغییر وضعیت</button>
        </div>
    </div>
</div>

<!-- Change Designer Modal -->
<div id="change-designer-modal" class="modal" style="display:none;">
    <div class="modal-content form-card" style="max-width:420px; margin:auto; padding:24px;">
        <h3 style="margin-bottom:12px;">تغییر طراح کیس‌ها</h3>
        <p id="change-designer-count" style="margin-bottom:16px; color:#555;"></p>
        <div class="form-group">
            <label for="change-designer-select">طراح جدید</label>
            <select id="change-designer-select" style="width:100%;">
                <option value="">بدون طراح (حذف طراح)</option>
                <?php foreach ($designers as $des): ?>
                    <option value="<?= $des['id'] ?>"><?= htmlspecialchars($des['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; margin-top:6px; color:#525252;">هزینه طراحی و جمع کل هر کیس بر اساس نرخ طراح جدید به‌صورت خودکار دوباره محاسبه می‌شود.</small>
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
            <button id="change-designer-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
            <button id="change-designer-confirm" class="btn" style="background:#d97706; color:#fff;">تغییر طراح</button>
        </div>
    </div>
</div>

<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<link rel="stylesheet" href="../assets/css/case-teeth-picker.css">
<style>
.case-teeth-cell{white-space:normal; word-break:break-word; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;}
</style>
<script src="../assets/js/case-teeth-picker.js"></script>
<style>
    .modal{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.45); z-index:9999; }
    .modal .modal-content{ max-height:90vh; overflow:auto; box-shadow:0 8px 24px rgba(0,0,0,0.2); background:white; border-radius:4px; }
    /* Action dropdown */
    .action-dropdown { position: relative; display: inline-block; }
    .action-toggle { font-size: 1.2rem; line-height: 1; background: transparent; border: 1px solid #ddd; border-radius: 4px; padding: 0 6px; }
    .action-menu { min-width: 40px !important; padding: 4px !important; }
    .action-icon { display: block !important; padding: 4px 6px !important; margin: 2px 0; border-radius: 4px; }
    .action-icon svg { width: 18px; height: 18px; vertical-align: middle; }
    .action-icon:hover { background: #f3f4f6; }
    .action-icon.delete-case:hover { background: #fee2e2; }
    /* Searchable select */
    .ss-wrap { position: relative; }
    .ss-display { width: 100%; min-height: 36px; text-align: right; background: #fff; color: #0F172A; border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; }
    .ss-display::after { content: "▼"; font-size: 0.6rem; color: #6b7280; margin-right: 6px; }
    .ss-dd { position: absolute; top: calc(100% + 2px); right: 0; left: 0; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; z-index: 3000; box-shadow: 0 6px 18px rgba(0,0,0,0.12); }
    .ss-search { width: 100%; box-sizing: border-box; border: none; border-bottom: 1px solid #e5e7eb; padding: 8px 10px; outline: none; }
    .ss-list { max-height: 200px; overflow: auto; }
    .ss-option { padding: 7px 10px; cursor: pointer; }
    .ss-option:hover, .ss-option.selected { background: #e0f2fe; }
    .ss-option.empty { color: #9ca3af; text-align: center; }
    /* Hide lab/designer for restricted roles */
    <?php if (in_array($user['role'] ?? '', ['doctor', 'clinic'])): ?>
    #lab-group, #designer-group { display: none !important; }
    <?php endif; ?>
    /* DataTable layout – single row on large screens */
    .dt-layout-row {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
    }
    .dt-layout-cell {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .dt-length, .dt-search {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .dt-search input {
        margin-right: 6px;
    }
    @media (max-width: 768px) {
        .dt-layout-row {
            flex-direction: column;
            align-items: stretch;
        }
        .dt-layout-cell {
            justify-content: center;
        }
    }
</style>
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>

<script>
// Lightweight searchable select for filter dropdowns
(function(){
    function initSearchableSelect(select){
        if (!select || select.dataset.ssInit) return;
        select.dataset.ssInit = '1';
        var wrapper = document.createElement('div');
        wrapper.className = 'ss-wrap';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.style.display = 'none';
        var display = document.createElement('button');
        display.type = 'button';
        display.className = 'ss-display';
        wrapper.appendChild(display);
        var dd = document.createElement('div');
        dd.className = 'ss-dd';
        dd.style.display = 'none';
        var search = document.createElement('input');
        search.type = 'text';
        search.className = 'ss-search';
        search.placeholder = 'جستجو...';
        var list = document.createElement('div');
        list.className = 'ss-list';
        dd.appendChild(search);
        dd.appendChild(list);
        wrapper.appendChild(dd);
        function setDisplay(){
            var sel = select.options[select.selectedIndex];
            display.textContent = sel ? sel.text : '';
        }
        function render(filter){
            list.innerHTML = '';
            var shown = 0;
            Array.prototype.forEach.call(select.options, function(opt){
                if (filter && opt.text.indexOf(filter) === -1) return;
                shown++;
                var item = document.createElement('div');
                item.className = 'ss-option' + (opt.selected ? ' selected' : '');
                item.textContent = opt.text;
                item.addEventListener('click', function(){
                    select.value = opt.value;
                    select.selectedIndex = opt.index;
                    setDisplay();
                    dd.style.display = 'none';
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });
                list.appendChild(item);
            });
            if (shown === 0) {
                var empty = document.createElement('div');
                empty.className = 'ss-option empty';
                empty.textContent = 'موردی یافت نشد';
                list.appendChild(empty);
            }
        }
        setDisplay();
        // Keep the shown value in sync whenever the native select value changes
        select.addEventListener('change', setDisplay);
        display.addEventListener('click', function(e){
            e.stopPropagation();
            var open = dd.style.display === 'block';
            document.querySelectorAll('.ss-dd').forEach(function(x){ x.style.display = 'none'; });
            if (!open) {
                dd.style.display = 'block';
                search.value = '';
                render('');
                search.focus();
            }
        });
        search.addEventListener('input', function(){ render(search.value); });
        document.addEventListener('click', function(e){
            if (!wrapper.contains(e.target)) dd.style.display = 'none';
        });
    }
    document.querySelectorAll('select.searchable-select').forEach(initSearchableSelect);
})();
</script>

<script>
    // Global: toggle lab field visibility based on case type
    function toggleCaseType(type) {
        var labGroup = document.getElementById('lab-group');
        var labSelect = document.getElementById('case-lab-id');
        var labLabel = document.getElementById('lab-label');
        if (!labGroup || !labSelect) return;
        if (type === 'doctor') {
            labGroup.style.display = 'none';
            labSelect.value = '';
        } else if (type === 'lab_in') {
            labGroup.style.display = 'block';
            labLabel.textContent = 'لابراتوار همکار (پرداخت‌کننده)';
        } else if (type === 'lab_out') {
            labGroup.style.display = 'block';
            labLabel.textContent = 'لابراتوار برونسپاری (گیرنده کار)';
        }
    }
    (function(){
        var todayJalali = '<?= toJalaliDateFormatted(date('Y-m-d')) ?>';
        var editReceivedJalali = '';   // تاریخ دریافتِ معتبر هنگام ویرایش (از دیتابیس)
        // برای نگه‌داشتن انتخابِ قبلیِ لابراتوار در حالت ویرایش (اگر در لیستِ مقصد نبود)
        window.__LAB_NAMES__ = <?= json_encode(array_column(getAllLabs(), 'full_name', 'id'), JSON_UNESCAPED_UNICODE) ?>;

        function getDatepickerPlugin() {
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.persianDatepicker === 'function') {
                return window.jQuery.fn.persianDatepicker;
            }
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.pDatepicker === 'function') {
                return window.jQuery.fn.pDatepicker;
            }
            return null;
        }

        // تبدیل ارقام فارسی به انگلیسی — پلاگین تاریخ، ارقام فارسی را در مقدار اولیه نمی‌پذیرد
        // و در آن صورت تقویم را روی «امروز» باز می‌کند و مقدار ورودی را هم امروز می‌کند.
        function faToEnDigits(s) {
            return String(s).replace(/[۰-۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); });
        }

        function initJalaliPicker(selector) {
            var plugin = getDatepickerPlugin();
            if (!plugin) return false;
            jQuery(selector).each(function(){
                var $el = jQuery(this);
                var currentVal = $el.val();
                // موقتاً مقدار را با ارقام انگلیسی بده تا پلاگین آن را پارس کند
                // (تقویم روی همان تاریخِ ورودی باز شود، نه امروز).
                if (currentVal) $el.val(faToEnDigits(currentVal));
                var opts = {
                    format: 'YYYY/MM/DD',
                    calendarType: 'persian',
                    initialValue: currentVal ? true : false,
                    initialValueType: 'jalali',
                    persianDigit: true,
                    autoClose: true
                };
                try { $el.persianDatepicker(opts); }
                catch (e) { try { $el.pDatepicker(opts); } catch (e2) {} }
                // مقدار اصلی (با ارقام فارسی) را برای نمایش برگردان
                if (currentVal) $el.val(currentVal);
            });
            return true;
        }

        function initCaseReceivedDate() {
            var $input = jQuery('#case-received-date');
            if (!$input.length) return;

            var currentVal = $input.val();
            console.log('initCaseReceivedDate: currentVal =', currentVal);

            // Destroy any existing instance
            try { $input.persianDatepicker('destroy'); } catch(e) {}
            try { $input.pDatepicker('destroy'); } catch(e) {}

            var plugin = getDatepickerPlugin();
            if (!plugin) {
                console.warn('Datepicker plugin not found');
                return;
            }

            // پلاگین ارقام فارسی را در مقدار اولیه نمی‌پذیرد؛ موقتاً انگلیسی کن
            if (currentVal) $input.val(faToEnDigits(currentVal));

            try {
                // Initialize with initialValue: true (which is default) to use the input's value
                $input.persianDatepicker({
                    format: 'YYYY/MM/DD',
                    calendarType: 'persian',
                    initialValue: true,          // use input's current value
                    initialValueType: 'jalali',
                    persianDigit: true,
                    autoClose: true
                });

                // No need for setDate – the plugin will read the input's value

                // Ensure the input value is still the one we want (in case plugin changed it)
                if (currentVal) {
                    $input.val(currentVal);
                }

                // Show on click/focus
                $input.off('click focus').on('click focus', function(){
                    try { jQuery(this).persianDatepicker('show'); } catch(e) {}
                });

            } catch(e) {
                console.error('Error initializing persianDatepicker', e);
                // Fallback to pDatepicker (if used)
                try {
                    $input.pDatepicker({
                        format: 'YYYY/MM/DD',
                        calendarType: 'persian',
                        initialValue: true,
                        initialValueType: 'jalali',
                        persianDigit: true,
                        autoClose: true
                    });
                    if (currentVal) $input.val(currentVal);
                    $input.off('click focus').on('click focus', function(){
                        try { jQuery(this).pDatepicker('show'); } catch(e) {}
                    });
                } catch(e2) {
                    console.error('Fallback datepicker also failed', e2);
                }
            }

            console.log('initCaseReceivedDate: final input value =', $input.val());
        }

        // Jalali date-range filter for the cases list (از تاریخ / تا تاریخ)
        initJalaliPicker('#date_from');
        initJalaliPicker('#date_to');

        if (window.jQuery && typeof jQuery.fn.DataTable === 'function') {
            var table = jQuery('#cases-table').DataTable({
                processing: true,
                serverSide: false,
                ajax: {
                    url: 'cases_data.php?mode=all',
                    dataSrc: 'data',
                    data: function(d) {
                        // Server-side jalali date-range filter (converted in cases_data.php)
                        d.date_from = jQuery('#date_from').val() || '';
                        d.date_to = jQuery('#date_to').val() || '';
                    }
                },
                order: [[10, 'desc']], // received_date column
                responsive: true,
                pageLength: 25,
                // Place the SearchPanes feature into the table layout (DataTables 2.x)
                layout: {
                    top1: 'searchPanes'
                },
                searchPanes: {
                    layout: 'columns-2',
                    // INTEGER column positions -> creates panes ONLY for these columns.
                    // If empty, SearchPanes would create a pane for EVERY column.
                    columns: [2, 3, 5, 7, 12, 16]  /* doctor, designer, service, shade, lab, status(text) */
                },
                columnDefs: [
                    // location/teeth (col 6): اجازه بده متن طولانی (شماره دندان‌ها) در ۲ ردیف بشکند؛
                    // محدودیت ۲ ردیف روی یک <div> داخلی اعمال می‌شود (نه روی خودِ td — چیدمان جدول را خراب می‌کند)
                    { targets: [6], width: '140px', createdCell: function(td, cellData){
                        td.style.whiteSpace = 'normal';
                        td.style.wordBreak = 'break-word';
                        td.title = String(cellData || '');
                    } },
                    // clean header for the plain-status pane (its <th> says "وضعیت (متن)")
                    { targets: [16], searchPanes: { header: 'وضعیت' } }
                ],
                columns: [
                    { data: 0, orderable: false, searchable: false, render: function(data){ return '<input type="checkbox" class="case-select-cb" value="' + data + '">'; }, visible: <?= $canSelectCases ? 'true' : 'false' ?> },
                    { data: 0, render: function(data, type, row){ if (type === 'display' && row[14]) { return '<span style="background:#dcfce7; color:#166534; border-radius:6px; padding:2px 8px; font-weight:bold;" title="برچسب چاپ شده">' + data + '</span>'; } return data; } },
                    { data: 1 },
                    { data: 12, visible: <?= (!$isDesigner && canSeeDesignerInfo()) ? 'true' : 'false' ?> }, /* designer – internal only; designers only see their own case → no need */
                    { data: 2 },
                    { data: 3 },
                    { data: 4, render: function(data, type){ if (type !== 'display') return data; return '<div class="case-teeth-cell" title="' + (data ? String(data).replace(/"/g, '&quot;') : '') + '">' + (data || '') + '</div>'; } },
                    { data: 5 },
                    { data: 6, visible: <?= $isDesigner ? 'false' : 'true' ?> }, /* price – hidden for designers */
                    { data: 7 },
                    { data: 8 },
                    { data: 9, visible: <?= $isDesigner ? 'false' : 'true' ?> }, /* invoice – hidden for designers */
                    { data: 10, orderable: false, searchable: true, visible: <?= has_role('admin') ? 'true' : 'false' ?> },
                    { data: 13, orderable: false, searchable: false, render: function(data, type, row){
                        var html = String(data || 0);
                        var chips = [];
                        if (row[17]) chips.push('<span style="background:#e0f2fe; color:#0369a1; border-radius:6px; padding:1px 6px; font-size:0.72rem;" title="فایل خام این کیس قبلاً دانلود شده است">خام ✓</span>');
                        if (row[18]) chips.push('<span style="background:#f3e8ff; color:#6b21a8; border-radius:6px; padding:1px 6px; font-size:0.72rem;" title="فایل طراحی این کیس قبلاً دانلود شده است">طراحی ✓</span>');
                        if (chips.length) html += '<div style="margin-top:3px; line-height:1.5;">' + chips.join(' ') + '</div>';
                        return html;
                    } }, /* files count + download status */
                    { data: 16 }, /* receipt number */
                    { data: 11, orderable: false, searchable: false }, /* actions */
                    { data: 15, visible: false, searchable: true } /* plain status text for SearchPanes */
                ],
                order: [[10, 'desc']], // received_date column always at index 10
                language: {
                    search: "جستجو:",
                    lengthMenu: "نمایش _MENU_ در هر صفحه",
                    info: "نمایش _START_ تا _END_ از _TOTAL_ مورد",
                    infoEmpty: "هیچ موردی یافت نشد",
                    infoFiltered: "(فیلتر شده از _MAX_ مورد)",
                    loadingRecords: "در حال بارگذاری...",
                    zeroRecords: "موردی یافت نشد",
                    emptyTable: "داده‌ای موجود نیست",
                    paginate: {
                        first: "اول",
                        previous: "قبلی",
                        next: "بعدی",
                        last: "آخر"
                    },
                    aria: {
                        sortAscending: ": مرتب‌سازی صعودی",
                        sortDescending: ": مرتب‌سازی نزولی"
                    }
                }
            });

            table.on('draw', function(){
                var info = table.page.info();
                jQuery('#cases-count').text(info.recordsDisplay);
            });

            // Move the SearchPanes container into the collapsible filters area.
            // DataTables renders panes into a .dtsp-panesContainer node above the table.
            table.on('init', function(){
                var host = document.getElementById('searchpanes-host');
                if (!host) return;
                var wrapper = table.table().container();
                var panes = wrapper ? wrapper.querySelector('.dtsp-panesContainer') : null;
                if (panes && panes.parentNode && panes.parentNode !== host) {
                    host.appendChild(panes);
                }
            });

            // Toggle the filters (date range + SearchPanes) visibility
            window.toggleFilters = function(){
                var area = document.getElementById('filters-area');
                var btn = document.getElementById('toggle-filters-btn');
                if (!area) return;
                var hidden = (area.style.display === 'none' || area.style.display === '');
                area.style.display = hidden ? 'block' : 'none';
                if (btn) btn.textContent = hidden ? '🙈 مخفی کردن فیلترها' : '🔍 فیلترها';
                // Ask SearchPanes to re-layout the panes after being shown/hidden
                try { if (table && table.searchPanes) table.searchPanes.resize(); } catch(e){}
            };

            // Date-range filter (reload with the jalali range → server converts to gregorian)
            jQuery('#date-filter-form').on('submit', function(e){
                e.preventDefault();
                table.ajax.reload();
            });
            jQuery('#clear-date-filter').on('click', function(){
                jQuery('#date_from').val('');
                jQuery('#date_to').val('');
                try { jQuery('#date_from').persianDatepicker('destroy'); } catch(e){}
                try { jQuery('#date_to').persianDatepicker('destroy'); } catch(e){}
                initJalaliPicker('#date_from');
                initJalaliPicker('#date_to');
                table.ajax.reload();
            });

            // ── Teeth ↔ location ↔ quantity auto logic ──
            // Quantity is never manually edited: it follows the selection.
            function countTeethFromValue(v){
                if (!v) return 0;
                var n = 0;
                String(v).split(',').forEach(function(g){ g.split('_').forEach(function(t){ if (String(t).trim()) n++; }); });
                return n;
            }
            function updateTeethForLocation(){
                var loc = jQuery('#case-location-type').val();
                var picker = document.getElementById('case-teeth-picker');
                if (!picker) return;
                if (loc === 'upper' || loc === 'lower' || loc === 'both') {
                    // A whole jaw → no individual teeth selectable
                    picker.classList.add('is-disabled');
                    if (window.CaseTeethPicker) { CaseTeethPicker.reset(); }
                    else { jQuery('#case-teeth').val(''); }
                } else {
                    picker.classList.remove('is-disabled');
                }
            }
            function updateCaseQuantity(){
                var loc = jQuery('#case-location-type').val();
                var qty = jQuery('#case-quantity');
                if (!qty.length) return;
                if (loc === 'both') { qty.val(2); }
                else if (loc === 'upper' || loc === 'lower') { qty.val(1); }
                else {
                    var v = (window.CaseTeethPicker ? CaseTeethPicker.getValue() : '') || jQuery('#case-teeth').val() || '';
                    var n = countTeethFromValue(v);
                    qty.val(n > 0 ? n : 1);
                }
                // با تغییر تعداد (دندان/فک)، هزینه طراحی هم دوباره محاسبه شود.
                // مقدار دادن برنامه‌ای به #case-quantity رویداد change را آتش نمی‌زند،
                // پس بدون این فراخوانی، هزینه‌ی طراحیِ محاسبه‌شده برای تعدادِ قبلی باقی می‌ماند
                // (باگِ وابسته به ترتیب انتخاب خدمت/طراح/دندان).
                applyDesignFee();
            }
            jQuery(document).on('change', '#case-location-type', function(){
                updateTeethForLocation();
                updateCaseQuantity();
            });
            jQuery(document).on('click', '#case-teeth-picker .case-tooth-button, #case-teeth-picker .case-bridge-key, #case-teeth-picker .case-teeth-picker__clear', function(){
                setTimeout(updateCaseQuantity, 10);
            });

            jQuery('#add-case-btn').on('click', function(e){
                e.preventDefault();
                jQuery('#case-form')[0].reset();
                jQuery('#case-id').val('');
                jQuery('#case-parent-id').val('');
                if (window.CaseTeethPicker) { CaseTeethPicker.reset(); }
                // حالت پیش‌فرض «کیس دکتر» → لابراتوار مخفی است تا نوع کیس تغییر کند
                jQuery('#case-type').val('doctor');
                toggleCaseType('doctor');
                openCaseModal('افزودن کیس جدید', true);
            });

            // Auto-open modal with parent_id from URL parameter ?add_sub=XXX
            var urlParams = new URLSearchParams(window.location.search);
            var addSub = urlParams.get('add_sub');
            if (addSub) {
                jQuery('#case-form')[0].reset();
                jQuery('#case-id').val('');
                jQuery('#case-parent-id').val(addSub);
                if (window.CaseTeethPicker) { CaseTeethPicker.reset(); }
                // Prefill from the parent case to save time
                var parentCase = <?= json_encode($addSubParent ?? null, JSON_UNESCAPED_UNICODE) ?>;
                if (parentCase) {
                    jQuery('#case-type').val(parentCase.case_type || 'doctor');
                    toggleCaseType(jQuery('#case-type').val());
                    jQuery('#case-doctor-id').val(parentCase.doctor_id || '');
                    jQuery('#case-patient-name').val(parentCase.patient_name || '');
                    jQuery('#case-service-id').val(parentCase.service_id || '');
                    jQuery('#case-location-type').val(parentCase.location_type || '');
                    if (window.CaseTeethPicker) { CaseTeethPicker.setValue(parentCase.teeth || ''); }
                    else { jQuery('#case-teeth').val(parentCase.teeth || ''); }
                    jQuery('#case-received-date').val(parentCase.received_date_jalali || todayJalali);
                    updateTeethForLocation();
                    updateCaseQuantity();
                    <?php if (!$isDoctor): ?>
                    setTimeout(function(){ initCaseReceivedDate(); }, 100);
                    <?php endif; ?>
                }
                openCaseModal('افزودن کیس زیرمجموعه برای کیس #' + addSub, true);
            }

            jQuery(document).on('click', '.edit-case', function(e){
                e.preventDefault();
                var id = jQuery(this).data('id');
                jQuery.getJSON('get_case.php', { id: id }, function(resp){
                    if (resp.success) {
                        populateCaseForm(resp.case);
                        openCaseModal('ویرایش کیس #' + id);
                    } else {
                        alert('خطا در دریافت اطلاعات کیس');
                    }
                }).fail(function(){ alert('خطا در دریافت اطلاعات'); });
            });

            var deleteId = null;
            jQuery(document).on('click', '.delete-case', function(e){
                e.preventDefault();
                deleteId = jQuery(this).data('id');
                jQuery('#delete-modal').css({display: 'flex'});
            });
            jQuery('#delete-confirm').on('click', function(){
                if (!deleteId) return;
                jQuery('#delete-modal').css({display: 'none'});
                jQuery.ajax({
                    url: 'delete_case.php',
                    type: 'POST',
                    data: { id: deleteId, _csrf_token: '<?= htmlspecialchars($csrf_token) ?>' },
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-CSRF-Token', '<?= htmlspecialchars($csrf_token) ?>');
                    },
                    success: function(resp){
                        try { var j = (typeof resp === 'string') ? JSON.parse(resp) : resp; } catch(e){ j = { success: false }; }
                        if (j.success) { table.ajax.reload(null, false); }
                        else { alert('حذف انجام نشد'); }
                    },
                    error: function(){ alert('خطا در حذف'); }
                });
            });
            jQuery('#delete-cancel').on('click', function(){
                jQuery('#delete-modal').css({display: 'none'});
                deleteId = null;
            });

            function openCaseModal(title, isAdd){
                jQuery('#case-modal-title').text(title || 'افزودن کیس جدید');
                jQuery('#case-modal').css({display: 'flex'});
                if (isAdd) {
                    // طراح همیشه «طراح پیش‌فرض» است + گروه‌های فایل خالی
                    var did = Number('<?= (int) $defaultDesignerId ?>') || 0;
                    if (did) jQuery('#case-designer-id').val(did);
                    var pwEl = document.getElementById('case-files-progress');
                    if (pwEl) pwEl.style.display = 'none';
                    if (window.CaseFiles) window.CaseFiles.reset();
                }
                if (!jQuery('#case-received-date').val()) {
                    jQuery('#case-received-date').val(todayJalali);
                }
                // هنگام ویرایش، همیشه «تاریخ دریافتِ» دیتابیس اعمال شود (به امروز نرود)
                if (!isAdd && editReceivedJalali) {
                    jQuery('#case-received-date').val(editReceivedJalali);
                }
                updateTeethForLocation();
                updateCaseQuantity();
                <?php if (!$isDoctor): ?>
                setTimeout(function(){ initCaseReceivedDate(); }, 100);
                <?php endif; ?>
            }
            function closeCaseModal(){
                jQuery('#case-modal').css({display: 'none'});
                jQuery('#case-form')[0].reset();
                if (window.CaseTeethPicker) { CaseTeethPicker.reset(); }
                jQuery('#case-save').prop('disabled', false).text('ذخیره');
                var progWrap = document.getElementById('case-files-progress');
                if (progWrap) progWrap.style.display = 'none';
                if (window.CaseFiles) window.CaseFiles.reset();
                setTimeout(function(){ jQuery('#case-received-date').val(''); }, 100);
            }
            jQuery('#case-cancel').on('click', function(){ closeCaseModal(); });

            // Auto-fill the side-outsourcing rate from outsource_rates when lab+service are chosen.
            function fetchOutsourceRate(){
                var lab = jQuery('#case-outsourced-lab').val();
                var svc = jQuery('#case-outsourced-service').val();
                var rateInput = jQuery('#case-outsourced-rate');
                if (!lab || !svc) return;
                var csrf = '<?= htmlspecialchars($csrf_token) ?>';
                jQuery.ajax({
                    url: 'get_outsource_rate.php',
                    type: 'GET',
                    data: { lab_id: lab, service_id: svc },
                    headers: { 'X-CSRF-Token': csrf },
                    dataType: 'json',
                    success: function(resp){
                        if (resp && resp.rate != null) {
                            rateInput.val(resp.rate);
                            rateInput.attr('placeholder', 'نرخ اختصاصی');
                        } else if (rateInput.val() === '' || rateInput.val() == null) {
                            // No dedicated rate and nothing saved on this case yet → default to 0 (manually editable).
                            rateInput.val(0);
                        }
                    }
                });
            }
            jQuery(document).on('change', '#case-outsourced-lab, #case-outsourced-service', fetchOutsourceRate);

            // Toggle the collapsible side-outsourcing section (robust open/close)
            (function(){
                var soOpen = false;
                var soBtn = document.getElementById('side-outsource-toggle');
                var soBody = document.getElementById('side-outsource-body');
                var soCaret = document.querySelector('.side-outsource-caret');
                if (soBtn && soBody) soBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    soOpen = !soOpen;
                    soBody.style.display = soOpen ? 'block' : 'none';
                    if (soCaret) soCaret.textContent = soOpen ? '▴' : '▾';
                });
            })();


            // (آپلود فایل کیس به «گروه‌های فایل» با drag&drop و نوع/توضیح/زیپِ جداگانه منتقل شد — window.CaseFiles)

            jQuery(document).on('keydown', function(e){
                if (e.key === 'Escape' || e.key === 'Esc') {
                    if (jQuery('#case-modal').is(':visible')) {
                        e.preventDefault();
                        closeCaseModal();
                    }
                }
            });

            // (action-menu toggle handled by icons.php action_menu_script)

            function populateCaseForm(data){
                jQuery('#case-id').val(data.id || '');
                jQuery('#case-parent-id').val(data.parent_id || '');
                jQuery('#case-type').val(data.case_type || 'doctor');
                toggleCaseType(jQuery('#case-type').val());
                jQuery('#case-doctor-id').val(data.doctor_id || '');
                jQuery('#case-patient-name').val(data.patient_name || '');
                jQuery('#case-receipt-number').val(data.receipt_number || '');
                jQuery('#case-service-id').val(data.service_id || '');
                jQuery('#case-location-type').val(data.location_type || '');
                if (window.CaseTeethPicker) { CaseTeethPicker.setValue(data.teeth || ''); }
                else { jQuery('#case-teeth').val(data.teeth || ''); }
                updateTeethForLocation();
                updateCaseQuantity();
                jQuery('#case-shade').val(data.shade || '');
                jQuery('#case-quantity').val(data.quantity || 1);
                jQuery('#case-unit-price').val(data.unit_price || '');
                jQuery('#case-design-fee').val(data.design_fee || 0);
                jQuery('#case-received-date').val(data.received_date || ''); // sets correct date
                editReceivedJalali = data.received_date || '';
                jQuery('#case-status-id').val(data.status_id || '');
                var keepLabId = String(data.lab_id || '');
                var keepOutLabId = String(data.outsourced_lab_id || '');
                jQuery('#case-lab-id').val(keepLabId);
                jQuery('#case-designer-id').val(data.designer_id || '');
                jQuery('#case-outsourced-lab').val(keepOutLabId);
                // اگر لابراتوارِ انتخاب‌شده در لیستِ مقصد نیست (مثلاً لابراتوارِ خودِ شعبه در کیس‌های
                // قدیمی)، گزینه‌اش را اضافه کن تا هنگام ویرایش انتخاب از بین نرود.
                function keepLabOption(selId, val) {
                    if (!val) return;
                    if (jQuery(selId + ' option[value="' + val + '"]').length) return;
                    var nm = (window.__LAB_NAMES__ && window.__LAB_NAMES__[val]) ? window.__LAB_NAMES__[val] : ('لابراتوار #' + val);
                    jQuery(selId).append(new Option(nm, val));
                }
                keepLabOption('#case-lab-id', keepLabId);
                keepLabOption('#case-outsourced-lab', keepOutLabId);
                jQuery('#case-outsourced-service').val(data.outsourced_service_id || '');
                jQuery('#case-outsourced-qty').val(data.outsourced_qty || 0);
                // Keep the saved per-case rate (do NOT overwrite with lookup on edit; user can re-pick to re-fetch)
                jQuery('#case-outsourced-rate').val(data.outsourced_rate != null && data.outsourced_rate !== '' ? data.outsourced_rate : '');
                jQuery('#case-description').val(data.description || '');
            }

            jQuery('#case-form').on('submit', function(e){
                e.preventDefault();
                var formEl = jQuery(this)[0];
                var csrf = '<?= htmlspecialchars($csrf_token) ?>';

                // Build URL-encoded data (text fields only – no files)
                var params = new URLSearchParams(new FormData(formEl));
                params.set('_csrf_token', csrf);
                if (!jQuery('#case-received-date').val()) {
                    params.set('received_date', todayJalali);
                }

                jQuery.ajax({
                    url: 'save_case.php',
                    type: 'POST',
                    data: params.toString(),
                    headers: {
                        'X-CSRF-Token': csrf
                    },
                    beforeSend: function() {
                        jQuery('#case-save').prop('disabled', true).text('در حال ذخیره...');
                    },
                    success: function(resp){
                        try { var j = (typeof resp === 'string') ? JSON.parse(resp) : resp; } catch(e){ j = { success: false }; }
                        if (j.success) {
                            var finish = function () {
                                closeCaseModal();
                                table.ajax.reload(null, false);
                            };
                            if (window.CaseFiles && window.CaseFiles.countFiles() > 0) {
                                // آپلود گروه‌های فایل (هر گروه با نوع/توضیح/زیپ خودش)؛ مودال باز می‌ماند تا پایان
                                window.CaseFiles.uploadAll(j.id, csrf, finish);
                            } else {
                                finish();
                            }
                        } else {
                            alert('ذخیره انجام نشد: ' + (j.message || ''));
                        }
                    },
                    error: function(){
                        jQuery('#case-save').prop('disabled', false).text('ذخیره');
                        alert('خطا در سرور');
                    }
                });
            });
            // Auto-update unit price when doctor, lab, service, or case type changes in the modal
            jQuery('#case-doctor-id, #case-service-id, #case-lab-id, #case-type').on('change', function() {
                var caseType = jQuery('#case-type').val();
                var serviceId = jQuery('#case-service-id').val();
                if (!serviceId) return;
                var labId = jQuery('#case-lab-id').val();
                var doctorId = jQuery('#case-doctor-id').val();
                if ((caseType === 'lab_in' || caseType === 'lab_out') && labId) {
                    // Lab-origin cases use the lab's applicable price regardless of doctor
                    jQuery.ajax({
                        url: 'get_price.php',
                        data: { lab_id: labId, service_id: serviceId },
                        dataType: 'json',
                        success: function(resp) {
                            jQuery('#case-unit-price').val(resp.price !== null ? resp.price : '');
                        }
                    });
                } else if (doctorId) {
                    jQuery.ajax({
                        url: 'get_price.php',
                        data: { doctor_id: doctorId, service_id: serviceId },
                        dataType: 'json',
                        success: function(resp) {
                            if (resp.price !== null) {
                                jQuery('#case-unit-price').val(resp.price);
                            } else {
                                jQuery('#case-unit-price').val('');
                            }
                        }
                    });
                }
            });

            var currentDesignUnitFee = null;

            // Load per-unit design fee for the selected designer + service
            function reloadDesignFee() {
                var designerId = jQuery('#case-designer-id').val();
                var serviceId = jQuery('#case-service-id').val();
                if (!designerId) {
                    currentDesignUnitFee = null;
                    jQuery('#case-design-fee').val('0');
                    return;
                }
                if (!serviceId) {
                    currentDesignUnitFee = null;
                    return;
                }
                jQuery.ajax({
                    url: 'get_price.php',
                    data: { doctor_id: designerId, service_id: serviceId, price_type: 'design_fee' },
                    dataType: 'json',
                    success: function(resp) {
                        currentDesignUnitFee = (resp.price !== null) ? parseFloat(resp.price) : null;
                        applyDesignFee();
                    }
                });
            }

            // design fee (تومان) = per-unit fee × quantity
            function applyDesignFee() {
                if (currentDesignUnitFee === null) return;
                var qty = parseInt(jQuery('#case-quantity').val(), 10) || 1;
                jQuery('#case-design-fee').val(Math.round(currentDesignUnitFee * qty));
            }

            jQuery('#case-designer-id, #case-service-id').on('change', function() {
                reloadDesignFee();
            });
            jQuery('#case-quantity').on('input change', function() {
                applyDesignFee();
            });

            // ─── Print labels for selected cases ───
            window.printSelectedLabels = function() {
                var checked = document.querySelectorAll('.case-select-cb:checked');
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                if (ids.length === 0) {
                    alert('لطفاً حداقل یک کیس را انتخاب کنید.');
                    return;
                }
                var form = document.createElement('form');
                form.method = 'post';
                form.action = 'print_labels.php';
                form.target = '_blank';
                ids.forEach(function(id) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'case_ids[]';
                    inp.value = id;
                    form.appendChild(inp);
                });
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            };

            // ─── دانلود فایل‌های طراحی‌شده‌ی کیس‌های انتخاب‌شده (ZIP) ───
            window.downloadDesignFiles = function() {
                var checked = document.querySelectorAll('.case-select-cb:checked');
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                if (ids.length === 0) {
                    alert('لطفاً حداقل یک کیس را انتخاب کنید.');
                    return;
                }
                var form = document.createElement('form');
                form.method = 'post';
                form.action = 'download_design_files.php';
                form.target = '_blank';
                ids.forEach(function(id) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'case_ids[]';
                    inp.value = id;
                    form.appendChild(inp);
                });
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            };
            // ─── دانلود همه‌ی فایل‌های کیس‌های انتخاب‌شده (ZIP) ───
            window.downloadCaseFiles = function() {
                var checked = document.querySelectorAll('.case-select-cb:checked');
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                if (ids.length === 0) {
                    alert('لطفاً حداقل یک کیس را انتخاب کنید.');
                    return;
                }
                var form = document.createElement('form');
                form.method = 'post';
                form.action = 'download_case_files.php';
                form.target = '_blank';
                ids.forEach(function(id) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'case_ids[]';
                    inp.value = id;
                    form.appendChild(inp);
                });
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            };
            window.toggleAllCases = function(checked) {
                document.querySelectorAll('.case-select-cb').forEach(function(cb) {
                    cb.checked = checked;
                });
            };

            // ─── CSV export ───
            window.exportSelectedCSV = function() {
                var checked = document.querySelectorAll('.case-select-cb:checked');
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                if (ids.length === 0) {
                    alert('لطفاً حداقل یک کیس را انتخاب کنید.');
                    return;
                }
                var form = document.createElement('form');
                form.method = 'post';
                form.action = 'export_cases_csv.php';
                ids.forEach(function(id) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'case_ids[]';
                    inp.value = id;
                    form.appendChild(inp);
                });
                // Add CSRF header via hidden input
                var csrfInp = document.createElement('input');
                csrfInp.type = 'hidden';
                csrfInp.name = '_csrf_token';
                csrfInp.value = '<?= htmlspecialchars($csrf_token) ?>';
                form.appendChild(csrfInp);
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            };

            // ─── Batch status update ───
            window.openBatchStatusModal = function() {
                var checked = document.querySelectorAll('.case-select-cb:checked');
                if (checked.length === 0) {
                    alert('لطفاً حداقل یک کیس را انتخاب کنید.');
                    return;
                }
                document.getElementById('batch-status-count').textContent = checked.length + ' کیس انتخاب شده است.';
                document.getElementById('batch-status-modal').style.display = 'flex';
            };
            jQuery('#batch-status-cancel').on('click', function(){
                jQuery('#batch-status-modal').css({display: 'none'});
            });
            jQuery('#batch-status-confirm').on('click', function(){
                var statusId = jQuery('#batch-status-select').val();
                if (!statusId) { alert('لطفاً یک وضعیت انتخاب کنید.'); return; }
                var checked = document.querySelectorAll('.case-select-cb:checked');
                if (checked.length === 0) { alert('هیچ کیسی انتخاب نشده.'); return; }
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                jQuery('#batch-status-modal').css({display: 'none'});
                jQuery.ajax({
                    url: 'batch_update_status.php',
                    type: 'POST',
                    data: { case_ids: ids, status_id: statusId, _csrf_token: '<?= htmlspecialchars($csrf_token) ?>' },
                    headers: { 'X-CSRF-Token': '<?= htmlspecialchars($csrf_token) ?>' },
                    success: function(resp){
                        try { var j = (typeof resp === 'string') ? JSON.parse(resp) : resp; } catch(e){ j = { success: false }; }
                        if (j.success) {
                            table.ajax.reload(null, false);
                            alert(j.updated + ' کیس با موفقیت بروزرسانی شد.');
                        } else {
                            alert('خطا: ' + (j.errors ? j.errors.join(', ') : j.error));
                        }
                    },
                    error: function(){ alert('خطا در سرور'); }
                });
            });

            // ─── Change designer ───
            window.openChangeDesignerModal = function() {
                var checked = document.querySelectorAll('.case-select-cb:checked');
                if (checked.length === 0) {
                    alert('لطفاً حداقل یک کیس را انتخاب کنید.');
                    return;
                }
                document.getElementById('change-designer-count').textContent = checked.length + ' کیس انتخاب شده است.';
                document.getElementById('change-designer-modal').style.display = 'flex';
            };
            jQuery('#change-designer-cancel').on('click', function(){
                jQuery('#change-designer-modal').css({display: 'none'});
            });
            jQuery('#change-designer-confirm').on('click', function(){
                var designerId = jQuery('#change-designer-select').val();
                var checked = document.querySelectorAll('.case-select-cb:checked');
                if (checked.length === 0) { alert('هیچ کیسی انتخاب نشده.'); return; }
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                jQuery('#change-designer-modal').css({display: 'none'});
                jQuery.ajax({
                    url: 'batch_update_designer.php',
                    type: 'POST',
                    data: { case_ids: ids, designer_id: designerId, _csrf_token: '<?= htmlspecialchars($csrf_token) ?>' },
                    headers: { 'X-CSRF-Token': '<?= htmlspecialchars($csrf_token) ?>' },
                    success: function(resp){
                        try { var j = (typeof resp === 'string') ? JSON.parse(resp) : resp; } catch(e){ j = { success: false }; }
                        if (j.success) {
                            table.ajax.reload(null, false);
                            var msg = j.updated + ' کیس بروزرسانی شد.';
                            if (j.errors && j.errors.length) msg += '\n' + j.errors.join('\n');
                            alert(msg);
                        } else {
                            alert('خطا: ' + (j.errors ? j.errors.join(', ') : j.error));
                        }
                    },
                    error: function(){ alert('خطا در سرور'); }
                });
            });
        }

    })();
</script>

<?php panel_layout_end(); ?>