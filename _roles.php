<?php
require __DIR__ . '/panel/config.php';
$p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ($p->query('SELECT name, label, permissions FROM roles ORDER BY id') as $r) {
    echo "== {$r['name']} ({$r['label']}) ==\n" . ($r['permissions'] ?? '(null)') . "\n";
}
