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

if (!function_exists('renderFileDetailsView')) {
    /**
     * @param array $rows
     * @param array $opts
     */
    function renderFileDetailsView(array $rows, array $opts = []): void {
        $empty   = (string) ($opts['empty'] ?? 'فایلی یافت نشد.');
        $openAll = !empty($opts['open']);

        if (empty($rows)) {
            echo '<p class="empty" style="margin:12px;">' . htmlspecialchars($empty) . '</p>';
            return;
        }

        // ── گروه‌بندی: پوشه‌ها (rel_path دو بخشی) و فایل‌های تکی ──
        $folders = [];   // root => rows
        $flat    = [];
        foreach ($rows as $r) {
            $rp = flRelPathRootSafe($r['rel_path'] ?? null);
            if ($rp && (function_exists('isFolderUploadFile') ? isFolderUploadFile($r['rel_path'] ?? null) : strpos((string) $r['rel_path'], '/') !== false)) {
                $folders[$rp['root']][] = $r;
            } else {
                $flat[] = $r;
            }
        }
        ?>
        <div class="fl-details">
            <div class="fl-details__head">
                <span>نام</span>
                <span class="fl-size">حجم</span>
                <span>نوع</span>
                <span>تاریخ</span>
                <span style="text-align:end;">عملیات</span>
            </div>

            <?php foreach ($folders as $rootName => $children): ?>
                <?php
                $gSize = 0; $gLast = ''; $gExts = [];
                foreach ($children as $c) {
                    $gSize += (int) ($c['size'] ?? 0);
                    if (!empty($c['date']) && $c['date'] > $gLast) $gLast = (string) $c['date'];
                    $e = strtolower((string) ($c['ext'] ?? pathinfo((string) ($c['name'] ?? ''), PATHINFO_EXTENSION)));
                    if ($e !== '') $gExts[$e] = true;
                }
                $folderId = 'flf-' . substr(md5($rootName . count($children)), 0, 8);
                ?>
                <div class="fl-row fl-row--folder" data-folder="<?= htmlspecialchars($rootName) ?>">
                    <span class="fl-name">
                        <button type="button" class="fl-caret js-fl-toggle" aria-expanded="<?= $openAll ? 'true' : 'true' ?>"
                                data-target="#<?= $folderId ?>" title="باز/بسته کردن پوشه">▾</button>
                        <span>📁</span>
                        <span class="fl-name__txt" style="direction:ltr; text-align:left;" title="<?= htmlspecialchars($rootName) ?>"><?= htmlspecialchars($rootName) ?></span>
                        <span class="fl-badge"><?= toPersianDigits((string) count($children)) ?> فایل</span>
                    </span>
                    <span class="fl-size fl-meta"><?= $gSize ? formatFileSize($gSize) : '—' ?></span>
                    <span class="fl-meta">پوشه · <?= toPersianDigits((string) count($gExts)) ?> فرمت</span>
                    <span class="fl-meta"><?= $gLast ? toJalaliDateTimeFormatted($gLast) : '—' ?></span>
                    <span class="fl-actions">
                        <?php if (!empty($opts['folder_rename'])): ?>
                            <button type="button" class="btn js-fl-rename"
                                    style="background:#e0f2fe; color:#0369a1;"
                                    data-folder="<?= htmlspecialchars($rootName, ENT_QUOTES) ?>"
                                    data-action="<?= htmlspecialchars((string) $opts['folder_rename']['action']) ?>"
                                    data-scope-all="<?= !empty($opts['folder_rename']['all']) ? '1' : '0' ?>"
                                    title="تغییر نام این پوشه">✏ نام پوشه</button>
                        <?php endif; ?>
                        <?php
                        // دکمه‌های مخصوصِ هر پوشه را می‌توان از بیرون داد؛ اگر نبود،
                        // دکمهٔ «دانلود ZIP پوشه» را خودمان می‌سازیم.
                        if (!empty($opts['folder_download_action'])): ?>
                            <a class="btn" href="<?= htmlspecialchars(str_replace('__FOLDER__', rawurlencode($rootName), (string) $opts['folder_download_action'])) ?>"
                               style="background:#0F172A; color:#fff; text-decoration:none;" title="دانلود کل پوشه به‌صورت ZIP">🗜 دانلود ZIP</a>
                        <?php endif; ?>
                        <?php
                        // دکمه‌های اضافیِ پوشه (مثلاً «حذف پوشه») — جای‌نگهدارهای
                        // __FOLDER__ و __COUNT__ با مقدارِ همین پوشه پر می‌شوند.
                        if (!empty($opts['folder_actions'])) {
                            echo str_replace(
                                ['__FOLDER__', '__COUNT__'],
                                [htmlspecialchars($rootName, ENT_QUOTES), (string) count($children)],
                                (string) $opts['folder_actions']
                            );
                        }
                        ?>
                    </span>
                </div>
                <div id="<?= $folderId ?>">
                    <?php foreach ($children as $c): ?>
                        <?php
                        $sub = flRelPathRootSafe($c['rel_path'] ?? null);
                        $subPath = $sub ? $sub['rest'] : '';
                        ?>
                        <div class="fl-row fl-row--child">
                            <span class="fl-name" style="flex-direction:column; align-items:flex-start;">
                                <span style="display:flex; align-items:center; gap:6px; min-width:0; width:100%;">
                                    <span style="padding-inline-start:20px; color:#94a3b8;">↳</span>
                                    <span class="fl-name__txt fl-name__txt--ltr" title="<?= htmlspecialchars($subPath !== '' ? $subPath : (string) $c['name']) ?>"><?= htmlspecialchars((string) $c['name']) ?></span>
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
            <?php endforeach; ?>

            <?php foreach ($flat as $c): ?>
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
            document.querySelectorAll('.js-fl-toggle').forEach(function(btn){
                if (btn.__flBound) return; btn.__flBound = true;
                btn.addEventListener('click', function(){
                    var sel = btn.getAttribute('data-target');
                    var box = sel ? document.querySelector(sel) : null;
                    if (!box) return;
                    var open = btn.getAttribute('aria-expanded') === 'true';
                    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                    btn.textContent = open ? '▸' : '▾';
                    box.style.display = open ? 'none' : '';
                });
            });
        })();
        </script>
        <?php
    }
}
