<?php
/**
 * Database Migration Runner
 * 
 * Usage: php database/migrate.php
 * 
 * This script runs all .sql migration files in the database/migrations/ directory
 * in order. It tracks which migrations have been applied using a `_migrations`
 * tracking table.
 */

require_once __DIR__ . '/../panel/config.php';
require_once __DIR__ . '/../panel/db.php';

echo "============================================\n";
echo "  EXOLAB Database Migration Runner\n";
echo "============================================\n\n";

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
    echo "[✓] Migration tracking table ready.\n\n";
} catch (Throwable $e) {
    die("[✗] Failed to create tracking table: " . $e->getMessage() . "\n");
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

$count = 0;
foreach ($files as $file) {
    $filename = basename($file);
    
    if (in_array($filename, $applied)) {
        echo "[ ] Already applied: {$filename}\n";
        continue;
    }

    echo "[→] Applying: {$filename}... ";
    
    try {
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            echo "SKIPPED (empty file)\n";
            continue;
        }

        // Split by semicolons for multi-statement SQL
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s)
        );

        // Run each statement individually.
        // NOTE: DDL statements (CREATE TABLE, ALTER TABLE, etc.) in MySQL
        // cause an implicit COMMIT, so we cannot wrap them in a transaction.
        // We run them one by one and stop on first error.
        foreach ($statements as $statement) {
            // Skip pure comment lines
            if (preg_match('/^--/', $statement)) continue;
            if (str_starts_with($statement, 'DELIMITER')) continue;
            
            db()->exec($statement);
        }

        // Record migration
        $ins = db()->prepare("INSERT INTO `_migrations` (filename) VALUES (?)");
        $ins->execute([$filename]);
        
        echo "DONE ✓\n";
        $count++;
    } catch (Throwable $e) {
        echo "FAILED ✗\n";
        echo "  Error: " . $e->getMessage() . "\n\n";
        exit(1);
    }
}

echo "\n============================================\n";
if ($count === 0) {
    echo "  No new migrations to apply.\n";
} else {
    echo "  Applied {$count} migration(s) successfully.\n";
}
echo "============================================\n";
