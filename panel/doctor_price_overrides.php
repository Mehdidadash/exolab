<?php
// panel/doctor_price_overrides.php
require_once __DIR__ . '/auth.php';
require_admin();

$overrides = getAllDoctorPriceOverrides();
panel_layout_start('مدیریت قیمت‌های اختصاصی');
?>
<div style="margin-bottom:18px;">
    <a class="btn" href="doctor_price_override_form.php">افزودن قیمت اختصاصی جدید</a>
</div>
<table class="datatable display">
    <thead>
        <tr>
            <th>کاربر</th>
            <th>نوع</th>
            <th>مورد</th>
            <th>قیمت اختصاصی (تومان)</th>
            <th>عملیات</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($overrides as $override): ?>
        <tr>
            <td><?= htmlspecialchars($override['doctor_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($override['price_type'] === 'design_fee' ? 'هزینه طراحی' : 'قیمت خدمت') ?></td>
            <td><?= htmlspecialchars($override['service_title'] ?? '—') ?></td>
            <td><?= formatAmountToman($override['custom_price']) ?></td>
            <td class="actions">
                <?= action_dropdown(null, 'doctor_price_override_form.php?id=' . $override['id'], 'delete_doctor_price_override.php', $override['id']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($overrides)): ?>
        <tr><td colspan="5" class="empty">هیچ قیمت اختصاصی ثبت نشده است.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php panel_layout_end(); ?>