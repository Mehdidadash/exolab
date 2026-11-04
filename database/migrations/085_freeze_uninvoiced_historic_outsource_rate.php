<?php
/**
 * 085_freeze_uninvoiced_historic_outsource_rate.php
 *
 * تثبیتِ نرخِ برون‌سپاریِ کیس‌های **تاریخیِ فاکتورنشده**.
 *
 * ── مشکل ──
 * کیس‌هایی که قبل از ۱۴۰۵/۰۸/۰۱ ثبت شده‌اند ولی هنوز فاکتور برون‌سپاری برایشان صادر
 * نشده، ستون `outsourced_rate` خالی دارند؛ پس گزارش مخارج نرخ را از نرخ‌های **فعلی**
 * می‌خواند و با به‌روزرسانیِ نرخ‌ها، مخارجِ گذشته عوض می‌شود (نمونه: کیس ۱۱۰۸:
 * ۱٬۱۵۰٬۰۰۰ به‌جای ۱٬۰۰۰٬۰۰۰).
 *
 * ── راه‌حل ──
 * نرخِ تاریخیِ هر جفتِ (لابراتوار، خدمت) را از **آیتم‌های فاکتور برون‌سپاریِ کیس‌های
 * قبل از cutoff** استخراج می‌کنیم (آخرین فاکتورِ قبل از cutoff) و روی کیس‌های
 * فاکتورنشدهٔ همان جفت می‌نویسیم.
 *
 * جفت‌هایی که هیچ سابقهٔ فاکتوری ندارند دست‌نخورده می‌مانند و فقط گزارش می‌شوند
 * (نرخِ تاریخی‌شان قابل اثبات نیست؛ تصمیم با کاربر است).
 */

if (!function_exists('exolab_freeze_historic_outsource_rates')) {
    /**
     * @return array{fixed:int, unknown:int, pairs:array<string,array>}
     */
    function exolab_freeze_historic_outsource_rates(string $cutoff = '2026-10-23', bool $dryRun = false): array
    {
        $db  = db();
        $log = function (string $msg) {
            if (function_exists('out')) { out($msg, 'info'); } else { echo $msg, PHP_EOL; }
        };

        // ── نرخ‌های تاریخیِ تأییدشده توسط کاربر (بین شعبهٔ اسلامشهر و قزوین) ──
        // کلید = "lab_id:service_id". این مقادیر بر نرخ‌های فعلی اولویت دارند و فقط
        // برای کیس‌های **قبل از cutoff** اعمال می‌شوند.
        $verifiedOldRates = [
            '27:1' => 1000000.0,   // زیرکونیا مولتی لیر
            '27:2' => 1000000.0,   // زیرکونیا پایه دندان / ایمپلنت
            '27:5' => 1700000.0,   // کاستوم اباتمنت
            '27:7' => 400000.0,    // PMMA
        ];

        // ۱) نرخِ تاریخی هر جفت: آخرین آیتمِ فاکتورِ کیس‌های قبل از cutoff
        $hist = $verifiedOldRates;
        $rows = db()->query("
            SELECT CASE WHEN c.case_type='lab_out' THEN c.lab_id ELSE c.outsourced_lab_id END AS lab_id,
                   CASE WHEN c.case_type='lab_out' THEN c.service_id ELSE c.outsourced_service_id END AS svc_id,
                   oi.unit_rate, c.received_date, oi.id AS item_id
            FROM outsource_invoice_items oi
            JOIN cases c ON c.id = oi.case_id
            WHERE c.received_date < " . $db->quote($cutoff) . "
              AND oi.unit_rate IS NOT NULL AND oi.unit_rate > 0
            ORDER BY c.received_date ASC, oi.id ASC
        ")->fetchAll();
        foreach ($rows as $r) {
            $k = (int) $r['lab_id'] . ':' . (int) $r['svc_id'];
            if (isset($verifiedOldRates[$k])) continue;   // مقدار تأییدشده مقدم است
            $hist[$k] = (float) $r['unit_rate'];   // آخرین (جدیدترین تاریخ) برنده است
        }

        // ۲) کیس‌های تاریخیِ فاکتورنشدهٔ بدون نرخ
        $cases = db()->query("
            SELECT c.id,
                   CASE WHEN c.case_type='lab_out' THEN c.lab_id ELSE c.outsourced_lab_id END AS lab_id,
                   CASE WHEN c.case_type='lab_out' THEN c.service_id ELSE c.outsourced_service_id END AS svc_id
            FROM cases c
            WHERE c.received_date < " . $db->quote($cutoff) . "
              AND c.outsourced_rate IS NULL
              AND (c.case_type = 'lab_out' OR (c.outsourced_lab_id IS NOT NULL AND c.outsourced_qty > 0))
              AND NOT EXISTS (SELECT 1 FROM outsource_invoice_items oi WHERE oi.case_id = c.id)
            ORDER BY c.id ASC
        ")->fetchAll();

        $upd = $db->prepare('UPDATE cases SET outsourced_rate = ?, updated_at = NOW() WHERE id = ?');

        $stats = ['fixed' => 0, 'unknown' => 0, 'pairs' => []];
        foreach ($cases as $c) {
            $k = (int) $c['lab_id'] . ':' . (int) $c['svc_id'];
            if (!isset($hist[$k])) {
                $stats['unknown']++;
                $stats['pairs'][$k] = ($stats['pairs'][$k] ?? 0) + 1;
                continue;
            }
            if (!$dryRun) { $upd->execute([$hist[$k], (int) $c['id']]); }
            $stats['fixed']++;
        }

        $log(sprintf('  [085] %d کیس تثبیت شد؛ %d کیس نرخِ تاریخی نامشخص دارد%s',
            $stats['fixed'], $stats['unknown'], $dryRun ? ' (dry-run)' : ''));
        foreach ($stats['pairs'] as $k => $n) {
            [$lab, $svc] = explode(':', $k);
            $live = getOutsourceRate((int) $lab, (int) $svc, null);
            $log(sprintf('        نامشخص: lab=%s svc=%s — %d کیس (نرخ فعلی: %s)',
                $lab, $svc, $n, $live === null ? '—' : number_format($live)));
        }

        return $stats;
    }
}

exolab_freeze_historic_outsource_rates('2026-10-23', !empty($GLOBALS['EXOLAB_REPRICE_DRY_RUN']));
