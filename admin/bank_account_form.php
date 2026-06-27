<?php
require_once __DIR__ . '/auth.php';
$editing = !empty($_GET['id']);
$account = $editing ? getBankAccount((int) $_GET['id']) : null;
if ($editing && !$account) {
    header('Location: bank_accounts.php?error=notfound');
    exit;
}

admin_layout_start($editing ? 'ویرایش حساب بانکی' : 'افزودن حساب بانکی جدید');
?>
<form method="post" action="save_bank_account.php">
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= $account['id'] ?>">
    <?php endif; ?>
    
    <div class="form-card">
        <label for="account_owner_name">نام صاحب حساب</label>
        <input type="text" id="account_owner_name" name="account_owner_name" value="<?= $account['account_owner_name'] ?? '' ?>" required>
        
        <label for="bank_name">نام بانک</label>
        <input type="text" id="bank_name" name="bank_name" value="<?= $account['bank_name'] ?? '' ?>" required>
        
        <label for="account_number">شماره حساب</label>
        <input type="text" id="account_number" name="account_number" value="<?= $account['account_number'] ?? '' ?>">
        
        <label for="card_number">شماره کارت</label>
        <input type="text" id="card_number" name="card_number" value="<?= $account['card_number'] ?? '' ?>">
        
        <label for="iban_sheba">IBAN/SHEBA</label>
        <input type="text" id="iban_sheba" name="iban_sheba" value="<?= $account['iban_sheba'] ?? '' ?>" placeholder="IR...">
        <p style="font-size: 0.9rem; color: #6b7280;">حداقل یک مورد از شماره حساب، شماره کارت یا شبا/ایبان باید وارد شود.</p>
        
        <label for="is_active">فعال</label>
        <select id="is_active" name="is_active">
            <option value="1" <?= ($account['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>بله</option>
            <option value="0" <?= ($account['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>خیر</option>
        </select>
        
        <label for="notes">یادداشت‌ها</label>
        <textarea id="notes" name="notes" rows="3"><?= $account['notes'] ?? '' ?></textarea>
        
        <button type="submit" class="btn" style="background: #06B6D4; margin-top: 20px;">ذخیره</button>
        <a href="bank_accounts.php" class="btn" style="background: #E5E7EB; color: #0F172A; margin-left: 10px;">انصراف</a>
    </div>
</form>
<?php admin_layout_end();
