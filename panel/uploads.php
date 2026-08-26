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

// Designers and admins can also access the shared files uploaded by others
$canViewAllUploads = has_role('admin') || $user['role'] === 'designer';
$allUploads = [];
if ($canViewAllUploads) {
    $allUploads = db()->query('SELECT u.*, c.patient_name, c.id AS case_id, uu.full_name AS uploader_name FROM user_uploads u LEFT JOIN cases c ON u.case_id = c.id LEFT JOIN users uu ON u.user_id = uu.id ORDER BY u.created_at DESC')->fetchAll();
}

panel_layout_start('آپلود فایل');
?>
<div class="form-card" style="max-width:760px; margin:0 auto 24px;">
    <h3>آپلود فایل جدید</h3>
    <p style="color:#555; margin:6px 0 12px;">معمولاً فایل ZIP یا RAR یا عکس. می‌توانید فایل را به یکی از کیس‌های خودتان وصل کنید یا بدون کیس آپلود کنید.</p>
    <form id="upload-form" enctype="multipart/form-data" style="margin-top:12px;">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-group">
            <label for="upload-file">فایل</label>
            <input type="file" id="upload-file" name="file" accept=".zip,.rar,.jpg,.jpeg,.png,.gif,.webp,.bmp,.stl,.ply,.stp,.step,.obj,.3mf" required>
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
    <?php if (empty($uploads)): ?>
        <p class="empty">هنوز فایلی آپلود نکرده‌اید.</p>
    <?php else: ?>
        <table class="display datatable" style="width:100%">
            <thead>
            <tr>
                <th>فایل</th>
                <th>کیس</th>
                <th>توضیحات</th>
                <th>حجم</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($uploads as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['original_name']) ?></td>
                    <td><?= !empty($u['case_id']) ? ('#' . $u['case_id'] . ' - ' . htmlspecialchars($u['patient_name'] ?: '')) : '—' ?></td>
                    <td style="white-space:pre-wrap; max-width:260px;"><?= htmlspecialchars($u['description'] ?? '') ?: '—' ?></td>
                    <td><?= $u['size'] ? toPersianDigits(round((int)$u['size'] / 1024)) . ' KB' : '—' ?></td>
                    <td><?= toJalaliDateFormatted($u['created_at']) ?></td>
                    <td class="actions">
                        <a class="btn" href="download_user_upload.php?id=<?= (int) $u['id'] ?>" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">دانلود</a>
                        <form method="post" action="delete_user_upload.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px;">حذف</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($canViewAllUploads && !empty($allUploads)): ?>
<h3 style="margin-top:28px;">همه فایل‌ها (مشترک)</h3>
<div class="form-card" style="margin-top:12px;">
    <table class="display datatable" style="width:100%">
        <thead>
        <tr>
            <th>فایل</th>
            <th>آپلودکننده</th>
            <th>کیس</th>
            <th>توضیحات</th>
            <th>حجم</th>
            <th>تاریخ</th>
            <th>عملیات</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($allUploads as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['original_name']) ?></td>
                <td><?= htmlspecialchars($u['uploader_name'] ?: '—') ?></td>
                <td><?= !empty($u['case_id']) ? ('#' . $u['case_id'] . ' - ' . htmlspecialchars($u['patient_name'] ?: '')) : '—' ?></td>
                <td style="white-space:pre-wrap; max-width:260px;"><?= htmlspecialchars($u['description'] ?? '') ?: '—' ?></td>
                <td><?= $u['size'] ? toPersianDigits(round((int)$u['size'] / 1024)) . ' KB' : '—' ?></td>
                <td><?= toJalaliDateFormatted($u['created_at']) ?></td>
                <td class="actions">
                    <a class="btn" href="download_user_upload.php?id=<?= (int) $u['id'] ?>" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">دانلود</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<script>
(function(){
    var form = document.getElementById('upload-form');
    if (!form) return;
    var csrf = '<?= htmlspecialchars($csrf_token) ?>';
    form.addEventListener('submit', function(e){
        e.preventDefault();
        var msgEl = document.getElementById('upload-msg');
        var input = document.getElementById('upload-file');
        var caseSel = document.getElementById('upload-case');
        var progressWrap = document.getElementById('upload-progress');
        var bar = document.getElementById('upload-bar');
        var pct = document.getElementById('upload-percent');
        if (!input || !input.files.length) { if (msgEl) { msgEl.textContent = 'فایلی انتخاب نشده است.'; msgEl.style.color = '#b91c1c'; } return; }
        var fd = new FormData();
        fd.append('_csrf_token', csrf);
        fd.append('case_id', caseSel ? caseSel.value : '');
        fd.append('file', input.files[0]);
        var descInput = document.getElementById('upload-description');
        if (descInput && descInput.value.trim()) fd.append('description', descInput.value.trim());
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
            try { resp = JSON.parse(xhr.responseText); } catch(e){}
            if (xhr.status === 200 && resp && resp.success) {
                if (bar) bar.style.width = '100%';
                if (pct) pct.textContent = '100%';
                if (msgEl) { msgEl.textContent = 'فایل با موفقیت آپلود شد.'; msgEl.style.color = '#166534'; }
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
