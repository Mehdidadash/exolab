<?php
/**
 * includes/deploy_guard.php
 *
 * ابزارِ کوچکِ «لاگِ مهارشده» برای محافظ‌های دیپلوی.
 *
 * چرا؟ هنگام آپلودِ بستهٔ ناقص (چند ثانیه/دقیقه)، هر درخواستِ بازدیدکننده یک خطِ
 * error_log می‌ساخت و error_log از هزاران خط پر می‌شد (نمونهٔ واقعی: ~۲۰۰ خط در ۳ دقیقه).
 * این تابع هر «کلید» را حداکثر یک‌بار در هر بازه (پیش‌فرض ۵ دقیقه) لاگ می‌کند.
 *
 * ⚠️ این فایل نباید به config/db/helpers وابسته باشد؛ چون همان لحظه‌ای اجرا می‌شود که
 * ممکن است آن فایل‌ها روی سرور موجود نباشند.
 */

if (!function_exists('exolab_log_throttled')) {
    /**
     * @param string $key     کلیدِ یکتا برای هر نوع پیام (مثل 'index_missing_files')
     * @param string $message متنِ پیام (فقط وقتی بازه گذشته باشد نوشته می‌شود)
     * @param int    $window  بازهٔ مهار به ثانیه (پیش‌فرض ۳۰۰ = ۵ دقیقه)
     */
    function exolab_log_throttled(string $key, string $message, int $window = 300): void
    {
        $safeKey = preg_replace('/[^a-z0-9_\-]+/i', '_', $key) ?: 'guard';
        $stamp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'exolab_log_' . $safeKey . '.stamp';

        $last = @filemtime($stamp);
        if ($last !== false && (time() - (int) $last) < $window) {
            return;   // در همین بازه قبلاً لاگ شده است
        }
        @touch($stamp);

        error_log($message . ' (این پیام حداکثر هر ' . max(1, (int) floor($window / 60)) . ' دقیقه یک‌بار لاگ می‌شود)');
    }
}
