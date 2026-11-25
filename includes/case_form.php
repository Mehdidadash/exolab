<?php
/**
 * includes/case_form.php
 * ─────────────────────────────────────────────────────────────────────────────
 * تنها منبعِ مارک‌آپِ فرمِ کیس (افزودن/ویرایش).
 *
 * چرا این فایل؟
 *   قبلاً همین فرم دو بار نوشته شده بود (panel/cases.php و panel/view_case.php)
 *   و هر تغییر باید دو بار انجام می‌شد. با این پارشال، هر دو صفحه یک مارک‌آپِ
 *   یکسان دارند؛ شناسه‌ها عمداً همان `case-*` قبلی مانده‌اند تا JS فعلی
 *   (populateCaseForm / loadCasePricing / CaseFiles / teeth/shade picker) بدون
 *   تغییر کار کند.
 *
 * قراردادِ ctx (همه اختیاری‌اند مگر خلافش ذکر شود):
 *   mode              'create' | 'edit'           (پیش‌فرض create)
 *   csrf_token        string                       (اجباری)
 *   user              آرایهٔ کاربر جاری [id, full_name]
 *   isDoctor          bool
 *   isExternalViewer  bool
 *   doctors           [['id'=>..,'name'=>..], ...]
 *   allLabs           getOutsourceLabOptions()
 *   designers         getAllDesigners()
 *   defaultDesignerId int
 *   defaultDesigner   getDefaultDesigner()|null    (برای کاربران بیرونی)
 *   clinics           getAllClinics() (یا فیلترشده برای پزشک)
 *   caseClinicDefault int
 *   prices            getAllPrices()
 *   scanBodyTypes     getScanBodyTypes()
 *   fixtureTypes      getFixtureTypes()
 *   statuses          getAllCaseStatuses()
 *   allowedStatusIds  null|int[]
 *   canCreateCases    bool
 *   showFiles         bool   (پیش‌فرض true)
 *   showSubcaseButton bool   (پیش‌فرض false)
 *   values            مقادیر ذخیره‌شده برای رندر سرورساید (اختیاری).
 *                     received_date باید جلالی باشد؛ unit_price/design_fee عددی.
 *
 * خروجی: رشتهٔ HTML شامل <form id="case-form"> و تمام فیلدها (بدون مودال/عنوان).
 */

if (!function_exists('renderCaseForm')) {
    function renderCaseForm(array $ctx): string
    {
        $mode             = (string) ($ctx['mode'] ?? 'create');
        $values           = is_array($ctx['values'] ?? null) ? $ctx['values'] : [];
        $user             = is_array($ctx['user'] ?? null) ? $ctx['user'] : [];
        $isDoctor         = !empty($ctx['isDoctor']);
        $isExternalViewer = !empty($ctx['isExternalViewer']);
        $doctors          = is_array($ctx['doctors'] ?? null) ? $ctx['doctors'] : [];
        $allLabs          = is_array($ctx['allLabs'] ?? null) ? $ctx['allLabs'] : [];
        $designers        = is_array($ctx['designers'] ?? null) ? $ctx['designers'] : [];
        $defaultDesignerId = (int) ($ctx['defaultDesignerId'] ?? 0);
        $defaultDesigner   = $ctx['defaultDesigner'] ?? null;
        $clinics           = is_array($ctx['clinics'] ?? null) ? $ctx['clinics'] : [];
        $caseClinicDefault = (int) ($ctx['caseClinicDefault'] ?? 0);
        $prices            = is_array($ctx['prices'] ?? null) ? $ctx['prices'] : [];
        $scanBodyTypes     = is_array($ctx['scanBodyTypes'] ?? null) ? $ctx['scanBodyTypes'] : [];
        $fixtureTypes      = is_array($ctx['fixtureTypes'] ?? null) ? $ctx['fixtureTypes'] : [];
        $statuses          = is_array($ctx['statuses'] ?? null) ? $ctx['statuses'] : [];
        $allowedStatusIds  = array_key_exists('allowedStatusIds', $ctx) && is_array($ctx['allowedStatusIds'])
            ? array_map('intval', $ctx['allowedStatusIds'])
            : null;
        $canCreateCases    = !empty($ctx['canCreateCases']);
        $showFiles         = !array_key_exists('showFiles', $ctx) || !empty($ctx['showFiles']);
        $showSubcaseButton = !empty($ctx['showSubcaseButton']);
        $csrf              = (string) ($ctx['csrf_token'] ?? '');
        $isEdit            = ($mode === 'edit');
        // شناسهٔ <form> قابل تغییر است تا صفحه‌های قدیمی که با #edit-case-form کار می‌کنند نشکنند.
        $formId            = (string) ($ctx['form_id'] ?? 'case-form');
        // نگاشتِ شناسه‌ها (اختیاری): برای سازگاری با JS صفحهٔ مشاهدهٔ کیس که با ec-* کار می‌کند.
        $idMap             = is_array($ctx['id_map'] ?? null) ? $ctx['id_map'] : [];

        // ── کمک‌تابع‌های مقداردهی ──
        $has = static function (string $key) use ($values): bool {
            return array_key_exists($key, $values);
        };
        $esc = static function ($v): string {
            return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $valAttr = static function (string $key, string $default = '') use ($values, $has, $esc): string {
            $v = $has($key) ? $values[$key] : $default;
            return ' value="' . $esc($v) . '"';
        };
        $selAttr = static function (string $key, $option) use ($values, $has): string {
            if (!$has($key)) return '';
            return ((string) $values[$key] === (string) $option) ? ' selected' : '';
        };
        $chkAttr = static function (string $key) use ($values): string {
            return !empty($values[$key]) ? ' checked' : '';
        };

        // کلینیک و طراح: در حالت ایجاد از پیش‌فرض‌ها، در حالت ویرایش از مقدار ذخیره‌شده
        $clinicSelId   = $has('clinic_id') ? (string) $values['clinic_id'] : (string) $caseClinicDefault;
        $designerSelId = $has('designer_id') ? (string) $values['designer_id'] : (string) $defaultDesignerId;
        $statusSelId   = $has('status_id') ? (string) $values['status_id'] : '';
        $caseId        = $has('id') ? (string) $values['id'] : '';
        $parentId      = $has('parent_id') ? (string) $values['parent_id'] : '';
        $receivedJalali = $has('received_date') ? (string) $values['received_date'] : '';

        ob_start();
        ?>
        <form id="<?= $esc($formId) ?>" method="post"<?= $isEdit ? ' data-mode="edit"' : ' data-mode="create"' ?>>
            <style>
                /* جعبهٔ پیام/خطای ذخیرهٔ کیس — خطاهای سرور باید واضح و کپی‌پذیر دیده شوند */
                #case-form-error{ display:none; margin:0 0 12px; padding:10px 12px; border:1px solid #fca5a5; background:#fef2f2; color:#991b1b; border-radius:8px; font-size:0.9rem; line-height:1.9; }
                #case-form-error ul{ margin:6px 0 0; padding-inline-start:18px; }
                #case-form-error li{ margin:2px 0; }
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
                /* ── آپلود پوشه ── */
                .cf-dz-actions{ display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top:6px; }
                .cf-pick-folder{ border:1px solid #bbf7d0; background:#f0fdf4; color:#15803d; border-radius:6px; padding:5px 12px; cursor:pointer; font-size:.82rem; font-family:inherit; font-weight:600; }
                .cf-pick-folder:hover{ background:#dcfce7; }
                .cf-file-path{ direction:ltr; text-align:left; color:#0e7490; font-size:.72rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:46%; flex:0 1 auto; }
                .cf-file-main{ display:flex; flex-direction:column; gap:1px; flex:1; min-width:0; }
                .cf-folder-badge{ background:#cffafe; color:#155e75; border-radius:5px; padding:0 5px; font-size:.72rem; white-space:nowrap; }
            </style>
            <div id="case-form-error" role="alert" aria-live="polite"></div>
            <input type="hidden" name="id" id="case-id"<?= $valAttr('id') ?>>
            <input type="hidden" name="_csrf_token" value="<?= $esc($csrf) ?>">
            <input type="hidden" name="parent_id" id="case-parent-id"<?= $valAttr('parent_id') ?>>
            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
                <?php if ($isDoctor): ?>
                <input type="hidden" id="case-type" name="case_type" value="doctor">
                <input type="hidden" id="case-doctor-id" name="doctor_id" value="<?= (int) ($user['id'] ?? 0) ?>">
                <div class="form-group">
                    <label>پزشک</label>
                    <input type="text" value="<?= $esc($user['full_name'] ?? '') ?>" disabled style="background:#f3f4f6;">
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label for="case-type"><?= field_icon('tags') ?> نوع کیس</label>
                    <select id="case-type" name="case_type">
                        <option value="doctor"<?= $selAttr('case_type', 'doctor') ?>>کیس دکتر</option>
                        <option value="lab_in"<?= $selAttr('case_type', 'lab_in') ?>>کار از لابراتوار همکار</option>
                        <option value="lab_out"<?= $selAttr('case_type', 'lab_out') ?>>برون‌سپاری به لابراتوار</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="case-doctor-id"><?= field_icon('user-doctor') ?> پزشک</label>
                    <select id="case-doctor-id" name="doctor_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($doctors as $doctor): ?>
                        <option value="<?= (int) $doctor['id'] ?>"<?= $selAttr('doctor_id', (int) $doctor['id']) ?>><?= $esc($doctor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!$isExternalViewer): ?>
                <div class="form-group" id="lab-group" style="display:none;">
                    <label for="case-lab-id" id="lab-label"><?= field_icon('flask') ?> لابراتوار</label>
                    <select id="case-lab-id" name="lab_id">
                        <option value="">انتخاب لابراتوار...</option>
                        <?php foreach ($allLabs as $lab): ?>
                        <option value="<?= (int) $lab['id'] ?>" data-role="<?= $esc($lab['role'] ?? '') ?>"<?= $selAttr('lab_id', (int) $lab['id']) ?>><?= $esc($lab['full_name']) ?><?= !empty($lab['branch_name']) ? ' (' . $esc($lab['branch_name']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!$isExternalViewer || $isDoctor): ?>
                <div class="form-group" id="designer-group">
                    <label for="case-designer-id"><?= field_icon('compass-drafting') ?> طراح<?= $isExternalViewer ? ' (پیش‌فرض)' : '' ?></label>
                    <select id="case-designer-id" name="designer_id">
                        <?php // برای کاربران بیرونی/پزشک گزینهٔ «بدون طراح» نمایش داده نمی‌شود تا
                        // همیشه «طراح پیش‌فرض» انتخاب باشد. همچنین نامِ واقعیِ طراح نباید دیده
                        // شود؛ پس برچسبِ گزینه فقط «طراح پیش‌فرض» است (نه full_name طراح). ?>
                        <?php if (!$isExternalViewer): ?>
                        <option value="">بدون طراح</option>
                        <?php endif; ?>
                        <?php if ($isExternalViewer): ?>
                            <?php if (!empty($defaultDesigner)): ?>
                                <option value="<?= (int) $defaultDesigner['id'] ?>" selected>طراح پیش‌فرض</option>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach ($designers as $des): ?>
                            <option value="<?= (int) $des['id'] ?>"<?= ((string) $des['id'] === $designerSelId) ? ' selected' : '' ?>><?= $esc($des['full_name']) ?><?= !empty($des['is_default_designer']) ? ' (پیش‌فرض)' : '' ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <small id="case-design-note" style="display:none; color:#0369a1; background:#e0f2fe; border-radius:6px; padding:4px 8px; margin-top:6px;"></small>
                </div>
                <?php endif; ?>
                <div class="form-group" id="case-clinic-group">
                    <label for="case-clinic-id"><?= field_icon('hospital') ?> کلینیک</label>
                    <select id="case-clinic-id" name="clinic_id">
                        <option value="">بدون کلینیک</option>
                        <?php foreach ($clinics as $cl): ?>
                        <option value="<?= (int) $cl['id'] ?>"<?= ((string) (int) $cl['id'] === $clinicSelId) ? ' selected' : '' ?>><?= $esc($cl['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$isDoctor && !$isExternalViewer): ?>
                <div class="form-group" id="side-outsource-group" style="grid-column:1/-1; border:1px solid #bbf7d0; border-radius:8px; overflow:hidden; padding:0;">
                    <button type="button" id="side-outsource-toggle" class="btn" style="width:100%; background:#f0fdf4; color:#15803d; border:none; border-radius:0; text-align:right; display:flex; justify-content:space-between; align-items:center; padding:11px 14px; font-weight:700; cursor:pointer;">
                        <span>🔄 برون‌سپاری جانبی (بدهی به لابراتوار)</span>
                        <span class="side-outsource-caret" style="font-size:0.8rem;">▾</span>
                    </button>
                    <div id="side-outsource-body" style="display:none; background:#f0fdf4; padding:12px;">
                        <small style="display:block; color:#525252; margin:0 0 8px;">اگر بخشی از این کیس توسط لابراتوار همکار/برون‌سپاری/مشتری انجام شود (مثلاً ۵ واحد روکش کار خودتان است ولی پرینت کست یک فک را لابراتوار دیگر انجام می‌دهد)، این‌جا مشخص کنید تا در فاکتور مخارج همان لابراتوار لحاظ شود.</small>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                            <div style="flex:1; min-width:150px;">
                                <label for="case-outsourced-lab"><?= field_icon('flask') ?> لابراتوار (گیرنده بخشی از کار)</label>
                                <select id="case-outsourced-lab" name="outsourced_lab_id">
                                    <option value="">ندارد</option>
                                    <?php foreach ($allLabs as $lab): ?>
                                    <option value="<?= (int) $lab['id'] ?>"<?= $selAttr('outsourced_lab_id', (int) $lab['id']) ?>><?= $esc($lab['full_name']) ?><?= !empty($lab['branch_name']) ? ' (' . $esc($lab['branch_name']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="flex:1; min-width:150px;">
                                <label for="case-outsourced-service"><?= field_icon('tooth') ?> خدمت برون‌سپاری‌شده</label>
                                <select id="case-outsourced-service" name="outsourced_service_id">
                                    <option value="">انتخاب...</option>
                                    <?php foreach ($prices as $price): ?>
                                    <option value="<?= (int) $price['id'] ?>"<?= $selAttr('outsourced_service_id', (int) $price['id']) ?>><?= $esc($price['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="width:110px;">
                                <label for="case-outsourced-qty"><?= field_icon('hashtag') ?> تعداد برون‌سپاری</label>
                                <input type="number" id="case-outsourced-qty" name="outsourced_qty" min="0" step="1"<?= $valAttr('outsourced_qty', '0') ?>>
                            </div>
                            <div style="width:130px;">
                                <label for="case-outsourced-rate"><?= field_icon('money') ?> نرخ (تومان)</label>
                                <input type="number" id="case-outsourced-rate" name="outsourced_rate" min="0" step="1" placeholder="خودکار از نرخ‌ها"<?= $valAttr('outsourced_rate') ?>>
                            </div>
                        </div>
                        <small style="display:block; color:#525252; margin-top:6px;">با انتخاب لابراتوار و خدمت، نرخ اختصاصی آن‌ها به‌صورت خودکار وارد می‌شود. اگر نرخی ثبت نشده باشد، می‌توانید به‌صورت دستی وارد کنید.</small>
                    </div>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="case-patient-name"><?= field_icon('user') ?> نام بیمار</label>
                    <input id="case-patient-name" name="patient_name" required<?= $valAttr('patient_name') ?>>
                </div>
                <?php if (!$isDoctor): ?>
                <div class="form-group">
                    <label for="case-receipt-number"><?= field_icon('receipt') ?> شماره قبض</label>
                    <input type="number" id="case-receipt-number" name="receipt_number" min="0" placeholder="مثلاً 01020"<?= $valAttr('receipt_number') ?>>
                </div>
                <?php endif; ?>
                <?php // ── بخش ۲: خدمت و مشخصات فنی ── ?>
                <div class="form-section-title"><?= field_icon('tooth') ?> خدمت و مشخصات فنی</div>
                <div class="form-group">
                    <label for="case-service-id"><?= field_icon('tooth') ?> خدمت</label>
                    <select id="case-service-id" name="service_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($prices as $price): ?>
                        <option value="<?= (int) $price['id'] ?>"<?= $selAttr('service_id', (int) $price['id']) ?>><?= $esc($price['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($scanBodyTypes): ?>
                <div class="form-group" id="case-scan-body-group" style="display:none;">
                    <label for="case-scan-body-type"><?= field_icon('cubes') ?> نوع اسکن‌بادی</label>
                    <select id="case-scan-body-type" name="scan_body_type_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($scanBodyTypes as $bt): ?>
                            <option value="<?= (int) $bt['id'] ?>"<?= $selAttr('scan_body_type_id', (int) $bt['id']) ?>><?= $esc((string) $bt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="case-connection-group" style="display:none;">
                    <label for="case-connection-type"><?= field_icon('screwdriver') ?> نوع اتصال</label>
                    <select id="case-connection-type" name="connection_type">
                        <option value="">انتخاب...</option>
                        <?php foreach (connectionTypes() as $ck => $ct): ?>
                            <option value="<?= $esc($ck) ?>"<?= $selAttr('connection_type', $ck) ?>><?= $esc($ct['fa']) ?> (<?= $esc($ct['en']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($fixtureTypes): ?>
                <div class="form-group" id="case-fixture-group" style="display:none;">
                    <label for="case-fixture-type"><?= field_icon('screwdriver') ?> نوع فیکسچر</label>
                    <select id="case-fixture-type" name="fixture_type_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($fixtureTypes as $ft): ?>
                            <option value="<?= (int) $ft['id'] ?>"<?= $selAttr('fixture_type_id', (int) $ft['id']) ?>><?= $esc((string) $ft['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="case-location-type"><?= field_icon('map-location') ?> مکان</label>
                    <select id="case-location-type" name="location_type">
                        <option value="">انتخاب...</option>
                        <option value="teeth"<?= $selAttr('location_type', 'teeth') ?>>دندان</option>
                        <option value="upper"<?= $selAttr('location_type', 'upper') ?>>فک بالا</option>
                        <option value="lower"<?= $selAttr('location_type', 'lower') ?>>فک پایین</option>
                        <option value="both"<?= $selAttr('location_type', 'both') ?>>هر دو</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>سایه (رنگ استاندارد)</label>
                    <div class="shade-picker" data-target="#case-shade">
                        <input type="hidden" id="case-shade" name="shade"<?= $valAttr('shade') ?>>
                        <div class="shade-groups">
                            <?php foreach (caseShadeGroups() as $shGroup => $shRow): ?>
                                <div class="shade-group" aria-label="<?= $esc($shGroup) ?>">
                                    <?php foreach ($shRow as $shCode => $shHex): ?>
                                        <button type="button" class="shade-swatch" data-shade="<?= $esc($shCode) ?>" style="--sw:<?= $shHex ?>" title="<?= $esc($shCode) ?>"><span class="shade-code"><?= $esc($shCode) ?></span></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="shade-group">
                                <button type="button" class="shade-swatch shade-clear" data-shade="" title="بدون سایه"><span class="shade-code">—</span></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label for="case-teeth-picker">دندان</label>
                    <div id="case-teeth-picker" class="case-teeth-picker" aria-label="انتخاب دندان‌ها"></div>
                    <input type="hidden" id="case-teeth" name="teeth"<?= $valAttr('teeth') ?>>
                </div>
                <div class="form-group">
                    <label for="case-quantity"><?= field_icon('hashtag') ?> تعداد <small id="case-qty-hint" style="color:#64748b; font-weight:400;">(خودکار)</small></label>
                    <input type="number" id="case-quantity" name="quantity"<?= $valAttr('quantity', '1') ?> readonly style="background:#f3f4f6; cursor:not-allowed;">
                </div>
                <?php // ── بخش ۳: قیمت‌گذاری و تاریخ ── ?>
                <div class="form-section-title"><?= field_icon('money') ?> قیمت‌گذاری و تاریخ</div>
                <?php if ($isDoctor): ?>
                <input type="hidden" id="case-unit-price" name="unit_price" step="1"<?= $valAttr('unit_price') ?>>
                <?php else: ?>
                <div class="form-group">
                    <label for="case-unit-price"><?= field_icon('money') ?> فی (تومان)</label>
                    <input type="number" id="case-unit-price" name="unit_price" step="1"<?= $valAttr('unit_price') ?>>
                    <small id="case-price-note" style="display:none; color:#0369a1; background:#e0f2fe; border-radius:6px; padding:4px 8px; margin-top:6px; line-height:1.9;"></small>
                </div>
                <?php if (!$isExternalViewer): ?>
                <div class="form-group" id="case-design-fee-group">
                    <label for="case-design-fee"><?= field_icon('pen-ruler') ?> فی طراحی (تومان)</label>
                    <input type="number" id="case-design-fee" name="design_fee" step="1" min="0"<?= $valAttr('design_fee', '0') ?>>
                    <small id="case-design-fee-note" style="display:none; color:#b45309; background:#fffbeb; border-radius:6px; padding:4px 8px; margin-top:6px;"></small>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($isDoctor): ?>
                <input type="hidden" id="case-received-date" name="received_date" value="<?= $esc($receivedJalali !== '' ? $receivedJalali : toJalaliDateFormatted(date('Y-m-d'))) ?>">
                <?php else: ?>
                <div class="form-group">
                    <label for="case-received-date"><?= field_icon('calendar-days') ?> تاریخ دریافت</label>
                    <input type="text" id="case-received-date" name="received_date" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;"<?= $valAttr('received_date') ?>>
                </div>
                <?php endif; ?>
                <?php // ── درخواست کست از طراح ──
                // کیس‌هایی که برای طراحی به «کست/مدل» نیاز دارند؛ طراح در لیست کیس‌ها
                // نشان «کست» می‌بیند و می‌تواند فقط کیس‌های درخواست‌کست‌دار را فیلتر کند.
                // کاربران بیرونی (پزشک/کلینیک/لابراتوار) این فیلد را نمی‌بینند. ?>
                <?php if (!$isExternalViewer || $isDoctor): ?>
                <div class="form-group" id="case-cast-group" style="grid-column:1/-1; border:1px solid #ddd6fe; border-radius:8px; background:#faf5ff; padding:10px 12px;">
                    <?php // ⚠️ ورودیِ پنهانِ «۰» قبل از چک‌باکس: اگر کاربر تیک را بردارد،
                    // مرورگر هیچ مقداری نمی‌فرستد و سرور «فیلد نبوده» را «تغییر نده» تفسیر
                    // می‌کند. با این ورودی، مقدار همیشه ارسال می‌شود (۰ = لغو درخواست). ?>
                    <input type="hidden" name="case_cast_requested" value="0">
                    <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; font-weight:700; color:#5b21b6; margin:0;">
                        <input type="checkbox" id="case-cast-requested" name="case_cast_requested" value="1" style="width:auto; margin-top:3px;"<?= $chkAttr('case_cast_requested') ?>>
                        <span>
                            🦷 درخواست کست از طراح
                            <small style="display:block; font-weight:400; color:#6b7280; margin-top:2px;">
                                اگر این کیس به کست/مدل نیاز دارد آن را تیک بزنید؛ طراحِ کیس در لیست کیس‌ها نشان «کست» می‌بیند و اعلان دریافت می‌کند.
                            </small>
                        </span>
                    </label>
                </div>
                <?php endif; ?>
                <?php // ── فوری (اولویتِ طراحی) ──
                // کیسِ فوری با نشانِ 🔥 در ستونِ اولِ جدول و در صفحهٔ کیس دیده می‌شود؛
                // طراح از همان نشان متوجه می‌شود این کار باید اول انجام شود.
                // کاربران بیرونی (پزشک/کلینیک/لابراتوار) این فیلد را نمی‌بینند. ?>
                <?php if (!$isExternalViewer || $isDoctor): ?>
                <div class="form-group" id="case-urgent-group" style="grid-column:1/-1; border:1px solid #fecaca; border-radius:8px; background:#fff1f2; padding:10px 12px;">
                    <?php // ورودیِ پنهانِ «۰» قبل از چک‌باکس تا برداشتنِ تیک هم به سرور برسد (۰ = لغو فوریت) ?>
                    <input type="hidden" name="is_urgent" value="0">
                    <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; font-weight:700; color:#b91c1c; margin:0;">
                        <input type="checkbox" id="case-urgent" name="is_urgent" value="1" style="width:auto; margin-top:3px;"<?= $chkAttr('is_urgent') ?>>
                        <span>
                            🔥 فوری
                            <small style="display:block; font-weight:400; color:#6b7280; margin-top:2px;">
                                این کیس در اولویتِ طراحی قرار می‌گیرد؛ طراح نشانِ «فوری» را در ستون اولِ لیست کیس‌ها و در صفحهٔ کیس می‌بیند.
                            </small>
                        </span>
                    </label>
                </div>
                <?php endif; ?>
                <?php if ($isDoctor): ?>
                <input type="hidden" id="case-status-id" name="status_id" value="<?= $esc($statusSelId !== '' ? $statusSelId : '1') ?>">
                <?php else: ?>
                <div class="form-group">
                    <label for="case-status-id"><?= field_icon('circle-info') ?> وضعیت</label>
                    <select id="case-status-id" name="status_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($statuses as $status): ?>
                        <?php if ($allowedStatusIds !== null && !in_array((int) $status['id'], $allowedStatusIds, true)) continue; ?>
                        <option value="<?= $status['id'] ?>"<?= $selAttr('status_id', (int) $status['id']) ?>><?= $esc(visibleStatusName($status['name'], $user)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group" style="grid-column:1/-1; margin-top:4px;">
                    <label for="case-description"><?= field_icon('comment-dots') ?> توضیحات</label>
                    <textarea id="case-description" name="description" rows="3"><?= $has('description') ? $esc($values['description']) : '' ?></textarea>
                </div>
                <?php if ($showFiles && ($isDoctor || !$isExternalViewer)): ?>
                <?php // ── بخش ۴: فایل‌های کیس ── ?>
                <div class="form-section-title"><?= field_icon('file-arrow-up') ?> فایل‌های کیس</div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label><?= field_icon('file-arrow-up') ?> فایل‌های کیس <small style="color:#64748b; font-weight:400;">(اختیاری)</small></label>
                    <?php if ($isDoctor): ?>
                    <small style="display:block; color:#525252; margin:0 0 8px; line-height:1.8;">
                        فایل‌های اسکن را از این‌جا آپلود کنید (اسکن، عکس، فایل دستگاه).
                        اگر فایل اسکن بفرستید، وضعیت کیس <b>«اسکن شد»</b> ثبت می‌شود؛ در غیر این‌صورت همان «ثبت شد» می‌ماند.
                    </small>
                    <?php else: ?>
                    <small style="display:block; color:#525252; margin:0 0 8px; line-height:1.8;">
                        می‌توانید چند «گروه فایل» بسازید؛ برای هر گروه <b>نوع فایل</b>، <b>توضیحات</b> و <b>بسته‌بندی ZIP</b> را جداگانه تعیین کنید
                        (مثلاً یک گروه «طراحی نهایی» و یک گروه «عکس بیمار»).
                        <br>
                        <b>آپلود پوشه:</b> با دکمهٔ «📁 انتخاب پوشه» می‌توانید یک پوشه را با همهٔ محتویات و زیرپوشه‌هایش انتخاب کنید،
                        یا پوشه را با ماوس داخل کادر رها کنید. ساختار پوشه‌ها در فهرست فایل‌های کیس حفظ می‌شود.
                        اگر می‌خواهید همهٔ فایل‌های پوشه در یک فایل ZIP ذخیره شوند، تیکِ «ذخیره به‌صورت یک فایل ZIP» را خودتان بزنید
                        (به‌صورت پیش‌فرض خاموش است).
                    </small>
                    <?php endif; ?>
                    <div id="cf-groups"></div>
                    <button type="button" id="cf-add-group" class="btn" style="background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0;">＋ افزودن گروه فایل دیگر</button>
                    <div id="cf-formats" style="margin-top:8px; font-size:.8rem; color:#64748b; line-height:1.8;">
                        فرمت‌های مجاز: <b>STL، PLY، STP، STEP، OBJ، 3MF</b> (اسکن/مدل سه‌بعدی) ·
                        <b>matrix4، dentalProject، iftScan، constructionInfo، DCM، DICOM</b> (فایل دستگاهٔ اسکن) ·
                        <b>JPG، JPEG، PNG، GIF، WEBP، BMP</b> (تصویر) ·
                        <b>RAR، ZIP</b> (بایگانی) · <b>PDF</b> · <b>TXT، XML، HTML</b>
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
                <?php if ($showSubcaseButton && $canCreateCases && !$isEdit): ?>
                <button type="submit" id="case-save-and-sub" class="btn" style="background:#7C3AED; color:#fff; display:none;"
                        title="ذخیرهٔ این کیس، سپس باز شدنِ خودکار فرمِ کیس زیرمجموعه">➕ ذخیره و ایجاد کیس زیرمجموعه</button>
                <?php endif; ?>
                <button type="submit" id="case-save" class="btn" style="background:#06B6D4;">ذخیره</button>
            </div>
        </form>
        <?php
        $html = (string) ob_get_clean();
        // نگاشت شناسه‌ها (اگر خواسته شده) — strtr کلیدهای بلندتر را اول جایگزین می‌کند
        if (!empty($idMap)) {
            $html = strtr($html, $idMap);
        }
        return $html;
    }
}

if (!function_exists('caseFormValuesFromCase')) {
    /**
     * نگاشتِ یک ردیفِ cases به آرایهٔ values برای renderCaseForm().
     * تاریخ دریافت به جلالی تبدیل می‌شود (فرم همیشه جلالی می‌فرستد/نمایش می‌دهد).
     */
    function caseFormValuesFromCase(array $case): array
    {
        $jDate = '';
        if (!empty($case['received_date'])) {
            $jDate = function_exists('toJalaliDateFormatted')
                ? toJalaliDateFormatted((string) $case['received_date'])
                : (string) $case['received_date'];
        }
        return [
            'id'                   => (int) ($case['id'] ?? 0),
            'parent_id'            => $case['parent_id'] ?? '',
            'case_type'            => $case['case_type'] ?? 'doctor',
            'doctor_id'            => $case['doctor_id'] ?? '',
            'lab_id'               => $case['lab_id'] ?? '',
            'designer_id'          => $case['designer_id'] ?? '',
            'clinic_id'            => $case['clinic_id'] ?? '',
            'patient_name'         => $case['patient_name'] ?? '',
            'receipt_number'       => $case['receipt_number'] ?? '',
            'service_id'           => $case['service_id'] ?? '',
            'scan_body_type_id'    => $case['scan_body_type_id'] ?? '',
            'connection_type'      => $case['connection_type'] ?? '',
            'fixture_type_id'      => $case['fixture_type_id'] ?? '',
            'location_type'        => $case['location_type'] ?? '',
            'shade'                => $case['shade'] ?? '',
            'teeth'                => $case['teeth'] ?? '',
            'quantity'             => (int) ($case['quantity'] ?? 1),
            'unit_price'           => $case['unit_price'] ?? '',
            'design_fee'           => $case['design_fee'] ?? 0,
            'received_date'        => $jDate,
            'case_cast_requested'  => !empty($case['case_cast_requested']),
            'is_urgent'            => !empty($case['is_urgent']),
            'status_id'            => $case['status_id'] ?? '',
            'description'          => $case['description'] ?? '',
            'outsourced_lab_id'    => $case['outsourced_lab_id'] ?? '',
            'outsourced_service_id' => $case['outsourced_service_id'] ?? '',
            'outsourced_qty'       => (int) ($case['outsourced_qty'] ?? 0),
            'outsourced_rate'      => ($case['outsourced_rate'] ?? null) !== null ? $case['outsourced_rate'] : '',
        ];
    }
}

if (!function_exists('caseFormContextForCurrentUser')) {
    /**
     * ساختِ ctx آماده برای renderCaseForm() از کاربرِ جاری (منبعِ مشترکِ سرورساید).
     * هر دو صفحهٔ «کیس‌ها» و «مشاهدهٔ کیس» و اندپوینتِ case_form.php از همین استفاده می‌کنند
     * تا فهرست‌ها/مجوزها/پیش‌فرض‌ها یکسان بمانند.
     *
     * @param array $overrides مقادیر دلخواه برای بازنویسی (mode, values, showFiles, ...)
     */
    function caseFormContextForCurrentUser(array $overrides = []): array
    {
        $user = function_exists('current_user') ? (current_user() ?: []) : [];
        $isDoctor = (($user['role'] ?? '') === 'doctor');
        $isExternalViewer = function_exists('isExternalCaseViewer') ? (bool) isExternalCaseViewer($user) : false;

        $doctors   = function_exists('getAllDoctors') ? getAllDoctors() : [];
        $designers = function_exists('getAllDesigners') ? getAllDesigners() : [];
        $defDes    = function_exists('getDefaultDesigner') ? getDefaultDesigner() : null;
        $prices    = function_exists('getAllPrices') ? getAllPrices() : [];
        $statuses  = function_exists('getAllCaseStatuses') ? getAllCaseStatuses() : [];
        $allowed   = function_exists('getAllowedStatusIdsForUser') ? getAllowedStatusIdsForUser() : [];
        $allLabs   = function_exists('getOutsourceLabOptions') ? getOutsourceLabOptions() : [];
        $scan      = function_exists('getScanBodyTypes') ? getScanBodyTypes() : [];
        $fixture   = function_exists('getFixtureTypes') ? getFixtureTypes() : [];
        $allClinics = function_exists('getAllClinics') ? getAllClinics() : [];

        $myClinicIds = [];
        if ($isDoctor && function_exists('getUserClinicIds')) {
            $myClinicIds = getUserClinicIds((int) ($user['id'] ?? 0));
        }
        $clinics = $allClinics;
        if ($isDoctor) {
            $clinics = array_values(array_filter($allClinics, static function ($c) use ($myClinicIds) {
                return in_array((int) ($c['id'] ?? 0), array_map('intval', $myClinicIds), true);
            }));
        }

        $clinicDefault = (int) ($myClinicIds[0] ?? 0);
        if ($clinicDefault <= 0 && function_exists('db') && !empty($user['id'])) {
            try {
                $st = db()->prepare('SELECT clinic_id FROM users WHERE id = ? LIMIT 1');
                $st->execute([(int) $user['id']]);
                $clinicDefault = (int) $st->fetchColumn();
            } catch (\Throwable $e) {
                $clinicDefault = 0;
            }
        }

        $csrf = '';
        if (isset($GLOBALS['csrf_token']) && is_string($GLOBALS['csrf_token'])) {
            $csrf = $GLOBALS['csrf_token'];
        } elseif (isset($_SESSION['_csrf_token']) && is_string($_SESSION['_csrf_token'])) {
            $csrf = $_SESSION['_csrf_token'];
        }

        $ctx = [
            'mode'              => 'create',
            'csrf_token'        => $csrf,
            'user'              => $user,
            'isDoctor'          => $isDoctor,
            'isExternalViewer'  => $isExternalViewer,
            'doctors'           => $doctors,
            'allLabs'           => $allLabs,
            'designers'         => $designers,
            'defaultDesignerId' => $defDes ? (int) $defDes['id'] : 0,
            'defaultDesigner'   => $defDes,
            'clinics'           => $clinics,
            'caseClinicDefault' => $clinicDefault,
            'prices'            => $prices,
            'scanBodyTypes'     => $scan,
            'fixtureTypes'      => $fixture,
            'statuses'          => $statuses,
            // آرایهٔ خالی = «همه مجاز»؛ برای رندر باید null شود
            'allowedStatusIds'  => !empty($allowed) ? $allowed : null,
            'canCreateCases'    => function_exists('has_permission') ? has_permission('create_cases') : false,
            'showFiles'         => true,
            'showSubcaseButton' => false,
            'values'            => [],
        ];

        return array_replace($ctx, $overrides);
    }
}

