<?php
// panel/save_price_link.php
// Create / update a price-link (Ø±Ø§Ø¨Ø·Ù‡ Ù‚ÛŒÙ…ØªÛŒ) in the unified price map.
// Dual-write: the legacy billing tables are kept in sync so invoice/cost logic
// (getApplicablePrice, getOutsourceRate, design fees) stays untouched.
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: prices.php?tab=map');
    exit;
}

$id           = (int) ($_POST['id'] ?? 0);
$serviceId    = (int) ($_POST['service_id'] ?? 0);
$providerType = (string) ($_POST['provider_type'] ?? '');
$providerId   = (int) ($_POST['provider_id'] ?? 0);
$receiverType = (string) ($_POST['receiver_type'] ?? '');
$receiverId   = (int) ($_POST['receiver_id'] ?? 0);
$priceRaw     = (string) ($_POST['price'] ?? '');
$priceType    = in_array($_POST['price_type'] ?? '', ['general', 'specific', 'outsource', 'design_fee', 'branch_default'], true) ? $_POST['price_type'] : 'general';
$branchId     = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null;
$note         = trim((string) ($_POST['note'] ?? ''));

// Accept thousand separators (western comma, Persian Ù¬ØŒ, spaces)
$priceRaw = str_replace([',', 'ØŒ', 'Ù¬', ' '], '', $priceRaw);
$price    = is_numeric($priceRaw) ? (float) $priceRaw : 0;

$validTypes = ['branch', 'lab', 'doctor', 'designer'];
$ok = $price > 0
    && in_array($providerType, $validTypes, true) && $providerId > 0
    && in_array($receiverType, $validTypes, true)
    && !($providerType === $receiverType && $providerId === $receiverId);
// service_id Ø¨Ø±Ø§ÛŒ Ù‡Ù…Ù‡ Ø§Ø¬Ø¨Ø§Ø±ÛŒ Ø§Ø³ØªØ› ÙÙ‚Ø· Â«Ù†Ø±Ø® Ú©Ù„ÛŒ Ø·Ø±Ø§Ø­ÛŒÂ» Ù…ÛŒâ€ŒØªÙˆØ§Ù†Ø¯ Ø¨Ø¯ÙˆÙ† Ø®Ø¯Ù…Øª Ø¨Ø§Ø´Ø¯
if ($serviceId <= 0 && $priceType !== 'design_fee') {
    $ok = false;
}
// receiver ÙÙ‚Ø· Ø¨Ø±Ø§ÛŒ Â«Ù¾ÛŒØ´â€ŒÙØ±Ø¶ Ø´Ø¹Ø¨Ù‡Â» Ù…ÛŒâ€ŒØªÙˆØ§Ù†Ø¯ Ø®Ø§Ù„ÛŒ Ø¨Ø§Ø´Ø¯ (Ú¯ÛŒØ±Ù†Ø¯Ù‡ Ù…Ø´Ø®ØµÛŒ Ù†Ø¯Ø§Ø±Ø¯)
if ($priceType !== 'branch_default' && $receiverId <= 0) {
    $ok = false;
}
// Â«Ù¾ÛŒØ´â€ŒÙØ±Ø¶ Ø´Ø¹Ø¨Ù‡Â» Ø¨Ø§ÛŒØ¯ Ø§Ø±Ø§Ø¦Ù‡â€ŒØ¯Ù‡Ù†Ø¯Ù‡â€ŒØ§Ø´ ÛŒÚ© Ø´Ø¹Ø¨Ù‡ Ø¨Ø§Ø´Ø¯
if ($priceType === 'branch_default' && $providerType !== 'branch') {
    $ok = false;
}

if (!$ok) {
    header('Location: prices.php?tab=map?error=1');
    exit;
}

$note   = mb_substr($note, 0, 255);
$svcDb  = $serviceId > 0 ? $serviceId : null;
$recvDb = ($priceType === 'branch_default' || $receiverId <= 0) ? null : $receiverId;

if ($id) {
    $stmt = db()->prepare('UPDATE price_links
        SET service_id = ?, provider_type = ?, provider_id = ?, receiver_type = ?, receiver_id = ?,
            price = ?, price_type = ?, branch_id = ?, note = ?, updated_at = NOW()
        WHERE id = ?');
    $stmt->execute([$svcDb, $providerType, $providerId, $receiverType, $recvDb, $price, $priceType, $branchId, $note, $id]);
} else {
    $stmt = db()->prepare('INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$svcDb, $providerType, $providerId, $receiverType, $recvDb, $price, $priceType, $branchId, $note]);
    $id = (int) db()->lastInsertId();
}

// Ù‡Ù…Ú¯Ø§Ù…â€ŒØ³Ø§Ø²ÛŒ Ø¨Ø§ Ø¬Ø¯ÙˆÙ„â€ŒÙ‡Ø§ÛŒ Ù‚Ø¯ÛŒÙ…ÛŒ ØªØ§ ÙØ§Ú©ØªÙˆØ±/Ø¯Ø±Ø¢Ù…Ø¯/Ù‡Ø²ÛŒÙ†Ù‡ Ø¨Ø¯ÙˆÙ† ØªØºÛŒÛŒØ± Ú©Ø§Ø± Ú©Ù†Ù†Ø¯
syncLegacyFromPriceLink([
    'id'            => $id,
    'service_id'    => $svcDb,
    'provider_type' => $providerType,
    'provider_id'   => $providerId,
    'receiver_type' => $receiverType,
    'receiver_id'   => $recvDb,
    'price'         => $price,
    'price_type'    => $priceType,
    'branch_id'     => $branchId,
    'note'          => $note,
]);

header('Location: prices.php?tab=map');
exit;
