<?php
// includes/upload_error_modal.php
// ── مودالِ «خطای آپلود» به‌صورتِ مسدودکننده (blocking) ──
//
// درخواست کاربر: اگر آپلود (در صفحهٔ کیس‌ها، مشاهدهٔ کیس یا آپلود) به هر دلیلی
// ناموفق شد، خطا باید **حتماً** دیده شود و کاربر نتواند بی‌توجه از آن رد شود —
// حتی اگر بخواهد تب را ببندد، اول باید خطا را تأیید کند.
//
// راهکار:
//   • مودالِ تمام‌صفحه و غیرقابل‌بستن (بدون ✕، بدون کلیک بیرون، Escape کار نمی‌کند).
//   • فقط با دکمهٔ «متوجه شدم» بسته می‌شود.
//   • تا وقتی باز است، اسکرولِ صفحه قفل و فوکوس داخل مودال نگه داشته می‌شود (focus trap).
//   • هنگام تلاش برای بستن/رفرش/خروج، مرورگر دیالوگِ «تغییرات ذخیره نشده؟» را نشان
//     می‌دهد (beforeunload) تا کاربر نتواند بی‌توجه تب را ببندد.
//
// استفاده (در هر صفحه‌ای که آپلود دارد):
//   require_once __DIR__ . '/../includes/upload_error_modal.php';
//   ... سپس در JS:  window.UploadError.show({ title, message, details: [...] })
if (!defined('EXOLAB_UPLOAD_ERROR_MODAL')) {
    define('EXOLAB_UPLOAD_ERROR_MODAL', true);
?>
<div id="upload-error-modal" class="uerr-overlay" role="alertdialog" aria-modal="true"
     aria-labelledby="uerr-title" aria-describedby="uerr-message" style="display:none;">
    <div class="uerr-box">
        <div class="uerr-icon" aria-hidden="true">⚠️</div>
        <h3 id="uerr-title" class="uerr-title">آپلود ناموفق بود</h3>
        <p id="uerr-message" class="uerr-message"></p>
        <ul id="uerr-details" class="uerr-details"></ul>
        <p class="uerr-hint">
            تا وقتی این خطا را تأیید نکنید، صفحه باز می‌ماند. اگر مشکل ادامه داشت،
            حجم فایل‌ها و اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.
        </p>
        <div class="uerr-actions">
            <button type="button" id="uerr-ack" class="uerr-btn">متوجه شدم</button>
        </div>
    </div>
</div>
<style>
    .uerr-overlay{
        position:fixed; inset:0; z-index:2147483600;           /* بالاتر از همهٔ مودال‌های دیگر */
        display:flex; align-items:center; justify-content:center;
        background:rgba(15,23,42,.62); backdrop-filter:blur(2px);
        padding:18px; overflow:auto;
    }
    .uerr-box{
        background:#fff; border-radius:14px; width:100%; max-width:520px;
        padding:24px 22px 18px; box-shadow:0 24px 60px rgba(0,0,0,.32);
        border-top:6px solid #dc2626; text-align:right;
        animation:uerr-in .18s ease-out;
    }
    @keyframes uerr-in{ from{ transform:translateY(-10px) scale(.98); opacity:0; } to{ transform:none; opacity:1; } }
    .uerr-icon{ font-size:2.4rem; line-height:1; text-align:center; margin-bottom:6px; }
    .uerr-title{ margin:0 0 10px; font-size:1.28rem; color:#991b1b; text-align:center; }
    .uerr-message{ margin:0 0 12px; color:#334155; line-height:1.95; font-size:1rem; white-space:pre-wrap; }
    .uerr-details{
        margin:0 0 12px; padding:10px 14px 10px 18px; background:#fef2f2;
        border:1px solid #fecaca; border-radius:8px; color:#991b1b;
        font-size:.92rem; line-height:1.9; max-height:220px; overflow:auto;
        list-style:disc; direction:rtl; text-align:right;
    }
    .uerr-details:empty{ display:none; }
    .uerr-details li{ margin:3px 0; overflow-wrap:anywhere; }
    .uerr-hint{ margin:0 0 16px; color:#64748b; font-size:.86rem; line-height:1.85; }
    .uerr-actions{ display:flex; justify-content:center; }
    .uerr-btn{
        background:#dc2626; color:#fff; border:none; border-radius:9px;
        padding:11px 34px; font-size:1rem; font-weight:700; cursor:pointer; font-family:inherit;
    }
    .uerr-btn:hover{ background:#b91c1c; }
    .uerr-btn:focus-visible{ outline:3px solid #fca5a5; outline-offset:2px; }
    body.uerr-locked{ overflow:hidden !important; }
</style>
<script>
(function(){
    'use strict';
    if (window.UploadError) return;

    var overlay = document.getElementById('upload-error-modal');
    var titleEl = document.getElementById('uerr-title');
    var msgEl   = document.getElementById('uerr-message');
    var detEl   = document.getElementById('uerr-details');
    var ackBtn  = document.getElementById('uerr-ack');
    var isOpen  = false;
    var lastFocus = null;

    // ── جلوگیری از بستنِ تب/رفرش تا وقتی خطا تأیید نشده ──
    function beforeUnloadHandler(e){
        if (!isOpen) return;
        // در بعضی مرورگرها متنِ سفارشی نادیده گرفته می‌شود، ولی خودِ دیالوگ نمایش داده می‌شود.
        var msg = 'خطای آپلود تأیید نشده است. اگر صفحه را ترک کنید، جزئیات خطا از دست می‌رود.';
        e.preventDefault();
        e.returnValue = msg;
        return msg;
    }

    function lock(on){
        document.body.classList.toggle('uerr-locked', on);
        if (on) window.addEventListener('beforeunload', beforeUnloadHandler);
        else window.removeEventListener('beforeunload', beforeUnloadHandler);
    }

    function close(){
        if (!isOpen) return;
        isOpen = false;
        overlay.style.display = 'none';
        lock(false);
        if (lastFocus && typeof lastFocus.focus === 'function') {
            try { lastFocus.focus(); } catch (err) {}
        }
    }

    /**
     * نمایشِ خطای مسدودکننده.
     * @param {{title?:string,message?:string,details?:string[]|string}} opts
     */
    function show(opts){
        opts = opts || {};
        if (titleEl) titleEl.textContent = opts.title || 'آپلود ناموفق بود';
        if (msgEl) {
            msgEl.textContent = opts.message || 'فایل‌ها ذخیره نشدند. لطفاً دوباره تلاش کنید.';
        }
        if (detEl) {
            detEl.innerHTML = '';
            var d = opts.details;
            if (typeof d === 'string' && d !== '') d = [d];
            if (Array.isArray(d) && d.length) {
                d.forEach(function(item){
                    var li = document.createElement('li');
                    li.textContent = String(item);
                    detEl.appendChild(li);
                });
            }
        }
        if (!overlay) {
            // اگر HTML مودال موجود نبود (صفحه‌ای که آن را include نکرده) حداقل alert بده
            alert((opts.title || 'آپلود ناموفق بود') + '\n' + (opts.message || ''));
            return;
        }
        lastFocus = document.activeElement;
        overlay.style.display = 'flex';
        isOpen = true;
        lock(true);
        // فوکوس روی دکمهٔ تأیید تا Enter سریع کار کند
        setTimeout(function(){ if (ackBtn) ackBtn.focus(); }, 30);
    }

    if (ackBtn) ackBtn.addEventListener('click', close);

    // ── نمیتوان با Escape بست ──
    document.addEventListener('keydown', function(e){
        if (!isOpen) return;
        if (e.key === 'Escape' || e.key === 'Esc') {
            e.preventDefault();
            e.stopPropagation();
        }
        // focus trap ساده: Tab بیرون از مودال نرود
        if (e.key === 'Tab') {
            e.preventDefault();
            if (ackBtn) ackBtn.focus();
        }
    }, true);

    // ── کلیک بیرونِ جعبه مودال را نبند ──
    if (overlay) {
        overlay.addEventListener('mousedown', function(e){
            if (e.target === overlay) { e.preventDefault(); e.stopPropagation(); }
        });
        overlay.addEventListener('click', function(e){
            if (e.target === overlay) { e.preventDefault(); e.stopPropagation(); }
        });
    }

    window.UploadError = {
        show: show,
        close: close,
        isOpen: function(){ return isOpen; }
    };
})();
</script>
<?php } ?>
