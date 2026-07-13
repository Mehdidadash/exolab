<?php
// panel\prices.php

require_once __DIR__ . '/auth.php';
require_role('admin');

$prices = getAllPrices();
panel_layout_start('لیست قیمت‌ها');
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
                <?= action_dropdown(null, 'price_form.php?id=' . $price['id'], 'delete_price.php', $price['id']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<script src="../assets/js/admin-order.js"></script>
<?php panel_layout_end();
