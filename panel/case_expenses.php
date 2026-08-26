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

$ceScope = branchCaseScope('c');

// Expenses are borne by the branch that OWNS the case (branch_id). An inbound
// shared case (source_branch_id = ours) is NOT our expense — the owning branch
// pays for design/outsourcing. So for a branch-scoped user only include cases
// owned by the current branch (this also removes "we owe ourselves" rows when
// the partner lab belongs to our own branch).
$myBranch = currentBranchId();
$ceOwnerFilter = $myBranch === null ? '' : ' AND c.branch_id = ' . (int) $myBranch;

// 1) Design-fee rows
$stmt = db()->prepare("
    SELECT c.id AS case_id, c.patient_name, c.received_date, u.full_name AS doctor_name,
           'طراحی' AS exp_type, des.full_name AS party_name, p.title AS service_title,
           c.quantity AS qty, ROUND(c.design_fee / NULLIF(c.quantity,0)) AS unit_rate, c.design_fee AS amount,
           (c.designer_invoice_id IS NOT NULL) AS invoiced
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN users des ON c.designer_id = des.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    WHERE c.designer_id IS NOT NULL AND c.design_fee > 0 AND {$ceScope['sql']}{$ceOwnerFilter}
");
$stmt->execute($ceScope['params']);
foreach ($stmt->fetchAll() as $r) $rows[] = $r;

// 2) Side-outsourcing rows (uses per-case outsourced_rate when set, else the outsource_rates lookup)
$stmt = db()->prepare("
    SELECT c.id AS case_id, c.patient_name, c.received_date, u.full_name AS doctor_name,
           'برون‌سپاری جانبی' AS exp_type, olab.full_name AS party_name, os.title AS service_title,
           c.outsourced_qty AS qty,
           COALESCE(c.outsourced_rate, r.rate, 0) AS unit_rate,
           c.outsourced_qty * COALESCE(c.outsourced_rate, r.rate, 0) AS amount,
           (c.outsource_invoice_id IS NOT NULL) AS invoiced
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN users olab ON c.outsourced_lab_id = olab.id
    LEFT JOIN site_prices os ON c.outsourced_service_id = os.id
    LEFT JOIN outsource_rates r ON r.lab_id = c.outsourced_lab_id AND r.service_id = c.outsourced_service_id
    WHERE c.outsourced_lab_id IS NOT NULL AND c.outsourced_qty > 0 AND {$ceScope['sql']}{$ceOwnerFilter}
");
$stmt->execute($ceScope['params']);
foreach ($stmt->fetchAll() as $r) $rows[] = $r;

// 3) Fully-outsourced (lab_out) rows
$stmt = db()->prepare("
    SELECT c.id AS case_id, c.patient_name, c.received_date, u.full_name AS doctor_name,
           'برون‌سپاری کامل' AS exp_type, lab.full_name AS party_name, p.title AS service_title,
           c.quantity AS qty, COALESCE(r.rate,0) AS unit_rate, c.quantity * COALESCE(r.rate,0) AS amount,
           (c.outsource_invoice_id IS NOT NULL) AS invoiced
    FROM cases c
    LEFT JOIN users u ON c.doctor_id = u.id
    LEFT JOIN users lab ON c.lab_id = lab.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    LEFT JOIN outsource_rates r ON r.lab_id = c.lab_id AND r.service_id = c.service_id
    WHERE c.case_type = 'lab_out' AND {$ceScope['sql']}{$ceOwnerFilter}
");
$stmt->execute($ceScope['params']);
foreach ($stmt->fetchAll() as $r) $rows[] = $r;

// Sort by received date (newest first), then case id
usort($rows, function ($a, $b) {
    $cmp = strcmp($b['received_date'], $a['received_date']);
    if ($cmp !== 0) return $cmp;
    return (int) $b['case_id'] - (int) $a['case_id'];
});

$totalAmount = array_sum(array_map(function ($r) { return (float) $r['amount']; }, $rows));
$uninvoicedAmount = array_sum(array_map(function ($r) { return ((int) $r['invoiced'] === 0) ? (float) $r['amount'] : 0; }, $rows));

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
<?php panel_layout_end(); ?>
