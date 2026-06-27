<?php
require_once __DIR__ . '/auth.php';
$works = getAllPortfolioWorks();
admin_layout_start('مدیریت نمونه کار');
?>
<div style="margin-bottom: 18px;">
    <a class="btn" href="work_form.php">افزودن نمونه کار جدید</a>
</div>
<table>
    <thead>
    <tr>
        <th>ردیف</th>
        <th>عنوان</th>
        <th>تصویر</th>
        <th>فعال</th>
        <th>ترتیب نمایش</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody id="sortable-works">
    <?php foreach ($works as $index => $work): ?>
        <tr draggable="true" data-id="<?= $work['id'] ?>">
            <td><?= $index + 1 ?></td>
            <td><?= htmlspecialchars($work['title']) ?></td>
            <td><small><?= htmlspecialchars($work['image_filename']) ?></small></td>
            <td><span class="badge"><?= $work['active'] ? 'بله' : 'خیر' ?></span></td>
            <td><?= (int) $work['display_order'] ?></td>
            <td class="actions">
                <a class="btn" href="work_form.php?id=<?= $work['id'] ?>">ویرایش</a>
                <form method="post" action="delete_work.php" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                    <input type="hidden" name="id" value="<?= $work['id'] ?>">
                    <button type="submit">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<script src="../assets/js/admin-order.js"></script>
<?php admin_layout_end();
