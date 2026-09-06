<?php
// panel/case_expenses.php
// Expenses per case (کیس‌های مخارج): lists, for every case, the payable line items
// that WE owe to others:
//   - طراحی (design fee to the designer)
//   - برون‌سپاری جانبی (side-outsourcing – part of the work done by another lab)
//   - برون‌سپاری کامل (fully outsourced lab_out cases)
// Each row shows the party (designer/lab), service, quantity, unit rate and amount,
// plus whether it has already been included in a payable invoice. Filterable/searchable.
require_once __DIR__ . '/auth.php';
require_login();
if (!is_admin()) {
    die('دسترسی غیرمجاز');
}

$rows = [];

// ── چه کسی هر هزینه را می‌پردازد؟ (هر شعبه فقط مخارجِ خودش را می‌بیند) ──
// مدیر کل (role=admin) به‌عنوان شعبه‌ی اصلی/مرکزی (۱) دیده می‌شود؛ هر شعبه فقط
// ردیف‌هایی را می‌بیند که خودش باید آن را بپردازد:
//   • برون‌سپاری (کامل/جانبی) → صاحب کیس (branch_id) می‌پردازد.
//   • هزینه‌ی طراحی → شعبه‌ای که لابراتوارِ انجام‌دهنده‌ی کار (lab_id) به آن تعلق دارد
//     می‌پردازد؛ اگر لابراتوار به شعبه‌ای تعلق نداشت، صاحب کیس می‌پردازد. یعنی کیس‌هایی که
//     لابراتوار مرکزی انجامشان می‌دهد (حتی اگر مالِ شعبه‌ی دیگر باشند) هزینه‌ی طراحی‌شان
//     در مخارج شعبه‌ی مرکزی می‌آید، نه در مخارج شعبه‌ی مالک.
$effBranch = currentBranchId() ?? 1;

// ردیف‌های برون‌سپاری: پرداخت‌کننده = صاحب کیس
$ceOwnerFilter = ' AND c.branch_id = ' . (int) $effBranch;
// ردیف‌های طراحی: پرداخت‌کننده = شعبه‌ی لابراتوارِ انجام‌دهنده (وگرنه صاحب کیس)
$designPayerSql = "COALESCE((SELECT lb.branch_id FROM users lb WHERE lb.id = c.lab_id), c.branch_id)";
$ceDesignFilter = ' AND ' . $designPayerSql . ' = ' . (int) $effBranch;

// 1) Design-fee rows
$stmt = db()->query("
    SELECT c.id AS case_id, c.patient_name, c.received_date, u.full_name AS doctor_name,
           'طراحی' AS exp_type, des.full_name AS party_name, p.title AS service_title,
           c.quantity AS qty, ROUND(c.design_fee / NULLIF(c.quantity,0)) AS unit_rate, c.design_fee AS amount,
           (c.designer_invoice_id IS NOT NULL) AS invoiced
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN users des ON c.designer_id = des.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    WHERE c.designer_id IS NOT NULL AND c.design_fee > 0 {$ceDesignFilter}
");
foreach ($stmt->fetchAll() as $r) $rows[] = $r;

// 2) Side-outsourcing rows (uses per-case outsourced_rate when set, else the outsource_rates lookup)
$plExprSide = priceLinkOutsourceSqlExpr('c.outsourced_lab_id', 'c.outsourced_service_id');
$plUnit = $plExprSide ? "COALESCE(c.outsourced_rate, COALESCE($plExprSide, r.rate), 0)" : "COALESCE(c.outsourced_rate, r.rate, 0)";
$stmt = db()->query("
    SELECT c.id AS case_id, c.patient_name, c.received_date, u.full_name AS doctor_name,
           'برون‌سپاری جانبی' AS exp_type, olab.full_name AS party_name, os.title AS service_title,
           c.outsourced_qty AS qty,
           $plUnit AS unit_rate,
           c.outsourced_qty * $plUnit AS amount,
           (c.outsource_invoice_id IS NOT NULL) AS invoiced
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN users olab ON c.outsourced_lab_id = olab.id
    LEFT JOIN site_prices os ON c.outsourced_service_id = os.id
    LEFT JOIN outsource_rates r ON r.lab_id = c.outsourced_lab_id AND r.service_id = c.outsourced_service_id
    WHERE c.outsourced_lab_id IS NOT NULL AND c.outsourced_qty > 0
      AND c.case_type <> 'lab_out'   -- کیسِ کاملاً برون‌سپاری‌شده نباید دوباره به‌عنوان «جانبی» حساب شود
      {$ceOwnerFilter}
");
foreach ($stmt->fetchAll() as $r) $rows[] = $r;

// 3) Fully-outsourced (lab_out) rows
$plExprFull = priceLinkOutsourceSqlExpr('c.lab_id', 'c.service_id');
$plUnitFull = $plExprFull ? "COALESCE($plExprFull, r.rate, 0)" : "COALESCE(r.rate,0)";
$stmt = db()->query("
    SELECT c.id AS case_id, c.patient_name, c.received_date, u.full_name AS doctor_name,
           'برون‌سپاری کامل' AS exp_type, lab.full_name AS party_name, p.title AS service_title,
           c.quantity AS qty, $plUnitFull AS unit_rate, c.quantity * $plUnitFull AS amount,
           (c.outsource_invoice_id IS NOT NULL) AS invoiced
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN users lab ON c.lab_id = lab.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    LEFT JOIN outsource_rates r ON r.lab_id = c.lab_id AND r.service_id = c.service_id
    WHERE c.case_type = 'lab_out' {$ceOwnerFilter}
");
foreach ($stmt->fetchAll() as $r) $rows[] = $r;

// Sort by received date (newest first), then case id
usort($rows, function ($a, $b) {
    $cmp = strcmp($b['received_date'], $a['received_date']);
    if ($cmp !== 0) return $cmp;
    return (int) $b['case_id'] - (int) $a['case_id'];
});

$totalAmount = array_sum(array_map(function ($r) { return (float) $r['amount']; }, $rows));
$uninvoicedAmount = array_sum(array_map(function ($r) { return ((int) $r['invoiced'] === 0) ? (float) $r['amount'] : 0; }, $rows));

// Inter-branch DEBT: receivable invoices that OTHER branches issued to US
// (partner_branch_id = ours). This is the inverse of branch_receivables.php —
// each branch can see here what it owes to partner branches.
$debtInvoices = [];
$totalDebt = 0;
$unpaidDebt = 0;
if ($effBranch) {
    $stmt = db()->prepare("SELECT r.*, b.name AS issuer_branch_name
        FROM branch_receivables r
        LEFT JOIN branches b ON r.branch_id = b.id
        WHERE r.partner_branch_id = ?
        ORDER BY r.invoice_date DESC, r.id DESC");
    $stmt->execute([(int) $effBranch]);
    $debtInvoices = $stmt->fetchAll();
    foreach ($debtInvoices as $inv) {
        $paid = getBranchReceivablePaid((int) $inv['id']);
        $totalDebt += (float) $inv['total_amount'];
        $unpaidDebt += (float) $inv['total_amount'] - $paid;
    }
}

$dataJson = json_encode(array_map(function ($r) {
    return [
        toJalaliDateFormatted($r['received_date']),   // jalali display (sortable string)
        (int) $r['case_id'],
        $r['patient_name'] ?: '—',
        $r['doctor_name'] ?: '—',
        $r['exp_type'],
        $r['party_name'] ?: '—',
        $r['service_title'] ?: '—',
        (int) $r['qty'],
        (float) $r['unit_rate'],
        (float) $r['amount'],
        (int) $r['invoiced'],
    ];
}, $rows), JSON_UNESCAPED_UNICODE);

panel_layout_start('کیس‌های مخارج (بدهی‌ها)');
?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="expenses.php" style="background:#b91c1c; color:#fff;">فاکتورهای مخارج</a>
        <a class="btn" href="financial_overview.php" style="background:#0F172A; color:#fff;">بررسی درآمد و هزینه</a>
        <a class="btn" href="cases.php" style="background:#E5E7EB; color:#0F172A;">کیس‌ها</a>
    </div>
</div>

<div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:18px;">
    <div style="flex:1; min-width:200px; background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:14px 18px;">
        <div style="font-size:0.85rem; color:#9a3412;">جمع کل مخارج (تمام آیتم‌ها)</div>
        <div style="font-size:1.3rem; font-weight:bold; color:#c2410c; margin-top:4px;"><?= formatAmountToman($totalAmount) ?> <small style="font-size:0.75rem;">تومان</small></div>
    </div>
    <div style="flex:1; min-width:200px; background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:14px 18px;">
        <div style="font-size:0.85rem; color:#991b1b;">مخارج فاکتورنشده (بدهی جاری)</div>
        <div style="font-size:1.3rem; font-weight:bold; color:#b91c1c; margin-top:4px;"><?= formatAmountToman($uninvoicedAmount) ?> <small style="font-size:0.75rem;">تومان</small></div>
    </div>
    <?php if ($effBranch): ?>
    <div style="flex:1; min-width:200px; background:#fefce8; border:1px solid #fde68a; border-radius:12px; padding:14px 18px;">
        <div style="font-size:0.85rem; color:#854d0e;">جمع بدهی به شعب دیگر (فاکتور طلب صادرشده توسط آن‌ها)</div>
        <div style="font-size:1.3rem; font-weight:bold; color:#a16207; margin-top:4px;"><?= formatAmountToman($totalDebt) ?> <small style="font-size:0.75rem;">تومان</small></div>
    </div>
    <div style="flex:1; min-width:200px; background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:14px 18px;">
        <div style="font-size:0.85rem; color:#991b1b;">بدهی پرداخت‌نشده به شعب دیگر</div>
        <div style="font-size:1.3rem; font-weight:bold; color:#b91c1c; margin-top:4px;"><?= formatAmountToman($unpaidDebt) ?> <small style="font-size:0.75rem;">تومان</small></div>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
    <p class="empty">هیچ مورد مخارجی (طراحی یا برون‌سپاری) روی کیس‌ها ثبت نشده است.</p>
<?php else: ?>
<table id="case-expenses-table" class="display" style="width:100%">
    <thead>
    <tr>
        <th>تاریخ</th>
        <th>کیس</th>
        <th>بیمار</th>
        <th>پزشک</th>
        <th>نوع هزینه</th>
        <th>طراح / لابراتوار</th>
        <th>خدمت</th>
        <th>تعداد</th>
        <th>نرخ واحد</th>
        <th>مبلغ</th>
        <th>وضعیت فاکتور</th>
    </tr>
    </thead>
</table>
<script>
(function(){
    if (!(window.jQuery && typeof jQuery.fn.DataTable === 'function')) return;
    var data = <?= $dataJson ?>;
    function faDigits(s){ return String(s).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
    function toman(n){ n = Math.round(Number(n) || 0); return faDigits(n.toLocaleString('en-US')); }
    var table = jQuery('#case-expenses-table').DataTable({
        data: data,
        pageLength: 25,
        order: [[0, 'desc']],
        layout: { top1: 'searchPanes' },
        searchPanes: {
            layout: 'columns-3',
            columns: [
                { column: 4, header: 'نوع هزینه' },
                { column: 5, header: 'طراح / لابراتوار' },
                { column: 6, header: 'خدمت' }
            ]
        },
        columns: [
            { },
            { render: function(data, type, row){ if (type === 'display') { return '<a href="view_case.php?id=' + data + '" target="_blank">#' + data + '</a>'; } return data; } },
            { },
            { },
            { },
            { },
            { },
            { render: function(data, type){ return type === 'sort' ? data : faDigits(data); } },
            { render: function(data, type){ return type === 'sort' ? data : toman(data); } },
            { render: function(data, type){ return type === 'sort' ? data : '<strong style="color:#b91c1c;">' + toman(data) + '</strong>'; } },
            { render: function(data){ return data ? '<span class="badge" style="background:#dcfce7; color:#166534;">فاکتور شده</span>' : '<span class="badge" style="background:#fee2e2; color:#991b1b;">فاکتور نشده</span>'; } }
        ],
        language: {
            search: "جستجو:",
            lengthMenu: "نمایش _MENU_ در هر صفحه",
            info: "نمایش _START_ تا _END_ از _TOTAL_ مورد",
            infoEmpty: "هیچ موردی یافت نشد",
            infoFiltered: "(فیلتر شده از _MAX_ مورد)",
            loadingRecords: "در حال بارگذاری...",
            zeroRecords: "موردی یافت نشد",
            emptyTable: "داده‌ای موجود نیست",
            paginate: { first: "اول", previous: "قبلی", next: "بعدی", last: "آخر" },
            aria: { sortAscending: ": مرتب‌سازی صعودی", sortDescending: ": مرتب‌سازی نزولی" }
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($effBranch): ?>
<div class="form-card" style="margin-top:26px;">
    <h4>بدهی به شعب دیگر (فاکتورهای طلب صادرشده توسط شعب دیگر به ما)</h4>
    <p style="font-size:0.85rem; color:#525252;">برعکس صفحه «فاکتورهای طلب از شعبه‌ها»: این‌ها فاکتورهایی هستند که شعبهٔ دیگر برای کارهایی که ما از آن‌ها گرفتیم (یا برای ما انجام داده‌اند) به نام ما صادر کرده است. برای جزئیات هر فاکتور روی PDF بزنید.</p>
    <?php if (empty($debtInvoices)): ?>
        <p class="empty">بدهی ثبت‌شده‌ای به شعب دیگر وجود ندارد.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="display" style="width:100%">
        <thead>
        <tr>
            <th>شماره</th>
            <th>شعبه صادرکننده</th>
            <th>بازه</th>
            <th>تاریخ</th>
            <th>مبلغ</th>
            <th>پرداخت</th>
            <th>وضعیت</th>
            <th>عملیات</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($debtInvoices as $inv): ?>
            <?php
            $paid = getBranchReceivablePaid((int) $inv['id']);
            $st = $inv['payment_status'] ?? 'unpaid';
            ?>
            <tr>
                <td style="font-weight:bold;"><?= htmlspecialchars($inv['invoice_number']) ?></td>
                <td><?= htmlspecialchars($inv['issuer_branch_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inv['period_label'] ?? '—') ?></td>
                <td><?= $inv['invoice_date'] ? toJalaliDateFormatted($inv['invoice_date']) : '—' ?></td>
                <td style="font-weight:bold; color:#b91c1c;"><?= formatAmountToman($inv['total_amount']) ?></td>
                <td><?= formatAmountToman($paid) ?> <small style="color:#525252;">از <?= formatAmountToman($inv['total_amount']) ?></small></td>
                <td>
                    <?php if ($st === 'paid'): ?>
                        <span class="badge" style="background:#dcfce7; color:#166534;">تسویه شده</span>
                    <?php elseif ($st === 'partial'): ?>
                        <span class="badge" style="background:#fef3c7; color:#92400e;">جزئی</span>
                    <?php else: ?>
                        <span class="badge" style="background:#fee2e2; color:#991b1b;">پرداخت نشده</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="btn" href="branch_receivable_pdf.php?id=<?= (int) $inv['id'] ?>" target="_blank" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">PDF</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php panel_layout_end(); ?>
