<?php
// panel/save_general_price.php
// ویرایش «قیمت عمومی» از نقشه‌ی قیمت — بدون ساخت رابطه در price_links.
//  • مدیر کل / شعبه‌ی مرکزی (۱) → قیمت مرجع کاتالوگِ خدمت (site_prices)
//  • مدیران سایر شعب → پیش‌فرض عمومیِ همان شعبه (branch_service_prices)
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'method']);
    exit;
}

$serviceId = (int) ($_POST['service_id'] ?? 0);
$priceRaw  = (string) ($_POST['price'] ?? '');
$priceRaw  = str_replace([',', '،', '٬', ' '], '', $priceRaw);
$price     = is_numeric($priceRaw) ? (float) $priceRaw : 0;

$chk = db()->prepare('SELECT id FROM site_prices WHERE id = ?');
$chk->execute([$serviceId]);
if (!$chk->fetch() || $price <= 0) {
    echo json_encode(['success' => false, 'message' => 'ورودی نامعتبر است.']);
    exit;
}

$effBranch = currentBranchId() ?? 1;
if ($effBranch === 1) {
    // شعبه‌ی مرکزی / مدیر کل → قیمت مرجع کاتالوگ (تأثیر بر همه‌ی شعب بدون پیش‌فرض خاص)
    $upd = db()->prepare('UPDATE site_prices SET price = ?, updated_at = NOW() WHERE id = ?');
    $upd->execute([$price, $serviceId]);
    audit_log('update_site_price', 'service', $serviceId, 'قیمت مرجع خدمت #' . $serviceId . ' به ' . number_format($price) . ' تغییر کرد');
} else {
    // سایر شعب → پیش‌فرض عمومیِ همان شعبه
    setBranchServiceCustomPriceFor($effBranch, $serviceId, $price);
    audit_log('update_branch_default', 'service', $serviceId, 'قیمت پیش‌فرض شعبه ' . $effBranch . ' خدمت #' . $serviceId . ' = ' . number_format($price));
}

echo json_encode(['success' => true]);
