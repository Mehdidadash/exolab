<?php
// panel\payment_form.php

require_once __DIR__ . '/auth.php';
require_role('admin');
$editing = !empty($_GET['id']);
$payment = $editing ? getPayment((int) $_GET['id']) : null;
if ($editing && !$payment) {
    header('Location: payments.php?error=notfound');
    exit;
}

$bankAccounts = getAllBankAccounts();
$invoices = getAllInvoices();
$billingTargets = getAllBillingTargets();

panel_layout_start($editing ? 'ویرایش پرداخت' : 'ثبت پرداخت جدید');
?>
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<form method="post" action="save_payment.php">
    <?= csrf_field() ?>
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

        <label for="doctor_id">انتخاب پزشک/طراح/کلینیک</label>
        <select id="doctor_id" name="doctor_id">
            <option value="">انتخاب...</option>
            <?php foreach ($billingTargets as $target): ?>
                <option value="<?= $target['id'] ?>" <?= ($payment['doctor_id'] ?? '') == $target['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($target['name']) ?> (<?= htmlspecialchars($target['role'] === 'clinic' ? 'کلینیک' : ($target['role'] === 'designer' ? 'طراح' : 'پزشک')) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <label for="amount">مبلغ (تومان)</label>
        <input type="number" id="amount" name="amount" value="<?= round((float)($payment['amount'] ?? 0)) ?>" step="1" required>
        
        <label for="payment_method">روش پرداخت</label>
        <select id="payment_method" name="payment_method" required>
            <option value="">انتخاب روش پرداخت...</option>
            <option value="کارت به کارت" <?= ($payment['payment_method'] ?? '') === 'کارت به کارت' ? 'selected' : '' ?>>کارت به کارت</option>
            <option value="پایا" <?= ($payment['payment_method'] ?? '') === 'پایا' ? 'selected' : '' ?>>پایا</option>
            <option value="ساتنا" <?= ($payment['payment_method'] ?? '') === 'ساتنا' ? 'selected' : '' ?>>ساتنا</option>
            <option value="پل" <?= ($payment['payment_method'] ?? '') === 'پل' ? 'selected' : '' ?>>پل</option>
            <option value="نقدی" <?= ($payment['payment_method'] ?? '') === 'نقدی' ? 'selected' : '' ?>>نقدی</option>
            <option value="چک" <?= ($payment['payment_method'] ?? '') === 'چک' ? 'selected' : '' ?>>چک</option>
        </select>
        
        <label for="payment_date">تاریخ پرداخت</label>
        <input type="text" id="payment_date" name="payment_date" value="<?= htmlspecialchars(!empty($payment['payment_date']) ? toJalaliDate($payment['payment_date']) : toJalaliDate(date('Y-m-d'))) ?>" autocomplete="off" required>
        
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
<script>
(function ($) {
    $(function () {
        var $input = $('#payment_date');
        var todayJalali = '<?= toJalaliDateFormatted(date('Y-m-d')) ?>';
        if ($input.length && $.fn.persianDatepicker) {
            $input.persianDatepicker({
                format: 'YYYY/MM/DD',
                calendarType: 'persian',
                initialValue: true,
                initialValueType: 'jalali',
                persianDigit: true,
                autoClose: true,
                toolbox: {
                    enabled: true,
                    todayButton: { enabled: true, text: { fa: 'امروز', en: 'Today' } },
                    submitButton: { enabled: true, text: { fa: 'تایید', en: 'Submit' } }
                }
            });
            if (!$input.val()) {
                $input.val(todayJalali);
            }
            $input.off('click focus').on('click focus', function () {
                if (!$(this).val()) {
                    $(this).val(todayJalali);
                }
                try { $(this).persianDatepicker('show'); } catch (e) {}
            });
        }
    });
})(jQuery);
</script>
<?php panel_layout_end();
