<?php
// panel/case_statuses.php
// مدیریت وضعیت‌های کیس: افزودن/حذف، ترتیب نمایش، آیکون و رنگ.
require_once __DIR__ . '/auth.php';
require_login();

if (!is_admin()) { die('دسترسی غیرمجاز'); }

$statuses = getAllCaseStatuses();

// تعداد کیس‌های هر وضعیت
$usage = [];
foreach (db()->query('SELECT status_id, COUNT(*) n FROM cases GROUP BY status_id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $usage[(int) $r['status_id']] = (int) $r['n'];
}

// آیکون‌های پیشنهادی (کاربر می‌تواند هر ایموجی/متن دیگری هم وارد کند)
$iconPresets = ['⬜','📥','⏳','🎨','🖌','🧱','🖨','🔥','💎','🧪','🔬','⚙️','🛠','✅','📦','🚚','📤','🔁','🧩','🦷','🟢','🟡','🟠','🔵','🟣','⛔','❌','💡','🏷','📌'];

$msg = trim((string) ($_GET['msg'] ?? ''));
$err = trim((string) ($_GET['error'] ?? ''));

panel_layout_start('وضعیت‌های کیس');
?>
<div class="form-card" style="max-width:1000px; margin:0 auto 20px;">
    <h3 style="margin-top:0;">افزودن وضعیت جدید</h3>
    <p style="color:#555; margin:6px 0 12px;">ترتیب (عدد کوچک‌تر = بالاتر) در همه‌ی منوها و فیلترها رعایت می‌شود. برای آیکون می‌توانید یک ایموجی بچسبانید.</p>
    <form method="post" action="save_case_status.php" style="display:grid; grid-template-columns:1fr 90px 90px 120px 100px auto; gap:8px; align-items:end;">
        <?= csrf_field() ?>
        <div class="form-group" style="margin:0;">
            <label>نام وضعیت *</label>
            <input type="text" name="name" required placeholder="مثلاً: در حال طراحی">
        </div>
        <div class="form-group" style="margin:0;">
            <label>ترتیب</label>
            <input type="number" name="sort_order" value="<?= count($statuses) + 1 ?>" step="1">
        </div>
        <div class="form-group" style="margin:0;">
            <label>آیکون</label>
            <input type="text" name="icon" list="status-icon-presets" placeholder="🎨">
        </div>
        <div class="form-group" style="margin:0;">
            <label>رنگ</label>
            <input type="color" name="color" value="#06b6d4" style="padding:2px; height:38px;">
        </div>
        <label style="display:flex; align-items:center; gap:4px; font-size:0.85rem; margin-bottom:8px;" title="تغییر به این وضعیت به کاربران مرتبط اعلان می‌فرستد">
            <input type="checkbox" name="is_abnormal" value="1"> غیرعادی
        </label>
        <div>
            <button class="btn" style="background:#0F172A; color:#fff;">افزودن</button>
        </div>
    </form>
    <datalist id="status-icon-presets">
        <?php foreach ($iconPresets as $ic): ?><option value="<?= htmlspecialchars($ic) ?>"></option><?php endforeach; ?>
    </datalist>
</div>

<?php if ($msg === 'saved'): ?><p style="color:#166534; font-weight:bold;">ذخیره شد.</p><?php endif; ?>
<?php if ($msg === 'deleted'): ?><p style="color:#166534; font-weight:bold;">حذف شد.</p><?php endif; ?>
<?php if ($err === 'name'): ?><p style="color:#b91c1c; font-weight:bold;">نام وضعیت الزامی است.</p><?php endif; ?>
<?php if ($err === 'duplicate'): ?><p style="color:#b91c1c; font-weight:bold;">وضعیتی با این نام قبلاً ثبت شده است.</p><?php endif; ?>
<?php if ($err === 'in_use'): ?><p style="color:#b91c1c; font-weight:bold;">این وضعیت روی چند کیس استفاده می‌شود و قابل حذف نیست.</p><?php endif; ?>

<div class="form-card" style="max-width:1000px; margin:0 auto;">
    <table class="display" style="width:100%">
        <thead>
        <tr>
            <th>پیش‌نمایش</th>
            <th>نام</th>
            <th>ترتیب</th>
            <th>آیکون</th>
            <th>رنگ</th>
            <th>غیرعادی</th>
            <th>تعداد کیس</th>
            <th>عملیات</th>
        </tr>
        </thead>
        <tbody id="status-rows">
        <?php foreach ($statuses as $s):
            $used = $usage[(int) $s['id']] ?? 0;
            $color = $s['color'] ?: '#e5e7eb';
            $textColor = $s['color'] ? '#fff' : '#374151';
            ?>
            <tr data-id="<?= (int) $s['id'] ?>">
                <td>
                    <span class="drag-handle" title="برای تغییر ترتیب بکش" style="cursor:move; margin-left:6px; color:#9ca3af; font-size:1.1rem; user-select:none;">⠿</span>
                    <span style="display:inline-flex; align-items:center; gap:5px; background:<?= htmlspecialchars($color) ?>; color:<?= $textColor ?>; border-radius:6px; padding:2px 8px; font-weight:600; white-space:nowrap;">
                        <?php if (!empty($s['icon'])): ?><span><?= htmlspecialchars($s['icon']) ?></span><?php endif; ?>
                        <span><?= htmlspecialchars($s['name']) ?></span>
                    </span>
                </td>
                <td colspan="6" style="padding:0;">
                    <form method="post" action="save_case_status.php" style="display:grid; grid-template-columns:1fr 80px 80px 90px 70px auto; gap:6px; align-items:center; padding:6px 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <input type="text" name="name" value="<?= htmlspecialchars($s['name']) ?>" style="width:100%;">
                        <input type="number" name="sort_order" value="<?= (int) $s['sort_order'] ?: 10 ?>" step="1" style="width:100%;">
                        <input type="text" name="icon" list="status-icon-presets" value="<?= htmlspecialchars($s['icon'] ?? '') ?>" placeholder="آیکون" style="width:100%;">
                        <input type="color" name="color" value="<?= htmlspecialchars($s['color'] ?: '#e5e7eb') ?>" style="width:100%; padding:1px; height:34px;">
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.78rem; white-space:nowrap;" title="تغییر به این وضعیت به کاربران مرتبط اعلان می‌فرستد">
                            <input type="checkbox" name="is_abnormal" value="1" <?= !empty($s['is_abnormal']) ? 'checked' : '' ?>> غیرعادی
                        </label>
                        <div style="display:flex; gap:4px; align-items:center; white-space:nowrap;">
                            <span style="color:#6b7280; font-size:0.8rem;"><?= toPersianDigits($used) ?></span>
                            <button class="btn" style="background:#06B6D4; color:#fff; padding:4px 10px;">ذخیره</button>
                        </div>
                    </form>
                </td>
                <td>
                    <?php if ($used === 0): ?>
                        <form method="post" action="delete_case_status.php" onsubmit="return confirm('این وضعیت حذف شود؟');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px;">حذف</button>
                        </form>
                    <?php else: ?>
                        <span style="color:#9ca3af; font-size:0.8rem;">در استفاده</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script>
(function(){
    var tbody = document.getElementById('status-rows');
    if (!tbody) return;
    var dragSrc = null;
    var csrf = '<?= htmlspecialchars($_SESSION['_csrf_token'] ?? '') ?>';

    function persistOrder(){
        var ids = [];
        tbody.querySelectorAll('tr[data-id]').forEach(function(tr, idx){
            ids.push(tr.getAttribute('data-id'));
            var inp = tr.querySelector('input[name="sort_order"]');
            if (inp) inp.value = (idx + 1);
        });
        var fd = new FormData();
        fd.append('ids', JSON.stringify(ids));
        fd.append('_csrf_token', csrf);
        fetch('reorder_case_statuses.php', { method:'POST', headers:{ 'X-CSRF-Token': csrf }, body: fd })
            .then(function(r){ return r.json().catch(function(){ return {}; }); })
            .then(function(res){
                if (!res || !res.success) { alert('ذخیرهٔ ترتیب ناموفق بود.'); }
            })
            .catch(function(){ alert('خطا در ذخیرهٔ ترتیب.'); });
    }

    tbody.querySelectorAll('tr[data-id]').forEach(function(tr){
        var grip = tr.querySelector('.drag-handle');
        tr.setAttribute('draggable', 'false');
        if (grip) {
            grip.addEventListener('mousedown', function(){ tr.setAttribute('draggable', 'true'); });
            grip.addEventListener('touchstart', function(){ tr.setAttribute('draggable', 'true'); }, { passive: true });
        }
        tr.addEventListener('dragstart', function(e){
            if (tr.getAttribute('draggable') !== 'true') { e.preventDefault(); return; }
            dragSrc = tr;
            tr.style.opacity = '0.4';
            try { e.dataTransfer.effectAllowed = 'move'; } catch(err) {}
        });
        tr.addEventListener('dragend', function(){
            tr.style.opacity = '1';
            tr.setAttribute('draggable', 'false');
            dragSrc = null;
        });
        tr.addEventListener('dragover', function(e){ e.preventDefault(); });
        tr.addEventListener('drop', function(e){
            e.preventDefault();
            if (!dragSrc || dragSrc === tr) return;
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-id]'));
            var fromIdx = rows.indexOf(dragSrc);
            var toIdx = rows.indexOf(tr);
            if (fromIdx < toIdx) {
                tbody.insertBefore(dragSrc, tr.nextSibling);
            } else {
                tbody.insertBefore(dragSrc, tr);
            }
            persistOrder();
        });
    });
})();
</script>
<?php panel_layout_end(); ?>
