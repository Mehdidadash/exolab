<?php
/**
 * 081_reprice_cases_since_1405_08_01.php
 *
 * بازنرخ‌گذاریِ کیس‌های «از ۱۴۰۵/۰۸/۰۱ به بعد» با نرخ‌های فعلیِ سیستم.
 *
 *   ۱۴۰۵/۰۸/۰۱  =  2026-10-23
 *
 * چرا PHP و نه SQL؟
 *   نرخِ هر کیس از چند لایه حل می‌شود (نرخ اختصاصی پزشک/طراح/لابراتوار → نرخ شعبه →
 *   نرخ عمومیِ خدمت) و برای هر نوع کیس (doctor / lab_out / lab_in) مسیرِ متفاوتی دارد.
 *   بازنویسیِ آن در SQL خالص هم ناخوانا و هم خطاپذیر است. این فایل دقیقاً همان ترتیبِ
 *   حلِ نرخِ توابعِ برنامه (getApplicablePrice / getApplicableDesignFee /
 *   getOutsourceRate / getLabApplicablePrice) را — ولی با «شعبهٔ خودِ کیس» به‌جای
 *   شعبهٔ کاربرِ لاگین‌شده — پیاده می‌کند.
 *
 * چه چیزی به‌روز می‌شود (فقط برای received_date >= 2026-10-23):
 *   • unit_price      → فیِ واحد (lab_in: نرخ لابراتوار ، بقیه: نرخ پزشک/عمومی)
 *   • total_price     → همان قاعدهٔ برنامه (serviceTotalPrice — قیمت پله‌ای)
 *   • design_fee      → فیِ طراحی هر واحد (نه جمع کل — طبق مهاجرت ۰۷۴)
 *   • outsourced_rate → نرخ برون‌سپاری هر واحد (lab_out یا برون‌سپاری جانبی)
 *
 * کیس‌های قبل از این تاریخ دست‌نخورده می‌مانند.
 * کیس‌هایی که فاکتور/صورتحساب دارند (invoice/designer/outsource/receivable) دست‌نخورده می‌مانند
 * تا سندِ صادرشده با کیس ناسازگار نشود.
 *
 * این مهاجرت «تکرارپذیر» است: مقدارها از نرخ‌های فعلی بازمحاسبه می‌شوند (نه ضرب/تقسیمِ مقدارِ قبلی)،
 * ولی migrate.php آن را فقط یک‌بار اجرا می‌کند.
 */

// ───────────────────────── ابزارِ حلِ نرخ (مستقل از سشن، با شعبهٔ صریح) ─────────────────────────

if (!function_exists('exolab_reprice_doctor_unit')) {
    /** فیِ واحدِ پزشک/کلینیک برای یک خدمت، با نرخ اختصاصی → شعبهٔ کیس → کاتالوگ عمومی. */
    function exolab_reprice_doctor_unit(int $doctorId, int $serviceId, ?int $branchId): ?float
    {
        $db = db();
        $branchSql = ($branchId !== null)
            ? ' AND (branch_id = ? OR branch_id IS NULL) ORDER BY (branch_id = ?) DESC'
            : ' ORDER BY id DESC';
        $branchParams = ($branchId !== null) ? [$branchId, $branchId] : [];

        $q = function (int $targetId) use ($db, $serviceId, $branchSql, $branchParams): ?float {
            $st = $db->prepare('SELECT custom_price FROM doctor_price_overrides
                                WHERE doctor_id = ? AND price_type = "service" AND service_id = ?'
                                . $branchSql . ' LIMIT 1');
            $st->execute(array_merge([$targetId, $serviceId], $branchParams));
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null) ? (float) $v : null;
        };

        $v = $q($doctorId);
        if ($v !== null) return $v;

        // کلینیکِ والد (اگر پزشک زیرِ یک کلینیک است)
        $parent = 0;
        try {
            $pc = $db->prepare('SELECT parent_clinic_id FROM users WHERE id = ?');
            $pc->execute([$doctorId]);
            $parent = (int) $pc->fetchColumn();
        } catch (Throwable $e) {
            $parent = 0;
        }
        if ($parent > 0) {
            $v = $q($parent);
            if ($v !== null) return $v;
        }

        // نرخ اختصاصیِ خودِ شعبه برای این خدمت
        if ($branchId !== null) {
            $st = $db->prepare('SELECT custom_price FROM branch_service_prices WHERE branch_id = ? AND service_id = ?');
            $st->execute([$branchId, $serviceId]);
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null) return (float) $v;
        }

        // کاتالوگ عمومی
        $st = $db->prepare('SELECT price FROM site_prices WHERE id = ?');
        $st->execute([$serviceId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (float) $v : null;
    }
}

if (!function_exists('exolab_reprice_lab_unit')) {
    /** فیِ واحدی که از یک لابراتوار همکار می‌گیریم (کیسِ lab_in). */
    function exolab_reprice_lab_unit(int $labId, int $serviceId): ?float
    {
        $db = db();

        // ۱) همان چیزی که تبِ «نرخ لابراتوارها» ذخیره می‌کند (price_links: شعبه → لابراتوار)
        $st = $db->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type = 'specific'
                            AND receiver_type = 'lab' AND receiver_id = ? AND service_id = ?
                            ORDER BY id DESC LIMIT 1");
        $st->execute([$labId, $serviceId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        // ۲) جدول یکپارچهٔ override (لابراتوار به‌عنوان هدف)
        $st = $db->prepare('SELECT custom_price FROM doctor_price_overrides
                            WHERE doctor_id = ? AND price_type = "service" AND service_id = ?
                            ORDER BY id DESC LIMIT 1');
        $st->execute([$labId, $serviceId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        // ۳) جدول قدیمیِ نرخ لابراتوار
        $st = $db->prepare('SELECT custom_price FROM lab_price_overrides WHERE lab_id = ? AND service_id = ?
                            ORDER BY id DESC LIMIT 1');
        $st->execute([$labId, $serviceId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        // ۴) کاتالوگ عمومی
        $st = $db->prepare('SELECT price FROM site_prices WHERE id = ?');
        $st->execute([$serviceId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (float) $v : null;
    }
}

if (!function_exists('exolab_reprice_design_unit')) {
    /** فیِ طراحیِ هر واحد (design_fee از مهاجرت ۰۷۴ به بعد «فیِ واحد» است). */
    function exolab_reprice_design_unit(int $designerId, int $serviceId, ?int $branchId): ?float
    {
        $db = db();

        // ۱) نرخ اختصاصیِ طراح برای همین خدمت
        $st = $db->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type = 'design_fee'
                            AND provider_type IN ('designer','doctor') AND provider_id = ? AND service_id = ?
                            ORDER BY id DESC LIMIT 1");
        $st->execute([$designerId, $serviceId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        // ۲) نرخ عمومیِ طراح
        $st = $db->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type = 'design_fee'
                            AND provider_type IN ('designer','doctor') AND provider_id = ? AND service_id IS NULL
                            ORDER BY id DESC LIMIT 1");
        $st->execute([$designerId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        $branchSql = ($branchId !== null)
            ? ' AND (branch_id = ? OR branch_id IS NULL) ORDER BY (branch_id = ?) DESC'
            : ' ORDER BY id DESC';
        $branchParams = ($branchId !== null) ? [$branchId, $branchId] : [];

        // ۳) نرخ اختصاصیِ طراح در جدول قدیمی (خدمتِ مشخص)
        $st = $db->prepare('SELECT custom_price FROM doctor_price_overrides
                            WHERE doctor_id = ? AND price_type = "design_fee" AND service_id = ?'
                            . $branchSql . ' LIMIT 1');
        $st->execute(array_merge([$designerId, $serviceId], $branchParams));
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        // ۴) نرخ عمومیِ طراح در جدول قدیمی
        $st = $db->prepare('SELECT custom_price FROM doctor_price_overrides
                            WHERE doctor_id = ? AND price_type = "design_fee" AND service_id IS NULL'
                            . $branchSql . ' LIMIT 1');
        $st->execute(array_merge([$designerId], $branchParams));
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        // ۵) فی طراحیِ پیش‌فرضِ خودِ خدمت
        $st = $db->prepare('SELECT default_design_fee FROM site_prices WHERE id = ?');
        $st->execute([$serviceId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? (float) $v : null;
    }
}

if (!function_exists('exolab_reprice_outsource_unit')) {
    /** نرخِ هر واحدِ برون‌سپاری که «شعبهٔ پرداخت‌کننده» به لابراتوار می‌دهد. */
    function exolab_reprice_outsource_unit(int $labId, int $serviceId, ?int $payerBranchId): ?float
    {
        $db = db();

        // ۱) لینکِ مستقیم با لابراتوار به‌عنوان انجام‌دهنده
        $st = $db->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type IN ('outsource','specific')
                            AND provider_type = 'lab' AND provider_id = ? AND service_id = ?
                            AND receiver_type = 'branch' ORDER BY id DESC LIMIT 1");
        $st->execute([$labId, $serviceId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        // ۲) لینکِ بین‌شعبه‌ای از طریقِ شعبهٔ خودِ لابراتوار (لابراتوارِ مرکزی)
        $pb = $db->prepare('SELECT branch_id FROM users WHERE id = ?');
        $pb->execute([$labId]);
        $labBranch = $pb->fetchColumn();
        if ($labBranch !== false && $labBranch !== null) {
            $sql = "SELECT price FROM price_links WHERE active = 1 AND price_type IN ('outsource','specific')
                    AND provider_type = 'branch' AND provider_id = ? AND service_id = ? AND receiver_type = 'branch'"
                 . ($payerBranchId !== null ? ' AND receiver_id = ?' : '')
                 . ' ORDER BY id DESC LIMIT 1';
            $st = $db->prepare($sql);
            $st->execute($payerBranchId !== null
                ? [(int) $labBranch, $serviceId, $payerBranchId]
                : [(int) $labBranch, $serviceId]);
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null) return (float) $v;
        }

        // ۳) جدول قدیمیِ outsource_rates (اختصاصیِ شعبه، بعد عمومی)
        if ($payerBranchId !== null) {
            $st = $db->prepare('SELECT rate FROM outsource_rates WHERE lab_id = ? AND service_id = ?
                                AND (branch_id = ? OR branch_id IS NULL) ORDER BY (branch_id = ?) DESC LIMIT 1');
            $st->execute([$labId, $serviceId, $payerBranchId, $payerBranchId]);
        } else {
            $st = $db->prepare('SELECT rate FROM outsource_rates WHERE lab_id = ? AND service_id = ? LIMIT 1');
            $st->execute([$labId, $serviceId]);
        }
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (float) $v : null;
    }
}

if (!function_exists('exolab_reprice_total')) {
    /** مبلغ کل — با همان قاعدهٔ برنامه (قیمت پله‌ای). */
    function exolab_reprice_total(float $unitPrice, int $quantity, int $serviceId): float
    {
        if (function_exists('serviceTotalPrice')) {
            return (float) serviceTotalPrice($unitPrice, $quantity, $serviceId);
        }
        $qty = max(1, $quantity);
        $p = function_exists('getServicePricing') ? getServicePricing($serviceId) : ['base_units' => 1, 'extra_unit_price' => null];
        $baseUnits = max(1, (int) ($p['base_units'] ?? 1));
        $extraUnit = $p['extra_unit_price'] ?? $unitPrice;
        return round($unitPrice + (max(0, $qty - $baseUnits) * (float) $extraUnit));
    }
}

// ───────────────────────── اجرای بازنرخ‌گذاری ─────────────────────────

if (!function_exists('exolab_reprice_cases_since_1405_08_01')) {
    /**
     * @return array{n:int,updated:int,skipped:int,errors:int}
     */
    function exolab_reprice_cases_since_1405_08_01(string $cutoff = '2026-10-23', bool $dryRun = false): array
    {
        $db  = db();
        $log = function (string $msg) {
            if (function_exists('out')) {
                out($msg, 'info');
            } else {
                echo $msg, PHP_EOL;
            }
        };

        $stmt = $db->prepare('SELECT id, case_type, branch_id, doctor_id, service_id, quantity,
                                     unit_price, total_price, design_fee, designer_id,
                                     lab_id, outsourced_lab_id, outsourced_service_id, outsourced_qty, outsourced_rate
                              FROM cases
                              WHERE received_date >= ?
                                AND invoice_id IS NULL
                                AND designer_invoice_id IS NULL
                                AND outsource_invoice_id IS NULL
                                AND receivable_invoice_id IS NULL
                              ORDER BY id ASC');
        $stmt->execute([$cutoff]);
        $cases = $stmt->fetchAll();

        $upd = $db->prepare('UPDATE cases
                             SET unit_price = ?, total_price = ?, design_fee = ?, design_fee_is_unit = 1,
                                 outsourced_rate = ?, updated_at = NOW()
                             WHERE id = ?');

        $stats = ['n' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $log('  [081] بازهٔ بازنرخ‌گذاری: received_date >= ' . $cutoff . ' — ' . count($cases) . ' کیس');

        foreach ($cases as $c) {
            $stats['n']++;
            $id      = (int) $c['id'];
            $svcId   = (int) $c['service_id'];
            $qty     = max(1, (int) $c['quantity']);
            $brId    = $c['branch_id'] !== null ? (int) $c['branch_id'] : null;
            $type    = (string) ($c['case_type'] ?? 'doctor');
            $docId   = (int) ($c['doctor_id'] ?? 0);
            $labId   = (int) ($c['lab_id'] ?? 0);

            try {
                // ── ۱) فیِ واحد ──
                if ($type === 'lab_in' && $labId > 0) {
                    $newUnit = exolab_reprice_lab_unit($labId, $svcId);
                } elseif ($docId > 0) {
                    $newUnit = exolab_reprice_doctor_unit($docId, $svcId, $brId);
                } else {
                    $newUnit = exolab_reprice_doctor_unit(0, $svcId, $brId);   // فقط کاتالوگ/شعبه
                }
                if ($newUnit === null) {
                    $stats['skipped']++;
                    continue;
                }
                $newUnit = round($newUnit, 2);
                $newTotal = exolab_reprice_total($newUnit, $qty, $svcId);

                // ── ۲) فیِ طراحی (هر واحد) ──
                $newDesign = (float) $c['design_fee'];
                $designerId = (int) ($c['designer_id'] ?? 0);
                if ($designerId > 0) {
                    $d = exolab_reprice_design_unit($designerId, $svcId, $brId);
                    if ($d !== null) $newDesign = round($d, 2);
                }

                // ── ۳) نرخ برون‌سپاری (هر واحد) ──
                $newOut = $c['outsourced_rate'] !== null ? (float) $c['outsourced_rate'] : null;
                if ($type === 'lab_out' && $labId > 0) {
                    $r = exolab_reprice_outsource_unit($labId, $svcId, $brId);
                    if ($r !== null) $newOut = round($r, 2);
                } elseif (!empty($c['outsourced_lab_id']) && (int) $c['outsourced_qty'] > 0) {
                    $r = exolab_reprice_outsource_unit((int) $c['outsourced_lab_id'], (int) $c['outsourced_service_id'], $brId);
                    if ($r !== null) $newOut = round($r, 2);
                }

                $oldUnit   = (float) $c['unit_price'];
                $oldTotal  = (float) $c['total_price'];
                $oldDesign = (float) $c['design_fee'];
                $oldOut    = $c['outsourced_rate'] !== null ? (float) $c['outsourced_rate'] : null;

                $changed = abs($newUnit - $oldUnit) > 0.4
                        || abs($newTotal - $oldTotal) > 0.4
                        || abs($newDesign - $oldDesign) > 0.4
                        || ($newOut !== $oldOut && ($newOut === null || $oldOut === null || abs($newOut - $oldOut) > 0.4));

                if (!$changed) {
                    continue;
                }

                if ($dryRun) {
                    $log(sprintf('  [081] (dry) #%d %s: فی %s→%s | طراحی %s→%s | برون‌سپاری %s→%s',
                        $id, $type, number_format($oldUnit), number_format($newUnit),
                        number_format($oldDesign), number_format($newDesign),
                        $oldOut === null ? '—' : number_format($oldOut),
                        $newOut === null ? '—' : number_format($newOut)));
                } else {
                    $upd->execute([$newUnit, $newTotal, $newDesign, $newOut, $id]);
                }
                $stats['updated']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                $log('  [081] خطا در کیس #' . $id . ': ' . $e->getMessage());
            }
        }

        $log(sprintf('  [081] نتیجه: %d کیس بررسی شد، %d به‌روزرسانی%s، %d رد شد، %d خطا',
            $stats['n'], $stats['updated'], $dryRun ? ' (dry-run: فقط گزارش)' : '', $stats['skipped'], $stats['errors']));

        return $stats;
    }
}

// اجرای واقعی — مگر اینکه کسی از بیرون dry-run را درخواست کرده باشد.
exolab_reprice_cases_since_1405_08_01('2026-10-23', !empty($GLOBALS['EXOLAB_REPRICE_DRY_RUN']));
