<?php
// panel/prices_general_tab.php
// بدنه‌ی «قیمت‌های عمومی (پیش‌فرض)» — CRUD روی site_prices. در تب prices.php استفاده می‌شود.
require_once __DIR__ . '/auth.php';
require_admin();

if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];
session_write_close();

$prices = getAllPrices();
?>
<div style="margin-bottom: 18px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <a class="btn" href="price_form.php">افزودن قیمت جدید</a>
    <small style="color:#525252;">قیمت‌های عمومی همان پیش‌فرض همه‌ی شعب و پزشک‌ها هستند. برای ویرایش، مقدار را در همان جدول تغییر دهید و روی «ذخیره» بزنید.</small>
</div>
<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th class="th-narrow">ردیف</th>
        <th>عنوان</th>
        <th>دسته</th>
        <th class="th-price">قیمت (تومان)</th>
        <th class="th-narrow">ترتیب</th>
        <th class="th-narrow">فعال</th>
        <th class="th-narrow" title="تیک = نمایش در سایت اصلی">سایت</th>
        <th class="th-narrow">عملیات</th>
    </tr>
    </thead>
    <tbody id="sortable-prices">
    <?php foreach ($prices as $index => $price): ?>
        <tr draggable="true" data-id="<?= (int) $price['id'] ?>">
            <td class="price-index"><?= $index + 1 ?></td>
            <td><input type="text" class="ed-title" value="<?= htmlspecialchars($price['title']) ?>" style="width:96%; min-width:160px;"></td>
            <td><input type="text" class="ed-category" value="<?= htmlspecialchars($price['category']) ?>" style="width:96%; min-width:100px;"></td>
            <td><input type="number" class="ed-price" value="<?= htmlspecialchars(formatTomanInput($price['price'])) ?>" min="0" step="1" style="width:170px; font-weight:bold;"></td>
            <td><input type="number" class="ed-order" value="<?= isset($price['display_order']) ? (int)$price['display_order'] : 0 ?>" min="0" step="1" style="width:56px;"></td>
            <td style="text-align:center;"><input type="checkbox" class="ed-active" <?= $price['active'] ? 'checked' : '' ?> style="width:auto;"></td>
            <td style="text-align:center;"><input type="checkbox" class="ed-hideonsite" <?= empty($price['hide_on_site']) ? 'checked' : '' ?> style="width:auto;" title="تیک = در سایت اصلی نمایش داده شود"></td>
            <td class="actions" style="white-space:nowrap;">
                <button type="button" class="btn save-price-row" style="background:#06B6D4; color:#fff; padding:3px 8px; font-size:0.85rem;">ذخیره</button>
                <button type="button" class="btn delete-price-row" style="background:#fee2e2; color:#991b1b; padding:3px 8px; font-size:0.85rem;">حذف</button>
                <input type="hidden" class="ed-desc" value="<?= htmlspecialchars($price['description'] ?? '') ?>">
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<style>
    #sortable-prices th,
    #sortable-prices td { padding: 5px 8px; }
    #sortable-prices .th-narrow { width: 40px; white-space: nowrap; }
    #sortable-prices .th-price { white-space: nowrap; }
    #sortable-prices .price-index { text-align: center; color: #64748b; }
    #sortable-prices input { padding: 6px 8px; }
</style>
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
        var hs = tr.querySelector('.ed-hideonsite');
        data.set('hide_on_site', (hs && hs.checked) ? '0' : '1');
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
