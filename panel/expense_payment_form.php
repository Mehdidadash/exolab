<?php
// panel/expense_payment_form.php
// Register a payment WE make to a designer or outsource lab (free-text recipient
// bank/card info – no pre-defined bank_accounts row required).
require_once __DIR__ . '/auth.php';
require_admin();

$editing = !empty($_GET['id']);
$payment = $editing ? getExpensePayment((int) $_GET['id']) : null;
if ($editing && !$payment) {
    header('Location: expense_payments.php?error=notfound');
    exit;
}

$invoices = getPayableExpenseInvoices();
// Preselect type+invoice from query string (e.g. from expenses.php "ثبت پرداخت")
$presetType = isset($_GET['type']) && in_array($_GET['type'], ['designer', 'outsource'], true) ? $_GET['type'] : ($payment['expense_type'] ?? '');
$presetInvoice = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : ($payment['invoice_id'] ?? 0);

panel_layout_start($editing ? 'ویرایش پرداخت (هزینه)' : 'ثبت پرداخت (هزینه)');
?>
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<div style="margin-bottom:18px;">
    <a class="btn" href="expense_payments.php" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
    <a class="btn" href="payments.php" style="background:#E5E7EB; color:#0F172A;">پرداخت‌های دریافتی (دکتر)</a>
</div>

<form method="post" action="save_expense_payment.php">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $payment['id'] ?>"><?php endif; ?>

    <div class="form-card">
        <h3 style="margin-top:0;"><?= $editing ? 'ویرایش' : 'ثبت' ?> پرداخت به طراح / لابراتوار</h3>
        <p style="color:#525252;">پرداختی‌هایی که <strong>ما به دیگران</strong> انجام می‌دهیم (هزینه طراحی / برون‌سپاری). مشخصات حساب گیرنده را می‌توانید دستی وارد کنید — نیازی به تعریف حساب بانکی قبلی نیست.</p>

        <div class="form-group">
            <label for="expense_type">نوع</label>
            <select id="expense_type" name="expense_type" required onchange="filterInvoices()">
                <option value="designer" <?= $presetType === 'designer' ? 'selected' : '' ?>>فاکتور طراحی (هزینه به طراح)</option>
                <option value="outsource" <?= $presetType === 'outsource' ? 'selected' : '' ?>>فاکتور برون‌سپاری (هزینه به لابراتوار)</option>
            </select>
        </div>

        <div class="form-group">
            <label for="invoice_id">فاکتور</label>
            <select id="invoice_id" name="invoice_id" required>
                <option value="">انتخاب فاکتور...</option>
                <?php foreach ($invoices as $inv): ?>
                    <option value="<?= (int) $inv['id'] ?>" data-type="<?= htmlspecialchars($inv['expense_type']) ?>"
                        <?= ($presetType === $inv['expense_type'] && (int) $presetInvoice === (int) $inv['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($inv['invoice_number']) ?> — <?= htmlspecialchars($inv['party_name'] ?? '—') ?> (<?= formatAmountToman($inv['total_amount']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="amount">مبلغ (تومان)</label>
            <input type="number" id="amount" name="amount" value="<?= round((float)($payment['amount'] ?? 0)) ?>" step="1" required>
        </div>

        <div class="form-group">
            <label for="payment_method">روش پرداخت</label>
            <select id="payment_method" name="payment_method">
                <option value="">انتخاب...</option>
                <?php foreach (['کارت به کارت', 'پایا', 'ساتنا', 'پل', 'نقدی', 'چک', 'حواله'] as $m): ?>
                    <option value="<?= $m ?>" <?= ($payment['payment_method'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="payment_date">تاریخ پرداخت</label>
            <input type="text" id="payment_date" name="payment_date" value="<?= htmlspecialchars(!empty($payment['payment_date']) ? toJalaliDateFormatted($payment['payment_date']) : toJalaliDateFormatted(date('Y-m-d'))) ?>" autocomplete="off" required>
        </div>

        <div class="form-group">
            <label for="recipient_bank">نام بانک گیرنده (اختیاری — دستی)</label>
            <input type="text" id="recipient_bank" name="recipient_bank" value="<?= htmlspecialchars($payment['recipient_bank'] ?? '') ?>" placeholder="مثلاً ملت">
        </div>

        <div class="form-group">
            <label for="recipient_card">شماره کارت / حساب / شبا گیرنده (اختیاری — دستی)</label>
            <input type="text" id="recipient_card" name="recipient_card" value="<?= htmlspecialchars($payment['recipient_card'] ?? '') ?>" placeholder="مثلاً 6037-... یا شماره حساب یا شبا" dir="ltr">
        </div>

        <div class="form-group">
            <label for="transaction_number">شماره تراکنش (اختیاری)</label>
            <input type="text" id="transaction_number" name="transaction_number" value="<?= htmlspecialchars($payment['transaction_number'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="notes">یادداشت</label>
            <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($payment['notes'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn" style="background:#06B6D4; color:#fff; margin-top:10px;">ذخیره</button>
    </div>
</form>

<script>
function filterInvoices(){
    var type = document.getElementById('expense_type').value;
    var sel = document.getElementById('invoice_id');
    Array.prototype.forEach.call(sel.options, function(opt){
        if (opt.value === '') { opt.style.display = (opt.value === '' ? '' : 'none'); return; }
        opt.style.display = (opt.dataset.type === type) ? '' : 'none';
    });
    // select first visible
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].style.display !== 'none') { sel.selectedIndex = i; break; }
    }
}
filterInvoices();
</script>
<script>
(function($){
    $(function(){
        var $input = $('#payment_date');
        if ($input.length && $.fn.persianDatepicker) {
            $input.persianDatepicker({
                format: 'YYYY/MM/DD', calendarType: 'persian',
                initialValue: true, initialValueType: 'jalali', persianDigit: true, autoClose: true,
                toolbox: { enabled: true, todayButton: { enabled: true, text: { fa: 'امروز' } }, submitButton: { enabled: true, text: { fa: 'تایید' } } }
            });
            if (!$input.val()) { $input.val('<?= toJalaliDateFormatted(date('Y-m-d')) ?>'); }
        }
    });
})(jQuery);
</script>
<?php panel_layout_end(); ?>
