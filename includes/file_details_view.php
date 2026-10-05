<?php
/**
 * includes/file_details_view.php
 *
 * نمایشِ «نمای Details» فایل‌ها (شبیه Windows Explorer) — کامپوننتِ مشترک بین
 * صفحهٔ آپلود (uploads.php) و صفحهٔ کیس (view_case.php).
 *
 * ساختار: یک درختِ دو سطحی — ردیفِ پوشه (با دکمهٔ تا کردن) + ردیف‌های فایلِ داخلش.
 * سرستون‌ها هم‌ترازند تا چشم بتواند «نام | حجم | نوع | تاریخ | عملیات» را دنبال کند.
 *
 * استفاده:
 *   renderFileDetailsView(array $rows, array $opts);
 *
 * $rows هر عضو: ['id'=>int, 'name'=>string, 'rel_path'=>?string, 'size'=>?int,
 *                'date'=>?string (میلادی), 'ext'=>?string, 'meta'=>?string,
 *                'actions'=>string (HTML دکمه‌ها), 'folder_actions'=>string (HTML برای ردیف پوشه),
 *                'name_extra'=>string (HTML اختیاری که زیرِ نام فایل رندر می‌شود —
 *                              مثلاً آپلودکننده/نوع/توضیحات/اتصال‌ها)]
 * $opts: ['empty' => متنِ خالی بودن، 'open' => باز بودن پیش‌فرض پوشه‌ها,
 *         'folder_actions' => HTML دکمه‌های اضافیِ ردیف پوشه (__FOLDER__ و __COUNT__ جایگزین می‌شوند),
 *         'folder_download_action' => الگوی لینک دانلود ZIP پوشه (__FOLDER__ جایگزین می‌شود)]
 */

if (!function_exists('flRelPathRootSafe')) {
    /** relPathRoot با محافظت از خطا (اگر helper بار نشده باشد). */
    function flRelPathRootSafe(?string $relPath): ?array {
        if (function_exists('relPathRoot')) {
            return relPathRoot($relPath);
        }
        if ($relPath === null || trim($relPath) === '') return null;
        $rel = str_replace('\\', '/', trim($relPath));
        $pos = strpos($rel, '/');
        if ($pos === false) return ['root' => $rel, 'rest' => ''];
        return ['root' => substr($rel, 0, $pos), 'rest' => substr($rel, $pos + 1)];
    }
}

if (!function_exists('flIsKeepRow')) {
    /**
     * آیا این ردیف «نگهدارندهٔ پوشهٔ خالی» است؟ (rel_path به .keep ختم می‌شود)
     * این ردیف‌ها فایل واقعی نیستند و نباید در نمایش شمرده شوند.
     */
    function flIsKeepRow(array $r): bool {
        $rel = (string) ($r['rel_path'] ?? '');
        return $rel !== '' && substr($rel, -5) === '.keep';
    }
}

if (!function_exists('flBuildFolderTree')) {
    /**
     * ساختِ درختِ پوشه از روی rel_path فایل‌ها — پشتیبانی از چند سطح تودرتو.
     *
     * به‌جای درختِ آشیانه‌ای (که با ارجاع‌های PHP شکننده است)، هر پوشه با «مسیر
     * کامل» در یک نقشه ذخیره می‌شود و والدش جدا نگه داشته می‌شود. ترتیبِ نمایش با
     * مرتب‌کردنِ طبیعیِ مسیرها به‌دست می‌آید ⇒ والد همیشه قبل از فرزند.
     *
     * @return array{folders:array<string,array>,flat:array,order:array<string>}
     */
    function flBuildFolderTree(array $rows): array {
        $folders = [];   // path => ['name','path','parent','files']
        $flat    = [];

        $ensure = function (string $dir) use (&$folders) {
            $acc = '';
            foreach (array_filter(explode('/', $dir), fn($p) => $p !== '') as $p) {
                $parent = ($acc === '') ? null : $acc;
                $acc = ($acc === '') ? $p : ($acc . '/' . $p);
                if (!isset($folders[$acc])) {
                    $folders[$acc] = ['name' => $p, 'path' => $acc, 'parent' => $parent, 'files' => []];
                }
            }
        };

        // ── مرحلهٔ ۱: ابتدا همهٔ پوشه‌ها ثبت شوند (از .keep و مسیرهای چندبخشی) ──
        // ترتیب مهم است: اگر فایلی با rel_path تک‌سگمنت هم‌نامِ پوشه باشد، باید
        // پوشه از قبل شناخته شده باشد تا فایل داخلش قرار بگیرد، نه تکی.
        foreach ($rows as $r) {
            if (flIsKeepRow($r)) {
                $dir = substr((string) $r['rel_path'], 0, -6);   // حذف «/.keep»
                if ($dir !== '') { $ensure($dir); }
                continue;
            }
            $rel = (string) ($r['rel_path'] ?? '');
            $slash = strrpos($rel, '/');
            if ($slash !== false) {
                $ensure(substr($rel, 0, $slash));           // پوشهٔ والد
            } elseif ($rel !== '' && pathinfo($rel, PATHINFO_EXTENSION) === '') {
                $ensure($rel);                              // نام پوشهٔ بدون پسوند
            }
        }

        // ── مرحلهٔ ۲: حالا فایل‌ها را به پوشهٔ درستشان نسبت بده ──
        foreach ($rows as $r) {
            if (flIsKeepRow($r)) { continue; }
            $rel = (string) ($r['rel_path'] ?? '');
            if ($rel === '') { $flat[] = $r; continue; }

            $slash = strrpos($rel, '/');
            if ($slash !== false) {
                $folders[substr($rel, 0, $slash)]['files'][] = $r;
                continue;
            }
            // تک‌سگمنت: اگر پوشهٔ هم‌نام وجود دارد عضو آن است، وگرنه فایل تکی
            if (isset($folders[$rel])) {
                $folders[$rel]['files'][] = $r;
            } else {
                $flat[] = $r;
            }
        }

        $order = array_keys($folders);
        sort($order, SORT_NATURAL | SORT_FLAG_CASE);
        return ['folders' => $folders, 'flat' => $flat, 'order' => $order];
    }
}

if (!function_exists('flFolderChildCount')) {
    /** شمارش کل فایل‌های یک پوشه با احتساب زیرپوشه‌ها. */
    function flFolderChildCount(array $tree, string $path): int {
        $n = count($tree['folders'][$path]['files'] ?? []);
        $prefix = $path . '/';
        foreach ($tree['folders'] as $p => $node) {
            if (strpos($p, $prefix) === 0) { $n += count($node['files']); }
        }
        return $n;
    }
}

if (!function_exists('flDirectChildFolders')) {
    /** زیرپوشه‌های بی‌واسطهٔ یک پوشه (یا ریشه وقتی $parent === null). */
    function flDirectChildFolders(array $tree, ?string $parent): array {
        $out = [];
        foreach ($tree['order'] as $p) {
            $node = $tree['folders'][$p];
            if ((string) ($node['parent'] ?? '') === (string) ($parent ?? '')) {
                $out[] = $p;
            }
        }
        return $out;
    }
}

if (!function_exists('renderFileDetailsView')) {
    /**
     * @param array $rows
     * @param array $opts
     */
    function renderFileDetailsView(array $rows, array $opts = []): void {
        $empty   = (string) ($opts['empty'] ?? 'فایلی یافت نشد.');
        $openAll = !empty($opts['open']);

        // ⚠️ ردیف‌های نگهدارندهٔ «پوشهٔ خالی» (.keep) نباید از ورودی حذف شوند —
        // آن‌ها تنها نشانهٔ وجود یک پوشهٔ بی‌فایل هستند و درخت از روی آن‌ها ساخته می‌شود.
        // حذفشان فقط از **نمایش به‌عنوان فایل** لازم است که داخل flBuildFolderTree
        // انجام می‌شود (آن تابع .keep را می‌بیند ولی به فهرست فایل‌ها اضافه نمی‌کند).
        $hasRealRows = false;
        foreach ($rows as $r) { if (!flIsKeepRow($r)) { $hasRealRows = true; break; } }
        if (!$hasRealRows && empty($opts['always_show'])) {
            // فقط ردیف .keep داریم (کیس با پوشه‌های خالی) → درخت ساخته می‌شود ولی
            // پیام «خالی» هم بالای آن نشان داده می‌شود.
            echo '<p class="empty" style="margin:12px;">' . htmlspecialchars($empty) . '</p>';
        }

        // ── ساختِ درختِ چندسطحی از rel_path ──
        // پشتیبانی از: پوشهٔ خالی (ردیف .keep)، پوشهٔ تک‌سطحی، و تودرتوی چندسطحی.
        $tree = flBuildFolderTree($rows);
        ?>
        <div class="fl-details" data-fl-root>
            <div class="fl-details__head">
                <span>نام</span>
                <span class="fl-size">حجم</span>
                <span>نوع</span>
                <span>تاریخ</span>
                <span style="text-align:end;">عملیات</span>
            </div>

            <?php
            // ── رندرِ بازگشتیِ درخت ──
            // نکتهٔ مهم: ردیفِ هر زیرپوشه **داخل** ظرفِ .fl-children پوشهٔ والد
            // قرار می‌گیرد (نه به‌صورت خواهر/برادر در یک لیست تخت). فقط این‌طور
            // جمع‌کردنِ پوشهٔ والد واقعاً همهٔ زیرپوشه‌ها و فایل‌های تودرتو را
            // پنهان می‌کند. ساختار DOM: wrapper > (row + .fl-children > ...)
            $renderFolderRow = function (string $fPath, int $depth) use (&$renderFolderRow, $tree, $opts): void {
                $node     = $tree['folders'][$fPath];
                $children = $node['files'];
                $subPaths = flDirectChildFolders($tree, $fPath);
                $subDirs  = count($subPaths);

                // جمعِ حجم/تاریخ/فرمت در کل زیردرخت
                $gSize = 0; $gLast = ''; $gExts = [];
                $collect = function (array $n) use (&$collect, &$gSize, &$gLast, &$gExts, $tree) {
                    foreach ($n['files'] as $c) {
                        $gSize += (int) ($c['size'] ?? 0);
                        if (!empty($c['date']) && $c['date'] > $gLast) $gLast = (string) $c['date'];
                        $e = strtolower((string) ($c['ext'] ?? pathinfo((string) ($c['name'] ?? ''), PATHINFO_EXTENSION)));
                        if ($e !== '') $gExts[$e] = true;
                    }
                    foreach ($tree['folders'] as $p3 => $n3) {
                        if ($n3['parent'] === $n['path']) { $collect($n3); }
                    }
                };
                $collect($node);

                $total    = flFolderChildCount($tree, $fPath);
                $folderId = 'flf-' . substr(md5($fPath), 0, 8);
                ?>
                <div class="fl-folder-wrap" data-folder-wrap="<?= htmlspecialchars($fPath, ENT_QUOTES) ?>">
                    <div class="fl-row fl-row--folder" data-folder="<?= htmlspecialchars($fPath, ENT_QUOTES) ?>"
                         data-depth="<?= $depth ?>">
                        <span class="fl-name">
                            <button type="button" class="fl-caret js-fl-toggle" aria-expanded="true"
                                    data-target="#<?= $folderId ?>" title="باز/بسته کردن پوشه">▾</button>
                            <?php // نشانگرِ سطح: «ریشه» برای عمق ۰ و شمارهٔ سطح برای بقیه ?>
                            <?php if ($depth > 0): ?>
                                <span class="fl-lvl" title="سطح <?= toPersianDigits((string) $depth) ?>"><?= toPersianDigits((string) $depth) ?></span>
                            <?php else: ?>
                                <span class="fl-lvl fl-lvl--root" title="پوشهٔ اصلی">ریشه</span>
                            <?php endif; ?>
                            <span class="fl-folder-ico">📁</span>
                            <span class="fl-name__txt" title="<?= htmlspecialchars($fPath, ENT_QUOTES) ?>"><?= htmlspecialchars($node['name']) ?></span>
                            <span class="fl-badge"><?= toPersianDigits((string) $total) ?> فایل</span>
                            <?php if ($subDirs > 0): ?>
                                <span class="fl-badge fl-badge--sub"><?= toPersianDigits((string) $subDirs) ?> زیرپوشه</span>
                            <?php endif; ?>
                        </span>
                        <span class="fl-size fl-meta"><?= $gSize ? formatFileSize($gSize) : '—' ?></span>
                        <span class="fl-meta">پوشه · <?= toPersianDigits((string) count($gExts)) ?> فرمت</span>
                        <span class="fl-meta"><?= $gLast ? toJalaliDateTimeFormatted($gLast) : '—' ?></span>
                        <span class="fl-actions">
                            <?php if (!empty($opts['folder_new'])): ?>
                                <button type="button" class="btn js-fl-new-sub"
                                        style="background:#f0fdf4; color:#15803d;"
                                        data-parent="<?= htmlspecialchars($fPath, ENT_QUOTES) ?>"
                                        title="ساخت زیرپوشه در این پوشه">＋ زیرپوشه</button>
                            <?php endif; ?>
                            <?php if (!empty($opts['folder_upload'])): ?>
                                <button type="button" class="btn js-fl-upload-here"
                                        style="background:#e0f2fe; color:#0369a1;"
                                        data-folder="<?= htmlspecialchars($fPath, ENT_QUOTES) ?>"
                                        data-mode="files"
                                        title="آپلود فایل داخل این پوشه">🗂 آپلود فایل</button>
                                <button type="button" class="btn js-fl-upload-here"
                                        style="background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0;"
                                        data-folder="<?= htmlspecialchars($fPath, ENT_QUOTES) ?>"
                                        data-mode="folder"
                                        title="آپلود یک پوشهٔ کامل داخل این پوشه">📁 آپلود پوشه</button>
                            <?php endif; ?>
                            <?php if (!empty($opts['folder_rename'])): ?>
                                <button type="button" class="btn js-fl-rename"
                                        style="background:#e0f2fe; color:#0369a1;"
                                        data-folder="<?= htmlspecialchars($fPath, ENT_QUOTES) ?>"
                                        data-action="<?= htmlspecialchars((string) $opts['folder_rename']['action']) ?>"
                                        data-scope-all="<?= !empty($opts['folder_rename']['all']) ? '1' : '0' ?>"
                                        title="تغییر نام این پوشه">✏ نام</button>
                            <?php endif; ?>
                            <?php
                            // دکمه‌های مخصوصِ هر پوشه را می‌توان از بیرون داد؛ اگر نبود،
                            // دکمهٔ «دانلود ZIP پوشه» را خودمان می‌سازیم.
                            if (!empty($opts['folder_download_action'])): ?>
                                <a class="btn" href="<?= htmlspecialchars(str_replace('__FOLDER__', rawurlencode($fPath), (string) $opts['folder_download_action'])) ?>"
                                   style="background:#0F172A; color:#fff; text-decoration:none;" title="دانلود کل پوشه به‌صورت ZIP">🗜 دانلود ZIP</a>
                            <?php endif; ?>
                            <?php
                            // دکمه‌های اضافیِ پوشه (مثلاً «حذف پوشه») — جای‌نگهدارهای
                            // __FOLDER__ و __COUNT__ با مقدارِ همین پوشه پر می‌شوند.
                            if (!empty($opts['folder_actions'])) {
                                echo str_replace(
                                    ['__FOLDER__', '__COUNT__'],
                                    [htmlspecialchars($fPath, ENT_QUOTES), (string) $total],
                                    (string) $opts['folder_actions']
                                );
                            }
                            ?>
                        </span>
                    </div>
                    <div id="<?= $folderId ?>" class="fl-children" data-depth="<?= $depth ?>">
                        <?php foreach ($subPaths as $subPath): ?>
                            <?php $renderFolderRow($subPath, $depth + 1); ?>
                        <?php endforeach; ?>
                        <?php if (empty($children) && $subDirs === 0): ?>
                            <div class="fl-row fl-row--child">
                                <span class="fl-name" style="flex-direction:column; align-items:flex-start;">
                                    <span style="display:flex; align-items:center; gap:6px; min-width:0; width:100%;">
                                        <span class="fl-tick">↳</span>
                                        <span style="color:#94a3b8; font-size:.8rem; font-style:italic;">این پوشه خالی است</span>
                                    </span>
                                </span>
                                <span class="fl-size fl-meta">—</span>
                                <span class="fl-meta">—</span>
                                <span class="fl-meta">—</span>
                                <span class="fl-actions"></span>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($children as $c): ?>
                            <?php
                            $sub = flRelPathRootSafe($c['rel_path'] ?? null);
                            $subPath = $sub ? $sub['rest'] : '';
                            ?>
                            <div class="fl-row fl-row--child">
                                <span class="fl-name" style="flex-direction:column; align-items:flex-start;">
                                    <span style="display:flex; align-items:center; gap:6px; min-width:0; width:100%;">
                                        <span class="fl-tick">↳</span>
                                        <span class="fl-name__txt" title="<?= htmlspecialchars($subPath !== '' ? $subPath : (string) $c['name'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $c['name']) ?></span>
                                    </span>
                                    <?php if (!empty($c['name_extra'])): ?><?= (string) $c['name_extra'] ?><?php endif; ?>
                                </span>
                                <span class="fl-size fl-meta"><?= !empty($c['size']) ? formatFileSize((int) $c['size']) : '—' ?></span>
                                <span class="fl-meta"><?= htmlspecialchars(strtoupper((string) ($c['ext'] ?? pathinfo((string) $c['name'], PATHINFO_EXTENSION)) ?: '—')) ?></span>
                                <span class="fl-meta"><?= !empty($c['date']) ? toJalaliDateTimeFormatted((string) $c['date']) : '—' ?></span>
                                <span class="fl-actions"><?= (string) ($c['actions'] ?? '') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
            };

            foreach (flDirectChildFolders($tree, null) as $rootPath) {
                $renderFolderRow($rootPath, 0);
            }
            ?>

            <?php foreach ($tree['flat'] as $c): ?>
                <div class="fl-row">
                    <span class="fl-name" style="flex-direction:column; align-items:flex-start;">
                        <span style="display:flex; align-items:center; gap:6px; min-width:0; width:100%;">
                            <span style="color:#94a3b8; padding-inline-start:20px;">📄</span>
                            <span class="fl-name__txt" title="<?= htmlspecialchars((string) $c['name']) ?>"><?= htmlspecialchars((string) $c['name']) ?></span>
                        </span>
                        <?php if (!empty($c['name_extra'])): ?><?= (string) $c['name_extra'] ?><?php endif; ?>
                    </span>
                    <span class="fl-size fl-meta"><?= !empty($c['size']) ? formatFileSize((int) $c['size']) : '—' ?></span>
                    <span class="fl-meta"><?= htmlspecialchars(strtoupper((string) ($c['ext'] ?? pathinfo((string) $c['name'], PATHINFO_EXTENSION)) ?: '—')) ?></span>
                    <span class="fl-meta"><?= !empty($c['date']) ? toJalaliDateTimeFormatted((string) $c['date']) : '—' ?></span>
                    <span class="fl-actions"><?= (string) ($c['actions'] ?? '') ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function(){
            function flToggle(btn) {
                var sel = btn.getAttribute('data-target');
                var box = sel ? document.querySelector(sel) : null;
                if (!box) return;
                var open = btn.getAttribute('aria-expanded') === 'true';
                var willOpen = !open;
                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                btn.textContent = willOpen ? '▾' : '▸';
                // چون ردیفِ زیرپوشه‌ها **داخل** همین ظرف رندر می‌شود،
                // پنهان‌کردنِ ظرف به‌طور خودکار کل زیردرخت را مخفی می‌کند.
                box.hidden = !willOpen;
                box.style.display = willOpen ? '' : 'none';
                // نشانه‌گذاریِ ردیفِ پوشه برای استایل (is-open)
                var wrap = btn.closest('.fl-folder-wrap');
                var row = wrap ? wrap.querySelector(':scope > .fl-row--folder') : btn.closest('.fl-row--folder');
                if (row) { row.classList.toggle('is-open', willOpen); }
                if (!willOpen) {
                    // بازگشت به حالت اولیه: زیرپوشه‌ها هم جمع شوند تا
                    // با بازِ دوبارهٔ پوشهٔ والد، همه‌چیز بسته شروع شود.
                    box.querySelectorAll('.fl-children').forEach(function(sub){
                        sub.hidden = true; sub.style.display = 'none';
                    });
                    box.querySelectorAll('.js-fl-toggle').forEach(function(b){
                        b.setAttribute('aria-expanded', 'false'); b.textContent = '▸';
                        var w2 = b.closest('.fl-folder-wrap');
                        var r2 = w2 ? w2.querySelector(':scope > .fl-row--folder') : null;
                        if (r2) { r2.classList.remove('is-open'); }
                    });
                }
            }
            window.flToggle = flToggle;

            function bind(root) {
                (root || document).querySelectorAll('.js-fl-toggle').forEach(function(btn){
                    if (btn.__flBound) return; btn.__flBound = true;
                    btn.addEventListener('click', function(){ flToggle(btn); });
                });
            }
            bind(document);

            // باز/بسته کردن همه
            document.querySelectorAll('.js-fl-expand-all').forEach(function(b){
                if (b.__flBound) return; b.__flBound = true;
                b.addEventListener('click', function(){
                    var scope = b.getAttribute('data-scope');
                    var host  = scope ? document.querySelector(scope) : document;
                    if (!host) return;
                    host.querySelectorAll('.fl-details').forEach(function(d){
                        d.querySelectorAll('.fl-children').forEach(function(c){ c.style.display = ''; });
                        d.querySelectorAll('.js-fl-toggle').forEach(function(t){
                            t.setAttribute('aria-expanded','true'); t.textContent = '▾';
                        });
                    });
                });
            });
            document.querySelectorAll('.js-fl-collapse-all').forEach(function(b){
                if (b.__flBound) return; b.__flBound = true;
                b.addEventListener('click', function(){
                    var scope = b.getAttribute('data-scope');
                    var host  = scope ? document.querySelector(scope) : document;
                    if (!host) return;
                    // همهٔ پوشه‌ها را جمع کن، سپس فقط سطح اول را باز کن
                    host.querySelectorAll('.fl-details').forEach(function(d){
                        d.querySelectorAll('.fl-children').forEach(function(c){ c.style.display = 'none'; });
                        d.querySelectorAll('.js-fl-toggle').forEach(function(t){
                            t.setAttribute('aria-expanded','false'); t.textContent = '▸';
                        });
                    });
                });
            });
        })();
        </script>
        <?php
    }
}
