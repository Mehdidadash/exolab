<?php
// includes/invoice_types.php
//
// «لایهٔ ۱» — رجیستریِ انواع فاکتور.
//
// چرا این فایل وجود دارد؟
// پیش از این برای هر نوع فاکتور (پزشک/کلینیک، طراح، لابراتوار، برون‌سپاری،
// شعبه، طلب از شعبه) یک جفت صفحهٔ صدور/ویرایش جدا با منطقِ کپی‌شده وجود داشت.
// نتیجه: باگ‌هایی مثل «متن بازه در PDF به‌روز نمی‌شود» یا «افزودن کیس از ماه‌های
// دیگر فقط در بعضی انواع هست» به‌صورت پراکنده و تکراری بروز می‌کرد.
//
// این رجیستری تنها «منبع حقیقت» دربارهٔ تفاوت‌های هر نوع است. صفحات و ویوهای
// مشترک (invoice_items_table.php، شمارش بازه، PDF) از همین‌جا می‌خوانند.
//
// نکته: این فایل هیچ کوئری نمی‌زند و هیچ چیزی را تغییر نمی‌دهد؛ فقط ماهیتِ
// انواع فاکتور را توصیف می‌کند. لایه‌های بعدی روی همین بنا می‌شوند.

if (!function_exists('invoice_types')) {
    /**
     * @return array<string, array{
     *   key:string, label:string, short_label:string,
     *   table:string, items_table:string, item_fk:string,
     *   party_col:string, party_type:string,
     *   prefix:string, default_note:string,
     *   has_payments:bool, has_items:bool, editable:bool,
     *   price_source:string, period_scope:string
     * }>
     */
    function invoice_types(): array
    {
        static $types = null;
        if ($types !== null) {
            return $types;
        }

        $types = [
            // ── فاکتور پزشک / کلینیک ──────────────────────────────────────────
            'doctor' => [
                'key'             => 'doctor',
                'label'           => 'فاکتور پزشک',
                'short_label'     => 'پزشک',
                'table'           => 'doctor_invoices',
                'items_table'     => 'invoice_items',   // ← نام تاریخی (نه doctor_invoice_items)
                'item_fk'         => 'invoice_id',
                'party_col'       => 'doctor_id',
                'party_type'      => 'user',           // طرف حساب یک کاربر است
                'prefix'          => 'INV',
                'default_note'    => 'فاکتور پزشک',
                'has_payments'    => true,
                'has_items'       => true,
                'editable'        => true,
                'price_source'    => 'patient_price',  // قیمتِ بیمار (getApplicablePrice)
                'period_scope'    => 'received_date',
            ],

            // ── فاکتور طراحی (طراح آزاد) ──────────────────────────────────────
            'designer' => [
                'key'             => 'designer',
                'label'           => 'فاکتور طراحی',
                'short_label'     => 'طراح',
                'table'           => 'designer_invoices',
                'items_table'     => 'designer_invoice_items',
                'item_fk'         => 'invoice_id',
                'party_col'       => 'designer_id',
                'party_type'      => 'user',
                'prefix'          => 'INV-DES',
                'default_note'    => 'فاکتور طراحی',
                'has_payments'    => true,
                'has_items'       => true,
                'editable'        => true,
                'price_source'    => 'design_fee',     // دستمزد طراحی
                'period_scope'    => 'received_date',
            ],

            // ── فاکتور لابراتوار (کارِ ما برای لابراتوار) ─────────────────────
            // توجه: جدول اختصاصی ندارد؛ فاکتور لابراتوار در همان doctor_invoices
            // با party = lab ثبت می‌شود. (generate_lab_invoice.php)
            'lab' => [
                'key'             => 'lab',
                'label'           => 'فاکتور لابراتوار',
                'short_label'     => 'لابراتوار',
                'table'           => 'doctor_invoices',
                'items_table'     => 'invoice_items',
                'item_fk'         => 'invoice_id',
                'party_col'       => 'doctor_id',
                'party_type'      => 'user',
                'prefix'          => 'LAB',
                'default_note'    => 'فاکتور لابراتوار',
                'has_payments'    => true,
                'has_items'       => true,
                'editable'        => true,
                'price_source'    => 'patient_price',
                'period_scope'    => 'received_date',
            ],

            // ── فاکتور برون‌سپاری (پرداختِ ما به لابراتوار — هزینه) ────────────
            'outsource' => [
                'key'             => 'outsource',
                'label'           => 'فاکتور برون‌سپاری',
                'short_label'     => 'برون‌سپاری',
                'table'           => 'outsource_invoices',
                'items_table'     => 'outsource_invoice_items',
                'item_fk'         => 'invoice_id',
                'party_col'       => 'lab_id',
                'party_type'      => 'user',
                'prefix'          => 'OUT',
                'default_note'    => 'فاکتور برون‌سپاری',
                'has_payments'    => true,
                'has_items'       => true,
                'editable'        => true,
                'price_source'    => 'outsource_rate', // نرخ توافقیِ برون‌سپاری
                'period_scope'    => 'received_date',
            ],

            // ── فاکتور طلب از شعبه همکار (درآمدِ ما از شعبه — دریافتنی) ───────
            'branch' => [
                'key'             => 'branch',
                'label'           => 'فاکتور طلب از شعبه',
                'short_label'     => 'شعبه',
                'table'           => 'branch_receivables',
                'items_table'     => 'branch_receivable_items',
                'item_fk'         => 'receivable_id',   // ← نامِ متفاوت
                'party_col'       => 'partner_branch_id',
                'party_type'      => 'branch',          // طرف حساب یک شعبه است، نه کاربر
                'prefix'          => 'REC',
                'default_note'    => 'فاکتور طلب از شعبه همکار',
                'has_payments'    => true,
                'has_items'       => true,
                'editable'        => true,
                'price_source'    => 'outsource_rate',
                'period_scope'    => 'received_date',
                'extra_columns'   => ['branch_id'],     // ستون‌های اضافه‌ی این جدول
            ],

            // ── فاکتور کلینیک ────────────────────────────────────────────────
            // کلینیک هم جدول اختصاصی ندارد؛ در doctor_invoices ثبت می‌شود.
            // (generate_clinic_invoice.php)
            'clinic' => [
                'key'             => 'clinic',
                'label'           => 'فاکتور کلینیک',
                'short_label'     => 'کلینیک',
                'table'           => 'doctor_invoices',
                'items_table'     => 'invoice_items',
                'item_fk'         => 'invoice_id',
                'party_col'       => 'doctor_id',
                'party_type'      => 'user',
                'prefix'          => 'CLN',
                'default_note'    => 'فاکتور کلینیک',
                'has_payments'    => true,
                'has_items'       => true,
                'editable'        => true,
                'price_source'    => 'patient_price',
                'period_scope'    => 'received_date',
            ],
        ];

        return $types;
    }

    /** یک تعریف را با کلید بگیر (یا null). */
    function invoice_type(string $key): ?array
    {
        $all = invoice_types();
        return $all[$key] ?? null;
    }

    /** همهٔ کلیدهای معتبر. */
    function invoice_type_keys(): array
    {
        return array_keys(invoice_types());
    }

    /**
     * فقط انواعی که جدولشان واقعاً وجود دارد (برای محیط‌هایی که بعضی جداول
     * هنوز ساخته نشده‌اند). نتیجه کش می‌شود.
     */
    function invoice_types_available(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $out = [];
        foreach (invoice_types() as $key => $def) {
            if (function_exists('db')) {
                try {
                    $st = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                    $st->execute([$def['table']]);
                    if ((int) $st->fetchColumn() === 0) {
                        continue;
                    }
                } catch (Throwable $e) {
                    // در نبود دسترسی به information_schema، خوش‌بینانه ادامه بده
                }
            }
            $out[$key] = $def;
        }
        return $cache = $out;
    }

    /**
     * کدام انواع فاکتور، مبلغشان از نرخ برون‌سپاری می‌آید؟ (برای شمارش بازه)
     */
    function invoice_types_with_outsource_pricing(): array
    {
        return array_keys(array_filter(invoice_types(), function ($d) {
            return ($d['price_source'] ?? '') === 'outsource_rate';
        }));
    }
}
