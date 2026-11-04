<?php
// panel/prices.php
// صفحه‌ی یکپارچه قیمت‌ها:
//   تب ۱ «قیمت‌های عمومی (پیش‌فرض)» = CRUD روی site_prices
//   تب ۲ «نقشه و نرخ‌های اختصاصی»    = گراف نقشه + جدول یکپارچه price_links
//   تب ۳..۷ «نرخ اختصاصی»            = طراحان / پزشکان / کلینیک‌ها / لابراتوارها / بین‌شعبه‌ای
//                                      (قیمت اختصاصیِ همهٔ خدمات برای یک طرف، در یک صفحه)
require_once __DIR__ . '/auth.php';
require_admin();

$tab = $_GET['tab'] ?? 'general';
if (!in_array($tab, ['general', 'map', 'designers', 'doctors', 'clinics', 'labs', 'branches'], true)) $tab = 'general';

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
    <a href="prices.php?tab=designers" class="<?= $tab === 'designers' ? 'active' : '' ?>">نرخ طراحی طراحان</a>
    <a href="prices.php?tab=doctors" class="<?= $tab === 'doctors' ? 'active' : '' ?>">نرخ پزشکان</a>
    <a href="prices.php?tab=clinics" class="<?= $tab === 'clinics' ? 'active' : '' ?>">نرخ کلینیک‌ها</a>
    <a href="prices.php?tab=labs" class="<?= $tab === 'labs' ? 'active' : '' ?>">نرخ لابراتوارها</a>
    <a href="prices.php?tab=branches" class="<?= $tab === 'branches' ? 'active' : '' ?>">نرخ بین‌شعبه‌ای</a>
    <a href="price_list_pdf.php?source=<?= htmlspecialchars($tab === 'general' || $tab === 'map' ? 'general' : $tab) ?>"
       style="margin-inline-start:auto; background:#16a34a; color:#fff; border-color:#16a34a;">📄 لیست قیمت PDF</a>
</div>
<?php
// تب‌های «نرخ اختصاصی» همه از یک رندرکنندهٔ مشترک استفاده می‌کنند (AJAX + دکمهٔ ذخیرهٔ هر ردیف)
$linkTabs = ['designers', 'doctors', 'clinics', 'labs', 'branches'];
if (in_array($tab, $linkTabs, true)) {
    require __DIR__ . '/prices_links_tab.php';
} else {
    $tabFiles = [
        'map'     => '/price_map_tab.php',
        'general' => '/prices_general_tab.php',
    ];
    require __DIR__ . $tabFiles[$tab];
}
panel_layout_end();
