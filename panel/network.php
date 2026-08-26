<?php
// panel/network.php
// Directory / شبکه همکاران: doctors, clinics and labs with their sub-doctors.
// Replaces the plain doctors list with a structured network view where clinic/lab
// sub-doctors can be managed (add/remove).
require_once __DIR__ . '/auth.php';
require_admin();

// Clinics with their sub-doctors
$clinics = db()->query('SELECT id, full_name, email, phone, active FROM users WHERE role = "clinic" ORDER BY full_name')->fetchAll();
$clinicDoctors = [];
$stmt = db()->prepare('SELECT id, full_name, phone, email, active FROM users WHERE role = "doctor" AND clinic_id = ? ORDER BY full_name');
foreach ($clinics as $c) {
    $stmt->execute([(int) $c['id']]);
    $clinicDoctors[(int) $c['id']] = $stmt->fetchAll();
}

// Labs with their sub-doctors
$labs = db()->query("SELECT id, full_name, email, phone, active, role FROM users WHERE role IN ('outsource_lab','customer_lab','partner_lab','lab') ORDER BY full_name")->fetchAll();
$labDoctors = [];
$stmt2 = db()->prepare('SELECT id, full_name, phone, email, active FROM users WHERE role = "doctor" AND lab_id = ? ORDER BY full_name');
foreach ($labs as $l) {
    $stmt2->execute([(int) $l['id']]);
    $labDoctors[(int) $l['id']] = $stmt2->fetchAll();
}

// Independent doctors (not assigned to any clinic or lab)
$independent = db()->query('SELECT id, full_name, phone, email, active FROM users WHERE role = "doctor" AND clinic_id IS NULL AND lab_id IS NULL ORDER BY full_name')->fetchAll();
// All doctors for add-dropdowns
$allDoctors = db()->query('SELECT id, full_name FROM users WHERE role = "doctor" AND active = 1 ORDER BY full_name')->fetchAll();

panel_layout_start('شبکه همکاران');
?>
<div style="margin-bottom:18px;">
    <a class="btn" href="doctor_form.php" style="background:#0F172A; color:#fff;">افزودن پزشک جدید</a>
    <a class="btn" href="doctors.php">لیست ساده پزشکان</a>
    <a class="btn" href="dashboard.php" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
</div>

<p style="color:#525252; margin-bottom:18px;">نمای شبکه‌ای همکاران: هر کلینیک یا لابراتوار می‌تواند پزشکان زیرمجموعه خودش را داشته باشد. پزشک می‌تواند عضو حداکثر یک کلینیک و یک لابراتوار باشد.</p>

<!-- ─── Clinics ─── -->
<h3 style="margin:20px 0 10px;">کلینیک‌ها و پزشکان زیرمجموعه</h3>
<?php if (empty($clinics)): ?>
    <p class="empty">کلینیکی ثبت نشده است.</p>
<?php else: foreach ($clinics as $cl): ?>
    <div class="form-card" style="margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div>
                <strong style="font-size:1.05rem;">🏥 <?= htmlspecialchars($cl['full_name']) ?></strong>
                <span style="color:#525252; font-size:0.85rem; margin-right:10px;">
                    <?= htmlspecialchars($cl['phone'] ?? '') ?>
                </span>
                <span class="badge" style="background:#e0e7ff; color:#3730a3;">کلینیک</span>
            </div>
            <form method="post" action="save_entity_doctor.php" style="display:flex; gap:6px; align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="entity_type" value="clinic">
                <input type="hidden" name="entity_id" value="<?= (int) $cl['id'] ?>">
                <select name="doctor_id" style="min-width:180px;">
                    <option value="">افزودن پزشک...</option>
                    <?php foreach ($allDoctors as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" style="padding:4px 10px; background:#06B6D4; color:#fff;">➕ افزودن</button>
            </form>
        </div>
        <?php $subs = $clinicDoctors[(int) $cl['id']] ?? []; ?>
        <?php if (empty($subs)): ?>
            <p class="empty" style="margin:10px 0 0;">پزشک زیرمجموعه‌ای ندارد.</p>
        <?php else: ?>
            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:10px;">
                <?php foreach ($subs as $sd): ?>
                    <span style="display:inline-flex; align-items:center; gap:6px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:8px; padding:4px 10px; font-size:0.9rem;">
                        🩺 <?= htmlspecialchars($sd['full_name']) ?>
                        <form method="post" action="save_entity_doctor.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entity_type" value="clinic">
                            <input type="hidden" name="entity_id" value="<?= (int) $cl['id'] ?>">
                            <input type="hidden" name="doctor_id" value="<?= (int) $sd['id'] ?>">
                            <input type="hidden" name="action" value="remove">
                            <button type="submit" style="background:none; border:none; color:#b91c1c; cursor:pointer; font-size:0.85rem;" title="حذف از کلینیک">✕</button>
                        </form>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; endif; ?>

<!-- ─── Labs ─── -->
<h3 style="margin:24px 0 10px;">لابراتوارها و پزشکان زیرمجموعه</h3>
<?php if (empty($labs)): ?>
    <p class="empty">لابراتواری ثبت نشده است.</p>
<?php else: foreach ($labs as $lb): ?>
    <div class="form-card" style="margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div>
                <strong style="font-size:1.05rem;">🏭 <?= htmlspecialchars($lb['full_name']) ?></strong>
                <span style="color:#525252; font-size:0.85rem; margin-right:10px;">
                    <?= htmlspecialchars($lb['phone'] ?? '') ?>
                </span>
                <span class="badge" style="background:#dcfce7; color:#166534;">لابراتوار (<?= htmlspecialchars($lb['role']) ?>)</span>
            </div>
            <form method="post" action="save_entity_doctor.php" style="display:flex; gap:6px; align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="entity_type" value="lab">
                <input type="hidden" name="entity_id" value="<?= (int) $lb['id'] ?>">
                <select name="doctor_id" style="min-width:180px;">
                    <option value="">افزودن پزشک...</option>
                    <?php foreach ($allDoctors as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" style="padding:4px 10px; background:#06B6D4; color:#fff;">➕ افزودن</button>
            </form>
        </div>
        <?php $subs = $labDoctors[(int) $lb['id']] ?? []; ?>
        <?php if (empty($subs)): ?>
            <p class="empty" style="margin:10px 0 0;">پزشک زیرمجموعه‌ای ندارد.</p>
        <?php else: ?>
            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:10px;">
                <?php foreach ($subs as $sd): ?>
                    <span style="display:inline-flex; align-items:center; gap:6px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:8px; padding:4px 10px; font-size:0.9rem;">
                        🩺 <?= htmlspecialchars($sd['full_name']) ?>
                        <form method="post" action="save_entity_doctor.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entity_type" value="lab">
                            <input type="hidden" name="entity_id" value="<?= (int) $lb['id'] ?>">
                            <input type="hidden" name="doctor_id" value="<?= (int) $sd['id'] ?>">
                            <input type="hidden" name="action" value="remove">
                            <button type="submit" style="background:none; border:none; color:#b91c1c; cursor:pointer; font-size:0.85rem;" title="حذف از لابراتوار">✕</button>
                        </form>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; endif; ?>

<!-- ─── Independent doctors ─── -->
<h3 style="margin:24px 0 10px;">پزشکان مستقل (بدون کلینیک/لابراتوار)</h3>
<?php if (empty($independent)): ?>
    <p class="empty">همه پزشکان به یک کلینیک یا لابراتوار متصل هستند.</p>
<?php else: ?>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        <?php foreach ($independent as $idoc): ?>
            <a href="doctor_view.php?id=<?= (int) $idoc['id'] ?>" style="display:inline-flex; align-items:center; gap:6px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:8px; padding:4px 10px; font-size:0.9rem; text-decoration:none; color:#0F172A;">
                🩺 <?= htmlspecialchars($idoc['full_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php panel_layout_end(); ?>
