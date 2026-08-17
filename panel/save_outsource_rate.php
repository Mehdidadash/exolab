<?php
// panel/save_outsource_rate.php
require_once __DIR__ . '/auth.php';
require_role('admin');
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: outsource_rates.php');
    exit;
}

$lab_id = (int) ($_POST['lab_id'] ?? 0);
$service_id = (int) ($_POST['service_id'] ?? 0);
$rate = (float) ($_POST['rate'] ?? 0);

if (!$lab_id || !$service_id || $rate < 0) {
    header('Location: outsource_rates.php?error=invalid');
    exit;
}

$lab = db()->prepare("SELECT id FROM users WHERE id=? AND role IN ('outsource_lab','partner_lab','customer_lab','lab')");
$lab->execute([$lab_id]);
if (!$lab->fetch()) {
    header('Location: outsource_rates.php?error=invalid');
    exit;
}

saveOutsourceRate($lab_id, $service_id, $rate);
header('Location: outsource_rates.php?saved=1');
exit;
