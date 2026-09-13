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
                <th>کیس‌های متصل</th>
                <th>توضیحات</th>
                <th>حجم</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($uploads as $u):
                $myLinkCases = $upLinkLabels((int) $u['id']);
                $myCanManage = is_admin() || (int) $u['user_id'] === (int) $user['id'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($u['original_name']) ?></td>
                    <td>
                        <?php foreach ($myLinkCases as $cid => $clabel): ?>
                            <span style="display:inline-flex; align-items:center; gap:4px; background:#e0f2fe; color:#0369a1; border-radius:6px; padding:2px 6px; margin:1px; font-size:0.85rem;">
                                <a href="view_case.php?id=<?= $cid ?>" style="text-decoration:none; color:#0369a1;"><?= $clabel ?></a>
                                <?php if ($myCanManage): ?><a href="#" class="js-unlink-upl" data-upload="<?= (int) $u['id'] ?>" data-case="<?= $cid ?>" style="text-decoration:none; color:#b91c1c; font-weight:bold;" title="حذف اتصال از این کیس">✕</a><?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (!$myLinkCases): ?>—<?php endif; ?>
                        <?php if ($myCanManage && !empty($upAttachList)):
                            $myAvail = array_values(array_filter($upAttachList, fn($c) => !isset($myLinkCases[(int) $c['id']]))); ?>
                            <?php if ($myAvail): ?>
                                <div style="margin-top:5px; display:flex; gap:4px; align-items:center;">
                                    <select class="js-upl-case-sel" data-upload="<?= (int) $u['id'] ?>" style="padding:4px 6px; font-size:0.85rem; max-width:210px;">
                                        <option value="">اتصال به کیس…</option>
                                        <?php foreach ($myAvail as $c): ?>
                                            <option value="<?= (int) $c['id'] ?>">#<?= (int) $c['id'] ?> - <?= htmlspecialchars($c['patient_name'] ?: '') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn js-upl-link-btn" data-upload="<?= (int) $u['id'] ?>" style="padding:3px 8px; font-size:0.85rem; background:#0F172A; color:#fff;">اتصال</button>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:pre-wrap; max-width:260px;"><?= htmlspecialchars($u['description'] ?? '') ?: '—' ?></td>
                    <td><?= $u['size'] ? toPersianDigits(round((int)$u['size'] / 1024)) . ' KB' : '—' ?></td>
                    <td><?= toJalaliDateTimeFormatted($u['created_at']) ?></td>
                    <td class="actions" style="white-space:nowrap;">
                        <a class="btn" href="serve_user_upload.php?id=<?= (int) $u['id'] ?>" target="_blank" style="background:#e0f2fe; color:#0369a1; padding:4px 8px; text-decoration:none;" title="باز کردن / پیش‌نمایش">باز کردن</a>
                        <a class="btn" href="download_user_upload.php?id=<?= (int) $u['id'] ?>" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">دانلود</a>
                        <?php if ((int) $u['user_id'] === (int) $user['id']): ?>
                        <form method="post" action="delete_user_upload.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px;">حذف</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($canViewAllUploads && !empty($allUploads)): ?>
<h3 style="margin-top:28px;">همه فایل‌ها (کتابخانهٔ مشترک)</h3>
<div class="form-card" style="margin-top:12px;">
    <table class="display datatable" style="width:100%">
        <thead>
        <tr>
            <th>فایل</th>
            <th>آپلودکننده</th>
            <th>کیس‌های متصل</th>
            <th>توضیحات</th>
            <th>حجم</th>
            <th>تاریخ</th>
            <th>عملیات</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($allUploads as $u):
            $allLinkCases = $upLinkLabels((int) $u['id']);
            $allCanManage = is_admin();
            ?>
            <tr>
                <td><?= htmlspecialchars($u['original_name']) ?></td>
                <td><?= htmlspecialchars($u['uploader_name'] ?: '—') ?></td>
                <td>
                    <?php foreach ($allLinkCases as $cid => $clabel): ?>
                        <span style="display:inline-flex; align-items:center; gap:4px; background:#e0f2fe; color:#0369a1; border-radius:6px; padding:2px 6px; margin:1px; font-size:0.85rem;">
                            <a href="view_case.php?id=<?= $cid ?>" style="text-decoration:none; color:#0369a1;"><?= $clabel ?></a>
                            <?php if ($allCanManage): ?><a href="#" class="js-unlink-upl" data-upload="<?= (int) $u['id'] ?>" data-case="<?= $cid ?>" style="text-decoration:none; color:#b91c1c; font-weight:bold;" title="حذف اتصال از این کیس">✕</a><?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if (!$allLinkCases): ?>—<?php endif; ?>
                    <?php if ($allCanManage && !empty($upAttachList)):
                        $allAvail = array_values(array_filter($upAttachList, fn($c) => !isset($allLinkCases[(int) $c['id']]))); ?>
                        <?php if ($allAvail): ?>
                            <div style="margin-top:5px; display:flex; gap:4px; align-items:center;">
                                <select class="js-upl-case-sel" data-upload="<?= (int) $u['id'] ?>" style="padding:4px 6px; font-size:0.85rem; max-width:190px;">
                                    <option value="">اتصال به کیس…</option>
                                    <?php foreach ($allAvail as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>">#<?= (int) $c['id'] ?> - <?= htmlspecialchars($c['patient_name'] ?: '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn js-upl-link-btn" data-upload="<?= (int) $u['id'] ?>" style="padding:3px 8px; font-size:0.85rem; background:#0F172A; color:#fff;">اتصال</button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td style="white-space:pre-wrap; max-width:220px;"><?= htmlspecialchars($u['description'] ?? '') ?: '—' ?></td>
                <td><?= $u['size'] ? toPersianDigits(round((int)$u['size'] / 1024)) . ' KB' : '—' ?></td>
                <td><?= toJalaliDateTimeFormatted($u['created_at']) ?></td>
                <td class="actions" style="white-space:nowrap;">
                    <a class="btn" href="serve_user_upload.php?id=<?= (int) $u['id'] ?>" target="_blank" style="background:#e0f2fe; color:#0369a1; padding:4px 8px; text-decoration:none;" title="باز کردن / پیش‌نمایش">باز کردن</a>
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
