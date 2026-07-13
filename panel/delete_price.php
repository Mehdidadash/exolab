<?php
// panel\delete_price.php

require_once __DIR__ . '/auth.php';
require_role('admin');

require_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    $id = (int) $_POST['id'];
    audit_log_delete('price', $id, 'قیمت');
    $stmt = db()->prepare('DELETE FROM site_prices WHERE id = ?');
    $stmt->execute([$id]);
}

header('Location: prices.php');
exit;
