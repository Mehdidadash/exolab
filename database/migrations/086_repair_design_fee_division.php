<?php
/**
 * 086_repair_design_fee_division.php
 *
 * تعمیرِ نهاییِ `cases.design_fee` برای کیس‌هایی که مقدارشان «تقسیم‌شده» است
 * (اثرِ اجرای چندبارهٔ مهاجرت ۰۷۴ که جمع کل را بر تعداد تقسیم می‌کرد).
 *
 * ── چطور تشخیص می‌دهیم؟ ──
 * «نرخ‌های معقول» هر طراح = هر عددی که در price_links / doctor_price_overrides /
 * فاکتورهای صادرشدهٔ همان طراح دیده شده است (یعنی اعدادی که واقعاً نرخ بوده‌اند).
 *
 *   قاعدهٔ E (تقدمِ سند): اگر برای کیس فاکتور طراح صادر شده، `unit_design_fee` فاکتور
 *      مرجع است و مقدار کیس با آن هم‌تراز می‌شود.
 *
 *   قاعدهٔ D: اگر `design_fee` هیچ‌کدام از نرخ‌های معقول نباشد ولی `design_fee × quantity`
 *      مضربِ صحیحِ یکی از آن نرخ‌ها باشد، یعنی مقدار قبلاً تقسیم شده است:
 *        • اگر نرخِ عمومیِ طراح (فی طراحی همهٔ خدمات) در میان مقسوم‌کننده‌ها باشد → همان
 *        • وگرنه بزرگ‌ترین مقسوم‌کننده (کمترین تعداد واحد طراحی)
 *
 * کیس‌هایی که `design_fee`شان خودش یک نرخ معقول است دست نمی‌خورند (حتی اگر نرخ امروز
 * عوض شده باشد) — چون نرخ قدیمیِ همان زمان درست است.
 *
 * قابل اجرای دوباره است: بعد از اصلاح، مقدار در مجموعهٔ نرخ‌های معقول قرار می‌گیرد.
 */

if (!function_exists('exolab_plausible_design_rates')) {
    /** @return array<int,int> مجموعهٔ نرخ‌های معقولِ یک طراح */
    function exolab_plausible_design_rates(int $designerId): array
    {
        $set = [];
        $add = function ($v) use (&$set) {
            $v = (float) $v;
            if ($v > 0) $set[(int) round($v)] = true;
        };
        $st = db()->prepare("SELECT price FROM price_links WHERE price_type='design_fee'
                             AND provider_type IN ('designer','doctor') AND provider_id = ?");
        $st->execute([$designerId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v);

        $st = db()->prepare("SELECT custom_price FROM doctor_price_overrides
                             WHERE price_type='design_fee' AND doctor_id = ?");
        $st->execute([$designerId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v);

        $st = db()->prepare("SELECT DISTINCT ii.unit_design_fee FROM designer_invoice_items ii
                             JOIN designer_invoices di ON di.id = ii.invoice_id
                             WHERE di.designer_id = ?");
        $st->execute([$designerId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v);

        return $set;
    }
}

if (!function_exists('exolab_repair_design_fee_division')) {
    /** @return array{fixed_e:int,fixed_d:int,skipped:int} */
    function exolab_repair_design_fee_division(bool $dryRun = false): array
    {
        $db  = db();
        $log = function (string $msg) {
            if (function_exists('out')) { out($msg, 'info'); } else { echo $msg, PHP_EOL; }
        };

        $cases = $db->query("SELECT id, received_date, service_id, quantity, design_fee, designer_id, designer_invoice_id
                             FROM cases
                             WHERE designer_id IS NOT NULL AND design_fee > 0
                             ORDER BY received_date ASC, id ASC")->fetchAll();

        $upd = $db->prepare('UPDATE cases SET design_fee = ?, design_fee_is_unit = 1, updated_at = NOW() WHERE id = ?');
        $stats = ['fixed_e' => 0, 'fixed_d' => 0, 'skipped' => 0];
        $rateCache = [];

        foreach ($cases as $c) {
            $des = (int) $c['designer_id'];
            $qty = max(1, (int) $c['quantity']);
            $fee = (float) $c['design_fee'];
            $rateCache[$des] = $rateCache[$des] ?? exolab_plausible_design_rates($des);
            $set = $rateCache[$des];

            // ── قاعدهٔ E: هم‌ترازی با فاکتور طراح ──
            if (!empty($c['designer_invoice_id'])) {
                $st = $db->prepare('SELECT unit_design_fee FROM designer_invoice_items WHERE case_id = ? ORDER BY id DESC LIMIT 1');
                $st->execute([(int) $c['id']]);
                $invFee = $st->fetchColumn();
                if ($invFee !== false && $invFee !== null && abs((float) $invFee - $fee) > 0.5) {
                    if (!$dryRun) { $upd->execute([(float) $invFee, (int) $c['id']]); }
                    $stats['fixed_e']++;
                    $log(sprintf('  [086] E #%d (%s): %s → %s (فاکتور #%d)', $c['id'], (string) $c['received_date'],
                        number_format($fee), number_format((float) $invFee), (int) $c['designer_invoice_id']));
                }
                continue;
            }

            if (isset($set[(int) round($fee)])) { continue; }   // خودش نرخ معقولی است

            // ── قاعدهٔ D: بازیابیِ مقدارِ تقسیم‌شده ──
            $prod = $fee * $qty;
            $divs = [];
            foreach (array_keys($set) as $r) {
                $d = $prod / $r;
                if (abs($d - round($d)) < 0.0002 && round($d) >= 1) $divs[] = $r;
            }
            if (!$divs) { $stats['skipped']++; continue; }

            sort($divs);
            $general = null;
            $st = $db->prepare("SELECT price FROM price_links WHERE price_type='design_fee'
                                AND provider_type IN ('designer','doctor') AND provider_id = ? AND service_id IS NULL
                                ORDER BY id DESC LIMIT 1");
            $st->execute([$des]);
            $g = $st->fetchColumn();
            if ($g !== false && $g !== null) $general = (int) round((float) $g);
            $chosen = in_array($general, $divs, true) ? $general : max($divs);

            if (!$dryRun) { $upd->execute([(float) $chosen, (int) $c['id']]); }
            $stats['fixed_d']++;
            $log(sprintf('  [086] D #%d (%s) qty=%d: %s → %s', $c['id'], (string) $c['received_date'], $qty,
                number_format($fee, 2), number_format($chosen)));
        }

        $log(sprintf('  [086] نتیجه: هم‌ترازیِ فاکتور=%d ، بازسازیِ تقسیم=%d ، نامشخص=%d%s',
            $stats['fixed_e'], $stats['fixed_d'], $stats['skipped'], $dryRun ? ' (dry-run)' : ''));

        return $stats;
    }
}

exolab_repair_design_fee_division(!empty($GLOBALS['EXOLAB_REPRICE_DRY_RUN']));
