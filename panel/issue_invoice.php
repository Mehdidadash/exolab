<?php
// panel/issue_invoice.php — «صدور فاکتور» (هابِ همهٔ انواع فاکتور)
//
// یک نقطهٔ ورود واحد برای صدور فاکتورِ همهٔ طرف‌حساب‌ها:
//   پزشک / کلینیک درمانی · کلینیک · لابراتوار همکار · کارِ برون‌سپاری · طراح · طلب از شعبه
// هر کدام از این صفحات، دو حالت «ماه شمسی» و «بازهٔ دلخواه» را پشتیبانی می‌کنند.
require_once __DIR__ . '/auth.php';
require_admin();

$canBranchInvoice = is_root_admin() || is_branch_scoped();

/**
 * کارت‌های صدور فاکتور
 * هر آیتم: عنوان، توضیح، آدرسِ فرمِ صدور، رنگ، و (اختیاری) لیستِ مربوطه
 */
$kinds = [
    [
        'key'    => 'doctor',
        'title'  => 'پزشک / کلینیک درمانی',
        'desc'   => 'صورت‌حساب کارهای فاکتورنشدهٔ یک پزشک، همراه با انتقالِ مانده بدهی قبلی و انتخاب حساب بانکی.',
        'href'   => 'generate_invoice.php',
        'color'  => '#0F172A',
        'icon'   => '👤',
        'list'   => 'invoices.php',
        'listLbl'=> 'لیست فاکتورها',
    ],
    [
        'key'    => 'clinic',
        'title'  => 'کلینیک',
        'desc'   => 'فاکتور کلینیک برای مجموعِ کار پزشکانِ زیرمجموعه، تفکیک‌شده بر اساس هر پزشک.',
        'href'   => 'generate_clinic_invoice.php',
        'color'  => '#059669',
        'icon'   => '🏥',
        'list'   => 'invoices.php',
        'listLbl'=> 'لیست فاکتورها',
    ],
    [
        'key'    => 'lab',
        'title'  => 'لابراتوار همکار',
        'desc'   => 'فاکتور کار لابراتوارهای همکار/برون‌سپاری (به تفکیک پزشک) با امکان مبالغ مثبت و منفی.',
        'href'   => 'generate_lab_invoice.php',
        'color'  => '#7c3aed',
        'icon'   => '🧪',
        'list'   => 'invoices.php',
        'listLbl'=> 'لیست فاکتورها',
    ],
    [
        'key'    => 'outsource',
        'title'  => 'کارِ برون‌سپاری (هزینه)',
        'desc'   => 'فاکتورِ هزینهٔ کارهایی که به لابراتوار بیرونی سپرده‌ایم — بر اساس نرخ برون‌سپاری هر لابراتوار.',
        'href'   => 'generate_outsource_invoice.php',
        'color'  => '#b45309',
        'icon'   => '📤',
        'list'   => 'outsource_invoices.php',
        'listLbl'=> 'لیست فاکتورهای برون‌سپاری',
    ],
    [
        'key'    => 'designer',
        'title'  => 'طراح',
        'desc'   => 'صورت‌حساب «فی طراحی» طراح، بر اساس نرخِ ثبت‌شده روی هر کیس (نه نرخ روز).',
        'href'   => 'generate_designer_invoice.php',
        'color'  => '#d97706',
        'icon'   => '✏️',
        'list'   => 'designer_invoices.php',
        'listLbl'=> 'لیست فاکتورهای طراحی',
    ],
    [
        'key'    => 'branch',
        'title'  => 'طلب از شعبه',
        'desc'   => 'فاکتورِ طلب از شعبهٔ همکار برای کارهایی که آن شعبه به ما سپرده است.',
        'href'   => 'generate_branch_receivable.php',
        'color'  => '#be123c',
        'icon'   => '🏬',
        'list'   => 'branch_receivables.php',
        'listLbl'=> 'لیست طلب‌ها',
        'hide'   => !$canBranchInvoice,
    ],
];

panel_layout_start('صدور فاکتور');
?>

<div class="form-card" style="margin-bottom:18px;">
    <h3 style="margin-bottom:6px;">صدور فاکتور</h3>
    <p style="color:#525252; margin:0;">
        نوع فاکتور را انتخاب کن. برای همهٔ انواع می‌توانی بازه را
        <strong>«ماه شمسی»</strong> یا <strong>«بازهٔ دلخواه (از تاریخ … تا تاریخ …)»</strong> بدهی،
        پیش‌نمایش بگیری، کیس‌های موردنظر را تیک بزنی و بعد فاکتور را ایجاد کنی.
    </p>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px;">
    <?php foreach ($kinds as $k): if (!empty($k['hide'])) continue; ?>
        <div class="form-card" style="padding:16px 18px; display:flex; flex-direction:column; gap:10px; border-top:4px solid <?= $k['color'] ?>;">
            <h4 style="margin:0; font-size:1.02rem;"><?= $k['icon'] ?> <?= htmlspecialchars($k['title']) ?></h4>
            <p style="margin:0; color:#525252; font-size:0.88rem; line-height:1.9;"><?= htmlspecialchars($k['desc']) ?></p>
            <div style="margin-top:auto; display:flex; gap:8px; flex-wrap:wrap;">
                <a class="btn" href="<?= htmlspecialchars($k['href']) ?>" style="background:<?= $k['color'] ?>; color:#fff;">صدور فاکتور</a>
                <?php if (!empty($k['list'])): ?>
                    <a class="btn" href="<?= htmlspecialchars($k['list']) ?>" style="background:#E5E7EB; color:#0F172A;"><?= htmlspecialchars($k['listLbl'] ?? 'لیست') ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="form-card" style="margin-top:18px; padding:14px 18px;">
    <strong style="display:block; margin-bottom:8px;">میان‌بُرها</strong>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn" href="invoices.php" style="background:#0F172A; color:#fff;">همهٔ فاکتورها</a>
        <a class="btn" href="invoice_form.php" style="background:#E5E7EB; color:#0F172A;">ایجاد دستیِ فاکتور</a>
        <a class="btn" href="expenses.php" style="background:#E5E7EB; color:#0F172A;">فاکتورهای مخارج (بدهی‌ها)</a>
        <a class="btn" href="payments.php" style="background:#E5E7EB; color:#0F172A;">دریافتی‌ها</a>
    </div>
</div>

<?php panel_layout_end(); ?>
