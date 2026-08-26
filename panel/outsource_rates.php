<?php
// panel/outsource_rates.php
// Manage per-lab per-service outsourcing rates (what we pay each lab).
require_once __DIR__ . '/auth.php';
require_admin();

$rates = getAllOutsourceRates();
$labs = db()->query("SELECT id, full_name FROM users WHERE role IN ('outsource_lab','partner_lab','customer_lab','lab') AND active=1 ORDER BY full_name")->fetchAll();
$prices = getAllPrices();

panel_layout_start('نرخ‌های برون‌سپاری');
?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="generate_outsource_invoice.php" style="background: #059669; color: #fff;">صدور فاکتور برون‌سپاری</a>
        <a class="btn" href="expenses.php" style="background: #0F172A; color: #fff;">فاکتورهای مخارج</a>
    </div>
</div>

<div class="form-card" style="margin-bottom:20px; max-width:520px;">
    <h3>افزودن / به‌روزرسانی نرخ برون‌سپاری</h3>
    <form method="post" action="save_outsource_rate.php">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="lab_id">لابراتوار</label>
            <select id="lab_id" name="lab_id" required>
                <option value="">انتخاب لابراتوار...</option>
                <?php foreach ($labs as $lab): ?>
                    <option value="<?= $lab['id'] ?>"><?= htmlspecialchars($lab['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="service_id">خدمت</label>
            <select id="service_id" name="service_id" required>
                <option value="">انتخاب خدمت...</option>
                <?php foreach ($prices as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="rate">نرخ برون‌سپاری (تومان به‌ازای هر واحد)</label>
            <input type="number" id="rate" name="rate" min="0" step="1" required>
            <small style="color:#525252;">مبلغی که برای این خدمت باید به این لابراتوار بپردازیم.</small>
        </div>
        <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره</button>
    </form>
</div>

<?php if (empty($rates)): ?>
    <p class="empty">هنوز نرخی ثبت نشده است.</p>
<?php else: ?>
<table class="datatable display" data-order="0">
    <thead>
    <tr>
        <th>لابراتوار</th>
        <th>خدمت</th>
        <th>نرخ (تومان)</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rates as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['lab_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['service_title'] ?? '—') ?></td>
            <td><?= formatAmountToman($r['rate']) ?></td>
            <td class="actions">
                <form method="post" action="delete_outsource_rate.php" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px;">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php panel_layout_end(); ?>
