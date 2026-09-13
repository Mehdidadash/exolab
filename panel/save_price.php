<?php
// panel\save_price.php

require_once __DIR__ . '/auth.php';
require_admin();

require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: prices.php');
    exit;
}

// Detect AJAX (inline editing on prices.php) so we can return JSON.
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$price = trim($_POST['price'] ?? '');
$category = trim($_POST['category'] ?? '');
$active = isset($_POST['active']) && $_POST['active'] === '1' ? 1 : 0;
$hideOnSiteProvided = array_key_exists('hide_on_site', $_POST);
$hideOnSite = $hideOnSiteProvided ? ((isset($_POST['hide_on_site']) && $_POST['hide_on_site'] === '1') ? 1 : 0) : 0;
$display_order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;

if ($title === '' || $price === '') {
    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'missing', 'message' => 'عنوان و قیمت الزامی است.']);
        exit;
    }
    header('Location: price_form.php?error=missing');
    exit;
}

if (!empty($_POST['id'])) {
    $stmt = db()->prepare('UPDATE site_prices SET title = ?, description = ?, price = ?, category = ?, active = ?, display_order = ?, hide_on_site = COALESCE(?, hide_on_site) WHERE id = ?');
    $stmt->execute([$title, $description, $price, $category, $active, $display_order, ($hideOnSiteProvided ? $hideOnSite : null), (int) $_POST['id']]);
} else {
    $stmt = db()->prepare('INSERT INTO site_prices (title, description, price, category, active, display_order, hide_on_site) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $description, $price, $category, $active, $display_order, $hideOnSite]);
}

$savedId = !empty($_POST['id']) ? (int) $_POST['id'] : (int) db()->lastInsertId();
audit_log_save('price', $savedId, 'قیمت');

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'id' => $savedId]);
    exit;
}

header('Location: prices.php');
exit;
