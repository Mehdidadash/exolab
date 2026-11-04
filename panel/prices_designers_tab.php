<?php
// panel/prices_designers_tab.php
// بدنه‌ی تبِ «نرخ طراحی طراحان» — در prices.php?tab=designers استفاده می‌شود.
//
// هدف: با انتخاب یک طراح، «فی طراحی» (نرخ هر واحد) همهٔ انواع خدمات در یک صفحه دیده و
// ویرایش شود — دقیقاً مثل قیمت‌های عمومی اما به‌ازای یک طراح.
//
// منبعِ حقیقت همان جدولِ price_links است:
//   price_type   = 'design_fee'
//   provider_type = 'designer'  (یا 'doctor' برای نرخ‌های قدیمیِ طراحی)
//   provider_id  = شناسهٔ طراح
//   service_id   = خدمت   (NULL = نرخ کلیِ طراحی؛ اینجا در جدولِ «همهٔ خدمات» استفاده نمی‌شود)
//   price        = فی طراحی به تومان
//
// ذخیره با POST معمولی (فرم) انجام می‌شود — بدون fetch/AJAX — و پس از آن صفحه
// ریدایرکت می‌شود تا همیشه دادهٔ تازه دیده شود.
require_once __DIR__ . '/auth.php';
require_admin();

if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];
session_write_close();

// ─── پیام نتیجه ───
$savedCount = isset($_GET['designer_saved']) ? (int) $_GET['designer_saved'] : 0;
$skippedCount = isset($_GET['designer_skipped']) ? (int) $_GET['designer_skipped'] : 0;
$errorMsg = '';
$errKey = (string) ($_GET['designer_error'] ?? '');
if ($errKey === 'csrf') {
    $errorMsg = 'توکن امنیتی نامعتبر است؛ صفحه را رفرش کنید و دوباره ذخیره کنید.';
} elseif ($errKey === 'designer') {
    $errorMsg = 'طراح انتخاب‌شده معتبر نیست.';
} elseif ($errKey === 'db') {
    $errorMsg = 'ذخیره در دیتابیس انجام نشد. اگر تکرار شد با پشتیبانی تماس بگیرید.';
}

// ─── طراحان ───
// همهٔ طراحانِ فعال (صرف‌نظر از شعبه) — چون این صفحه صرفاً کارت نرخ‌هاست و مدیر است که آن را می‌بیند.
$allDesigners = db()->query("SELECT id, full_name, is_default_designer FROM users WHERE is_designer = 1 AND active = 1 ORDER BY is_default_designer DESC, full_name ASC")->fetchAll();

$selectedDesignerId = isset($_GET['designer_id']) ? (int) $_GET['designer_id'] : 0;
$selectedDesignerName = '';
foreach ($allDesigners as $d) {
    if ((int) $d['id'] === $selectedDesignerId) { $selectedDesignerName = (string) $d['full_name']; break; }
}
if ($selectedDesignerId && $selectedDesignerName === '') {
    $selectedDesignerId = 0;   // طراح غیرفعال/حذف‌شده → بدون انتخاب
}

// ─── خدمات ───
$services = getAllPrices();

// ─── نرخ‌های طراحیِ طراحِ انتخاب‌شده ───
// یک کوئری: نقشهٔ service_id → { price, link_id }
// نکته: اگر برای یک خدمت چند ردیف باشد، آخرین ردیف (بزرگ‌ترین id) معتبر است —
// همان ترتیبی که getApplicableDesignFee با ORDER BY id DESC استفاده می‌کند.
$feeMap = [];   // service_id => ['price' => float, 'link_id' => int, 'provider_type' => string]
if ($selectedDesignerId) {
    try {
        $st = db()->prepare("SELECT id, service_id, price, provider_type
                             FROM price_links
                             WHERE active = 1
                               AND price_type = 'design_fee'
                               AND provider_type IN ('designer','doctor')
                               AND provider_id = ?
                               AND service_id IS NOT NULL
                             ORDER BY id ASC");
        $st->execute([$selectedDesignerId]);
        foreach ($st->fetchAll() as $row) {
            $sid = (int) $row['service_id'];
            $feeMap[$sid] = [
                'price'         => (float) $row['price'],
                'link_id'       => (int) $row['id'],
                'provider_type' => (string) $row['provider_type'],
            ];
        }
    } catch (Throwable $e) {
        $feeMap = [];
    }
}
?>
<style>
    .pd-bar{ display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
    .pd-bar .form-group{ margin:0; min-width:240px; }
    .pd-bar select{ min-width:240px; }
    .pd-info{ background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:.92rem; color:#475569; line-height:1.9; }
    .pd-info b{ color:#0f172a; }
    /* جدول فشرده مثل prices.php */
    #pd-table th, #pd-table td{ padding:5px 8px; vertical-align:middle; }
    #pd-table thead th{ white-space:nowrap; }
    #pd-table .th-narrow{ width:40px; white-space:nowrap; }
    #pd-table .pd-price input{ width:170px; font-weight:700; }
    #pd-table .pd-price input.is-custom{ background:#fffbeb; border-color:#fcd34d; }
    #pd-table .pd-index{ text-align:center; color:#64748b; width:46px; }
    #pd-table .pd-badge{ white-space:nowrap; }
    #pd-table tr.pd-custom td{ background:#fffdf5; }
    /* نوارِ چسبندهٔ ذخیره: در جدول‌های بلند نیازی به اسکرول تا انتها نیست */
    .pd-savebar{
        position:sticky; bottom:0; z-index:20; display:flex; gap:10px; align-items:center; justify-content:flex-end;
        background:#fff; border-top:1px solid #e2e8f0; padding:10px 12px; margin-top:14px;
        box-shadow:0 -6px 14px rgba(15,23,42,.06); border-radius:0 0 10px 10px;
    }
    .pd-savebar .pd-hint{ margin-inline-end:auto; color:#64748b; font-size:.85rem; }
    @media (max-width:640px){
        .pd-bar .form-group, .pd-bar select{ min-width:100%; width:100%; }
    }
</style>

<?php if ($savedCount > 0): ?>
    <div style="margin:0 0 14px; padding:10px 14px; border-radius:8px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-weight:700;">
        ✅ <?= toPersianDigits((string) $savedCount) ?> نرخ طراحی ذخیره شد.
    </div>
<?php endif; ?>
<?php if ($skippedCount > 0): ?>
    <div style="margin:0 0 14px; padding:10px 14px; border-radius:8px; background:#fffbeb; border:1px solid #fcd34d; color:#92400e; font-weight:700;">
        ⚠️ <?= toPersianDigits((string) $skippedCount) ?> ردیف مقدارِ نامعتبر داشت و ذخیره نشد (نرخ قبلی دست‌نخورده ماند).
    </div>
<?php endif; ?>
<?php if ($errorMsg !== ''): ?>
    <div style="margin:0 0 14px; padding:10px 14px; border-radius:8px; background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; font-weight:700;">
        ❌ <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<div class="pd-info">
    <b>نرخ طراحی طراحان</b> — یک طراح انتخاب کنید تا <b>فی طراحی</b> (نرخ هر واحد) همهٔ خدمات برای او در یک جدول دیده و ویرایش شود.
    این نرخ همان <code>price_type = design_fee</code> در جدول <code>price_links</code> است و هنگام ثبت کیس
    (و محاسبهٔ فاکتور طراح) استفاده می‌شود.
    <br>
    اگر برای خدمتی نرخ ثبت نشده باشد، هزینهٔ طراحی <b>صفر</b> در نظر گرفته می‌شود؛
    ولی با خالی گذاشتنِ همان ردیف، اگر برای خدمت «فی طراحی پیش‌فرض» تعیین شده باشد، آن عدد استفاده می‌شود.
    ردیف‌های زردرنگ = نرخی برای این طراح ثبت شده است.
</div>

<form method="get" action="prices.php" class="pd-bar">
    <input type="hidden" name="tab" value="designers">
    <div class="form-group">
        <label for="pd-designer">طراح</label>
        <select id="pd-designer" name="designer_id" onchange="this.form.submit()">
            <option value="">— انتخاب طراح —</option>
            <?php foreach ($allDesigners as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $selectedDesignerId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['full_name']) ?><?= !empty($d['is_default_designer']) ? ' (پیش‌فرض)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <noscript><button type="submit" class="btn" style="background:#0F172A; color:#fff;">نمایش</button></noscript>
</form>

<?php if (!$selectedDesignerId): ?>
    <div class="form-card" style="text-align:center; color:#64748b; padding:30px 16px;">
        برای دیدن و ویرایش نرخ‌های طراحی، یک طراح انتخاب کنید.
    </div>
    <?php return; ?>
<?php endif; ?>

<form method="post" action="save_designer_design_fees.php" id="pd-form">
    <?= csrf_field() ?>
    <input type="hidden" name="designer_id" value="<?= (int) $selectedDesignerId ?>">

    <div class="table-scroll">
    <table id="pd-table">
        <thead>
        <tr>
            <th class="pd-index">#</th>
            <th>عنوان خدمت</th>
            <th title="نام کوتاه خدمت — در جدول کیس‌ها و برچسب استفاده می‌شود">نام اختصاری</th>
            <th>دسته</th>
            <th class="th-narrow" title="تیک = این خدمت طراحی لازم دارد">طراحی</th>
            <th class="th-narrow" title="فی طراحی پیش‌فرضِ خدمت (وقتی برای طراح نرخی ثبت نشده باشد)">پیش‌فرض خدمت</th>
            <th title="فی طراحی (نرخ هر واحد) برای این طراح — خالی = بدون نرخ اختصاصی">فی طراحی این طراح (تومان)</th>
            <th class="th-narrow">وضعیت</th>
        </tr>
        </thead>
        <tbody>
        <?php $idx = 0; foreach ($services as $svc):
            $idx++;
            $sid = (int) $svc['id'];
            $hasFee = isset($feeMap[$sid]);
            $feeVal = $hasFee ? (int) round($feeMap[$sid]['price']) : '';
            $defFee = ($svc['default_design_fee'] ?? null);
            $designRequired = !isset($svc['design_required']) || !empty($svc['design_required']);
            ?>
            <tr class="<?= $hasFee ? 'pd-custom' : '' ?>" data-service-id="<?= $sid ?>">
                <td class="pd-index"><?= toPersianDigits((string) $idx) ?></td>
                <td><?= htmlspecialchars($svc['title']) ?></td>
                <td style="font-family:monospace; direction:ltr; text-align:center; font-weight:bold; color:#0369a1;">
                    <?= htmlspecialchars((string) ($svc['short_name'] ?? '')) ?: '<span style="color:#cbd5e1;">—</span>' ?>
                </td>
                <td><?= htmlspecialchars((string) ($svc['category'] ?? '')) ?></td>
                <td class="pd-badge" style="text-align:center;">
                    <?= $designRequired
                        ? '<span style="color:#166534;">✔</span>'
                        : '<span style="color:#94a3b8;" title="این خدمت طراحی لازم ندارد">—</span>' ?>
                </td>
                <td style="text-align:center; color:#64748b; white-space:nowrap;">
                    <?= ($defFee === null || $defFee === '') ? '—' : formatAmountToman($defFee) ?>
                </td>
                <td class="pd-price">
                    <input type="number" min="0" step="1"
                           class="pd-fee<?= $hasFee ? ' is-custom' : '' ?>"
                           name="fees[<?= $sid ?>]"
                           value="<?= $hasFee ? (int) $feeVal : '' ?>"
                           placeholder="<?= ($defFee === null || $defFee === '') ? 'خالی = بدون نرخ' : ('خالی = ' . formatAmountToman($defFee)) ?>">
                </td>
                <td class="pd-badge" style="text-align:center;">
                    <?php if ($hasFee): ?>
                        <span style="background:#fef3c7; color:#92400e; border-radius:6px; padding:2px 8px; font-size:.78rem; font-weight:700;">نرخ اختصاصی</span>
                    <?php else: ?>
                        <span style="color:#94a3b8; font-size:.8rem;">ثبت نشده</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="pd-savebar">
        <span class="pd-hint">
            پس از ویرایش، روی «ذخیره» بزنید. خالی گذاشتنِ یک ردیف، نرخ اختصاصیِ آن خدمت را برای این طراح حذف می‌کند.
        </span>
        <button type="submit" class="btn" id="pd-save-btn" style="background:#06B6D4; color:#fff; font-weight:700;">
            💾 ذخیره نرخ‌های طراحی
        </button>
        <a class="btn" href="prices.php?tab=designers&designer_id=<?= (int) $selectedDesignerId ?>" style="background:#E5E7EB; color:#0F172A;">بازخوانی</a>
    </div>
</form>
