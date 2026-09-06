<?php
/**
 * Database Migration Runner — EXOLAB
 *
 * Usage:
 *   CLI:        php database/migrate.php
 *   Web:        https://yourhost/exolab/database/migrate.php?key=YOUR_MIGRATE_KEY
 *
 * Runs every .sql file in database/migrations/ in order (alphabetical).
 * Applied files are tracked in the `_migrations` table so the script is safe
 * to run more than once. On a fresh database it applies ALL migrations; on an
 * existing database it only applies the pending ones.
 *
 * Web mode is disabled unless MIGRATE_KEY is set in config.php / .env.
 * This lets you run migrations ONCE on shared hosting that has no SSH/CLI,
 * without pasting each SQL file into phpMyAdmin one by one.
 */

require_once __DIR__ . '/../panel/config.php';
require_once __DIR__ . '/../panel/db.php';

// ── Detect CLI vs Web ──
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    $key = (string) (MIGRATE_KEY ?? '');
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="fa"><meta charset="utf-8"><body style="font-family:Tahoma;padding:40px;text-align:center;">'
           . '<h3>دسترسی غیرمجاز</h3><p>برای اجرای مهاجرت‌ها از طریق وب، آدرس را با کلید صحیح باز کنید.</p>'
           . '<p>متغیر <code>MIGRATE_KEY</code> باید در <code>.env</code> تنظیم شده باشد.</p></body></html>';
        exit;
    }
}

function out(string $msg, string $type = 'info'): void {
    global $isCli;
    if ($isCli) {
        echo $msg . "\n";
    } else {
        $color = match ($type) {
            'ok'   => '#16a34a',
            'err'  => '#dc2626',
            'skip' => '#6b7280',
            default => '#0f172a',
        };
        echo '<div style="padding:6px 10px; border-radius:6px; background:#f9fafb; margin:4px 0; color:' . $color . '; font-family:monospace;">'
           . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa"><head><meta charset="utf-8"><title>EXOLAB Migration</title></head><body style="font-family:Tahoma;padding:20px;max-width:820px;margin:auto;">';
    echo '<h2 style="margin-top:0;">اجرای مهاجرت‌های دیتابیس</h2>';
}

out("============================================", 'info');
out("  EXOLAB Database Migration Runner", 'info');
out("============================================", 'info');
out("", 'info');

// Ensure tracking table exists
try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS `_migrations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `filename` VARCHAR(255) NOT NULL,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_migration_filename` (`filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out("[✓] Migration tracking table ready.", 'ok');
    out("", 'info');
} catch (Throwable $e) {
    out("[✗] Failed to create tracking table: " . $e->getMessage(), 'err');
    if (!$isCli) echo '</body></html>';
    exit(1);
}

// Get applied migrations
$applied = [];
$stmt = db()->query("SELECT filename FROM `_migrations` ORDER BY id ASC");
while ($row = $stmt->fetch()) {
    $applied[] = $row['filename'];
}

// Scan migration files
$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files); // alphabetical = chronological order

// ── تطبیق خودکار (Reconcile) ─────────────────────────────────────────────
// اگر دیتابیسی را برگردانده باشیم که اسکیمای یک مهاجرت را «از قبل» دارد ولی
// ردیفِ آن در _migrations نیست (مثلاً نسخه‌ی قدیمیِ دیتابیس با _migrations ناقص)،
// به‌جای اجرا و خطای «Duplicate column/table»، آن مهاجرت را «اعمال‌شده» علامت
// می‌زنیم. هر مهاجرت یک «اثرِ شناسه» دارد: ? = نام دیتابیس (DB_NAME).
// مهاجرت‌هایی که check ندارند (مثل 030 و 032 که فقط UPDATE‌های idempotent‌اند)
// به‌صورت عادی اجرا می‌شوند.
$ALREADY_CHECKS = [
    '008_add_designer.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND COLUMN_NAME='is_designer'",
    '009_create_notifications.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='notifications'",
    '010_add_receipt_number.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='cases' AND COLUMN_NAME='receipt_number'",
    '011_lab_billing.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='lab_price_overrides'",
    '012_add_entity_comments.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='entity_comments'",
    '013_create_notifications.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='notifications'",
    '014_case_design_fee_and_price_override_type.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='doctor_price_overrides' AND COLUMN_NAME='price_type'",
    '015_designer_case_scope.sql' => "SELECT 1 FROM roles WHERE name='designer' AND permissions LIKE '%view_assigned_cases%'",
    '016_designer_view_all_cases_and_user_uploads.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='user_uploads'",
    '017_doctor_create_cases_and_designer_case_scope.sql' => "SELECT 1 FROM roles WHERE name='doctor' AND permissions LIKE '%view_own_cases%'",
    '018_designer_invoices.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='designer_invoices'",
    '019_doctor_gallery.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='doctor_gallery'",
    '020_label_marking_and_outsourcing.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='cases' AND COLUMN_NAME='label_printed_at'",
    '021_side_outsourcing.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='cases' AND COLUMN_NAME='outsourced_lab_id'",
    '022_outsourced_rate.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='outsource_rates'",
    '023_file_descriptions.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='case_files' AND COLUMN_NAME='description'",
    '024_branches.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='branches'",
    '025_branch_admin_role.sql' => "SELECT 1 FROM roles WHERE name='branch_admin'",
    '026_branch_ownership.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='bank_accounts' AND COLUMN_NAME='branch_id'",
    '027_doctor_lab_affiliation.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND COLUMN_NAME='clinic_id'",
    '028_expense_payments.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='expense_payments'",
    '029_shared_prices_and_default_designer.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='branch_service_prices'",
    '031_branch_receivables.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='branch_receivables'",
    '033_central_lab_user.sql' => "SELECT 1 FROM users WHERE username='central_lab'",
    '034_fix_qazvin_outsource_cases.sql' => "SELECT 1 FROM users WHERE username='central_admin'",
    '035_fix_outsource_rates_unique.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='outsource_rates' AND INDEX_NAME='lab_id' AND SEQ_IN_INDEX=3",
    '036_price_links.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='price_links'",
    '037_unify_price_links.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='price_links' AND COLUMN_NAME='branch_id'",
    '038_case_files_uploader.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='case_files' AND COLUMN_NAME='uploader_id'",
    '039_case_files_file_type.sql' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='case_files' AND COLUMN_NAME='file_type'",
    '040_role_permissions.sql' => "SELECT 1 FROM roles WHERE name='designer' AND permissions LIKE '%view_own_invoices%'",
    '041_case_activity_log.sql' => "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='case_activity_log'",
    '042_fix_design_fees.sql' => "SELECT 1 FROM cases WHERE patient_name='خانوم حسینی' AND received_date='2026-08-06' AND doctor_id=3 AND design_fee=600000",
    '043_fix_design_fee_1126.sql' => "SELECT 1 FROM cases WHERE patient_name='نیلوفر زمانی' AND received_date='2026-08-16' AND doctor_id=22 AND design_fee=160000",
    '044_fix_design_fee_1141_1159.sql' => "SELECT 1 FROM cases WHERE patient_name='علیرضا آتشین' AND received_date='2026-08-26' AND doctor_id=16 AND design_fee=480000",
];

$count = 0;
foreach ($files as $file) {
    $filename = basename($file);

    if (in_array($filename, $applied)) {
        out("[ ] Already applied: {$filename}", 'skip');
        continue;
    }

    // تطبیق خودکار: اگر اثرِ مهاجرت از قبل موجود باشد، بدون اجرا علامت می‌زنیم
    if (isset($ALREADY_CHECKS[$filename])) {
        try {
            $chk = db()->prepare($ALREADY_CHECKS[$filename]);
            $chk->execute([DB_NAME]);
            if ($chk->fetchColumn()) {
                $ins = db()->prepare("INSERT INTO `_migrations` (filename) VALUES (?)");
                $ins->execute([$filename]);
                out("[✓] Already present in DB (reconciled): {$filename}", 'skip');
                $count++;
                continue;
            }
        } catch (Throwable $e) {
            // اگر چک خطا داد، به‌صورت عادی اجرا شود
        }
    }

    out("[→] Applying: {$filename} ...", 'info');

    try {
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            out("    SKIPPED (empty file)", 'skip');
            continue;
        }

        // Split by semicolons for multi-statement SQL
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s)
        );

        // Run each statement individually.
        // DDL (CREATE/ALTER TABLE) causes an implicit COMMIT in MySQL, so we
        // cannot wrap them in a transaction; we run them one by one.
        foreach ($statements as $statement) {
            // Remove full-line comments (-- ...) and DELIMITER lines, then run
            // the remaining SQL. Older files start with a comment block before
            // the first real statement — without stripping it, that first
            // statement would be skipped entirely (the old bug at migration 008).
            $lines = preg_split('/\r?\n/', $statement);
            $clean = [];
            foreach ($lines as $ln) {
                $t = trim($ln);
                if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, 'DELIMITER')) {
                    continue;
                }
                $clean[] = $ln;
            }
            $cleanSql = trim(implode("\n", $clean));
            if ($cleanSql === '') {
                continue;
            }
            try {
                db()->exec($cleanSql);
            } catch (\PDOException $e) {
                // خطاهای «از قبل وجود دارد» (جدول/ستون/ایندکس تکراری) را تحمل کن؛
                // هر خطای دیگری متوقف‌کننده است.
                $myCode = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($myCode, [1050, 1060, 1061], true)) {
                    out("        ~ already exists (ignored)", 'skip');
                    continue;
                }
                throw $e;
            }
        }

        // Record migration
        $ins = db()->prepare("INSERT INTO `_migrations` (filename) VALUES (?)");
        $ins->execute([$filename]);

        out("    DONE ✓", 'ok');
        $count++;
    } catch (Throwable $e) {
        out("    FAILED ✗", 'err');
        out("    Error: " . $e->getMessage(), 'err');
        out("", 'info');
        if (!$isCli) echo '</body></html>';
        exit(1);
    }
}

out("", 'info');
out("============================================", 'info');
if ($count === 0) {
    out("  No new migrations to apply.", 'info');
} else {
    out("  Applied {$count} migration(s) successfully.", 'ok');
}
out("============================================", 'info');

if (!$isCli) {
    echo '<p style="margin-top:16px;"><a href="../index.php" style="color:#0f172a;">بازگشت به سایت</a></p>';
    echo '</body></html>';
}
