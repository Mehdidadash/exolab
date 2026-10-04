# ExoLab — وب‌سایت لابراتوار دیجیتال دندانسازی

سیستم مدیریت جامع کیس، فاکتور، پرداخت، برچسب‌زنی و نوتیفیکیشن برای لابراتوار دندانسازی دیجیتال — با پشتیبانی چندشعبه (شعبه اصلی و قزوین)، برون‌سپاری بین‌شعبه‌ای، دو نمای مالی (طلب/بدهی)، نوبت‌دهی اسکن و کتابخانهٔ اسکن‌بادی.

---

## وضعیت فعلی (October 2026)

پروژه در مرحله تولید است و همه ماژول‌ها تکمیل شده‌اند؛ از جمله سیستم چندشعبه، فاکتور طلب بین‌شعبه‌ای، پیکر انتخاب دندان (با بریج و اتصال میان‌خط)، بررسی درآمد و هزینه با نمودار و گزارش‌های تفکیکی، نوبت‌دهی اسکن با تقویم، مدیریت انواع/کتابخانهٔ اسکن‌بادی و نقشهٔ یکپارچهٔ قیمت‌ها.

نسخهٔ دیتابیس: **۷۳ مهاجرت** (`001` تا `073`) — همه با `database/migrate.php` قابل اجرا و idempotent هستند.

### آخرین تغییرات (October 2026)

- **نوع اتصال پروتز** (`connection_type`): سه گزینهٔ `screw-retained` (پیچ‌شونده)،
  `cement-retained` (سیمانی‌شونده) و `screw-cemented` (پیچ‌شونده + سیمانی‌شونده؛
  «screw access hole با کامپوزیت پر می‌شود») — در فرم ثبت/ویرایش کیس و نمایش در صفحهٔ کیس.
- **ذخیره و ایجاد کیس زیرمجموعه:** دکمهٔ بنفش کنار «ذخیره» که پس از ثبتِ موفقِ کیس،
  فرم را با پیش‌مقدار‌های هوشمند باز می‌کند: کلینیک و وضعیت از کیس والد، و نوع خدمت
  پیشنهادی بر اساس قاعدهٔ زیر.
- **قاعدهٔ پیشنهاد خدمتِ زیرمجموعه:** «روکش/فریم پایه ایمپلنت» ⇢ `custom_abutment`
  (کاستوم اباتمنت کره‌ای)؛ «کاستوم اباتمنت کره‌ای/اروپایی» ⇢ `abutment_crown`
  (مولتی‌لیر پایه ایمپلنت / پایه اباتمنت). خدمات بدون قابلیت زیرمجموعه پیشنهاد نمی‌شوند.
- **تغییر نام پوشهٔ فایل‌ها:** در `uploads.php` و صفحهٔ کیس — نام همهٔ فایل‌های یک پوشه
  با هم عوض می‌شود (`rename_upload_folder.php`)، با اعتبارسنجی مسیر و جلوگیری از تکرار.
- **نمای Details فایل‌ها (شبیه Windows Explorer):** کامپوننت مشترک
  `includes/file_details_view.php` با سرستون‌های هم‌تراز (نام | حجم | نوع | تاریخ | عملیات)،
  ردیف‌های پوشه/فرزند، جمع‌بستن پوشه، و دکمه‌هایی که وقتی کاربر دسترسی ندارد
  نمایش داده نمی‌شوند. در `uploads.php` و صفحهٔ کیس استفاده می‌شود.
- **فیلترهای صفحهٔ کیس‌ها:** افزون بر «فاکتورنشده‌ها» و «نمایش تحویل‌شده‌ها»، فیلتر
  **«برچسب‌نشده‌ها»** (`?unprinted_labels=1`) کیس‌هایی را نشان می‌دهد که
  `label_printed_at` خالی است. همهٔ این فیلترها **سمت سرور** اعمال می‌شوند (چون جدول
  Ajax با صفحه‌بندی است) و وضعیتشان در آدرس ذخیره می‌شود.
- **آیکون و گروه‌بندی فرم‌ها:** کتابخانهٔ SVG داخلی (`includes/icons.php`، بدون CDN)
  با تابع `field_icon()` روی برچسب فیلدها + عنوان‌بخش برای فرم کیس
  (اطلاعات کیس و بیمار / خدمت و مشخصات فنی / قیمت‌گذاری و تاریخ / فایل‌های کیس).
- **بهبود منوی موبایل:** پنل منو در گوشی ارتفاع محدود و اسکرول مستقل دارد (زیرمنوی
  بلند «مالی» دیگر باعث پنهان‌شدن بقیهٔ گزینه‌ها نمی‌شود)، هدف لمسی ≥۴۴px، و در
  حالت landscape لوگو/نام کوچک شده و `.brand` با منو در یک ردیف نازک می‌نشیند.

---

## ویژگی‌ها

### سایت عمومی (`index.php`, `contact.php`)
- صفحه اصلی با معرفی خدمات، لیست قیمت و نمونه کارها
- گالری تصاویر با لایت‌باکس
- نقشه و مسیریابی دو شعبه (اسلامشهر و قزوین)
- سرو عکس نمونه‌کار از پوشه‌ی بیرونِ وب با `media.php`

### پنل مدیریت (`panel/`)

| ماژول | توضیحات |
|-------|---------|
| **احراز هویت** | ورود با کپچا (بعد از ۳ تلاش ناموفق)، محدودیت تلاش تدریجی، سشن با مسیر اختصاصی و عمر ۶۰۰ روزه |
| **مدیریت نقش‌ها** | جدول `roles` با مجوزهای پویا — از طریق پنل قابل مدیریت |
| **مدیریت کاربران** | ۱۵+ نقش شامل مدیر، مدیر شعبه، پزشک، منشی، طراح، لابراتوار (برون‌سپاری/مشتری/همکار)، کلینیک و... — مدیر کل: همهٔ کاربران؛ **مدیر شعبه: فقط کاربرانِ شعبهٔ خودش** (بدون امکان ساختِ نقش‌های مدیریتی) |
| **سیستم چندشعبه** | شعبه اصلی (اسلامشهر) و شعبه قزوین؛ مالکیت کیس (`branch_id`) و شعبه همکار (`source_branch_id`)؛ دسترسی پزشکان هر شعبه (`branch_doctor_access`)؛ مدیر هر شعبه (`branch_admin`) |
| **شبکه همکاران** | فهرست یکجای پزشکان، کلینیک‌ها و لابراتوارها همراه پزشکانِ زیرمجموعهٔ هر کلینیک (`network.php`) |
| **قیمت‌های مشترک و اختصاصی شعبه** | کاتالوگ مشترک خدمات (`site_prices`) + قیمت اختصاصی هر شعبه (`branch_service_prices`) + قیمت اختصاصی هر پزشک |
| **نقشهٔ یکپارچهٔ قیمت‌ها** | جدول واحد `price_links` (provider → receiver) با انواع `general`/`specific`/`outsource`/`design_fee`/`branch_default` + نمای گرافیکی نقشهٔ قیمت (`price_map_tab.php` + `price-map.js`) و جدول‌های قدیمی همگام‌سازی‌شده |
| **ویژگی‌های خدمت** | `design_required` (خدمات بدون طراحی مثل پست NPG/پرینت کست/الاینر) و `requires_scan_body` (اباتمنت/فیکسچر ایمپلنت) — قابل تنظیم از `prices.php` و `price_form.php` |
| **طراح** | انتساب طراح به کیس با نوتیفیکیشن خودکار؛ «طراح پیش‌فرض» (`is_default_designer`) با انتساب خودکار هنگام ثبت کیس |
| **مدیریت کیس‌ها** | ثبت/ویرایش/حذف، فیلتر، سرچ، آپلود فایل (STL/PLY/stp/obj/3mf + تصاویر + zip/rar)، نوع فایل (`file_type`)، آپلود پوشه‌ای با حفظ ساختار، فایل‌های مشترک بین کیس‌ها |
| **فیلترهای لیست کیس‌ها** | «فاکتورنشده‌ها» (در هیچ فاکتوری نیامده)، «برچسب‌نشده‌ها» (`label_printed_at` خالی)، «نمایش/مخفی تحویل‌شده‌ها» — همه سمت سرور و با حفظ وضعیت در آدرس؛ افزون بر بازهٔ تاریخ شمسی، کلینیک و SearchPanes |
| **نوع اتصال پروتز** | `connection_type` روی کیس: پیچ‌شونده / سیمانی‌شونده / پیچ‌شونده + سیمانی‌شونده؛ همراه فیلد «نوع اسکن‌بادی» تنها برای خدماتی که `requires_scan_body` دارند |
| **ذخیره + ایجاد زیرمجموعه** | دکمهٔ «ذخیره و ایجاد کیس زیرمجموعه» با پیش‌مقدار کلینیک/وضعیت از والد و پیشنهاد هوشمند نوع خدمت |
| **تغییر نام پوشهٔ فایل‌ها** | تغییر نام گروهیِ فایل‌های یک پوشه در صفحهٔ آپلود و صفحهٔ کیس (`rename_upload_folder.php`) |
| **نمای Details فایل‌ها** | کامپوننت مشترک `includes/file_details_view.php` شبیه Windows Explorer: سرستون هم‌تراز، پوشه/فرزند، جمع‌بستن، دکمه‌های هم‌تراز و مخفی‌شده برای کاربران بی‌دسترسی |
| **زیرمجموعه کیس** | قابلیت تعریف کیس زیرمجموعه (parent_id) برای پروتزهای چندبخشی — نمایش خودکار والد/زیرکیس‌ها برای شعبه‌ها |
| **پیکر انتخاب دندان** | انتخاب گرافیکی دندان‌ها با بریج و اتصال میان‌خط (۱۱–۲۱، ۴۱–۳۱)، نرمال‌سازی اعداد/جداکننده فارسی، نمودار فقط‌خواندنی در صفحه مشاهده کیس |
| **آیکون‌های فیلد و بخش‌بندی فرم** | کتابخانهٔ SVG داخلی `includes/icons.php` (فونت‌آوسام Free Solid، بدون CDN) + `svg_icon()`/`field_icon()`؛ گروه‌بندی فرم کیس با `.form-section-title` |
| **منوی موبایل و landscape** | پنل منوی اسکرول‌دار روی گوشی، هدف لمسی ≥۴۴px، و هدر نازک (≈۴۶px) در landscape با قرار گرفتن `.brand` و `.site-nav` در یک ردیف |
| **نوبت‌دهی اسکن** | تقویم FullCalendar (لوکال، بدون CDN) + نمای لیست؛ بازهٔ ساعتی برای هر پزشک؛ کشیدن‌ورهاکردنِ نوبت‌ها؛ فیلتر پزشک/نوع/وضعیت/جست‌وجو؛ داشبورد نوبت‌های امروز و فردا؛ نوبت‌های مرتبط با کیس در صفحهٔ کیس |
| **اسکن‌بادی (فیکسچر ایمپلنت)** | کاتالوگ `scan_body_types` (اویتا، انی‌ریج و ...) با ترتیب/فعال‌بودن/توضیحات؛ انتخاب نوع در نوبت اسکن و در کیسِ خدماتی که `requires_scan_body` دارد؛ **کتابخانهٔ دانلود** (لینک خارجی یا فایل تا ۲۰۰MB در `storage/scan_body_library/`) |
| **دسترسی کتابخانهٔ اسکن‌بادی** | مدیر سیستم/مدیر شعبه: دسترسی کامل | **طراح: فقط مشاهده و دانلود** (لینک در منوی «کیس‌ها») |
| **برون‌سپاری** | برون‌سپاری کامل (`lab_out`) و برون‌سپاری جانبی (`outsourced_lab_id/service/qty/rate`)؛ نرخ‌های برون‌سپاری به تفکیک لابراتوار و خدمت (شعبه‌ای/سراسری) |
| **کار از لابراتوار همکار** | کیس‌های `lab_in`؛ هر شعبه کیس‌های برون‌سپاری‌شده به خودش را با نشان «کار از لابراتوار همکار» می‌بیند |
| **فاکتور برون‌سپاری** | صدور فاکتور لابراتوارها (`outsource_invoices`) با فرم و PDF، محاسبه نرخ از `price_links`/`outsource_rates` |
| **فاکتور طراحان** | صدور فاکتور طراح (`designer_invoices`) با نرخِ واحدِ طراحی (`unit_design_fee`) و PDF |
| **فاکتور طلب از شعبه همکار** | صدور فاکتور طلب بین‌شعبه‌ای (`branch_receivables`) + ثبت دریافت + PDF؛ دید معکوس (بدهی به شعب دیگر) در کیس‌های مخارج |
| **دو نمای مالی** | هر کار بین‌شعبه‌ای یک کیس، دو نما: هزینه/بدهی فرستنده و درآمد/طلب گیرنده |
| **نمایشگر سه‌بعدی** | نمایش STL/PLY با Three.js + چرخش/زوم/رزت |
| **مدیریت فاکتورها** | ایجاد دستی + صدور فاکتور ماهانه خودکار؛ ستون «فی» (قیمت واحد)، «تعداد»، «جمع» خودکار (فی×تعداد)، تخفیف منفی، آیکون حذف، PDF |
| **PDF فاکتور** | فونت کوچک مناسب چاپ، حذف بک‌گراند تیره، نام ماه بر اساس تاریخ دریافت کیس‌ها، خلاصه «تعداد خدمات به تفکیک» زیر جدول |
| **مدیریت پرداخت‌ها** | ثبت با روش‌های مختلف، اتصال به فاکتور |
| **بررسی درآمد و هزینه** | درآمد/هزینه تحقق‌یافته و تحقق‌نیافته، نمودار ۱۲ ماه اخیر، نمودار دایره‌ای بر اساس پزشک/نوع کار/لابراتوار/کلینیک + دایمنشن‌های «دریافتی از شعب همکار» و «برون‌سپاری‌شده» با جدول تفکیک نوع کار و مبدأ/مقصد، و آمار تعداد خدمات انجام‌شده |
| **حساب بانکی** | تعریف چند حساب برای درج در فاکتور PDF |
| **چاپ برچسب** | انتخاب گروهی کیس‌ها + تولید PDF با QR کد (دو ستون A4) |
| **تغییر وضعیت گروهی** | انتخاب چند کیس و تغییر وضعیت یکجا |
| **وضعیت‌های کیس** | CRUD وضعیت‌ها با ترتیب، آیکون، رنگ و پرچمِ «غیرعادی» (اعلان به کاربران مرتبط) + مرتب‌سازی کشیدنی |
| **خروجی CSV** | خروجی کیس‌های انتخاب شده برای لابراتوار (بدون نام دکتر) |
| **نمایه پزشک** | صفحه اختصاصی با آمار کیس‌ها، فاکتورها و پرداخت‌ها + آپلود عکس گالری |
| **پروفایل من** | ویرایش نام/ایمیل/تلفن و تغییر رمز عبور برای همهٔ نقش‌ها |
| **سلسله‌مراتب کلینیک** | کلینیک می‌تواند کیس‌ها و فاکتورهای پزشکان زیرمجموعه را ببیند؛ یک پزشک می‌تواند عضو چند کلینیک باشد (`user_clinics`) |
| **نوتیفیکیشن** | سیستم داخلی + ایمیل — هنگام انتصاب لابراتوار/طراح، تغییر وضعیت غیرعادی و نوبت اسکن |
| **کامنت و یادداشت** | کامنت روی کیس/موجودیت‌ها (`entity_comments`) + پسندیدن کامنت (`comment_likes`) + افزودن یادداشت پزشک |
| **آپلود کاربر** | آپلود مستقل فایل توسط کاربران بیرونی + اتصال آپلودها به کیس‌ها (`user_upload_case_links`) + دانلود پوشه‌ای ZIP |
| **لاگ فعالیت‌ها** | ثبت خودکار همه عملیات‌ها (create/update/delete) + لاگ فعالیت کیس (`case_activity_log`) |
| **آرشیو** | نمای آرشیو کیس‌ها (`view_archive.php`) |
| **داشبورد** | آمار کلی: کیس‌ها، پزشکان، کاربران، فاکتورها، درآمد + نوبت‌های اسکن امروز/فردا |

### امنیت
- رمز عبور با `password_hash()` + `password_verify()`
- CSRF Protection در تمام فرم‌ها (POST + Header)
- Rate limiting + کپچا در ورود
- Prepared statements برای همه کوئری‌ها
- دسترسی نقش‌ها از دیتابیس (`roles` table)
- مخفی‌سازی فیلدهای لابراتوار/طراح برای پزشکان و کلینیک
- ماسک‌کردن وضعیت «ارسال به لاب همکار» برای کاربرانِ بیرونی (با نام، نه شناسه)
- نگهبانِ فایل‌های آپلودی: `storage/scan_body_library` و `uploads` بیرون از دسترسی مستقیم وب + `.htaccess`
- اعتبارسنجی لینک کتابخانه (فقط `http`/`https`)، محافظت از path traversal در مسیر فایل‌ها، محدودیت پسوند و حجم (۲۰۰MB)
- سشن در مسیر اختصاصی `storage/sessions` (با `.htaccess` محافظت‌شده) برای جلوگیری از لاگ‌اوت زودهنگام در هاست اشتراکی
- رفع باگ خروج: `logout.php` اکنون سشن واقعی را با همان مسیر اختصاصی نابود می‌کند

---

## معماری پروژه

```
├── index.php                 # صفحه اصلی سایت
├── contact.php               # تماس با ما
├── media.php                 # سرو عمومی عکس‌های نمونه‌کار (از پوشه‌ی آپلود بیرونِ وب)
├── _roles.php                # داده‌های نقش‌ها (بوت‌استرپ)
├── .env                      # Credentials (دیتابیس، SMTP، MIGRATE_KEY، UPLOADS_ROOT)
├── composer.json
├── src/
│   ├── Database/
│   │   └── Connection.php    # اتصال PDO (Singleton)
│   └── Services/
│       ├── InvoiceService.php
│       ├── PriceService.php
│       └── WorkService.php
├── assets/
│   ├── css/ (style.css, case-teeth-picker.css, price-map.css, lightbox.css, datatables, persian-datepicker)
│   ├── js/ (jQuery, DataTables, invoice-items.js, case-teeth-picker.js, shade-picker.js,
│   │        price-map.js, admin-order.js, lightbox.js, main.js, persian-datepicker, three/)
│   ├── lib/fullcalendar/     # تقویم (لوکال، بدون CDN)
│   ├── fonts/ · icons/
├── includes/
│   ├── helpers.php           # توابع تاریخ فارسی، CSRF، renderTeethChart، formatTomanInput، formatFileSize، sanitizeRelPath
│   ├── icons.php             # SVG icons + field_icon()/svg_icon() + action_dropdown() + اسکریپت منوی عملیات
│   ├── file_details_view.php # نمای Details فایل‌ها (کامپوننت مشترک، شبیه Windows Explorer)
│   ├── invoice_items_table.php # جدول آیتم‌های فاکتور (کامپوننت مشترک)
│   ├── invoice_types.php     # انواع فاکتور
│   ├── captcha.php           # کپچای ورود
│   ├── EnvLoader.php         # بارگذاری .env
│   └── QrGenerator.php       # تولید QR Code
├── storage/
│   ├── sessions/             # مسیر اختصاصی سشن (محافظت‌شده با .htaccess)
│   └── scan_body_library/    # فایل‌های کتابخانهٔ اسکن‌بادی (خارج از وب)
├── uploads/                  # فایل‌های آپلودی (با .htaccess بسته)
├── panel/
│   ├── auth.php              # احراز هویت + مجوزها + قالب layout + تنظیم سشن
│   ├── db.php                # PDO + تمام توابع دیتابیس + ensure* (ساخت خودکار ستون‌ها)
│   ├── dashboard.php
│   ├── cases.php / cases_data.php / save_case.php / view_case.php / view_archive.php / case_expenses.php
│   ├── case_statuses.php / save_case_status.php / delete_case_status.php / reorder_case_statuses.php
│   ├── scan_appointments.php / scan_appointments_data.php / save_scan_appointment.php
│   ├── scan_body_types.php / save_scan_body_type.php / delete_scan_body_type.php
│   ├── upload_scan_body_library.php / download_scan_body_library.php / delete_scan_body_library.php
│   ├── invoices.php / invoice_form.php / save_invoice.php / invoice_pdf.php
│   ├── designer_invoices.php / designer_invoice_form.php / designer_invoice_pdf.php
│   ├── outsource_invoices.php / outsource_invoice_form.php / outsource_invoice_pdf.php
│   ├── prices.php / prices_general_tab.php / price_map_tab.php / price_form.php / save_price.php
│   ├── save_price_link.php / delete_price_link.php
│   ├── financial_overview.php
│   ├── branch_receivables.php / generate_branch_receivable.php / branch_receivable_pdf.php
│   ├── branches.php / branch_form.php / branch_prices.php / save_branch_service_price.php
│   ├── network.php
│   ├── generate_invoice.php / generate_lab_invoice.php / generate_clinic_invoice.php
│   ├── generate_outsource_invoice.php / generate_designer_invoice.php
│   ├── outsource_rates.php / save_outsource_rate.php / get_outsource_rate.php
│   ├── expenses.php / expense_payments.php / expense_payment_form.php / save_expense_payment.php
│   ├── users.php / user_form.php / save_user.php / user_view.php / my_profile.php
│   ├── roles.php / role_form.php / save_role.php / delete_role.php
│   ├── notifications.php / check_notifications.php
│   ├── doctors.php / doctor_form.php / doctor_view.php / save_doctor_gallery.php
│   ├── payments.php / payment_form.php / save_payment.php
│   ├── bank_accounts.php / bank_account_form.php / save_bank_account.php
│   ├── print_labels.php / batch_update_status.php / batch_update_designer.php / export_cases_csv.php
│   ├── uploads.php / upload_case_files.php / upload_user_file.php
│   ├── rename_upload_folder.php  # تغییر نام گروهیِ فایل‌های یک پوشه
│   ├── serve_case_file.php / serve_doctor_image.php / serve_user_upload.php
│   ├── download_case_file.php / download_case_files.php / download_design_files.php
│   ├── download_user_upload.php / download_upload_folder.php
│   ├── link_case_file_to_case.php / unlink_case_file_from_case.php
│   ├── link_user_upload_to_case.php / unlink_user_upload_from_case.php
│   ├── save_comment.php / toggle_comment_like.php / append_doctor_note.php
│   ├── works.php / work_form.php / save_work.php / delete_work.php
│   └── ...
├── database/
│   ├── migrate.php            # اجرای Migration (CLI + Web با MIGRATE_KEY)
│   ├── exolabir_index.sql     # اسکیمای کامل (dump)
│   ├── price_links_host_seed.sql
│   └── migrations/            # 001 تا 073
│       ├── 001_create_cases.sql
│       ├── 007_create_roles_table.sql
│       ├── 024_branches.sql            # شعب و مالکیت
│       ├── 029_shared_prices_and_default_designer.sql
│       ├── 031_branch_receivables.sql  # طلب بین‌شعبه‌ای
│       ├── 033_central_lab_user.sql    # لابراتوار مرکزی
│       ├── 036_price_links.sql / 037_unify_price_links.sql / 047_unify_price_links_direction.sql
│       ├── 038_case_files_uploader.sql / 039_case_files_file_type.sql
│       ├── 041_case_activity_log.sql / 053_user_upload_case_links.sql
│       ├── 054_case_statuses_order_icon.sql / 056_case_statuses_abnormal.sql
│       ├── 060_site_prices_design_required.sql / 062_site_prices_short_name.sql
│       ├── 063_scan_appointments_and_service_pricing.sql   # نوبت اسکن + قیمت پله‌ای
│       ├── 064_doctor_multiple_clinics_and_case_clinic.sql
│       ├── 068_case_files_rel_path.sql / 069_user_uploads_rel_path.sql
│       ├── 070_fix_branch_receivable_charset.sql
│       ├── 071_scan_body_types.sql                          # انواع اسکن‌بادی
│       ├── 072_scan_body_library_and_service_flag.sql       # کتابخانهٔ اسکن‌بادی + requires_scan_body
│       └── 073_connection_type.sql                          # نوع اتصال پروتز (connection_type)
└── vendor/ (Composer)
```

## Migration و ساختِ خودکار ستون‌ها

`database/migrate.php` همهٔ فایل‌های `.sql` را به ترتیب اجرا می‌کند و اعمال‌شده‌ها را
در جدول `_migrations` نگه می‌دارد (اجرای دوباره بی‌خطر است).

نکات مهم:

- **Web mode** فقط با `MIGRATE_KEY` در `.env`/`config.php` فعال است — مناسب هاست
  اشتراکی بدون SSH:
  `https://exolab.ir/database/migrate.php?key=YOUR_MIGRATE_KEY`
- **تطبیق خودکار (reconcile):** اگر دیتابیسی برگردانده شود که اسکیمای یک مهاجرت را
  «از قبل» دارد ولی ردیفش در `_migrations` نیست، به‌جای خطای «Duplicate column/table»
  آن مهاجرت «اعمال‌شده» علامت می‌خورد (`$ALREADY_CHECKS`).
- خطاهای تکراری MySQL (1050/1060/1061) در هر statement تحمل می‌شوند.
- **علاوه بر مهاجرت‌ها، `panel/db.php` هنگام اتصال چند ستون/جدول را در صورت نبود
  خودکار می‌سازد** (`ensure*`)، تا پنل روی دیتابیس قدیمی هم بدون خطا بالا بیاید:
  `ensureSitePricesOrderColumn`, `ensureSitePricesDesignRequiredColumn`,
  `ensureSitePricesRequiresScanBodyColumn`, `ensureSitePricesShortNameColumn`,
  `ensureEntityCommentsTable`, `ensureNotificationsTable`, `ensureCasesDesignFeeColumn`,
  `ensureDoctorPriceOverrideTypeColumn`, `ensureCasesOutsourcedRateColumn`,
  `ensureCaseFilesDescriptionColumn`, `ensureCaseFilesRelPathColumn`,
  `ensureUserUploadsDescriptionColumn`, `ensureUserUploadsRelPathColumn`,
  `ensureCaseFileCaseLinksTable`, `ensureScanAppointmentsTable`, `ensureScanBodyTypes`,
  `ensureServicePricingColumns`, `ensureUserClinicsTable`, `ensureCasesClinicColumn`,
  `ensureBranchReceivableCharset`.

> ⚠️ **کاراکترست:** همیشه `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` را در `CREATE TABLE`
> بنویسید. مهاجرت ۰۳۱ آن را نداشت و MaríaDB جداول را با `latin1` ساخت و متن فارسی
> به `?` تبدیل شد (مهاجرت ۰۷۰ فقط ساختار را درست می‌کند؛ دادهٔ ازدست‌رفته برنمی‌گردد).

---

## پوشه‌ی آپلودها

همه‌ی فایل‌های آپلودی — فایل کیس (`cases/<id>/`)، گالری پزشک (`doctors/`)، آپلود
کاربر (`user/`) و عکس نمونه‌کار (`work_*.jpg`) — در پوشه‌ی `uploads` **داخلِ ریشه‌ی
پروژه** ذخیره می‌شوند:

- لوکال: `<project>/uploads`
- هاست اشتراکی: `public_html/uploads`  (ریشه‌ی پروژه = پوشه‌ای که `panel/` داخل آن است)

این مسیر به‌صورت خودکار از روی محل فایل‌ها محاسبه می‌شود (`panel/config.php` → ثابت
`UPLOADS_ROOT` و توابع `uploads_path()` / `ensure_uploads_dir()`). اگر در هاست مسیر
واقعی فرق داشت، مقدار دقیق را با کلید `UPLOADS_ROOT` در `.env` بدهید (مثلاً
`UPLOADS_ROOT=/home/username/public_html/uploads`). پوشه‌ها در لحظه‌ی آپلود ساخته
می‌شوند.

> چون این پوشه داخلِ وب است، فایل `.htaccess` داخل آن دسترسی مستقیمِ وب را می‌بندد؛
> همه‌ی نمایش/دانلودها فقط از طریق اندپوینت‌های PHP زیر انجام می‌شود.

سرو فایل‌ها:
- `media.php?f=...` — عکس نمونه‌کار برای سایت عمومی (فقط تصویر)
- `panel/serve_case_file.php?id=...` — پیش‌نمایش/نمایش فایل کیس (با احراز هویت و مجوز کیس)
- `panel/serve_doctor_image.php?did=...&f=...` — عکس گالری پزشک (با احراز هویت)
- `panel/serve_user_upload.php?...` — آپلودهای کاربر (با احراز هویت)
- دانلودها از طریق `download_case_file.php` / `download_case_files.php` /
  `download_design_files.php` / `download_user_upload.php` / `download_upload_folder.php`
  (ZIP پوشه‌ای) انجام می‌شود.

نکته‌ی دیپلوی: پوشه‌ی `uploads` داده‌ی زنده است و نباید هنگام آپلود کد به‌روزرسانی
روی هاست بازنویسی شود — آن را از بسته‌ی دیپلوی حذف کنید و فقط فایل `.htaccess` آن را
یک‌بار در `public_html/uploads` بگذارید.

## کتابخانهٔ اسکن‌بادی

فایل‌های کتابخانهٔ اسکن‌بادی (فیکسچر ایمپلنت) در `storage/scan_body_library/`
ذخیره می‌شوند — **بیرون از ریشهٔ وب**، پس دسترسی مستقیم به آن‌ها ممکن نیست و همهٔ
دانلودها از `panel/download_scan_body_library.php` عبور می‌کنند که احراز هویت و مجوز
را بررسی و در `audit_log` ثبت می‌کند.

- برای هر نوع اسکن‌بادی می‌توان **یک لینک خارجی** و/یا **یک فایل روی سرور** ثبت کرد.
- اولویتِ دانلود: فایلِ سرور ← وگرنه لینک خارجی (ریدایرکت ۳۰۲).
- محدودیت‌ها: حجم تا ۲۰۰MB، پسوندهای مجاز (zip/rar/7z/stl/ply/obj/3mf/stp/step/pdf/
  تصویر/آفیس/متنی)، لینک فقط `http`/`https`، مسیر فایل در برابر path-traversal محافظت‌شده.
- دسترسی: مدیر سیستم و مدیران شعبه (کامل)، طراح‌ها (فقط مشاهده و دانلود).

در هاست، از وجود و قابل‌نوشتن بودن این پوشه مطمئن شوید:
```
storage/scan_body_library/    # مجوز نوشتن برای PHP
```

## نوع اتصال پروتز (connection_type)

ستون `cases.connection_type` (مهاجرت `073`) روشِ نگهداشتِ ترمیم روی اباتمنت را نگه
می‌دارد. سه مقدار مجاز در `connectionTypes()` (فایل `panel/db.php`):

| مقدار در دیتابیس | برچسب فارسی | توضیح |
|---|---|---|
| `screw-retained` | پیچ‌شونده | پیچ از داخل ترمیم به اباتمنت می‌رود |
| `cement-retained` | سیمانی‌شونده | ترمیم روی اباتمنت سیمان می‌شود |
| `screw-cemented` | پیچ‌شونده + سیمانی‌شونده | اباتمنتِ پیچ‌شونده + روکشِ سیمانی‌شونده روی آن |

> ⚠️ `screw-cemented` و `cement-retained` معادل نیستند و نباید جابه‌جا شوند.
> `screw-cemented` یعنی **اباتمنت پیچ می‌شود** و **روکش روی آن سیمان می‌شود**
> (screw access hole با کامپوزیت پر می‌شود).

قواعد اعتبارسنجی در `save_case.php`:
- اگر خدمتِ کیس `requires_scan_body = 0` باشد ⇢ هم `scan_body_type_id` و هم
  `connection_type` **NULL** ذخیره می‌شوند (این دو فقط برای خدمات اباتمنت/فیکسچر معنی دارند).
- اگر `requires_scan_body = 1` باشد ⇢ `connection_type` با `isValidConnectionType()`
  بررسی می‌شود و مقدار نامعتبر ⇢ NULL.

نمایش: کنار فیلد «نوع اسکن‌بادی» در فرم ثبت/ویرایش کیس (`cases.php` و
`#edit-case-modal` در `view_case.php`) و در جعبهٔ اطلاعات صفحهٔ مشاهدهٔ کیس.

## وابستگی‌ها (Composer)

| کتابخانه | کاربرد |
|----------|--------|
| `mpdf/mpdf` ^8.3 | تولید PDF فاکتور، برچسب و فاکتور طلب |
| `chillerlan/php-qrcode` ^6.0 | QR Code برای برچسب‌ها |
| `morilog/jalali` ^3.5 | تبدیل تاریخ شمسی/میلادی |
| `nesbot/carbon` | کار با تاریخ (پیش‌نیاز morilog/jalali) |

نام‌فضای `App\` روی `src/` نگاشت شده است (PSR-4) — سرویس‌های `Connection`, `InvoiceService`,
`PriceService`, `WorkService` در همین نام‌فضا قرار دارند.

**کتابخانه‌های سمت کاربر (لوکال، بدون CDN):**
`jQuery` · `DataTables` · `persian-date` / `persian-datepicker` · `FullCalendar` ·
`Three.js` (نمایشگر سه‌بعدی) — همه در `assets/js/` و `assets/lib/` میزبانی می‌شوند.

---

## توسعه

برای اعمال migrationها، به آدرس زیر بروید:
```
https://exolab.ir/database/migrate.php?key=YOUR_MIGRATE_KEY
```
(یا از خط فرمان: `php database/migrate.php`)

نمونهٔ فایل `.env`:
```
DB_HOST=localhost
DB_NAME=exolab
DB_USER=root
DB_PASS=...
SMTP_HOST=...
SMTP_PORT=587
SMTP_USER=...
SMTP_PASS=...

# کلید اجرای مهاجرت از طریق وب (بدون آن، Web mode غیرفعال است)
MIGRATE_KEY=...

# در صورت تفاوت مسیر واقعی آپلودها روی هاست
# UPLOADS_ROOT=/home/username/public_html/uploads
```


### نمونه کارها
- `panel/works.php` — فهرست نمونه کار
- `panel/work_form.php` — فرم ایجاد/ویرایش نمونه کار
- `panel/save_work.php` — ذخیره تصویر و داده نمونه کار
- `panel/delete_work.php` — حذف نمونه کار

### پزشکان
- `panel/doctors.php` — فهرست پزشکان
- `panel/doctor_form.php` — فرم پزشک
- `panel/save_doctor.php` — ذخیره یا بروزرسانی پزشک
- `panel/delete_doctor.php` — حذف پزشک

### فاکتورها
- `panel/invoices.php` — لیست فاکتورها
- `panel/invoice_form.php` — ایجاد/ویرایش فاکتور با آیتم‌های قابل اضافه شدن (ستون «فی»، «تعداد»، «جمع» خودکار، تخفیف منفی، آیکون حذف)
- `panel/save_invoice.php` — ذخیره فاکتور و آیتم‌های آن
- `panel/delete_invoice.php` — حذف فاکتور
- `panel/invoice_pdf.php` — تولید PDF فاکتور با تاریخ و اعداد فارسی، نام ماه بر اساس کیس‌ها و خلاصه «تعداد خدمات به تفکیک»
- `panel/generate_invoice.php` — صدور فاکتور ماهانه خودکار پزشک/کلینیک/لابراتوار

### طلب و بدهی بین‌شعبه‌ای
- `panel/branch_receivables.php` — فاکتورهای طلب از شعب همکار + ثبت دریافت
- `panel/generate_branch_receivable.php` — صدور فاکتور طلب
- `panel/branch_receivable_pdf.php` — PDF فاکتور طلب
- `panel/add_branch_receivable_cases.php` — افزودن کیس به فاکتور طلب موجود
- `panel/case_expenses.php` — کیس‌های مخارج + بدهی به شعب دیگر

### نوبت‌دهی اسکن
- `panel/scan_appointments.php` — تقویم FullCalendar + نمای لیست + مودال ثبت/ویرایش
- `panel/scan_appointments_data.php` — فید JSON تقویم (با محدودهٔ دسترسی کاربر)
- `panel/save_scan_appointment.php` — ذخیره (AJAX/JSON) + اعلان به پزشک + لاگ روی کیس
- `panel/delete_scan_appointment.php` — حذف نوبت
- `panel/set_scan_appointment_status.php` — تغییر سریع وضعیت

### انواع و کتابخانهٔ اسکن‌بادی
- `panel/scan_body_types.php` — CRUD انواع (مدیر: کامل | طراح: فقط مشاهده و دانلود)
- `panel/save_scan_body_type.php` / `delete_scan_body_type.php` — ذخیره و حذف نوع
- `panel/upload_scan_body_library.php` — بارگذاری فایل کتابخانه
- `panel/download_scan_body_library.php` — دانلودِ محافظت‌شده (فایل یا لینک خارجی)
- `panel/delete_scan_body_library.php` — حذف فایل کتابخانه (لینک دست‌نخورده می‌ماند)

### وضعیت‌های کیس
- `panel/case_statuses.php` — CRUD وضعیت‌ها (نام، آیکون، رنگ، ترتیب، پرچم «غیرعادی»)
- `panel/save_case_status.php` / `delete_case_status.php` — ذخیره و حذف
- `panel/reorder_case_statuses.php` — ذخیره ترتیب جدید (کشیدنی)

### قیمت‌ها و نقشهٔ نرخ‌ها
- `panel/prices.php` — دو تب: «قیمت‌های عمومی» و «نقشه و نرخ‌های اختصاصی»
- `panel/prices_general_tab.php` — CRUD در‌جا روی `site_prices` (نام اختصاری، قیمت پله‌ای،
  تعداد دستی، ترتیب، فعال، سایت، طراحی، اسکن‌بادی) + دکمهٔ «ویرایش» → فرم کامل
- `panel/price_map_tab.php` + `assets/js/price-map.js` — نقشهٔ گرافیکی `price_links`
- `panel/price_form.php` / `save_price.php` — فرم کامل خدمت
- `panel/save_price_link.php` / `delete_price_link.php` — نرخ‌های اختصاصی/برون‌سپاری/طراحی

### فاکتورهای طراح و برون‌سپاری
- `panel/designer_invoices.php` / `designer_invoice_form.php` / `designer_invoice_pdf.php`
- `panel/generate_designer_invoice.php` — صدور خودکار از کیس‌های فاکتور‌نشدهٔ طراح
- `panel/outsource_invoices.php` / `outsource_invoice_form.php` / `outsource_invoice_pdf.php`
- `panel/generate_outsource_invoice.php` — صدور خودکار فاکتور لابراتوار

### کامنت و یادداشت
- `panel/save_comment.php` — ثبت کامنت روی کیس/موجودیت
- `panel/toggle_comment_like.php` — پسندیدن/برداشتن پسند
- `panel/append_doctor_note.php` — افزودن متن کامنت به یادداشت پزشک

### بررسی درآمد و هزینه
- `panel/financial_overview.php` — کارت‌های درآمد/هزینه، نمودار ۱۲ ماه، نمودار دایره‌ای و جدول‌های تفکیکی (دریافتی/برون‌سپاری‌شده)

### پرداخت‌ها
- `panel/payments.php` — لیست پرداخت‌ها
- `panel/payment_form.php` — ثبت یا ویرایش پرداخت با لینک فاکتورها
- `panel/save_payment.php` — ذخیره پرداخت و ارتباط با فاکتورها
- `panel/delete_payment.php` — حذف پرداخت

### حساب‌های بانکی
- `panel/bank_accounts.php` — لیست حساب‌ها
- `panel/bank_account_form.php` — فرم حساب بانکی
- `panel/save_bank_account.php` — ذخیره اطلاعات حساب
- `panel/delete_bank_account.php` — حذف حساب

## جریان فاکتورها
1. فاکتور جدید در `panel/invoice_form.php` ایجاد می‌شود.
2. کاربر شماره فاکتور، اطلاعات دکتر یا انتخاب دکتر از فهرست، تاریخ فاکتور، تاریخ سررسید و آیتم‌ها را وارد می‌کند.
3. هر آیتم دارای «نوع»، «شرح»، «نام بیمار»، «فی» (قیمت واحد)، «تعداد» و «جمع» است؛ «جمع» خودکار = فی × تعداد و قابل ویرایش نیست. آیتم‌های تخفیف به‌صورت خودکار از کل کسر می‌شوند.
4. `save_invoice.php` داده‌ها را بررسی و با کمک `db.php` فاکتور را در `doctor_invoices` و آیتم‌ها را در `invoice_items` ذخیره می‌کند.
5. مبلغ کل فاکتور به‌صورت خودکار از مجموع آیتم‌ها محاسبه می‌شود و دستی قابل تغییر نیست.
6. فاکتور ماهانه هر پزشک/کلینیک/لابراتوار از صفحه «صدور فاکتور» (انتخاب ماه و بازه) تولید می‌شود.
7. PDF فاکتور با نام ماه‌های واقعی کیس‌ها (مثلاً «خرداد، تیر و مرداد») و خلاصه «تعداد خدمات به تفکیک» زیر جدول تولید می‌شود.
8. در `panel/invoices.php` می‌توان فاکتور را ویرایش، حذف یا پیش‌نمایش PDF گرفت.
9. پرداخت‌ها در `panel/payment_form.php` ثبت و به فاکتورها لینک می‌شوند (`doctor_payment_invoices`).

## نکات مهم
- سیستم تاریخ‌ها را به صورت شمسی و فارسی نمایش می‌دهد، ولی تاریخ‌ها در پایگاه داده به فرمت میلادی (`YYYY-MM-DD`) ذخیره می‌شوند.
- `db.php` با PDO به پایگاه داده متصل می‌شود و از `prepared statements` استفاده می‌کند.
- `panel/auth.php` سشن را با مسیر اختصاصی `storage/sessions` و عمر طولانی (۶۰۰ روز) راه‌اندازی می‌کند تا کاربر در هاست اشتراکی از سیستم بیرون نیفتد؛ `logout.php` نیز همان سشن را نابود می‌کند.
- `storage/sessions/.htaccess` دسترسی وب به فایل‌های سشن را مسدود می‌کند.
- هر کیس دارای `branch_id` (مالک) و `source_branch_id` (شعبه همکار) است؛ برون‌سپاری بین‌شعبه‌ای با دو نمای مالی (طلب/بدهی) مدل‌سازی می‌شود.
- نرخ‌های برون‌سپاری (`outsource_rates`) می‌توانند شعبه‌ای یا سراسری باشند؛ کلید یکتا `(lab_id, service_id, branch_id)` است. جدول یکپارچهٔ `price_links` (provider → receiver) مرجعِ جدیدتر است و `db.php` آن را با جدول‌های قدیمی همگام نگه می‌دارد.
- پیکر انتخاب دندان مقادیر فارسی (اعداد و جداکننده «،») را نرمال‌سازی می‌کند و از بریج میان‌خط (۱۱–۲۱ و ۴۱–۳۱) پشتیبانی می‌کند.
- `serviceTotalPrice()` قیمت پله‌ای را حساب می‌کند: مبلغ = قیمت پایه + (تعداد − واحد پایه) × «هر واحد اضافه»؛ در نبودِ «هر واحد اضافه» رفتار قبلی (تعداد × فی) حفظ می‌شود.
- فیلد «نوع اسکن‌بادی» در فرم کیس/ویرایش فقط وقتی نمایش داده می‌شود که خدمت `requires_scan_body = ۱` داشته باشد؛ اعتبارسنجی سمت سرور مستقل انجام می‌شود (نوع نامعتبر یا خدمتِ بی‌نیاز → `NULL`؛ در ویرایش مقدار قبلی حفظ می‌شود).
- تقویم FullCalendar به‌صورت **لوکال** (`assets/lib/fullcalendar/`) بارگذاری می‌شود تا روی هاستی که اینترنت بین‌الملل ندارد هم کار کند؛ در صورت نبودِ کتابخانه، صفحه به نمای لیستی سقوط می‌کند.
- `site_prices.display_order`، `design_required`، `requires_scan_body` و `short_name` در زمان اجرا بررسی و در صورت نیاز اضافه می‌شوند (به `ensure*` در `db.php` نگاه کنید).
- وضعیت «ارسال به لاب همکار» برای کاربرانِ بیرونی به «در حال انجام» ماسک می‌شود — تطبیق **با نام** انجام می‌شود (نه شناسه) تا در دیتابیسِ دیگر هم درست کار کند.
- `invoice_pdf.php` از `mpdf` برای تولید PDF با راست‌چین و تاریخ فارسی استفاده می‌کند.

## پیشنهادات برای بهبود
- تفکیک بیشتر لایه‌های داده، منطق و نمایش (MVC یا سرویس‌ها) برای کاهش وابستگی در `panel/*.php` — شروع آن با `src/Services/` انجام شده است.
- بهبود اعتبارسنجی سمت سرور و سمت کاربر.
- گسترش گزارش‌ها به اکسل/CSV.
- محاسبه خودکار وضعیت پرداخت فاکتور بر اساس پرداخت‌های مرتبط.
- ادامهٔ مهاجرت خواندنِ قیمت‌ها از جدول‌های قدیمی به `price_links` و بازنشسته‌کردن جدول‌های قدیمی.

## نصب و راه‌اندازی
1. `composer install`
2. فایل `.env` را با اطلاعات دیتابیس خود به‌روزرسانی کنید (و `MIGRATE_KEY` برای اجرای مهاجرت از طریق وب).
3. جداول را از فایل‌های مهاجرت (001 تا 072) بسازید — از `database/migrate.php` استفاده کنید.
4. پوشه‌های قابل‌نوشتن را ایجاد کنید: `storage/sessions` (با `.htaccess` محافظت‌شده)
   و `storage/scan_body_library`.
5. `panel/login.php` را از طریق مرورگر باز کنید.

## یادداشت
- فایل `install.php` در این مخزن وجود ندارد؛ فرآیند نصب باید از طریق فایل‌های فعلی و مهاجرت پایگاه داده (001 تا 072) انجام شود.
- اگر دیتابیس را از نسخهٔ قدیمی‌تری بازیابی کردید که جدول `_migrations` آن ناقص است،
  `migrate.php` با مکانیزم «تطبیق خودکار» مهاجرت‌های از قبل اعمال‌شده را بدون خطا
  علامت می‌زند و بقیه را اجرا می‌کند.
