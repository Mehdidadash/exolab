<?php
require_once __DIR__ . '/auth.php';
$accounts = getAllBankAccounts();
admin_layout_start('مدیریت حسابهای بانکی');
?>
<div style="margin-bottom: 18px;">
    <a class="btn" href="bank_account_form.php">افزودن حساب بانکی جدید</a>
</div>
<table>
    <thead>
    <tr>
        <th>نام صاحب حساب</th>
        <th>نام بانک</th>
        <th>شماره حساب</th>
        <th>شماره کارت</th>
        <th>IBAN/SHEBA</th>
        <th>فعال</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($accounts as $account): ?>
        <tr>
            <td><?= htmlspecialchars($account['account_owner_name']) ?></td>
            <td><?= htmlspecialchars($account['bank_name']) ?></td>
            <td><small><?= htmlspecialchars($account['account_number'] ?? '—') ?></small></td>
            <td><small><?= htmlspecialchars($account['card_number'] ?? '—') ?></small></td>
            <td><small><?= htmlspecialchars($account['iban_sheba'] ?? '—') ?></small></td>
            <td><span class="badge"><?= $account['is_active'] ? 'بله' : 'خیر' ?></span></td>
            <td class="actions">
                <a class="btn" href="bank_account_form.php?id=<?= $account['id'] ?>">ویرایش</a>
                <form method="post" action="delete_bank_account.php" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                    <input type="hidden" name="id" value="<?= $account['id'] ?>">
                    <button type="submit">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php admin_layout_end();
