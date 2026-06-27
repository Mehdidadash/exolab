<?php
require_once __DIR__ . '/auth.php';
$editing = !empty($_GET['id']);
$payment = $editing ? getPayment((int) $_GET['id']) : null;
if ($editing && !$payment) {
    header('Location: payments.php?error=notfound');
    exit;
}

$bankAccounts = getAllBankAccounts();
$invoices = getAllInvoices();
$doctors = getAllDoctors();

admin_layout_start($editing ? 'ویرایش پرداخت' : 'ثبت پرداخت جدید');
?>
<form method="post" action="save_payment.php">
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= $payment['id'] ?>">
    <?php endif; ?>
    
    <div class="form-card">
        <label for="invoice_ids">انتخاب فاکتورها</label>
        <select id="invoice_ids" name="invoice_ids[]" multiple size="5">
            <?php foreach ($invoices as $inv): ?>
                <option value="<?= $inv['id'] ?>" <?= in_array($inv['id'], $payment['invoice_ids'] ?? [], true) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($inv['invoice_number']) ?> - <?= htmlspecialchars($inv['doctor_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="doctor_id">انتخاب دکتر</label>
        <select id="doctor_id" name="doctor_id">
            <option value="">انتخاب دکتر...</option>
            <?php foreach ($doctors as $doctor): ?>
                <option value="<?= $doctor['id'] ?>" <?= ($payment['doctor_id'] ?? '') == $doctor['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($doctor['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="doctor_name">نام دکتر (در صورت نداشتن دکتر ثبت‌شده)</label>
        <input type="text" id="doctor_name" name="doctor_name" value="<?= htmlspecialchars($payment['doctor_name'] ?? '') ?>">

        <label for="amount">مبلغ (تومان)</label>
        <input type="number" id="amount" name="amount" value="<?= $payment['amount'] ?? 0 ?>" step="0.01" required>
        
        <label for="payment_method">روش پرداخت</label>
        <select id="payment_method" name="payment_method" required>
            <option value="">انتخاب روش پرداخت...</option>
            <option value="کارت به کارت" <?= ($payment['payment_method'] ?? '') === 'کارت به کارت' ? 'selected' : '' ?>>کارت به کارت</option>
            <option value="شبا" <?= ($payment['payment_method'] ?? '') === 'شبا' ? 'selected' : '' ?>>شبا</option>
            <option value="نقدی" <?= ($payment['payment_method'] ?? '') === 'نقدی' ? 'selected' : '' ?>>نقدی</option>
            <option value="چک" <?= ($payment['payment_method'] ?? '') === 'چک' ? 'selected' : '' ?>>چک</option>
        </select>
        
        <label for="payment_date">تاریخ پرداخت</label>
        <input type="date" id="payment_date" name="payment_date" value="<?= $payment['payment_date'] ?? date('Y-m-d') ?>" required>
        
        <label for="transaction_number">شماره تراکنش (اختیاری)</label>
        <input type="text" id="transaction_number" name="transaction_number" value="<?= htmlspecialchars($payment['transaction_number'] ?? '') ?>">
        
        <label for="bank_account_id">حساب بانکی دریافت کننده</label>
        <select id="bank_account_id" name="bank_account_id">
            <option value="">انتخاب حساب...</option>
            <?php foreach ($bankAccounts as $acc): ?>
                <option value="<?= $acc['id'] ?>" <?= ($payment['bank_account_id'] ?? 0) == $acc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($acc['account_owner_name']) ?> - <?= htmlspecialchars($acc['bank_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <label for="notes">یادداشت‌ها</label>
        <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($payment['notes'] ?? '') ?></textarea>
        
        <button type="submit" class="btn" style="background: #06B6D4; margin-top: 20px;">ذخیره</button>
        <a href="payments.php" class="btn" style="background: #E5E7EB; color: #0F172A; margin-left: 10px;">انصراف</a>
    </div>
</form>
<?php admin_layout_end();
