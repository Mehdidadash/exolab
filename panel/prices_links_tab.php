<?php
// panel/prices_links_tab.php
// رندرِ عمومیِ تب‌های «نرخ اختصاصی»:
//   ?tab=designers | doctors | clinics | labs | branches
//
// با انتخابِ یک طرفِ حساب (طراح/پزشک/کلینیک/لابراتوار/شعبه) نرخِ همهٔ خدمات در یک
// جدول دیده و ویرایش می‌شود. ذخیره به‌صورت AJAX (POST) و **بدون رفرش صفحه** انجام
// می‌شود؛ هم دکمهٔ «ذخیره» به ازای هر ردیف هست و هم «ذخیرهٔ همه».
//
// ── دسترسی ──
//   • ادمین اصلی (role=admin): همهٔ تب‌ها + نرخ بین‌شعبه‌ای با انتخاب دو شعبه.
//   • مدیر شعبه (branch_admin): نرخ پزشک/کلینیک/طراح/لابراتوارِ **شعبهٔ خودش**،
//     و در تبِ بین‌شعبه‌ای فقط نرخی که **شعبهٔ خودش پرداخت‌کننده است**.
require_once __DIR__ . '/auth.php';
require_admin();

if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];
session_write_close();

$kindKey = (string) ($_GET['tab'] ?? 'designers');
$kind    = priceTabKind($kindKey);
if (!$kind) {
    echo '<div class="form-card">تب نامعتبر است.</div>';
    return;
}

$user      = current_user();
$isRoot    = is_root_admin();
$myBranch  = currentBranchId() ?? 1;

/** پیامِ خطای دسترسی */
$denied = function (string $msg) {
    echo '<div style="padding:14px; border-radius:10px; background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; font-weight:700;">'
        . htmlspecialchars($msg) . '</div>';
};

// ─── تبِ بین‌شعبه‌ای: جفتِ (خدمت‌دهنده = انجام‌دهنده، خدمت‌گیرنده = پرداخت‌کننده) ───
// هر دو جهت برای هر دو طرف قابل دیدن است؛ اما:
//   • هر شعبه فقط نرخی را ویرایش می‌کند که **خودش خدمت‌دهنده (انجام‌دهنده)** است.
//   • حالتِ دیگر (که خودش پرداخت‌کننده است) فقط‌خواندنی است.
//   • ادمین اصلی هر دو جهت را ویرایش می‌کند.
$counterpartyId = isset($_GET['provider_branch']) ? (int) $_GET['provider_branch'] : 0;   // خدمت‌دهنده

$entityOptions = priceTabEntityOptions($kindKey);
$entityIds = array_map(fn($e) => (int) $e['id'], $entityOptions);

// entity_id در تبِ بین‌شعبه‌ای = خدمت‌گیرنده (پرداخت‌کننده)
$selectedId = isset($_GET['entity_id']) ? (int) $_GET['entity_id'] : 0;

// ─── تبِ لابراتوارها: دو جهت (مثل تبِ بین‌شعبه‌ای) ───
//   out = برون‌سپاری به لابراتوار  → لابراتوار انجام‌دهنده ، شعبهٔ ما پرداخت‌کننده
//   in  = کار از لابراتوار همکار   → شعبهٔ ما انجام‌دهنده ، لابراتوار پرداخت‌کننده
// در این تب `counterparty_id` همان «شعبهٔ طرفِ ما» است (نه لابراتوار).
$labDir      = 'out';
$labBranchId = $myBranch;
$branchOptions = [];
if ($kindKey === 'labs') {
    $labDir = (($_GET['dir'] ?? 'out') === 'in') ? 'in' : 'out';
    if ($isRoot) {
        $requestedBranch = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;
        if ($requestedBranch > 0) $labBranchId = $requestedBranch;
    }
    $branchOptions = getAllBranches();
    $branchIds = array_map(fn($b) => (int) $b['id'], $branchOptions);
    if (!in_array($labBranchId, $branchIds, true)) $labBranchId = $myBranch;
    $counterpartyId = $labBranchId;
}

// برچسبِ ستونِ مبلغ — در تبِ لابراتوار بسته به جهت عوض می‌شود.
$amountLabel = (string) $kind['amountLabel'];
if ($kindKey === 'labs') {
    $amountLabel = $labDir === 'in' ? 'نرخ کار از لابراتوار (دریافتی ما)' : 'نرخ برون‌سپاری (پرداختی ما)';
}

if ($kindKey === 'branches') {
    // ── پیش‌فرض‌ها (فقط وقتی کاربر انتخاب نکرده است) ──
    // ⚠️ قبلاً این بلوک برای *همه* اجرا می‌شد و انتخابِ کاربر را بازنویسی/قفل می‌کرد؛
    // برای ادمین اصلی ($myBranch = 1) باعث می‌شد همیشه (۱ → ۲) شود و «قزوین» به‌عنوان
    // خدمت‌دهنده قابل انتخاب نباشد. حالا فقط مقادیرِ خالی پر می‌شوند.
    if ($counterpartyId <= 0 || $selectedId <= 0) {
        $otherBranch = 0;
        foreach ($entityIds as $id) { if ($id !== $myBranch) { $otherBranch = $id; break; } }
        if ($counterpartyId <= 0) {
            $counterpartyId = $myBranch;                       // پیش‌فرض: من خدمت‌دهنده‌ام
        }
        if ($selectedId <= 0) {
            $selectedId = ($counterpartyId === $myBranch)
                ? ($otherBranch ?: $myBranch)
                : $myBranch;                                   // خدمت‌گیرنده = طرفِ مقابل
        }
    }
    // اگر کاربر عمداً هر دو را یکی انتخاب کرد، طرفِ دیگر (غیرِ شعبهٔ من) را بگذار
    if ($selectedId === $counterpartyId) {
        $alt = 0;
        foreach ($entityIds as $id) { if ($id !== $counterpartyId) { $alt = $id; break; } }
        if ($alt > 0) {
            $selectedId = $alt;
        }
    }
}

if ($selectedId > 0 && !in_array($selectedId, $entityIds, true)) {
    $selectedId = 0;
}
if ($kindKey !== 'labs') {
    if ($counterpartyId > 0 && !in_array($counterpartyId, $entityIds, true)) {
        $counterpartyId = 0;
    }
    if ($counterpartyId > 0 && $counterpartyId === $selectedId) {
        $counterpartyId = 0;   // یک شعبه نمی‌تواند با خودش طرف حساب باشد
    }
}

$selectedName = '';
$counterpartyName = '';
foreach ($entityOptions as $e) {
    if ((int) $e['id'] === $selectedId)      { $selectedName      = (string) $e['name']; }
    if ((int) $e['id'] === $counterpartyId)  { $counterpartyName  = (string) $e['name']; }
}
if ($kindKey === 'labs') {
    // در تبِ لابراتوار، طرفِ مقابل یک «شعبه» است نه لابراتوار؛ نامش را از فهرستِ شعبه‌ها بردار.
    $counterpartyName = '';
    foreach ($branchOptions as $b) {
        if ((int) $b['id'] === $labBranchId) { $counterpartyName = (string) $b['name']; break; }
    }
}

// ─── اجازهٔ ویرایش ───
// تبِ بین‌شعبه‌ای: فقط وقتی شعبهٔ جاری «خدمت‌دهنده/انجام‌دهنده» باشد.
if ($kindKey === 'branches') {
    $canEdit = $selectedId > 0 && $counterpartyId > 0 && ($isRoot || $counterpartyId === $myBranch);
} else {
    $canEdit = $selectedId > 0 && priceTabCanEdit($kindKey, $selectedId, $myBranch, $isRoot, $counterpartyId > 0 ? $counterpartyId : null, $kindKey === 'labs' ? $labDir : null);
}

// ─── خدمات و نرخ‌های فعلی ───
$services = getAllPrices();
if ($kindKey === 'branches') {
    // جفتِ صریح: provider = خدمت‌دهنده ، receiver = خدمت‌گیرنده
    $linkMap = ($selectedId > 0 && $counterpartyId > 0)
        ? priceTabLinks('branches', $selectedId, $myBranch, $counterpartyId)
        : [];
} else {
    $linkMap = $selectedId > 0 ? priceTabLinks($kindKey, $selectedId, $myBranch, $counterpartyId > 0 ? $counterpartyId : null, $kindKey === 'labs' ? $labDir : null) : [];
}

// نامِ طرفِ مقابل در تبِ بین‌شعبه‌ای (برای نمایشِ عنوان)
// ($counterpartyName بالاتر پر شده است)
?>
<style>
    .pl-bar{ display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
    .pl-bar .form-group{ margin:0; min-width:220px; }
    .pl-bar select{ min-width:220px; }
    .pl-info{ background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; margin-bottom:14px; font-size:.92rem; color:#475569; line-height:1.95; }
    .pl-info b{ color:#0f172a; }
    #pl-table th, #pl-table td{ padding:5px 8px; vertical-align:middle; }
    #pl-table thead th{ white-space:nowrap; }
    #pl-table .th-narrow{ width:44px; white-space:nowrap; }
    #pl-table .pl-price input{ width:160px; font-weight:700; }
    #pl-table .pl-price input.is-custom{ background:#fffbeb; border-color:#fcd34d; }
    #pl-table .pl-price input.is-dirty{ border-color:#06b6d4; box-shadow:0 0 0 2px rgba(6,182,212,.18); }
    #pl-table .pl-index{ text-align:center; color:#64748b; width:44px; }
    #pl-table tr.pl-custom td{ background:#fffdf5; }
    #pl-table .pl-row-btn{
        border:none; border-radius:6px; padding:4px 10px; font-size:.8rem; font-weight:700; cursor:pointer;
        background:#06b6d4; color:#fff; white-space:nowrap; font-family:inherit;
    }
    #pl-table .pl-row-btn:disabled{ background:#cbd5e1; cursor:not-allowed; }
    #pl-table .pl-row-btn.is-ok{ background:#16a34a; }
    #pl-table .pl-row-btn.is-err{ background:#dc2626; }
    .pl-savebar{
        position:sticky; bottom:0; z-index:20; display:flex; gap:10px; align-items:center; justify-content:flex-end;
        background:#fff; border-top:1px solid #e2e8f0; padding:10px 12px; margin-top:14px;
        box-shadow:0 -6px 14px rgba(15,23,42,.06); border-radius:0 0 10px 10px; flex-wrap:wrap;
    }
    .pl-savebar .pl-hint{ margin-inline-end:auto; color:#64748b; font-size:.85rem; }
    .pl-toast{
        position:fixed; inset-inline-start:50%; transform:translateX(-50%); top:18px; z-index:100000;
        padding:12px 20px; border-radius:10px; font-weight:700; font-size:.95rem; box-shadow:0 10px 28px rgba(15,23,42,.18);
        display:none; max-width:92vw; text-align:center;
    }
    .pl-toast.ok{ background:#f0fdf4; border:1px solid #86efac; color:#166534; }
    .pl-toast.err{ background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; }
    @media (max-width:640px){ .pl-bar .form-group, .pl-bar select{ min-width:100%; width:100%; } }
</style>

<div id="pl-toast" class="pl-toast" role="status" aria-live="polite"></div>

<?php if ($kindKey === 'branches' && !$isRoot): ?>
    <div class="pl-info" style="background:#eff6ff; border-color:#bfdbfe; color:#1e40af;">
        <b>قاعدهٔ نرخ بین‌شعبه‌ای:</b> هر شعبه نرخی را که <b>خودش خدمت‌دهنده (انجام‌دهنده) است</b> تعیین می‌کند.
        جهتِ مقابل (که شعبهٔ شما پرداخت‌کننده است) فقط <b>برای مشاهده</b> نمایش داده می‌شود.
        برای ویرایش، «خدمت‌دهنده» را روی <b>شعبهٔ خودتان</b> بگذارید.
    </div>
<?php endif; ?>
<?php if ($kindKey === 'branches' && $isRoot): ?>
    <div class="pl-info" style="background:#f5f3ff; border-color:#ddd6fe; color:#5b21b6;">
        <b>مدیر اصلی:</b> با انتخاب «خدمت‌دهنده» و «خدمت‌گیرنده» هر جهت را ویرایش می‌کنید.
        نرخ ذخیره‌شده متعلق به جفتِ (خدمت‌دهنده → خدمت‌گیرنده) است.
    </div>
<?php endif; ?>
<?php if ($kindKey === 'labs'): ?>
    <div class="pl-info" style="background:#ecfeff; border-color:#a5f3fc; color:#155e75;">
        <b>دو جهتِ نرخ لابراتوار:</b>
        <br>• <b>ما به لابراتوار می‌پردازیم (برون‌سپاری)</b>: لابراتوار کار را انجام می‌دهد و نرخ، هزینهٔ ماست.
        <br>• <b>لابراتوار به ما می‌پردازد (کار از لابراتوار همکار)</b>: ما کار را انجام می‌دهیم و نرخ، درآمدِ ماست.
        هر جهت نرخِ جداگانه‌ای دارد؛ تغییر جهت، ردیف‌های همان جهت را نشان می‌دهد.
        <?php if (!$isRoot): ?>
        <br><b>دسترسی:</b> جهتِ «برون‌سپاری» فقط وقتی قابل ویرایش است که لابراتوار متعلق به شعبهٔ شما باشد؛
        جهتِ «کار از لابراتوار» همیشه برای شعبهٔ خودتان قابل ویرایش است. بقیه فقط‌خواندنی است.
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="get" action="prices.php" class="pl-bar" id="pl-picker">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($kindKey) ?>">

    <?php if ($kindKey === 'branches'): ?>
        <?php // ── تبِ بین‌شعبه‌ای: انتخابِ «خدمت‌دهنده» و «خدمت‌گیرنده» ──
        // ⚠️ هیچ گزینه‌ای disabled نمی‌شود: انتخابِ «خدمت‌گیرنده» قبلاً گزینهٔ همان شعبه را
        // در لیستِ «خدمت‌دهنده» قفل می‌کرد و عملاً جابه‌جایی غیرممکن می‌شد (باگِ گزارش‌شده:
        // ادمین اصلی نمی‌توانست قزوین را خدمت‌دهنده کند). اگر کاربر همان شعبه را برای
        // هر دو انتخاب کند، سمتِ سرور طرفِ دیگر را می‌گذارد (پایین‌تر).
        $isProviderMine = ($counterpartyId === $myBranch);
        ?>
        <div class="form-group">
            <label for="pl-provider">خدمت‌دهنده (شعبه‌ای که کار را انجام می‌دهد)</label>
            <?php // ⚠️ هیچ گزینه‌ای disabled نمی‌شود و هیچ مقداری هم اجباری جابه‌جا نمی‌شود.
            // دو select آزادند؛ اگر یکی به شعبه‌ای تغییر کند که طرفِ مقابل هم همان است،
            // جاوااسکریپت طرفِ مقابل را به «شعبهٔ دیگر» می‌برد تا جفت همیشه معتبر بماند. ?>
            <select id="pl-provider" name="provider_branch" onchange="plPickChanged(this,'provider')">
                <?php foreach ($entityOptions as $e): ?>
                    <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $counterpartyId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['name']) ?><?= (int) $e['id'] === $myBranch ? ' (شعبهٔ من)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; margin-top:4px; color:<?= ($isProviderMine || $isRoot) ? '#166534' : '#92400e' ?>;">
                <?php if ($isRoot): ?>
                    شما به‌عنوان مدیر اصلی، هر دو جهت را ویرایش می‌کنید.
                <?php elseif ($isProviderMine): ?>
                    خدمت‌دهنده شعبهٔ شماست → این نرخ‌ها <b>قابل ویرایش</b> است.
                <?php else: ?>
                    خدمت‌دهنده شعبهٔ دیگری است → فقط <b>مشاهده</b> (برای ویرایش، خدمت‌دهنده را روی شعبهٔ خودتان بگذارید).
                <?php endif; ?>
            </small>
        </div>
        <div class="form-group">
            <label for="pl-entity">خدمت‌گیرنده (شعبه‌ای که پرداخت می‌کند)</label>
            <select id="pl-entity" name="entity_id" onchange="plPickChanged(this,'receiver')">
                <?php foreach ($entityOptions as $e): ?>
                    <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $selectedId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['name']) ?><?= (int) $e['id'] === $myBranch ? ' (شعبهٔ من)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php elseif ($kindKey === 'labs'): ?>
        <?php // ── تبِ لابراتوارها: لابراتوار + جهت + (برای مدیر اصلی) شعبه ── ?>
        <div class="form-group">
            <label for="pl-entity">لابراتوار</label>
            <select id="pl-entity" name="entity_id" onchange="this.form.submit()">
                <option value="">— انتخاب لابراتوار —</option>
                <?php foreach ($entityOptions as $e): ?>
                    <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $selectedId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['name']) ?><?= $e['sub'] !== '' ? ' — ' . htmlspecialchars($e['sub']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="pl-dir">جهت</label>
            <select id="pl-dir" name="dir" onchange="this.form.submit()">
                <option value="out" <?= $labDir === 'out' ? 'selected' : '' ?>>ما به لابراتوار می‌پردازیم (برون‌سپاری)</option>
                <option value="in" <?= $labDir === 'in' ? 'selected' : '' ?>>لابراتوار به ما می‌پردازد (کار از لابراتوار)</option>
            </select>
        </div>
        <?php if ($isRoot): ?>
        <div class="form-group">
            <label for="pl-branch">شعبه</label>
            <select id="pl-branch" name="branch_id" onchange="this.form.submit()">
                <?php foreach ($branchOptions as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $labBranchId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['name']) ?><?= (int) $b['id'] === $myBranch ? ' (شعبهٔ من)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="form-group">
            <label for="pl-entity"><?= htmlspecialchars($kind['entityLabel']) ?></label>
            <select id="pl-entity" name="entity_id" onchange="this.form.submit()">
                <option value="">— انتخاب <?= htmlspecialchars($kind['entityLabel']) ?> —</option>
                <?php foreach ($entityOptions as $e): ?>
                    <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $selectedId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['name']) ?><?= $e['sub'] !== '' ? ' — ' . htmlspecialchars($e['sub']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <noscript><button type="submit" class="btn" style="background:#0F172A; color:#fff;">نمایش</button></noscript>
</form>
<?php if ($kindKey === 'branches'): ?>
<script>
/**
 * تبِ بین‌شعبه‌ای: دو select مستقل‌اند، ولی «خدمت‌دهنده» و «خدمت‌گیرنده» نمی‌توانند یکی باشند.
 * اگر کاربر یکی را روی مقداری بگذارد که طرفِ مقابل هم همان است، طرفِ مقابل را به
 * «شعبهٔ دیگر» تغییر می‌دهیم تا انتخابِ کاربر اصلاً مسدود نشود (باگِ قبلی: با disabled
 * کردنِ گزینه، جابه‌جاییِ نقش‌ها غیرممکن می‌شد).
 */
function plPickChanged(sel, which){
    var prov = document.getElementById('pl-provider');
    var recv = document.getElementById('pl-entity');
    if (!prov || !recv) return;

    if (prov.value === recv.value) {
        var other = null;
        for (var i = 0; i < prov.options.length; i++) {
            var v = prov.options[i].value;
            if (v !== prov.value) { other = v; break; }
        }
        if (other !== null) {
            if (which === 'provider') { recv.value = other; }
            else                      { prov.value = other; }
        }
    }
    sel.form.submit();
}
</script>
<?php endif; ?>

<div class="pl-info">
    <b><?= htmlspecialchars($kind['title']) ?></b> —
    با انتخاب یک <?= htmlspecialchars($kind['entityLabel']) ?>، <b><?= htmlspecialchars($amountLabel) ?></b> همهٔ خدمات در یک جدول دیده و ویرایش می‌شود.
    این نرخ‌ها در جدول <code>price_links</code> ذخیره می‌شوند و هنگام ثبت کیس و صدور فاکتور استفاده می‌شوند.
    <br>
    ردیف‌های زردرنگ = نرخی ثبت شده است. خالی گذاشتن یک ردیف = حذف نرخ اختصاصی (نرخ پیش‌فرضِ خدمت اعمال می‌شود).
</div>

<?php if (!$selectedId): ?>
    <div class="form-card" style="text-align:center; color:#64748b; padding:30px 16px;">
        برای دیدن و ویرایش نرخ‌ها، یک <?= htmlspecialchars($kind['entityLabel']) ?> انتخاب کنید.
    </div>
    <?php return; ?>
<?php endif; ?>

<?php if (!$canEdit): ?>
    <div style="padding:12px 14px; border-radius:10px; background:#fffbeb; border:1px solid #fcd34d; color:#92400e; font-weight:700; margin-bottom:14px;">
        ⚠️ نرخ‌های این <?= htmlspecialchars($kind['entityLabel']) ?> متعلق به شعبهٔ دیگری است (یا مشترک است) و فقط توسط مدیر اصلی قابل تغییر است.
        این نرخ‌ها فقط‌خواندنی نمایش داده می‌شوند.
    </div>
<?php endif; ?>

<div class="table-scroll">
<table id="pl-table"
       data-kind="<?= htmlspecialchars($kindKey) ?>"
       data-entity="<?= (int) $selectedId ?>"
       data-counterparty="<?= (int) $counterpartyId ?>"
       data-dir="<?= $kindKey === 'labs' ? htmlspecialchars($labDir) : '' ?>"
       data-can-edit="<?= $canEdit ? '1' : '0' ?>">
    <thead>
    <tr>
        <th class="pl-index">#</th>
        <th>عنوان خدمت</th>
        <th title="نام کوتاه خدمت">نام اختصاری</th>
        <th>دسته</th>
        <th title="قیمت پیش‌فرض خدمت (اگر نرخ اختصاصی نباشد)">پیش‌فرض</th>
        <th><?= htmlspecialchars($amountLabel) ?> (تومان)</th>
        <th class="th-narrow">وضعیت</th>
        <?php if ($canEdit): ?><th class="th-narrow">ذخیره</th><?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php $idx = 0; foreach ($services as $svc):
        $idx++;
        $sid = (int) $svc['id'];
        $has  = isset($linkMap[$sid]);
        $val  = $has ? (int) round($linkMap[$sid]['price']) : '';
        ?>
        <tr class="<?= $has ? 'pl-custom' : '' ?>" data-service-id="<?= $sid ?>">
            <td class="pl-index"><?= toPersianDigits((string) $idx) ?></td>
            <td><?= htmlspecialchars($svc['title']) ?></td>
            <td style="font-family:monospace; direction:ltr; text-align:center; font-weight:bold; color:#0369a1;">
                <?= htmlspecialchars((string) ($svc['short_name'] ?? '')) ?: '<span style="color:#cbd5e1;">—</span>' ?>
            </td>
            <td><?= htmlspecialchars((string) ($svc['category'] ?? '')) ?></td>
            <td style="text-align:center; color:#64748b; white-space:nowrap;">
                <?= formatAmountToman($svc['price'] ?? 0) ?>
            </td>
            <td class="pl-price">
                <input type="number" min="0" step="1"
                       class="pl-input<?= $has ? ' is-custom' : '' ?>"
                       data-service-id="<?= $sid ?>"
                       value="<?= $has ? $val : '' ?>"
                       placeholder="خالی = پیش‌فرض"
                       <?= $canEdit ? '' : 'readonly style="background:#f3f4f6; cursor:not-allowed;"' ?>>
            </td>
            <td class="pl-state" style="text-align:center; white-space:nowrap;">
                <?php if ($has): ?>
                    <span style="background:#fef3c7; color:#92400e; border-radius:6px; padding:2px 8px; font-size:.78rem; font-weight:700;">ثبت شده</span>
                <?php else: ?>
                    <span style="color:#94a3b8; font-size:.8rem;">—</span>
                <?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
            <td style="text-align:center;">
                <button type="button" class="pl-row-btn pl-save-row" data-service-id="<?= $sid ?>">ذخیره</button>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($canEdit): ?>
<div class="pl-savebar">
    <span class="pl-hint">تغییرات هر ردیف با دکمهٔ «ذخیره» همان ردیف ثبت می‌شود (بدون رفرش صفحه).</span>
    <button type="button" class="btn" id="pl-save-all" style="background:#0F172A; color:#fff; font-weight:700;">💾 ذخیرهٔ همه</button>
</div>
<?php endif; ?>

<script>
(function(){
    'use strict';
    var table   = document.getElementById('pl-table');
    if (!table) return;
    var csrf    = <?= json_encode($csrf_token) ?>;
    var KIND    = table.getAttribute('data-kind');
    var ENTITY  = table.getAttribute('data-entity');
    var COUNTER = table.getAttribute('data-counterparty');
    var DIR     = table.getAttribute('data-dir') || '';
    var CANEDIT = table.getAttribute('data-can-edit') === '1';

    var toastEl = document.getElementById('pl-toast');
    var toastTimer = null;
    function toast(msg, ok){
        if (!toastEl) { alert(msg); return; }
        toastEl.textContent = msg;
        toastEl.className = 'pl-toast ' + (ok ? 'ok' : 'err');
        toastEl.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function(){ toastEl.style.display = 'none'; }, 3200);
    }

    /** ذخیرهٔ یک یا چند ردیف — کاملاً AJAX */
    function saveRows(rows, btn){
        if (!CANEDIT || !rows.length) return;
        var payload = {
            _csrf_token: csrf,
            kind: KIND,
            entity_id: ENTITY,
            counterparty_id: COUNTER,
            direction: DIR,
            fees: {}
        };
        rows.forEach(function(tr){
            var inp = tr.querySelector('.pl-input');
            payload.fees[tr.getAttribute('data-service-id')] = inp ? inp.value.trim() : '';
        });

        var origHtml = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '...'; }

        fetch('save_price_link_row.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(function(r){ return r.json().catch(function(){ return { success:false, message:'پاسخ نامعتبر سرور' }; }); })
        .then(function(res){
            if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
            if (res && res.success) {
                (res.results || []).forEach(function(r){
                    var tr = table.querySelector('tr[data-service-id="' + r.service_id + '"]');
                    if (!tr) return;
                    var inp = tr.querySelector('.pl-input');
                    var state = tr.querySelector('.pl-state');
                    var has = !!r.saved;
                    if (inp) {
                        inp.classList.remove('is-dirty');
                        inp.classList.toggle('is-custom', has);
                    }
                    if (state) {
                        state.innerHTML = has
                            ? '<span style="background:#fef3c7; color:#92400e; border-radius:6px; padding:2px 8px; font-size:.78rem; font-weight:700;">ثبت شده</span>'
                            : '<span style="color:#94a3b8; font-size:.8rem;">—</span>';
                    }
                    tr.classList.toggle('pl-custom', has);
                });
                var n = (res.results || []).length;
                var skipped = res.skipped || 0;
                var msg = '✅ ' + n + ' نرخ ذخیره شد.';
                if (skipped > 0) msg += ' (' + skipped + ' ردیف نامعتبر رد شد)';
                toast(msg, true);
                if (btn) { btn.classList.add('is-ok'); setTimeout(function(){ btn.classList.remove('is-ok'); }, 1200); }
            } else {
                toast('❌ ' + ((res && res.message) || 'ذخیره انجام نشد.'), false);
                if (btn) { btn.classList.add('is-err'); setTimeout(function(){ btn.classList.remove('is-err'); }, 1800); }
            }
        })
        .catch(function(){
            if (btn) { btn.disabled = false; btn.innerHTML = origHtml; btn.classList.add('is-err'); }
            toast('❌ خطا در اتصال به سرور.', false);
        });
    }

    table.addEventListener('click', function(e){
        var btn = e.target.closest('.pl-save-row');
        if (!btn) return;
        var tr = btn.closest('tr');
        saveRows([tr], btn);
    });

    table.addEventListener('input', function(e){
        if (e.target.classList && e.target.classList.contains('pl-input')) {
            e.target.classList.add('is-dirty');
        }
    });

    var allBtn = document.getElementById('pl-save-all');
    if (allBtn) {
        allBtn.addEventListener('click', function(){
            var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-service-id]'));
            // فقط ردیف‌هایی که مقدارشان تغییر کرده (یا هنوز ذخیره نشده) — ولی ذخیرهٔ همه
            // عمداً همه را می‌فرستد تا کاربر مطمئن باشد وضعیت نهایی همان چیزی است که می‌بیند.
            saveRows(rows, allBtn);
        });
    }
})();
</script>
