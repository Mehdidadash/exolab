<?php
// panel/payment_form.php
//
// فرمِ یکپارچهٔ «ثبت دریافت» — برای همهٔ انواعِ طرف‌حساب:
//   پزشک / کلینیک / لابراتوار همکار / طراح / شعبه همکار
//
// نکتهٔ مهم (خواستهٔ کاربر): بخشِ «انتخاب فاکتورها» تا وقتی طرف‌حساب انتخاب
// نشده نمایش داده نمی‌شود و بعد از انتخاب، فقط فاکتورهای همان طرف‌حساب
// (و فقط آن‌هایی که مانده دارند) بارگذاری می‌شوند — تا فهرست انتخاب کوتاه بماند.
// «دریافت از شعبهٔ همکار» هم دیگر فرمِ جداگانه ندارد و همین‌جا ثبت می‌شود.

require_once __DIR__ . '/auth.php';
require_admin();

$editing = !empty($_GET['id']);
$payment = $editing ? getPayment((int) $_GET['id']) : null;
if ($editing && !$payment) {
    header('Location: payments.php?error=notfound');
    exit;
}

$bankAccounts = getAllBankAccounts();
$billingTargets = getAllBillingTargets();
$branches = getAllBranches();
$partyLabels = paymentPartyTypeLabels();

// نقشِ کاربر → نوعِ طرف‌حسابِ پرداخت
$roleToType = [
    'doctor'        => 'doctor',
    'clinic'        => 'clinic',
    'designer'      => 'designer',
    'partner_lab'   => 'lab',
    'customer_lab'  => 'lab',
    'outsource_lab' => 'lab',
    'lab'           => 'lab',
];

$selectedType = $editing ? (string) ($payment['party_type'] ?: 'doctor') : 'doctor';
if (!empty($_GET['party_type'])) {
    $selectedType = (string) $_GET['party_type'];
}
if (!isset($partyLabels[$selectedType])) {
    $selectedType = 'doctor';
}

$selectedParty = 0;
if ($editing) {
    $selectedParty = $selectedType === 'branch'
        ? (int) ($payment['partner_branch_id'] ?? 0)
        : (int) ($payment['doctor_id'] ?? 0);
}
if (!empty($_GET['party_id'])) {
    $selectedParty = (int) $_GET['party_id'];
}
// میان‌بر: از صفحهٔ «فاکتور طلب از شعبه» با ?party_type=branch&party_id=X&receivable_id=Y
$presetReceivable = !empty($_GET['receivable_id']) ? (int) $_GET['receivable_id'] : (int) ($payment['branch_receivable_id'] ?? 0);

$myBranchId = currentBranchId();

panel_layout_start($editing ? 'ویرایش دریافت' : 'ثبت دریافت جدید');
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
        <h3 style="margin-top:0;"><?= $editing ? 'ویرایش دریافت' : 'ثبت دریافت' ?></h3>
        <p style="color:#525252; margin-top:-4px;">این فرم پولی را ثبت می‌کند که <strong>از طرف حساب دریافت می‌کنیم</strong> — پزشک، کلینیک، لابراتوار همکار، طراح یا شعبه همکار.</p>

        <div class="form-group">
            <label for="party_type">نوع طرف حساب</label>
            <select id="party_type" name="party_type" required>
                <?php foreach ($partyLabels as $typeKey => $typeLabel): ?>
                    <option value="<?= htmlspecialchars($typeKey) ?>" <?= $selectedType === $typeKey ? 'selected' : '' ?>><?= htmlspecialchars($typeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="party_id">طرف حساب</label>
            <select id="party_id" name="party_id" required>
                <option value="">انتخاب...</option>
                <?php foreach ($billingTargets as $target):
                    $tType = $roleToType[$target['role']] ?? 'doctor';
                ?>
                    <option value="<?= (int) $target['id'] ?>" data-type="<?= $tType ?>" <?= ($selectedType === $tType && $selectedParty === (int) $target['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($target['name']) ?> — <?= htmlspecialchars($partyLabels[$tType] ?? '') ?>
                    </option>
                <?php endforeach; ?>
                <?php foreach ($branches as $br):
                    if ($myBranchId !== null && (int) $br['id'] === (int) $myBranchId) continue;
                ?>
                    <option value="<?= (int) $br['id'] ?>" data-type="branch" <?= ($selectedType === 'branch' && $selectedParty === (int) $br['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($br['name']) ?> — شعبه همکار
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="invoice-group" style="display:none;">
            <label for="invoice_ids" id="invoice-label">انتخاب فاکتورها</label>
            <select id="invoice_ids" name="invoice_ids[]" size="6"></select>
            <div id="invoice-hint" style="font-size:0.8rem; color:#6b7280; margin-top:6px;"></div>
        </div>

        <label for="amount">مبلغ دریافتی (تومان)</label>
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
        <input type="text" id="payment_date" name="payment_date" value="<?= htmlspecialchars(!empty($payment['payment_date']) ? toJalaliDateFormatted($payment['payment_date']) : toJalaliDateFormatted(date('Y-m-d'))) ?>" autocomplete="off" required>
        
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
<script>
(function ($) {
    $(function () {
        var $type      = $('#party_type');
        var $party     = $('#party_id');
        var $group     = $('#invoice-group');
        var $inv       = $('#invoice_ids');
        var $invLabel  = $('#invoice-label');
        var $hint      = $('#invoice-hint');
        var $amount    = $('#amount');

        var paymentId        = <?= (int) ($editing ? $payment['id'] : 0) ?>;
        var presetReceivable = <?= (int) $presetReceivable ?>;
        var firstLoad        = true;

        // فقط گزینه‌های طرف‌حسابِ همان نوع را نشان بده
        function filterParties() {
            var type = $type.val();
            var shown = 0;
            $party.find('option').each(function () {
                var $o = $(this);
                if (!$o.val()) return;                 // «انتخاب...»
                var ok = $o.attr('data-type') === type;
                $o.prop('hidden', !ok).prop('disabled', !ok);
                if (ok) shown++;
            });
            var $cur = $party.find('option:selected');
            if ($cur.length && $cur.val() && $cur.attr('data-type') !== type) {
                $party.val('');
            }
            if (!shown) $party.val('');
            $inv.empty();
            $hint.text('');
            $group.hide();
        }

        function selectedRemaining() {
            var sum = 0;
            $inv.find('option:selected').each(function () {
                sum += parseFloat($(this).attr('data-remaining')) || 0;
            });
            return Math.round(sum);
        }

        function updateAmount() {
            var sum = selectedRemaining();
            if (sum > 0) $amount.val(sum);
        }

        // فاکتورهای همان طرف‌حساب را از سرور بگیر
        function loadInvoices() {
            var type  = $type.val();
            var party = $party.val();
            if (!party) {
                $inv.empty();
                $group.hide();
                return;
            }
            var url = 'payment_party_invoices.php?party_type=' + encodeURIComponent(type)
                    + '&party_id=' + encodeURIComponent(party);
            if (firstLoad && paymentId) url += '&payment_id=' + paymentId;

            $hint.text('در حال بارگذاری فاکتورها...');
            $inv.prop('disabled', true).empty();

            $.getJSON(url).done(function (res) {
                $inv.prop('multiple', !!(res && res.multiple));
                var list = (res && res.invoices) ? res.invoices : [];
                list.forEach(function (iv) {
                    var $o = $('<option>')
                        .val(iv.id)
                        .attr('data-remaining', iv.remaining)
                        .text(iv.label);
                    if (iv.selected) $o.prop('selected', true);
                    $inv.append($o);
                });
                $invLabel.text(type === 'branch'
                    ? 'فاکتور طلب از شعبه (یک فاکتور)'
                    : 'انتخاب فاکتورها — فقط فاکتورهای دارای مانده');
                $hint.text(list.length
                    ? 'مبلغ به‌صورت خودکار از جمعِ ماندهٔ فاکتورهای انتخاب‌شده پر می‌شود.'
                    : (res && res.message ? res.message : ''));
                $inv.prop('disabled', !list.length);
                $group.show();

                if (presetReceivable) {
                    $inv.val(String(presetReceivable));
                    presetReceivable = 0;
                }
                // در ویرایش، مبلغِ ذخیره‌شده دست‌نخورده بماند
                if (!(firstLoad && paymentId)) updateAmount();
                firstLoad = false;
            }).fail(function () {
                $inv.prop('disabled', false);
                $hint.text('دریافت فهرست فاکتورها ناموفق بود؛ مبلغ را دستی وارد کنید.');
                $group.show();
                firstLoad = false;
            });
        }

        $type.on('change', function () {
            filterParties();
            loadInvoices();
        });
        $party.on('change', function () {
            firstLoad = false;
            loadInvoices();
        });
        $inv.on('change', updateAmount);

        filterParties();
        if ($party.val()) loadInvoices();
    });
})(jQuery);
</script>
<?php panel_layout_end();
