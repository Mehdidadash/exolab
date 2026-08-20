<?php
// panel\prices.php

require_once __DIR__ . '/auth.php';
require_role('admin');

// CSRF token for the inline AJAX save/delete
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];
session_write_close();

$prices = getAllPrices();
panel_layout_start('لیست قیمت‌ها');
?>
<div style="margin-bottom: 18px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <a class="btn" href="price_form.php">افزودن قیمت جدید</a>
    <small style="color:#525252;">برای ویرایش، مقدار را در همان جدول تغییر دهید و روی «ذخیره» بزنید. با کشیدن ردیفها میتوانید ترتیب را تغییر دهید.</small>
</div>
<table>
    <thead>
    <tr>
        <th>ردیف</th>
        <th>عنوان</th>
        <th>دسته</th>
        <th>قیمت (تومان)</th>
        <th>ترتیب</th>
        <th>فعال</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody id="sortable-prices">
    <?php foreach ($prices as $index => $price): ?>
        <tr draggable="true" data-id="<?= (int) $price['id'] ?>">
            <td class="price-index"><?= $index + 1 ?></td>
            <td><input type="text" class="ed-title" value="<?= htmlspecialchars($price['title']) ?>" style="width:96%;"></td>
            <td><input type="text" class="ed-category" value="<?= htmlspecialchars($price['category']) ?>" style="width:96%;"></td>
            <td><input type="number" class="ed-price" value="<?= htmlspecialchars($price['price']) ?>" min="0" step="1" style="width:110px;"></td>
            <td><input type="number" class="ed-order" value="<?= isset($price['display_order']) ? (int)$price['display_order'] : 0 ?>" min="0" step="1" style="width:70px;"></td>
            <td style="text-align:center;"><input type="checkbox" class="ed-active" <?= $price['active'] ? 'checked' : '' ?> style="width:auto;"></td>
            <td class="actions" style="white-space:nowrap;">
                <button type="button" class="btn save-price-row" style="background:#06B6D4; color:#fff; padding:4px 10px;">ذخیره</button>
                <button type="button" class="btn delete-price-row" style="background:#fee2e2; color:#991b1b; padding:4px 10px;">حذف</button>
                <input type="hidden" class="ed-desc" value="<?= htmlspecialchars($price['description'] ?? '') ?>">
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<script src="../assets/js/admin-order.js"></script>
<script>
(function(){
    var csrf = '<?= htmlspecialchars($csrf_token) ?>';
    function saveRow(tr){
        var btn = tr.querySelector('.save-price-row');
        if (btn) { btn.disabled = true; btn.textContent = '...'; }
        var data = new URLSearchParams();
        data.set('id', tr.getAttribute('data-id'));
        data.set('title', tr.querySelector('.ed-title').value.trim());
        data.set('category', tr.querySelector('.ed-category').value.trim());
        data.set('price', tr.querySelector('.ed-price').value.trim());
        data.set('display_order', tr.querySelector('.ed-order').value.trim() || '0');
        data.set('active', tr.querySelector('.ed-active').checked ? '1' : '0');
        data.set('description', tr.querySelector('.ed-desc').value);
        data.set('_csrf_token', csrf);
        fetch('save_price.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: data
        }).then(function(r){ return r.json(); }).then(function(res){
            if (btn) { btn.disabled = false; btn.textContent = 'ذخیره'; }
            if (res && res.success) {
                btn.style.background = '#dcfce7';
                btn.style.color = '#166534';
                btn.textContent = '✓ ذخیره شد';
                setTimeout(function(){ if(btn){ btn.style.background = '#06B6D4'; btn.style.color = '#fff'; btn.textContent = 'ذخیره'; } }, 1500);
            } else {
                alert((res && res.message) || 'خطا در ذخیره قیمت.');
            }
        }).catch(function(){ if(btn){ btn.disabled=false; btn.textContent='ذخیره'; } alert('خطا در اتصال به سرور.'); });
    }
    function deleteRow(tr){
        if (!confirm('آیا از حذف این قیمت مطمئن هستید؟')) return;
        var data = new URLSearchParams();
        data.set('id', tr.getAttribute('data-id'));
        data.set('_csrf_token', csrf);
        fetch('delete_price.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: data
        }).then(function(){ tr.remove(); }).catch(function(){ alert('خطا در حذف.'); });
    }
    document.querySelectorAll('#sortable-prices tr').forEach(function(tr){
        var saveBtn = tr.querySelector('.save-price-row');
        var delBtn = tr.querySelector('.delete-price-row');
        if (saveBtn) saveBtn.addEventListener('click', function(){ saveRow(tr); });
        if (delBtn) delBtn.addEventListener('click', function(){ deleteRow(tr); });
    });
})();
</script>
<?php panel_layout_end();
