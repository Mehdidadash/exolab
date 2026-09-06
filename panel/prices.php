<?php
// panel/prices.php
// صفحه‌ی یکپارچه قیمت‌ها:
//   تب ۱ «قیمت‌های عمومی (پیش‌فرض)» = CRUD روی site_prices
//   تب ۲ «نقشه و نرخ‌های اختصاصی»    = گراف نقشه + جدول یکپارچه price_links
require_once __DIR__ . '/auth.php';
require_admin();

$tab = $_GET['tab'] ?? 'general';
if (!in_array($tab, ['general', 'map'], true)) $tab = 'general';

panel_layout_start('قیمت‌ها');
?>
<style>
    .pm-tabs { display:flex; gap:8px; margin-bottom:18px; border-bottom:2px solid #e5e7eb; flex-wrap:wrap; }
    .pm-tabs a { padding:10px 18px; border-radius:10px 10px 0 0; text-decoration:none; font-weight:600; color:#475569; background:#f8fafc; border:1px solid #e5e7eb; border-bottom:none; }
    .pm-tabs a.active { background:#0F172A; color:#fff; border-color:#0F172A; }
    .pm-tabs a:not(.active):hover { background:#eef2f7; }
</style>
<div class="pm-tabs">
    <a href="prices.php?tab=general" class="<?= $tab === 'general' ? 'active' : '' ?>">قیمت‌های عمومی (پیش‌فرض)</a>
    <a href="prices.php?tab=map" class="<?= $tab === 'map' ? 'active' : '' ?>">نقشه و نرخ‌های اختصاصی</a>
</div>
<?php
require __DIR__ . ($tab === 'map' ? '/price_map_tab.php' : '/prices_general_tab.php');
panel_layout_end();
