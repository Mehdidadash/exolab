<?php
// panel\doctors.php

require_once __DIR__ . '/auth.php';
require_role('admin');
$doctors = getAllDoctors();
panel_layout_start('لیست پزشکان');
?>
<div style="margin-bottom: 18px;">
    <a class="btn" href="doctor_form.php">افزودن پزشک جدید</a>
</div>
<table>
    <thead>
    <tr>
        <th>ردیف</th>
        <th>نام</th>
        <th>تلفن</th>
        <th>ایمیل</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($doctors as $index => $doctor): ?>
        <tr>
            <td><?= $index + 1 ?></td>
            <td><?= htmlspecialchars($doctor['name']) ?></td>
            <td><?= htmlspecialchars($doctor['phone'] ?? '') ?></td>
            <td><?= htmlspecialchars($doctor['email'] ?? '') ?></td>
            <td class="actions">
                <a class="btn" href="doctor_form.php?id=<?= $doctor['id'] ?>">ویرایش</a>
                <form method="post" action="delete_doctor.php" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                    <input type="hidden" name="id" value="<?= $doctor['id'] ?>">
                    <button type="submit">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php panel_layout_end();
