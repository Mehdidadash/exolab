<?php
// panel/uploads.php
// Personal file upload page – doctors, designers, and other users can upload
// ZIP/RAR/images. The file may optionally be attached to one of their cases.
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();

// Cases the current user may attach an upload to (only their own cases)
$cases = [];
if ($user['role'] === 'doctor') {
    $stmt = db()->prepare('SELECT c.id, c.patient_name, u.full_name AS doctor_name FROM cases c LEFT JOIN users u ON c.doctor_id = u.id WHERE c.doctor_id = ? ORDER BY c.id DESC');
    $stmt->execute([$user['id']]);
    $cases = $stmt->fetchAll();
} elseif ($user['role'] === 'clinic') {
    $clinicScope = getClinicScope('c');
    $stmt = db()->prepare('SELECT c.id, c.patient_name, u.full_name AS doctor_name FROM cases c LEFT JOIN users u ON c.doctor_id = u.id WHERE ' . $clinicScope['sql'] . ' ORDER BY c.id DESC');
    $stmt->execute($clinicScope['params']);
    $cases = $stmt->fetchAll();
} elseif ($user['role'] === 'lab' || $user['role'] === 'outsource_lab' || $user['role'] === 'customer_lab' || $user['role'] === 'partner_lab') {
    $stmt = db()->prepare('SELECT c.id, c.patient_name, u.full_name AS doctor_name FROM cases c LEFT JOIN users u ON c.doctor_id = u.id WHERE c.lab_id = ? ORDER BY c.id DESC');
    $stmt->execute([$user['id']]);
    $cases = $stmt->fetchAll();
} elseif (has_permission('view_all_cases')) {
    $cases = db()->query('SELECT c.id, c.patient_name, u.full_name AS doctor_name FROM cases c LEFT JOIN users u ON c.doctor_id = u.id ORDER BY c.id DESC')->fetchAll();
}

// CSRF
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

// This user's uploads
$uploadsStmt = db()->prepare('SELECT u.*, c.patient_name, c.id AS case_id FROM user_uploads u LEFT JOIN cases c ON u.case_id = c.id WHERE u.user_id = ? ORDER BY u.created_at DESC');
$uploadsStmt->execute([$user['id']]);
$uploads = $uploadsStmt->fetchAll();

// Designers and admins (اصلی و مدیران شعب) can also access the shared files uploaded by others
$canViewAllUploads = is_admin() || $user['role'] === 'designer';
$allUploads = [];
if ($canViewAllUploads) {
    $allUploads = db()->query('SELECT u.*, uu.full_name AS uploader_name FROM user_uploads u LEFT JOIN users uu ON uu.id = u.user_id ORDER BY u.created_at DESC')->fetchAll();
}

// Cases that an admin may attach a library file to (branch scoping: مدیر شعبه → شعبهٔ خودش،
// مدیر کل = شعبهٔ مرکزی — همراستا با بقیهٔ صفحات).
$attachCaseOptions = [];
if (is_admin()) {
    $sb = currentBranchId();
    if ($sb === null) $sb = 1;
    $sc = branchCaseScope('c', $sb);
    $st = db()->prepare('SELECT c.id, c.patient_name, u.full_name AS doctor_name FROM cases c LEFT JOIN users u ON c.doctor_id = u.id WHERE ' . $sc['sql'] . ' ORDER BY c.id DESC');
    $st->execute($sc['params']);
    $attachCaseOptions = $st->fetchAll();
}

// Render helpers for the tables below.
// Which cases the CURRENT user may attach library files to (admins → شعبهٔ خود، بقیه → کیس‌های خودشان)
$upAttachList = is_admin() ? $attachCaseOptions : $cases;
$upLinkLabels = function (int $uploadId): array {
    $ids = userUploadLinkedCaseIds($uploadId);
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare('SELECT id, patient_name FROM cases WHERE id IN (' . $ph . ') ORDER BY id DESC');
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['id']] = '#' . $r['id'] . ' ' . htmlspecialchars($r['patient_name'] ?: '');
    }
    return $out;
};

panel_layout_start('آپلود فایل');
?>
<?php
// ─── پیام‌های نتیجهٔ عملیات (تغییر نام پوشه و ...) ───
$upMsg = trim((string) ($_GET['msg'] ?? ''));
$upErr = trim((string) ($_GET['error'] ?? ''));
$upErrText = [
    'invalid_folder' => 'پوشهٔ انتخابی معتبر نیست.',
    'bad_name'       => 'نام وارد‌شده برای پوشه مجاز نیست (بدون / و \\ و کاراکتر کنترلی).',
    'duplicate'      => 'پوشه‌ای با این نام از قبل وجود دارد.',
    'notfound'       => 'پوشه‌ای با این نام یافت نشد.',
][$upErr] ?? '';
?>
<?php if ($upMsg === 'renamed'): ?>
    <p style="color:#166534; font-weight:bold;">
        ✅ نام پوشه تغییر کرد<?= isset($_GET['n']) ? ' (' . toPersianDigits((string) (int) $_GET['n']) . ' فایل به‌روزرسانی شد)' : '' ?>.
    </p>
<?php endif; ?>
<?php if ($upErrText !== ''): ?>
    <p style="color:#b91c1c; font-weight:bold;">⚠️ <?= htmlspecialchars($upErrText) ?></p>
<?php endif; ?>
<div class="form-card" style="max-width:760px; margin:0 auto 24px;">
    <h3>آپلود فایل جدید</h3>
    <p style="color:#555; margin:6px 0 12px;">می‌توانید چند فایل را همزمان انتخاب کنید (ZIP/RAR/عکس/مدل سه‌بعدی). اگر چند فایل انتخاب کنید، می‌توانید همه را در یک فایل ZIP بسته‌بندی کنید. اتصال به کیس اختیاری است.</p>
    <form id="upload-form" enctype="multipart/form-data" style="margin-top:12px;">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-group">
            <label for="upload-file">فایل‌ها (می‌توانید چند تا انتخاب کنید)</label>
            <input type="file" id="upload-file" name="files[]" accept=".zip,.rar,.pdf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.stl,.ply,.stp,.step,.obj,.3mf,.matrix4,.dentalProject,.iftScan,.constructionInfo,.dcm,.dicom,.txt,.xml,.html,.htm" multiple required>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                <button type="button" id="upload-pick-files" class="btn" style="background:#e0f2fe; color:#0369a1; padding:5px 12px;">🗂 انتخاب فایل</button>
                <button type="button" id="upload-pick-folder" class="btn" style="background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; padding:5px 12px;">📁 انتخاب پوشه</button>
            </div>
            <input type="file" id="upload-folder" multiple hidden>
            <small id="upload-file-hint" style="display:block; margin-top:6px; color:#525252;"></small>
            <small style="display:block; margin-top:4px; color:#64748b; line-height:1.8;">
                می‌توانید یک <b>پوشه</b> را با همهٔ محتویات و زیرپوشه‌هایش انتخاب کنید (یا پوشه را با ماوس داخل صفحه رها کنید).
                ساختار پوشه‌ها در فهرست فایل‌ها حفظ می‌شود.
                اگر فایل‌ها را به یک کیس متصل کنید و داخل پوشه باشند، نام هر فایل خودکار با
                <b>شماره کیس_سایه_نام بیمار_شماره قبض_</b> شروع می‌شود.
            </small>
            <div id="upload-folder-list" style="display:none; margin-top:8px; max-height:170px; overflow:auto; border:1px solid #e2e8f0; border-radius:8px; padding:6px;"></div>
        </div>
        <div class="form-group">
            <?php // نوع فایل — همان گزینه‌های صفحهٔ کیس/مشاهدهٔ کیس تا نام‌گذاری خودکار یکسان کار کند ?>
            <label for="upload-file-type">نوع فایل</label>
            <select id="upload-file-type" name="file_type">
                <?php foreach (caseFileTypeConfig()['options'] as $ftKey => $ftLabel): ?>
                    <option value="<?= $ftKey ?>" <?= $ftKey === caseFileTypeDefault($user) ? 'selected' : '' ?>><?= $ftLabel ?></option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; margin-top:4px; color:#64748b;">
                اگر «طراحی نهایی» باشد و کیس را انتخاب کنید، نام هر فایل خودکار با
                <b>شماره کیس_سایه_نام بیمار_شماره قبض_</b> شروع می‌شود.
            </small>
        </div>
        <div class="form-group" id="upload-zip-group" style="display:none;">
            <label style="display:flex; align-items:center; gap:8px; font-weight:400; cursor:pointer;">
                <input type="checkbox" id="upload-zip" checked>
                بسته‌بندی همهٔ فایل‌های انتخاب‌شده در یک فایل ZIP
            </label>
            <small style="display:block; margin-top:6px; color:#525252;">مثل صفحهٔ کیس‌ها/مشاهدهٔ کیس: همهٔ فایل‌ها در یک ZIP با نام خوانا ذخیره می‌شوند.</small>
        </div>
        <div class="form-group">
            <label for="upload-case">اتصال به کیس (اختیاری)</label>
            <select id="upload-case" name="case_id">
                <option value="">بدون اتصال به کیس</option>
                <?php foreach ($cases as $c): ?>
                    <option value="<?= $c['id'] ?>">#<?= $c['id'] ?> - <?= htmlspecialchars($c['patient_name']) ?> (<?= htmlspecialchars($c['doctor_name'] ?: '—') ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="upload-description">توضیحات (اختیاری)</label>
            <textarea id="upload-description" name="description" rows="3" placeholder="مثلاً: اسکن فک بالا - پرسلن، لطفاً بررسی شود"></textarea>
        </div>
        <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">آپلود</button>
    </form>
    <div id="upload-msg" style="margin-top:10px; font-weight:bold;"></div>
    <div id="upload-progress" style="display:none; margin-top:8px;">
        <div style="background:#e5e7eb; border-radius:6px; overflow:hidden; height:16px;">
            <div id="upload-bar" style="width:0%; height:100%; background:#06B6D4; transition:width .2s;"></div>
        </div>
        <div id="upload-percent" style="font-size:0.8rem; color:#555; margin-top:4px;"></div>
    </div>
</div>

<h3>فایل‌های من</h3>
<div class="form-card" style="margin-top:12px;">
    <?php
    // ─── «نمای Details» فایل‌های من (شبیه Windows Explorer) ───
    // هر ردیف: نام | حجم | نوع | تاریخ | عملیات. پوشه‌ها گروه می‌شوند و با یک کلیک باز/بسته می‌شوند.
    // دکمه‌ها فقط اگر کاربر مجاز باشد ساخته می‌شوند؛ چون ستونِ عملیات هم‌تراز است،
    // نبودِ دکمه‌ها چیدمان بقیهٔ ردیف‌ها را به‌هم نمی‌زند.
    $myFileRows = [];
    foreach ($uploads as $u) {
        $canDelete = ((int) $u['user_id'] === (int) $user['id']);
        $acts = '<a class="btn" href="serve_user_upload.php?id=' . (int) $u['id'] . '" target="_blank"'
              . ' style="background:#e0f2fe; color:#0369a1; text-decoration:none;" title="باز کردن / پیش‌نمایش">باز کردن</a>'
              . '<a class="btn" href="download_user_upload.php?id=' . (int) $u['id'] . '"'
              . ' style="background:#E5E7EB; color:#0F172A; text-decoration:none;">دانلود</a>';
        if ($canDelete) {
            $acts .= '<form method="post" action="delete_user_upload.php">' . csrf_field()
                   . '<input type="hidden" name="id" value="' . (int) $u['id'] . '">'
                   . '<button class="btn" style="background:#fee2e2; color:#991b1b;">حذف</button></form>';
        }
        $myFileRows[] = [
            'id'       => (int) $u['id'],
            'name'     => (string) $u['original_name'],
            'rel_path' => $u['rel_path'] ?? null,
            'size'     => $u['size'] ?? null,
            'date'     => $u['created_at'] ?? null,
            'ext'      => strtolower((string) pathinfo((string) $u['original_name'], PATHINFO_EXTENSION)),
            'actions'  => $acts,
        ];
    }
    require_once __DIR__ . '/../includes/file_details_view.php';
    renderFileDetailsView($myFileRows, [
        'empty'         => 'هنوز فایلی آپلود نکرده‌اید.',
        'folder_rename' => ['action' => 'rename_upload_folder.php', 'all' => 0],
        'folder_download_action' => 'download_upload_folder.php?folder=__FOLDER__',
    ]);
    ?>
</div>
<?php if ($canViewAllUploads && !empty($allUploads)): ?>
<h3 style="margin-top:28px;">همه فایل‌ها (کتابخانهٔ مشترک)</h3>
<div class="form-card" style="margin-top:12px;">
    <?php
    // ─── «نمای Details» کتابخانهٔ مشترک ───
    // مثل «فایل‌های من»: نام | حجم | نوع | تاریخ | عملیات، با پوشه‌های تاشو.
    // مدیرها روی همهٔ فایل‌ها دکمه دارند؛ دیگران فقط روی فایل‌های خودشان.
    $allFileRows = [];
    foreach ($allUploads as $u) {
        $isMine   = ((int) $u['user_id'] === (int) $user['id']);
        $canTouch = is_admin() || $isMine;
        $acts = '<a class="btn" href="serve_user_upload.php?id=' . (int) $u['id'] . '" target="_blank"'
              . ' style="background:#e0f2fe; color:#0369a1; text-decoration:none;" title="باز کردن / پیش‌نمایش">باز کردن</a>'
              . '<a class="btn" href="download_user_upload.php?id=' . (int) $u['id'] . '"'
              . ' style="background:#E5E7EB; color:#0F172A; text-decoration:none;">دانلود</a>';
        if ($canTouch) {
            $acts .= '<form method="post" action="delete_user_upload.php">' . csrf_field()
                   . '<input type="hidden" name="id" value="' . (int) $u['id'] . '">'
                   . '<button class="btn" style="background:#fee2e2; color:#991b1b;">حذف</button></form>';
        }
        $allFileRows[] = [
            'id'       => (int) $u['id'],
            'name'     => (string) $u['original_name'],
            'rel_path' => $u['rel_path'] ?? null,
            'size'     => $u['size'] ?? null,
            'date'     => $u['created_at'] ?? null,
            'ext'      => strtolower((string) pathinfo((string) $u['original_name'], PATHINFO_EXTENSION)),
            'meta'     => (string) ($u['uploader_name'] ?? ''),
            'actions'  => $acts,
        ];
    }
    if (!function_exists('renderFileDetailsView')) {
        require_once __DIR__ . '/../includes/file_details_view.php';
    }
    renderFileDetailsView($allFileRows, [
        'empty'         => 'فایلی در کتابخانه نیست.',
        'folder_rename' => ['action' => 'rename_upload_folder.php', 'all' => 1],
        'folder_download_action' => 'download_upload_folder.php?folder=__FOLDER__&all=1',
    ]);
    ?>
</div>
<?php endif; ?>
<script>
(function(){
    var csrf = '<?= htmlspecialchars($csrf_token) ?>';
    function uplPost(url, data, cb){
        var fd = new FormData();
        Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        fd.append('_csrf_token', csrf);
        fetch(url, { method:'POST', headers:{ 'X-CSRF-Token': csrf }, body: fd })
            .then(function(r){ return r.json().catch(function(){ return {success:false}; }); })
            .then(function(resp){ cb(resp); })
            .catch(function(){ cb({success:false}); });
    }
    document.addEventListener('click', function(e){
        // ─── تغییر نام پوشه (نمای Details) ───
        // نامِ جدید را می‌پرسیم و سپس در همان صفحه ارسال می‌کنیم (بدون مودالِ اضافه).
        var ren = e.target.closest && e.target.closest('.js-fl-rename');
        if (ren) {
            e.preventDefault();
            var folder = ren.getAttribute('data-folder') || '';
            var action = ren.getAttribute('data-action') || 'rename_upload_folder.php';
            var scopeAll = ren.getAttribute('data-scope-all') === '1';
            var name = window.prompt('نام جدید برای پوشهٔ «' + folder + '»:', folder);
            if (name === null) return;                       // انصراف
            name = String(name).trim();
            if (name === '' || name === folder) return;      // بی‌تغییر
            var f = document.createElement('form');
            f.method = 'POST';
            f.action = action;
            var fields = { folder: folder, new_name: name, _csrf_token: csrf };
            if (scopeAll) fields.all = '1';
            Object.keys(fields).forEach(function(k){
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = k; inp.value = fields[k];
                f.appendChild(inp);
            });
            document.body.appendChild(f);
            f.submit();
            return;
        }
        var unl = e.target.closest && e.target.closest('.js-unlink-upl');
        if (unl) {
            e.preventDefault();
            var uploadId = unl.getAttribute('data-upload'), caseId = unl.getAttribute('data-case');
            if (!confirm('اتصال این فایل از کیس حذف شود؟ (خود فایل در کتابخانه می‌ماند)')) return;
            uplPost('unlink_user_upload_from_case.php', { upload_id: uploadId, case_id: caseId }, function(){ location.reload(); });
            return;
        }
        var btn = e.target.closest && e.target.closest('.js-upl-link-btn');
        if (btn) {
            e.preventDefault();
            var uid = btn.getAttribute('data-upload');
            var sel = document.querySelector('.js-upl-case-sel[data-upload="'+uid+'"]');
            if (!sel || !sel.value) return;
            uplPost('link_user_upload_to_case.php', { upload_id: uid, case_id: sel.value }, function(){ location.reload(); });
        }
    });
})();
</script>
<script>
(function(){
    var form = document.getElementById('upload-form');
    if (!form) return;
    var csrf = '<?= htmlspecialchars($csrf_token) ?>';
    var fileInput = document.getElementById('upload-file');
    var folderInput = document.getElementById('upload-folder');
    var hintEl = document.getElementById('upload-file-hint');
    var listEl = document.getElementById('upload-folder-list');
    var zipGroup = document.getElementById('upload-zip-group');
    var FOLDER_MAX = <?= (int) uploadFolderMaxFiles() ?>;
    // فایل‌های انتخاب‌شده (شامل مسیر نسبی) — منبع حقیقت هنگام ارسال
    var picked = [];
    // انتخاب پوشه با webkitdirectory
    if (folderInput && 'webkitdirectory' in folderInput) { folderInput.webkitdirectory = true; folderInput.setAttribute('webkitdirectory', ''); }

    function fa(n){ return String(n).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }
    function fmtSize(b){
        if (!(b > 0)) return '۰';
        var u = ['B','KB','MB','GB'];
        var i = Math.min(u.length-1, Math.floor(Math.log(b)/Math.log(1024)));
        var n = b / Math.pow(1024,i);
        return (i===0 || n>=10 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
    }
    function cleanRel(p){
        p = String(p||'').replace(/\\/g,'/');
        var out = [];
        p.split('/').forEach(function(seg){
            seg = seg.replace(/[\u0000-\u001F\u007F]/g,'').replace(/^[.\s]+|[.\s]+$/g,'');
            if (seg && seg !== '.' && seg !== '..') out.push(seg);
        });
        return out.join('/');
    }
    var ALLOWED = <?= json_encode(['zip','rar','pdf','jpg','jpeg','png','gif','webp','bmp','stl','ply','stp','step','obj','3mf','matrix4','dentalproject','iftscan','constructioninfo','dcm','dicom','txt','xml','html','htm']) ?>;
    function validExt(name){ return ALLOWED.indexOf((name.split('.').pop()||'').toLowerCase()) !== -1; }

    function addFiles(fileList){
        var bad = [], added = 0, folders = {};
        for (var i = 0; i < fileList.length; i++) {
            var f = fileList[i];
            if (!validExt(f.name)) { bad.push(f.name); continue; }
            var rel = cleanRel(f.webkitRelativePath || f._rel || '');
            var dup = picked.some(function(x){ return x.file.name === f.name && x.file.size === f.size && x.rel === rel; });
            if (dup) continue;
            picked.push({ file: f, rel: rel });
            if (rel) folders[rel.split('/')[0]] = 1;
            added++;
        }
        if (bad.length) alert('این فرمت‌ها مجاز نیستند:\n' + bad.join('\n') + '\n\nفرمت‌های مجاز: ' + ALLOWED.join(', '));
        if (picked.length > FOLDER_MAX) alert('⚠️ تعداد فایل‌ها (' + picked.length + ') از حد مجاز (' + FOLDER_MAX + ') بیشتر است؛ پوشه را به بخش‌های کوچک‌تر تقسیم کنید.');
        render(folders);
        return added;
    }
    function render(folders){
        folders = folders || {};
        if (hintEl) {
            var nFolder = picked.filter(function(p){ return p.rel && p.rel.indexOf('/') !== -1; }).length;
            hintEl.textContent = picked.length
                ? (fa(picked.length) + ' فایل انتخاب شده است' + (nFolder ? ' (' + fa(nFolder) + ' فایل داخل پوشه)' : ''))
                : '';
        }
        if (listEl) {
            if (!picked.length) { listEl.style.display = 'none'; listEl.innerHTML = ''; }
            else {
                listEl.style.display = 'block';
                listEl.innerHTML = '';
                picked.forEach(function(p, idx){
                    var row = document.createElement('div');
                    row.style.cssText = 'display:flex; gap:8px; align-items:center; padding:3px 6px; border-bottom:1px solid #f1f5f9; font-size:.78rem;';
                    var nm = document.createElement('span');
                    nm.style.cssText = 'flex:1; direction:ltr; text-align:left; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;';
                    nm.textContent = p.rel || p.file.name;
                    var sz = document.createElement('span');
                    sz.style.cssText = 'color:#64748b; white-space:nowrap;';
                    sz.textContent = fmtSize(p.file.size);
                    var rm = document.createElement('button');
                    rm.type = 'button'; rm.textContent = '✕'; rm.title = 'حذف';
                    rm.style.cssText = 'border:none; background:none; color:#dc2626; cursor:pointer; font-size:.9rem; line-height:1;';
                    rm.addEventListener('click', function(){ picked.splice(idx,1); render(); });
                    row.appendChild(nm); row.appendChild(sz); row.appendChild(rm);
                    listEl.appendChild(row);
                });
            }
        }
        if (zipGroup) zipGroup.style.display = (picked.length > 1) ? 'block' : 'none';
    }

    var pickFilesBtn = document.getElementById('upload-pick-files');
    var pickFolderBtn = document.getElementById('upload-pick-folder');
    if (pickFilesBtn) pickFilesBtn.addEventListener('click', function(){ fileInput.click(); });
    if (pickFolderBtn) pickFolderBtn.addEventListener('click', function(){ folderInput.click(); });
    if (fileInput) fileInput.addEventListener('change', function(){ addFiles(fileInput.files); fileInput.value = ''; });
    if (folderInput) folderInput.addEventListener('change', function(){ addFiles(folderInput.files); folderInput.value = ''; });

    // پوشه را می‌توان با ماوس هم روی فرم رها کرد
    ['dragover','drop'].forEach(function(evt){
        form.addEventListener(evt, function(e){
            if (evt === 'dragover') { e.preventDefault(); return; }
            e.preventDefault();
            var dt = e.dataTransfer;
            if (!dt) return;
            var entries = [];
            if (dt.items) {
                Array.prototype.slice.call(dt.items).forEach(function(it){
                    if (it.kind !== 'file') return;
                    var en = (typeof it.webkitGetAsEntry === 'function') ? it.webkitGetAsEntry() : null;
                    if (en) entries.push(en);
                });
            }
            if (!entries.some(function(en){ return en && en.isDirectory; })) {
                if (dt.files && dt.files.length) addFiles(dt.files);
                return;
            }
            var collected = [];
            (function readEntry(entry, prefix, done){
                if (!entry) { done(); return; }
                if (entry.isFile) {
                    entry.file(function(f){
                        try { Object.defineProperty(f, '_rel', { value: cleanRel(prefix + entry.name), writable:true, configurable:true }); } catch(err) { f._rel = cleanRel(prefix + entry.name); }
                        collected.push(f); done();
                    }, function(){ done(); });
                } else if (entry.isDirectory) {
                    var reader = entry.createReader(), all = [];
                    (function batch(){
                        reader.readEntries(function(list){
                            if (!list.length) {
                                var i = 0;
                                (function next(){ if (i >= all.length) { done(); return; } readEntry(all[i++], prefix + entry.name + '/', next); })();
                                return;
                            }
                            for (var k = 0; k < list.length; k++) all.push(list[k]);
                            batch();
                        }, function(){ done(); });
                    })();
                } else { done(); }
            });
            var idx = 0;
            (function walk(){
                if (idx >= entries.length) { if (collected.length) addFiles(collected); return; }
                readEntry(entries[idx++], '', walk);
            })();
        });
    });

    form.addEventListener('submit', function(e){
        e.preventDefault();
        var msgEl = document.getElementById('upload-msg');
        var caseSel = document.getElementById('upload-case');
        var progressWrap = document.getElementById('upload-progress');
        var bar = document.getElementById('upload-bar');
        var pct = document.getElementById('upload-percent');
        if (!picked.length) { if (msgEl) { msgEl.textContent = 'فایلی انتخاب نشده است.'; msgEl.style.color = '#b91c1c'; } return; }

        var fd = new FormData();
        fd.append('_csrf_token', csrf);
        fd.append('case_id', caseSel ? caseSel.value : '');
        // نوع فایل — برای نام‌گذاری خودکارِ یکسان با صفحهٔ کیس‌ها/مشاهدهٔ کیس
        var typeEl = document.getElementById('upload-file-type');
        if (typeEl) fd.append('file_type', typeEl.value);
        var descInput = document.getElementById('upload-description');
        if (descInput && descInput.value.trim()) fd.append('description', descInput.value.trim());
        var zipEl = document.getElementById('upload-zip');
        var useZip = !!(zipEl && zipEl.checked && picked.length > 1);
        if (useZip) fd.append('compress', '1');
        // نام پوشهٔ ریشه (اولین بخش مسیر) برای نام‌گذاری ZIP و گروه‌بندی
        var rootName = '';
        for (var k = 0; k < picked.length; k++) {
            if (picked[k].rel && picked[k].rel.indexOf('/') !== -1) { rootName = picked[k].rel.split('/')[0]; break; }
        }
        if (rootName) fd.append('folder_name', rootName);
        picked.forEach(function(p){
            fd.append('files[]', p.file);
            fd.append('rel_paths[]', p.rel || '');
        });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload_user_file.php', true);
        xhr.setRequestHeader('X-CSRF-Token', csrf);
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
            try { resp = JSON.parse(xhr.responseText); } catch(err){}
            if (xhr.status === 200 && resp && resp.success) {
                if (bar) bar.style.width = '100%';
                if (pct) pct.textContent = '100%';
                var n = resp.uploaded || picked.length;
                if (msgEl) { msgEl.textContent = 'آپلود انجام شد (' + n + ' فایل' + (resp.zipped ? ' — یک فایل ZIP' : '') + ').'; msgEl.style.color = '#166534'; }
                setTimeout(function(){ location.reload(); }, 800);
            } else {
                if (progressWrap) progressWrap.style.display = 'none';
                if (msgEl) { msgEl.textContent = 'خطا در آپلود: ' + ((resp && resp.message) ? resp.message : 'نامشخص'); msgEl.style.color = '#b91c1c'; }
            }
        };
        xhr.onerror = function(){ if (progressWrap) progressWrap.style.display = 'none'; if (msgEl) { msgEl.textContent = 'خطا در اتصال به سرور.'; msgEl.style.color = '#b91c1c'; } };
        xhr.send(fd);
    });
})();
</script>
<?php panel_layout_end(); ?>
