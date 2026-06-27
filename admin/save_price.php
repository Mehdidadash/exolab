<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: prices.php');
    exit;
}

$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$price = trim($_POST['price'] ?? '');
$category = trim($_POST['category'] ?? '');
$active = isset($_POST['active']) && $_POST['active'] === '1' ? 1 : 0;
$display_order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;

if ($title === '' || $description === '' || $price === '') {
    header('Location: price_form.php?error=missing');
    exit;
}

if (!empty($_POST['id'])) {
    $stmt = db()->prepare('UPDATE site_prices SET title = ?, description = ?, price = ?, category = ?, active = ?, display_order = ? WHERE id = ?');
    $stmt->execute([$title, $description, $price, $category, $active, $display_order, (int) $_POST['id']]);
} else {
    $stmt = db()->prepare('INSERT INTO site_prices (title, description, price, category, active, display_order) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $description, $price, $category, $active, $display_order]);
}

header('Location: prices.php');
exit;
