<?php
// panel/doctor_price_overrides.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$overrides = getAllDoctorPriceOverrides();
panel_layout_start('مدیریت قیمت‌های اختصاصی پزشکان');
?>
<div style="margin-bottom:18px;">
    <a class="btn" href="doctor_price_override_form.php">افزودن قیمت اختصاصی جدید</a>
</div>
<table>
    <thead>
        <tr>
            <th>پزشک</th>
            <th>خدمت</th>
            <th>قیمت اختصاصی (تومان)</th>
            <th>عملیات</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($overrides as $override): ?>
        <tr>
            <td><?= htmlspecialchars($override['doctor_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($override['service_title'] ?? '—') ?></td>
            <td><?= formatAmountToman($override['custom_price']) ?></td>
            <td class="actions">
                <a class="btn" href="doctor_price_override_form.php?id=<?= $override['id'] ?>">ویرایش</a>
                <form method="post" action="delete_doctor_price_override.php" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                    <input type="hidden" name="id" value="<?= $override['id'] ?>">
                    <button type="submit">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($overrides)): ?>
        <tr><td colspan="4" class="empty">هیچ قیمت اختصاصی ثبت نشده است.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php panel_layout_end(); ?>