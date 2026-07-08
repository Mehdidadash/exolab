<?php
// panel/cases.php
require_once __DIR__ . '/auth.php';
require_login();

if (!has_permission('view_all_cases') && !has_permission('view_own_cases')) {
    die('دسترسی غیرمجاز');
}

// CSRF token
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

$doctors = getAllDoctors(); // now returns from users table
$statusesStmt = db()->query('SELECT * FROM case_statuses ORDER BY name ASC');
$statuses = $statusesStmt->fetchAll();
// At the very top, after require_once
date_default_timezone_set('Asia/Tehran');

// Default: 30 days ago
$defaultDateFromGregorian = date('Y-m-d', strtotime('-30 days'));
$defaultDateFromJalali = toJalaliDateFormatted($defaultDateFromGregorian);

// Filters
$filterDoctorId = !empty($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : 0;
$filterStatusId = !empty($_GET['status_id']) ? (int) $_GET['status_id'] : 0;

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

<form method="get" class="form-card" style="margin-bottom: 20px;">
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
        <div class="form-group">
            <label for="doctor_id">پزشک</label>
            <select id="doctor_id" name="doctor_id">
                <option value="">همه پزشکان</option>
                <?php foreach ($doctors as $doctor): ?>
                    <option value="<?= $doctor['id'] ?>" <?= $doctor['id'] === $filterDoctorId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($doctor['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- ADDED: status filter dropdown -->
        <div class="form-group">
            <label for="status_id">وضعیت</label>
            <select id="status_id" name="status_id">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= $status['id'] ?>" <?= $status['id'] === $filterStatusId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($status['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="date_from">از تاریخ دریافت</label>
            <input type="text" id="date_from" name="date_from" 
       value="<?= htmlspecialchars(toJalaliDateFormatted($filterDateFrom)) ?>" 
       placeholder="۱۴۰۳/۰۱/۰۱">
        </div>

        <div class="form-group">
            <label for="date_to">تا تاریخ دریافت</label>
            <input type="text" id="date_to" name="date_to" 
       value="<?= htmlspecialchars($filterDateTo ? toJalaliDateFormatted($filterDateTo) : '') ?>" 
       placeholder="۱۴۰۳/۰۱/۰۱">
        </div>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px;">
        <button type="submit" class="btn">اعمال فیلتر</button>
        <a href="cases.php" class="btn" style="background: #E5E7EB; color: #0F172A;">پاک کردن فیلترها</a>
    </div>
</form>

<p style="margin-bottom: 16px; font-weight: 700;">تعداد کیس‌ها: <span id="cases-count">—</span></p>

<table id="cases-table" class="display" style="width:100%">
    <thead>
    <tr>
        <th>Case ID</th>
        <th>پزشک</th>
        <th>بیمار</th>
        <th>خدمت</th>
        <th>مکان</th>
        <th>سایه</th>
        <th>قیمت</th>
        <th>وضعیت</th>
        <th>تاریخ دریافت</th>
        <th>فاکتور</th>
        <th>عملیات</th>
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
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label for="case-doctor-id">پزشک</label>
                    <select id="case-doctor-id" name="doctor_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($doctors as $doctor): ?>
                        <option value="<?= $doctor['id'] ?>"><?= htmlspecialchars($doctor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="case-patient-name">نام بیمار</label>
                    <input id="case-patient-name" name="patient_name" required>
                </div>
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
                <div class="form-group">
                    <label for="case-teeth">دندان (مثال: 11,12)</label>
                    <input id="case-teeth" name="teeth">
                </div>
                <div class="form-group">
                    <label for="case-shade">سایه</label>
                    <input id="case-shade" name="shade">
                </div>
                <div class="form-group">
                    <label for="case-quantity">تعداد</label>
                    <input type="number" id="case-quantity" name="quantity" value="1">
                </div>
                <div class="form-group">
                    <label for="case-unit-price">فی (تومان)</label>
                    <input type="number" id="case-unit-price" name="unit_price" step="0.01">
                </div>
                <div class="form-group">
                    <label for="case-received-date">تاریخ دریافت</label>
                    <input type="text" id="case-received-date" name="received_date" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;">
                </div>
                <div class="form-group">
                    <label for="case-status-id">وضعیت</label>
                    <select id="case-status-id" name="status_id">
                        <option value="">انتخاب...</option>
                        <?php foreach ($statuses as $status): ?>
                        <option value="<?= $status['id'] ?>"><?= htmlspecialchars($status['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/3;">
                    <label for="case-description">توضیحات</label>
                    <textarea id="case-description" name="description" rows="3"></textarea>
                </div>
                <div class="form-group" style="grid-column:1/3;">
                    <label for="case-files">فایل طراحی (STL / PLY)</label>
                    <input type="file" id="case-files" name="case_files[]" accept=".stl,.ply" multiple>
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px; justify-content:flex-end;">
                <button type="button" id="case-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
                <button type="submit" id="case-save" class="btn" style="background:#06B6D4;">ذخیره</button>
            </div>
        </form>
    </div>
</div>

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

<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<link rel="stylesheet" href="../assets/css/datatables.min.css">
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
<script src="../assets/js/jquery-3.6.0.min.js"></script>
<script src="../assets/js/datatables.min.js"></script>
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>

<script>
    (function(){
        var todayJalali = '<?= toJalaliDateFormatted(date('Y-m-d')) ?>';

        function getDatepickerPlugin() {
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.persianDatepicker === 'function') {
                return window.jQuery.fn.persianDatepicker;
            }
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.pDatepicker === 'function') {
                return window.jQuery.fn.pDatepicker;
            }
            return null;
        }

        function initJalaliPicker(selector) {
            var plugin = getDatepickerPlugin();
            if (!plugin) return false;
            jQuery(selector).each(function(){
                var $el = jQuery(this);
                try {
                    $el.persianDatepicker({
                        format: 'YYYY/MM/DD',
                        calendarType: 'persian',
                        initialValue: false,          // <-- crucial
                        initialValueType: 'jalali',
                        persianDigit: true,
                        autoClose: true
                    });
                } catch (e) {
                    try { $el.pDatepicker({
                        format: 'YYYY/MM/DD',
                        calendarType: 'persian',
                        initialValue: false,
                        initialValueType: 'jalali',
                        persianDigit: true,
                        autoClose: true
                    }); } catch (e2) {}
                }
            });
            return true;
        }

        initJalaliPicker('#date_from');
        initJalaliPicker('#date_to');
        // Ensure date_from has the correct default value (30 days ago)
        var defaultDateFrom = '<?= toJalaliDateFormatted($defaultDateFromGregorian) ?>';
        if (jQuery('#date_from').val() === '') {
            jQuery('#date_from').val(defaultDateFrom);
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

        if (window.jQuery && typeof jQuery.fn.DataTable === 'function') {
            var table = jQuery('#cases-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: 'cases_data.php',
                    data: function(d) {
                        d.doctor_id = jQuery('#doctor_id').val();
                        d.status_id = jQuery('#status_id').val();
                        d.date_from = jQuery('#date_from').val();
                        d.date_to = jQuery('#date_to').val();
                    }
                },
                order: [[8, 'desc']],
                responsive: true,
                pageLength: 25,
                columns: [
                    { data: 0 },
                    { data: 1 },
                    { data: 2 },
                    { data: 3 },
                    { data: 4 },
                    { data: 5 },
                    { data: 6 },
                    { data: 7 },
                    { data: 8 },
                    { data: 9 },
                    { data: 10, orderable: false, searchable: false }
                ],
                // Persian language for DataTables
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

            jQuery('form.form-card').on('submit', function(e){
                e.preventDefault();
                table.ajax.reload();
            });

            jQuery('#add-case-btn').on('click', function(e){
                e.preventDefault();
                jQuery('#case-form')[0].reset();
                jQuery('#case-id').val('');
                openCaseModal();
            });

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
                jQuery.post('delete_case.php', { id: deleteId, _csrf_token: '<?= htmlspecialchars($csrf_token) ?>' }, function(resp){
                    try { var j = (typeof resp === 'string') ? JSON.parse(resp) : resp; } catch(e){ j = { success: false }; }
                    if (j.success) {
                        table.ajax.reload(null, false);
                    } else {
                        alert('حذف انجام نشد');
                    }
                }).fail(function(){ alert('خطا در حذف'); });
            });
            jQuery('#delete-cancel').on('click', function(){
                jQuery('#delete-modal').css({display: 'none'});
                deleteId = null;
            });

            function openCaseModal(title){
                jQuery('#case-modal-title').text(title || 'افزودن کیس جدید');
                jQuery('#case-modal').css({display: 'flex'});
                if (!jQuery('#case-received-date').val()) {
                    jQuery('#case-received-date').val(todayJalali);
                }
                setTimeout(function(){ initCaseReceivedDate(); }, 100);
            }
            function closeCaseModal(){
                jQuery('#case-modal').css({display: 'none'});
                jQuery('#case-form')[0].reset();
                setTimeout(function(){ jQuery('#case-received-date').val(''); }, 100);
            }
            jQuery('#case-cancel').on('click', function(){ closeCaseModal(); });

            jQuery(document).on('click', '.action-toggle', function(e){
                e.stopPropagation();
                var $menu = jQuery(this).siblings('.action-menu');
                jQuery('.action-menu').not($menu).hide();
                $menu.toggle();
            });
            jQuery(document).on('click', function(){ jQuery('.action-menu').hide(); });

            function populateCaseForm(data){
                jQuery('#case-id').val(data.id || '');
                jQuery('#case-doctor-id').val(data.doctor_id || '');
                jQuery('#case-patient-name').val(data.patient_name || '');
                jQuery('#case-service-id').val(data.service_id || '');
                jQuery('#case-location-type').val(data.location_type || '');
                jQuery('#case-teeth').val(data.teeth || '');
                jQuery('#case-shade').val(data.shade || '');
                jQuery('#case-quantity').val(data.quantity || 1);
                jQuery('#case-unit-price').val(data.unit_price || '');
                jQuery('#case-received-date').val(data.received_date || ''); // sets correct date
                jQuery('#case-status-id').val(data.status_id || '');
                jQuery('#case-description').val(data.description || '');
            }

            jQuery('#case-form').on('submit', function(e){
                e.preventDefault();
                var formEl = jQuery(this)[0];
                var fd = new FormData(formEl);
                fd.set('_csrf_token', '<?= htmlspecialchars($csrf_token) ?>');
                if (!jQuery('#case-received-date').val()) {
                    fd.set('received_date', todayJalali);
                }
                jQuery.ajax({
                    url: 'save_case.php',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp){
                        try { var j = (typeof resp === 'string') ? JSON.parse(resp) : resp; } catch(e){ j = { success: false }; }
                        if (j.success) {
                            closeCaseModal();
                            table.ajax.reload(null, false);
                        } else {
                            alert('ذخیره انجام نشد: ' + (j.message || ''));
                        }
                    },
                    error: function(){ alert('خطا در سرور'); }
                });
            });
            // Auto-update unit price when doctor or service changes in the modal
            jQuery('#case-doctor-id, #case-service-id').on('change', function() {
                var doctorId = jQuery('#case-doctor-id').val();
                var serviceId = jQuery('#case-service-id').val();
                if (doctorId && serviceId) {
                    jQuery.ajax({
                        url: 'get_price.php',
                        data: { doctor_id: doctorId, service_id: serviceId },
                        dataType: 'json',
                        success: function(resp) {
                            if (resp.price !== null) {
                                jQuery('#case-unit-price').val(resp.price);
                            } else {
                                // If no price, clear the field or set to 0
                                jQuery('#case-unit-price').val('');
                            }
                        }
                    });
                }
            });

        }

    })();
</script>

<?php panel_layout_end(); ?>