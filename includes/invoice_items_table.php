<?php
// includes/invoice_items_table.php
//
// «لایهٔ ۳» — جدولِ مشترکِ آیتم‌های فاکتور.
//
// چرا؟ قبلاً هر صفحهٔ فاکتور جدول آیتم خودش را داشت و قابلیت‌هایی مثل
// «انتخاب همه» یا «افزودن کیس از ماه‌های دیگر» فقط در بعضی از آن‌ها بود.
// این کامپوننت یک‌بار آن‌ها را می‌سازد تا همهٔ انواع فاکتور رفتار یکسان داشته باشند.
//
// استفاده:
//   renderInvoiceItemsEditor($rows, $ctx);
// که $ctx شامل:
//   mode            : 'edit' | 'add'      (edit = ویرایش تعداد/نرخ، add = انتخاب از کیس‌ها)
//   form_action     : URL فرم (برای mode=add)
//   invoice_id      : int
//   show_remove     : bool                ستون حذف (پیش‌فرض true در edit)
//   empty_message   : string
//   orphan_case_ids : array<int,bool>     (فقط mode=edit)
//   datatable       : bool                فعال‌سازی DataTables (پیش‌فرض: فقط در mode=add)
//
// نکته دربارهٔ DataTables:
//   حالت «افزودن» از DataTables استفاده می‌کند (جستجو/مرتب‌سازی/صفحه‌بندی) چون
//   ممکن است ده‌ها کیس واجد شرایط باشد.
//   حالت «ویرایش» داخل یک <form> است و ورودی دارد؛ صفحه‌بندی/جستجو در فرم باعث
//   می‌شود ردیف‌های صفحه‌های دیگر ذخیره نشوند و مبلغ کل غلط شود. پس آن یکی با
//   data-dt-plain و بدون صفحه‌بندی/مرتب‌سازی رندر می‌شود.

if (!function_exists('renderInvoiceItemsEditor')) {
    /**
     * جدول آیتم‌های فاکتور.
     * @param array $rows ردیف‌ها (در mode=add: کیس‌های قابل افزودن)
     * @param array $ctx
     */
    function renderInvoiceItemsEditor(array $rows, array $ctx = []): void
    {
        $mode         = $ctx['mode'] ?? 'edit';
        $formAction   = $ctx['form_action'] ?? '';
        $invoiceId    = (int) ($ctx['invoice_id'] ?? 0);
        $showRemove   = $ctx['show_remove'] ?? ($mode === 'edit');
        $emptyMsg     = $ctx['empty_message'] ?? 'آیتمی وجود ندارد.';
        $orphanIds    = $ctx['orphan_case_ids'] ?? [];
        $tableId      = $ctx['table_id'] ?? ('invoice-items-' . $mode);
        $isAdd        = ($mode === 'add');

        if (empty($rows)) {
            echo '<p class="empty" style="margin:0;">' . htmlspecialchars($emptyMsg) . '</p>';
            return;
        }

        $wrapOpen  = $isAdd ? '<form method="post" action="' . htmlspecialchars($formAction) . '">' : '';
        $wrapClose = $isAdd ? '</form>' : '';
        echo $wrapOpen;
        if ($isAdd && function_exists('csrf_field')) {
            echo csrf_field();
            echo '<input type="hidden" name="id" value="' . $invoiceId . '">';
        }
        ?>
        <?php
        // در حالت افزودن، DataTables فعال است (جستجو/مرتب‌سازی/صفحه‌بندی).
        // در حالت ویرایش، جدول داخل فرم است و نباید صفحه‌بندی شود؛ پس
        // datatable غیرفعال و فقط با کلاس ظاهری display رندر می‌شود.
        $useDataTable = $ctx['datatable'] ?? $isAdd;
        $tableClasses = 'invoice-rows-table' . ($useDataTable ? ' datatable display' : '')
                      . (!$useDataTable && !$isAdd ? ' display' : '');
        $tableAttrs = '';
        if ($useDataTable) {
            // ستون‌هایی که مرتب‌سازی‌شان بی‌معنی است: چک‌باکس‌ها و ستون‌های متنی
            $noSort = $isAdd ? '0,3,4' : '0,1,2,3';
            $tableAttrs = ' data-dt-nosort="' . $noSort . '"';
        } else {
            // بدون صفحه‌بندی/جستجو/مرتب‌سازی (فرم با ورودی نباید صفحه‌بندی شود)
            $tableAttrs = ' data-dt-plain';
        }
        ?>
        <div class="table-scroll">
        <table id="<?= htmlspecialchars($tableId) ?>"
               class="<?= $tableClasses ?>"<?= $tableAttrs ?>>
            <thead>
            <tr>
                <?php if ($isAdd): ?>
                <th class="col-pick" style="text-align:center;">
                    <input type="checkbox" class="js-pick-all" title="انتخاب همه" aria-label="انتخاب همه">
                </th>
                <?php endif; ?>
                <th class="col-case"># کیس</th>
                <th class="col-date">تاریخ</th>
                <th class="col-patient">بیمار</th>
                <th class="col-doctor">پزشک</th>
                <th class="col-service">خدمت</th>
                <th class="col-qty">تعداد</th>
                <th class="col-rate">نرخ (تومان)</th>
                <?php if (!$isAdd): ?>
                <th class="col-amount">مبلغ</th>
                <?php endif; ?>
                <?php if ($showRemove): ?>
                <th class="col-remove" style="text-align:center;">حذف</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                if ($isAdd) {
                    $caseId   = (int) ($r['id'] ?? 0);
                    $qty      = (int) ($r['_bill_qty'] ?? 1);
                    $rate     = (float) ($r['unit_rate'] ?? 0);
                    $amt      = (float) ($r['_bill_amount'] ?? 0);
                    $svcTitle = $r['_bill_service_title'] ?? ($r['service_title'] ?? null);
                    $rowId    = $caseId;
                    $date     = $r['received_date'] ?? null;
                } else {
                    $rowId    = (int) ($r['id'] ?? 0);
                    $caseId   = (int) ($r['case_id'] ?? 0);
                    $qty      = (int) ($r['quantity'] ?? 1);
                    $rate     = (float) ($r['unit_rate'] ?? 0);
                    $amt      = (float) ($r['total_amount'] ?? 0);
                    $svcTitle = $r['service_title'] ?? null;
                    $date     = $r['received_date'] ?? null;
                }
                $isOrphan    = (!$isAdd) && ($caseId <= 0 || isset($orphanIds[$caseId]));
                $rowBg = $isOrphan ? 'background:#fffbeb;' : '';
                ?>
            <tr data-row="<?= $rowId ?>" class="js-item-row" style="<?= $rowBg ?>">
                <?php if ($isAdd): ?>
                <td class="col-pick" style="text-align:center;">
                    <input type="checkbox" class="js-pick-case" name="case_ids[]" value="<?= $caseId ?>">
                </td>
                <?php endif; ?>
                <td class="col-case">
                    <?php if ($isOrphan): ?>
                        <span style="color:#b45309;" title="این ردیف به کیسی وصل نیست (کیس حذف شده). نرخ آن حفظ می‌شود."><?= $caseId ?: '—' ?> ⚠️</span>
                    <?php elseif ($caseId > 0): ?>
                        <a href="view_case.php?id=<?= $caseId ?>" target="_blank" style="color:#0369a1;"><?= $caseId ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="col-date">
                    <?= $date ? (function_exists('toJalaliDateFormatted') ? toJalaliDateFormatted($date) : $date) : '—' ?>
                </td>
                <td class="col-patient"><?= htmlspecialchars((string) ($r['patient_name'] ?? '—')) ?></td>
                <td class="col-doctor"><?= htmlspecialchars((string) ($r['doctor_name'] ?? '—')) ?></td>
                <td class="col-service"><?= htmlspecialchars((string) ($svcTitle ?? '—')) ?></td>
                <?php if ($isAdd): ?>
                <td class="col-qty"><?= $qty ?></td>
                <td class="col-rate">
                    <?= $rate > 0 ? formatAmountToman($rate) : '<span style="color:#b45309;" title="نرخی ثبت نشده؛ با نرخ صفر اضافه می‌شود.">— ⚠️</span>' ?>
                </td>
                <?php else: ?>
                <td class="col-qty">
                    <input type="number" class="row-qty" name="items[<?= $rowId ?>][qty]" value="<?= $qty ?>" min="1" step="1">
                </td>
                <td class="col-rate">
                    <input type="number" class="row-unit" name="items[<?= $rowId ?>][unit]" value="<?= round($rate) ?>" min="0" step="1">
                </td>
                <td class="col-amount"><?= formatAmountToman($amt) ?></td>
                <?php endif; ?>
                <?php if ($showRemove): ?>
                <td class="col-remove" style="text-align:center;">
                    <input type="checkbox" class="row-remove" name="items[<?= $rowId ?>][remove]" value="1" title="حذف این ردیف">
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($isAdd): ?>
        <div style="margin-top:14px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <button type="submit" class="btn" style="background:#059669; color:#fff;">افزودن به فاکتور</button>
            <span class="js-pick-count" style="color:#525252; font-size:0.9rem;">۰ مورد انتخاب شده</span>
            <span class="js-pick-total" style="color:#0f172a; font-size:0.9rem; font-weight:bold;"></span>
        </div>
        <?php endif; ?>
        <?php
        echo $wrapClose;
    }
}

if (!function_exists('invoiceItemsEditorScript')) {
    /**
     * اسکریپت مشترک جدول آیتم‌های فاکتور:
     *   - «انتخاب همه» + شمارش و جمعِ ردیف‌های انتخاب‌شده (حالت افزودن)
     *   - محو کردن ردیفِ تیک‌خورده (حالت ویرایش)
     *
     * نکته: اگر جدول DataTable شده باشد (کلاس datatable)، ردیف‌ها با صفحه‌بندی
     * و جستجو جابه‌جا می‌شوند؛ پس به‌جای اتصالِ یک‌بارهٔ listener به هر چک‌باکس،
     * از event delegation روی document استفاده می‌کنیم تا بعد از هر redraw هم
     * کار کند. همچنین شمارش/جمع از روی همهٔ چک‌باکس‌های DOM (نه فقط صفحهٔ جاری)
     * محاسبه می‌شود.
     */
    function invoiceItemsEditorScript(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<script>
(function () {
    function faDigits(s) {
        return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }
    function fmt(n) {
        return faDigits(String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
    }

    // مبلغِ هر ردیف: از ستون «مبلغ» (class) بخوان، نه از آخرین ستون —
    // چون در جدول افزودن، آخرین ستون «مبلغ» نیست در حالت ویرایش.
    function rowAmount(tr) {
        var cell = tr.querySelector('td.col-amount') || tr.querySelector('td.col-rate');
        if (!cell) return 0;
        var txt = (cell.textContent || '').replace(/[^\d۰-۹]/g, '');
        txt = txt.replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); });
        return Number(txt) || 0;
    }

    function refreshTable(table) {
        var boxes = table.querySelectorAll('.js-pick-case');
        var pickAll = table.querySelector('.js-pick-all');
        var host = table.closest('form') || document;
        var countEl = host.querySelector('.js-pick-count');
        var totalEl = host.querySelector('.js-pick-total');

        var n = 0, sum = 0;
        Array.prototype.forEach.call(boxes, function (b) {
            if (!b.checked) return;
            n++;
            var tr = b.closest('tr');
            if (tr) sum += rowAmount(tr);
        });

        if (countEl) countEl.textContent = faDigits(n) + ' مورد انتخاب شده';
        if (totalEl) totalEl.textContent = n ? 'جمع: ' + fmt(sum) + ' تومان' : '';
        if (pickAll) {
            pickAll.checked = n > 0 && n === boxes.length;
            pickAll.indeterminate = n > 0 && n < boxes.length;
        }
    }

    // «انتخاب همه» باید فقط چک‌باکس‌های همان جدول را تغییر دهد.
    // توجه: در جدول DataTable، این هدر در صفحهٔ جاری است و ردیف‌های فیلترشده
    // دیده نمی‌شوند؛ پس انتخاب همه روی همان چیزی که کاربر می‌بیند اثر می‌گذارد.
    document.addEventListener('change', function (e) {
        var t = e.target;

        if (t.classList && t.classList.contains('js-pick-all')) {
            var table = t.closest('table');
            if (!table) return;
            Array.prototype.forEach.call(table.querySelectorAll('.js-pick-case'), function (b) {
                b.checked = t.checked;
            });
            refreshTable(table);
            return;
        }

        if (t.classList && t.classList.contains('js-pick-case')) {
            var tbl = t.closest('table');
            if (tbl) refreshTable(tbl);
            return;
        }

        if (t.classList && t.classList.contains('row-remove')) {
            var tr = t.closest('tr');
            if (tr) tr.style.opacity = t.checked ? '0.4' : '1';
        }
    });

    // جلوگیری از سابمیت فرم با کلیک روی چک‌باکس «انتخاب همه»
    document.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('js-pick-all')) {
            e.stopPropagation();
        }
    });

    // مقدار اولیه پس از آماده‌شدن DataTable
    function initAll() {
        document.querySelectorAll('table.invoice-rows-table').forEach(function (table) {
            if (table.querySelector('.js-pick-case')) refreshTable(table);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    // بعد از صفحه‌بندی/جستجوی DataTable هم دوباره حساب کن
    if (window.jQuery) {
        jQuery(document).on('draw.dt search.dt order.dt', function (e, settings) {
            var node = settings && settings.nTable ? settings.nTable : null;
            if (node && node.querySelector && node.querySelector('.js-pick-case')) refreshTable(node);
        });
    }
})();
</script>
        <?php
    }
}

if (!function_exists('invoiceItemsMonthPart')) {
    /**
     * عبارتِ ماه‌های شمسی از تاریخِ دریافت خودِ ردیف‌های فاکتور.
     *
     * این همان روشی است که در فاکتور پزشک به کار رفته (panel/invoice_pdf.php):
     * بازه در دیتابیس ذخیره نمی‌شود، بلکه از تاریخِ کیس‌ها «استخراج» می‌گردد.
     * پس اگر بعداً کیسی از ماه دیگری به فاکتور اضافه شود، عنوان و متن فاکتور
     * خودبه‌خود ماهِ جدید را هم نشان می‌دهد — بدون ستون اضافه و بدون مهاجرت.
     *
     * خروجی نمونه:
     *   «مرداد ۱۴۰۵»
     *   «مرداد و شهریور ۱۴۰۵»
     *   «اسفند ۱۴۰۴ و فروردین ۱۴۰۵»   (سال‌ها متفاوت → سال کنار هر ماه)
     *
     * @param array $items ردیف‌های فاکتور (کلید received_date)
     * @param string|null $fallbackDate تاریخِ فاکتور، اگر هیچ کیسی تاریخ نداشت
     * @return string
     */
    function invoiceItemsMonthPart(array $items, ?string $fallbackDate = null): string
    {
        $monthNames = [
            'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
            'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
        ];

        $pairs = [];
        foreach ($items as $it) {
            $d = $it['received_date'] ?? null;
            if ($d === null || $d === '') {
                continue;
            }
            try {
                $j = \Morilog\Jalali\Jalalian::fromDateTime($d);
                $pairs[$j->getYear() * 100 + $j->getMonth()] = [$j->getYear(), $j->getMonth()];
            } catch (\Throwable $e) {
                // تاریخ نامعتبر را نادیده بگیر
            }
        }
        ksort($pairs);
        $pairs = array_values($pairs);

        // اگر هیچ کیسی تاریخِ دریافت نداشت، ماهِ فاکتور را بنویس.
        if (empty($pairs) && $fallbackDate !== null && $fallbackDate !== '') {
            try {
                $j = \Morilog\Jalali\Jalalian::fromDateTime($fallbackDate);
                $pairs = [[$j->getYear(), $j->getMonth()]];
            } catch (\Throwable $e) {
                return '';
            }
        }
        if (empty($pairs)) {
            return '';
        }

        $years = array_unique(array_column($pairs, 0));
        $sameYear = count($years) === 1;
        $total = count($pairs);
        $part = '';
        foreach ($pairs as $i => [$y, $m]) {
            if ($i > 0) {
                $part .= ($i === $total - 1) ? ' و ' : '، ';
            }
            $part .= $monthNames[$m - 1];
            if (!$sameYear) {
                $part .= ' ' . toPersianDigits((string) $y);
            }
        }
        if ($sameYear && $total === 1) {
            $part .= ' ' . toPersianDigits((string) reset($years));
        }
        return $part;
    }
}

if (!function_exists('jalaliMonthKeyToName')) {
    /** «۱۴۰۵/۰۶» → «شهریور ۱۴۰۵» */
    function jalaliMonthKeyToName(string $key): string
    {
        $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور',
                   'مهر','آبان','آذر','دی','بهمن','اسفند'];
        $parts = preg_split('#[/-]#', $key);
        if (count($parts) < 2) {
            return $key;
        }
        $y = (int) str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
                               ['0','1','2','3','4','5','6','7','8','9'], $parts[0]);
        $m = (int) str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
                               ['0','1','2','3','4','5','6','7','8','9'], $parts[1]);
        if ($m >= 1 && $m <= 12) {
            return $months[$m - 1] . ' ' . $parts[0];
        }
        return $key;
    }
}
