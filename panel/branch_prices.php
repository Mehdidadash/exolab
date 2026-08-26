<?php
// panel/branch_prices.php
// Per-branch service prices: shows the SHARED standard service catalog and lets
// the current branch set its own custom price per service (fallback = shared price).
require_once __DIR__ . '/auth.php';
require_admin();

$bid = currentBranchId();
if ($bid === null) {
    // Root admin: this page is for branch admins; redirect to shared prices.
    header('Location: prices.php');
    exit;
}
$branch = getBranch($bid);

// Shared catalog (all branches see the same titles/units)
$prices = getAllPrices();

// This branch's custom prices
$custom = [];
$stmt = db()->prepare('SELECT service_id, custom_price FROM branch_service_prices WHERE branch_id = ?');
$stmt->execute([$bid]);
foreach ($stmt->fetchAll() as $r) {
    $custom[(int) $r['service_id']] = (float) $r['custom_price'];
}

panel_layout_start('قیمت‌های شعبه');
?>
<div style="margin-bottom:18px;">
    <a class="btn" href="network.php" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
    <a class="btn" href="prices.php">لیست قیمت مشترک (مرجع)</a>
</div>
<div class="form-card" style="margin-bottom:16px; padding:14px 18px;">
    <strong>شعبه: <?= htmlspecialchars($branch['name'] ?? '') ?></strong>
    <p style="color:#525252; margin-top:6px;">
        این لیست همان <strong>لیست قیمت مشترک (مرجع شعبه اصلی)</strong> است که همه شعب از همان عناوین و واحدها استفاده می‌کنند.
        می‌توانید برای هر خدمت یک <strong>قیمت اختصاصی این شعبه</strong> تعیین کنید. اگر قیمت اختصاصی تعیین نشود، از لیست مشترک استفاده می‌شود.
    </p>
</div>
<table class="datatable display">
    <thead>
    <tr>
        <th>خدمت</th>
        <th>دسته</th>
        <th>قیمت مرجع (مشترک)</th>
        <th>قیمت اختصاصی این شعبه</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($prices as $p):
        $isCustom = isset($custom[(int) $p['id']]);
        $customVal = $custom[(int) $p['id']] ?? null;
    ?>
        <tr>
            <td><?= htmlspecialchars($p['title']) ?></td>
            <td><?= htmlspecialchars($p['category']) ?></td>
            <td><?= $p['active'] ? formatAmountToman($p['price']) : '<span style="color:#9ca3af;">غیرفعال</span>' ?></td>
            <td>
                <form method="post" action="save_branch_service_price.php" style="display:flex; gap:6px; align-items:center;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="service_id" value="<?= (int) $p['id'] ?>">
                    <input type="number" name="price" min="0" step="1" value="<?= $customVal !== null ? (int) $customVal : '' ?>" placeholder="استفاده از مرجع" style="width:140px;">
                </form>
            </td>
            <td>
                <form method="post" action="save_branch_service_price.php" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="service_id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="price" value="">
                    <button class="btn" style="background:#E5E7EB; color:#0F172A; padding:4px 10px;">ذخیره</button>
                </form>
                <?php if ($isCustom): ?>
                    <span class="badge" style="background:#dcfce7; color:#166534;">اختصاصی</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php panel_layout_end(); ?>
