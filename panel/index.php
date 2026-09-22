<?php
/**
 * panel/index.php
 * ورود به پوشهٔ پنل: exolab.ir/panel یا exolab.ir/panel/
 *   • اگر کاربر وارد شده باشد → داشبورد (dashboard.php)
 *   • اگر وارد نشده باشد → require_login() او را به login.php می‌برد و پس از
 *     ورود، دوباره همین آدرس باز می‌شود و به داشبورد می‌رسد.
 *
 * بدون این فایل، آدرس /panel روی هاست (به‌خاطر Options -Indexes) خطای 403 می‌داد.
 */
require_once __DIR__ . '/auth.php';
require_login();

// اگر کاربر مستقیم به index.php آمده باشد، آدرس را تمیز و به داشبورد منتقل می‌کنیم
$target = 'dashboard.php';
if (!headers_sent()) {
    header('Location: ' . $target, true, 302);
    exit;
}
// احتیاط: اگر هدر ارسال شده بود، با متا ریدایرکت
echo '<!DOCTYPE html><html lang="fa"><head><meta charset="utf-8">'
   . '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES) . '">'
   . '<title>انتقال به داشبورد</title></head><body style="font-family:Tahoma,sans-serif;text-align:center;padding:40px;">'
   . '<p>در حال انتقال به <a href="' . htmlspecialchars($target, ENT_QUOTES) . '">داشبورد</a>…</p>'
   . '</body></html>';
exit;
