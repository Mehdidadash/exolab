<?php
/**
 * 082_repair_design_fee_units.php
 *
 * اصلاحِ نرخ طراحیِ کیس‌هایی که در مهاجرت‌های ۰۷۴/۰۷۶ «دوبار تقسیم» شده‌اند
 * + هم‌سان‌سازیِ کیس‌هایی که مهاجرت ۰۸۱ با نرخِ اشتباهِ ۶۸۰٬۰۰۰ بازنرخ‌گذاری کرد.
 *
 * ── ریشهٔ مشکل ۰۷۴/۰۷۶ ──
 * ۰۷۴ معنای `cases.design_fee` را از «جمع کل» به «فی هر واحد» برد و با
 * `design_fee = design_fee / quantity` این تبدیل را انجام داد. آن UPDATE idempotent
 * نبود؛ هر بار که فایل دوباره روی داده اجرا می‌شد، مقدار یک بار دیگر تقسیم می‌شد
 * (مثلاً ۱۶۰٬۰۰۰ → ۱۶٬۰۰۰ برای ۱۰ واحد). چون تاریخچهٔ نرخ‌ها را نگه نمی‌داریم،
 * «مقدار درستِ آن روز» قابل بازیابی نیست؛ ولی خودِ خرابی قابل تشخیص است:
 *
 *   الف) `design_fee × quantity == نرخِ فعلی` و `design_fee < نرخ`  → قطعاً تقسیم شده
 *        (نرخِ همان خدمت/طراح از آن روز تا امروز عوض نشده) ⇒ همان نرخ نوشته می‌شود.
 *   ب) `design_fee` عددی صحیح نیست (اعشار دارد) و `design_fee × quantity` برابر یکی از
 *        نرخ‌های شناخته‌شدهٔ همان طراح است و خودِ `design_fee` نرخ شناخته‌شده نیست
 *        ⇒ تقسیمِ یک‌بارهٔ یک مبلغِ ثابت است ⇒ همان مبلغ نوشته می‌شود.
 *   ج) کیس‌های «از ۱۴۰۵/۰۸/۰۱ به بعد» که نرخشان دیگر در هیچ جدول نرخی وجود ندارد
 *        (نمونه: ۶۸۰٬۰۰۰ که اشتباه بود و به ۸۰٬۰۰۰ اصلاح شد) ⇒ نرخِ فعلی نوشته می‌شود.
 *   د) بقیه (نرخِ مذاکره‌ایِ قدیمی، یا طراحیِ چندواحدی مثل کیس ۱۱۲۳) دست‌نخورده می‌ماند
 *        و فقط گزارش می‌شود.
 *
 * در پایان همهٔ ردیف‌های دارای طراحی `design_fee_is_unit = 1` می‌شوند تا اگر روزی
 * فایل ۰۷۴ دوباره اجرا شد، این ردیف‌ها هدفِ تقسیمِ دوباره قرار نگیرند.
 *
 * ⚠️ اجرای دوباره بی‌خطر است: قواعد الف/ب/ج بعد از اصلاح دیگر مطابقت نمی‌کنند.
 */

if (!function_exists('exolab_design_rate_for_case')) {
    /** نرخِ فعلیِ طراحی برای (طراح، خدمت) با محدودهٔ شعبهٔ صریح. */
    function exolab_design_rate_for_case(int $designerId, int $serviceId, ?int $branchId): ?float
    {
        $db = db();
        $st = $db->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type = 'design_fee'
                            AND provider_type IN ('designer','doctor') AND provider_id = ? AND service_id = ?
                            ORDER BY id DESC LIMIT 1");
        $st->execute([$designerId, $serviceId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        $st = $db->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type = 'design_fee'
                            AND provider_type IN ('designer','doctor') AND provider_id = ? AND service_id IS NULL
                            ORDER BY id DESC LIMIT 1");
        $st->execute([$designerId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        $branchSql = ($branchId !== null)
            ? ' AND (branch_id = ? OR branch_id IS NULL) ORDER BY (branch_id = ?) DESC'
            : ' ORDER BY id DESC';
        $bp = ($branchId !== null) ? [$branchId, $branchId] : [];

        $st = $db->prepare('SELECT custom_price FROM doctor_price_overrides
                            WHERE doctor_id = ? AND price_type = "design_fee" AND service_id = ?'
                            . $branchSql . ' LIMIT 1');
        $st->execute(array_merge([$designerId, $serviceId], $bp));
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        $st = $db->prepare('SELECT custom_price FROM doctor_price_overrides
                            WHERE doctor_id = ? AND price_type = "design_fee" AND service_id IS NULL'
                            . $branchSql . ' LIMIT 1');
        $st->execute(array_merge([$designerId], $bp));
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float) $v;

        $st = $db->prepare('SELECT default_design_fee FROM site_prices WHERE id = ?');
        $st->execute([$serviceId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? (float) $v : null;
    }
}

if (!function_exists('exolab_design_known_rates')) {
    /** همهٔ نرخ‌های طراحیِ «شناخته‌شدهٔ» یک طراح (برای تشخیصِ مقدارِ اصلی از مقدارِ تقسیم‌شده). */
    function exolab_design_known_rates(int $designerId): array
    {
        $db = db();
        $out = [];

        $st = $db->prepare("SELECT DISTINCT price FROM price_links
                            WHERE price_type = 'design_fee' AND provider_type IN ('designer','doctor') AND provider_id = ?");
        $st->execute([$designerId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $out[(int) round((float) $v)] = true;

        $st = $db->prepare('SELECT DISTINCT custom_price FROM doctor_price_overrides
                            WHERE doctor_id = ? AND price_type = "design_fee"');
        $st->execute([$designerId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $out[(int) round((float) $v)] = true;

        return $out;
    }
}

if (!function_exists('exolab_repair_design_fees')) {
    /**
     * @return array{total:int,fixed_a:int,fixed_b:int,fixed_c:int,left:int,marked:int}
     */
    function exolab_repair_design_fees(bool $dryRun = false): array
    {
        $db  = db();
        $log = function (string $msg) {
            if (function_exists('out')) { out($msg, 'info'); } else { echo $msg, PHP_EOL; }
        };

        $repoCutoff = '2026-10-23';   // ۱۴۰۵/۰۸/۰۱

        $cases = $db->query("SELECT id, received_date, branch_id, service_id, quantity, design_fee, designer_id
                             FROM cases
                             WHERE designer_id IS NOT NULL AND design_fee > 0
                             ORDER BY id ASC")->fetchAll();

        $upd = $db->prepare('UPDATE cases SET design_fee = ?, design_fee_is_unit = 1, updated_at = NOW() WHERE id = ?');

        $stats = ['total' => 0, 'fixed_a' => 0, 'fixed_b' => 0, 'fixed_c' => 0, 'left' => 0, 'marked' => 0];

        foreach ($cases as $c) {
            $stats['total']++;
            $id   = (int) $c['id'];
            $qty  = max(1, (int) $c['quantity']);
            $fee  = (float) $c['design_fee'];
            $sid  = (int) $c['service_id'];
            $did  = (int) $c['designer_id'];
            $br   = $c['branch_id'] !== null ? (int) $c['branch_id'] : null;
            $isRepo = ((string) $c['received_date'] >= $repoCutoff);

            $expected = exolab_design_rate_for_case($did, $sid, $br);
            $known    = exolab_design_known_rates($did);
            $feeKey   = (int) round($fee);
            $newFee   = null;
            $rule     = '';

            // (الف) نرخِ همین خدمت عوض نشده و مقدار دقیقاً به تعداد تقسیم شده
            if ($expected !== null && $expected > 0 && $fee < $expected && abs(($fee * $qty) - $expected) < 0.9) {
                $newFee = round($expected, 2);
                $rule = 'A';
            }
            // (ب) مقدار اعشاری + بازیابیِ مبلغِ ثابت، فقط اگر مبلغِ بازیابی‌شده نرخِ شناخته‌شده باشد
            elseif (abs($fee - round($fee)) > 0.009) {
                $cand = (int) round($fee * $qty);
                if ($cand > 0 && isset($known[$cand]) && !isset($known[$feeKey])) {
                    $newFee = (float) $cand;
                    $rule = 'B';
                }
            }
            // (ج) فقط کیس‌های محدودهٔ بازنرخ‌گذاری: نرخی که دیگر در هیچ جدولی نیست (نرخِ اشتباهِ اصلاح‌شده)
            elseif ($isRepo && $expected !== null && $expected > 0
                    && !isset($known[$feeKey]) && abs($fee - $expected) > 0.9) {
                $newFee = round($expected, 2);
                $rule = 'C';
            }

            if ($newFee === null) {
                if (abs($fee - (float) $expected) > 0.9) {
                    $stats['left']++;
                    $log(sprintf('  [082] دست‌نخورده #%d (%s) qty=%d: فعلی=%s | نرخ=%s',
                        $id, (string) $c['received_date'], $qty, number_format($fee), $expected === null ? '—' : number_format($expected)));
                }
                continue;
            }

            if (!$dryRun) {
                $upd->execute([$newFee, $id]);
            }
            $stats['fixed_' . strtolower($rule)]++;
            $log(sprintf('  [082] #%d (%s) qty=%d: %s → %s  [قاعده %s]',
                $id, (string) $c['received_date'], $qty, number_format($fee), number_format($newFee), $rule));
        }

        // نشان‌دار کردنِ ردیف‌ها به‌عنوان «فی» تا مهاجرت ۰۷۴ هرگز دوباره تقسیمشان نکند
        if (!$dryRun) {
            $st = $db->prepare('UPDATE cases SET design_fee_is_unit = 1 WHERE design_fee > 0 AND design_fee_is_unit = 0');
            $st->execute();
            $stats['marked'] = $st->rowCount();
        }

        $log(sprintf('  [082] نتیجه: %d کیس، اصلاح A=%d B=%d C=%d، دست‌نخورده=%d، نشان‌دارشده=%d%s',
            $stats['total'], $stats['fixed_a'], $stats['fixed_b'], $stats['fixed_c'],
            $stats['left'], $stats['marked'], $dryRun ? ' (dry-run)' : ''));

        return $stats;
    }
}

exolab_repair_design_fees(!empty($GLOBALS['EXOLAB_REPRICE_DRY_RUN']));
