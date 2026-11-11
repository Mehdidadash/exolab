<?php
/**
 * cron/backup_db.php — بکاپِ روزانهٔ دیتابیس
 *
 * ── چه می‌کند؟ ──
 *   ۱) از دیتابیس (DB_NAME) یک فایل SQL می‌گیرد و در `backups/db/` ذخیره می‌کند.
 *   ۲) اگر gzip در دسترس باشد، فایل را فشرده می‌کند (`....sql.gz`).
 *   ۳) بکاپ‌های قدیمی‌تر از N روز را پاک می‌کند (پیش‌فرض ۷ روز).
 *
 * ── چطور اجرا می‌شود؟ ──
 *   • CLI (کرون):        php cron/backup_db.php
 *   • وب (اگر کرون ندارید): https://site/cron/backup_db.php?key=BACKUP_KEY
 *     (BACKUP_KEY را در .env تنظیم کنید؛ اگر نبود از MIGRATE_KEY استفاده می‌شود)
 *
 * ── روشِ گرفتن بکاپ ──
 *   اول `mysqldump` را امتحان می‌کند (اگر exec فعال باشد و باینری پیدا شود)؛
 *   اگر نشد، با PHP و PDO خودش دامپ می‌گیرد (روی همهٔ هاست‌های اشتراکی کار می‌کند).
 *
 * ── امنیت ──
 *   پوشهٔ backups/db با .htaccess بسته است و پسوند .sql هم در .htaccess ریشه مسدود شده.
 *   فایل موقتِ اعتبارنامه (برای mysqldump) بلافاصله پاک می‌شود و رمز در لیستِ پروسه‌ها نمی‌رود.
 */

require_once __DIR__ . '/../panel/config.php';
require_once __DIR__ . '/../panel/db.php';   // فقط برای db() در روش دامپِ PHP

// ─────────── تنظیمات ───────────
$keepDays   = (int) \EnvLoader::get('BACKUP_KEEP_DAYS', '7');
if ($keepDays < 1) $keepDays = 7;
$prefix     = (string) \EnvLoader::get('BACKUP_FILE_PREFIX', DB_NAME);
if (trim($prefix) === '') $prefix = DB_NAME;
$prefix     = preg_replace('/[^A-Za-z0-9._-]+/', '_', $prefix);
$backupDir  = dirname(__DIR__) . '/backups/db';
$mysqldump  = \EnvLoader::get('MYSQLDUMP_PATH', '');   // اختیاری: مسیر دستی
// auto = اول mysqldump بعد PHP | mysqldump = فقط باینری | php = فقط دامپِ PHP
$method     = strtolower(trim((string) \EnvLoader::get('BACKUP_METHOD', 'auto')));
if (!in_array($method, ['auto', 'mysqldump', 'php'], true)) $method = 'auto';

// ─────────── دسترسی (فقط CLI یا با کلید) ───────────
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $expected = (string) \EnvLoader::get('BACKUP_KEY', MIGRATE_KEY);
    $given    = (string) ($_GET['key'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "دسترسی غیرمجاز\n";
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$log = function (string $msg) use ($isCli, $backupDir) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line, PHP_EOL;
    if (is_dir($backupDir)) {
        @file_put_contents($backupDir . '/backup.log', $line . PHP_EOL, FILE_APPEND);
    }
};

// ─────────── آماده‌سازی پوشه ───────────
if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    $log('✗ ساختِ پوشهٔ بکاپ ناموفق بود: ' . $backupDir);
    exit(1);
}
// محافظتِ وب از پوشه (اگر نبود)
$htaccess = $backupDir . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}

// ─────────── قفل (جلوگیری از اجرای هم‌زمان) ───────────
$lockFile = $backupDir . '/.backup.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('! یک بکاپ دیگر در حال اجراست؛ این اجرا لغو شد.');
    exit(0);
}

$tmpFile   = $backupDir . '/' . $prefix . '_backup.tmp';
$stamp     = date('Y-m-d_Hi');
$baseSql   = $backupDir . '/' . $prefix . '_' . $stamp . '.sql';
$finalFile = $baseSql . '.gz';

// ─────────── پیدا کردن mysqldump ───────────
$disableFns = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$execOk = $method !== 'php' && function_exists('exec') && !in_array('exec', $disableFns, true);

$mysqldumpCandidates = $mysqldump !== '' ? [$mysqldump] : [
    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    '/usr/bin/mysqldump',
    '/usr/local/bin/mysqldump',
    '/usr/local/mysql/bin/mysqldump',
];
$mysqldumpBin = '';
if ($execOk) {
    foreach ($mysqldumpCandidates as $cand) {
        if ($cand !== '' && @is_file($cand)) { $mysqldumpBin = $cand; break; }
    }
    if ($mysqldumpBin === '') {
        // مسیر پیش‌فرض سیستم (اگر در PATH باشد)
        $probe = @exec(PHP_OS_FAMILY === 'Windows' ? 'where mysqldump 2>NUL' : 'command -v mysqldump 2>/dev/null');
        if (is_string($probe) && $probe !== '' && @is_file(trim($probe))) {
            $mysqldumpBin = trim($probe);
        }
    }
}

$ok = false;

// ─────────── روش ۱: mysqldump ───────────
if ($mysqldumpBin !== '') {
    $cnfFile = $backupDir . '/.my.cnf.tmp';
    $cnf = "[client]\nuser=" . DB_USER . "\npassword=\"" . str_replace('"', '\\"', DB_PASS) . "\"\nhost=" . DB_HOST . "\n";
    @file_put_contents($cnfFile, $cnf);
    @chmod($cnfFile, 0600);

    $cmd = '"' . $mysqldumpBin . '"'
         . ' --defaults-extra-file="' . $cnfFile . '"'
         . ' --single-transaction --quick --skip-lock-tables --no-tablespaces'
         . ' --default-character-set=utf8mb4 --routines --triggers --events'
         . ' ' . escapeshellarg(DB_NAME)
         . ' > ' . escapeshellarg($baseSql) . ' 2>&1';

    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);
    @unlink($cnfFile);

    if ($code === 0 && @is_file($baseSql) && @filesize($baseSql) > 1024
        && strpos((string) @file_get_contents($baseSql, false, null, 0, 2048), 'CREATE TABLE') !== false) {
        $ok = true;
        $log('✓ بکاپ با mysqldump گرفته شد.');
    } else {
        $log('! mysqldump ناموفق بود (کد ' . $code . ') — سراغ روش PHP می‌رویم.');
        @unlink($baseSql);
    }
}

// ─────────── روش ۲: دامپ با PHP (بدون نیاز به exec) ───────────
if (!$ok) {
    try {
        $pdo = db();
        $fh = @fopen($baseSql, 'wb');
        if ($fh === false) throw new RuntimeException('cannot open dump file');
        fwrite($fh, "-- EXOLAB backup " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $t) {
            $name = (string) $t[0];
            $type = strtoupper((string) ($t[1] ?? 'BASE TABLE'));
            $quoted = '`' . str_replace('`', '``', $name) . '`';

            $createRow = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
            $create = (string) ($createRow[1] ?? '');
            if ($create === '') continue;   // جدول/ویو خوانده نشد

            fwrite($fh, $type === 'VIEW'
                ? "DROP VIEW IF EXISTS {$quoted};\n{$create};\n\n"
                : "DROP TABLE IF EXISTS {$quoted};\n{$create};\n\n");

            if ($type === 'VIEW') continue;

            $stmt = $pdo->query('SELECT * FROM ' . $quoted);
            $buffer = [];
            $cols = null;
            $flush = function () use (&$buffer, &$cols, $fh, $quoted) {
                if (!$buffer) return;
                $colSql = $cols !== null ? ' (' . implode(',', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $cols)) . ')' : '';
                fwrite($fh, "INSERT INTO {$quoted}{$colSql} VALUES\n" . implode(",\n", $buffer) . ";\n");
                $buffer = [];
            };
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) $cols = array_keys($row);
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = ($v === null) ? 'NULL' : $pdo->quote((string) $v);
                }
                $buffer[] = '(' . implode(',', $vals) . ')';
                if (count($buffer) >= 200) $flush();
            }
            $flush();
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        if (@filesize($baseSql) > 512) { $ok = true; $log('✓ بکاپ با PHP (PDO) گرفته شد.'); }
    } catch (Throwable $e) {
        $log('✗ دامپ با PHP شکست خورد: ' . $e->getMessage());
    }
}

if (!$ok) {
    @unlink($baseSql);
    $log('✗ بکاپ ناموفق بود؛ فایلی ذخیره نشد.');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

// ─────────── فشرده‌سازی ───────────
$savedFile = $baseSql;
if (function_exists('gzopen')) {
    $in = @fopen($baseSql, 'rb');
    $gz = @gzopen($finalFile, 'wb9');
    if ($in && $gz) {
        while (!feof($in)) {
            gzwrite($gz, (string) fread($in, 1048576));
        }
        gzclose($gz);
        fclose($in);
        @unlink($baseSql);
        $savedFile = $finalFile;
    } else {
        if ($gz) gzclose($gz);
        if ($in) fclose($in);
        @unlink($finalFile);
    }
}

$log(sprintf('✓ فایل بکاپ: %s (%s)', basename($savedFile), number_format((float) @filesize($savedFile) / 1024, 1) . ' KB'));

// ─────────── پاک‌سازی بکاپ‌های قدیمی ───────────
$limit = time() - ($keepDays * 86400);
$removed = 0;
foreach (glob($backupDir . '/' . $prefix . '_*.sql*') ?: [] as $file) {
    if (basename($file) === basename($savedFile)) continue;
    $mtime = @filemtime($file);
    if ($mtime !== false && $mtime < $limit) {
        if (@unlink($file)) { $removed++; }
    }
}
$log(sprintf('✓ نگهداری: %d روز — %d فایل قدیمی حذف شد.', $keepDays, $removed));

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
