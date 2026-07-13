<?php
// panel\invoice_form.php

require_once __DIR__ . '/auth.php';
require_role('admin');
$editing = !empty($_GET['id']);
$invoice = $editing ? getInvoice((int) $_GET['id']) : null;
$doctors = getAllDoctors();
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

        <label for="doctor_id">انتخاب دکتر</label>
        <select id="doctor_id" name="doctor_id">
            <option value="">انتخاب دکتر...</option>
            <?php foreach ($doctors as $doctor): ?>
                <option value="<?= $doctor['id'] ?>" <?= ($invoice['doctor_id'] ?? '') == $doctor['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($doctor['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="doctor_name">نام دکتر (در صورت نداشتن دکتر ثبت‌شده)</label>
        <input type="text" id="doctor_name" name="doctor_name" value="<?= htmlspecialchars($invoice['doctor_name'] ?? '') ?>">

        <label for="doctor_phone">تلفن دکتر</label>
        <input type="tel" id="doctor_phone" name="doctor_phone" value="<?= htmlspecialchars($invoice['doctor_phone'] ?? '') ?>">

        <label for="doctor_email">ایمیل دکتر</label>
        <input type="email" id="doctor_email" name="doctor_email" value="<?= htmlspecialchars($invoice['doctor_email'] ?? '') ?>">

        <label>آیتم‌های فاکتور</label>
        <table class="invoice-items-table" style="width:100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th>انتخاب</th>
                    <th>شرح</th>
                    <th>نام بیمار</th>
                    <th style="width: 90px;">تعداد</th>
                    <th>فی (تومان)</th>
                    <th>جمع</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="invoice-items" data-items='<?= json_encode(array_map(function ($item) {
                return [
                    'price_id' => $item['price_id'],
                    'case_id' => $item['case_id'] ?? null,
                    'item_title' => $item['item_title'],
                    'item_description' => $item['item_description'],
                    'patient_name' => $item['patient_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => round((float) $item['unit_price'])
                ];
            }, $invoiceItems), JSON_UNESCAPED_UNICODE) ?>'>
            </tbody>
        </table>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <button type="button" id="add-invoice-item" class="btn" style="background: #0F172A; color: #fff;">افزودن آیتم جدید</button>
            <button type="button" id="add-cases-from-doctor" class="btn" style="background: #06B6D4; color: #fff;">افزودن از کیس‌های دکتر</button>
            <button type="button" id="add-discount-item" class="btn" style="background: #f59e0b; color: #fff;">افزودن تخفیف</button>
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
            <input type="text" id="due_date" name="due_date" value="<?= htmlspecialchars($invoice['due_date'] ? toJalaliDateFormatted($invoice['due_date']) : '') ?>" placeholder="۱۴۰۳/۰۱/۰۱">
            <button type="button" id="set_due_today" class="btn" style="background:#0F172A; color:#fff;">امروز</button>
        </div>
        <small>اگر نیاز به سررسید دارید، تاریخ را به صورت هجری شمسی وارد کنید.</small>

        <label for="notes">یادداشت‌ها</label>
        <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($invoice['notes'] ?? '') ?></textarea>

        <script id="price-data" type="application/json"><?= json_encode(array_map(function ($price) {
            return ['id' => $price['id'], 'title' => $price['title'], 'price' => $price['price']];
        }, $prices), JSON_UNESCAPED_UNICODE) ?></script>
        <link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
        <script src="../assets/js/jquery-3.6.0.min.js"></script>
        <script src="../assets/js/persian-date.min.js"></script>
        <script src="../assets/js/persian-datepicker.min.js"></script>
        <script src="../assets/js/invoice-items.js"></script>

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
