<?php
// panel\invoice_form.php

require_once __DIR__ . '/auth.php';
require_admin();
$editing = !empty($_GET['id']);
$invoice = $editing ? getInvoice((int) $_GET['id']) : null;
$billingTargets = getAllBillingTargets();
$prices = getAllPrices();
$invoiceItems = $invoice ? getInvoiceItems($invoice['id']) : [];
if ($editing && !$invoice) {
    header('Location: invoices.php?error=notfound');
    exit;
}

panel_layout_start($editing ? 'ویرایش فاکتور' : 'ایجاد فاکتور جدید');
?>
<form method="post" action="save_invoice.php">
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= $invoice['id'] ?>">
    <?php endif; ?>

    <div class="form-card">
        <label for="invoice_number">شماره فاکتور</label>
        <input type="text" id="invoice_number" name="invoice_number" value="<?= htmlspecialchars($invoice['invoice_number'] ?? '') ?>" required>

        <label for="doctor_id">انتخاب پزشک/طراح/کلینیک</label>
        <select id="doctor_id" name="doctor_id">
            <option value="">انتخاب...</option>
            <?php foreach ($billingTargets as $target): ?>
                <option value="<?= $target['id'] ?>" <?= ($invoice['doctor_id'] ?? '') == $target['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($target['name']) ?> (<?= htmlspecialchars($target['role'] === 'clinic' ? 'کلینیک' : ($target['role'] === 'designer' ? 'طراح' : 'پزشک')) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <label for="doctor_name">نام طرف حساب (در صورت نداشتن حساب ثبت‌شده)</label>
        <input type="text" id="doctor_name" name="doctor_name" value="<?= htmlspecialchars($invoice['doctor_name'] ?? '') ?>">

        <label for="doctor_phone">تلفن دکتر</label>
        <input type="tel" id="doctor_phone" name="doctor_phone" value="<?= htmlspecialchars($invoice['doctor_phone'] ?? '') ?>">

        <label for="doctor_email">ایمیل دکتر</label>
        <input type="email" id="doctor_email" name="doctor_email" value="<?= htmlspecialchars($invoice['doctor_email'] ?? '') ?>">

        <label>آیتم‌های فاکتور</label>
        <div class="table-scroll">
        <table class="invoice-items-table" style="width:100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th>نوع</th>
                    <th>شرح</th>
                    <th>نام بیمار</th>
                    <th>فی</th>
                    <th>تعداد</th>
                    <th>جمع</th>
                    <th title="عملیات">⚙</th>
                </tr>
            </thead>
            <tbody id="invoice-items" data-items='<?= json_encode(array_map(function ($item) {
                // Map DB fields to the format expected by addCaseRow() in invoice-items.js
                $unitPrice = round((float) $item['unit_price']);
                $quantity = (int) ($item['quantity'] ?? 1);
                return [
                    'id' => $item['case_id'],
                    'case_id' => $item['case_id'],
                    'price_id' => $item['price_id'],
                    'service_id' => $item['price_id'],
                    'item_title' => $item['item_title'],
                    'service_title' => $item['item_title'],
                    'item_description' => $item['item_description'],
                    'patient_name' => $item['patient_name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $quantity,
                    'total_amount' => round((float) $item['total_amount']),
                ];
            }, $invoiceItems), JSON_UNESCAPED_UNICODE) ?>'>
            </tbody>
        </table>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <button type="button" id="add-invoice-item" class="btn" style="background: #0F172A; color: #fff;">افزودن آیتم جدید</button>
            <button type="button" id="add-cases-from-doctor" class="btn" style="background: #06B6D4; color: #fff;">افزودن از کیس‌های دکتر</button>
            <button type="button" id="add-discount-item" class="btn" style="background: #f59e0b; color: #fff;">افزودن تخفیف</button>
        </div>

        <?php
        // تعداد هر خدمت به تفکیک (نمایش اولیه؛ با JS هم زنده به‌روز می‌شود)
        $serviceSummary = [];
        foreach ($invoiceItems as $item) {
            $svc = trim((string) ($item['item_title'] ?: $item['price_title'] ?: ''));
            $qty = (int) ($item['quantity'] ?? 0);
            if ($svc === '' || $qty <= 0) continue;
            $serviceSummary[$svc] = ($serviceSummary[$svc] ?? 0) + $qty;
        }
        ?>
        <div id="service-summary" style="<?= empty($serviceSummary) ? 'display:none;' : '' ?> margin-top:12px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
            <strong style="color:#0F172A; font-size:0.9rem;">تعداد خدمات به تفکیک:</strong>
            <div id="service-summary-body" style="margin-top:7px; display:flex; gap:6px 12px; flex-wrap:wrap;">
                <?php foreach ($serviceSummary as $svc => $qty): ?>
                    <span style="background:#eef2ff; padding:3px 10px; border-radius:20px; white-space:nowrap; font-size:0.82rem;"><?= htmlspecialchars($svc) ?>: <b style="color:#1d4ed8;"><?= toPersianDigits($qty) ?></b></span>
                <?php endforeach; ?>
            </div>
        </div>

        <label for="total_amount">مبلغ کل (تومان)</label>
        <input type="number" id="total_amount" name="total_amount" value="<?= (int) round($invoice['total_amount'] ?? 0) ?>" readonly>

        <label for="bank_account_id">حساب بانکی (برای درج در فاکتور)</label>
        <select id="bank_account_id" name="bank_account_id">
            <option value="">بدون حساب بانکی</option>
            <?php foreach (getAllBankAccounts() as $acc): ?>
                <option value="<?= $acc['id'] ?>" <?= ($invoice['bank_account_id'] ?? 0) == $acc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($acc['bank_name'] . ' - ' . $acc['account_owner_name']) ?>
                    <?= $acc['card_number'] ? ' - کارت: ' . htmlspecialchars($acc['card_number']) : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <small style="display:block; margin-bottom:12px;">این حساب در فاکتور PDF چاپ خواهد شد.</small>

        <label for="invoice_date">تاریخ فاکتور</label>
        <div style="display:flex; gap:8px; align-items:center;">
            <input type="text" id="invoice_date" name="invoice_date" value="<?= htmlspecialchars(toJalaliDateFormatted($invoice['invoice_date'] ?? date('Y-m-d'))) ?>" placeholder="۱۴۰۳/۰۱/۰۱" required>
            <button type="button" id="set_invoice_today" class="btn" style="background:#06B6D4; color:#fff;">امروز</button>
        </div>
        <small>تاریخ را به صورت هجری شمسی (سال/ماه/روز) وارد کنید.</small>

        <label for="due_date">تاریخ سررسید</label>
        <div style="display:flex; gap:8px; align-items:center;">
            <input type="text" id="due_date" name="due_date" value="<?= htmlspecialchars(($invoice['due_date'] ?? '') ? toJalaliDateFormatted($invoice['due_date']) : '') ?>" placeholder="۱۴۰۳/۰۱/۰۱">
            <button type="button" id="set_due_today" class="btn" style="background:#0F172A; color:#fff;">امروز</button>
        </div>
        <small>اگر نیاز به سررسید دارید، تاریخ را به صورت هجری شمسی وارد کنید.</small>

        <label for="notes">یادداشت‌ها</label>
        <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($invoice['notes'] ?? '') ?></textarea>

        <script id="price-data" type="application/json"><?= json_encode(array_map(function ($price) {
            return ['id' => $price['id'], 'title' => $price['title'], 'price' => $price['price']];
        }, $prices), JSON_UNESCAPED_UNICODE) ?></script>
        <link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
        <script src="../assets/js/persian-date.min.js"></script>
        <script src="../assets/js/persian-datepicker.min.js"></script>
        <script src="../assets/js/invoice-items.js"></script>
        <!-- DEBUG: invoice items count=<?= count($invoiceItems) ?> -->
        <?php if (!empty($invoiceItems)): ?>
        <!-- DEBUG: first item patient_name=<?= htmlspecialchars($invoiceItems[0]['patient_name'] ?? 'NULL') ?> qty=<?= $invoiceItems[0]['quantity'] ?? 'NULL' ?> case_id=<?= $invoiceItems[0]['case_id'] ?? 'NULL' ?> -->
        <?php endif; ?>

        <script>
            // Restore default bank account from sessionStorage
            (function(){
                var saved = sessionStorage.getItem('default_bank_account_id');
                if (saved) {
                    var sel = document.getElementById('bank_account_id');
                    if (sel && !<?= $editing ? 'true' : 'false' ?>) {
                        sel.value = saved;
                    }
                }
            })();
            (function(){
                var todayJalali = '<?= toJalaliDateFormatted(date('Y-m-d')) ?>';
                document.getElementById('set_invoice_today')?.addEventListener('click', function(){
                    document.getElementById('invoice_date').value = todayJalali;
                });
                document.getElementById('set_due_today')?.addEventListener('click', function(){
                    document.getElementById('due_date').value = todayJalali;
                });

                function initJalaliPicker(selector) {
                    if (window.jQuery && typeof jQuery.fn.persianDatepicker === 'function') {
                        jQuery(selector).persianDatepicker({
                            format: 'YYYY/MM/DD',
                            calendarType: 'persian',
                            initialValueType: 'jalali',
                            persianDigit: true,
                            autoClose: true,
                            toolbox: {
                                enabled: true,
                                todayButton: {
                                    enabled: true,
                                    text: {
                                        fa: 'امروز',
                                        en: 'Today'
                                    }
                                },
                                submitButton: {
                                    enabled: true,
                                    text: {
                                        fa: 'تایید',
                                        en: 'Submit'
                                    }
                                },
                                calendarSwitch: {
                                    enabled: true,
                                    format: 'MMMM'
                                }
                            }
                        });
                    }
                }

                function initPickers() {
                    initJalaliPicker('#invoice_date');
                    initJalaliPicker('#due_date');
                }

                if (window.jQuery && typeof jQuery.fn.persianDatepicker === 'function') {
                    initPickers();
                } else {
                    var checkLoaded = setInterval(function(){
                        if (window.jQuery && typeof jQuery.fn.persianDatepicker === 'function') {
                            clearInterval(checkLoaded);
                            initPickers();
                        }
                    }, 100);
                    setTimeout(function(){ clearInterval(checkLoaded); }, 5000);
                }
            })();
        </script>

        <button type="submit" class="btn" style="background: #06B6D4; margin-top: 20px;">ذخیره</button>
        <a href="invoices.php" class="btn" style="background: #E5E7EB; color: #0F172A; margin-left: 10px;">انصراف</a>
    </div>
</form>
<?php panel_layout_end();
