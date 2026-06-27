<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

// Accept JSON body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data) || empty($data['table']) || empty($data['ids']) || !is_array($data['ids'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'invalid_input']);
    exit;
}

$allowed = ['portfolio_works' => 'portfolio_works', 'site_prices' => 'site_prices'];
$table = $data['table'];
if (!isset($allowed[$table])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'invalid_table']);
    exit;
}

$ids = array_map('intval', $data['ids']);
try {
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE {$allowed[$table]} SET display_order = ? WHERE id = ?");
    foreach ($ids as $index => $id) {
        $order = $index + 1;
        $stmt->execute([$order, $id]);
    }
    $pdo->commit();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
