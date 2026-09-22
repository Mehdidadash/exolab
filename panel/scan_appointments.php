<?php
// panel/scan_appointments.php
// نوبت‌دهی اسکن: تقویم (FullCalendar) + لیست + ثبت/ویرایش نوبت
//   • مدیر/مدیر شعبه/کارمند: ثبت و ویرایش همهٔ نوبت‌های شعبه
//   • پزشک: فقط مشاهدهٔ نوبت‌های خودش
require_once __DIR__ . '/auth.php';
require_login();

$user      = current_user();
$isDoctor  = (($user['role'] ?? '') === 'doctor');
$canManage = canManageScanAppointments($user);

// توکن CSRF برای درخواست‌های AJAX همین صفحه
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

if (!$canManage && !$isDoctor) {
    panel_layout_start('نوبت اسکن');
    echo '<div class="form-card"><p>این بخش برای مدیران، کارکنان شعبه و پزشکان است. شما به نوبت‌های اسکن دسترسی ندارید.</p>'
       . '<p><a class="btn" href="dashboard.php">بازگشت به داشبورد</a></p></div>';
    panel_layout_end();
    exit;
}

$types    = scanAppointmentTypes();
$statuses = scanAppointmentStatuses();

// پزشکان (برای فیلتر و فرم) — برای پزشک فقط خودش
$doctors = [];
if (!$isDoctor) {
    foreach (getAllDoctors() as $d) {
        $doctors[] = ['id' => (int) $d['id'], 'name' => (string) ($d['name'] ?? $d['full_name'] ?? '')];
    }
}

// آمار سریع
$today     = date('Y-m-d');
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$weekEnd   = date('Y-m-d', strtotime('+7 days'));
$todayAppts    = getScanAppointments(['from' => $today, 'to' => $today, 'limit' => 200]);
$tomorrowAppts = getScanAppointments(['from' => $tomorrow, 'to' => $tomorrow, 'limit' => 200]);
$weekAppts     = getScanAppointments(['from' => $today, 'to' => $weekEnd, 'limit' => 500]);
$weekScanBody  = 0;
foreach ($weekAppts as $w) { if (!empty($w['needs_scan_body'])) $weekScanBody++; }

// جدول نوبت‌های پیش‌رو (۶۰ روز آینده) — برای نمای لیستی
$upcoming = getScanAppointments(['from' => $today, 'to' => date('Y-m-d', strtotime('+60 days')), 'limit' => 300]);

panel_layout_start('نوبت اسکن');
?>
<style>
    .sa-head{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .sa-stats{ display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:10px; margin-bottom:14px; }
    .sa-stat{ background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; }
    .sa-stat b{ display:block; font-size:1.4rem; color:#0f172a; }
    .sa-stat span{ font-size:.82rem; color:#64748b; }
    .sa-filters{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .sa-filters select, .sa-filters input{ padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; max-width:190px; }
    #sa-calendar{ background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px; min-height:520px; }
    .fc .fc-toolbar-title{ font-size:1.05rem; }
    .fc-event{ cursor:pointer; border:none; }
    .fc-event.sa-done{ opacity:.55; text-decoration:line-through; }
    .fc-event.sa-canceled{ opacity:.45; filter:grayscale(1); }
    .sa-ev{ font-size:.78rem; line-height:1.5; overflow:hidden; }
    .sa-ev .t{ font-weight:700; }
    .sa-ev .b{ background:rgba(255,255,255,.25); border-radius:4px; padding:0 3px; }
    .sa-legend{ display:flex; gap:12px; flex-wrap:wrap; font-size:.8rem; color:#334155; margin:8px 0 0; }
    .sa-legend i{ display:inline-block; width:10px; height:10px; border-radius:3px; margin-inline-end:4px; }
    .sa-modal{ position:fixed; inset:0; display:none; align-items:flex-start; justify-content:center; background:rgba(0,0,0,.45); z-index:9999; overflow:auto; padding:24px 12px; }
    .sa-modal .sa-box{ background:#fff; border-radius:12px; width:760px; max-width:100%; padding:18px; }
    .sa-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(210px,1fr)); gap:10px; }
    .sa-grid .fg{ display:flex; flex-direction:column; gap:4px; }
    .sa-grid label{ font-size:.82rem; font-weight:600; color:#334155; }
    .sa-grid input, .sa-grid select, .sa-grid textarea{ padding:7px 9px; border:1px solid #d1d5db; border-radius:7px; }
    .sa-body-check{ display:flex; align-items:center; gap:8px; background:#fffbeb; border:1px dashed #f59e0b; color:#92400e; border-radius:8px; padding:8px 10px; font-weight:700; font-size:.85rem; }
    .sa-msg{ display:none; margin:10px 0; padding:10px 12px; border-radius:8px; font-size:.9rem; line-height:1.9; }
    .sa-msg.err{ background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; display:block; }
    .sa-msg.ok{ background:#f0fdf4; border:1px solid #86efac; color:#166534; display:block; }
</style>

<div class="sa-head">
    <div>
        <h3 style="margin:0;">🗓 نوبت‌دهی اسکن</h3>
        <p style="margin:4px 0 0; color:#64748b; font-size:.86rem;">
            نوبت هر پزشک با بازهٔ ساعتی (از چه ساعتی تا چه ساعتی برویم). برای کیس‌های ایمپلنت، «اسکن‌بادی» را تیک بزنید تا همراه برده شود.
        </p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($canManage): ?>
        <button type="button" id="sa-new" class="btn" style="background:#0F172A; color:#fff;">➕ نوبت جدید</button>
        <?php endif; ?>
        <a class="btn" href="#sa-list" style="background:#E5E7EB; color:#0F172A;">📋 لیست نوبت‌ها</a>
    </div>
</div>

<div class="sa-stats">
    <div class="sa-stat"><b><?= toPersianDigits((string) count($todayAppts)) ?></b><span>نوبت امروز (<?= toJalaliDateFormatted($today) ?>)</span></div>
    <div class="sa-stat"><b><?= toPersianDigits((string) count($tomorrowAppts)) ?></b><span>نوبت فردا</span></div>
    <div class="sa-stat"><b><?= toPersianDigits((string) count($weekAppts)) ?></b><span>نوبت هفتهٔ آینده</span></div>
    <div class="sa-stat"><b style="color:#b45309;"><?= toPersianDigits((string) $weekScanBody) ?></b><span>🧩 نیازمند اسکن‌بادی (این هفته)</span></div>
</div>

<div class="form-card" style="margin-bottom:14px;">
    <div class="sa-filters">
        <?php if (!$isDoctor): ?>
        <select id="sa-f-doctor">
            <option value="">همهٔ پزشکان</option>
            <?php foreach ($doctors as $d): ?>
                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select id="sa-f-type">
            <option value="">همهٔ نوع‌ها</option>
            <?php foreach ($types as $k => $t): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= $t['icon'] ?> <?= htmlspecialchars($t['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="sa-f-status">
            <option value="">همهٔ وضعیت‌ها</option>
            <?php foreach ($statuses as $k => $s): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($s['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="sa-f-q" placeholder="جست‌وجو: بیمار، پزشک، آدرس...">
        <button type="button" id="sa-f-apply" class="btn" style="background:#06B6D4; color:#fff;">اعمال فیلتر</button>
        <button type="button" id="sa-f-clear" class="btn" style="background:#E5E7EB; color:#0F172A;">پاک کردن</button>
    </div>
    <div class="sa-legend">
        <?php foreach ($types as $t): ?>
            <span><i style="background:<?= htmlspecialchars($t['color']) ?>;"></i><?= $t['icon'] ?> <?= htmlspecialchars($t['label']) ?></span>
        <?php endforeach; ?>
        <span>🧩 = اسکن‌بادی همراه برده شود</span>
    </div>
</div>

<div id="sa-calendar"></div>
<div id="sa-fallback" style="display:none;" class="form-card" >
    <p style="margin:0; color:#b45309;">⚠️ کتابخانهٔ تقویم (FullCalendar) بارگذاری نشد. نوبت‌ها را از لیست زیر ببینید و ثبت کنید.</p>
    <p style="margin:6px 0 0; font-size:.82rem; color:#92400e;">فایل مورد نیاز روی هاست: <code style="direction:ltr;">assets/lib/fullcalendar/fullcalendar.global.min.js</code> و <code style="direction:ltr;">fa.global.min.js</code> (بدون CDN).</p>
</div>
<div id="sa-feed-error" class="form-card" style="display:none; border-color:#fca5a5; background:#fef2f2; color:#991b1b;">
    <p style="margin:0;">⚠️ دریافت نوبت‌ها از سرور ناموفق بود. صفحه را دوباره باز کنید؛ اگر تکرار شد موضوع را به مدیر سایت اطلاع دهید.</p>
</div>

<div class="form-card" id="sa-list" style="margin-top:16px;">
    <h4 style="margin:0 0 10px;">📋 نوبت‌های پیش‌رو (۶۰ روز آینده)</h4>
    <?php if (empty($upcoming)): ?>
        <p class="empty" style="margin:0;">نوبتی ثبت نشده است.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="datatable display" style="width:100%; font-size:.9rem;">
            <thead>
            <tr>
                <th>تاریخ</th><th>ساعت</th><th>پزشک</th><th>بیمار</th><th>کیس</th><th>نوع</th>
                <th>اسکن‌بادی</th><th>وضعیت</th><th>آدرس</th><th>عملیات</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($upcoming as $a):
                $tMeta = $types[(string) $a['appt_type']] ?? ['label' => '—', 'icon' => '📌', 'color' => '#64748b'];
                $sMeta = $statuses[(string) $a['status']] ?? ['label' => '—', 'color' => '#64748b'];
            ?>
                <tr>
                    <td data-order="<?= htmlspecialchars((string) $a['appt_date']) ?>"><?= toJalaliDateFormatted((string) $a['appt_date']) ?></td>
                    <td><?= toPersianDigits(substr((string) $a['start_time'], 0, 5)) ?><?= !empty($a['end_time']) ? ' - ' . toPersianDigits(substr((string) $a['end_time'], 0, 5)) : '' ?></td>
                    <td><?= htmlspecialchars((string) ($a['doctor_name'] ?? '—')) ?></td>
                    <td><?= htmlspecialchars((string) ($a['patient_name'] ?: ($a['case_patient'] ?? '—'))) ?></td>
                    <td><?= !empty($a['case_id']) ? '<a href="view_case.php?id=' . (int) $a['case_id'] . '">#' . (int) $a['case_id'] . '</a>' : '—' ?></td>
                    <td><span style="background:<?= htmlspecialchars($tMeta['color']) ?>; color:#fff; border-radius:6px; padding:2px 7px; font-size:.78rem;"><?= $tMeta['icon'] ?> <?= htmlspecialchars($tMeta['label']) ?></span></td>
                    <td style="text-align:center;"><?= !empty($a['needs_scan_body']) ? '<span style="background:#fef3c7; color:#92400e; border-radius:6px; padding:2px 7px; font-weight:700;">🧩 بله</span>' : '—' ?></td>
                    <td><span style="background:<?= htmlspecialchars($sMeta['color']) ?>; color:#fff; border-radius:6px; padding:2px 7px; font-size:.78rem;"><?= htmlspecialchars($sMeta['label']) ?></span></td>
                    <td style="max-width:200px; white-space:normal;"><?= htmlspecialchars((string) ($a['address'] ?? '')) ?: '—' ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($canManage): ?>
                            <button type="button" class="btn js-sa-edit" data-id="<?= (int) $a['id'] ?>" style="background:#e0f2fe; color:#0369a1; padding:3px 8px;">ویرایش</button>
                            <button type="button" class="btn js-sa-del" data-id="<?= (int) $a['id'] ?>" style="background:#fee2e2; color:#991b1b; padding:3px 8px;">حذف</button>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<!-- مودال ثبت/ویرایش نوبت -->
<div id="sa-modal" class="sa-modal">
    <div class="sa-box">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
            <h4 id="sa-modal-title" style="margin:0;">➕ نوبت اسکن جدید</h4>
            <button type="button" id="sa-close" class="btn" style="background:#E5E7EB; color:#0F172A; padding:4px 12px;">✕</button>
        </div>

        <div id="sa-msg" class="sa-msg"></div>

        <form id="sa-form" style="margin-top:10px;">
            <input type="hidden" id="sa-id" value="">
            <div class="sa-grid">
                <div class="fg">
                    <label for="sa-date">تاریخ نوبت (شمسی)</label>
                    <input type="text" id="sa-date" placeholder="۱۴۰۵/۰۶/۲۹" required style="cursor:pointer;">
                </div>
                <div class="fg">
                    <label for="sa-start">از ساعت</label>
                    <input type="time" id="sa-start" required>
                </div>
                <div class="fg">
                    <label for="sa-end">تا ساعت</label>
                    <input type="time" id="sa-end">
                </div>
                <div class="fg">
                    <label for="sa-doctor">پزشک</label>
                    <select id="sa-doctor">
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach ($doctors as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label for="sa-case">شمارهٔ کیس (اختیاری)</label>
                    <input type="number" id="sa-case" placeholder="مثلاً ۱۲۳۴" min="0">
                    <small id="sa-case-info" style="color:#0369a1;"></small>
                </div>
                <div class="fg">
                    <label for="sa-patient">نام بیمار</label>
                    <input type="text" id="sa-patient">
                </div>
                <div class="fg">
                    <label for="sa-type">نوع نوبت</label>
                    <select id="sa-type">
                        <?php foreach ($types as $k => $t): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= $t['icon'] ?> <?= htmlspecialchars($t['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label for="sa-status">وضعیت</label>
                    <select id="sa-status">
                        <?php foreach ($statuses as $k => $s): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($s['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label for="sa-phone">تلفن</label>
                    <input type="text" id="sa-phone">
                </div>
                <div class="fg" style="grid-column:1/-1;">
                    <label for="sa-address">آدرس</label>
                    <input type="text" id="sa-address" placeholder="آدرس مطب/کلینیک برای مراجعه">
                </div>
                <div class="fg" style="grid-column:1/-1;">
                    <div class="sa-body-check">
                        <input type="checkbox" id="sa-body" style="width:auto;">
                        <label for="sa-body" style="margin:0;">🧩 اسکن‌بادی باید همراه برده شود (کیس ایمپلنت)</label>
                    </div>
                </div>
                <div class="fg" style="grid-column:1/-1;">
                    <label for="sa-title">عنوان/موضوع (اختیاری)</label>
                    <input type="text" id="sa-title" placeholder="مثلاً: اسکن فک بالا + بادی">
                </div>
                <div class="fg" style="grid-column:1/-1;">
                    <label for="sa-notes">توضیحات</label>
                    <textarea id="sa-notes" rows="3"></textarea>
                </div>
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; align-items:center;">
                <button type="submit" id="sa-save" class="btn" style="background:#06B6D4; color:#fff;">ذخیرهٔ نوبت</button>
                <button type="button" id="sa-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
                <button type="button" id="sa-delete" class="btn" style="background:#fee2e2; color:#991b1b; display:none; margin-inline-start:auto;">🗑 حذف نوبت</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<!-- FullCalendar به‌صورت لوکال (بدون CDN — روی هاستی که اینترنت بین‌الملل ندارد هم کار می‌کند) -->
<script src="../assets/lib/fullcalendar/fullcalendar.global.min.js"></script>
<script src="../assets/lib/fullcalendar/fa.global.min.js"></script>
<script>
(function(){
    var CSRF       = '<?= htmlspecialchars($csrf_token) ?>';
    var CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
    var IS_DOCTOR  = <?= $isDoctor ? 'true' : 'false' ?>;
    var DOCTOR_ID  = <?= $isDoctor ? (int) $user['id'] : 0 ?>;
    var TYPES      = <?= json_encode($types, JSON_UNESCAPED_UNICODE) ?>;
    var STATUSES   = <?= json_encode($statuses, JSON_UNESCAPED_UNICODE) ?>;

    function pd(d){ return (typeof persianDate !== 'undefined') ? new persianDate(d) : null; }
    function faDigits(s){ return String(s == null ? '' : s).replace(/\d/g, function(c){ return '۰۱۲۳۴۵۶۷۸۹'[c]; }); }
    function jDate(d){ var p = pd(d); return p ? faDigits(p.format('YYYY/MM/DD')) : ''; }
    function jTitle(d){ var p = pd(d); return p ? faDigits(p.format('MMMM YYYY')) : ''; }
    function jWeekday(d){ var p = pd(d); return p ? p.format('dddd') : ''; }
    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

    function showMsg(kind, text, details){
        var box = document.getElementById('sa-msg');
        if (!box) { alert(text); return; }
        var html = (kind === 'ok' ? '✅ ' : '❌ ') + esc(text || '');
        if (details && details.length) {
            html += '<ul style="margin:6px 0 0; padding-inline-start:18px;">';
            details.forEach(function(d){ html += '<li>' + esc(d) + '</li>'; });
            html += '</ul>';
        }
        box.className = 'sa-msg ' + (kind === 'ok' ? 'ok' : 'err');
        box.innerHTML = html;
        try { box.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch(e){}
    }
    function clearMsg(){ var b = document.getElementById('sa-msg'); if (b) { b.className = 'sa-msg'; b.innerHTML = ''; } }

    function post(url, data){
        var fd = new FormData();
        Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        fd.append('_csrf_token', CSRF);
        return fetch(url, { method:'POST', headers:{ 'X-CSRF-Token': CSRF, 'Accept':'application/json' }, body: fd })
            .then(function(r){ return r.json().catch(function(){ return { success:false, message:'پاسخ نامعتبر سرور' }; }); });
    }

    // ─── مودال ───
    var modal = document.getElementById('sa-modal');
    function openModal(id, prefill){
        if (!modal) return;
        initDatePicker();   // اگر تقویم FullCalendar بارگذاری نشده باشد هم فیلد تاریخ شمسی کار کند
        clearMsg();
        var editing = !!id;
        document.getElementById('sa-modal-title').textContent = editing ? '✏️ ویرایش نوبت' : '➕ نوبت اسکن جدید';
        document.getElementById('sa-delete').style.display = editing ? 'inline-block' : 'none';
        document.getElementById('sa-id').value = id || '';
        if (!editing) {
            document.getElementById('sa-form').reset();
            document.getElementById('sa-id').value = '';
            document.getElementById('sa-body').checked = false;
            document.getElementById('sa-case-info').textContent = '';
            if (prefill) {
                if (prefill.date) setDateField(prefill.date);
                if (prefill.start) document.getElementById('sa-start').value = prefill.start;
                if (prefill.end)   document.getElementById('sa-end').value = prefill.end;
                if (prefill.doctor_id) document.getElementById('sa-doctor').value = prefill.doctor_id;
                if (prefill.case_id) { document.getElementById('sa-case').value = prefill.case_id; loadCaseInfo(); }
                if (prefill.patient) document.getElementById('sa-patient').value = prefill.patient;
                if (prefill.type) document.getElementById('sa-type').value = prefill.type;
                if (prefill.needs_scan_body) document.getElementById('sa-body').checked = true;
            }
            document.getElementById('sa-status').value = 'scheduled';
        }
        modal.style.display = 'flex';
    }
    function closeModal(){ if (modal) modal.style.display = 'none'; }

    // فیلد تاریخ شمسی (با persian-datepicker اگر موجود باشد، وگرنه متن ساده)
    var datePickerReady = false;
    function initDatePicker(){
        if (datePickerReady || typeof jQuery === 'undefined' || !jQuery.fn.persianDatepicker) return;
        jQuery('#sa-date').persianDatepicker({
            format: 'YYYY/MM/DD', initialValue: true, persianDigit: true, autoClose: true,
            calendar: { persian: { locale: 'fa' } }
        });
        datePickerReady = true;
    }
    function setDateField(gDate){
        var p = pd(gDate);
        var val = p ? faDigits(p.format('YYYY/MM/DD')) : '';
        var el = document.getElementById('sa-date');
        el.value = val;
        try { jQuery('#sa-date').persianDatepicker('setDate', val); } catch(e){}
    }

    // اطلاعات کیس (پزشک/بیمار) با شمارهٔ کیس
    function loadCaseInfo(){
        var cid = parseInt(document.getElementById('sa-case').value || '0', 10);
        var info = document.getElementById('sa-case-info');
        if (!cid) { info.textContent = ''; return; }
        fetch('get_case.php?id=' + encodeURIComponent(cid), { headers:{ 'Accept':'application/json' } })
            .then(function(r){ return r.json(); })
            .then(function(j){
                var c = (j && (j.case || j)) || null;
                if (!c || !c.id) { info.textContent = 'کیس پیدا نشد.'; info.style.color = '#b91c1c'; return; }
                info.style.color = '#0369a1';
                var bits = [];
                if (c.patient_name) bits.push('بیمار: ' + c.patient_name);
                if (c.service_title) bits.push('خدمت: ' + c.service_title);
                info.textContent = bits.join(' | ');
                if (c.patient_name && !document.getElementById('sa-patient').value) {
                    document.getElementById('sa-patient').value = c.patient_name;
                }
                if (c.doctor_id && !document.getElementById('sa-doctor').value) {
                    document.getElementById('sa-doctor').value = c.doctor_id;
                }
            })
            .catch(function(){ info.textContent = ''; });
    }
    var caseEl = document.getElementById('sa-case');
    if (caseEl) {
        caseEl.addEventListener('change', loadCaseInfo);
        caseEl.addEventListener('blur', loadCaseInfo);
    }

    // حذف نوبت
    function deleteAppt(id){
        if (!confirm('این نوبت حذف شود؟')) return;
        post('delete_scan_appointment.php', { id: id }).then(function(r){
            if (r && r.success) {
                closeModal();
                refreshCalendar();
                location.reload();
            } else {
                showMsg('err', (r && r.message) || 'حذف انجام نشد.');
            }
        });
    }
    var delBtn = document.getElementById('sa-delete');
    if (delBtn) delBtn.addEventListener('click', function(){
        var id = parseInt(document.getElementById('sa-id').value || '0', 10);
        if (id) deleteAppt(id);
    });
    var cancelBtn = document.getElementById('sa-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    var closeBtn = document.getElementById('sa-close');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function(e){
        if ((e.key === 'Escape' || e.key === 'Esc') && modal && modal.style.display === 'flex') closeModal();
    });
    var newBtn = document.getElementById('sa-new');
    if (newBtn) newBtn.addEventListener('click', function(){ openModal(null, { date: new Date() }); });

    // ثبت فرم
    var form = document.getElementById('sa-form');
    if (form) form.addEventListener('submit', function(e){
        e.preventDefault();
        clearMsg();
        var saveBtn = document.getElementById('sa-save');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'در حال ذخیره...'; }
        var payload = {
            id:          document.getElementById('sa-id').value || '',
            appt_date:   document.getElementById('sa-date').value,
            start_time:  document.getElementById('sa-start').value,
            end_time:    document.getElementById('sa-end').value,
            doctor_id:   document.getElementById('sa-doctor').value,
            case_id:     document.getElementById('sa-case').value,
            patient_name:document.getElementById('sa-patient').value,
            appt_type:   document.getElementById('sa-type').value,
            status:      document.getElementById('sa-status').value,
            phone:       document.getElementById('sa-phone').value,
            address:     document.getElementById('sa-address').value,
            title:       document.getElementById('sa-title').value,
            notes:       document.getElementById('sa-notes').value,
            needs_scan_body: document.getElementById('sa-body').checked ? 1 : 0
        };
        post('save_scan_appointment.php', payload).then(function(r){
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'ذخیرهٔ نوبت'; }
            if (r && r.success) {
                showMsg('ok', r.message || 'نوبت ذخیره شد.');
                setTimeout(function(){ location.reload(); }, 600);
            } else {
                showMsg('err', (r && r.message) || 'ثبت نوبت انجام نشد.', (r && r.errors) || []);
            }
        }).catch(function(){
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'ذخیرهٔ نوبت'; }
            showMsg('err', 'خطا در ارتباط با سرور.');
        });
    });

    // ویرایش/حذف از داخل لیست
    document.addEventListener('click', function(e){
        var ed = e.target.closest && e.target.closest('.js-sa-edit');
        if (ed) {
            e.preventDefault();
            openForEdit(parseInt(ed.getAttribute('data-id'), 10));
            return;
        }
        var del = e.target.closest && e.target.closest('.js-sa-del');
        if (del) { e.preventDefault(); deleteAppt(parseInt(del.getAttribute('data-id'), 10)); }
    });

    function fillForm(id, ev){
        var p = ev.extendedProps || {};
        openModal(id, {});
        document.getElementById('sa-date').value = p.appt_date_jalali || '';
        try { jQuery('#sa-date').persianDatepicker('setDate', p.appt_date_jalali || ''); } catch(e){}
        document.getElementById('sa-start').value = p.start_time || '';
        document.getElementById('sa-end').value = p.end_time || '';
        document.getElementById('sa-doctor').value = p.doctor_id || '';
        document.getElementById('sa-case').value = p.case_id || '';
        document.getElementById('sa-patient').value = p.patient_name || p.case_patient || '';
        document.getElementById('sa-type').value = p.appt_type || 'scan';
        document.getElementById('sa-status').value = p.status || 'scheduled';
        document.getElementById('sa-phone').value = p.phone || '';
        document.getElementById('sa-address').value = p.address || '';
        document.getElementById('sa-title').value = ev.title || '';
        document.getElementById('sa-notes').value = p.notes || '';
        document.getElementById('sa-body').checked = !!p.needs_scan_body;
        if (p.case_id) loadCaseInfo();
    }

    // دیتای نوبت را از سرور می‌گیریم و فرم را پر می‌کنیم (هرگز فرمِ خالی باز نکنیم)
    function openForEdit(id){
        if (!id) return;
        var cached = null;
        (window.__SA_EVENTS__ || []).forEach(function(ev){ if (String(ev.id) === String(id)) cached = ev; });
        if (cached) { fillForm(id, cached); return; }

        fetch('scan_appointments_data.php?id=' + encodeURIComponent(id), { headers:{ 'Accept':'application/json' } })
            .then(function(r){ return r.json(); })
            .then(function(j){
                var ev = (j && j.events && j.events.length) ? j.events[0] : null;
                if (!ev) { alert('این نوبت پیدا نشد (شاید حذف شده است).'); return; }
                fillForm(id, ev);
            })
            .catch(function(){ alert('خطا در دریافت اطلاعات نوبت.'); });
    }

    // ─── تقویم ───
    var calendar = null;
    function filters(){
        return {
            doctor_id: (document.getElementById('sa-f-doctor') || {}).value || '',
            type:      (document.getElementById('sa-f-type') || {}).value || '',
            status:    (document.getElementById('sa-f-status') || {}).value || '',
            q:         (document.getElementById('sa-f-q') || {}).value || ''
        };
    }
    function eventHtml(arg){
        var p = arg.event.extendedProps || {};
        var time = arg.timeText ? arg.timeText : (p.start_time || '');
        var who  = p.doctor_name ? p.doctor_name : '';
        var who2 = p.patient_name || p.case_patient || '';
        var line2 = [];
        if (who2) line2.push(esc(who2));
        if (p.service) line2.push(esc(p.service));
        var html = '<div class="sa-ev">'
            + '<div class="t">' + faDigits(esc(time)) + (who ? ' — ' + esc(who) : '') + '</div>';
        if (line2.length) html += '<div>' + line2.join(' · ') + '</div>';
        if (p.needs_scan_body) html += '<div><span class="b">🧩 اسکن‌بادی</span></div>';
        if (p.status === 'done') html += '<div>✅ انجام شد</div>';
        if (p.status === 'canceled') html += '<div>✖ لغو شد</div>';
        html += '</div>';
        return { html: html };
    }
    function refreshCalendar(){
        if (!calendar) { location.reload(); return; }
        window.__SA_EVENTS__ = null;
        calendar.refetchEvents();
    }

    // اگر دریافت نوبت‌ها از سرور شکست خورد، به کاربر پیام واضح نشان بده (تقلبِ آنی)
    var feedErrShown = false;
    function showFeedError(){
        if (feedErrShown) return;
        feedErrShown = true;
        var box = document.getElementById('sa-feed-error');
        if (box) box.style.display = 'block';
    }

    function initCalendar(){
        var el = document.getElementById('sa-calendar');
        if (typeof FullCalendar === 'undefined') {
            el.style.display = 'none';
            document.getElementById('sa-fallback').style.display = 'block';
            return;
        }
        initDatePicker();
        var f = filters();
        calendar = new FullCalendar.Calendar(el, {
            locale: 'fa',
            direction: 'rtl',
            initialView: window.innerWidth < 800 ? 'listWeek' : 'timeGridWeek',
            firstDay: 6,                       // شنبه
            nowIndicator: true,
            height: 'auto',
            slotMinTime: '07:00:00',
            slotMaxTime: '22:00:00',
            allDaySlot: false,
            selectable: CAN_MANAGE,
            editable: CAN_MANAGE,
            eventResizableFromStart: CAN_MANAGE,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
            buttonText: { today: 'امروز', month: 'ماه', week: 'هفته', day: 'روز', list: 'لیست' },
            expandRows: true,
            events: function(info, successCallback, failureCallback){
                var qs = new URLSearchParams({ from: info.startStr.substring(0, 10), to: info.endStr.substring(0, 10) });
                Object.keys(f).forEach(function(k){ if (f[k]) qs.set(k, f[k]); });
                fetch('scan_appointments_data.php?' + qs.toString(), { headers:{ 'Accept':'application/json' } })
                    .then(function(r){ if (!r.ok) throw new Error('feed ' + r.status); return r.json(); })
                    .then(function(j){
                        window.__SA_EVENTS__ = (j && j.events) || [];
                        successCallback(window.__SA_EVENTS__);
                    })
                    .catch(function(){ failureCallback(); showFeedError(); });
            },
            eventContent: eventHtml,
            dayHeaderContent: function(arg){
                if (arg.view.type === 'dayGridMonth') return { text: jWeekday(arg.date) };
                return { html: '<div style="font-size:.78rem;">' + esc(jWeekday(arg.date)) + '</div>'
                    + '<div style="font-weight:700;">' + esc(jDate(arg.date)) + '</div>' };
            },
            dayCellContent: function(arg){
                var dd = arg.date;
                var p = pd(dd);
                var day = p ? faDigits(p.date()) : String(dd.getDate());
                return { html: '<span>' + day + '</span>' };
            },
            datesSet: function(arg){
                var t = document.querySelector('.fc-toolbar-title');
                if (!t) return;
                var s = arg.start, e = new Date(arg.end.getTime() - 86400000);
                if (arg.view.type === 'dayGridMonth') t.textContent = jTitle(s);
                else if (arg.view.type === 'listWeek') t.textContent = jDate(s) + ' تا ' + jDate(e);
                else if (arg.view.type === 'timeGridDay') t.textContent = jDate(s);
                else t.textContent = jDate(s) + ' تا ' + jDate(e);
            },
            dateClick: function(arg){
                if (!CAN_MANAGE) return;
                var time = arg.dateStr.length > 10 ? arg.dateStr.substring(11, 16) : '';
                var end  = '';
                if (time) {
                    var h = parseInt(time.substring(0,2), 10);
                    end = (h + 1 < 10 ? '0' : '') + (h + 1) + ':00';
                    if (h + 1 > 23) end = '23:59';
                }
                openModal(null, { date: arg.dateStr.substring(0,10), start: time, end: end, doctor_id: DOCTOR_ID || '' });
            },
            eventClick: function(arg){
                arg.jsEvent.preventDefault();
                if (!CAN_MANAGE) { alert('فقط مشاهده — ' + (arg.event.extendedProps.doctor_name || '')); return; }
                openForEdit(parseInt(arg.event.id, 10));
            },
            eventDrop: function(arg){
                if (!CAN_MANAGE) { arg.revert(); return; }
                var p = arg.event.extendedProps || {};
                var s = arg.event.start;
                post('save_scan_appointment.php', {
                    id: arg.event.id,
                    appt_date: s.toISOString().substring(0,10),
                    start_time: ('0' + s.getHours()).slice(-2) + ':' + ('0' + s.getMinutes()).slice(-2),
                    end_time: arg.event.end ? (('0' + arg.event.end.getHours()).slice(-2) + ':' + ('0' + arg.event.end.getMinutes()).slice(-2)) : '',
                    doctor_id: p.doctor_id || '', case_id: p.case_id || '', patient_name: p.patient_name || '',
                    appt_type: p.appt_type || 'scan', status: p.status || 'scheduled',
                    phone: p.phone || '', address: p.address || '', notes: p.notes || '',
                    needs_scan_body: p.needs_scan_body ? 1 : 0
                }).then(function(r){
                    if (!r || !r.success) { arg.revert(); alert((r && r.message) || 'جابه‌جایی ذخیره نشد.'); }
                    else { refreshCalendar(); }
                }).catch(function(){ arg.revert(); alert('خطا در ارتباط با سرور.'); });
            },
            eventResize: function(arg){
                if (!CAN_MANAGE) { arg.revert(); return; }
                var p = arg.event.extendedProps || {};
                var s = arg.event.start, e2 = arg.event.end;
                post('save_scan_appointment.php', {
                    id: arg.event.id,
                    appt_date: s.toISOString().substring(0,10),
                    start_time: ('0' + s.getHours()).slice(-2) + ':' + ('0' + s.getMinutes()).slice(-2),
                    end_time: e2 ? (('0' + e2.getHours()).slice(-2) + ':' + ('0' + e2.getMinutes()).slice(-2)) : '',
                    doctor_id: p.doctor_id || '', case_id: p.case_id || '', patient_name: p.patient_name || '',
                    appt_type: p.appt_type || 'scan', status: p.status || 'scheduled',
                    phone: p.phone || '', address: p.address || '', notes: p.notes || '',
                    needs_scan_body: p.needs_scan_body ? 1 : 0
                }).then(function(r){
                    if (!r || !r.success) { arg.revert(); alert((r && r.message) || 'تغییر مدت ذخیره نشد.'); }
                    else { refreshCalendar(); }
                }).catch(function(){ arg.revert(); alert('خطا در ارتباط با سرور.'); });
            }
        });
        calendar.render();

        var applyBtn = document.getElementById('sa-f-apply');
        if (applyBtn) applyBtn.addEventListener('click', refreshCalendar);
        var clearBtn = document.getElementById('sa-f-clear');
        if (clearBtn) clearBtn.addEventListener('click', function(){
            ['sa-f-doctor','sa-f-type','sa-f-status','sa-f-q'].forEach(function(id){
                var el2 = document.getElementById(id); if (el2) el2.value = '';
            });
            refreshCalendar();
        });
        var qEl = document.getElementById('sa-f-q');
        if (qEl) qEl.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); refreshCalendar(); } });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initCalendar);
    else initCalendar();

    // اگر از صفحهٔ کیس با ?new=1&case_id=... آمده باشیم، فرم نوبت با اطلاعات همان کیس باز شود
    (function autoOpenFromCase(){
        if (!CAN_MANAGE || typeof URLSearchParams === 'undefined') return;
        var urlp = new URLSearchParams(window.location.search);
        if (urlp.get('new') !== '1') return;
        var cid = parseInt(urlp.get('case_id') || '0', 10);
        setTimeout(function(){
            openModal(null, { date: new Date(), case_id: cid, type: urlp.get('type') || 'scan' });
        }, 400);
    })();
})();
</script>
<?php panel_layout_end(); ?>
