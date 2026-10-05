<?php
/**
 * panel/partials/case_folder_js.php
 *
 * اسکریپت مشترکِ مدیریت پوشه (ساخت / تغییر نام / حذف) + انتخاب پوشهٔ مقصد آپلود.
 *
 * چرا فایل جدا: این اسکریپت باید **هم** روی کیسِ دارای فایل و **هم** روی کیسِ خالی
 * اجرا شود. قبلاً داخل بلوکِ `else` شرطِ «کیس فایل دارد؟» بود، پس روی کیسِ خالی
 * دکمهٔ «پوشه جدید» هیچ هندلری نمی‌گرفت و کار نمی‌کرد.
 *
 * پیش‌نیازهای DOM:
 *   #case-files-toolbar  (data-case، data-action-url، data-csrf)
 *   #case-folder-msg     (پیام‌ها)
 *   #case-files-path     (نمایش مسیر مقصد — اختیاری)
 *   #case-upload-dest    (نمایش مقصد در فرم آپلود — اختیاری)
 *   دکمه‌های .js-fl-new-folder / .js-fl-new-sub / .js-fl-rename / .js-fl-upload-here
 *
 * خروجی: مقدار مسیر مقصدِ آپلود در `window.__CASE_UPLOAD_DEST__` نگه داشته می‌شود
 *        و فرم آپلود آن را می‌خواند.
 */

if (!defined('CASE_FOLDER_JS_LOADED')) {
    define('CASE_FOLDER_JS_LOADED', true);
    ?>
    <script>
    (function () {
        var bar = document.getElementById('case-files-toolbar');
        if (!bar) { if (window.console) console.warn('[folders] نوار ابزار پوشه در صفحه نیست'); return; }
        // جلوگیری از دوباره‌بایند شدن (اگر این partial دو بار include شود)
        if (bar.getAttribute('data-bound') === '1') return;
        bar.setAttribute('data-bound', '1');

        var CASE_ID = bar.getAttribute('data-case');
        var URL     = bar.getAttribute('data-action-url') || 'case_folder_action.php';
        var CSRF    = window.CSRF_TOKEN || bar.getAttribute('data-csrf') || '';
        var msgEl   = document.getElementById('case-folder-msg');

        // پوشهٔ مقصدِ آپلود ('' یعنی ریشهٔ کیس)
        window.__CASE_UPLOAD_DEST__ = window.__CASE_UPLOAD_DEST__ || '';

        function say(text, isError) {
            if (!msgEl) return;
            msgEl.textContent = text || '';
            msgEl.style.color = isError ? '#b91c1c' : '#166534';
            if (text) setTimeout(function () { if (msgEl.textContent === text) msgEl.textContent = ''; }, 5000);
        }

        // نمایش خطاهای غیرمنتظره روی صفحه (نه فقط کنسول)
        function jsErr(m) {
            var el = document.getElementById('case-folder-jserr');
            if (!el) {
                el = document.createElement('div');
                el.id = 'case-folder-jserr';
                el.style.cssText = 'flex-basis:100%; background:#fef2f2; color:#991b1b; border:1px solid #fca5a5; border-radius:6px; padding:5px 9px; font-size:.78rem;';
                bar.appendChild(el);
            }
            el.textContent = '⚠️ خطای اسکریپت پوشه: ' + m;
        }

        var ERRORS = {
            duplicate:         'پوشه‌ای با این نام از قبل وجود دارد.',
            bad_name:          'نام نامعتبر است (اسلش و کاراکترهای خاص مجاز نیستند).',
            parent_not_found:  'پوشهٔ والد پیدا نشد.',
            not_found:         'پوشه پیدا نشد.',
            forbidden:         'دسترسی ندارید.',
            no_case_access:    'به این کیس دسترسی ندارید.',
            csrf:              'نشست منقضی شده — صفحه را دوباره باز کنید.',
            bad_response:      'پاسخ نامعتبر از سرور.'
        };

        function call(payload, onOk) {
            var fd = new FormData();
            fd.append('case_id', CASE_ID);
            fd.append('_csrf_token', CSRF);
            Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
            fetch(URL, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
                body: fd
            })
            .then(function (r) { return r.json().catch(function () { return { success: false, error: 'bad_response' }; }); })
            .then(function (res) {
                if (res && res.success) { onOk(res); return; }
                var code = (res && res.error) || 'unknown';
                say('⚠️ ' + (ERRORS[code] || ('خطا: ' + code)), true);
            })
            .catch(function () { say('⚠️ خطا در ارتباط با سرور.', true); });
        }

        // پرسیدن نام پوشه. اگر مرورگر prompt را بلاک کند، به‌جای سکوت هشدار می‌دهد.
        function ask(parent, then) {
            var label = parent ? ('نام زیرپوشه در «' + parent + '»:') : 'نام پوشهٔ جدید:';
            var raw = null;
            try { raw = window.prompt(label, ''); } catch (e) { raw = null; }
            if (raw === null) {
                // کاربر انصراف داد یا prompt غیرفعال است — تفکیک با تأیید
                if (!window.confirm('پنجرهٔ پرسش نام باز نشد. آیا می‌خواهید نام پیش‌فرض «پوشه جدید» ساخته شود؟')) return;
                raw = 'پوشه جدید';
            }
            var name = String(raw).replace(/[\\/]+/g, ' ').replace(/^\s+|\s+$/g, '');
            if (!name) return;
            then(name);
        }

        try {
            // ── ساخت پوشه در ریشه ──
            var newRootBtn = bar.querySelector('.js-fl-new-folder');
            if (newRootBtn) {
                newRootBtn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    ask('', function (name) { call({ action: 'create', name: name }, function () { location.reload(); }); });
                });
            }

            // ── ساخت زیرپوشه ──
            document.querySelectorAll('.js-fl-new-sub').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var parent = btn.getAttribute('data-parent') || '';
                    ask(parent, function (name) {
                        call({ action: 'create', parent: parent, name: name }, function () { location.reload(); });
                    });
                });
            });

            // ── تغییر نام پوشه ──
            document.querySelectorAll('.js-fl-rename[data-folder]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var folder = btn.getAttribute('data-folder') || '';
                    var old = folder.split('/').pop();
                    var name = null;
                    try { name = window.prompt('نام جدید برای «' + folder + '»:', old); } catch (err) { name = null; }
                    if (name === null) return;
                    name = String(name).replace(/[\\/]+/g, ' ').replace(/^\s+|\s+$/g, '');
                    if (!name || name === old) return;
                    call({ action: 'rename', folder: folder, new_name: name }, function () { location.reload(); });
                });
            });

            // ── مقصد آپلود ──
            var destEl = document.getElementById('case-upload-dest');
            var pathEl = document.getElementById('case-files-path');
            window.__setCaseUploadDest__ = function (folder) {
                window.__CASE_UPLOAD_DEST__ = folder || '';
                if (destEl) destEl.textContent = folder || 'ریشهٔ کیس';
                if (pathEl) pathEl.textContent = folder ? ('📂 ' + folder) : '📂 ریشهٔ کیس';
            };
            document.querySelectorAll('.js-fl-upload-here').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var folder = btn.getAttribute('data-folder') || '';
                    var mode = btn.getAttribute('data-mode') || 'files';
                    window.__setCaseUploadDest__(folder);
                    say('مقصد آپلود: ' + (folder || 'ریشهٔ کیس'));
                    var box = document.getElementById('case-upload-box');
                    if (box) { try { box.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (err) {} }
                    // ← انتخاب‌گر را بلافاصله باز کن (مثل «آپلود اینجا» در cPanel)
                    var picker = (mode === 'folder')
                        ? document.getElementById('case-upload-pick-folder')
                        : document.getElementById('case-upload-pick-files');
                    if (picker) { try { picker.click(); } catch (err) {} }
                });
            });
            var rootBtn = document.getElementById('case-upload-dest-root');
            if (rootBtn) rootBtn.addEventListener('click', function () {
                window.__setCaseUploadDest__('');
                say('مقصد آپلود: ریشهٔ کیس');
            });

            // ── «📁 پوشه جدید» در فرم آپلود: پوشه را در مقصد فعلی می‌سازد ──
            var newFolderBtn = document.getElementById('case-upload-new-folder');
            if (newFolderBtn) newFolderBtn.addEventListener('click', function () {
                var parent = window.__CASE_UPLOAD_DEST__ || '';
                ask(parent, function (name) {
                    call({ action: 'create', parent: parent, name: name }, function (res) {
                        window.__setCaseUploadDest__(res.folder);
                        location.reload();
                    });
                });
            });

            // ── باز/بستن همه ──
            document.querySelectorAll('.js-fl-expand-all').forEach(function (b) {
                b.addEventListener('click', function () {
                    var host = document.querySelector(b.getAttribute('data-scope') || '') || document;
                    host.querySelectorAll('.fl-children').forEach(function (c) { c.hidden = false; c.style.display = ''; });
                    host.querySelectorAll('.js-fl-toggle').forEach(function (t) {
                        t.setAttribute('aria-expanded', 'true'); t.textContent = '▾';
                    });
                    host.querySelectorAll('.fl-folder-wrap > .fl-row--folder').forEach(function (r) {
                        r.classList.add('is-open');
                    });
                });
            });
            document.querySelectorAll('.js-fl-collapse-all').forEach(function (b) {
                b.addEventListener('click', function () {
                    var host = document.querySelector(b.getAttribute('data-scope') || '') || document;
                    host.querySelectorAll('.fl-children').forEach(function (c) { c.hidden = true; c.style.display = 'none'; });
                    host.querySelectorAll('.js-fl-toggle').forEach(function (t) {
                        t.setAttribute('aria-expanded', 'false'); t.textContent = '▸';
                    });
                    host.querySelectorAll('.fl-folder-wrap > .fl-row--folder').forEach(function (r) {
                        r.classList.remove('is-open');
                    });
                });
            });
        } catch (e) {
            jsErr((e && e.message) ? e.message : String(e));
            if (window.console) console.error('[folders]', e);
        }
    })();
    </script>
    <?php
}
