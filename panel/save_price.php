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
// نام اختصاری خدمت (اختیاری) — فقط وقتی در فرم فرستاده شده باشد تغییر می‌کند
$shortNameProvided = array_key_exists('short_name', $_POST);
$shortName = '';
if ($shortNameProvided) {
    $shortName = preg_replace('/\s+/u', ' ', trim(strip_tags((string) $_POST['short_name']))) ?? '';
    $shortName = mb_substr($shortName, 0, 24, 'UTF-8');
}
$description = trim($_POST['description'] ?? '');
$price = trim($_POST['price'] ?? '');
$category = trim($_POST['category'] ?? '');
$active = isset($_POST['active']) && $_POST['active'] === '1' ? 1 : 0;
$hideOnSiteProvided = array_key_exists('hide_on_site', $_POST);
$hideOnSite = $hideOnSiteProvided ? ((isset($_POST['hide_on_site']) && $_POST['hide_on_site'] === '1') ? 1 : 0) : 0;
$display_order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;

// قیمت‌گذاری خدمت: تعداد دستی + قیمت پله‌ای (فقط وقتی در فرم فرستاده شده باشند تغییر می‌کنند)
$qtyManualProvided = array_key_exists('qty_manual', $_POST);
$qtyManual = $qtyManualProvided ? (((string) $_POST['qty_manual'] === '1') ? 1 : 0) : null;
$baseUnitsProvided = array_key_exists('base_units', $_POST);
$baseUnits = $baseUnitsProvided ? max(1, (int) $_POST['base_units']) : null;
$extraProvided = array_key_exists('extra_unit_price', $_POST);
$extraUnitPrice = null;
if ($extraProvided) {
    $rawExtra = trim((string) $_POST['extra_unit_price']);
    $extraUnitPrice = ($rawExtra === '') ? null : max(0, (float) $rawExtra);
}

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
    // فقط فیلدهایی که فرستاده شده‌اند تغییر می‌کنند (COALESCE برای مقدارهای حفظ‌شدنی)
    $setParts = [
        'title = ?', 'short_name = COALESCE(?, short_name)', 'description = ?', 'price = ?',
        'category = ?', 'active = ?', 'display_order = ?', 'hide_on_site = COALESCE(?, hide_on_site)',
    ];
    $params = [$title, ($shortNameProvided ? $shortName : null), $description, $price, $category, $active, $display_order, ($hideOnSiteProvided ? $hideOnSite : null)];
    if ($qtyManualProvided)  { $setParts[] = 'qty_manual = ?';       $params[] = $qtyManual; }
    if ($baseUnitsProvided)  { $setParts[] = 'base_units = ?';       $params[] = $baseUnits; }
    if ($extraProvided)      { $setParts[] = 'extra_unit_price = ?'; $params[] = $extraUnitPrice; }
    $params[] = (int) $_POST['id'];
    $stmt = db()->prepare('UPDATE site_prices SET ' . implode(', ', $setParts) . ' WHERE id = ?');
    $stmt->execute($params);
} else {
    $stmt = db()->prepare('INSERT INTO site_prices (title, short_name, description, price, category, active, display_order, hide_on_site, qty_manual, base_units, extra_unit_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $title, ($shortName === '' ? null : $shortName), $description, $price, $category, $active, $display_order, $hideOnSite,
        ($qtyManualProvided ? $qtyManual : 0),
        ($baseUnitsProvided ? $baseUnits : 1),
        ($extraProvided ? $extraUnitPrice : null),
    ]);
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
