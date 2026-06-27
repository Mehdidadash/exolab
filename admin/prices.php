<?php
require_once __DIR__ . '/auth.php';
$prices = getAllPrices();
admin_layout_start('لیست قیمت‌ها');
?>
<div style="margin-bottom: 18px;">
    <a class="btn" href="price_form.php">افزودن قیمت جدید</a>
</div>
<table>
    <thead>
    <tr>
        <th>ردیف</th>
        <th>عنوان</th>
        <th>دسته</th>
        <th>قیمت</th>
        <th>ترتیب</th>
        <th>فعال</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody id="sortable-prices">
    <?php foreach ($prices as $index => $price): ?>
        <tr draggable="true" data-id="<?= $price['id'] ?>">
            <td><?= $index + 1 ?></td>
            <td><?= htmlspecialchars($price['title']) ?></td>
            <td><?= htmlspecialchars($price['category']) ?></td>
            <td><?= htmlspecialchars($price['price']) ?></td>
            <td><?= isset($price['display_order']) ? (int)$price['display_order'] : 0 ?></td>
            <td><span class="badge"><?= $price['active'] ? 'بله' : 'خیر' ?></span></td>
            <td class="actions">
                <a class="btn" href="price_form.php?id=<?= $price['id'] ?>">ویرایش</a>
                <form method="post" action="delete_price.php" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                    <input type="hidden" name="id" value="<?= $price['id'] ?>">
                    <button type="submit">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<script src="../assets/js/admin-order.js"></script>
<?php admin_layout_end();
