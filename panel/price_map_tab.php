<?php
// panel/price_map_tab.php
// بدنه‌ی «نقشه قیمت‌گذاری» — در تبِ «نقشه و نرخ‌های اختصاصی» صفحه‌ی قیمت‌ها (prices.php?tab=map) استفاده می‌شود.
// (بدون panel_layout_start/end — صدا زننده این‌ها را کنترل می‌کند.)
require_once __DIR__ . '/auth.php';
require_admin();

$meBranchId = currentBranchId() ?? 1;

// ---------- entities ----------
$branches = getAllBranches();
$labs     = db()->query("SELECT id, full_name, branch_id FROM users WHERE role IN ('outsource_lab','partner_lab','customer_lab','lab') AND active=1 ORDER BY full_name")->fetchAll();
$doctors  = db()->query("SELECT id, full_name, branch_id FROM users WHERE role='doctor' AND active=1 ORDER BY full_name")->fetchAll();
$designers = db()->query("SELECT id, full_name FROM users WHERE is_designer=1 AND active=1 ORDER BY full_name")->fetchAll();

$branchName = []; foreach ($branches as $b) $branchName[(int) $b['id']] = $b['name'];
$labName    = []; foreach ($labs as $u)     $labName[(int) $u['id']]     = $u['full_name'];
$doctorName = []; foreach ($doctors as $u)  $doctorName[(int) $u['id']]  = $u['full_name'];
$designerName = []; foreach ($designers as $u) $designerName[(int) $u['id']] = $u['full_name'];

$services = db()->query("SELECT id, title, category, price FROM site_prices WHERE active=1 ORDER BY (display_order=0) ASC, CASE WHEN display_order=0 THEN id ELSE display_order END ASC")->fetchAll();
$serviceTitle = []; foreach ($services as $s) $serviceTitle[(int) $s['id']] = $s['title'];

// ---------- links (نقشه مشترک بین شعب — فقط مدیران) ----------
$links = db()->query("SELECT * FROM price_links WHERE active=1 ORDER BY id DESC")->fetchAll();

if (!function_exists('pmEntityName')) {
    function pmEntityName(array $branchName, array $labName, array $doctorName, array $designerName, string $type, int $id): string {
        if ($type === 'branch')   return $branchName[$id] ?? 'شعبه #' . $id;
        if ($type === 'lab')      return $labName[$id] ?? 'لابراتوار #' . $id;
        if ($type === 'doctor')   return $doctorName[$id] ?? 'پزشک #' . $id;
        if ($type === 'designer') return $designerName[$id] ?? 'طراح #' . $id;
        return 'نامشخص';
    }
    function pmNodeKey(string $type, int $id): string { return $type . '-' . $id; }
    function pmTypeBadge(string $type): string {
        $map = ['branch' => ['شعبه', '#e0e7ff', '#3730a3'], 'lab' => ['لابراتوار', '#cffafe', '#155e75'], 'doctor' => ['پزشک', '#dcfce7', '#166534'], 'designer' => ['طراح', '#fef3c7', '#92400e']];
        $b = $map[$type] ?? ['نامشخص', '#f3f4f6', '#374151'];
        return '<span class="badge" style="background:' . $b[1] . '; color:' . $b[2] . ';">' . $b[0] . '</span>';
    }
}

// ---------- graph data ----------
$nodes = [];
foreach ($branches as $b) {
    $k = pmNodeKey('branch', (int) $b['id']);
    $nodes[$k] = ['key' => $k, 'label' => $b['name'], 'type' => 'branch', 'isMe' => (int) $b['id'] === $meBranchId, 'group' => (int) $b['id']];
}
// لابراتوارهایی که «متعلق به یک شعبه» هستند (مثل «لابراتوار مرکزی» که متعلق به شعبه‌ی اصلی است)
// به‌عنوان گره‌ی مستقل در گراف نمی‌آیند؛ به‌جای آن در گره‌ی همان شعبه ادغام می‌شوند.
// (لابراتوارهای بیرونی/مستقل — بدون branch_id — همچنان گره‌ی مستقل می‌مانند.)
$labToBranchKey = [];
foreach ($labs as $u) {
    $k = pmNodeKey('lab', (int) $u['id']);
    $bid = $u['branch_id'] !== null ? (int) $u['branch_id'] : null;
    $branchKey = $bid !== null ? pmNodeKey('branch', $bid) : null;
    if ($branchKey !== null && isset($nodes[$branchKey])) {
        $labToBranchKey[$k] = $branchKey; // این لابراتوار = همان شعبه
        continue;
    }
    $nodes[$k] = ['key' => $k, 'label' => $u['full_name'], 'type' => 'lab', 'isMe' => false, 'group' => $bid];
}
foreach ($doctors as $u) {
    $k = pmNodeKey('doctor', (int) $u['id']);
    $nodes[$k] = ['key' => $k, 'label' => $u['full_name'], 'type' => 'doctor', 'isMe' => false, 'group' => $u['branch_id'] !== null ? (int) $u['branch_id'] : null];
}
foreach ($designers as $u) {
    $k = pmNodeKey('designer', (int) $u['id']);
    $nodes[$k] = ['key' => $k, 'label' => $u['full_name'], 'type' => 'designer', 'isMe' => false, 'group' => null];
}

$graphLinks = [];
foreach ($links as $l) {
    if ($l['receiver_id'] === null) continue; // پیش‌فرض شعبه — در گراف نمایش داده نمی‌شود
    $from = pmNodeKey($l['provider_type'], (int) $l['provider_id']);
    $to   = pmNodeKey($l['receiver_type'], (int) $l['receiver_id']);
    // لابراتوارِ متعلق به شعبه → همان گره‌ی شعبه (مثلاً «لابراتوار مرکزی» → «شعبه‌ی اصلی»)
    $from = $labToBranchKey[$from] ?? $from;
    $to   = $labToBranchKey[$to] ?? $to;
    if (!isset($nodes[$from]) || !isset($nodes[$to])) continue;
    $graphLinks[] = [
        'id'         => (int) $l['id'],
        'from'       => $from,
        'to'         => $to,
        'service_id' => (int) $l['service_id'],
        'service'    => $serviceTitle[(int) $l['service_id']] ?? 'خدمت نامشخص',
        'price'      => (float) $l['price'],
        'price_type' => $l['price_type'],
        'note'       => (string) $l['note'],
    ];
}

// قیمت عمومی هر خدمت: قیمت مرجع کاتالوگ + قیمت اختصاصی این شعبه (branch_service_prices) اگر باشد
$branchPriceMap = [];
if ($meBranchId) {
    $stmt = db()->prepare('SELECT service_id, custom_price FROM branch_service_prices WHERE branch_id = ?');
    $stmt->execute([$meBranchId]);
    foreach ($stmt->fetchAll() as $r) $branchPriceMap[(int) $r['service_id']] = (float) $r['custom_price'];
}
$servicesJson = array_map(function ($s) use ($branchPriceMap) {
    $sid = (int) $s['id'];
    return [
        'id' => $sid,
        'title' => $s['title'],
        'price' => (float) $s['price'],
        'branch_price' => $branchPriceMap[$sid] ?? null,
    ];
}, $services);
$graphJson = [
    'meBranchId' => (int) $meBranchId,
    'nodes'      => array_values($nodes),
    'links'      => $graphLinks,
    'services'   => $servicesJson,
];
?>
<link rel="stylesheet" href="../assets/css/price-map.css">

<div class="form-card" style="margin-bottom:16px; padding:14px 18px;">
    <p style="color:#525252; margin:0;">
        <strong>نقشه قیمت‌گذاری</strong> — روابط قیمتی خدمات بین شعب، لابراتوارها و پزشک‌ها.
        هر پیکان از <strong>ارائه‌دهنده</strong> به <strong>دریافت‌کننده</strong> با کارت قیمت رسم می‌شود.
        <strong>شعبه‌ی خود شما همیشه وسط و ثابت است</strong>؛ با کشیدن هر گره فقط همان گره جابه‌جا می‌شود.
        رنگ خط: سبز = ما می‌گیریم (فروش)، قرمز = ما می‌پردازیم (خرید)، خاکستری = بین دو طرف دیگر. ⭐ = قیمت اختصاصی.
    </p>
</div>

<div class="price-map-card">
    <div class="pm-toolbar">
        <label>خدمت:
            <select id="pm-service">
                <option value="">همه خدمات</option>
            </select>
        </label>
        <span id="pm-genprice" class="pm-genprice" style="display:none;"></span>
        <label><input type="checkbox" id="pm-type-branch" checked> شعبه</label>
        <label><input type="checkbox" id="pm-type-lab" checked> لابراتوار</label>
        <label><input type="checkbox" id="pm-type-doctor" checked> پزشک</label>
        <label><input type="checkbox" id="pm-type-designer" checked> طراح</label>
        <label id="pm-only-center-wrap" style="display:none;"><input type="checkbox" id="pm-only-center" checked> فقط روابط شعبه مرکزی</label>
        <button type="button" class="btn" id="pm-relayout" style="background:#E5E7EB; color:#0F172A;">تنظیم مجدد چیدمان</button>
    </div>

    <div id="pm-graph-wrap">
        <svg id="pm-svg" viewBox="0 0 1200 760" preserveAspectRatio="xMidYMid meet"></svg>
        <div id="pm-empty" class="pm-empty" style="display:none;"></div>
        <div id="pm-tooltip"></div>
        <div id="pm-focus-note"></div>
        <div class="pm-legend">
            <div><span class="pm-dot" style="background:#6366F1;"></span> شعبه</div>
            <div><span class="pm-dot" style="background:#0891B2;"></span> لابراتوار</div>
            <div><span class="pm-dot" style="background:#16A34A;"></span> پزشک</div>
            <div><span class="pm-dot" style="background:#D97706;"></span> طراح</div>
            <div><span class="pm-line" style="border-color:#16A34A;"></span> فروش (ما می‌گیریم)</div>
            <div><span class="pm-line" style="border-color:#DC2626;"></span> خرید (ما می‌پردازیم)</div>
            <div><span class="pm-line" style="border-color:#64748B;"></span> بین دو طرف دیگر</div>
            <div>⭐ قیمت اختصاصی</div>
            <div><span class="pm-box-dashed"></span> عمومی گروهی</div>
        </div>
    </div>

    <div id="pm-margin" class="pm-margin" style="display:none;"></div>
</div>

<div style="display:flex; justify-content:space-between; align-items:center; margin:22px 0 10px; flex-wrap:wrap; gap:8px;">
    <h3 style="margin:0;">روابط قیمتی</h3>
    <button type="button" class="btn" id="pm-add-btn" style="background:#0F172A; color:#fff;">+ افزودن رابطه قیمتی</button>
</div>

<table class="datatable display" id="pm-table">
    <thead>
    <tr>
        <th>خدمت</th>
        <th>ارائه‌دهنده</th>
        <th>دریافت‌کننده</th>
        <th>قیمت (تومان)</th>
        <th>نوع قیمت</th>
        <th>یادداشت</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($links as $l):
        $pn = htmlspecialchars(pmEntityName($branchName, $labName, $doctorName, $designerName, $l['provider_type'], (int) $l['provider_id']));
        $rn = $l['receiver_id'] !== null ? htmlspecialchars(pmEntityName($branchName, $labName, $doctorName, $designerName, $l['receiver_type'], (int) $l['receiver_id'])) : '<span style="color:#9ca3af;">عمومی</span>';
        $sv = ($l['service_id'] !== null && $l['service_id'] !== '') ? htmlspecialchars($serviceTitle[(int) $l['service_id']] ?? 'خدمت نامشخص') : 'عمومی (همه خدمات)';
        $kindBadge = [
            'specific' => '<span class="badge" style="background:#fef3c7; color:#92400e;">اختصاصی</span>',
            'outsource' => '<span class="badge" style="background:#fee2e2; color:#991b1b;">برون‌سپاری</span>',
            'design_fee' => '<span class="badge" style="background:#f3e8ff; color:#6b21a8;">طراحی</span>',
            'branch_default' => '<span class="badge" style="background:#e0e7ff; color:#3730a3;">پیش‌فرض شعبه</span>',
            'general' => '<span class="badge" style="background:#eef2ff; color:#4338ca;">عمومی</span>',
        ];
        $kb = $kindBadge[$l['price_type']] ?? $kindBadge['general'];
    ?>
        <tr>
            <td><?= $sv ?></td>
            <td><?= pmTypeBadge($l['provider_type']) ?> <?= $pn ?></td>
            <td><?= pmTypeBadge($l['receiver_type']) ?> <?= $rn ?></td>
            <td style="white-space:nowrap;"><?= formatAmountToman($l['price']) ?></td>
            <td><?= $kb ?></td>
            <td style="max-width:220px;"><?= htmlspecialchars((string) $l['note']) ?></td>
            <td style="white-space:nowrap;">
                <button type="button" class="btn pm-edit" style="background:#E5E7EB; color:#0F172A; padding:4px 10px;"
                        data-id="<?= (int) $l['id'] ?>"
                        data-service="<?= (int) $l['service_id'] ?>"
                        data-p-type="<?= htmlspecialchars($l['provider_type']) ?>"
                        data-p-id="<?= (int) $l['provider_id'] ?>"
                        data-r-type="<?= htmlspecialchars($l['receiver_type']) ?>"
                        data-r-id="<?= $l['receiver_id'] !== null ? (int) $l['receiver_id'] : '' ?>"
                        data-price="<?= formatTomanInput($l['price']) ?>"
                        data-price-type="<?= htmlspecialchars($l['price_type']) ?>"
                        data-note="<?= htmlspecialchars((string) $l['note']) ?>">ویرایش</button>
                <form method="post" action="delete_price_link.php" style="display:inline;" onsubmit="return confirm('این رابطه قیمتی حذف شود؟');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <button class="btn" style="background:#FEE2E2; color:#991B1B; padding:4px 10px;">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- ─── Modal: افزودن/ویرایش رابطه ─── -->
<div id="pm-modal" class="pm-modal" style="display:none;">
    <div class="pm-modal-box">
        <h3 id="pm-modal-title" style="margin-top:0;">افزودن رابطه قیمتی</h3>
        <form method="post" action="save_price_link.php" id="pm-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="pm-id">
            <div class="pm-form-grid">
                <label class="pm-full">نوع قیمت
                    <select name="price_type" id="pm-kind">
                        <option value="specific">قیمت اختصاصی (بین دو طرف)</option>
                        <option value="design_fee">نرخ طراحی</option>
                        <option value="general">قیمت عمومی (مرجع/پیش‌فرض)</option>
                    </select>
                    <small id="pm-kind-hint" style="display:block; color:#64748b; font-weight:400; margin-top:4px;"></small>
                </label>

                <label class="pm-full">خدمت
                    <select name="service_id" id="pm-modal-service">
                        <option value="">انتخاب خدمت...</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div id="pm-provider-wrap">
                    <label>ارائه‌دهنده (انجام‌دهنده کار)
                        <select name="provider_type" id="pm-provider-type">
                            <option value="branch">شعبه</option>
                            <option value="lab">لابراتوار</option>
                            <option value="doctor">پزشک</option>
                            <option value="designer">طراح</option>
                        </select>
                        <select name="provider_id" id="pm-provider-id">
                            <?php
                            foreach ($branches as $b)  echo '<option data-type="branch" value="' . (int) $b['id'] . '">' . htmlspecialchars($b['name']) . '</option>';
                            foreach ($labs as $u)      echo '<option data-type="lab" value="' . (int) $u['id'] . '">' . htmlspecialchars($u['full_name']) . '</option>';
                            foreach ($doctors as $u)   echo '<option data-type="doctor" value="' . (int) $u['id'] . '">' . htmlspecialchars($u['full_name']) . '</option>';
                            foreach ($designers as $u) echo '<option data-type="designer" value="' . (int) $u['id'] . '">' . htmlspecialchars($u['full_name']) . '</option>';
                            ?>
                        </select>
                    </label>
                </div>

                <div id="pm-receiver-wrap">
                    <label>دریافت‌کننده (پرداخت‌کننده)
                        <select name="receiver_type" id="pm-receiver-type">
                            <option value="branch">شعبه</option>
                            <option value="lab">لابراتوار</option>
                            <option value="doctor">پزشک</option>
                            <option value="designer">طراح</option>
                        </select>
                        <select name="receiver_id" id="pm-receiver-id">
                            <?php
                            foreach ($branches as $b)  echo '<option data-type="branch" value="' . (int) $b['id'] . '">' . htmlspecialchars($b['name']) . '</option>';
                            foreach ($labs as $u)      echo '<option data-type="lab" value="' . (int) $u['id'] . '">' . htmlspecialchars($u['full_name']) . '</option>';
                            foreach ($doctors as $u)   echo '<option data-type="doctor" value="' . (int) $u['id'] . '">' . htmlspecialchars($u['full_name']) . '</option>';
                            foreach ($designers as $u) echo '<option data-type="designer" value="' . (int) $u['id'] . '">' . htmlspecialchars($u['full_name']) . '</option>';
                            ?>
                        </select>
                    </label>
                </div>

                <label>قیمت (تومان)
                    <input type="text" name="price" id="pm-price" inputmode="numeric" required placeholder="مثلاً 1900000">
                </label>

                <label class="pm-full">یادداشت (اختیاری)
                    <input type="text" name="note" id="pm-note" maxlength="255" placeholder="مثلاً: بسته به کیس ۷۰۰ تا ۹۰۰ هزار">
                </label>
            </div>
            <div style="display:flex; gap:8px; margin-top:14px; justify-content:flex-start;">
                <button type="submit" class="btn" style="background:#0F172A; color:#fff;">ذخیره</button>
                <button type="button" class="btn" id="pm-cancel" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
            </div>
        </form>
    </div>
</div>

<script>
window.PRICE_MAP = <?= json_encode($graphJson, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../assets/js/price-map.js"></script>
