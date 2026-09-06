<?php
// panel/delete_price_link.php
// Delete a price-link from the price map.
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: prices.php?tab=map');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    $row = db()->prepare('SELECT * FROM price_links WHERE id = ?');
    $row->execute([$id]);
    $link = $row->fetch();
    if ($link) {
        // Ø­Ø°Ù Ù‡Ù…Ø§Ù‡Ù†Ú¯ Ø§Ø² Ø¬Ø¯ÙˆÙ„â€ŒÙ‡Ø§ÛŒ Ù‚Ø¯ÛŒÙ…ÛŒ ØªØ§ ÙØ§Ú©ØªÙˆØ±/Ù‡Ø²ÛŒÙ†Ù‡ Ø¯ÙˆØ¨Ø§Ø±Ù‡ Ù‡Ù…Ø§Ù† Ù†Ø±Ø® Ø±Ø§ Ù†Ø®ÙˆØ§Ù†Ù†Ø¯
        unsyncLegacyFromPriceLink($link);
        db()->prepare('DELETE FROM price_links WHERE id = ?')->execute([$id]);
    }
}
header('Location: prices.php?tab=map');
exit;
