<?php
// panel/financial_overview.php
// Income vs Expense (درآمد در برابر هزینه) status page with charts.
require_once __DIR__ . '/auth.php';
require_login();
if (!is_admin()) {
    die('دسترسی غیرمجاز');
}

use Morilog\Jalali\Jalalian;

// Branch scoping: a branch admin sees only their branch's financials.
// مدیر کل (نقش admin) نیز به‌عنوان شعبهٔ مرکزی (۱) رفتار می‌کند.
$bid = currentBranchId();
if ($bid === null) $bid = 1;
$finInvoiceFilter = $bid === null ? '' : ' AND branch_id = ' . (int) $bid;   // doctor_invoices / designer_invoices / outsource_invoices
// فاکتورهای پزشک: شعبه = branch_id، یا (برای فاکتورهای قدیمی بدون شعبه) شعبهٔ خودِ پزشک.
$finDocInvoiceFilter = $bid === null ? '' : " AND COALESCE(doctor_invoices.branch_id, (SELECT u.branch_id FROM users u WHERE u.id = doctor_invoices.doctor_id)) = " . (int) $bid;
$finCaseFilter    = $bid === null ? '' : ' AND c.branch_id = ' . (int) $bid;  // cases (alias c)
$finCaseFilterNoAlias = $bid === null ? '' : ' AND branch_id = ' . (int) $bid; // cases (no alias)

// Inbound cross-branch amount = what a partner branch owes us for a case it
// outsourced to us (source_branch_id = ours). Mirrors their outsource expense.
// Phase 3: prefers the unified price_links rate, falls back to outsource_rates.
$plInbound = priceLinkOutsourceSqlExpr("IF(c.case_type = 'lab_out', c.lab_id, c.outsourced_lab_id)", "IF(c.case_type = 'lab_out', c.service_id, c.outsourced_service_id)");
$inboundLegacy = "(SELECT r.rate FROM outsource_rates r\n      WHERE r.lab_id = IF(c.case_type = 'lab_out', c.lab_id, c.outsourced_lab_id)\n        AND r.service_id = IF(c.case_type = 'lab_out', c.service_id, c.outsourced_service_id)\n      ORDER BY (r.branch_id = c.branch_id) DESC, (r.branch_id IS NULL) DESC LIMIT 1)";
$inboundRate = $plInbound !== null
    ? "COALESCE(c.outsourced_rate, COALESCE($plInbound, $inboundLegacy), 0)"
    : "COALESCE(c.outsourced_rate, $inboundLegacy, 0)";
$inboundAmountSql = $inboundRate . " * IF(c.case_type = 'lab_out', c.quantity, c.outsourced_qty)";

// Unrealized income: owned cases count their total_price; inbound shared cases
// (branch_id = the partner's) count only the outsource amount owed to us.
// Unrealized expense: only OWNED cases bear design/outsource costs.
// Phase 3: rate prefers price_links; also counts FULL lab_out cases (an outsource
// obligation to the receiving lab), which the old query missed.
$plSide = priceLinkOutsourceSqlExpr('c.outsourced_lab_id', 'c.outsourced_service_id');
$sideLegacy = "(SELECT r.rate FROM outsource_rates r WHERE r.lab_id = c.outsourced_lab_id AND r.service_id = c.outsourced_service_id)";
$sideRate = $plSide !== null
    ? "COALESCE(c.outsourced_rate, COALESCE($plSide, $sideLegacy), 0)"
    : "COALESCE(c.outsourced_rate, $sideLegacy, 0)";
$plFull = priceLinkOutsourceSqlExpr('c.lab_id', 'c.service_id');
$fullLegacy = "(SELECT r.rate FROM outsource_rates r WHERE r.lab_id = c.lab_id AND r.service_id = c.service_id)";
$fullRate = $plFull !== null
    ? "COALESCE($plFull, $fullLegacy, 0)"
    : "COALESCE($fullLegacy, 0)";
$outAmountExpr = "CASE
            WHEN c.outsourced_lab_id IS NOT NULL AND c.outsourced_qty > 0 AND c.case_type <> 'lab_out' THEN c.outsourced_qty * {$sideRate}
            WHEN c.case_type = 'lab_out' THEN c.quantity * {$fullRate}
            ELSE 0 END";
// پرداخت‌کننده‌ی هزینه‌ی طراحیِ هر کیس = شعبه‌ی لابراتوارِ انجام‌دهنده (وگرنه صاحب کیس).
// کیس‌هایی که لابراتوار مرکزی انجامشان می‌دهد (حتی مالِ شعب دیگر) → پرداخت‌کننده = مرکزی.
$designPayerSql = "COALESCE((SELECT lb.branch_id FROM users lb WHERE lb.id = c.lab_id), c.branch_id)";
if ($bid === null) {
    $unrealizedIncomeSql = 'SELECT COALESCE(SUM(total_price),0) FROM cases WHERE received_date BETWEEN ? AND ?';
    $unrealizedExpenseSql = "SELECT COALESCE(SUM(c.design_fee),0) + COALESCE(SUM({$outAmountExpr}),0)\n        FROM cases c WHERE c.received_date BETWEEN ? AND ?";
} else {
    $b = (int) $bid;
    $unrealizedIncomeSql = "SELECT COALESCE(SUM(\n            CASE WHEN c.source_branch_id = $b AND (c.branch_id IS NULL OR c.branch_id <> $b)\n                 THEN $inboundAmountSql\n                 ELSE c.total_price END),0)\n        FROM cases c\n        WHERE c.received_date BETWEEN ? AND ? AND (c.branch_id = $b OR c.source_branch_id = $b)";
    // هزینه‌ی تحقق‌نیافته = طراحی که «این شعبه» پرداخت‌کننده‌اش است + برون‌سپاریِ کیس‌هایِ مالِ این شعبه
    $unrealizedExpenseSql = "SELECT\n            COALESCE(SUM(CASE WHEN ({$designPayerSql} = $b) THEN c.design_fee ELSE 0 END),0)\n          + COALESCE(SUM(CASE WHEN (c.branch_id = $b) THEN ({$outAmountExpr}) ELSE 0 END),0)\n        FROM cases c WHERE c.received_date BETWEEN ? AND ?";
}

$jalaliMonths = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
];

$nowJalali = Jalalian::now();
$defaultYear = $nowJalali->getYear();
$defaultMonth = $nowJalali->getMonth();

// Period type + range
$periodType = isset($_GET['period_type']) ? (string) $_GET['period_type'] : 'monthly';
if (!in_array($periodType, ['monthly', 'custom'], true)) $periodType = 'monthly';
$year = isset($_GET['year']) ? (int) $_GET['year'] : $defaultYear;
$month = isset($_GET['month']) ? (int) $_GET['month'] : $defaultMonth;
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$startDate = '';
$endDate = '';
$periodLabel = '';
if ($periodType === 'custom' && $dateFrom !== '' && $dateTo !== '') {
    $startDate = parseJalaliToGregorian($dateFrom);
    $endDate = parseJalaliToGregorian($dateTo);
    if ($startDate !== '' && $endDate !== '' && $startDate <= $endDate) {
        $periodLabel = toJalaliDateFormatted($startDate) . ' تا ' . toJalaliDateFormatted($endDate);
    }
}
if ($periodLabel === '' && $year >= 1300 && $year <= 1500) {
    try {
        if ($month >= 1 && $month <= 12) {
            // Specific month
            $jalaliStart = Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/01', $year, $month));
            $startDate = $jalaliStart->toCarbon()->toDateString();
            $endDate = $jalaliStart->addMonths(1)->subDay()->toCarbon()->toDateString();
            $periodLabel = $jalaliMonths[$month] . ' ' . $year;
        } else {
            // Whole jalali year
            $jalaliStart = Jalalian::fromFormat('Y/m/d', sprintf('%04d/01/01', $year));
            $startDate = $jalaliStart->toCarbon()->toDateString();
            $endDate = $jalaliStart->addMonths(12)->subDay()->toCarbon()->toDateString();
            $periodLabel = 'سال ' . $year;
        }
    } catch (\Exception $e) {}
}

// ─── Period figures: realized (paid/issued) vs unrealized (based on received cases) ───
$realizedIncome = 0;      // paid invoices (پول دریافت شده)
$incomeByType = ['regular' => 0, 'clinic' => 0, 'lab' => 0];
$realizedExpense = 0;     // issued payable invoices – designer + outsource
$expenseByType = ['designer' => 0, 'outsource' => 0];
$unrealizedIncome = 0;    // sum of total_price of cases received in the period
$unrealizedExpense = 0;   // design fees + side-outsourcing cost on received cases

if ($startDate !== '' && $endDate !== '') {
    // Realized income = paid invoices
    $stmt = db()->prepare("SELECT invoice_number, total_amount FROM doctor_invoices WHERE invoice_date BETWEEN ? AND ? AND payment_status = 'paid'" . $finDocInvoiceFilter);
    $stmt->execute([$startDate, $endDate]);
    foreach ($stmt->fetchAll() as $r) {
        $amt = (float) $r['total_amount'];
        $realizedIncome += $amt;
        if (str_starts_with($r['invoice_number'], 'INV-CLN')) $incomeByType['clinic'] += $amt;
        elseif (str_starts_with($r['invoice_number'], 'INV-LAB')) $incomeByType['lab'] += $amt;
        else $incomeByType['regular'] += $amt;
    }

    // Realized expense = issued payable invoices (designer + outsource)
    $stmt = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM designer_invoices WHERE invoice_date BETWEEN ? AND ?" . $finInvoiceFilter);
    $stmt->execute([$startDate, $endDate]);
    $expenseByType['designer'] = (float) $stmt->fetchColumn();
    $stmt = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM outsource_invoices WHERE invoice_date BETWEEN ? AND ?" . $finInvoiceFilter);
    $stmt->execute([$startDate, $endDate]);
    $expenseByType['outsource'] = (float) $stmt->fetchColumn();
    $realizedExpense = $expenseByType['designer'] + $expenseByType['outsource'];

    // Unrealized income = sum of total_price of cases received in the period
    // Unrealized income: owned cases total_price + inbound partner receivable
    $stmt = db()->prepare($unrealizedIncomeSql);
    $stmt->execute([$startDate, $endDate]);
    $unrealizedIncome = (float) $stmt->fetchColumn();

    // Unrealized expense = design fees + side-outsourcing cost on received cases
    // Uses the per-case outsourced_rate when set; otherwise falls back to the outsource_rates lookup.
    $stmt = db()->prepare($unrealizedExpenseSql);
    $stmt->execute([$startDate, $endDate]);
    $unrealizedExpense = (float) $stmt->fetchColumn();
}

// Realized income from paid inter-branch receivable invoices (طلب از شعبه همکار)
$stmt = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM branch_receivables WHERE invoice_date BETWEEN ? AND ? AND payment_status = 'paid'" . $finInvoiceFilter);
$stmt->execute([$startDate, $endDate]);
$branchReceivableIncome = (float) $stmt->fetchColumn();
$realizedIncome += $branchReceivableIncome;
$incomeByType['branch'] = $branchReceivableIncome;

$realizedNet = $realizedIncome - $realizedExpense;
$unrealizedNet = $unrealizedIncome - $unrealizedExpense;

// ─── Last 12 jalali months chart data (4 series) ───
$months = [];
$cur = Jalalian::now();
for ($i = 11; $i >= 0; $i--) {
    $m = ($i === 0) ? $cur : $cur->subMonths($i);
    $key = $m->getYear() . '-' . str_pad((string) $m->getMonth(), 2, '0', STR_PAD_LEFT);
    $months[$key] = [
        'label' => $jalaliMonths[$m->getMonth()] . ' ' . $m->getYear(),
        'year' => $m->getYear(),
        'month' => $m->getMonth(),
        'inc_real' => 0,
        'inc_unreal' => 0,
        'exp_real' => 0,
        'exp_unreal' => 0,
    ];
}
foreach ($months as $key => &$mm) {
    $ms = Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/01', $mm['year'], $mm['month']))->toCarbon()->toDateString();
    $me = Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/01', $mm['year'], $mm['month']))->addMonths(1)->subDay()->toCarbon()->toDateString();

    $s = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM doctor_invoices WHERE invoice_date BETWEEN ? AND ? AND payment_status = 'paid'" . $finDocInvoiceFilter);
    $s->execute([$ms, $me]);
    $mm['inc_real'] = (float) $s->fetchColumn();

    $s = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM branch_receivables WHERE invoice_date BETWEEN ? AND ? AND payment_status = 'paid'" . $finInvoiceFilter);
    $s->execute([$ms, $me]);
    $mm['inc_real'] += (float) $s->fetchColumn();

    $s = db()->prepare($unrealizedIncomeSql);
    $s->execute([$ms, $me]);
    $mm['inc_unreal'] = (float) $s->fetchColumn();

    $s = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM designer_invoices WHERE invoice_date BETWEEN ? AND ?" . $finInvoiceFilter);
    $s->execute([$ms, $me]);
    $d = (float) $s->fetchColumn();
    $s = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM outsource_invoices WHERE invoice_date BETWEEN ? AND ?" . $finInvoiceFilter);
    $s->execute([$ms, $me]);
    $o = (float) $s->fetchColumn();
    $mm['exp_real'] = $d + $o;

    $s = db()->prepare($unrealizedExpenseSql);
    $s->execute([$ms, $me]);
    $mm['exp_unreal'] = (float) $s->fetchColumn();
}
unset($mm);
$months = array_values($months);

$maxChart = 1;
foreach ($months as $mm) {
    $maxChart = max($maxChart, $mm['inc_real'], $mm['inc_unreal'], $mm['exp_real'], $mm['exp_unreal']);
}

// ─── Grouped income & expense for the selected period (pie/donut tab) ───
// Grouped by doctor, service type (نوع کار), lab, and clinic.
$pieGroups = [];
if ($startDate !== '' && $endDate !== '') {
    $buildDonut = function (array $items, int $topN = 8): array {
        usort($items, function ($a, $b) { return $b['value'] <=> $a['value']; });
        $filtered = [];
        $other = 0;
        foreach ($items as $i => $it) {
            if ($i < $topN) { $filtered[] = $it; }
            else { $other += $it['value']; }
        }
        if ($other > 0) { $filtered[] = ['label' => 'سایر', 'value' => $other]; }
        $filtered = array_values(array_filter($filtered, function ($x) { return $x['value'] > 0; }));
        $total = array_sum(array_column($filtered, 'value'));
        if ($total <= 0) { return ['slices' => [], 'total' => 0]; }
        $palette = ['#22c55e', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#06b6d4', '#84cc16', '#a855f7'];
        $start = 0;
        $slices = [];
        foreach ($filtered as $i => $it) {
            $pct = ($it['value'] / $total) * 100;
            $color = $palette[$i % count($palette)];
            $slices[] = ['label' => $it['label'], 'value' => $it['value'], 'pct' => $pct, 'color' => $color, 'start' => $start];
            $start += $pct;
        }
        return ['slices' => $slices, 'total' => $total];
    };

    $groupings = [
        'doctor' => [
            'label' => 'پزشک',
            'select' => "u.full_name",
            'join' => "LEFT JOIN users u ON c.doctor_id = u.id",
        ],
        'service' => [
            'label' => 'نوع کار',
            'select' => "p.title",
            'join' => "LEFT JOIN site_prices p ON c.service_id = p.id",
        ],
        'lab' => [
            'label' => 'لابراتوار',
            'select' => "lab.full_name",
            'join' => "LEFT JOIN users lab ON c.lab_id = lab.id",
        ],
        'clinic' => [
            'label' => 'کلینیک',
            'select' => "cl.full_name",
            'join' => "LEFT JOIN users doc ON c.doctor_id = doc.id LEFT JOIN users cl ON doc.clinic_id = cl.id",
        ],
    ];

    // Branch-only dimensions: برون‌سپاری‌شده (what we owe others) and دریافتی
    // از شعب همکار (what partner branches owe us) – shown separately from the
    // owned-work donuts above.
    if ($bid !== null) {
        $groupings['inbound'] = ['label' => 'دریافتی از شعب همکار'];
        $groupings['outsourced'] = ['label' => 'برون‌سپاری‌شده'];
    }

    // در نمودارهای گروهی هم هزینه‌ی طراحی فقط برای کیس‌هایی شمرده می‌شود که این شعبه
    // پرداخت‌کننده‌ی طراحی‌شان باشد (برای مدیر کل/همه‌ی شعب = همه).
    $designFeeForGroup = ($bid === null)
        ? 'c.design_fee'
        : "CASE WHEN ({$designPayerSql} = " . (int) $bid . ") THEN c.design_fee ELSE 0 END";

    foreach ($groupings as $key => $g) {
        if ($key === 'inbound') {
            // Income from cases a partner branch outsourced to us (source = ours).
            $b = (int) $bid;
            $sql = "SELECT pb.name AS grp, SUM($inboundAmountSql) AS income
                    FROM cases c
                    LEFT JOIN branches pb ON c.branch_id = pb.id
                    WHERE c.received_date BETWEEN ? AND ?
                      AND c.source_branch_id = $b AND (c.branch_id IS NULL OR c.branch_id <> $b)
                    GROUP BY grp ORDER BY income DESC";
            $stmt = db()->prepare($sql);
            $stmt->execute([$startDate, $endDate]);
            $rows = $stmt->fetchAll();
            $incomeItems = array_map(function ($r) { return ['label' => $r['grp'] ?: 'نامشخص', 'value' => (float) $r['income']]; }, $rows);
            // Detail: work type x source branch (تعداد واحد دریافتی به تفکیک)
            $detailSql = "SELECT COALESCE(p.title, 'نامشخص') AS service,
                                 COALESCE(pb.name, 'نامشخص') AS src_branch,
                                 SUM(IF(c.case_type = 'lab_out', c.quantity, c.outsourced_qty)) AS qty,
                                 SUM($inboundAmountSql) AS amount
                          FROM cases c
                          LEFT JOIN site_prices p ON p.id = IF(c.case_type = 'lab_out', c.service_id, c.outsourced_service_id)
                          LEFT JOIN branches pb ON c.branch_id = pb.id
                          WHERE c.received_date BETWEEN ? AND ?
                            AND c.source_branch_id = $b AND (c.branch_id IS NULL OR c.branch_id <> $b)
                          GROUP BY p.title, pb.name
                          ORDER BY amount DESC";
            $stmt = db()->prepare($detailSql);
            $stmt->execute([$startDate, $endDate]);
            $detail = $stmt->fetchAll();
            $pieGroups[$key] = ['label' => $g['label'], 'income' => $buildDonut($incomeItems), 'expense' => [], 'detail' => $detail];
            continue;
        }
        if ($key === 'outsourced') {
            // Expense for cases we outsourced (side + full) to a lab / partner branch.
            $b = (int) $bid;
            // Per-case rate: the case's own rate, else price_links, else outsource_rates.
            $rateSub = $inboundRate;
            $qtyExpr = "IF(c.case_type = 'lab_out', c.quantity, c.outsourced_qty)";
            $sql = "SELECT COALESCE(olab.full_name, lab.full_name) AS grp,
                           SUM($qtyExpr * $rateSub) AS expense
                    FROM cases c
                    LEFT JOIN users olab ON c.outsourced_lab_id = olab.id
                    LEFT JOIN users lab ON c.lab_id = lab.id
                    WHERE c.received_date BETWEEN ? AND ?
                      AND c.branch_id = $b
                      AND (c.outsourced_lab_id IS NOT NULL OR c.case_type = 'lab_out')
                    GROUP BY grp ORDER BY expense DESC";
            $stmt = db()->prepare($sql);
            $stmt->execute([$startDate, $endDate]);
            $rows = $stmt->fetchAll();
            $expenseItems = array_map(function ($r) { return ['label' => $r['grp'] ?: 'نامشخص', 'value' => (float) $r['expense']]; }, $rows);
            // Detail: work type x destination lab (تعداد واحد برون‌سپاری‌شده به تفکیک)
            $detailSql = "SELECT COALESCE(p.title, 'نامشخص') AS service,
                                 COALESCE(olab.full_name, lab.full_name) AS dst_lab,
                                 SUM($qtyExpr) AS qty,
                                 SUM($qtyExpr * $rateSub) AS amount
                          FROM cases c
                          LEFT JOIN site_prices p ON p.id = IF(c.case_type = 'lab_out', c.service_id, c.outsourced_service_id)
                          LEFT JOIN users olab ON c.outsourced_lab_id = olab.id
                          LEFT JOIN users lab ON c.lab_id = lab.id
                          WHERE c.received_date BETWEEN ? AND ?
                            AND c.branch_id = $b
                            AND (c.outsourced_lab_id IS NOT NULL OR c.case_type = 'lab_out')
                          GROUP BY p.title, olab.full_name, lab.full_name
                          ORDER BY amount DESC";
            $stmt = db()->prepare($detailSql);
            $stmt->execute([$startDate, $endDate]);
            $detail = $stmt->fetchAll();
            $pieGroups[$key] = ['label' => $g['label'], 'income' => [], 'expense' => $buildDonut($expenseItems), 'detail' => $detail];
            continue;
        }
        $sql = "SELECT {$g['select']} AS grp,
                       SUM(c.total_price) AS income,
                       SUM({$designFeeForGroup} + COALESCE({$outAmountExpr}, 0)) AS expense
                FROM cases c
                {$g['join']}
                WHERE c.received_date BETWEEN ? AND ?" . $finCaseFilter . "
                GROUP BY grp
                ORDER BY income DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $rows = $stmt->fetchAll();
        $incomeItems = array_map(function ($r) { return ['label' => $r['grp'] ?: 'نامشخص', 'value' => (float) $r['income']]; }, $rows);
        $expenseItems = array_map(function ($r) { return ['label' => $r['grp'] ?: 'نامشخص', 'value' => (float) $r['expense']]; }, $rows);
        $pieGroups[$key] = [
            'label' => $g['label'],
            'income' => $buildDonut($incomeItems),
            'expense' => $buildDonut($expenseItems),
        ];
    }
}

// Helper to render a conic-gradient donut from built data
$renderDonut = function (array $donut): string {
    if (empty($donut['slices'])) {
        return '<p class="empty">داده‌ای برای این بازه وجود ندارد.</p>';
    }
    $grad = '';
    foreach ($donut['slices'] as $s) {
        $from = round($s['start'], 2);
        $to = round($s['start'] + $s['pct'], 2);
        $grad .= ($grad !== '' ? ', ' : '') . $s['color'] . ' ' . $from . '% ' . $to . '%';
    }
    $html = '<div style="display:flex; gap:24px; flex-wrap:wrap; align-items:center;">';
    $html .= '<div class="fin-donut" style="width:200px; height:200px; border-radius:50%; position:relative; background: conic-gradient(' . $grad . '); flex:0 0 auto;">';
    $html .= '<div style="position:absolute; inset:25%; background:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-direction:column; text-align:center; padding:8px; box-sizing:border-box;">';
    $html .= '<div style="font-size:0.7rem; color:#555;">جمع</div>';
    $html .= '<div style="font-weight:bold; font-size:0.85rem;">' . formatAmountToman($donut['total']) . '</div>';
    $html .= '<div style="font-size:0.6rem; color:#888;">تومان</div>';
    $html .= '</div></div>';
    $html .= '<div style="flex:1; min-width:220px;">';
    foreach ($donut['slices'] as $s) {
        $html .= '<div style="display:flex; align-items:center; gap:8px; padding:3px 0; font-size:0.85rem;">';
        $html .= '<span style="display:inline-block; width:12px; height:12px; background:' . $s['color'] . '; border-radius:2px; flex:0 0 auto;"></span>';
        $html .= '<span style="flex:1;">' . htmlspecialchars($s['label']) . '</span>';
        $html .= '<span style="font-weight:bold; white-space:nowrap;">' . formatAmountToman($s['value']) . '</span>';
        $html .= '<span style="color:#666; white-space:nowrap; min-width:34px; text-align:left;">' . round($s['pct']) . '٪</span>';
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
};

// Helper to render a detail table (نوع کار × مبدأ/مقصد) for the inbound /
// outsourced breakdowns under their donuts.
$renderDetailTable = function (array $rows, string $srcLabel, string $amountLabel): string {
    if (empty($rows)) return '';
    $html = '<div class="table-scroll" style="margin-top:12px;">';
    $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.85rem;">';
    $html .= '<thead><tr>';
    $html .= '<th style="text-align:right; padding:6px 8px; border-bottom:1px solid #ddd;">نوع کار</th>';
    $html .= '<th style="text-align:right; padding:6px 8px; border-bottom:1px solid #ddd;">' . htmlspecialchars($srcLabel) . '</th>';
    $html .= '<th style="text-align:center; padding:6px 8px; border-bottom:1px solid #ddd;">تعداد واحد</th>';
    $html .= '<th style="text-align:left; padding:6px 8px; border-bottom:1px solid #ddd;">' . htmlspecialchars($amountLabel) . '</th>';
    $html .= '</tr></thead><tbody>';
    $totQty = 0; $totAmt = 0;
    foreach ($rows as $row) {
        $qty = (float) ($row['qty'] ?? 0);
        $amt = (float) ($row['amount'] ?? 0);
        $totQty += $qty; $totAmt += $amt;
        $src = $row['src_branch'] ?? $row['dst_lab'] ?? '';
        $html .= '<tr>';
        $html .= '<td style="padding:6px 8px; border-bottom:1px solid #f3f4f6;">' . htmlspecialchars($row['service'] ?: 'نامشخص') . '</td>';
        $html .= '<td style="padding:6px 8px; border-bottom:1px solid #f3f4f6;">' . htmlspecialchars($src ?: 'نامشخص') . '</td>';
        $html .= '<td style="padding:6px 8px; border-bottom:1px solid #f3f4f6; text-align:center;">' . toPersianDigits(number_format($qty)) . '</td>';
        $html .= '<td style="padding:6px 8px; border-bottom:1px solid #f3f4f6; text-align:left; font-weight:bold;">' . formatAmountToman($amt) . '</td>';
        $html .= '</tr>';
    }
    $html .= '<tr style="font-weight:bold;">';
    $html .= '<td colspan="2" style="padding:6px 8px;">جمع</td>';
    $html .= '<td style="padding:6px 8px; text-align:center;">' . toPersianDigits(number_format($totQty)) . '</td>';
    $html .= '<td style="padding:6px 8px; text-align:left;">' . formatAmountToman($totAmt) . '</td>';
    $html .= '</tr></tbody></table></div>';
    return $html;
};

// ─── Service counts: چند کیس از هر خدمت در بازه انجام شده ───
$serviceCounts = [];
if ($startDate !== '' && $endDate !== '') {
    $stmt = db()->prepare("SELECT p.title AS service, COUNT(*) AS case_count, COALESCE(SUM(c.quantity),0) AS qty_sum
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        WHERE c.received_date BETWEEN ? AND ?" . $finCaseFilter . "
        GROUP BY c.service_id, p.title
        ORDER BY qty_sum DESC, case_count DESC");
    $stmt->execute([$startDate, $endDate]);
    $serviceCounts = $stmt->fetchAll();
}

panel_layout_start('بررسی وضعیت درآمد و هزینه');
?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="dashboard.php" style="background:#E5E7EB; color:#0F172A;">بازگشت به داشبورد</a>
        <a class="btn" href="expenses.php" style="background:#b91c1c; color:#fff;">فاکتورهای مخارج</a>
        <a class="btn" href="invoices.php" style="background:#0F172A; color:#fff;">فاکتورها</a>
    </div>
</div>

<form method="get" class="form-card" style="margin-bottom:20px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
    <div class="form-group" style="margin:0; min-width:120px;">
        <label for="period_type">نوع دوره</label>
        <select id="period_type" name="period_type" onchange="togglePeriodType()">
            <option value="monthly" <?= $periodType === 'monthly' ? 'selected' : '' ?>>ماهانه</option>
            <option value="custom" <?= $periodType === 'custom' ? 'selected' : '' ?>>بازه دلخواه</option>
        </select>
    </div>
    <div id="monthly-fields" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div class="form-group" style="margin:0; min-width:110px;">
            <label for="year">سال شمسی</label>
            <input type="number" id="year" name="year" value="<?= $year ?: $defaultYear ?>" min="1300" max="1500">
        </div>
        <div class="form-group" style="margin:0; min-width:140px;">
            <label for="month">ماه</label>
            <select id="month" name="month">
                <option value="">کل سال</option>
                <?php foreach ($jalaliMonths as $mNum => $mName): ?>
                    <option value="<?= $mNum ?>" <?= $month == $mNum ? 'selected' : '' ?>><?= $mName ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div id="custom-fields" style="display:none; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div class="form-group" style="margin:0; min-width:150px;">
            <label for="date_from">از تاریخ</label>
            <input type="text" id="date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;">
        </div>
        <div class="form-group" style="margin:0; min-width:150px;">
            <label for="date_to">تا تاریخ</label>
            <input type="text" id="date_to" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;">
        </div>
    </div>
    <button type="submit" class="btn" style="background:#0F172A; color:#fff;">نمایش</button>
    <a href="financial_overview.php" class="btn" style="background:#E5E7EB; color:#0F172A;">پاک کردن</a>
</form>

<!-- Tabs -->
<div style="display:flex; gap:0; margin-bottom:20px; border-bottom:2px solid #e5e7eb; flex-wrap:wrap;">
    <button type="button" id="fin-tab-overview" class="fin-tab" onclick="switchFinTab('overview')" style="background:#0F172A; color:#fff; border:none; padding:10px 20px; font-weight:700; cursor:pointer; border-radius:8px 8px 0 0;">خلاصه و نمودار ماهانه</button>
    <button type="button" id="fin-tab-cases" class="fin-tab" onclick="switchFinTab('cases')" style="background:#E5E7EB; color:#0F172A; border:none; padding:10px 20px; font-weight:700; cursor:pointer; border-radius:8px 8px 0 0;">بر اساس کیس (نمودار دایره‌ای)</button>
</div>

<div id="fin-pane-overview">
<?php if ($startDate !== '' && $endDate !== ''): ?>
<!-- Income cards -->
<div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:14px;">
    <div style="flex:1; min-width:200px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:16px 18px;">
        <div style="font-size:0.85rem; color:#166534;">درآمد تحقق‌یافته (<?= htmlspecialchars($periodLabel) ?>)</div>
        <div style="font-size:1.4rem; font-weight:bold; color:#15803d; margin-top:6px;"><?= formatAmountToman($realizedIncome) ?> <small style="font-size:0.75rem;">تومان</small></div>
        <small style="color:#525252;">فاکتور صادر شده و پول آن دریافت شده</small>
    </div>
    <div style="flex:1; min-width:200px; background:#ecfeff; border:1px solid #a5f3fc; border-radius:12px; padding:16px 18px;">
        <div style="font-size:0.85rem; color:#155e75;">درآمد تحقق‌نیافته</div>
        <div style="font-size:1.4rem; font-weight:bold; color:#0e7490; margin-top:6px;"><?= formatAmountToman($unrealizedIncome) ?> <small style="font-size:0.75rem;">تومان</small></div>
        <small style="color:#525252;">مجموع کیس‌های دریافت‌شده در این بازه (فارغ از صدور فاکتور)</small>
    </div>
</div>

<!-- Expense cards -->
<div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:14px;">
    <div style="flex:1; min-width:200px; background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:16px 18px;">
        <div style="font-size:0.85rem; color:#991b1b;">هزینه تحقق‌یافته</div>
        <div style="font-size:1.4rem; font-weight:bold; color:#b91c1c; margin-top:6px;"><?= formatAmountToman($realizedExpense) ?> <small style="font-size:0.75rem;">تومان</small></div>
        <small style="color:#525252;">فاکتورهای مخارج صادرشده (طراحی + برون‌سپاری)</small>
    </div>
    <div style="flex:1; min-width:200px; background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:16px 18px;">
        <div style="font-size:0.85rem; color:#9a3412;">هزینه تحقق‌نیافته</div>
        <div style="font-size:1.4rem; font-weight:bold; color:#c2410c; margin-top:6px;"><?= formatAmountToman($unrealizedExpense) ?> <small style="font-size:0.75rem;">تومان</small></div>
        <small style="color:#525252;">هزینه طراحی + برون‌سپاری جانبی کیس‌های دریافت‌شده (فارغ از صدور فاکتور/پرداخت)</small>
    </div>
</div>

<!-- Net cards -->
<div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:22px;">
    <div style="flex:1; min-width:200px; background:<?= $realizedNet >= 0 ? '#eff6ff' : '#fff7ed' ?>; border:1px solid <?= $realizedNet >= 0 ? '#bfdbfe' : '#fed7aa' ?>; border-radius:12px; padding:16px 18px;">
        <div style="font-size:0.85rem; color:<?= $realizedNet >= 0 ? '#1e40af' : '#9a3412' ?>;">سود خالص تحقق‌یافته (درآمد − هزینه)</div>
        <div style="font-size:1.4rem; font-weight:bold; color:<?= $realizedNet >= 0 ? '#1d4ed8' : '#c2410c' ?>; margin-top:6px;"><?= formatAmountToman($realizedNet) ?> <small style="font-size:0.75rem;">تومان</small></div>
    </div>
    <div style="flex:1; min-width:200px; background:<?= $unrealizedNet >= 0 ? '#eff6ff' : '#fff7ed' ?>; border:1px solid <?= $unrealizedNet >= 0 ? '#bfdbfe' : '#fed7aa' ?>; border-radius:12px; padding:16px 18px;">
        <div style="font-size:0.85rem; color:<?= $unrealizedNet >= 0 ? '#1e40af' : '#9a3412' ?>;">سود خالص تحقق‌نیافته (درآمد − هزینه)</div>
        <div style="font-size:1.4rem; font-weight:bold; color:<?= $unrealizedNet >= 0 ? '#1d4ed8' : '#c2410c' ?>; margin-top:6px;"><?= formatAmountToman($unrealizedNet) ?> <small style="font-size:0.75rem;">تومان</small></div>
    </div>
</div>

<div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:22px;">
    <!-- Realized income breakdown -->
    <div class="form-card" style="flex:1; min-width:260px; margin:0;">
        <h4>درآمد تحقق‌یافته بر اساس نوع فاکتور</h4>
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <tr><td style="padding:7px 0; border-bottom:1px solid #eee;">فاکتور عادی پزشکان</td><td style="padding:7px 0; border-bottom:1px solid #eee; text-align:left; font-weight:bold;"><?= formatAmountToman($incomeByType['regular']) ?></td></tr>
            <tr><td style="padding:7px 0; border-bottom:1px solid #eee;">فاکتور کلینیک</td><td style="padding:7px 0; border-bottom:1px solid #eee; text-align:left; font-weight:bold;"><?= formatAmountToman($incomeByType['clinic']) ?></td></tr>
            <tr><td style="padding:7px 0; border-bottom:1px solid #eee;">فاکتور لابراتوار</td><td style="padding:7px 0; border-bottom:1px solid #eee; text-align:left; font-weight:bold;"><?= formatAmountToman($incomeByType['lab']) ?></td></tr>
            <tr><td style="padding:7px 0; border-bottom:1px solid #eee;">فاکتور طلب از شعبه همکار</td><td style="padding:7px 0; border-bottom:1px solid #eee; text-align:left; font-weight:bold; color:#166534;"><?= formatAmountToman($incomeByType['branch'] ?? 0) ?></td></tr>
            <tr><td style="padding:7px 0;"><strong>جمع درآمد تحقق‌یافته</strong></td><td style="padding:7px 0; text-align:left; font-weight:bold; color:#15803d;"><?= formatAmountToman($realizedIncome) ?></td></tr>
        </table>
    </div>
    <!-- Realized expense breakdown -->
    <div class="form-card" style="flex:1; min-width:260px; margin:0;">
        <h4>هزینه تحقق‌یافته بر اساس نوع</h4>
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <tr><td style="padding:7px 0; border-bottom:1px solid #eee;">هزینه طراحی (طراحان)</td><td style="padding:7px 0; border-bottom:1px solid #eee; text-align:left; font-weight:bold;"><?= formatAmountToman($expenseByType['designer']) ?></td></tr>
            <tr><td style="padding:7px 0; border-bottom:1px solid #eee;">برون‌سپاری (لابراتوارها)</td><td style="padding:7px 0; border-bottom:1px solid #eee; text-align:left; font-weight:bold;"><?= formatAmountToman($expenseByType['outsource']) ?></td></tr>
            <tr><td style="padding:7px 0;"><strong>جمع هزینه تحقق‌یافته</strong></td><td style="padding:7px 0; text-align:left; font-weight:bold; color:#b91c1c;"><?= formatAmountToman($realizedExpense) ?></td></tr>
        </table>
    </div>
</div>

<!-- تعداد خدمات انجام‌شده در بازه -->
<div class="form-card" style="margin-bottom:22px;">
    <h4>تعداد خدمات انجام‌شده (<?= htmlspecialchars($periodLabel) ?>)</h4>
    <div class="table-scroll">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead>
                <tr>
                    <th style="text-align:right; padding:7px 8px; border-bottom:1px solid #ddd;">خدمت</th>
                    <th style="text-align:center; padding:7px 8px; border-bottom:1px solid #ddd;">تعداد کیس</th>
                    <th style="text-align:center; padding:7px 8px; border-bottom:1px solid #ddd;">مجموع تعداد واحد</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($serviceCounts)): ?>
                    <tr><td colspan="3" style="padding:10px; color:#6b7280;">موردی در این بازه انجام نشده است.</td></tr>
                <?php else: ?>
                    <?php $totalCases = 0; $totalQty = 0; foreach ($serviceCounts as $sc): $totalCases += (int)$sc['case_count']; $totalQty += (int)$sc['qty_sum']; ?>
                    <tr>
                        <td style="padding:7px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($sc['service'] ?: 'نامشخص') ?></td>
                        <td style="padding:7px 8px; border-bottom:1px solid #eee; text-align:center;"><?= toPersianDigits((int)$sc['case_count']) ?></td>
                        <td style="padding:7px 8px; border-bottom:1px solid #eee; text-align:center;"><?= toPersianDigits((int)$sc['qty_sum']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:bold;">
                        <td style="padding:7px 8px;">جمع</td>
                        <td style="padding:7px 8px; text-align:center;"><?= toPersianDigits($totalCases) ?></td>
                        <td style="padding:7px 8px; text-align:center;"><?= toPersianDigits($totalQty) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
    <p class="empty">بازه زمانی را انتخاب کنید تا آمار درآمد و هزینه نمایش داده شود.</p>
<?php endif; ?>

<!-- Monthly chart (pure CSS bars) -->
<div class="form-card" style="margin-top:10px;">
    <h4>درآمد و هزینه — ۱۲ ماه اخیر (شمسی)</h4>
    <div id="chart-wrap" style="display:flex; gap:8px; align-items:flex-end; min-height:260px; padding-top:20px; overflow-x:auto; direction:ltr; text-align:center; position:relative;">
        <?php foreach ($months as $mm): ?>
            <?php
            $hIncR = $mm['inc_real'] > 0 ? max(8, round($mm['inc_real'] / $maxChart * 200)) : 2;
            $hIncU = $mm['inc_unreal'] > 0 ? max(8, round($mm['inc_unreal'] / $maxChart * 200)) : 2;
            $hExpR = $mm['exp_real'] > 0 ? max(8, round($mm['exp_real'] / $maxChart * 200)) : 2;
            $hExpU = $mm['exp_unreal'] > 0 ? max(8, round($mm['exp_unreal'] / $maxChart * 200)) : 2;
            ?>
            <div style="flex:1; min-width:64px; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:240px; position:relative;">
                <div style="display:flex; gap:3px; align-items:flex-end;">
                    <div class="chart-bar" data-label="درآمد تحقق‌یافته" data-value="<?= formatAmountToman($mm['inc_real']) ?>" data-month="<?= htmlspecialchars($mm['label']) ?>" style="width:15px; background:#22c55e; border-radius:3px 3px 0 0; height:<?= $hIncR ?>px; cursor:pointer;"></div>
                    <div class="chart-bar" data-label="درآمد تحقق‌نیافته" data-value="<?= formatAmountToman($mm['inc_unreal']) ?>" data-month="<?= htmlspecialchars($mm['label']) ?>" style="width:15px; background:#5eead4; border-radius:3px 3px 0 0; height:<?= $hIncU ?>px; cursor:pointer;"></div>
                    <div class="chart-bar" data-label="هزینه تحقق‌یافته" data-value="<?= formatAmountToman($mm['exp_real']) ?>" data-month="<?= htmlspecialchars($mm['label']) ?>" style="width:15px; background:#ef4444; border-radius:3px 3px 0 0; height:<?= $hExpR ?>px; cursor:pointer;"></div>
                    <div class="chart-bar" data-label="هزینه تحقق‌نیافته" data-value="<?= formatAmountToman($mm['exp_unreal']) ?>" data-month="<?= htmlspecialchars($mm['label']) ?>" style="width:15px; background:#fdba74; border-radius:3px 3px 0 0; height:<?= $hExpU ?>px; cursor:pointer;"></div>
                </div>
                <div style="font-size:0.62rem; color:#6b7280; margin-top:4px; white-space:nowrap;"><?= htmlspecialchars($mm['label']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex; gap:18px; margin-top:14px; font-size:0.85rem; color:#525252; flex-wrap:wrap;">
        <span><span style="display:inline-block; width:12px; height:12px; background:#22c55e; border-radius:2px; vertical-align:middle;"></span> درآمد تحقق‌یافته</span>
        <span><span style="display:inline-block; width:12px; height:12px; background:#5eead4; border-radius:2px; vertical-align:middle;"></span> درآمد تحقق‌نیافته</span>
        <span><span style="display:inline-block; width:12px; height:12px; background:#ef4444; border-radius:2px; vertical-align:middle;"></span> هزینه تحقق‌یافته</span>
        <span><span style="display:inline-block; width:12px; height:12px; background:#fdba74; border-radius:2px; vertical-align:middle;"></span> هزینه تحقق‌نیافته</span>
    </div>
    <small style="display:block; margin-top:8px; color:#6b7280;">🖱 برای دیدن مبلغ دقیق هر ستون، نشانگر موس را روی آن ببرید.</small>
</div>
</div><!-- /fin-pane-overview -->

<div id="fin-pane-cases" style="display:none;">
    <?php if ($startDate !== '' && $endDate !== '' && !empty($pieGroups)): ?>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
            <?php $first = true; foreach ($pieGroups as $key => $g): ?>
                <button type="button" class="pie-dim-btn" data-dim="<?= $key ?>" onclick="switchPieDim('<?= $key ?>')" style="background:<?= $first ? '#0F172A' : '#E5E7EB' ?>; color:<?= $first ? '#fff' : '#0F172A' ?>; border:none; padding:9px 18px; font-weight:700; cursor:pointer; border-radius:8px;"><?= htmlspecialchars($g['label']) ?></button>
                <?php $first = false; endforeach; ?>
        </div>
        <?php $first = true; foreach ($pieGroups as $key => $g): ?>
        <div class="pie-dim-pane" id="pie-dim-<?= $key ?>" style="display:<?= $first ? '' : 'none' ?>;">
            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                <?php if (!empty($g['income']['slices'])): ?>
                <div class="form-card" style="flex:1; min-width:320px; margin:0;">
                    <h4>درآمد بر اساس <?= htmlspecialchars($g['label']) ?> (<?= htmlspecialchars($periodLabel) ?>)</h4>
                    <?php if ($key === 'lab'): ?><small style="display:block; color:#6b7280; margin-bottom:8px;">فقط کیس‌های متعلق به شعبه شما — به تفکیک لابراتوار انجام‌دهندهٔ کار</small><?php endif; ?>
                    <?= $renderDonut($g['income']) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($g['expense']['slices'])): ?>
                <div class="form-card" style="flex:1; min-width:320px; margin:0;">
                    <h4>هزینه بر اساس <?= htmlspecialchars($g['label']) ?> (<?= htmlspecialchars($periodLabel) ?>)</h4>
                    <?= $renderDonut($g['expense']) ?>
                </div>
                <?php endif; ?>
                <?php if ($key === 'inbound' && !empty($g['detail'])): ?>
                <div class="form-card" style="flex:1 1 100%; min-width:320px; margin:0;">
                    <h4>تفکیک دریافتی از شعب همکار — نوع کار و مبدأ (<?= htmlspecialchars($periodLabel) ?>)</h4>
                    <small style="display:block; color:#6b7280; margin-bottom:8px;">تعداد واحد هر نوع کار، به تفکیک شعبه‌ای که کار را به شما ارسال کرده است.</small>
                    <?= $renderDetailTable($g['detail'], 'مبدأ (شعبه فرستنده)', 'مبلغ دریافتی') ?>
                </div>
                <?php endif; ?>
                <?php if ($key === 'outsourced' && !empty($g['detail'])): ?>
                <div class="form-card" style="flex:1 1 100%; min-width:320px; margin:0;">
                    <h4>تفکیک برون‌سپاری‌شده — نوع کار و مقصد (<?= htmlspecialchars($periodLabel) ?>)</h4>
                    <small style="display:block; color:#6b7280; margin-bottom:8px;">تعداد واحد هر نوع کاری که به بیرون ارسال کرده‌اید، به تفکیک لابراتوار/شعبه مقصد.</small>
                    <?= $renderDetailTable($g['detail'], 'مقصد (لابراتوار/شعبه)', 'مبلغ هزینه') ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php $first = false; endforeach; ?>
        <p style="margin-top:12px; font-size:0.85rem; color:#525252;">نمودارها بر اساس کیس‌های دریافت‌شده در همین بازه هستند. «دریافتی از شعب همکار» = مبلغی که شعبه‌های دیگر بابت کارِ ما به ما بدهکارند، و «برون‌سپاری‌شده» = بدهی ما به لابراتوار/شعبه‌های دیگر (این دو فقط برای مدیران شعبه نمایش داده می‌شوند).</p>
    <?php else: ?>
        <p class="empty">ابتدا یک بازه زمانی (مثلاً یک ماه) انتخاب کنید تا نمودار دایره‌ای نمایش داده شود.</p>
    <?php endif; ?>
</div>

<!-- Interactive chart tooltip -->
<div id="chart-tooltip" style="display:none; position:fixed; z-index:99999; pointer-events:none; background:#0F172A; color:#fff; border-radius:8px; padding:8px 12px; font-size:0.85rem; box-shadow:0 6px 18px rgba(0,0,0,0.25); max-width:260px;"></div>

<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<script>
function togglePeriodType(){
    var v = document.getElementById('period_type').value;
    document.getElementById('monthly-fields').style.display = (v === 'monthly') ? 'flex' : 'none';
    document.getElementById('custom-fields').style.display = (v === 'custom') ? 'flex' : 'none';
}
function switchFinTab(tab){
    var ov = document.getElementById('fin-pane-overview');
    var cs = document.getElementById('fin-pane-cases');
    var tbO = document.getElementById('fin-tab-overview');
    var tbC = document.getElementById('fin-tab-cases');
    if (ov) ov.style.display = (tab === 'overview') ? '' : 'none';
    if (cs) cs.style.display = (tab === 'cases') ? '' : 'none';
    if (tbO) { tbO.style.background = (tab === 'overview') ? '#0F172A' : '#E5E7EB'; tbO.style.color = (tab === 'overview') ? '#fff' : '#0F172A'; }
    if (tbC) { tbC.style.background = (tab === 'cases') ? '#0F172A' : '#E5E7EB'; tbC.style.color = (tab === 'cases') ? '#fff' : '#0F172A'; }
}
function switchPieDim(dim){
    document.querySelectorAll('.pie-dim-pane').forEach(function(p){ p.style.display = 'none'; });
    document.querySelectorAll('.pie-dim-btn').forEach(function(b){
        var active = b.getAttribute('data-dim') === dim;
        b.style.background = active ? '#0F172A' : '#E5E7EB';
        b.style.color = active ? '#fff' : '#0F172A';
    });
    var pane = document.getElementById('pie-dim-' + dim);
    if (pane) pane.style.display = '';
}
(function(){
    var tip = document.getElementById('chart-tooltip');
    if (!tip) return;
    var bars = document.querySelectorAll('.chart-bar');
    bars.forEach(function(bar){
        bar.addEventListener('mouseenter', function(){
            var label = bar.getAttribute('data-label') || '';
            var value = bar.getAttribute('data-value') || '0';
            var month = bar.getAttribute('data-month') || '';
            tip.innerHTML = '<strong>' + month + '</strong><br>' + label + ': <span style="color:#86efac;">' + value + '</span> تومان';
            tip.style.display = 'block';
            bar.style.filter = 'brightness(0.9)';
            bar.style.outline = '2px solid rgba(0,0,0,0.25)';
        });
        bar.addEventListener('mousemove', function(e){
            tip.style.left = (e.clientX + 14) + 'px';
            tip.style.top = (e.clientY - 10) + 'px';
        });
        bar.addEventListener('mouseleave', function(){
            tip.style.display = 'none';
            bar.style.filter = '';
            bar.style.outline = '';
        });
    });
})();
document.addEventListener('DOMContentLoaded', function(){
    togglePeriodType();
    if (window.jQuery && typeof jQuery.fn.persianDatepicker === 'function') {
        var opts = { format: 'YYYY/MM/DD', calendarType: 'persian', initialValueType: 'jalali', persianDigit: true, autoClose: true };
        jQuery('#date_from').persianDatepicker(opts);
        jQuery('#date_to').persianDatepicker(opts);
    }
});
</script>
<?php panel_layout_end(); ?>
