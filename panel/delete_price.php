<?php
// panel\delete_price.php

require_once __DIR__ . '/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    $stmt = db()->prepare('DELETE FROM site_prices WHERE id = ?');
    $stmt->execute([(int) $_POST['id']]);
}

header('Location: prices.php');
exit;
