<?php
// db.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\Connection;

function db() {
    static $pdo = null;
    if ($pdo === null) {
        // اگر دیتابیس در دسترس نباشد (مثلاً MySQL هاست برای چند لحظه restart شود و
        // خطای 2002 رخ دهد) به‌جای Fatal error و صفحهٔ سفید، پیام 503 برگردان.
        try {
            $pdo = Connection::getInstance();
        } catch (Throwable $e) {
            error_log('db connection failed: ' . $e->getMessage());
            dbConnectionFailedResponse();
        }
        try {
            ensureSitePricesOrderColumn($pdo);
            ensureSitePricesDesignRequiredColumn($pdo);
            ensureSitePricesRequiresScanBodyColumn($pdo);
            ensureSitePricesShortNameColumn($pdo);
            ensureEntityCommentsTable($pdo);
            ensureNotificationsTable($pdo);
            ensureCasesDesignFeeColumn($pdo);
            ensureDoctorPriceOverrideTypeColumn($pdo);
            ensureCasesOutsourcedRateColumn($pdo);
            ensureCaseFilesDescriptionColumn($pdo);
            ensureCaseFilesRelPathColumn($pdo);
            ensureUserUploadsDescriptionColumn($pdo);
            ensureUserUploadsRelPathColumn($pdo);
            ensureCaseFileCaseLinksTable($pdo);
            ensureScanAppointmentsTable($pdo);
            ensureScanBodyTypes($pdo);
            ensureServicePricingColumns($pdo);
            ensureUserClinicsTable($pdo);
            ensureCasesClinicColumn($pdo);
            ensureBranchReceivableCharset($pdo);
        } catch (Throwable $e) {}
    }
    return $pdo;
}

/**
 * جدولِ «انواع اسکن‌بادی» (فیکسچر ایمپلنت) + ستون‌های مرتبط.
 *   • scan_body_types — کاتالوگ انواع (اویتا / انی‌ریج / ...) + کتابخانهٔ دانلود (لینک یا فایل).
 *   • scan_appointments.scan_body_type_id — نوعِ اسکن‌بادی نوبتِ اسکن.
 *   • cases.scan_body_type_id — نوعِ اسکن‌بادی کیس (برای خدماتِ اباتمنت/فیکسچر).
 *   • site_prices.requires_scan_body — خدماتی که فیلدِ «نوع اسکن‌بادی» لازم دارند.
 * ساختِ خودکار لازم است چون این صفحه‌ها ممکن است روی دیتابیس‌های قدیمی هم اجرا شوند.
 */
function ensureScanBodyTypes($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS scan_body_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            library_url VARCHAR(500) NULL,
            library_path VARCHAR(500) NULL,
            library_name VARCHAR(255) NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_scan_body_types_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // ستون‌های کتابخانه ممکن است روی دیتابیس‌هایی که جدول را قبلاً داشته‌اند نباشند
        foreach ([
            'library_url'  => "VARCHAR(500) NULL AFTER active",
            'library_path' => "VARCHAR(500) NULL AFTER library_url",
            'library_name' => "VARCHAR(255) NULL AFTER library_path",
            'description'  => "TEXT NULL AFTER library_name",
        ] as $col => $def) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'scan_body_types' AND COLUMN_NAME = ?");
            $stmt->execute([DB_NAME, $col]);
            $row = $stmt->fetch();
            if (empty($row) || (int) $row['cnt'] === 0) {
                $pdo->exec("ALTER TABLE scan_body_types ADD COLUMN {$col} {$def}");
            }
        }

        // دادهٔ اولیه فقط اگر جدول خالی باشد (تا اگر کاربر همه را حذف کرد دوباره ساخته نشوند)
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM scan_body_types")->fetchColumn();
        if ($cnt === 0) {
            $pdo->exec("INSERT INTO scan_body_types (name, sort_order, active) VALUES
                ('اویتا', 1, 1), ('انی ریج', 2, 1)");
        }

        foreach ([
            ['scan_appointments', 'scan_body_type_id', "INT NULL AFTER needs_scan_body"],
            ['cases', 'scan_body_type_id', "INT NULL AFTER shade"],
            ['cases', 'connection_type', "VARCHAR(30) NULL AFTER scan_body_type_id"],
            ['site_prices', 'requires_scan_body', "TINYINT(1) NOT NULL DEFAULT 0 AFTER design_required"],
        ] as [$table, $column, $definition]) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([DB_NAME, $table, $column]);
            $row = $stmt->fetch();
            if (empty($row) || (int) $row['cnt'] === 0) {
                // ستونِ مرجع (AFTER ...) ممکن است روی دیتابیس قدیمی نباشد؛ در آن صورت بدون AFTER اضافه می‌کنیم.
                try {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                } catch (Throwable $e) {
                    $fallback = preg_replace('/\s+AFTER\s+`?\w+`?\s*$/i', '', $definition);
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$fallback}");
                }
            }
        }
    } catch (Throwable $e) {
        error_log('ensureScanBodyTypes failed: ' . $e->getMessage());
    }
}

/**
 * پاسخ دوستانه وقتی اتصال به دیتابیس برقرار نمی‌شود (خطاهای 2002/1040 هاست).
 * برای درخواست‌های JSON پاسخ JSON و برای صفحات، یک صفحهٔ ساده با کد 503 می‌دهد.
 */
function dbConnectionFailedResponse(): void {
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
    }
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $wantsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || stripos((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'xmlhttprequest') !== false
        || (bool) preg_match('#/(check_|data|get_|save_|upload_|delete_|toggle_|generate_|download_|link_|batch_|update_|append_|reorder_|export_|print_|serve_)[A-Za-z0-9_\-]*\.php$#i', $script);
    if ($wantsJson) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'db_unavailable',
            'message' => 'ارتباط با دیتابیس برقرار نشد. لطفاً چند لحظه بعد دوباره تلاش کنید.',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="fa"><head><meta charset="utf-8"><title>سرویس موقتاً در دسترس نیست</title></head>'
           . '<body style="font-family:Tahoma,sans-serif;padding:40px;text-align:center;color:#0f172a;">'
           . '<h2>سرویس موقتاً در دسترس نیست</h2>'
           . '<p>ارتباط با دیتابیس برقرار نشد. لطفاً چند لحظه بعد صفحه را دوباره باز کنید.</p>'
           . '</body></html>';
    }
    exit;
}

/**
 * site_prices.design_required — خدماتي که طراحي لازم ندارند (مثل پست NPG، پرينت کست،
 * الاینر شفاف) تا کیس به‌صورت پیش‌فرض بدون طراح ثبت شود. مقدار پیش‌فرض ۱ (نیازمند طراحی).
 */
function ensureSitePricesDesignRequiredColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'site_prices' AND COLUMN_NAME = 'design_required'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int) $row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE site_prices ADD COLUMN design_required TINYINT(1) NOT NULL DEFAULT 1");
    }
}

/**
 * site_prices.requires_scan_body — خدماتی که هنگام ثبت/ویرایش کیس فیلدِ «نوع اسکن‌بادی»
 * لازم دارند (اباتمنت کره‌ای/اروپایی، فیکسچر ایمپلنت و ...). مقدار پیش‌فرض ۰.
 * این پرچم از prices.php قابل تنظیم است تا خدماتِ آینده هم پشتیبانی شوند.
 */
function ensureSitePricesRequiresScanBodyColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'site_prices' AND COLUMN_NAME = 'requires_scan_body'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int) $row['cnt'] === 0) {
        try {
            $pdo->exec("ALTER TABLE site_prices ADD COLUMN requires_scan_body TINYINT(1) NOT NULL DEFAULT 0 AFTER design_required");
        } catch (Throwable $e) {
            // اگر ستونِ مرجع (design_required) روی دیتابیس قدیمی نبود، بدون AFTER اضافه کن
            $pdo->exec("ALTER TABLE site_prices ADD COLUMN requires_scan_body TINYINT(1) NOT NULL DEFAULT 0");
        }
    }
}

/**
 * آیا خدمتِ داده‌شده نیازمند انتخاب «نوع اسکن‌بادی» است؟
 * (اباتمنت کره‌ای/اروپایی، فیکسچر ایمپلنت و هر خدمتی که در prices.php تیک خورده باشد.)
 * @param int|null $serviceId یا شناسهٔ خدمت
 * @param array|null $priceRow اگر ردیفِ site_prices از قبل لود شده، بدهید تا کوئری اضافه نخورد
 */
function serviceRequiresScanBody(?int $serviceId, ?array $priceRow = null): bool {
    if ($serviceId === null || $serviceId <= 0) return false;
    if ($priceRow !== null) {
        return !empty($priceRow['requires_scan_body']);
    }
    try {
        $st = db()->prepare('SELECT requires_scan_body FROM site_prices WHERE id = ? LIMIT 1');
        $st->execute([(int) $serviceId]);
        $v = $st->fetchColumn();
        return $v !== false && (int) $v === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/** نقشهٔ id → 0/1 برای همهٔ خدمات (برای فرمِ کیس — بدون کوئری به‌ازای هر خدمت). */
function serviceScanBodyMap(): array {
    $out = [];
    try {
        foreach (db()->query('SELECT id, requires_scan_body FROM site_prices')->fetchAll() as $r) {
            $out[(int) $r['id']] = (int) (!empty($r['requires_scan_body']));
        }
    } catch (Throwable $e) {
        // ستون هنوز ساخته نشده — همه false
    }
    return $out;
}

// =====================================================
// نوع اتصال (connection / retention type) — کیس‌های اباتمنت
// =====================================================
// مرجع اصطلاحات (پروتز ایمپلنت):
//   • screw-retained  — ترمیم با پیچ به اباتمنت/ایمپلنت بسته می‌شود
//   • cement-retained — ترمیم روی اباتمنت سیمان می‌شود
//   • screw-cemented  — اباتمنت پیچ‌شونده + روکشِ سیمانی‌شونده روی آن (حالت ترکیبی)
// این فیلد فقط برای خدماتِ needs/requires_scan_body (اباتمنت/فیکسچر) نمایش داده می‌شود.

/**
 * انواع اتصال → کلید ماشینی + برچسب فارسی/انگلیسی.
 * کلیدها همان مقادیر مجازِ ذخیره‌شده در cases.connection_type هستند.
 */
function connectionTypes(): array {
    return [
        'screw-retained'  => ['en' => 'Screw-retained',  'fa' => 'پیچ‌شونده'],
        'cement-retained' => ['en' => 'Cement-retained', 'fa' => 'چسب‌شونده'],
        'screw-cemented'  => ['en' => 'Screw-cemented',  'fa' => 'پیچ‌شونده + چسب‌شونده'],
    ];
}

/** آیا کلیدِ نوع اتصال معتبر است؟ */
function isValidConnectionType(?string $key): bool {
    return $key !== null && array_key_exists($key, connectionTypes());
}

/** برچسبِ نمایشیِ نوع اتصال (فارسی + انگلیسی) — یا رشتهٔ خالی. */
function connectionTypeLabel(?string $key): string {
    if (!isValidConnectionType($key)) return '';
    $t = connectionTypes()[$key];
    return $t['fa'] . ' (' . $t['en'] . ')';
}

// =====================================================
// کیس زیرمجموعه — پیشنهاد خدمتِ مکمل
// =====================================================
// الگوی رایجِ کار: یک «کاستوم اباتمنت» + یک «روکش/فریم پایه اباتمنت» روی همان دندان.
// برای صرفه‌جویی در وقت کاربر، وقتی کیسِ اصلی از یکی از این خانواده‌ها باشد، خدمتِ
// مکمل به‌صورت پیش‌فرض در فرمِ کیسِ زیرمجموعه انتخاب می‌شود.
//
// تشخیص با «تطبیق عنوان» انجام می‌شود (نه شناسه) تا روی دیتابیس‌های مختلف هم درست کار کند.
// قواعدِ کاربر:
//   • کیسِ اصلی «روکش/فریم پایه اباتمنت یا ایمپلنت» → زیرمجموعه = کاستوم اباتمنت کره‌ای
//   • کیسِ اصلی «کاستوم اباتمنت کره‌ای/اروپایی»   → زیرمجموعه = روکش پایه اباتمنت/ایمپلنت
//
// @return array{key:string, service_id:int|null, label:string} key = نوع قاعده (برای پیام به کاربر)

/** آیا عنوانِ خدمت به خانوادهٔ «پایه اباتمنت / پایه ایمپلنت» تعلق دارد؟ */
function serviceTitleIsAbutmentBased(string $title): bool {
    $t = trim($title);
    if ($t === '') return false;
    // «پایه اباتمنت» / «روی اباتمنت» / «پایه ایمپلنت»
    return (bool) preg_match('/پایه\s*(اباتمنت|ابوتمنت|ایمپلنت)|روی\s*(اباتمنت|ابوتمنت)/u', $t);
}

/** آیا عنوانِ خدمت «کاستوم اباتمنت» است؟ */
function serviceTitleIsCustomAbutment(string $title): bool {
    $t = trim($title);
    if ($t === '') return false;
    return (bool) preg_match('/کاستوم/u', $t) && (bool) preg_match('/اباتمنت|ابوتمنت/u', $t);
}

/**
 * خدمتِ مکملی که برای «کیس زیرمجموعه» پیشنهاد می‌شود.
 * @param int|null $serviceId شناسهٔ خدمتِ کیسِ اصلی
 * @return array{key:string, service_id:int|null, label:string, parent_label:string}
 *         key = 'custom_abutment' | 'abutment_crown' | 'none'
 */
function suggestSubCaseService(?int $serviceId): array {
    $out = ['key' => 'none', 'service_id' => null, 'label' => '', 'parent_label' => ''];
    if ($serviceId === null || $serviceId <= 0) return $out;

    $parent = getPrice((int) $serviceId);
    if (!$parent) return $out;

    $parentTitle = (string) ($parent['title'] ?? '');
    $out['parent_label'] = $parentTitle;
    if ($parentTitle === '') return $out;

    // فقط بین خدماتِ فعال می‌گردیم (خدمتِ غیرفعال نباید پیش‌فرض شود)
    $all = [];
    try {
        foreach (db()->query('SELECT id, title FROM site_prices WHERE active = 1 ORDER BY id ASC')->fetchAll() as $r) {
            $all[] = ['id' => (int) $r['id'], 'title' => (string) $r['title']];
        }
    } catch (Throwable $e) {
        return $out;
    }

    $findFirst = function (callable $pred) use ($all): ?array {
        foreach ($all as $row) {
            if ($pred($row['title'])) return $row;
        }
        return null;
    };

    // قاعدهٔ ۱: کیسِ اصلی «پایه اباتمنت/ایمپلنت» (روکش یا فریم) → زیرمجموعه «کاستوم اباتمنت کره‌ای»
    if (serviceTitleIsAbutmentBased($parentTitle)) {
        // اولویت با نسخهٔ کره‌ای، بعد اروپایی، بعد هر کاستوم اباتمنتی
        $hit = $findFirst(fn($t) => serviceTitleIsCustomAbutment($t) && (bool) preg_match('/کره/u', $t))
            ?: $findFirst(fn($t) => serviceTitleIsCustomAbutment($t) && (bool) preg_match('/اروپ/u', $t))
            ?: $findFirst(fn($t) => serviceTitleIsCustomAbutment($t));
        if ($hit) {
            $out['key'] = 'custom_abutment';
            $out['service_id'] = $hit['id'];
            $out['label'] = $hit['title'];
        }
        return $out;
    }

    // قاعدهٔ ۲: کیسِ اصلی «کاستوم اباتمنت» → زیرمجموعه «روکش/فریم پایه اباتمنت»
    if (serviceTitleIsCustomAbutment($parentTitle)) {
        // اولویت با «روکش ... پایه اباتمنت»، بعد «فریم ... روی اباتمنت»،
        // بعد هر چیزی با «پایه اباتمنت/ایمپلنت»
        $hit = $findFirst(fn($t) => (bool) preg_match('/روکش/u', $t) && serviceTitleIsAbutmentBased($t))
            ?: $findFirst(fn($t) => (bool) preg_match('/فریم/u', $t) && serviceTitleIsAbutmentBased($t))
            ?: $findFirst(fn($t) => serviceTitleIsAbutmentBased($t));
        if ($hit) {
            $out['key'] = 'abutment_crown';
            $out['service_id'] = $hit['id'];
            $out['label'] = $hit['title'];
        }
        return $out;
    }

    return $out;
}

/** آیا خدمتِ داده‌شده یکی از خدماتِ «پایه اباتمنت/کاستوم اباتمنت» است (برای دکمهٔ زیرمجموعه)؟ */
function serviceSupportsSubCaseCreation(?int $serviceId): bool {
    $s = suggestSubCaseService($serviceId);
    return $s['service_id'] !== null;
}

function ensureSitePricesOrderColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'site_prices' AND COLUMN_NAME = 'display_order'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE site_prices ADD COLUMN display_order INT DEFAULT 0");
    }
}

/**
 * site_prices.short_name — نام اختصاری خدمت (مثل ML برای «روکش زیرکونیا مولتی لیر»).
 * در جدول کیس‌ها و چاپ برچسب استفاده می‌شود تا متن‌ها کوتاه و چیدمان ثابت بماند.
 */
function ensureSitePricesShortNameColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'site_prices' AND COLUMN_NAME = 'short_name'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int) $row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE site_prices ADD COLUMN short_name VARCHAR(24) NULL AFTER title");
    }
}

/** نام اختصاری یک خدمت (اگر تعریف نشده باشد، null). */
function getServiceShortName(int $serviceId): ?string {
    if ($serviceId <= 0) return null;
    try {
        $st = db()->prepare('SELECT short_name FROM site_prices WHERE id = ?');
        $st->execute([$serviceId]);
        $v = $st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    $v = is_string($v) ? trim($v) : '';
    return $v === '' ? null : $v;
}

/** نقشهٔ service_id → نام اختصاری برای همهٔ خدمات (برای لیست‌ها/برچسب‌ها). */
function getServiceShortNamesMap(): array {
    $map = [];
    try {
        foreach (db()->query('SELECT id, short_name FROM site_prices') as $r) {
            $s = trim((string) ($r['short_name'] ?? ''));
            if ($s !== '') $map[(int) $r['id']] = $s;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $map;
}

function ensureCasesDesignFeeColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'design_fee'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN design_fee DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total_price");
    }
}

function ensureDoctorPriceOverrideTypeColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'doctor_price_overrides' AND COLUMN_NAME = 'price_type'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE doctor_price_overrides ADD COLUMN price_type VARCHAR(30) NOT NULL DEFAULT 'service' AFTER doctor_id");
    }

    try {
        $pdo->exec("ALTER TABLE doctor_price_overrides MODIFY COLUMN service_id INT NULL");
    } catch (Throwable $e) {}
}

function ensureCasesOutsourcedRateColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'outsourced_rate'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN outsourced_rate DECIMAL(15,2) NULL AFTER outsourced_qty");
    }
}

function ensureCaseFilesDescriptionColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'case_files' AND COLUMN_NAME = 'description'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE case_files ADD COLUMN description TEXT NULL AFTER original_name");
    }
}

/** مسیر نسبیِ فایل داخل پوشهٔ آپلودشده (آپلود پوشه‌ای؛ برای فایل تکی NULL). */
function ensureCaseFilesRelPathColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'case_files' AND COLUMN_NAME = 'rel_path'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE case_files ADD COLUMN rel_path VARCHAR(500) NULL AFTER original_name");
    }
}

/**
 * جدول پیوندِ «یک کاربر (پزشک) در چند کلینیک» + انتقالِ عضویت‌های فعلی
 * (users.clinic_id) به آن. ستون users.clinic_id به‌عنوان «کلینیک اصلی» می‌ماند.
 */
function ensureUserClinicsTable($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user_clinics'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    $justCreated = false;
    if (empty($row) || (int) $row['cnt'] === 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_clinics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            clinic_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_clinic (user_id, clinic_id),
            KEY idx_uc_user (user_id),
            KEY idx_uc_clinic (clinic_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $justCreated = true;
    }
    if ($justCreated) {
        // فقط یک‌بار: عضویت‌های موجود منتقل می‌شوند
        try {
            $pdo->exec("INSERT IGNORE INTO user_clinics (user_id, clinic_id)
                        SELECT id, clinic_id FROM users WHERE clinic_id IS NOT NULL AND clinic_id > 0");
        } catch (Throwable $e) {}
    }
}

/** ستونِ cases.clinic_id (کلینیکِ صاحبِ کار) + پرکردنِ یک‌بارهٔ کیس‌های قبلی. */
function ensureCasesClinicColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'clinic_id'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int) $row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN clinic_id INT NULL AFTER doctor_id");
        try {
            $pdo->exec("ALTER TABLE cases ADD INDEX idx_cases_clinic (clinic_id)");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("UPDATE cases c JOIN users u ON u.id = c.doctor_id
                        SET c.clinic_id = u.clinic_id
                        WHERE c.clinic_id IS NULL AND u.clinic_id IS NOT NULL AND u.clinic_id > 0");
        } catch (Throwable $e) {}
    }
}

function ensureUserUploadsDescriptionColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user_uploads' AND COLUMN_NAME = 'description'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE user_uploads ADD COLUMN description TEXT NULL AFTER original_name");
    }
}

/**
 * جداول فاکتور طلب از شعبه باید utf8mb4 باشند.
 * مهاجرت 031 کاراکترست را تعیین نکرده بود، پس روی بعضی سرورها با پیش‌فرض
 * latin1 ساخته شدند و هر متن فارسی هنگام درج به «?» تبدیل می‌شد (ازدست‌رفته).
 * این تابع یک‌بار در ابتدای هر اتصال collation را بررسی و در صورت لزوم اصلاح می‌کند
 * تا مشکل روی هیچ محیطی (لوکال/هاست) تکرار نشود.
 */
function ensureBranchReceivableCharset($pdo) {
    $tables = ['branch_receivables', 'branch_receivable_items', 'branch_receivable_payments'];    $stmt = $pdo->prepare("SELECT TABLE_NAME, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
                           WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('branch_receivables','branch_receivable_items','branch_receivable_payments')");
    $stmt->execute([DB_NAME]);
    foreach ($stmt->fetchAll() as $row) {
        $collation = (string) ($row['TABLE_COLLATION'] ?? '');
        if ($collation === '' || stripos($collation, 'utf8mb4') === 0) {
            continue; // درست است
        }
        $table = (string) $row['TABLE_NAME'];
        if (!in_array($table, $tables, true)) {
            continue; // محافظت در برابر تزریق نام جدول
        }
        try {
            $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            error_log("ensureBranchReceivableCharset: converted $table (was $collation) to utf8mb4");
        } catch (Throwable $e) {
            error_log("ensureBranchReceivableCharset failed for $table: " . $e->getMessage());
        }
    }
}

/** مسیر نسبیِ فایل در آپلودِ پوشه‌ای صفحهٔ uploads.php (برای فایل تکی NULL). */
function ensureUserUploadsRelPathColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user_uploads' AND COLUMN_NAME = 'rel_path'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE user_uploads ADD COLUMN rel_path VARCHAR(500) NULL AFTER original_name");
    }
}

/**
 * Given an original file name, return a unique display name for a case.
 * If another file with the same name is already attached to the case,
 * append "_YYYYMMDD" before the extension (and a counter if still taken).
 */
function uniqueCaseFileName(int $caseId, string $originalName): string {
    $used = db()->prepare('SELECT original_name FROM case_files WHERE case_id = ?');
    $used->execute([$caseId]);
    $existing = array_map('strval', $used->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array($originalName, $existing, true)) {
        return $originalName;
    }
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $suffix = '_' . date('Ymd');
    $candidate = $base . $suffix . ($ext !== '' ? '.' . $ext : '');
    $i = 1;
    while (in_array($candidate, $existing, true)) {
        $i++;
        $candidate = $base . $suffix . '_' . $i . ($ext !== '' ? '.' . $ext : '');
    }
    return $candidate;
}

/**
 * Same as uniqueCaseFileName but for standalone user uploads (uploader-scoped).
 */
function uniqueUserUploadName(int $userId, ?int $caseId, string $originalName): string {
    if ($caseId) {
        $used = db()->prepare('SELECT original_name FROM user_uploads WHERE case_id = ?');
        $used->execute([$caseId]);
    } else {
        $used = db()->prepare('SELECT original_name FROM user_uploads WHERE user_id = ? AND case_id IS NULL');
        $used->execute([$userId]);
    }
    $existing = array_map('strval', $used->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array($originalName, $existing, true)) {
        return $originalName;
    }
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $suffix = '_' . date('Ymd');
    $candidate = $base . $suffix . ($ext !== '' ? '.' . $ext : '');
    $i = 1;
    while (in_array($candidate, $existing, true)) {
        $i++;
        $candidate = $base . $suffix . '_' . $i . ($ext !== '' ? '.' . $ext : '');
    }
    return $candidate;
}

// ----- Shared file library (user_uploads ⇄ cases, many-to-many) -----

/** Case IDs a library file (user_uploads) is linked to (pivot links + legacy single case_id). */
function userUploadLinkedCaseIds(int $uploadId): array {
    $pdo = db();
    $ids = [];
    $st = $pdo->prepare('SELECT case_id FROM user_upload_case_links WHERE upload_id = ?');
    $st->execute([$uploadId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) if ($v !== null && $v !== '') $ids[(int) $v] = true;
    $st2 = $pdo->prepare('SELECT case_id FROM user_uploads WHERE id = ? AND case_id IS NOT NULL');
    $st2->execute([$uploadId]);
    foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $v) if ($v !== null && $v !== '') $ids[(int) $v] = true;
    return array_keys($ids);
}

/** All library files (user_uploads) attached to a case — new pivot links + legacy case_id. */
function getUserUploadsForCase(int $caseId): array {
    $cid = (int) $caseId;
    return db()->query("SELECT DISTINCT u.*, uu.full_name AS uploader_name
            FROM user_uploads u
            LEFT JOIN user_upload_case_links l ON l.upload_id = u.id
            LEFT JOIN users uu ON uu.id = u.user_id
            WHERE (l.case_id = {$cid} OR u.case_id = {$cid})
            ORDER BY u.created_at DESC, u.id DESC")->fetchAll();
}

/** Link a library file to a case (idempotent). */
function linkUserUploadToCase(int $uploadId, int $caseId): void {
    $pdo = db();
    $st = $pdo->prepare('INSERT IGNORE INTO user_upload_case_links (upload_id, case_id, created_at) VALUES (?, ?, NOW())');
    $st->execute([(int) $uploadId, (int) $caseId]);
    // Mirror into the legacy "primary case" column when empty (keeps old UI consistent).
    $pdo->prepare('UPDATE user_uploads SET case_id = ? WHERE id = ? AND (case_id IS NULL OR case_id = 0)')->execute([(int) $caseId, (int) $uploadId]);
}

/** Unlink a library file from a case (idempotent). */
function unlinkUserUploadFromCase(int $uploadId, int $caseId): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM user_upload_case_links WHERE upload_id = ? AND case_id = ?')->execute([(int) $uploadId, (int) $caseId]);
    $pdo->prepare('UPDATE user_uploads SET case_id = NULL WHERE id = ? AND case_id = ?')->execute([(int) $uploadId, (int) $caseId]);
}

/**
 * نقش‌هایی که دسترسی‌شان به کیس فقط از راهِ «رابطهٔ مستقیم با کیس» تعریف می‌شود
 * (پزشکِ کیس، طراحِ کیس، لابراتوارِ کیس/برون‌سپاری، پزشکانِ زیرمجموعهٔ کلینیک).
 * این نقش‌ها با مجوزهای عمومیِ کارکنان (view_all_cases و…) دسترسی اضافه نمی‌گیرند.
 */
function isCaseRelationalRole(string $role): bool {
    return in_array($role, ['doctor', 'designer', 'lab', 'outsource_lab', 'customer_lab', 'partner_lab', 'clinic'], true);
}

/** ستون‌های رابطهٔ مستقیمِ کاربر با کیس. */
function caseRelationColumns(): array {
    return ['doctor_id', 'designer_id', 'lab_id', 'outsourced_lab_id'];
}

/**
 * آیا این کیس در محدودهٔ شعبهٔ کاربر قرار می‌گیرد؟
 * - مدیر کل (role = admin) و کاربران بدون شعبه: محدودیتی ندارند.
 * - کاربر شعبه‌محور: کیس‌های همان شعبه، کیس‌های خانوادهٔ کیس (والد/فرزند) و
 *   کیس‌هایی که شعبه‌شان طرفِ برون‌سپاری است → مجاز (منطقِ branchCaseScope).
 */
function caseInUserBranchScope(array $case, ?array $user = null): bool {
    $user = $user ?: (function_exists('current_user') ? current_user() : null);
    if (!$user) return false;
    if (($user['role'] ?? '') === 'admin') return true;          // مدیر کل: سراسری
    $branchId = ($user['branch_id'] ?? null);
    if ($branchId === null || $branchId === '' || (int) $branchId <= 0) return true; // بدون شعبه
    $bs = branchCaseScope('c', (int) $branchId);
    if (empty($bs['scoped'])) return true;
    $st = db()->prepare('SELECT COUNT(*) FROM cases c WHERE c.id = ? AND ' . $bs['sql']);
    $st->execute(array_merge([(int) $case['id']], $bs['params']));
    return ((int) $st->fetchColumn()) > 0;
}

/**
 * دسترسی دیدنِ یک کیس (صفحهٔ مشاهدهٔ کیس، فایل‌های کیس، فراخوانی‌های AJAX).
 *
 * ملاکِ اصلی «رابطهٔ کاربر با کیس» است، نه فقط نامِ نقش؛ بنابراین کاربری که
 * هم‌زمان «لابراتوار برون‌سپاری» است و «طراح» هم هست، اگر هر یک از این دو رابطه
 * برقرار باشد اجازهٔ دیدن دارد (رفع باگِ دسترسیِ کیس ۱۲۲۴).
 *
 * ترتیب بررسی:
 *   ۱) مدیر کل → همهٔ کیس‌ها.
 *   ۲) رابطهٔ مستقیم: doctor_id / designer_id / lab_id / outsourced_lab_id.
 *   ۳) کلینیک → کیس‌های پزشکانِ زیرمجموعه.
 *   ۴) نقش‌های کارکنان با مجوزِ view_all_cases → با محدودهٔ شعبه.
 *
 * @param array|null $case ردیف کیس (اختیاری) برای پرهیز از کوئریِ تکراری.
 */
function userCanViewCase(int $caseId, ?array $user = null, ?array $case = null): bool {
    if ($caseId <= 0) return false;
    $user = $user ?: (function_exists('current_user') ? current_user() : null);
    if (!$user) return false;
    $role = (string) ($user['role'] ?? '');
    if ($role === 'admin') return true;                          // مدیر کل: همه‌چیز
    $uid = (int) $user['id'];

    if (!is_array($case) || (int) ($case['id'] ?? 0) !== $caseId) {
        $st = db()->prepare('SELECT id, doctor_id, designer_id, lab_id, outsourced_lab_id, branch_id, source_branch_id, parent_id FROM cases WHERE id = ?');
        $st->execute([$caseId]);
        $case = $st->fetch() ?: null;
    }
    if (!$case) return false;

    // ۱) رابطهٔ مستقیم با کیس (مستقل از نامِ نقش)
    foreach (caseRelationColumns() as $col) {
        if (!empty($case[$col]) && (int) $case[$col] === $uid) return true;
    }

    // ۲) کلینیک: کیس‌های پزشکانِ زیرمجموعه
    if ($role === 'clinic' && function_exists('getClinicScope')) {
        $sc = getClinicScope('c');
        $st = db()->prepare('SELECT COUNT(*) FROM cases c WHERE c.id = ? AND ' . $sc['sql']);
        $st->execute(array_merge([$caseId], $sc['params']));
        if ((int) $st->fetchColumn() > 0) return true;
    }

    // ۳) نقش‌های رابطه‌محور: بدون رابطه، دسترسی ندارند
    if (isCaseRelationalRole($role)) return false;

    // ۴) کارکنان/منشی/فنی: مجوز + محدودهٔ شعبه
    if (has_permission('view_all_cases')) return caseInUserBranchScope($case, $user);

    return false;
}

/** Whether the given user can open a case (used for library-file access). Mirrors view_case.php. */
function userCanViewCaseId(int $caseId, ?array $user = null): bool {
    $user = $user ?: (function_exists('current_user') ? current_user() : null);
    if (!$user) return false;
    if (userCanViewCase($caseId, $user)) return true;

    // نقش‌های رابطه‌محور با مجوزهای سبک‌تر (view_assigned_cases/view_own_cases) دسترسی نمی‌گیرند
    if (isCaseRelationalRole((string) ($user['role'] ?? ''))) return false;
    if (!has_permission('view_assigned_cases') && !has_permission('view_own_cases')) return false;

    $st = db()->prepare('SELECT id, doctor_id, designer_id, lab_id, outsourced_lab_id, branch_id, source_branch_id FROM cases WHERE id = ?');
    $st->execute([$caseId]);
    $row = $st->fetch();
    return $row ? caseInUserBranchScope($row, $user) : false;
}

/**
 * Whether the current user may access a library file (user_uploads).
 * Root admin / branch admins / designers see the whole shared pool; the uploader sees
 * their own files; other roles (doctor/clinic/lab/staff…) may view a file only if it is
 * linked to a case they may open.
 */
function userCanViewUserUpload(array $up, ?array $user = null): bool {
    $user = $user ?: (function_exists('current_user') ? current_user() : null);
    if (!$user) return false;
    if (in_array($user['role'] ?? '', ['admin', 'branch_admin', 'designer'], true)) return true;
    if ((int) $up['user_id'] === (int) $user['id']) return true;
    foreach (userUploadLinkedCaseIds((int) $up['id']) as $cid) {
        if (userCanViewCaseId((int) $cid, $user)) return true;
    }
    return false;
}

// =====================================================
// فایل‌های مرتبط: اتصالِ فایلِ یک کیس به کیس‌های دیگر
// (case_files ↔ case_file_case_links)
// =====================================================

/** جدولِ پیوندِ فایل‌های کیس را در صورت نبود می‌سازد (هم‌سبکِ سایر ensureها). */
function ensureCaseFileCaseLinksTable($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'case_file_case_links'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int) $row['cnt'] === 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS case_file_case_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            case_file_id INT NOT NULL,
            case_id INT NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_casefile_case (case_file_id, case_id),
            KEY idx_cfcl_case (case_id),
            KEY idx_cfcl_file (case_file_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

/**
 * فایل‌هایی که به این کیس وصل شده‌اند و مبدأشان کیسِ دیگری است.
 * (فایل‌های خودِ کیس در جدول اصلی case_files نمایش داده می‌شوند.)
 */
function getLinkedCaseFilesForCase(int $caseId): array {
    $cid = (int) $caseId;
    $st = db()->prepare("SELECT cf.*, l.id AS link_id, l.created_at AS linked_at, l.case_id AS target_case_id, l.created_by AS link_created_by,
            src.id AS src_case_id, src.patient_name AS src_patient_name, src.service_id AS src_service_id,
            src.received_date AS src_received_date,
            up.full_name AS uploader_name, lu.full_name AS linker_name
        FROM case_file_case_links l
        JOIN case_files cf ON cf.id = l.case_file_id
        JOIN cases src ON src.id = cf.case_id
        LEFT JOIN users up ON up.id = cf.uploader_id
        LEFT JOIN users lu ON lu.id = l.created_by
        WHERE l.case_id = ? AND cf.case_id <> ?
        ORDER BY l.created_at DESC, cf.id DESC");
    $st->execute([$cid, $cid]);
    return $st->fetchAll();
}

/** کیس‌هایی که این فایل به آن‌ها وصل شده است (کیسِ مبدأ شامل نمی‌شود). */
function caseFileLinkedCaseIds(int $fileId): array {
    $st = db()->prepare('SELECT case_id FROM case_file_case_links WHERE case_file_id = ?');
    $st->execute([(int) $fileId]);
    return array_map('intval', array_column($st->fetchAll(), 'case_id'));
}

/** کیس‌های متصل به یک فایل با نام بیمار (برای نمایشِ برچسب روی فایل). */
function caseFileLinkTargets(int $fileId): array {
    $st = db()->prepare('SELECT l.case_id, c.patient_name FROM case_file_case_links l
        LEFT JOIN cases c ON c.id = l.case_id WHERE l.case_file_id = ? ORDER BY l.case_id');
    $st->execute([(int) $fileId]);
    return $st->fetchAll();
}

/** اتصالِ یک فایلِ کیس به کیسِ دیگر (تکراری‌ها نادیده گرفته می‌شوند). */
function linkCaseFileToCase(int $fileId, int $caseId, ?int $userId = null): void {
    db()->prepare('INSERT IGNORE INTO case_file_case_links (case_file_id, case_id, created_by, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([(int) $fileId, (int) $caseId, $userId]);
}

/** حذف اتصالِ فایل از یک کیس (تکراری‌ها نادیده گرفته می‌شوند). */
function unlinkCaseFileFromCase(int $fileId, int $caseId): void {
    db()->prepare('DELETE FROM case_file_case_links WHERE case_file_id = ? AND case_id = ?')
        ->execute([(int) $fileId, (int) $caseId]);
}

/** آیا کاربر از طریق «اتصال‌ها» به این فایل دسترسی دارد؟ (کیسِ مبدأ را جدا بررسی کنید.) */
function caseFileAccessibleViaLinks(int $fileId, ?array $user = null): bool {
    foreach (caseFileLinkedCaseIds($fileId) as $cid) {
        if (userCanViewCaseId((int) $cid, $user)) return true;
    }
    return false;
}

/** فایلِ کیس + اطلاعات کیسِ مبدأ (برای بررسی دسترسی و پاسخ‌های JSON). */
function getCaseFileRow(int $fileId) {
    $st = db()->prepare('SELECT cf.*, c.doctor_id, c.lab_id, c.designer_id, c.branch_id, c.source_branch_id, c.patient_name
        FROM case_files cf JOIN cases c ON cf.case_id = c.id WHERE cf.id = ? LIMIT 1');
    $st->execute([$fileId]);
    $row = $st->fetch();
    return $row ?: null;
}

// =====================================================
// نوبت‌دهی اسکن (scan_appointments)
// =====================================================

/** جدولِ نوبت‌های اسکن را در صورت نبود می‌سازد (هم‌سبکِ سایر ensureها). */
function ensureScanAppointmentsTable($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'scan_appointments'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int) $row['cnt'] === 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS scan_appointments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NULL,
            doctor_id INT NULL,
            case_id INT NULL,
            patient_name VARCHAR(160) NULL,
            title VARCHAR(180) NULL,
            appt_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NULL,
            appt_type VARCHAR(20) NOT NULL DEFAULT 'scan',
            needs_scan_body TINYINT(1) NOT NULL DEFAULT 0,
            address VARCHAR(255) NULL,
            phone VARCHAR(30) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            notes TEXT NULL,
            reminder_sent_at DATETIME NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            KEY idx_sa_date (appt_date),
            KEY idx_sa_doctor (doctor_id),
            KEY idx_sa_branch (branch_id),
            KEY idx_sa_case (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

/** ستون‌های قیمت‌گذاری خدمات: تعداد دستی + قیمت پله‌ای. */
function ensureServicePricingColumns($pdo) {
    foreach ([
        'qty_manual'       => "TINYINT(1) NOT NULL DEFAULT 0",
        'base_units'       => "INT NOT NULL DEFAULT 1",
        'extra_unit_price' => "DECIMAL(15,2) NULL",
    ] as $col => $def) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'site_prices' AND COLUMN_NAME = ?");
        $stmt->execute([DB_NAME, $col]);
        $row = $stmt->fetch();
        if (empty($row) || (int) $row['cnt'] === 0) {
            $pdo->exec("ALTER TABLE site_prices ADD COLUMN {$col} {$def}");
        }
    }
}

/** نوع نوبت‌ها → برچسب فارسی/آیکون/رنگ (برای تقویم و فرم). */
function scanAppointmentTypes(): array {
    return [
        'scan'      => ['label' => 'اسکن',            'icon' => '🦷', 'color' => '#0ea5e9'],
        'scan_body' => ['label' => 'اسکن + اسکن‌بادی', 'icon' => '🧩', 'color' => '#7c3aed'],
        'pickup'    => ['label' => 'دریافت کار',      'icon' => '📥', 'color' => '#d97706'],
        'delivery'  => ['label' => 'تحویل کار',       'icon' => '📦', 'color' => '#16a34a'],
        'other'     => ['label' => 'سایر',            'icon' => '📌', 'color' => '#64748b'],
    ];
}

/** وضعیت نوبت‌ها → برچسب/رنگ. */
function scanAppointmentStatuses(): array {
    return [
        'scheduled' => ['label' => 'رزرو شده', 'color' => '#0ea5e9'],
        'done'      => ['label' => 'انجام شد', 'color' => '#16a34a'],
        'canceled'  => ['label' => 'لغو شد',   'color' => '#b91c1c'],
    ];
}

/** چه کسی می‌تواند نوبت ثبت/ویرایش/حذف کند؟ (مدیر، مدیر شعبه، کارمند/منشی/تکنسین — پزشک فقط مشاهده) */
function canManageScanAppointments(?array $user = null): bool {
    $user = $user ?: (function_exists('current_user') ? current_user() : null);
    if (!$user) return false;
    $role = (string) ($user['role'] ?? '');
    if ($role === 'doctor') return false;   // پزشک فقط نوبت‌های خودش را می‌بیند
    if (in_array($role, ['admin', 'branch_admin', 'technician', 'secretary', 'staff'], true)) return true;
    if (function_exists('has_permission') && has_permission('manage_appointments')) return true;
    return false;
}

/**
 * محدودهٔ نوبت‌های قابل مشاهده برای کاربر:
 *   پزشک → فقط نوبت‌های خودش | کاربر شعبه → نوبت‌های شعبهٔ خودش | مدیر کل → همه.
 * @return array{sql:string, params:array, manage:bool, doctorOnly:bool}
 */
function scanAppointmentScope(?array $user = null): array {
    $user = $user ?: (function_exists('current_user') ? current_user() : null);
    $manage = canManageScanAppointments($user);
    if (!$user) return ['sql' => '1=0', 'params' => [], 'manage' => false, 'doctorOnly' => false];

    if (($user['role'] ?? '') === 'doctor') {
        return ['sql' => 'a.doctor_id = ?', 'params' => [(int) $user['id']], 'manage' => false, 'doctorOnly' => true];
    }
    $bid = function_exists('currentBranchId') ? currentBranchId() : null;
    if ($bid === null) {
        return ['sql' => '1=1', 'params' => [], 'manage' => $manage, 'doctorOnly' => false];
    }
    // شعبهٔ خودی + نوبت‌های بدون شعبه + نوبت‌هایی که پزشکشان به این شعبه تعلق دارد
    // (نوبت بین‌شعبه‌ای هم قابل مشاهده باشد). توجه: این SQL نباید به alias جدول users
    // وابسته باشد تا در همهٔ کوئری‌ها (لیست/فید/داشبورد) قابل استفاده باشد.
    return [
        'sql' => '(a.branch_id = ? OR a.branch_id IS NULL OR a.doctor_id IN (SELECT id FROM users WHERE branch_id = ?))',
        'params' => [(int) $bid, (int) $bid],
        'manage' => $manage,
        'doctorOnly' => false,
    ];
}

/** یک نوبت با اطلاعات پزشک/کیس/شعبه. */
function getScanAppointment(int $id): ?array {
    $st = db()->prepare("SELECT a.*, u.full_name AS doctor_name, u.phone AS doctor_phone, b.name AS branch_name,
               c.patient_name AS case_patient, c.service_id AS case_service_id, c.case_type AS case_type,
               p.title AS service_title, p.short_name AS service_short, cu.full_name AS created_by_name,
               sbt.name AS scan_body_type_name
        FROM scan_appointments a
        LEFT JOIN users u ON u.id = a.doctor_id
        LEFT JOIN branches b ON b.id = a.branch_id
        LEFT JOIN cases c ON c.id = a.case_id
        LEFT JOIN site_prices p ON p.id = c.service_id
        LEFT JOIN users cu ON cu.id = a.created_by
        LEFT JOIN scan_body_types sbt ON sbt.id = a.scan_body_type_id
        WHERE a.id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * لیست/فید نوبت‌ها.
 * @param array $f شامل: from, to, doctor_id, case_id, status, type, q, needs_scan_body, limit
 */
function getScanAppointments(array $f = []): array {
    $scope = scanAppointmentScope();
    $where = [$scope['sql']];
    $params = $scope['params'];

    if (!empty($f['from'])) { $where[] = 'a.appt_date >= ?'; $params[] = $f['from']; }
    if (!empty($f['to']))   { $where[] = 'a.appt_date <= ?'; $params[] = $f['to']; }
    if (!empty($f['id']))   { $where[] = 'a.id = ?';          $params[] = (int) $f['id']; }
    if (!empty($f['doctor_id'])) { $where[] = 'a.doctor_id = ?'; $params[] = (int) $f['doctor_id']; }
    if (!empty($f['case_id']))   { $where[] = 'a.case_id = ?';   $params[] = (int) $f['case_id']; }
    if (!empty($f['status']))    { $where[] = 'a.status = ?';    $params[] = (string) $f['status']; }
    if (!empty($f['type']))      { $where[] = 'a.appt_type = ?'; $params[] = (string) $f['type']; }
    if (isset($f['needs_scan_body']) && $f['needs_scan_body'] !== '') {
        $where[] = 'a.needs_scan_body = ?';
        $params[] = (int) $f['needs_scan_body'] ? 1 : 0;
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $where[] = '(a.patient_name LIKE ? OR a.title LIKE ? OR a.notes LIKE ? OR a.address LIKE ? OR u.full_name LIKE ? OR c.patient_name LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $limit = isset($f['limit']) ? max(1, min(2000, (int) $f['limit'])) : 1000;
    $sql = "SELECT a.*, u.full_name AS doctor_name, b.name AS branch_name, c.patient_name AS case_patient,
                   p.title AS service_title, p.short_name AS service_short, sbt.name AS scan_body_type_name
            FROM scan_appointments a
            LEFT JOIN users u ON u.id = a.doctor_id
            LEFT JOIN branches b ON b.id = a.branch_id
            LEFT JOIN cases c ON c.id = a.case_id
            LEFT JOIN site_prices p ON p.id = c.service_id
            LEFT JOIN scan_body_types sbt ON sbt.id = a.scan_body_type_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.appt_date ASC, a.start_time ASC
            LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** نوبت‌های یک کیس (برای صفحهٔ مشاهدهٔ کیس). */
function getCaseScanAppointments(int $caseId): array {
    $st = db()->prepare("SELECT a.*, u.full_name AS doctor_name, sbt.name AS scan_body_type_name
        FROM scan_appointments a
        LEFT JOIN users u ON u.id = a.doctor_id
        LEFT JOIN scan_body_types sbt ON sbt.id = a.scan_body_type_id
        WHERE a.case_id = ? ORDER BY a.appt_date DESC, a.start_time DESC");
    $st->execute([$caseId]);
    return $st->fetchAll();
}

/** نوبت‌های پیش‌رو (برای داشبورد)، با محدودهٔ دسترسی کاربر. */
function getUpcomingScanAppointments(int $limit = 8, ?string $fromDate = null): array {
    return getScanAppointments([
        'from'  => $fromDate ?: date('Y-m-d'),
        'to'    => date('Y-m-d', strtotime('+60 days')),
        'limit' => $limit,
    ]);
}

/**
 * ثبت/ویرایش نوبت. $d کلیدها: appt_date, start_time, end_time, doctor_id, case_id,
 * patient_name, title, appt_type, needs_scan_body, scan_body_type_id, address, phone, status, notes, branch_id
 */
function saveScanAppointment(array $d, ?int $id = null): int {
    $fields = [
        'branch_id', 'doctor_id', 'case_id', 'patient_name', 'title', 'appt_date', 'start_time', 'end_time',
        'appt_type', 'needs_scan_body', 'scan_body_type_id', 'address', 'phone', 'status', 'notes',
    ];
    $vals = [];
    foreach ($fields as $f) {
        $vals[$f] = array_key_exists($f, $d) ? ($d[$f] === '' ? null : $d[$f]) : null;
    }
    $vals['needs_scan_body'] = !empty($d['needs_scan_body']) ? 1 : 0;
    // نوع اسکن‌بادی فقط وقتی معنی دارد که «اسکن‌بادی لازم است» تیک خورده باشد
    $bodyTypeId = !empty($d['scan_body_type_id']) ? (int) $d['scan_body_type_id'] : null;
    $vals['scan_body_type_id'] = ($vals['needs_scan_body'] && $bodyTypeId && getScanBodyType($bodyTypeId)) ? $bodyTypeId : null;
    $vals['appt_type'] = isset($d['appt_type']) && array_key_exists($d['appt_type'], scanAppointmentTypes()) ? $d['appt_type'] : 'scan';
    $vals['status'] = isset($d['status']) && array_key_exists($d['status'], scanAppointmentStatuses()) ? $d['status'] : 'scheduled';

    if ($id) {
        $sql = 'UPDATE scan_appointments SET branch_id = ?, doctor_id = ?, case_id = ?, patient_name = ?, title = ?, appt_date = ?, start_time = ?, end_time = ?, appt_type = ?, needs_scan_body = ?, scan_body_type_id = ?, address = ?, phone = ?, status = ?, notes = ?, updated_at = NOW() WHERE id = ?';
        $st = db()->prepare($sql);
        $st->execute(array_merge(array_values($vals), [$id]));
        return $id;
    }
    $sql = 'INSERT INTO scan_appointments (branch_id, doctor_id, case_id, patient_name, title, appt_date, start_time, end_time, appt_type, needs_scan_body, scan_body_type_id, address, phone, status, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
    $st = db()->prepare($sql);
    $st->execute(array_merge(array_values($vals), [(int) ($d['created_by'] ?? 0) ?: null]));
    return (int) db()->lastInsertId();
}

function deleteScanAppointment(int $id): void {
    db()->prepare('DELETE FROM scan_appointments WHERE id = ?')->execute([$id]);
}

// =====================================================
// انواع اسکن‌بادی (فیکسچر ایمپلنت)
// =====================================================
// وقتی نوبتِ اسکن نیاز به اسکن‌بادی دارد (needs_scan_body=1)، باید نوع/سیستمِ
// اسکن‌بادی هم مشخص شود (اویتا، انی‌ریج، ...). این کاتالوگ قابل مدیریت است.

/**
 * لیستِ انواع اسکن‌بادی.
 * @param bool $includeInactive آیا موارد غیرفعال هم برگردند؟ (برای صفحهٔ مدیریت)
 */
function getScanBodyTypes(bool $includeInactive = false): array {
    $sql = 'SELECT * FROM scan_body_types'
        . ($includeInactive ? '' : ' WHERE active = 1')
        . ' ORDER BY sort_order ASC, id ASC';
    try {
        $rows = db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        // اگر جدول هنوز ساخته نشده (مثلاً قبل از اجرای ensure)، به‌جای خطای کشنده لیست خالی
        if (function_exists('ensureScanBodyTypes')) { ensureScanBodyTypes(db()); }
        try { $rows = db()->query($sql)->fetchAll(); } catch (Throwable $e2) { $rows = []; }
    }
    return $rows;
}

/** یک نوع اسکن‌بادی. */
function getScanBodyType(int $id): ?array {
    if ($id <= 0) return null;
    try {
        $st = db()->prepare('SELECT * FROM scan_body_types WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return null;
    }
    return $row ?: null;
}

/** نقشهٔ id → نام برای برچسب‌گذاری سریع در لیست‌ها. */
function getScanBodyTypeNames(): array {
    $out = [];
    foreach (getScanBodyTypes(true) as $t) {
        $out[(int) $t['id']] = (string) $t['name'];
    }
    return $out;
}

/** نامِ یک نوع اسکن‌بادی (یا رشتهٔ خالی). */
function scanBodyTypeName(?int $id): string {
    if (!$id) return '';
    $t = getScanBodyType((int) $id);
    return $t ? (string) $t['name'] : '';
}

/** ذخیرهٔ (ایجاد/ویرایش) یک نوع اسکن‌بادی. خطا با Exception برگردانده می‌شود. */
function saveScanBodyType(string $name, int $sortOrder = 0, int $active = 1, ?int $id = null, array $extra = []): int {
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    $dup = db()->prepare('SELECT id FROM scan_body_types WHERE name = ? AND id <> ? LIMIT 1');
    $dup->execute([$name, (int) ($id ?? 0)]);
    if ($dup->fetchColumn()) {
        throw new RuntimeException('duplicate');
    }
    $id = (int) ($id ?? 0);

    // کتابخانهٔ دانلود: لینک خارجی و/یا فایلِ بارگذاری‌شده روی سرور
    $libraryUrl  = array_key_exists('library_url', $extra)  ? trim((string) $extra['library_url'])  : null;
    $libraryPath = array_key_exists('library_path', $extra) ? trim((string) $extra['library_path']) : null;
    $libraryName = array_key_exists('library_name', $extra) ? trim((string) $extra['library_name']) : null;
    $description = array_key_exists('description', $extra)  ? trim((string) $extra['description'])  : null;

    // اعتبارسنجی لینک: فقط http/https مجاز است (جلوگیری از javascript: و data:)
    if ($libraryUrl !== null && $libraryUrl !== '') {
        if (!preg_match('#^https?://#i', $libraryUrl)) {
            throw new InvalidArgumentException('bad_url');
        }
    } else {
        $libraryUrl = null;
    }

    if ($id) {
        $set = [
            'name = ?', 'sort_order = ?', 'active = ?',
            'library_url = COALESCE(?, library_url)',
            'library_path = COALESCE(?, library_path)',
            'library_name = COALESCE(?, library_name)',
            'description = ?',
        ];
        $params = [
            $name, $sortOrder, $active ? 1 : 0,
            $libraryUrl, ($libraryPath === '' ? null : $libraryPath), ($libraryName === '' ? null : $libraryName),
            ($description === '' ? null : $description),
            $id,
        ];
        db()->prepare('UPDATE scan_body_types SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        return $id;
    }

    db()->prepare('INSERT INTO scan_body_types (name, sort_order, active, library_url, library_path, library_name, description, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([
            $name, $sortOrder, $active ? 1 : 0,
            $libraryUrl, ($libraryPath === '' ? null : $libraryPath), ($libraryName === '' ? null : $libraryName),
            ($description === '' ? null : $description),
        ]);
    return (int) db()->lastInsertId();
}

/** تعداد نوبت‌های اسکن و کیس‌هایی که از این نوعِ اسکن‌بادی استفاده می‌کنند. */
function scanBodyTypeUsage(int $id): int {
    $n = 0;
    foreach (['scan_appointments' => 'scan_body_type_id', 'cases' => 'scan_body_type_id'] as $table => $col) {
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = ?");
            $st->execute([$id]);
            $n += (int) $st->fetchColumn();
        } catch (Throwable $e) {
            // جدول/ستون ممکن است هنوز نباشد
        }
    }
    return $n;
}

/** حذفِ یک نوع اسکن‌بادی. اگر در نوبت/کیسی استفاده شده باشد حذف نمی‌شود (false). */
function deleteScanBodyType(int $id): bool {
    if ($id <= 0) return false;
    if (scanBodyTypeUsage($id) > 0) return false;
    // فایلِ کتابخانه (اگر روی سرور ذخیره شده) هم پاک شود
    $t = getScanBodyType($id);
    if ($t && !empty($t['library_path'])) {
        $abs = scanBodyLibraryAbsolutePath((string) $t['library_path']);
        if ($abs && is_file($abs)) { @unlink($abs); }
    }
    db()->prepare('DELETE FROM scan_body_types WHERE id = ?')->execute([$id]);
    return true;
}

// ─── کتابخانهٔ اسکن‌بادی (فایل/لینکِ دانلود) ───

/** پوشهٔ ذخیرهٔ فایل‌های کتابخانهٔ اسکن‌بادی (خارج از دسترسِ مستقیم وب اجرا می‌شود). */
function scanBodyLibraryDir(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'scan_body_library';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** مسیرِ مطلقِ یک فایل کتابخانه از مسیرِ نسبیِ ذخیره‌شده در دیتابیس. */
function scanBodyLibraryAbsolutePath(string $relative): ?string {
    $relative = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
    if ($relative === '' || strpos($relative, '..') !== false) return null;
    return scanBodyLibraryDir() . DIRECTORY_SEPARATOR . $relative;
}

/** آیا این نوع اسکن‌بادی چیزی برای دانلود دارد؟ */
function scanBodyTypeHasLibrary(?array $type): bool {
    if (!$type) return false;
    return !empty($type['library_path']) || !empty($type['library_url']);
}

/** برچسبِ نمایشیِ کتابخانه (نام فایل یا دامنهٔ لینک). */
function scanBodyLibraryLabel(array $type): string {
    $name = trim((string) ($type['library_name'] ?? ''));
    if ($name !== '') return $name;
    $path = trim((string) ($type['library_path'] ?? ''));
    if ($path !== '') return basename(str_replace('\\', '/', $path));
    $url = trim((string) ($type['library_url'] ?? ''));
    if ($url !== '') {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? (string) $host : $url;
    }
    return '';
}

/** نگهبانِ دسترسی به کتابخانهٔ اسکن‌بادی: کاربر باید لاگین باشد و به صفحهٔ اسکن‌بادی دسترسی داشته باشد. */
function canViewScanBodyLibrary(?array $user = null): bool {
    $user = $user ?: (function_exists('current_user') ? current_user() : null);
    if (!$user) return false;
    if (is_admin()) return true;
    // طراح‌ها (و هر کاربری با پرچمِ طراح بودن) فقط مشاهده/دانلود دارند
    if (function_exists('is_designer_user') && is_designer_user($user)) return true;
    if (has_permission('view_scan_body_library')) return true;
    return false;
}

/** نگهبانِ ویرایشِ کاتالوگ اسکن‌بادی (فقط مدیر سیستم و مدیران شعبه). */
function canManageScanBodyTypes(?array $user = null): bool {
    return is_admin();
}

/** تاریخ‌های شمسی برای فیلدها/فیدها. */
function scanApptDateTimeLabel(array $appt): string {
    return toJalaliDateFormatted((string) $appt['appt_date']) . ' — ' . substr((string) $appt['start_time'], 0, 5)
        . (!empty($appt['end_time']) ? ' تا ' . substr((string) $appt['end_time'], 0, 5) : '');
}

// =====================================================
// قیمت‌گذاری خدمات: تعداد دستی + قیمت پله‌ای
// =====================================================

/** تنظیمات قیمت‌گذاری یک خدمت (price, qty_manual, base_units, extra_unit_price). */
function getServicePricing(int $serviceId): array {
    $svc = $serviceId > 0 ? getPrice($serviceId) : null;
    return [
        'id'               => $serviceId,
        'title'            => $svc['title'] ?? '',
        'price'            => $svc ? (float) $svc['price'] : 0.0,
        'qty_manual'       => $svc ? (int) ($svc['qty_manual'] ?? 0) : 0,
        'base_units'       => $svc ? max(1, (int) ($svc['base_units'] ?? 1)) : 1,
        'extra_unit_price' => ($svc && $svc['extra_unit_price'] !== null && $svc['extra_unit_price'] !== '')
            ? (float) $svc['extra_unit_price'] : null,
    ];
}

/**
 * مبلغ کل خدمت با احتساب «قیمت پله‌ای»:
 *   مبلغ = قیمت پایه + (تعداد − واحدهای پایه) × قیمت هر واحد اضافه
 * اگر خدمتی «قیمت هر واحد اضافه» نداشته باشد، رفتار قبلی (تعداد × قیمت) حفظ می‌شود.
 */
function serviceTotalPrice(float $unitPrice, int $quantity, ?int $serviceId): float {
    $qty = max(1, $quantity);
    $p = getServicePricing((int) $serviceId);
    $baseUnits = $p['base_units'];
    $extraUnit = $p['extra_unit_price'] ?? $unitPrice;
    $extraUnits = max(0, $qty - $baseUnits);
    return round($unitPrice + ($extraUnits * $extraUnit));
}

// ----- Branch helpers (multi-branch / hierarchical lab system) -----

/** Get a single branch row. */
function getBranch(int $id): ?array {
    $stmt = db()->prepare('SELECT b.*, u.full_name AS owner_name FROM branches b LEFT JOIN users u ON b.owner_user_id = u.id WHERE b.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** All branches (ordered). */
function getAllBranches(): array {
    $stmt = db()->query('SELECT b.*, u.full_name AS owner_name FROM branches b LEFT JOIN users u ON b.owner_user_id = u.id ORDER BY b.id ASC');
    return $stmt->fetchAll();
}

/**
 * Labs across ALL branches (for inter-branch outsourcing). A branch may outsource
 * work to any lab, including the central branch's lab, so the case-form lab
 * dropdown must not be limited to the current branch. Each row includes the
 * owning branch name so the UI can label where the lab belongs.
 */
function getAllLabs(): array {
    return db()->query("SELECT u.id, u.full_name, u.role, u.branch_id, b.name AS branch_name
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE u.active = 1
          AND (
            -- ۱) نقش‌های لابراتواریِ کلاسیک
            u.role IN ('outsource_lab','partner_lab','customer_lab','lab')
            -- ۲) هر کاربری که واقعاً به‌عنوان گیرندهٔ برون‌سپاری در کیس‌ها ثبت شده
            --    (مثلاً مدیر یک شعبهٔ همکار با نقش branch_admin — باگ گزارش‌شده:
            --     فاکتور برون‌سپاری برای «فاطمه حسینی/قزوین» هیچ کیسی نمی‌آورد چون
            --     در این لیست نبود.)
            OR EXISTS (
                SELECT 1 FROM cases c
                WHERE c.outsourced_lab_id = u.id AND c.outsourced_qty > 0
            )
            OR EXISTS (
                SELECT 1 FROM cases c2
                WHERE c2.case_type = 'lab_out' AND c2.lab_id = u.id
            )
            -- ۳) هر کاربری که برایش نرخ برون‌سپاری توافقی ثبت شده
            OR EXISTS (
                SELECT 1 FROM outsource_rates r WHERE r.lab_id = u.id
            )
          )
        ORDER BY u.branch_id IS NULL, b.name, u.full_name")->fetchAll();
}

/**
 * Lab options for OUTSOURCING a case (برون‌سپاری / کار از لابراتوار همکار).
 * همهٔ لابراتوارهای فعال برگردانده می‌شوند: یک شعبه می‌تواند هم به لابراتوارهای
 * زیرمجموعهٔ خودش کار بدهد/بگیرد و هم به لابراتوارهای شعب دیگر. (§ تصمیم کاربر)
 * پارامتر $keepLabId فقط برای سازگاریِ فراخوانی‌های قبلی نگه داشته شده است.
 */
function getOutsourceLabOptions(?int $keepLabId = null): array {
    return getAllLabs();
}

/**
 * The branch the current user is scoped to.
 * - Root admin (role = 'admin') is ALWAYS global → returns null, regardless of branch_id.
 * - A branch-scoped user (branch_id set, e.g. branch_admin/staff/doctor) sees only that branch.
 * - A user with branch_id NULL sees everything (returns null).
 */
function currentBranchId(): ?int {
    if (!function_exists('current_user')) return null;
    $user = current_user();
    if (!$user) return null;
    if ($user['role'] === 'admin') return null;   // root admin is always global
    $bid = $user['branch_id'] ?? null;
    return $bid !== null && $bid !== '' ? (int) $bid : null;
}

/**
 * Which branch a doctor_invoice BELONGS to, financially.
 * Prefers the invoice's own branch_id; for legacy/self-made invoices that were
 * saved without a branch (branch_id NULL), falls back to the doctor's branch.
 * (کلینیک‌/لابراتوارهایی که شعبه ندارند و فاکتورشان هم بدون شعبه است → به هیچ شعبه‌ای تعلق
 *  نمی‌گیرند و فقط در آمار سراسری می‌آیند.)
 * @param string $alias SQL alias of the doctor_invoices table.
 * @param int|null $branchId target branch (default: current user's branch).
 * @return array ['sql'=>.., 'params'=>[..]]
 */
function doctorInvoiceBranchScope(string $alias = 'i', ?int $branchId = null): array {
    $bid = $branchId !== null ? (int) $branchId : currentBranchId();
    if ($bid === null) {
        return ['sql' => '1=1', 'params' => []];
    }
    return [
        'sql' => "COALESCE({$alias}.branch_id, (SELECT u.branch_id FROM users u WHERE u.id = {$alias}.doctor_id)) = ?",
        'params' => [$bid],
    ];
}

/**
 * شعبه‌ای که یک فاکتور پزشک/کلینیک باید به آن منتسب شود.
 * ترتیب: ۱) شعبهٔ خودِ طرف حساب  ۲) شعبهٔ کاربرِ سازنده/ویرایش‌کننده  ۳) شعبهٔ مرکزی (۱).
 * دلیل وجودِ مورد ۳: بعضی پزشکان/کلینیک‌ها اصلاً شعبه ندارند (users.branch_id = NULL)؛
 * اگر فاکتورشان بدون شعبه بماند، برای کاربرانِ محدود به شعبه در «لیست فاکتورها» دیده نمی‌شود
 * (باگ گزارش‌شده: فاکتور #40 بعد از ویرایش ناپدید شد).
 */
function resolveInvoiceBranchId($doctorId = null): int {
    if (!empty($doctorId)) {
        $bs = db()->prepare('SELECT branch_id FROM users WHERE id = ? LIMIT 1');
        $bs->execute([(int) $doctorId]);
        $dbBranch = $bs->fetchColumn();
        if ($dbBranch !== null && $dbBranch !== '' && (int) $dbBranch > 0) {
            return (int) $dbBranch;
        }
    }
    $cur = currentBranchId();
    if ($cur !== null && (int) $cur > 0) {
        return (int) $cur;
    }
    return 1;   // شعبهٔ مرکزی
}

/**
 * آیا این کاربر مسئول «هزینه‌های طراحی» است؟ هزینه طراحی همیشه توسط شعبه‌ی مرکزی (۱) پرداخت
 * می‌شود — حتی برای کیس‌های مالِ شعب دیگر که به لابراتوار مرکزی وصل‌اند. پس صدور/مشاهده‌ی
 * فاکتور طراحی فقط برای مدیر کل (بدون شعبه) یا مدیرِ شعبه‌ی مرکزی مجاز است.
 */
function isCentralDesignPayer(): bool {
    $user = current_user();
    if (!$user) return false;
    if (!in_array($user['role'] ?? '', ['admin', 'branch_admin'], true)) return false;
    $bid = currentBranchId();
    return $bid === null || $bid === 1;
}

/** Whether the current user may access a given branch. */
function canAccessBranch(int $branchId): bool {
    $user = current_user();
    if (!$user) return false;
    if (has_permission('view_all_cases') && (($user['branch_id'] ?? null) === null || $user['branch_id'] === '')) {
        return true; // root/global admin sees all branches
    }
    return ((int) ($user['branch_id'] ?? 0)) === $branchId;
}

/**
 * Build a WHERE-clause snippet + params to scope a query to the current user's branch.
 * Returns ['sql' => '...', 'params' => [...], 'scoped' => bool].
 * $alias = the table alias used in the query for the branch-bearing table.
 * $column = the column name holding branch_id (default 'branch_id').
 */
function branchScope(string $alias, string $column = 'branch_id'): array {
    $bid = currentBranchId();
    if ($bid === null) {
        return ['sql' => '1=1', 'params' => [], 'scoped' => false];
    }
    return ['sql' => "{$alias}.{$column} = ?", 'params' => [(int) $bid], 'scoped' => true];
}

/**
 * Case visibility for a branch-scoped user (a branch manager or any user of a branch).
 * A user sees:
 *   - cases OWNED by their branch (branch_id = theirs)
 *   - cases where their branch is the SOURCE/partner (source_branch_id = theirs) —
 *     i.e. work outsourced to them or from them (two-financial-views shared case).
 *   - cases side-outsourced TO a lab that belongs to their branch (outsourced_lab_id)
 *     and cases fully outsourced to a lab of their branch (lab_id) — even when
 *     source_branch_id was not filled in.
 *   - cases of doctors GRANTED to them via branch_doctor_access.
 *   - the whole case family: if a case is visible, its parent and children are
 *     visible too (so a side-outsourced sub-case and its main case are both seen).
 * Root/global admins see everything.
 * Returns ['sql' => '...', 'params' => [...], 'scoped' => bool].
 */
function branchCaseScope(string $alias = 'c', ?int $branchId = null): array {
    $bid = $branchId !== null ? (int) $branchId : currentBranchId();
    if ($bid === null) {
        return ['sql' => '1=1', 'params' => [], 'scoped' => false];
    }
    $granted = accessibleDoctorIds();

    // Base visibility (expressed for an arbitrary alias).
    $base = function ($a) use ($bid, $granted) {
        $s = "({$a}.branch_id = ? OR {$a}.source_branch_id = ?"
            . " OR {$a}.outsourced_lab_id IN (SELECT id FROM users WHERE branch_id = ?)"
            . " OR {$a}.lab_id IN (SELECT id FROM users WHERE branch_id = ?)";
        $p = [(int) $bid, (int) $bid, (int) $bid, (int) $bid];
        if (!empty($granted)) {
            $ph = implode(',', array_fill(0, count($granted), '?'));
            $s .= " OR {$a}.doctor_id IN ({$ph})";
            $p = array_merge($p, $granted);
        }
        $s .= ")";
        return [$s, $p];
    };

    [$baseSql, $params] = $base($alias);

    // Expand to the whole case family (parents of visible children + children
    // of visible parents). Subqueries scan the cases table with alias 'sub'.
    [$subSql, $subParams] = $base('sub');
    $sql = "({$baseSql}"
        . " OR {$alias}.parent_id IN (SELECT id FROM cases AS sub WHERE {$subSql})"
        . " OR {$alias}.id IN (SELECT parent_id FROM cases AS sub WHERE {$subSql} AND sub.parent_id IS NOT NULL)"
        . ")";
    $params = array_merge($params, $subParams, $subParams);

    return ['sql' => $sql, 'params' => $params, 'scoped' => true];
}

/**
 * Doctor OWNERSHIP: a doctor belongs to the branch stored in users.branch_id.
 * A branch may access doctors that:
 *   - belong to their branch (users.branch_id = theirs), OR
 *   - are granted to them via branch_doctor_access (by the owning branch).
 * Cross-branch OUTSOURCING gives case visibility only, never financial access
 * to another branch's doctor.
 *
 * Returns the list of doctor IDs the current branch may access
 * (empty = global/root admin → unrestricted).
 */
function accessibleDoctorIds(): array {
    $bid = currentBranchId();
    if ($bid === null) {
        return []; // root admin: unrestricted
    }
    $ids = [];
    // Owned doctors
    $stmt = db()->prepare('SELECT id FROM users WHERE role = "doctor" AND branch_id = ?');
    $stmt->execute([$bid]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[(int) $v] = true;
    // Granted doctors (another branch gave us access)
    $stmt = db()->prepare('SELECT doctor_id FROM branch_doctor_access WHERE branch_id = ?');
    $stmt->execute([$bid]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[(int) $v] = true;
    return array_keys($ids);
}

/**
 * Doctor ownership scope for SQL: a branch sees its own doctors + granted doctors.
 * Returns ['sql' => ..., 'params' => [...]]. Unrestricted for root admin.
 * $column = the doctor id column (e.g. 'c.doctor_id', 'i.doctor_id').
 */
function doctorBranchScope(string $column): array {
    $ids = accessibleDoctorIds();
    if (empty($ids)) {
        return ['sql' => '1=1', 'params' => []];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return ['sql' => "{$column} IN ({$ph})", 'params' => $ids];
}

/**
 * Whether the current branch may access a given doctor's financial data
 * (cases, invoices, payments). Root admin → always true.
 */
function canAccessDoctorFinancially(int $doctorId): bool {
    if (currentBranchId() === null) return true;
    return in_array($doctorId, accessibleDoctorIds(), true);
}

/**
 * Designers visible to the current user for case assignment.
 * - Root admin: all designers.
 * - Branch user: designers of their own branch PLUS the default designer
 *   (is_default_designer=1, wherever they belong) so branches/partner labs can
 *   auto-select the default designer. Includes the is_default_designer flag.
 */
function getAllDesigners(): array {
    $bid = currentBranchId();
    if ($bid === null) {
        $stmt = db()->query("SELECT id, full_name, is_default_designer FROM users WHERE is_designer=1 AND active=1 ORDER BY is_default_designer DESC, full_name");
        return $stmt->fetchAll();
    }
    $stmt = db()->prepare("SELECT id, full_name, is_default_designer FROM users WHERE is_designer=1 AND active=1 AND (branch_id = ? OR is_default_designer = 1) ORDER BY is_default_designer DESC, full_name");
    $stmt->execute([$bid]);
    return $stmt->fetchAll();
}

/**
 * The default designer (is_default_designer=1) for auto-assignment when a
 * partner lab / branch creates a case. Returns array|false.
 */
function getDefaultDesigner() {
    $stmt = db()->query("SELECT id, full_name FROM users WHERE is_designer=1 AND active=1 AND is_default_designer=1 ORDER BY id LIMIT 1");
    return $stmt->fetch();
}

/**
 * برچسبی که به کاربران بیرونی (پزشک/کلینیک/لابراتوار) به‌جای نام طراح نشان داده می‌شود.
 *
 * ⚠️ این تابع قبلاً نامِ واقعیِ طراحِ پیش‌فرض را برمی‌گرداند و در نتیجه خودِ «ماسک»
 * نام طراح را لو می‌داد (هم در ستون طراحِ جدول کیس‌ها، هم در صفحهٔ مشاهدهٔ کیس).
 * اکنون همیشه یک برچسبِ ثابت برمی‌گرداند و هیچ نامی از طراح را افشا نمی‌کند.
 */
function defaultDesignerDisplayName(): string {
    return 'طراح پیش‌فرض';
}

/**
 * آیا طراحِ پیش‌فرض تعریف شده است؟ (برای تصمیم‌های منطقی، بدون افشای نام)
 */
function hasDefaultDesigner(): bool {
    $d = getDefaultDesigner();
    return !empty($d['id']);
}

// ----- Price functions -----
/**
 * site_prices is the SHARED standard service catalog: all branches use the same
 * service titles/units/IDs. Each branch may override a price per service via
 * branch_service_prices. This function returns the shared catalog.
 */
function getPrices() {
    $stmt = db()->query("SELECT * FROM site_prices WHERE active = 1 AND hide_on_site = 0 ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    return $stmt->fetchAll();
}

function getPrice($id) {
    $stmt = db()->prepare('SELECT * FROM site_prices WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function getAllPrices() {
    $stmt = db()->query("SELECT * FROM site_prices ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    return $stmt->fetchAll();
}

/** Get the current branch's custom price for a service (or null if none set). */
function getBranchServiceCustomPrice(int $serviceId): ?float {
    $bid = currentBranchId();
    if ($bid === null) return null;
    $stmt = db()->prepare('SELECT custom_price FROM branch_service_prices WHERE branch_id = ? AND service_id = ?');
    $stmt->execute([$bid, $serviceId]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null) ? (float) $val : null;
}

/** Set (insert/update) the current branch's custom price for a service. */
function setBranchServiceCustomPrice(int $serviceId, ?float $price): void {
    $bid = currentBranchId();
    if ($bid === null) return; // root admin manages the shared catalog
    $existing = db()->prepare('SELECT id FROM branch_service_prices WHERE branch_id = ? AND service_id = ?');
    $existing->execute([$bid, $serviceId]);
    $id = $existing->fetchColumn();
    if ($price === null || $price <= 0) {
        if ($id) {
            $del = db()->prepare('DELETE FROM branch_service_prices WHERE id = ?');
            $del->execute([$id]);
        }
        return;
    }
    if ($id) {
        $upd = db()->prepare('UPDATE branch_service_prices SET custom_price = ?, updated_at = NOW() WHERE id = ?');
        $upd->execute([$price, $id]);
    } else {
        $ins = db()->prepare('INSERT INTO branch_service_prices (branch_id, service_id, custom_price, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
        $ins->execute([$bid, $serviceId, $price]);
    }
}

// =====================================================
// Unified price-link → legacy tables sync (dual-write)
// =====================================================
// The unified price map (price_links) is the single place to manage rates. We
// keep the legacy billing tables in sync so invoice/cost logic stays untouched.

/** Upsert a doctor_price_overrides row (target = doctor/clinic/designer/lab user). */
function upsertDoctorPriceOverrideLegacy(int $targetId, ?int $serviceId, string $priceType, float $price, int $bid): void {
    $existing = db()->prepare('SELECT id FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND ((? IS NULL AND service_id IS NULL) OR service_id = ?)');
    $existing->execute([$targetId, $priceType, $serviceId, $serviceId]);
    $id = $existing->fetchColumn();
    if ($id) {
        db()->prepare('UPDATE doctor_price_overrides SET custom_price = ?, service_id = ?, branch_id = ?, updated_at = NOW() WHERE id = ?')->execute([$price, $serviceId, $bid, $id]);
    } else {
        db()->prepare('INSERT INTO doctor_price_overrides (doctor_id, service_id, price_type, custom_price, branch_id, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())')->execute([$targetId, $serviceId, $priceType, $price, $bid]);
    }
}

/** Delete a doctor_price_overrides row for a target+service (price_type service|design_fee). */
function deleteDoctorPriceOverrideByTarget(int $targetId, ?int $serviceId, string $priceType): void {
    if ($serviceId !== null) {
        $stmt = db()->prepare('DELETE FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?');
        $stmt->execute([$targetId, $priceType, $serviceId]);
    } else {
        $stmt = db()->prepare('DELETE FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id IS NULL');
        $stmt->execute([$targetId, $priceType]);
    }
}

/** Upsert an outsource_rates row (what a lab charges us). */
function upsertOutsourceRateLegacy(int $labId, ?int $serviceId, float $rate, ?int $branchId, int $bid): void {
    if ($serviceId === null) return;
    $target = $branchId !== null ? $branchId : $bid;
    $existing = db()->prepare('SELECT id, branch_id FROM outsource_rates WHERE lab_id = ? AND service_id = ? AND (branch_id = ? OR branch_id IS NULL) ORDER BY (branch_id = ?) DESC LIMIT 1');
    $existing->execute([$labId, $serviceId, $target, $target]);
    $row = $existing->fetch();
    if ($row && ($branchId === null || (int) $row['branch_id'] === $target)) {
        db()->prepare('UPDATE outsource_rates SET rate = ?, updated_at = NOW() WHERE id = ?')->execute([$rate, (int) $row['id']]);
    } else {
        db()->prepare('INSERT INTO outsource_rates (lab_id, service_id, rate, branch_id, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())')->execute([$labId, $serviceId, $rate, $branchId]);
    }
}

/** Delete outsource_rates rows for a lab+service. */
function deleteOutsourceRateByLabService(int $labId, ?int $serviceId): void {
    if ($serviceId === null) return;
    db()->prepare('DELETE FROM outsource_rates WHERE lab_id = ? AND service_id = ?')->execute([$labId, $serviceId]);
}

/** Upsert a lab_price_overrides row (legacy lab price). */
function upsertLabPriceOverrideLegacy(int $labId, ?int $serviceId, float $price): void {
    if ($serviceId === null) return;
    $existing = db()->prepare('SELECT id FROM lab_price_overrides WHERE lab_id = ? AND service_id = ?');
    $existing->execute([$labId, $serviceId]);
    $id = $existing->fetchColumn();
    if ($id) {
        db()->prepare('UPDATE lab_price_overrides SET custom_price = ?, updated_at = NOW() WHERE id = ?')->execute([$price, $id]);
    } else {
        db()->prepare('INSERT INTO lab_price_overrides (lab_id, service_id, custom_price, branch_id, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())')->execute([$labId, $serviceId, $price, currentBranchId() ?? 1]);
    }
}

/** Delete lab_price_overrides rows for a lab+service. */
function deleteLabPriceOverrideByLabService(int $labId, ?int $serviceId): void {
    if ($serviceId === null) return;
    db()->prepare('DELETE FROM lab_price_overrides WHERE lab_id = ? AND service_id = ?')->execute([$labId, $serviceId]);
}

/** Set (or clear) an arbitrary branch's default price for a service (branch_service_prices). */
function setBranchServiceCustomPriceFor(int $branchId, int $serviceId, ?float $price): void {
    $existing = db()->prepare('SELECT id FROM branch_service_prices WHERE branch_id = ? AND service_id = ?');
    $existing->execute([$branchId, $serviceId]);
    $id = $existing->fetchColumn();
    if ($price === null || $price <= 0) {
        if ($id) db()->prepare('DELETE FROM branch_service_prices WHERE id = ?')->execute([$id]);
        return;
    }
    if ($id) {
        db()->prepare('UPDATE branch_service_prices SET custom_price = ?, updated_at = NOW() WHERE id = ?')->execute([$price, $id]);
    } else {
        db()->prepare('INSERT INTO branch_service_prices (branch_id, service_id, custom_price, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())')->execute([$branchId, $serviceId, $price]);
    }
}

/**
 * Mirror a price_links row into the legacy billing table it represents.
 * Called on insert/update of a unified price-link so existing billing stays intact.
 */
function syncLegacyFromPriceLink(array $l): void {
    $kind = $l['price_type'] ?? '';
    $serviceId = isset($l['service_id']) && $l['service_id'] !== '' && $l['service_id'] !== null ? (int) $l['service_id'] : null;
    $price = (float) $l['price'];
    $branchId = isset($l['branch_id']) && $l['branch_id'] !== '' && $l['branch_id'] !== null ? (int) $l['branch_id'] : null;
    $bid = currentBranchId() ?? 1;

    if ($kind === 'design_fee' && in_array($l['provider_type'] ?? '', ['doctor', 'designer'], true) && !empty($l['provider_id'])) {
        upsertDoctorPriceOverrideLegacy((int) $l['provider_id'], $serviceId, 'design_fee', $price, $bid);
    }
    if ($kind === 'branch_default' && ($l['provider_type'] ?? '') === 'branch' && !empty($l['provider_id']) && $serviceId) {
        setBranchServiceCustomPriceFor((int) $l['provider_id'], $serviceId, $price);
    }
    // ردیف‌های جهت‌دار «اختصاصی» (نوع قدیمی «outsource» هم برای سازگاری): جهت از روی طرفین
    // مشخص است — ارائه‌دهنده = انجام‌دهنده کار، دریافت‌کننده = پرداخت‌کننده.
    if (in_array($kind, ['outsource', 'specific'], true)) {
        $pType = $l['provider_type'] ?? '';
        $rType = $l['receiver_type'] ?? '';
        if ($pType === 'lab' && !empty($l['provider_id']) && $rType === 'branch') {
            // یک شعبه به این لابراتوار می‌پردازد → همگام با جدول نرخ برون‌سپاری قدیمی
            upsertOutsourceRateLegacy((int) $l['provider_id'], $serviceId, $price, (int) $l['receiver_id'], $bid);
            upsertLabPriceOverrideLegacy((int) $l['provider_id'], $serviceId, $price);
        }
        if ($rType === 'doctor' && !empty($l['receiver_id'])) {
            // از این پزشک می‌گیریم → قیمت اختصاصی پزشک
            upsertDoctorPriceOverrideLegacy((int) $l['receiver_id'], $serviceId, 'service', $price, $bid);
        }
        if ($rType === 'lab' && !empty($l['receiver_id'])) {
            // این لابراتوار به ما می‌پردازد → قیمت اختصاصیِ همان لابراتوار
            upsertLabPriceOverrideLegacy((int) $l['receiver_id'], $serviceId, $price);
        }
    }
}

/** Remove the legacy rows mirrored by a price_links row (called on delete). */
function unsyncLegacyFromPriceLink(array $l): void {
    $kind = $l['price_type'] ?? '';
    $serviceId = isset($l['service_id']) && $l['service_id'] !== '' && $l['service_id'] !== null ? (int) $l['service_id'] : null;
    if ($kind === 'design_fee' && in_array($l['provider_type'] ?? '', ['doctor', 'designer'], true) && !empty($l['provider_id'])) {
        deleteDoctorPriceOverrideByTarget((int) $l['provider_id'], $serviceId, 'design_fee');
    }
    if ($kind === 'branch_default' && ($l['provider_type'] ?? '') === 'branch' && !empty($l['provider_id']) && $serviceId) {
        setBranchServiceCustomPriceFor((int) $l['provider_id'], $serviceId, null);
    }
    // جهت‌دار «اختصاصی» / قدیمی «outsource»
    if (in_array($kind, ['outsource', 'specific'], true)) {
        $pType = $l['provider_type'] ?? '';
        $rType = $l['receiver_type'] ?? '';
        if ($pType === 'lab' && !empty($l['provider_id']) && $rType === 'branch') {
            deleteOutsourceRateByLabService((int) $l['provider_id'], $serviceId);
            deleteLabPriceOverrideByLabService((int) $l['provider_id'], $serviceId);
        }
        if ($rType === 'doctor' && !empty($l['receiver_id'])) {
            deleteDoctorPriceOverrideByTarget((int) $l['receiver_id'], $serviceId, 'service');
        }
        if ($rType === 'lab' && !empty($l['receiver_id'])) {
            deleteLabPriceOverrideByLabService((int) $l['receiver_id'], $serviceId);
        }
    }
}

// ----- Portfolio functions (unchanged) -----
function getPortfolioWorks() {
    $stmt = db()->prepare("SELECT * FROM portfolio_works WHERE active = 1 ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getPortfolioWork($id) {
    $stmt = db()->prepare('SELECT * FROM portfolio_works WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function getAllPortfolioWorks() {
    $stmt = db()->query("SELECT * FROM portfolio_works ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    return $stmt->fetchAll();
}

// ----- User/Doctor functions (using users table) -----
/**
 * Doctors visible to the current user.
 * - Root admin: all doctors.
 * - Branch user: doctors OWNED by their branch (users.branch_id = theirs)
 *   OR granted to them via branch_doctor_access. Doctors with NULL branch
 *   are treated as global/shared (visible to all branches).
 */
function getAllDoctors() {
    $bid = currentBranchId();
    if ($bid === null) {
        $stmt = db()->query('SELECT id, full_name AS name, email, phone, notes, active FROM users WHERE role = "doctor" ORDER BY full_name ASC');
        return $stmt->fetchAll();
    }
    $granted = accessibleDoctorIds();
    $where = '(branch_id = ? OR branch_id IS NULL)';
    $params = [$bid];
    if (!empty($granted)) {
        $ph = implode(',', array_fill(0, count($granted), '?'));
        $where .= ' OR id IN (' . $ph . ')';
        $params = array_merge($params, $granted);
    }
    $stmt = db()->prepare('SELECT id, full_name AS name, email, phone, notes, active FROM users WHERE role = "doctor" AND ' . $where . ' ORDER BY full_name ASC');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getAllBillingTargets() {
    $stmt = db()->query('SELECT id, full_name AS name, role, is_designer, email, phone, notes, active FROM users WHERE role IN ("doctor", "clinic", "designer", "partner_lab", "customer_lab", "outsource_lab", "lab") ORDER BY full_name ASC');
    return $stmt->fetchAll();
}

function getAllDoctorAndClinicUsers() {
    $stmt = db()->query('SELECT id, full_name AS name, role, active FROM users WHERE role IN ("doctor", "clinic") ORDER BY full_name ASC');
    return $stmt->fetchAll();
}

// =====================================================
// کلینیک‌ها — عضویتِ چندگانه (یک پزشک می‌تواند در چند کلینیک کار کند)
// =====================================================
// مدل داده:
//   users.clinic_id      = «کلینیک اصلی» (پیش‌فرضِ کیس‌های جدید و نمایشِ خلاصه)
//   user_clinics         = عضویت‌های اضافی (چند کلینیک)
//   cases.clinic_id      = کلینیکِ صاحبِ همان کار (مستقل از تغییرِ بعدیِ کلینیکِ پزشک)
// همهٔ کوئری‌های دسترسی/فاکتور از تابع‌های زیر استفاده می‌کنند تا هر دو مسیر دیده شود.

/** فهرست کلینیک‌ها (کاربران با نقشِ کلینیک). */
function getAllClinics(bool $activeOnly = true): array {
    $sql = "SELECT id, full_name, phone, active FROM users WHERE role = 'clinic'";
    if ($activeOnly) $sql .= ' AND active = 1';
    $sql .= ' ORDER BY full_name';
    return db()->query($sql)->fetchAll();
}

/** شناسهٔ همهٔ کلینیک‌های یک کاربر (اجتماعِ «کلینیک اصلی» و جدولِ پیوند). */
function getUserClinicIds(int $userId): array {
    if ($userId <= 0) return [];
    $ids = [];
    try {
        $st = db()->prepare('SELECT clinic_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $primary = $st->fetchColumn();
        if (!empty($primary)) $ids[] = (int) $primary;
    } catch (\Throwable $e) {}
    try {
        $st = db()->prepare('SELECT clinic_id FROM user_clinics WHERE user_id = ?');
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) $ids[] = $cid;
        }
    } catch (\Throwable $e) {}
    return array_values(array_unique($ids));
}

/** نامِ کلینیک‌های یک کاربر → [clinicId => name] */
function getUserClinicNames(int $userId): array {
    $ids = getUserClinicIds($userId);
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, full_name FROM users WHERE id IN ({$ph}) ORDER BY full_name");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['id']] = (string) $r['full_name'];
    }
    return $out;
}

/** پزشکانِ عضوِ یک کلینیک. (روی شعبه/لابراتوار حساس نیست — فقط عضویت کلینیکی.) */
function getClinicDoctorIdsForClinic(int $clinicId, bool $activeOnly = true): array {
    if ($clinicId <= 0) return [];
    try {
        $sql = "SELECT DISTINCT u.id FROM users u
                LEFT JOIN user_clinics uc ON uc.user_id = u.id
                WHERE u.role = 'doctor'"
                . ($activeOnly ? ' AND u.active = 1' : '')
                . ' AND (u.clinic_id = ? OR uc.clinic_id = ?)';
        $st = db()->prepare($sql);
        $st->execute([$clinicId, $clinicId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (\Throwable $e) {
        // اگر جدول پیوند هنوز ساخته نشده باشد (نصب تازه)، فقط کلینیک اصلی
        $st = db()->prepare("SELECT id FROM users WHERE role = 'doctor'" . ($activeOnly ? ' AND active = 1' : '') . ' AND clinic_id = ?');
        $st->execute([$clinicId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}

/**
 * شرط SQL «کیس‌هایی که این کلینیک صاحبشان است».
 *
 * قاعدهٔ اصلی (پیش‌فرض): فقط کیس‌هایی که کلینیکشان صریحاً همین کلینیک است
 *   (cases.clinic_id = ?) — چون فاکتور کلینیک به «کسی که باید پرداخت کند» داده می‌شود و
 *   یک پزشک می‌تواند در چند کلینیک کار کند؛ پس کارهای کلینیک دیگر نباید در فاکتور/لیست بیاید.
 *
 * $includeDoctorCases = true → علاوه بر آن، کارهای پزشکانِ عضوِ کلینیک هم گرفته می‌شود
 *   (برای موارد خاص؛ در فاکتور و فهرست‌های معمول استفاده نمی‌شود).
 */
function clinicCaseScope(string $alias, int $clinicId, bool $includeDoctorCases = false): array {
    $clinicId = (int) $clinicId;
    if ($clinicId <= 0) return ['sql' => '1=0', 'params' => []];
    $sql = "{$alias}.clinic_id = ?";
    $params = [$clinicId];
    if ($includeDoctorCases) {
        $ids = getClinicDoctorIdsForClinic($clinicId, false);
        if ($ids) {
            $sql = "({$sql} OR {$alias}.doctor_id IN (" . implode(',', array_fill(0, count($ids), '?')) . '))';
            $params = array_merge($params, $ids);
        }
    }
    return ['sql' => $sql, 'params' => $params];
}

/**
 * ذخیرهٔ عضویت‌های کلینیکی یک کاربر.
 * @param array    $clinicIds همهٔ کلینیک‌هایی که کاربر عضو آن‌هاست
 * @param int|null $primaryId کلینیک اصلی (تک‌مقداری، در users.clinic_id)
 */
function setUserClinics(int $userId, array $clinicIds, ?int $primaryId = null): void {
    if ($userId <= 0) return;
    $ids = [];
    foreach ($clinicIds as $cid) {
        $cid = (int) $cid;
        if ($cid > 0) $ids[$cid] = $cid;
    }
    $primary = (int) ($primaryId ?? 0);
    if ($primary > 0) $ids[$primary] = $primary;

    if ($primary > 0) {
        db()->prepare('UPDATE users SET clinic_id = ? WHERE id = ?')->execute([$primary, $userId]);
    } else {
        db()->prepare('UPDATE users SET clinic_id = NULL WHERE id = ?')->execute([$userId]);
    }
    try {
        db()->prepare('DELETE FROM user_clinics WHERE user_id = ?')->execute([$userId]);
        if ($ids) {
            $ins = db()->prepare('INSERT IGNORE INTO user_clinics (user_id, clinic_id) VALUES (?, ?)');
            foreach ($ids as $cid) $ins->execute([$userId, $cid]);
        }
    } catch (\Throwable $e) {}
}

/** افزودن یک عضویتِ کلینیکی (اگر کلینیک اصلی خالی باشد، همان می‌شود). */
function addUserToClinic(int $userId, int $clinicId): void {
    if ($userId <= 0 || $clinicId <= 0) return;
    try {
        db()->prepare('INSERT IGNORE INTO user_clinics (user_id, clinic_id) VALUES (?, ?)')->execute([$userId, $clinicId]);
    } catch (\Throwable $e) {}
    $st = db()->prepare('SELECT clinic_id FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    if (empty($st->fetchColumn())) {
        db()->prepare('UPDATE users SET clinic_id = ? WHERE id = ?')->execute([$clinicId, $userId]);
    }
}

/** حذف یک عضویتِ کلینیکی (اگر کلینیک اصلی همان بود، یکی از بقیه جانشین می‌شود). */
function removeUserFromClinic(int $userId, int $clinicId): void {
    if ($userId <= 0 || $clinicId <= 0) return;
    try {
        db()->prepare('DELETE FROM user_clinics WHERE user_id = ? AND clinic_id = ?')->execute([$userId, $clinicId]);
    } catch (\Throwable $e) {}
    $st = db()->prepare('SELECT clinic_id FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() === (int) $clinicId) {
        $rest = getUserClinicIds($userId);
        $next = 0;
        foreach ($rest as $cid) { if ((int) $cid !== (int) $clinicId) { $next = (int) $cid; break; } }
        db()->prepare('UPDATE users SET clinic_id = ? WHERE id = ?')->execute([$next > 0 ? $next : null, $userId]);
    }
}

function getDoctor($id) {
    $stmt = db()->prepare('SELECT id, full_name AS name, email, phone, notes, active, clinic_id, lab_id, last_login FROM users WHERE id = ? AND role = "doctor" LIMIT 1');
    $stmt->execute([(int) $id]);
    $result = $stmt->fetch();
    return $result;
}

/** Doctor profile gallery (photos + captions showing work style/taste). */
function getDoctorGallery(int $doctorId): array {
    $stmt = db()->prepare('SELECT * FROM doctor_gallery WHERE doctor_id = ? ORDER BY id DESC');
    $stmt->execute([$doctorId]);
    return $stmt->fetchAll();
}

function saveDoctor($data) {
    $now = date('Y-m-d H:i:s');
    $newPasswordHash = !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null;
    $clinicId = !empty($data['clinic_id']) ? (int) $data['clinic_id'] : null;
    $labId = !empty($data['lab_id']) ? (int) $data['lab_id'] : null;
    $memberClinics = (isset($data['clinic_ids']) && is_array($data['clinic_ids']))
        ? array_values(array_filter(array_unique(array_map('intval', $data['clinic_ids'])), function ($v) { return $v > 0; }))
        : [];

    if (isset($data['id']) && !empty($data['id'])) {
        // Update existing user
        if ($newPasswordHash !== null) {
            $stmt = db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, active = ?, notes = ?, clinic_id = ?, lab_id = ?, password_hash = ?, updated_at = ? WHERE id = ? AND role = "doctor"');
            $stmt->execute([
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['active'] ?? 1,
                $data['notes'] ?? null,
                $clinicId,
                $labId,
                $newPasswordHash,
                $now,
                (int) $data['id']
            ]);
        } else {
            $stmt = db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, active = ?, notes = ?, clinic_id = ?, lab_id = ?, updated_at = ? WHERE id = ? AND role = "doctor"');
            $stmt->execute([
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['active'] ?? 1,
                $data['notes'] ?? null,
                $clinicId,
                $labId,
                $now,
                (int) $data['id']
            ]);
        }
        $savedDoctorId = (int) $data['id'];
    } else {
        // Insert new user with role 'doctor'
        $username = $data['email'] ?? $data['phone'] ?? 'doc_' . uniqid();
        $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, active, notes, clinic_id, lab_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "doctor", ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $username,
            $newPasswordHash,
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['active'] ?? 1,
            $data['notes'] ?? null,
            $clinicId,
            $labId,
            $now,
            $now
        ]);
        $savedDoctorId = (int) db()->lastInsertId();
    }

    // ─── عضویت‌های کلینیکی (چند کلینیک) ───
    // «کلینیک/پدر» انتخابی هم یکی از عضویت‌هاست؛ اگر کلینیکی انتخاب نشده باشد
    // ولی عضویت‌ها تیک خورده باشند، اولین عضویت «کلینیک اصلی» می‌شود.
    $memberships = $memberClinics;
    if ($clinicId) array_unshift($memberships, $clinicId);
    $primaryClinic = $clinicId ? $clinicId : (!empty($memberships) ? (int) $memberships[0] : null);
    if ($savedDoctorId > 0) {
        setUserClinics($savedDoctorId, $memberships, $primaryClinic ? (int) $primaryClinic : null);
    }
    return $savedDoctorId;
}

function deleteDoctor($id) {
    // Delete the user (cascade will handle foreign keys if set)
    $stmt = db()->prepare('DELETE FROM users WHERE id = ? AND role = "doctor"');
    $stmt->execute([(int) $id]);
}

function ensureEntityCommentsTable($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'entity_comments'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS entity_comments (
            id INT NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_entity_comments_entity (entity_type, entity_id),
            KEY idx_entity_comments_user (user_id),
            CONSTRAINT fk_entity_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

function ensureNotificationsTable($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            case_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notifications_user (user_id, is_read),
            KEY idx_notifications_case (case_id),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/** کاربران مرتبط با کیس: پزشک، طراح، لابراتوار + مدیران و تکنسین‌های شعبهٔ کیس. */
function caseParticipantUserIds(array $case): array {
    $ids = [];
    foreach (['doctor_id', 'designer_id', 'lab_id'] as $k) {
        if (!empty($case[$k])) $ids[] = (int) $case[$k];
    }
    if (!empty($case['branch_id'])) {
        $st = db()->prepare("SELECT id FROM users WHERE active = 1 AND branch_id = ? AND role IN ('branch_admin','technician')");
        $st->execute([(int) $case['branch_id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[] = (int) $v;
    }
    return array_values(array_unique(array_filter($ids)));
}

/**
 * اعلان تغییر وضعیت به یک وضعیتِ «خارج از ترتیب» (sort_order > 30).
 * وضعیت‌های عادی اعلان تولید نمی‌کنند تا نوتیفیکیشن‌ها زیاد نشوند.
 */
function notifyCaseStatusChangeParticipants(int $caseId, int $authorUserId, int $newStatusId, ?int $oldStatusId = null): void {
    $st = db()->prepare('SELECT id, name, sort_order, is_abnormal FROM case_statuses WHERE id = ? LIMIT 1');
    $st->execute([$newStatusId]);
    $new = $st->fetch();
    if (!$new) return;
    $newAbnormal = ((int) $new['sort_order'] > 30) || !empty($new['is_abnormal']);
    if (!$newAbnormal) return; // وضعیت عادی → اعلان نمی‌دهیم

    if ($oldStatusId) {
        $stOld = db()->prepare('SELECT sort_order, is_abnormal FROM case_statuses WHERE id = ? LIMIT 1');
        $stOld->execute([$oldStatusId]);
        $old = $stOld->fetch();
        if ($old && (((int) $old['sort_order'] > 30) || !empty($old['is_abnormal']))) return; // قبلاً هم غیرعادی بود → تکرار نکن
    }

    $stc = db()->prepare('SELECT id, patient_name, doctor_id, designer_id, lab_id, branch_id FROM cases WHERE id = ? LIMIT 1');
    $stc->execute([$caseId]);
    $case = $stc->fetch();
    if (!$case) return;

    $recipients = caseParticipantUserIds($case);
    if (empty($recipients)) return;

    $caseTitle = !empty($case['patient_name']) ? trim((string) $case['patient_name']) : 'کیس #' . $case['id'];
    foreach ($recipients as $rid) {
        if ((int) $rid === (int) $authorUserId) continue;
        createNotification(
            (int) $rid,
            'تغییر وضعیت غیرعادی در ' . $caseTitle,
            'وضعیت این کیس به «' . $new['name'] . '» تغییر کرد.',
            $caseId,
            'status'
        );
    }
}

function notifyCaseCommentParticipants(int $caseId, int $authorUserId, string $commentMessage): void {
    $stmt = db()->prepare('SELECT c.id, c.patient_name, c.doctor_id, c.designer_id, c.lab_id, c.branch_id FROM cases c WHERE c.id = ? LIMIT 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case) {
        return;
    }

    $recipientIds = caseParticipantUserIds($case);
    if (empty($recipientIds)) {
        return;
    }

    $authorStmt = db()->prepare('SELECT full_name, role FROM users WHERE id = ? LIMIT 1');
    $authorStmt->execute([$authorUserId]);
    $authorUser = $authorStmt->fetch();
    $authorName = !empty($authorUser['full_name']) ? trim((string) $authorUser['full_name']) : 'کاربر';
    $authorRole = (string) ($authorUser['role'] ?? '');

    $preview = trim((string) $commentMessage);
    if (mb_strlen($preview) > 80) {
        $preview = mb_substr($preview, 0, 80) . '...';
    }

    $caseTitle = !empty($case['patient_name']) ? trim((string) $case['patient_name']) : 'کیس #' . $case['id'];

    foreach ($recipientIds as $recipientId) {
        if ((int) $recipientId === (int) $authorUserId) {
            continue;
        }
        // پزشک/کلینیک نباید نام طراح یا لابراتوار را در اعلان‌ها ببیند → «طراح کیس» / «لابراتوار».
        $shownName = caseVisibleAuthorName($authorName, $authorRole, (int) $recipientId);
        createNotification(
            (int) $recipientId,
            'کامنت جدید در ' . $caseTitle,
            $shownName . ' یک پیام جدید در این کیس ثبت کرد: ' . $preview,
            $caseId,
            'comment'
        );
    }
}

/**
 * نامِ نمایشیِ «نویسندهٔ» یک رخداد (کامنت/آپلود فایل) از دیدِ گیرندهٔ اعلان.
 * پزشک/کلینیک نباید بداند کیس را چه کسی (طراح/لابراتوار) انجام می‌دهد؛ به‌جای نام،
 * برچسبِ نقش نشان داده می‌شود: «طراح کیس» یا «لابراتوار».
 * کاربران داخلی (مدیر/کارمند/تکنسین) نام واقعی را می‌بینند.
 */
function caseVisibleAuthorName(string $authorName, string $authorRole, int $recipientId): string {
    if ($recipientId <= 0) return $authorName;

    static $recipientRoles = [];
    if (!isset($recipientRoles[$recipientId])) {
        $st = db()->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $st->execute([$recipientId]);
        $recipientRoles[$recipientId] = (string) ($st->fetchColumn() ?: '');
    }
    $recipientRole = $recipientRoles[$recipientId];

    // گیرندهٔ داخلی است → نام واقعی
    $internalRoles = ['admin', 'branch_admin', 'staff', 'secretary', 'technician', 'designer', 'operator', 'powder', 'courier', 'finance'];
    if (in_array($recipientRole, $internalRoles, true)) {
        return $authorName;
    }

    // گیرندهٔ بیرونی (پزشک/کلینیک/لابراتوار) → نقشِ نویسنده را برچسب بزن
    if ($authorRole === 'designer') return 'طراح کیس';
    if (in_array($authorRole, ['lab', 'outsource_lab', 'customer_lab', 'partner_lab'], true)) return 'لابراتوار';
    if ($authorRole === 'clinic') return 'کلینیک';
    if ($authorRole === 'doctor') return 'پزشک';
    if (in_array($authorRole, $internalRoles, true)) return 'کارشناس لابراتوار';
    return $authorName;
}

/**
 * اعلان آپلود فایل جدید به کاربران مرتبط با کیس: پزشک، طراح، لابراتوار و مدیر(های) شعبهٔ کیس.
 */
function notifyCaseFileParticipants(int $caseId, int $authorUserId, int $fileCount = 1): void {
    $stmt = db()->prepare('SELECT c.id, c.patient_name, c.doctor_id, c.designer_id, c.lab_id, c.branch_id FROM cases c WHERE c.id = ? LIMIT 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case) return;

    $recipientIds = caseParticipantUserIds($case);
    if (empty($recipientIds)) return;

    $authorStmt = db()->prepare('SELECT full_name, role FROM users WHERE id = ? LIMIT 1');
    $authorStmt->execute([$authorUserId]);
    $authorUser = $authorStmt->fetch();
    $authorName = !empty($authorUser['full_name']) ? trim((string) $authorUser['full_name']) : 'کاربر';
    $authorRole = (string) ($authorUser['role'] ?? '');

    $caseTitle = !empty($case['patient_name']) ? trim((string) $case['patient_name']) : 'کیس #' . $case['id'];

    foreach ($recipientIds as $rid) {
        if ((int) $rid === (int) $authorUserId) continue;
        // پزشک/کلینیک نباید نام طراح/لابراتوار را ببیند (مثل اعلان کامنت‌ها)
        $shownName = caseVisibleAuthorName($authorName, $authorRole, (int) $rid);
        createNotification(
            (int) $rid,
            'فایل جدید در ' . $caseTitle,
            $shownName . ' ' . ($fileCount > 1 ? $fileCount . ' فایل جدید' : 'یک فایل جدید') . ' برای این کیس آپلود کرد.',
            $caseId,
            'file'
        );
    }
}

function saveEntityComment($entityType, $entityId, $userId, $message) {
    $message = trim((string) $message);
    if ($message === '') {
        return null;
    }
    $stmt = db()->prepare('INSERT INTO entity_comments (entity_type, entity_id, user_id, message, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$entityType, (int) $entityId, (int) $userId, $message]);
    $commentId = (int) db()->lastInsertId();

    if ($entityType === 'case') {
        notifyCaseCommentParticipants((int) $entityId, (int) $userId, $message);
    }

    return $commentId;
}

function getEntityComments($entityType, $entityId) {
    $stmt = db()->prepare('SELECT c.*, u.full_name AS user_name, u.role AS user_role FROM entity_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.entity_type = ? AND c.entity_id = ? ORDER BY c.created_at ASC');
    $stmt->execute([$entityType, (int) $entityId]);
    return $stmt->fetchAll();
}

function deleteEntityComment($commentId, $userId, $isAdmin = false) {
    if ($isAdmin) {
        $stmt = db()->prepare('DELETE FROM entity_comments WHERE id = ?');
        $stmt->execute([(int) $commentId]);
        return true;
    }

    $stmt = db()->prepare('DELETE FROM entity_comments WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $commentId, (int) $userId]);
    return $stmt->rowCount() > 0;
}

// ----- Case statuses (ordered) -----
/** همهٔ وضعیت‌ها به ترتیبِ تعیین‌شده (sort_order سپس id). در همهٔ selectها از این استفاده کنید. */
function getAllCaseStatuses(): array {
    return db()->query('SELECT * FROM case_statuses ORDER BY sort_order ASC, id ASC')->fetchAll();
}

// ----- Comment likes -----
function getCommentLikeData(int $commentId, ?int $userId = null): array {
    $st = db()->prepare('SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?');
    $st->execute([$commentId]);
    $count = (int) $st->fetchColumn();
    $liked = false;
    if ($userId) {
        $st2 = db()->prepare('SELECT 1 FROM comment_likes WHERE comment_id = ? AND user_id = ?');
        $st2->execute([$commentId, (int) $userId]);
        $liked = (bool) $st2->fetchColumn();
    }
    $st3 = db()->prepare('SELECT u.full_name FROM comment_likes cl JOIN users u ON u.id = cl.user_id WHERE cl.comment_id = ? ORDER BY cl.id');
    $st3->execute([$commentId]);
    $names = array_map('strval', $st3->fetchAll(PDO::FETCH_COLUMN));
    return ['count' => $count, 'liked' => $liked, 'names' => $names];
}

/** لایک/برداشتن‌لایک یک کامنت؛ وضعیت جدید را برمی‌گرداند. */
function toggleCommentLike(int $commentId, int $userId): array {
    $st = db()->prepare('SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?');
    $st->execute([$commentId, (int) $userId]);
    $existing = $st->fetchColumn();
    if ($existing) {
        db()->prepare('DELETE FROM comment_likes WHERE id = ?')->execute([(int) $existing]);
    } else {
        db()->prepare('INSERT INTO comment_likes (comment_id, user_id, created_at) VALUES (?, ?, NOW())')->execute([$commentId, (int) $userId]);
    }
    return getCommentLikeData($commentId, (int) $userId);
}

/**
 * خلاصهٔ «تعداد خدمات به تفکیک» برای فاکتورها.
 * آ یتم‌های تخفیف (مبلغ منفی) در این خلاصه نمایش داده نمی‌شوند.
 */
function invoiceServiceSummaryHtml(array $items, string $titleLabel = 'خدمت', string $qtyLabel = 'تعداد'): string {
    $services = [];
    foreach ($items as $item) {
        $amount = (float) ($item['total_amount'] ?? 0);
        $qty = (int) ($item['quantity'] ?? 0);
        if ($amount < 0) {
            continue; // تخفیف‌ها در خلاصه نمایش داده نمی‌شوند
        }
        $svc = $item['price_title'] ?? ($item['service_title'] ?? ($item['item_title'] ?? '—'));
        if ($svc === '' || $svc === null) $svc = '—';
        $svc = (string) $svc;
        if (!isset($services[$svc])) $services[$svc] = 0;
        $services[$svc] += $qty;
    }
    if (empty($services)) return '';
    arsort($services);
    $html = '<div class="section"><div class="section-title">تعداد خدمات به تفکیک</div><table>'
        . '<thead><tr><th>' . htmlspecialchars($titleLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>'
        . '<th>' . htmlspecialchars($qtyLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th></tr></thead><tbody>';
    foreach ($services as $title => $qty) {
        $html .= '<tr><td>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
            . '<td>' . toPersianDigits(number_format($qty, 0)) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

// ----- Authentication helpers -----
function getUserByLogin($identifier) {
    $stmt = db()->prepare('SELECT * FROM users WHERE (username = ? OR email = ? OR phone = ?) AND active = 1 LIMIT 1');
    $stmt->execute([$identifier, $identifier, $identifier]);
    return $stmt->fetch();
}

// Legacy alias for doctors login (still works)
function getDoctorByLogin($identifier) {
    return getUserByLogin($identifier);
}

function updateDoctorLastLogin($id) {
    $stmt = db()->prepare('UPDATE users SET last_login = ? WHERE id = ?');
    $stmt->execute([date('Y-m-d H:i:s'), (int) $id]);
}

function setDoctorPassword($id, $plainPassword) {
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = "doctor"');
    $stmt->execute([$hash, (int) $id]);
}

// ----- Scoped data access (using users.id as doctor_id) -----
function getCaseForDoctor($caseId, $doctorId) {
    $stmt = db()->prepare('SELECT c.*, u.full_name AS doctor_name, p.title AS service_title, cs.name AS status_name,
            di.invoice_number, di.id AS invoice_id
        FROM cases c
        LEFT JOIN users u ON c.doctor_id = u.id
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN case_statuses cs ON c.status_id = cs.id
        LEFT JOIN doctor_invoices di ON c.invoice_id = di.id
        WHERE c.id = ? AND c.doctor_id = ?');
    $stmt->execute([(int) $caseId, (int) $doctorId]);
    return $stmt->fetch();
}

function getCaseFiles($caseId) {
    $stmt = db()->prepare('SELECT * FROM case_files WHERE case_id = ? ORDER BY id ASC');
    $stmt->execute([(int) $caseId]);
    return $stmt->fetchAll();
}

function getInvoicesForDoctor($doctorId) {
    $stmt = db()->prepare('SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
        FROM doctor_invoices i
        LEFT JOIN users u ON i.doctor_id = u.id
        WHERE i.doctor_id = ?
        ORDER BY i.invoice_date DESC, i.id DESC');
    $stmt->execute([(int) $doctorId]);
    return $stmt->fetchAll();
}

function getInvoiceForDoctor($invoiceId, $doctorId) {
    $stmt = db()->prepare('SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
        FROM doctor_invoices i
        LEFT JOIN users u ON i.doctor_id = u.id
        WHERE i.id = ? AND i.doctor_id = ?');
    $stmt->execute([(int) $invoiceId, (int) $doctorId]);
    return $stmt->fetch();
}

function getPaymentsForDoctor($doctorId) {
    $stmt = db()->prepare('SELECT p.*, b.bank_name, b.account_owner_name,
            COALESCE(u.full_name, p.doctor_name) AS doctor_name,
            GROUP_CONCAT(DISTINCT i.invoice_number SEPARATOR ", ") AS linked_invoices
        FROM doctor_payments p
        LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
        LEFT JOIN users u ON p.doctor_id = u.id
        LEFT JOIN doctor_payment_invoices pi ON pi.payment_id = p.id
        LEFT JOIN doctor_invoices i ON i.id = pi.invoice_id
        WHERE p.doctor_id = ?
        GROUP BY p.id
        ORDER BY p.payment_date DESC, p.id DESC');
    $stmt->execute([(int) $doctorId]);
    return $stmt->fetchAll();
}

// ----- "Own" invoice/payment helpers for external parties (designer / lab) -----
// These let a designer / lab see ONLY their own invoices and the payments made
// to them, without any edit/delete capability (edit stays admin-only).

/** Designer invoices visible to a designer (their own). */
function getInvoicesForDesigner(int $designerId): array {
    $stmt = db()->prepare('SELECT i.*, u.full_name AS designer_name
        FROM designer_invoices i
        LEFT JOIN users u ON i.designer_id = u.id
        WHERE i.designer_id = ?
        ORDER BY i.invoice_date DESC, i.id DESC');
    $stmt->execute([$designerId]);
    return $stmt->fetchAll();
}

/** A designer may only fetch one of their own invoices. */
function getDesignerInvoiceForUser(int $id, int $designerId): ?array {
    $stmt = db()->prepare('SELECT i.*, u.full_name AS designer_name FROM designer_invoices i LEFT JOIN users u ON i.designer_id = u.id WHERE i.id = ? AND i.designer_id = ?');
    $stmt->execute([$id, $designerId]);
    return $stmt->fetch() ?: null;
}

/** Outsource invoices visible to a lab (their own). */
function getInvoicesForLab(int $labId): array {
    $stmt = db()->prepare('SELECT i.*, u.full_name AS lab_name
        FROM outsource_invoices i
        LEFT JOIN users u ON i.lab_id = u.id
        WHERE i.lab_id = ?
        ORDER BY i.invoice_date DESC, i.id DESC');
    $stmt->execute([$labId]);
    return $stmt->fetchAll();
}

/** A lab may only fetch one of their own outsource invoices. */
function getOutsourceInvoiceForUser(int $id, int $labId): ?array {
    $stmt = db()->prepare('SELECT i.*, u.full_name AS lab_name FROM outsource_invoices i LEFT JOIN users u ON i.lab_id = u.id WHERE i.id = ? AND i.lab_id = ?');
    $stmt->execute([$id, $labId]);
    return $stmt->fetch() ?: null;
}

/** Payments we made to a specific party (designer or lab) — for their "پرداخت‌های من". */
function getExpensePaymentsForParty(string $expenseType, int $userId): array {
    if ($expenseType === 'designer') {
        $stmt = db()->prepare('SELECT p.*, di.designer_name AS party_name, di.invoice_number AS invoice_number
            FROM expense_payments p
            JOIN (SELECT di.id, di.invoice_number, di.designer_id, u.full_name AS designer_name
                  FROM designer_invoices di LEFT JOIN users u ON di.designer_id = u.id) di
              ON p.invoice_id = di.id AND p.expense_type = "designer"
            WHERE di.designer_id = ?
            ORDER BY p.payment_date DESC, p.id DESC');
    } else {
        $stmt = db()->prepare('SELECT p.*, oi.lab_name AS party_name, oi.invoice_number AS invoice_number
            FROM expense_payments p
            JOIN (SELECT oi.id, oi.invoice_number, oi.lab_id, u.full_name AS lab_name
                  FROM outsource_invoices oi LEFT JOIN users u ON oi.lab_id = u.id) oi
              ON p.invoice_id = oi.id AND p.expense_type = "outsource"
            WHERE oi.lab_id = ?
            ORDER BY p.payment_date DESC, p.id DESC');
    }
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// ----- Invoice and payment functions (global) -----
function getAllInvoices() {
    $stmt = db()->query('SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
        FROM doctor_invoices i
        LEFT JOIN users u ON i.doctor_id = u.id
        ORDER BY invoice_date DESC, id DESC');
    return $stmt->fetchAll();
}

function getInvoice($id) {
    $stmt = db()->prepare('SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name,
            COALESCE(u.phone, i.doctor_phone) AS doctor_phone,
            COALESCE(u.email, i.doctor_email) AS doctor_email,
            u.id AS doctor_id,
            b.account_owner_name AS bank_owner, b.bank_name, b.account_number, b.card_number, b.iban_sheba
        FROM doctor_invoices i
        LEFT JOIN users u ON i.doctor_id = u.id
        LEFT JOIN bank_accounts b ON i.bank_account_id = b.id
        WHERE i.id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function getInvoiceItems($invoice_id) {
    $stmt = db()->prepare('SELECT ii.*, p.title AS price_title, c.received_date AS case_received_date,
                  c.case_type, u.full_name AS case_doctor_name
        FROM invoice_items ii
        LEFT JOIN site_prices p ON ii.price_id = p.id
        LEFT JOIN cases c ON ii.case_id = c.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC');
    $stmt->execute([(int) $invoice_id]);
    $items = $stmt->fetchAll();
    // Round amounts to integers (Toman has no decimals)
    foreach ($items as &$item) {
        $item['unit_price'] = round((float) $item['unit_price']);
        $item['total_amount'] = round((float) $item['total_amount']);
    }
    return $items;
}

function saveInvoice($data) {
    $now = date('Y-m-d H:i:s');
    $items = [];
    foreach ($data['items'] ?? [] as $item) {
        $itemDescription = trim($item['item_description'] ?? '');
        $itemTitle = trim($item['item_title'] ?? '');
        if ($itemTitle === '' && $itemDescription !== '') $itemTitle = $itemDescription;
        if ($itemTitle === '') continue;
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        // The "جمع" (total) column is editable – use the submitted total when present.
        $submittedTotal = $item['total_amount'] ?? null;
        $total = ($submittedTotal !== null && $submittedTotal !== '')
            ? (float) $submittedTotal
            : round($quantity * $unitPrice);
        // ─── تخفیف همیشه منفی ذخیره می‌شود ───
        // ردیفِ تخفیف case_id ندارد. مرورگر مبلغ را مثبت نشان می‌دهد و ردیف را منفی
        // حساب می‌کند؛ اگر مرورگر (نسخهٔ قدیمی/کش‌شدهٔ JS) علامت را اعمال نکند، این‌جا
        // اصلاح می‌شود تا تخفیف هیچ‌وقت «جمع» نشود. تشخیص: علامتِ ورودی یا عنوانِ «تخفیف».
        $looksDiscount = (bool) preg_match('/تخفیف/u', $itemTitle . ' ' . $itemDescription);
        if (empty($item['case_id']) && ($total < 0 || $unitPrice < 0 || $looksDiscount)) {
            $unitPrice = abs($unitPrice);
            $total = -abs($total > 0 ? $total : ($quantity * $unitPrice));
        }
        $items[] = [
            'price_id' => !empty($item['price_id']) ? (int) $item['price_id'] : null,
            'case_id' => !empty($item['case_id']) ? (int) $item['case_id'] : null,
            'item_title' => $itemTitle,
            'item_description' => $itemDescription ?: null,
            'patient_name' => trim($item['patient_name'] ?? '') ?: null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => round($total),
        ];
    }
    $totalAmount = round(array_sum(array_column($items, 'total_amount')));

    $bankAccountId = !empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;

    if (isset($data['id']) && !empty($data['id'])) {
        // ⚠️ branch_id باید در ویرایش هم نوشته شود. قبلاً فقط در INSERT ست می‌شد و اگر
        // فاکتور بدون شعبه بود (پزشک بدونbranch) یا پزشکش عوض می‌شد، فاکتور با فیلتر
        // شعبهٔ لیست فاکتورها از چشم می‌افتاد (باگ گزارش‌شده روی هاست: فاکتور #40).
        $editBranchId = resolveInvoiceBranchId($data['doctor_id'] ?? null);
        $stmt = db()->prepare('UPDATE doctor_invoices SET invoice_number = ?, doctor_id = ?, doctor_name = ?, doctor_phone = ?, doctor_email = ?, total_amount = ?, payment_status = ?, invoice_date = ?, due_date = ?, notes = ?, bank_account_id = ?, branch_id = COALESCE(NULLIF(branch_id, 0), ?), updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['invoice_number'],
            $data['doctor_id'] ?: null,
            $data['doctor_name'] ?? null,
            $data['doctor_phone'] ?? null,
            $data['doctor_email'] ?? null,
            $totalAmount,
            $data['payment_status'] ?? 'unpaid',
            $data['invoice_date'],
            $data['due_date'] ?? null,
            $data['notes'] ?? null,
            $bankAccountId,
            $editBranchId,
            $now,
            (int) $data['id']
        ]);
        $invoiceId = (int) $data['id'];
    } else {
        // شعبهٔ فاکتور = شعبهٔ خودِ پزشک؛ اگر پزشک شعبه ندارد → شعبهٔ کاربرِ صادرکننده،
        // وگرنه شعبهٔ مرکزی (۱) — تا فاکتور هیچ‌وقت بدون شعبه نماند.
        $branchId = resolveInvoiceBranchId($data['doctor_id'] ?? null);
        $stmt = db()->prepare('INSERT INTO doctor_invoices (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, due_date, notes, bank_account_id, branch_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['invoice_number'],
            $data['doctor_id'] ?: null,
            $data['doctor_name'] ?? null,
            $data['doctor_phone'] ?? null,
            $data['doctor_email'] ?? null,
            $totalAmount,
            $data['payment_status'] ?? 'unpaid',
            $data['invoice_date'],
            $data['due_date'] ?? null,
            $data['notes'] ?? null,
            $bankAccountId,
            $branchId,
            $now
        ]);
        $invoiceId = db()->lastInsertId();
    }

    // Delete old items and insert new ones (with case_id support)
    $del = db()->prepare('DELETE FROM invoice_items WHERE invoice_id = ?');
    $del->execute([$invoiceId]);
    $ins = db()->prepare('INSERT INTO invoice_items (invoice_id, price_id, case_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $item) {
        $ins->execute([
            $invoiceId,
            $item['price_id'],
            $item['case_id'],
            $item['item_title'],
            $item['item_description'],
            $item['patient_name'],
            $item['quantity'],
            $item['unit_price'],
            $item['total_amount'],
            $now
        ]);
    }
    return $invoiceId;
}

function deleteInvoice($id) {
    // Clear invoice_id from cases
    $stmt = db()->prepare('UPDATE cases SET invoice_id = NULL WHERE invoice_id = ?');
    $stmt->execute([(int) $id]);

    // Delete the invoice
    $stmt = db()->prepare('DELETE FROM doctor_invoices WHERE id = ?');
    $stmt->execute([(int) $id]);
}

// ----- Payment functions -----
function getAllPayments() {
    $bid = currentBranchId();
    $params = [];
    $filter = '';
    if ($bid !== null) {
        $granted = accessibleDoctorIds();
        $filter = ' WHERE (p.branch_id = ' . (int) $bid;
        if (!empty($granted)) {
            $ph = implode(',', array_fill(0, count($granted), '?'));
            $filter .= " OR p.doctor_id IN ({$ph})";
            $params = array_merge($params, array_map('intval', $granted));
        }
        $filter .= ')';
    }
    $stmt = db()->prepare('SELECT p.*, b.bank_name, b.account_owner_name,
            COALESCE(u.full_name, p.doctor_name) AS doctor_name,
            GROUP_CONCAT(DISTINCT i.invoice_number SEPARATOR ", ") AS linked_invoices
        FROM doctor_payments p
        LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
        LEFT JOIN users u ON p.doctor_id = u.id
        LEFT JOIN doctor_payment_invoices pi ON pi.payment_id = p.id
        LEFT JOIN doctor_invoices i ON i.id = pi.invoice_id' . $filter . "
        GROUP BY p.id
        ORDER BY p.payment_date DESC, p.id DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getPayment($id) {
    $stmt = db()->prepare('SELECT p.*, b.bank_name, b.account_owner_name,
            COALESCE(u.full_name, p.doctor_name) AS doctor_name
        FROM doctor_payments p
        LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
        LEFT JOIN users u ON p.doctor_id = u.id
        WHERE p.id = ?');
    $stmt->execute([(int) $id]);
    $payment = $stmt->fetch();
    if ($payment) {
        $payment['invoice_ids'] = getPaymentInvoiceIds($payment['id']);
    }
    return $payment;
}

function getPaymentInvoiceIds($payment_id) {
    $stmt = db()->prepare('SELECT invoice_id FROM doctor_payment_invoices WHERE payment_id = ?');
    $stmt->execute([(int) $payment_id]);
    return array_map('current', $stmt->fetchAll(PDO::FETCH_NUM));
}

function refreshInvoicePaymentStatuses(array $invoiceIds): void {
    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    if (empty($invoiceIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $stmt = db()->prepare("SELECT invoice_id, SUM(amount_applied) AS applied_amount
        FROM doctor_payment_invoices
        WHERE invoice_id IN ($placeholders)
        GROUP BY invoice_id");
    $stmt->execute($invoiceIds);

    $appliedByInvoice = [];
    while ($row = $stmt->fetch()) {
        $appliedByInvoice[(int) $row['invoice_id']] = (float) $row['applied_amount'];
    }

    $invoiceStmt = db()->prepare("SELECT id, total_amount FROM doctor_invoices WHERE id IN ($placeholders)");
    $invoiceStmt->execute($invoiceIds);
    while ($invoice = $invoiceStmt->fetch()) {
        $invoiceId = (int) $invoice['id'];
        $totalAmount = (float) ($invoice['total_amount'] ?? 0);
        $appliedAmount = $appliedByInvoice[$invoiceId] ?? 0.0;
        $paymentStatus = ($appliedAmount >= $totalAmount && $totalAmount > 0) ? 'paid' : 'unpaid';
        $updateStmt = db()->prepare('UPDATE doctor_invoices SET payment_status = ? WHERE id = ?');
        $updateStmt->execute([$paymentStatus, $invoiceId]);
    }
}

function savePayment($data) {
    $now = date('Y-m-d H:i:s');
    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $data['invoice_ids'] ?? []))));

    if (isset($data['id']) && !empty($data['id'])) {
        $existingInvoiceIds = getPaymentInvoiceIds((int) $data['id']);
        $stmt = db()->prepare('UPDATE doctor_payments SET doctor_id = ?, doctor_name = ?, amount = ?, payment_method = ?, payment_date = ?, transaction_number = ?, bank_account_id = ?, notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['doctor_id'] ?: null,
            $data['doctor_name'] ?? '',
            $data['amount'],
            $data['payment_method'],
            $data['payment_date'],
            $data['transaction_number'] ?? null,
            $data['bank_account_id'] ?? null,
            $data['notes'] ?? null,
            $now,
            (int) $data['id']
        ]);
        $paymentId = (int) $data['id'];
    } else {
        $branchId = currentBranchId() ?? 1;
        $stmt = db()->prepare('INSERT INTO doctor_payments (doctor_id, doctor_name, amount, payment_method, payment_date, transaction_number, bank_account_id, notes, branch_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['doctor_id'] ?: null,
            $data['doctor_name'] ?? '',
            $data['amount'],
            $data['payment_method'],
            $data['payment_date'],
            $data['transaction_number'] ?? null,
            $data['bank_account_id'] ?? null,
            $data['notes'] ?? null,
            $branchId,
            $now
        ]);
        $paymentId = db()->lastInsertId();
        $existingInvoiceIds = [];
    }

    // Update payment-invoice links
    $del = db()->prepare('DELETE FROM doctor_payment_invoices WHERE payment_id = ?');
    $del->execute([$paymentId]);
    $ins = db()->prepare('INSERT INTO doctor_payment_invoices (payment_id, invoice_id, amount_applied) VALUES (?, ?, ?)');
    foreach ($invoiceIds as $invoiceId) {
        $ins->execute([$paymentId, (int) $invoiceId, $data['amount_applied'] ?? $data['amount']]);
    }

    $affectedInvoiceIds = array_values(array_unique(array_merge($existingInvoiceIds, $invoiceIds)));
    refreshInvoicePaymentStatuses($affectedInvoiceIds);
    return $paymentId;
}

function deletePayment($id) {
    $invoiceIds = getPaymentInvoiceIds((int) $id);
    $stmt = db()->prepare('DELETE FROM doctor_payments WHERE id = ?');
    $stmt->execute([(int) $id]);
    refreshInvoicePaymentStatuses($invoiceIds);
}

function getAllInvoiceLinks($invoice_id) {
    $stmt = db()->prepare('SELECT p.* FROM doctor_payments p JOIN doctor_payment_invoices pi ON pi.payment_id = p.id WHERE pi.invoice_id = ?');
    $stmt->execute([(int) $invoice_id]);
    return $stmt->fetchAll();
}

// ----- Bank Account functions (unchanged) -----
function getAllBankAccounts() {
    $bid = currentBranchId();
    if ($bid === null) {
        $stmt = db()->query('SELECT * FROM bank_accounts ORDER BY is_active DESC, id DESC');
        return $stmt->fetchAll();
    }
    $stmt = db()->prepare('SELECT * FROM bank_accounts WHERE branch_id = ? OR branch_id IS NULL ORDER BY is_active DESC, id DESC');
    $stmt->execute([$bid]);
    return $stmt->fetchAll();
}

function getBankAccount($id) {
    $stmt = db()->prepare('SELECT * FROM bank_accounts WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function saveBankAccount($data) {
    $now = date('Y-m-d H:i:s');
    if (isset($data['id']) && !empty($data['id'])) {
        $stmt = db()->prepare('UPDATE bank_accounts SET account_owner_name = ?, bank_name = ?, account_number = ?, card_number = ?, iban_sheba = ?, is_active = ?, notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['account_owner_name'],
            $data['bank_name'],
            $data['account_number'] ?: null,
            $data['card_number'] ?: null,
            $data['iban_sheba'] ?: null,
            $data['is_active'] ?? 1,
            $data['notes'] ?? null,
            $now,
            (int) $data['id']
        ]);
        return (int) $data['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO bank_accounts (branch_id, account_owner_name, bank_name, account_number, card_number, iban_sheba, is_active, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            currentBranchId() ?? 1,
            $data['account_owner_name'],
            $data['bank_name'],
            $data['account_number'] ?: null,
            $data['card_number'] ?: null,
            $data['iban_sheba'] ?: null,
            $data['is_active'] ?? 1,
            $data['notes'] ?? null,
            $now
        ]);
        return db()->lastInsertId();
    }
}

function deleteBankAccount($id) {
    $stmt = db()->prepare('DELETE FROM bank_accounts WHERE id = ?');
    $stmt->execute([(int) $id]);
}

function calculateDoctorDebt($doctor_id) {
    $stmt = db()->prepare("SELECT COALESCE(SUM(i.total_amount), 0) - COALESCE(SUM(p.amount), 0) as debt
        FROM doctor_invoices i
        LEFT JOIN doctor_payments p ON p.doctor_id = i.doctor_id
        WHERE i.doctor_id = ?");
    $stmt->execute([(int) $doctor_id]);
    return $stmt->fetchColumn();
}
// =====================================================
// Doctor Price Overrides
// =====================================================

/**
 * Get a single override for a doctor and service.
 * @return array|null
 */
function getParentClinicUserId($doctor_id) {
    $doctor_id = (int) $doctor_id;
    $stmt = db()->prepare('SELECT clinic_id FROM users WHERE id = ? AND role IN ("doctor", "clinic") LIMIT 1');
    $stmt->execute([$doctor_id]);
    $row = $stmt->fetch();
    if ($row && !empty($row['clinic_id'])) return (int) $row['clinic_id'];
    // اگر «کلینیک اصلی» خالی بود، نخستین عضویتِ چندگانه (user_clinics) استفاده می‌شود.
    try {
        $st = db()->prepare('SELECT clinic_id FROM user_clinics WHERE user_id = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$doctor_id]);
        $cid = (int) $st->fetchColumn();
        return $cid > 0 ? $cid : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function getDoctorPriceOverride($doctor_id, $service_id) {
    $bid = currentBranchId();
    $branchClause = $bid === null ? '' : ' AND (branch_id = ? OR branch_id IS NULL)';
    if ($bid === null) {
        $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?');
        $stmt->execute([(int)$doctor_id, 'service', (int)$service_id]);
    } else {
        $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?' . $branchClause);
        $stmt->execute([(int)$doctor_id, 'service', (int)$service_id, $bid]);
    }
    $override = $stmt->fetch();
    if ($override) {
        return $override;
    }

    $parentClinicId = getParentClinicUserId((int) $doctor_id);
    if ($parentClinicId) {
        if ($bid === null) {
            $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?');
            $stmt->execute([$parentClinicId, 'service', (int)$service_id]);
        } else {
            $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?' . $branchClause);
            $stmt->execute([$parentClinicId, 'service', (int)$service_id, $bid]);
        }
        return $stmt->fetch();
    }

    return null;
}

function getDesignerDesignFeeOverride($designer_id, $service_id = null) {
    $designer_id = (int) $designer_id;
    $bid = currentBranchId();
    $branchClause = $bid === null ? '' : ' AND (branch_id = ? OR branch_id IS NULL)';
    $run = function (string $sql, array $params) use ($branchClause, $bid) {
        $stmt = db()->prepare($sql . $branchClause);
        if ($bid !== null) $params[] = $bid;
        $stmt->execute($params);
        return $stmt->fetch();
    };
    if ($service_id !== null && (int) $service_id > 0) {
        // 1) designer + specific service (per-type design fee)
        $override = $run('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?', [$designer_id, 'design_fee', (int) $service_id]);
        if ($override) {
            return $override;
        }
    }
    // 2) fallback to general per-designer design fee
    return $run('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id IS NULL', [$designer_id, 'design_fee']);
}

/** Per-unit design fee (تومان) for a designer and optionally a specific service. */
function getApplicableDesignFee($designer_id, $service_id = null) {
    // Phase 3: price_links (design_fee) is authoritative first when enabled.
    if (defined('USE_PRICE_LINKS') && USE_PRICE_LINKS) {
        $did = (int) $designer_id;
        $svc = $service_id !== null ? (int) $service_id : 0;
        $pl = function (string $sql, array $params) {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $v = $stmt->fetchColumn();
            return ($v !== false && $v !== null) ? (float) $v : null;
        };
        // 1) designer + specific service
        if ($svc > 0) {
            $v = $pl("SELECT price FROM price_links WHERE active = 1 AND price_type = 'design_fee' AND provider_type IN ('designer','doctor') AND provider_id = ? AND service_id = ? ORDER BY id DESC LIMIT 1", [$did, $svc]);
            if ($v !== null) return $v;
        }
        // 2) fallback to general per-designer design fee
        $v = $pl("SELECT price FROM price_links WHERE active = 1 AND price_type = 'design_fee' AND provider_type IN ('designer','doctor') AND provider_id = ? AND service_id IS NULL ORDER BY id DESC LIMIT 1", [$did]);
        if ($v !== null) return $v;
    }

    $override = getDesignerDesignFeeOverride((int) $designer_id, $service_id);
    return $override ? (float) $override['custom_price'] : null;
}

// =====================================================
// Designer (freelance) Invoice Helpers
// =====================================================

/**
 * Uninvoiced cases done by a designer within a date range.
 * Each case's per-unit design fee is resolved from the designer's override.
 * When $payerBranch is given, only cases whose DESIGN is paid by that branch are
 * returned (پرداخت‌کننده = شعبه‌ی لابراتوار انجام‌دهنده، وگرنه صاحب کیس). این یعنی یک
 * شعبه فقط می‌تواند طراحیِ کیس‌هایی را فاکتور کند که خودش باید بپردازد؛ کیس‌هایِ لابراتوار
 * مرکزی (که مرکزی می‌پردازد) در فهرستِ شعبه‌های دیگر نمی‌آیند.
 */
function getUninvoicedCasesForDesigner(int $designerId, string $startDate, string $endDate, ?int $payerBranch = null): array {
    $payerCond = $payerBranch !== null
        ? " AND (COALESCE((SELECT lb.branch_id FROM users lb WHERE lb.id = c.lab_id), c.branch_id) = ?)"
        : '';
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.designer_id = ?
          AND c.designer_invoice_id IS NULL
          AND COALESCE(p.design_required, 1) = 1
          AND c.received_date BETWEEN ? AND ?' . $payerCond . '
        ORDER BY c.received_date ASC, c.id ASC
    ');
    $params = [$designerId, $startDate, $endDate];
    if ($payerBranch !== null) $params[] = (int) $payerBranch;
    $stmt->execute($params);
    $cases = $stmt->fetchAll();
    foreach ($cases as &$c) {
        $c['unit_design_fee'] = getApplicableDesignFee($designerId, (int) ($c['service_id'] ?? 0));
    }
    return $cases;
}

/** Create a designer invoice from a list of cases (design fee = unit fee x quantity). */
function createDesignerInvoice(int $designerId, array $cases, string $invoiceDate, ?string $periodLabel = null): int {
    $now = date('Y-m-d H:i:s');

    // Invoice number: INV-DES-YYYYMM-001 (prefix contains dashes → use the shared helper)
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $invoiceNumber = nextInvoiceNumber('designer_invoices', 'INV-DES', $yearMonth);

    $total = 0;
    $rows = [];
    foreach ($cases as $c) {
        $unitFee = getApplicableDesignFee($designerId, (int) ($c['service_id'] ?? 0));
        if ($unitFee === null) {
            $unitFee = 0.0;
        }
        $qty = (int) ($c['quantity'] ?? 1);
        $amount = round($unitFee * $qty);
        $total += $amount;
        $rows[] = [$c, $unitFee, $qty, $amount];
    }

    $notes = 'فاکتور طراحی' . ($periodLabel ? ' — بازه: ' . $periodLabel : '');
    $branchId = currentBranchId() ?? 1;
    $ins = db()->prepare('INSERT INTO designer_invoices (invoice_number, designer_id, total_amount, period_label, invoice_date, notes, branch_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$invoiceNumber, $designerId, $total, $periodLabel, $invoiceDate, $notes, $branchId, $now]);
    $invoiceId = (int) db()->lastInsertId();

    $item = db()->prepare('INSERT INTO designer_invoice_items (invoice_id, case_id, doctor_id, doctor_name, service_id, service_title, patient_name, quantity, unit_design_fee, total_amount, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as [$c, $unitFee, $qty, $amount]) {
        $item->execute([
            $invoiceId,
            $c['id'],
            $c['doctor_id'] ?? null,
            $c['doctor_name'] ?? null,
            $c['service_id'] ?? null,
            $c['service_title'] ?? null,
            $c['patient_name'] ?? null,
            $qty,
            $unitFee,
            $amount,
            $c['received_date'] ?? null,
            $now,
        ]);
    }

    // Mark cases as invoiced for the designer (separate from doctor invoice)
    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET designer_invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }

    return $invoiceId;
}

/** Get a designer invoice by id. */
function getDesignerInvoice(int $id): ?array {
    $stmt = db()->prepare('SELECT i.*, u.full_name AS designer_name FROM designer_invoices i LEFT JOIN users u ON i.designer_id = u.id WHERE i.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Get items of a designer invoice. */
function getDesignerInvoiceItems(int $invoiceId): array {
    // نامِ اختصاری خدمت و تاریخ دریافت کیس هم برگردانده می‌شوند (برای جدول‌های ویرایش/PDF).
    $stmt = db()->prepare('SELECT dii.*, p.short_name AS service_short,
                                  c.received_date AS case_received_date
                           FROM designer_invoice_items dii
                           LEFT JOIN site_prices p ON p.id = dii.service_id
                           LEFT JOIN cases c ON c.id = dii.case_id
                           WHERE dii.invoice_id = ?
                           ORDER BY dii.id ASC');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

/** All designer invoices (admin listing). */
function getAllDesignerInvoices(): array {
    $bid = currentBranchId();
    $filter = $bid === null ? '' : ' WHERE i.branch_id = ' . (int) $bid;
    $stmt = db()->query('SELECT i.*, u.full_name AS designer_name FROM designer_invoices i LEFT JOIN users u ON i.designer_id = u.id' . $filter . ' ORDER BY i.invoice_date DESC, i.id DESC');
    return $stmt->fetchAll();
}

/** Delete a designer invoice: release its cases and remove its items/payments. */
function deleteDesignerInvoice(int $id): void {
    $inv = getDesignerInvoice($id);
    if (!$inv) return;
    // release cases that were billed on this invoice so they can be re-invoiced
    db()->prepare('UPDATE cases SET designer_invoice_id = NULL WHERE designer_invoice_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM designer_invoice_items WHERE invoice_id = ?')->execute([$id]);
    // remove expense payments recorded against this designer invoice
    db()->prepare("DELETE FROM expense_payments WHERE expense_type = 'designer' AND invoice_id = ?")->execute([$id]);
    db()->prepare('DELETE FROM designer_invoices WHERE id = ?')->execute([$id]);
}

/**
 * Edit a designer invoice header + line items.
 * $rows: each = [item_id, qty, unit]; items not present in the list are removed
 * and their case is released (designer_invoice_id → NULL). Totals are recomputed.
 */
function saveDesignerInvoiceEdit(int $invoiceId, string $invoiceDate, ?string $periodLabel, ?string $notes, array $rows): void {
    $now = date('Y-m-d H:i:s');
    $invoice = getDesignerInvoice($invoiceId);
    if (!$invoice) return;

    $keepIds = [];
    $total = 0.0;
    $upd = db()->prepare('UPDATE designer_invoice_items SET quantity = ?, unit_design_fee = ?, total_amount = ? WHERE id = ? AND invoice_id = ?');
    foreach ($rows as $r) {
        $itemId = (int) ($r['item_id'] ?? 0);
        if ($itemId <= 0) continue;
        $qty = max(1, (int) ($r['qty'] ?? 1));
        $unit = max(0, (float) ($r['unit'] ?? 0));
        $amt = round($qty * $unit);
        $total += $amt;
        $upd->execute([$qty, $unit, $amt, $itemId, $invoiceId]);
        $keepIds[] = $itemId;
    }

    // drop rows that were unchecked (removed) and release their case
    $existing = db()->prepare('SELECT id, case_id FROM designer_invoice_items WHERE invoice_id = ?');
    $existing->execute([$invoiceId]);
    $removeIds = [];
    $rel = db()->prepare('UPDATE cases SET designer_invoice_id = NULL WHERE id = ? AND designer_invoice_id = ?');
    foreach ($existing->fetchAll() as $it) {
        if (in_array((int) $it['id'], $keepIds, true)) continue;
        $removeIds[] = (int) $it['id'];
        if (!empty($it['case_id'])) {
            $rel->execute([(int) $it['case_id'], $invoiceId]);
        }
    }
    if (!empty($removeIds)) {
        $ph = implode(',', array_fill(0, count($removeIds), '?'));
        db()->prepare("DELETE FROM designer_invoice_items WHERE id IN ($ph)")->execute($removeIds);
    }

    $updInv = db()->prepare('UPDATE designer_invoices SET total_amount = ?, invoice_date = ?, period_label = ?, notes = ? WHERE id = ?');
    $updInv->execute([round($total), $invoiceDate, ($periodLabel !== '' ? $periodLabel : null), ($notes !== '' ? $notes : null), $invoiceId]);
}

/**
 * کیس‌های بدون فاکتورِ یک طراح (بدون محدودیت تاریخ) — برای افزودن به فاکتور موجود.
 * $payerBranch = شعبه‌ای که هزینهٔ طراحی را می‌پردازد (برای محدودکردن به کیس‌های همان شعبه).
 */
function getUninvoicedCasesForDesignerAll(int $designerId, ?int $payerBranch = null): array {
    $payerCond = $payerBranch !== null
        ? " AND (COALESCE((SELECT lb.branch_id FROM users lb WHERE lb.id = c.lab_id), c.branch_id) = ?)"
        : '';
    $stmt = db()->prepare('
        SELECT c.id, c.patient_name, c.service_id, c.quantity, c.received_date, c.doctor_id,
               p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.designer_id = ?
          AND c.designer_invoice_id IS NULL' . $payerCond . '
        ORDER BY c.received_date DESC, c.id DESC
        LIMIT 500
    ');
    $params = [$designerId];
    if ($payerBranch !== null) $params[] = (int) $payerBranch;
    $stmt->execute($params);
    $cases = $stmt->fetchAll();
    foreach ($cases as &$c) {
        $c['unit_design_fee'] = getApplicableDesignFee($designerId, (int) ($c['service_id'] ?? 0));
    }
    return $cases;
}

/**
 * افزودن چند کیس به یک فاکتور طراحی موجود: هر کیس به‌عنوان آیتم اضافه و به فاکتور
 * نشان‌دار می‌شود و جمع کل فاکتور به‌روزرسانی می‌گردد. تعداد آیتم‌های اضافه‌شده را برمی‌گرداند.
 */
function addCasesToDesignerInvoice(int $invoiceId, array $caseIds): int {
    $inv = getDesignerInvoice($invoiceId);
    if (!$inv) return 0;
    $designerId = (int) $inv['designer_id'];

    $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds), fn($v) => $v > 0)));
    if (empty($caseIds)) return 0;

    $ph = implode(',', array_fill(0, count($caseIds), '?'));
    $st = db()->prepare("
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.id IN ($ph)
          AND c.designer_id = ?
          AND c.designer_invoice_id IS NULL
    ");
    $st->execute(array_merge($caseIds, [$designerId]));
    $cases = $st->fetchAll();
    if (empty($cases)) return 0;

    $now = date('Y-m-d H:i:s');
    $ins = db()->prepare('INSERT INTO designer_invoice_items (invoice_id, case_id, doctor_id, doctor_name, service_id, service_title, patient_name, quantity, unit_design_fee, total_amount, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $mark = db()->prepare('UPDATE cases SET designer_invoice_id = ? WHERE id = ?');

    $added = 0;
    $addedTotal = 0.0;
    foreach ($cases as $c) {
        $unitFee = getApplicableDesignFee($designerId, (int) ($c['service_id'] ?? 0));
        if ($unitFee === null) $unitFee = 0.0;
        $qty = (int) ($c['quantity'] ?? 1);
        $amt = round($unitFee * $qty);
        $ins->execute([
            $invoiceId,
            $c['id'],
            $c['doctor_id'] ?? null,
            $c['doctor_name'] ?? null,
            $c['service_id'] ?? null,
            $c['service_title'] ?? null,
            $c['patient_name'] ?? null,
            $qty,
            $unitFee,
            $amt,
            $c['received_date'] ?? null,
            $now,
        ]);
        $mark->execute([$invoiceId, $c['id']]);
        $addedTotal += $amt;
        $added++;
    }

    if ($added > 0) {
        db()->prepare('UPDATE designer_invoices SET total_amount = total_amount + ? WHERE id = ?')->execute([round($addedTotal), $invoiceId]);
    }
    return $added;
}

// =====================================================
// Outsource Invoice Edit Helpers (ویرایش فاکتور برون‌سپاری)
// =====================================================

/**
 * Edit an outsource invoice header + line items (آینهٔ saveDesignerInvoiceEdit).
 * $rows: each = [item_id, qty, unit]; items not present are removed and their
 * case is released (outsource_invoice_id → NULL). Totals are recomputed and the
 * payment status is refreshed (because the total may drop below the paid amount).
 */
function saveOutsourceInvoiceEdit(int $invoiceId, string $invoiceDate, ?string $periodLabel, ?string $notes, array $rows): void {
    $invoice = getOutsourceInvoice($invoiceId);
    if (!$invoice) return;

    $keepIds = [];
    $total = 0.0;
    $upd = db()->prepare('UPDATE outsource_invoice_items SET quantity = ?, unit_rate = ?, total_amount = ? WHERE id = ? AND invoice_id = ?');
    foreach ($rows as $r) {
        $itemId = (int) ($r['item_id'] ?? 0);
        if ($itemId <= 0) continue;
        $qty = max(1, (int) ($r['qty'] ?? 1));
        $unit = max(0, (float) ($r['unit'] ?? 0));
        $amt = round($qty * $unit);
        $total += $amt;
        $upd->execute([$qty, $unit, $amt, $itemId, $invoiceId]);
        $keepIds[] = $itemId;
    }

    // حذف ردیف‌های حذف‌شده + آزادکردن کیس آن‌ها برای صدور مجدد
    $existing = db()->prepare('SELECT id, case_id FROM outsource_invoice_items WHERE invoice_id = ?');
    $existing->execute([$invoiceId]);
    $removeIds = [];
    $rel = db()->prepare('UPDATE cases SET outsource_invoice_id = NULL WHERE id = ? AND outsource_invoice_id = ?');
    foreach ($existing->fetchAll() as $it) {
        if (in_array((int) $it['id'], $keepIds, true)) continue;
        $removeIds[] = (int) $it['id'];
        if (!empty($it['case_id'])) {
            $rel->execute([(int) $it['case_id'], $invoiceId]);
        }
    }
    if (!empty($removeIds)) {
        $ph = implode(',', array_fill(0, count($removeIds), '?'));
        db()->prepare("DELETE FROM outsource_invoice_items WHERE id IN ($ph)")->execute($removeIds);
    }

    $updInv = db()->prepare('UPDATE outsource_invoices SET total_amount = ?, invoice_date = ?, period_label = ?, notes = ? WHERE id = ?');
    $updInv->execute([round($total), $invoiceDate, ($periodLabel !== '' ? $periodLabel : null), ($notes !== '' ? $notes : null), $invoiceId]);

    // جمع کل عوض شده → وضعیت پرداخت را بازمحاسبه کن
    refreshExpenseInvoiceStatus('outsource', $invoiceId);
}

/**
 * Automatic cases that are not yet on any outsource invoice, for a given lab.
 * Used by the "افزودن کیس به این فاکتور" picker (like the designer invoice form).
 * $payerBranch = شعبه‌ای که هزینهٔ برون‌سپاری را می‌پردازد.
 */
function getUninvoicedCasesForLabAll(int $labId, ?int $payerBranch = null): array {
    $payerCond = $payerBranch !== null ? ' AND COALESCE(c.branch_id, 0) = ?' : '';
    $stmt = db()->prepare('
        SELECT c.id, c.patient_name, c.service_id, c.quantity, c.received_date, c.doctor_id,
               c.case_type, c.lab_id, c.outsourced_lab_id, c.outsourced_rate, c.unit_price,
               p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE (c.lab_id = ? OR c.outsourced_lab_id = ?)
          AND c.outsource_invoice_id IS NULL' . $payerCond . '
        ORDER BY c.received_date DESC, c.id DESC
        LIMIT 500
    ');
    $params = [$labId, $labId];
    if ($payerBranch !== null) $params[] = (int) $payerBranch;
    $stmt->execute($params);
    $cases = $stmt->fetchAll();
    foreach ($cases as &$c) {
        // فی: نرخِ ثبت‌شده روی کیس (در صورت وجود) وگرنه نرخِ برون‌سپاریِ آن خدمت
        $rate = ($c['outsourced_rate'] ?? null) !== null ? (float) $c['outsourced_rate'] : getOutsourceRate($labId, (int) ($c['service_id'] ?? 0));
        $c['unit_rate'] = $rate === null ? 0.0 : (float) $rate;
    }
    return $cases;
}

/**
 * افزودن چند کیس به فاکتور برون‌سپاریِ موجود (آینهٔ addCasesToDesignerInvoice).
 */
function addCasesToOutsourceInvoice(int $invoiceId, array $caseIds): int {
    $inv = getOutsourceInvoice($invoiceId);
    if (!$inv) return 0;
    $labId = (int) $inv['lab_id'];

    $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds), fn($v) => $v > 0)));
    if (empty($caseIds)) return 0;

    $ph = implode(',', array_fill(0, count($caseIds), '?'));
    $st = db()->prepare("
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.id IN ($ph)
          AND (c.lab_id = ? OR c.outsourced_lab_id = ?)
          AND c.outsource_invoice_id IS NULL
    ");
    $st->execute(array_merge($caseIds, [$labId, $labId]));
    $cases = $st->fetchAll();
    if (empty($cases)) return 0;

    $now = date('Y-m-d H:i:s');
    $ins = db()->prepare('INSERT INTO outsource_invoice_items (invoice_id, case_id, doctor_id, doctor_name, service_id, service_title, patient_name, quantity, unit_rate, total_amount, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $mark = db()->prepare('UPDATE cases SET outsource_invoice_id = ? WHERE id = ?');

    $added = 0;
    $addedTotal = 0.0;
    foreach ($cases as $c) {
        $rate = ($c['outsourced_rate'] ?? null) !== null ? (float) $c['outsourced_rate'] : getOutsourceRate($labId, (int) ($c['service_id'] ?? 0));
        if ($rate === null) $rate = 0.0;
        $qty = (int) ($c['quantity'] ?? 1);
        $amt = round($rate * $qty);
        $ins->execute([
            $invoiceId,
            $c['id'],
            $c['doctor_id'] ?? null,
            $c['doctor_name'] ?? null,
            $c['service_id'] ?? null,
            $c['service_title'] ?? null,
            $c['patient_name'] ?? null,
            $qty,
            $rate,
            $amt,
            $c['received_date'] ?? null,
            $now,
        ]);
        $mark->execute([$invoiceId, $c['id']]);
        $addedTotal += $amt;
        $added++;
    }

    if ($added > 0) {
        db()->prepare('UPDATE outsource_invoices SET total_amount = total_amount + ? WHERE id = ?')->execute([round($addedTotal), $invoiceId]);
        refreshExpenseInvoiceStatus('outsource', $invoiceId);
    }
    return $added;
}

// =====================================================
// Clinic Invoice Helpers
// =====================================================

/**
 * Uninvoiced doctor-type cases of a clinic in a date range.
 * The clinic (not the individual doctors) is the payer → فقط کیس‌هایی که کلینیکِ همان کار هستند
 * (cases.clinic_id = این کلینیک) در فاکتور می‌آیند، نه همهٔ کارهای پزشکانِ عضوِ کلینیک.
 */
function getUninvoicedCasesForClinic(int $clinicId, string $startDate, string $endDate): array {
    $scope = clinicCaseScope('c', $clinicId);
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.case_type IN ("doctor", "lab_out")
          AND c.invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
          AND ' . $scope['sql'] . '
        ORDER BY c.doctor_id, c.received_date ASC
    ');
    $stmt->execute(array_merge([$startDate, $endDate], $scope['params']));
    return $stmt->fetchAll();
}

/**
 * کیس‌های فاکتورنشدهٔ پزشکانِ عضوِ این کلینیک که «کلینیکِ صاحبشان» این کلینیک نیست.
 * برای هشدار در صفحهٔ صدور فاکتور: این کارها در فاکتور این کلینیک نمی‌آیند
 * (چون فاکتور به کلینیکی داده می‌شود که کار برایش انجام شده).
 */
function getClinicDoctorCasesAssignedElsewhere(int $clinicId, string $startDate, string $endDate, int $limit = 10): array {
    $clinicId = (int) $clinicId;
    $ids = getClinicDoctorIdsForClinic($clinicId, false);
    if ($clinicId <= 0 || !$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare("
        SELECT c.id, c.patient_name, c.received_date, c.total_price, c.clinic_id,
               u.full_name AS doctor_name, cl.full_name AS clinic_name
        FROM cases c
        LEFT JOIN users u ON u.id = c.doctor_id
        LEFT JOIN users cl ON cl.id = c.clinic_id
        WHERE c.case_type IN ('doctor', 'lab_out')
          AND c.invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
          AND c.doctor_id IN ({$ph})
          AND (c.clinic_id IS NULL OR c.clinic_id <> ?)
        ORDER BY c.received_date ASC, c.id ASC
        LIMIT {$limit}
    ");
    $stmt->execute(array_merge([$startDate, $endDate], $ids, [$clinicId]));
    return $stmt->fetchAll();
}

/**
 * شمارهٔ فاکتورِ یکتا می‌سازد: {PREFIX}-{YYYYMM}-{NNN}
 *
 * چرا این تابع؟
 * کدِ قبلی با explode('-', $max)[N] شمارهٔ بعدی را می‌ساخت. وقتی خودِ PREFIX
 * خطِ تیره دارد (مثل «INV-CLN» یا «INV-DES») ایندکسِ ثابت غلط می‌شد و
 * «Undefined array key 4» می‌داد؛ نتیجه NULL/0 می‌شد و همیشه «-001» تولید
 * می‌شد → خطای Duplicate entry روی invoice_number.
 *
 * این تابع رقمِ آخرِ شماره را با regex می‌خواند، پس مستقل از تعداد خط‌تیره‌های
 * PREFIX درست کار می‌کند.
 *
 * @param string $table   نام جدول (فقط مقادیر داخلی/ثابت؛ هرگز ورودی کاربر نیست)
 * @param string $prefix  پیشوند شمارهٔ فاکتور، مثل INV-CLN
 * @param string $yearMonth بازهٔ YYYYMM
 */
function nextInvoiceNumber(string $table, string $prefix, string $yearMonth): string {
    // فهرست سفید جدول‌ها تا هرگز رشتهٔ بیرونی داخل SQL قرار نگیرد.
    $allowed = ['doctor_invoices', 'designer_invoices', 'outsource_invoices', 'branch_receivables', 'lab_invoices'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('nextInvoiceNumber: جدول نامعتبر: ' . $table);
    }

    $like = $prefix . '-' . $yearMonth . '-%';
    $stmt = db()->prepare("SELECT invoice_number FROM {$table} WHERE invoice_number LIKE ?");
    $stmt->execute([$like]);

    $maxNum = 0;
    $needle = $prefix . '-' . $yearMonth . '-';
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existing) {
        $tail = substr((string) $existing, strlen($needle));
        // فقط بخشِ عددیِ انتهایی را می‌خوانیم (مثلاً از «001» عدد 1).
        if ($tail !== '' && ctype_digit($tail)) {
            $maxNum = max($maxNum, (int) $tail);
        }
    }

    return $prefix . '-' . $yearMonth . '-' . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
}

/** Create a clinic invoice (grouped by doctor) from a list of cases. */
function createClinicInvoice(int $clinicId, array $cases, string $invoiceDate, ?string $periodLabel = null, ?int $bankAccountId = null): int {
    $now = date('Y-m-d H:i:s');

    // Invoice number: INV-CLN-YYYYMM-001 (prefix contains dashes → use the shared helper)
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $invoiceNumber = nextInvoiceNumber('doctor_invoices', 'INV-CLN', $yearMonth);

    $total = 0;
    foreach ($cases as $c) {
        $total += (float) $c['total_price'];
    }

    $clinic = db()->prepare('SELECT * FROM users WHERE id = ?');
    $clinic->execute([$clinicId]);
    $clinicUser = $clinic->fetch();
    $clinicName = $clinicUser['full_name'] ?? 'کلینیک';
    $clinicBranch = ($clinicUser['branch_id'] ?? null);
    $clinicBranch = ($clinicBranch !== null && $clinicBranch !== '') ? (int) $clinicBranch : null;

    $notes = 'فاکتور کلینیک' . ($periodLabel ? ' — بازه: ' . $periodLabel : '');
    $stmt = db()->prepare('INSERT INTO doctor_invoices (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, notes, bank_account_id, branch_id, created_at) VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $invoiceNumber,
        $clinicId,
        $clinicName,
        $total,
        'unpaid',
        $invoiceDate,
        $notes,
        $bankAccountId,
        $clinicBranch,
        $now,
    ]);
    $invoiceId = (int) db()->lastInsertId();

    $ins = db()->prepare('INSERT INTO invoice_items (invoice_id, price_id, case_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($cases as $c) {
        $desc = trim((string) ($c['doctor_name'] ?? ''));
        $location = formatCaseLocation($c['location_type'] ?? null, $c['teeth'] ?? null);
        if ($location !== '—') $desc = trim($desc . ' - ' . $location);
        $ins->execute([
            $invoiceId,
            $c['service_id'],
            $c['id'],
            $c['service_title'] ?? 'خدمت',
            $desc ?: null,
            $c['patient_name'],
            $c['quantity'] ?? 1,
            $c['unit_price'] ?? 0,
            $c['total_price'] ?? 0,
            $now,
        ]);
    }

    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }

    return $invoiceId;
}

// =====================================================
// Outsourcing (برون‌سپاری) Helpers – per-lab per-service rates + invoices
// =====================================================

/**
 * Phase 3: SQL subquery fragment that resolves the payable outsource price from
 * price_links for a row, using its provider lab column + service column.
 * Direction-based: the RECEIVER of the link must be the paying branch (payerCol,
 * usually the case's owning branch c.branch_id), so a link like «شعبه→لابراتوار»
 * (which is income to us) never matches an outsource the case owner pays.
 * - direct lab-provider link (provider_type='lab', provider_id=labCol)
 * - branch-provider link (provider_type='branch', provider_id=the lab's branch)
 * When USE_PRICE_LINKS is off, returns NULL (caller falls back to legacy).
 */
function priceLinkOutsourceSqlExpr(string $labCol, string $svcCol, string $payerCol = 'c.branch_id'): ?string {
    if (!defined('USE_PRICE_LINKS') || !USE_PRICE_LINKS) return null;
    $payerArm = "pl.receiver_type='branch' AND pl.receiver_id = {$payerCol}";
    return "(SELECT pl.price FROM price_links pl WHERE pl.active=1 AND pl.price_type IN ('outsource','specific') AND pl.service_id={$svcCol} AND ((pl.provider_type='lab' AND pl.provider_id={$labCol} AND {$payerArm}) OR (pl.provider_type='branch' AND pl.provider_id=(SELECT u.branch_id FROM users u WHERE u.id={$labCol}) AND {$payerArm})) ORDER BY (pl.provider_type='lab') DESC, pl.id DESC LIMIT 1)";
}

/**
 * Get the outsourcing rate for a lab+service (what the PAYER branch pays the lab).
 * Phase 3: when USE_PRICE_LINKS is on, price_links is authoritative first
 * (direct lab-provider link, then branch-provider link for the lab's own branch);
 * falls back to the legacy outsource_rates table.
 *
 * $receiverBranchId = the branch that PAYS (the case-owning branch).
 * وقتی از دیدِ «شعبهٔ انجام‌دهنده» نگاه می‌کنیم (مثلاً شعبهٔ مرکزی که خودش
 * لابراتوار است و کیسِ شعبهٔ دیگر به آن برون‌سپاری شده)، باید شعبهٔ مالکِ کیس
 * پاس داده شود؛ وگرنه نرخ پیدا نمی‌شود و مبلغ صفر نمایش داده می‌شود.
 * When null, the current user's branch is used (the payer's own perspective).
 */
function getOutsourceRate(int $labId, int $serviceId, ?int $receiverBranchId = null): ?float {
    if (defined('USE_PRICE_LINKS') && USE_PRICE_LINKS && $serviceId > 0) {
        // 1) direct lab-provider link: گیرنده باید یک شعبه (پرداخت‌کننده) باشد
        $stmt = db()->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type IN ('outsource','specific') AND provider_type = 'lab' AND provider_id = ? AND service_id = ? AND receiver_type = 'branch' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$labId, $serviceId]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) return (float) $val;

        // 2) branch-provider link: the lab's own branch provides the work;
        //    receiver must be a branch (the payer). When scoped → the payer branch.
        $pb = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
        $pb->execute([$labId]);
        $labBranch = $pb->fetchColumn();
        if ($labBranch !== false && $labBranch !== null) {
            $bid = $receiverBranchId ?? currentBranchId();
            if ($bid !== null) {
                $stmt = db()->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type IN ('outsource','specific') AND provider_type = 'branch' AND provider_id = ? AND service_id = ? AND receiver_type = 'branch' AND receiver_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([(int) $labBranch, $serviceId, $bid]);
            } else {
                $stmt = db()->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type IN ('outsource','specific') AND provider_type = 'branch' AND provider_id = ? AND service_id = ? AND receiver_type = 'branch' ORDER BY id DESC LIMIT 1");
                $stmt->execute([(int) $labBranch, $serviceId]);
            }
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) return (float) $val;
        }
    }

    $bid = $receiverBranchId ?? currentBranchId();
    if ($bid === null) {
        $stmt = db()->prepare('SELECT rate FROM outsource_rates WHERE lab_id = ? AND service_id = ?');
        $stmt->execute([$labId, $serviceId]);
    } else {
        // Prefer the branch-specific rate, fall back to the shared/global row.
        $stmt = db()->prepare('SELECT rate FROM outsource_rates WHERE lab_id = ? AND service_id = ? AND (branch_id = ? OR branch_id IS NULL) ORDER BY (branch_id = ?) DESC LIMIT 1');
        $stmt->execute([$labId, $serviceId, $bid, $bid]);
    }
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null) ? (float) $val : null;
}

/** Save (insert or update) an outsourcing rate. */
function saveOutsourceRate(int $labId, int $serviceId, float $rate): void {
    $bid = currentBranchId() ?? 1;
    // Prefer the exact branch row, otherwise use the shared/global row (branch_id
    // IS NULL) so we don't create redundant per-branch copies of a shared rate.
    $existing = db()->prepare('SELECT id, branch_id FROM outsource_rates WHERE lab_id = ? AND service_id = ? AND (branch_id = ? OR branch_id IS NULL) ORDER BY (branch_id = ?) DESC LIMIT 1');
    $existing->execute([$labId, $serviceId, $bid, $bid]);
    $row = $existing->fetch();
    if ($row) {
        $stmt = db()->prepare('UPDATE outsource_rates SET rate = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$rate, (int) $row['id']]);
    } else {
        $stmt = db()->prepare('INSERT INTO outsource_rates (lab_id, service_id, rate, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$labId, $serviceId, $rate, $bid]);
    }
}

/** All outsourcing rates (admin listing). */
function getAllOutsourceRates(): array {
    $bid = currentBranchId();
    $branchClause = $bid === null ? '' : ' WHERE (r.branch_id = ? OR r.branch_id IS NULL)';
    $stmt = db()->prepare('
        SELECT r.*, u.full_name AS lab_name, p.title AS service_title
        FROM outsource_rates r
        LEFT JOIN users u ON r.lab_id = u.id
        LEFT JOIN site_prices p ON r.service_id = p.id' . $branchClause . '
        ORDER BY u.full_name, p.title
    ');
    $params = [];
    if ($bid !== null) $params[] = $bid;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function deleteOutsourceRate(int $id): void {
    $stmt = db()->prepare('DELETE FROM outsource_rates WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Uninvoiced cases that create a payable (expense) to a given lab, within a date range.
 * Includes:
 *  - fully outsourced cases (case_type = lab_out, lab_id = lab)
 *  - side-outsourced cases (outsourced_lab_id = lab, outsourced_qty > 0) where part
 *    of the work was performed by that lab.
 * Each returned case is annotated with unit_rate, _bill_qty, _bill_service_id and
 * _bill_service_title so createOutsourceInvoice can bill correctly for either kind.
 */
function getUninvoicedOutsourceCases(int $labId, string $startDate, string $endDate): array {
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title, os.title AS outsourced_service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN site_prices os ON c.outsourced_service_id = os.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.outsource_invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
          AND (
            (c.case_type = "lab_out" AND c.lab_id = ?)
            OR (c.outsourced_lab_id = ? AND c.outsourced_qty > 0)
          )
        ORDER BY c.received_date ASC, c.id ASC
    ');
    $stmt->execute([$startDate, $endDate, $labId, $labId]);
    $cases = $stmt->fetchAll();
    foreach ($cases as &$c) {
        if ($c['case_type'] === 'lab_out' && (int) $c['lab_id'] === $labId) {
            $svcId = (int) ($c['service_id'] ?? 0);
            $qty = (int) ($c['quantity'] ?? 1);
            $svcTitle = $c['service_title'] ?? null;
        } else {
            $svcId = (int) ($c['outsourced_service_id'] ?? 0);
            $qty = (int) ($c['outsourced_qty'] ?? 0);
            $svcTitle = $c['outsourced_service_title'] ?? $c['service_title'] ?? null;
        }
        $c['unit_rate'] = $c['outsourced_rate'] !== null ? (float) $c['outsourced_rate'] : getOutsourceRate($labId, $svcId);
        $c['_bill_qty'] = $qty;
        $c['_bill_service_id'] = $svcId;
        $c['_bill_service_title'] = $svcTitle;
    }
    return $cases;
}

/** Create an outsourcing invoice from a list of outsourced cases. */
function createOutsourceInvoice(int $labId, array $cases, string $invoiceDate, ?string $periodLabel = null): int {
    $now = date('Y-m-d H:i:s');
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $invoiceNumber = nextInvoiceNumber('outsource_invoices', 'OUT', $yearMonth);

    $total = 0;
    $rows = [];
    foreach ($cases as $c) {
        // Use the billing qty/service annotated by getUninvoicedOutsourceCases.
        // Falls back to full-case billing (quantity, service_id) for safety.
        $qty = isset($c['_bill_qty']) ? (int) $c['_bill_qty'] : (int) ($c['quantity'] ?? 1);
        $svcId = isset($c['_bill_service_id']) ? (int) $c['_bill_service_id'] : (int) ($c['service_id'] ?? 0);
        $svcTitle = $c['_bill_service_title'] ?? $c['service_title'] ?? null;
        // Prefer the per-case saved rate; fall back to the outsource_rates lookup.
        $rate = ($c['outsourced_rate'] ?? null) !== null ? (float) $c['outsourced_rate'] : getOutsourceRate($labId, $svcId);
        if ($rate === null) $rate = 0.0;
        $amount = round($rate * $qty);
        $total += $amount;
        $rows[] = [$c, $rate, $qty, $amount, $svcId, $svcTitle];
    }

    $notes = 'فاکتور برون‌سپاری' . ($periodLabel ? ' — بازه: ' . $periodLabel : '');
    $branchId = currentBranchId() ?? 1;
    $ins = db()->prepare('INSERT INTO outsource_invoices (invoice_number, lab_id, total_amount, period_label, invoice_date, notes, branch_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$invoiceNumber, $labId, $total, $periodLabel, $invoiceDate, $notes, $branchId, $now]);
    $invoiceId = (int) db()->lastInsertId();

    $item = db()->prepare('INSERT INTO outsource_invoice_items (invoice_id, case_id, doctor_id, doctor_name, service_id, service_title, patient_name, quantity, unit_rate, total_amount, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as [$c, $rate, $qty, $amount, $svcId, $svcTitle]) {
        $item->execute([
            $invoiceId,
            $c['id'],
            $c['doctor_id'] ?? null,
            $c['doctor_name'] ?? null,
            $svcId ?: null,
            $svcTitle ?: null,
            $c['patient_name'] ?? null,
            $qty,
            $rate,
            $amount,
            $c['received_date'] ?? null,
            $now,
        ]);
    }

    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET outsource_invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }
    return $invoiceId;
}

function getOutsourceInvoice(int $id): ?array {
    $stmt = db()->prepare('SELECT i.*, u.full_name AS lab_name FROM outsource_invoices i LEFT JOIN users u ON i.lab_id = u.id WHERE i.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getOutsourceInvoiceItems(int $invoiceId): array {
    $stmt = db()->prepare('SELECT * FROM outsource_invoice_items WHERE invoice_id = ? ORDER BY id ASC');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

function getAllOutsourceInvoices(): array {
    $bid = currentBranchId();
    $filter = $bid === null ? '' : ' WHERE i.branch_id = ' . (int) $bid;
    $stmt = db()->query('SELECT i.*, u.full_name AS lab_name FROM outsource_invoices i LEFT JOIN users u ON i.lab_id = u.id' . $filter . ' ORDER BY i.invoice_date DESC, i.id DESC');
    return $stmt->fetchAll();
}

// =====================================================
// Expense Payments (پرداخت‌های ما به دیگران: طراح / لابراتوار)
// =====================================================

/** List expense payments (optionally filtered by type + invoice). */
function getAllExpensePayments(?string $type = null, ?int $invoiceId = null): array {
    $bid = currentBranchId();
    $where = [];
    $params = [];
    if ($bid !== null) {
        $where[] = 'p.branch_id = ?';
        $params[] = $bid;
    }
    if ($type && in_array($type, ['designer', 'outsource'], true)) {
        $where[] = 'p.expense_type = ?';
        $params[] = $type;
    }
    if ($invoiceId) {
        $where[] = 'p.invoice_id = ?';
        $params[] = $invoiceId;
    }
    $sql = 'SELECT p.*,
               CASE p.expense_type
                 WHEN "designer" THEN di.designer_name
                 ELSE oi.lab_name
               END AS party_name,
               CASE p.expense_type
                 WHEN "designer" THEN di.invoice_number
                 ELSE oi.invoice_number
               END AS invoice_number
            FROM expense_payments p
            LEFT JOIN (SELECT di.id, di.invoice_number, u.full_name AS designer_name
                       FROM designer_invoices di LEFT JOIN users u ON di.designer_id = u.id) di
              ON p.expense_type = "designer" AND di.id = p.invoice_id
            LEFT JOIN (SELECT oi.id, oi.invoice_number, u.full_name AS lab_name
                       FROM outsource_invoices oi LEFT JOIN users u ON oi.lab_id = u.id) oi
              ON p.expense_type = "outsource" AND oi.id = p.invoice_id';
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.payment_date DESC, p.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Get a single expense payment. */
function getExpensePayment(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM expense_payments WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Paid amount so far for an expense invoice. */
function getExpenseInvoicePaid(string $type, int $invoiceId): float {
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE expense_type = ? AND invoice_id = ?');
    $stmt->execute([$type, $invoiceId]);
    return (float) $stmt->fetchColumn();
}

/** Recompute payment_status (unpaid/partial/paid) for an expense invoice. */
function refreshExpenseInvoiceStatus(string $type, int $invoiceId): void {
    $table = $type === 'outsource' ? 'outsource_invoices' : 'designer_invoices';
    $stmt = db()->prepare("SELECT total_amount FROM {$table} WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $total = (float) ($stmt->fetchColumn() ?: 0);
    $paid = getExpenseInvoicePaid($type, $invoiceId);
    if ($total > 0 && $paid >= $total) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'partial';
    } else {
        $status = 'unpaid';
    }
    $upd = db()->prepare("UPDATE {$table} SET payment_status = ? WHERE id = ?");
    $upd->execute([$status, $invoiceId]);
}

/** Save an expense payment (insert/update). */
function saveExpensePayment(array $data): int {
    $now = date('Y-m-d H:i:s');
    $id = !empty($data['id']) ? (int) $data['id'] : 0;
    $branchId = currentBranchId() ?? 1;
    if ($id) {
        $stmt = db()->prepare('UPDATE expense_payments SET expense_type = ?, invoice_id = ?, amount = ?, payment_method = ?, payment_date = ?, transaction_number = ?, recipient_bank = ?, recipient_card = ?, notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['expense_type'],
            (int) $data['invoice_id'],
            (float) $data['amount'],
            $data['payment_method'] ?? null,
            $data['payment_date'] ?? null,
            $data['transaction_number'] ?? null,
            $data['recipient_bank'] ?? null,
            $data['recipient_card'] ?? null,
            $data['notes'] ?? null,
            $now,
            $id,
        ]);
        $savedId = $id;
    } else {
        $stmt = db()->prepare('INSERT INTO expense_payments (branch_id, expense_type, invoice_id, amount, payment_method, payment_date, transaction_number, recipient_bank, recipient_card, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $branchId,
            $data['expense_type'],
            (int) $data['invoice_id'],
            (float) $data['amount'],
            $data['payment_method'] ?? null,
            $data['payment_date'] ?? null,
            $data['transaction_number'] ?? null,
            $data['recipient_bank'] ?? null,
            $data['recipient_card'] ?? null,
            $data['notes'] ?? null,
            $now,
            $now,
        ]);
        $savedId = (int) db()->lastInsertId();
    }
    refreshExpenseInvoiceStatus($data['expense_type'], (int) $data['invoice_id']);
    return $savedId;
}

/** Delete an expense payment and refresh the invoice status. */
function deleteExpensePayment(int $id): void {
    $p = getExpensePayment($id);
    if (!$p) return;
    $stmt = db()->prepare('DELETE FROM expense_payments WHERE id = ?');
    $stmt->execute([$id]);
    refreshExpenseInvoiceStatus($p['expense_type'], (int) $p['invoice_id']);
}

/** Unpaid/partially-paid expense invoices for the payment form. */
function getPayableExpenseInvoices(): array {
    $bid = currentBranchId();
    $filter = $bid === null ? '' : ' AND i.branch_id = ' . (int) $bid;
    $rows = [];
    $d = db()->query('SELECT i.id, i.invoice_number, i.total_amount, i.payment_status, u.full_name AS party_name, "designer" AS expense_type
        FROM designer_invoices i LEFT JOIN users u ON i.designer_id = u.id
        WHERE i.payment_status != "paid"' . $filter . ' ORDER BY i.invoice_date DESC');
    foreach ($d->fetchAll() as $r) $rows[] = $r;
    $o = db()->query('SELECT i.id, i.invoice_number, i.total_amount, i.payment_status, u.full_name AS party_name, "outsource" AS expense_type
        FROM outsource_invoices i LEFT JOIN users u ON i.lab_id = u.id
        WHERE i.payment_status != "paid"' . $filter . ' ORDER BY i.invoice_date DESC');
    foreach ($o->fetchAll() as $r) $rows[] = $r;
    return $rows;
}

// =====================================================
// Branch Receivables (فاکتور طلب از شعبه همکار)
// When another branch outsources work to US (inbound, source_branch_id = our
// branch), the amount they owe us is the mirror of their outsource expense.
// =====================================================

/**
 * The amount a partner branch owes US for an inbound cross-branch case
 * (source_branch_id = our branch, branch_id = the partner's branch).
 * Mirrors the creating branch's expense: qty × rate where rate =
 * per-case outsourced_rate when set, else the outsource_rates lookup.
 */
function getInboundReceivableAmount(array $case): float {
    $isLabOut = ($case['case_type'] ?? '') === 'lab_out';
    $qty = $isLabOut ? (int) ($case['quantity'] ?? 1) : (int) ($case['outsourced_qty'] ?? 0);
    $svcId = $isLabOut ? (int) ($case['service_id'] ?? 0) : (int) ($case['outsourced_service_id'] ?? 0);
    $labId = $isLabOut ? (int) ($case['lab_id'] ?? 0) : (int) ($case['outsourced_lab_id'] ?? 0);
    // پرداخت‌کننده = شعبهٔ مالکِ کیس (نه شعبهٔ بیننده). بدون این، وقتی بیننده خودِ
    // شعبهٔ انجام‌دهنده (لابراتوار) باشد نرخ پیدا نمی‌شد و مبلغ صفر نمایش داده می‌شد.
    $payerBranch = (int) ($case['branch_id'] ?? 0);
    $rate = $case['outsourced_rate'] !== null ? (float) $case['outsourced_rate'] : getOutsourceRate($labId, $svcId, $payerBranch > 0 ? $payerBranch : null);
    if ($rate === null) $rate = 0.0;
    return round($rate * $qty);
}

/**
 * Inbound cross-branch cases (work a partner branch owes us for) that have not
 * yet been included in a receivable invoice, within a date range.
 * Only meaningful for a branch-scoped user (the receiving branch).
 * Annotates each case with unit_rate, _bill_qty, _bill_service_id/title, _bill_amount.
 */
function getUninvoicedInboundPartnerCases(?int $partnerBranchId, string $startDate, string $endDate): array {
    $bid = currentBranchId();
    if ($bid === null) $bid = 1;   // مدیر کل به‌عنوان شعبه‌ی اصلی/مرکزی صادر می‌کند
    $sql = 'SELECT c.*, p.title AS service_title, os.title AS outsourced_service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN site_prices os ON c.outsourced_service_id = os.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.source_branch_id = ?
          AND (c.branch_id IS NULL OR c.branch_id <> ?)
          AND c.receivable_invoice_id IS NULL';
    $params = [$bid, $bid];
    if ($partnerBranchId) {
        $sql .= ' AND c.branch_id = ?';
        $params[] = $partnerBranchId;
    }
    // بازه اختیاری است: اگر خالی باشد همهٔ ماه‌ها (کیس‌های فاکتورنشدهٔ قبلی) برمی‌گردد.
    if ($startDate !== '' && $endDate !== '') {
        $sql .= ' AND c.received_date BETWEEN ? AND ?';
        $params[] = $startDate;
        $params[] = $endDate;
    }
    $sql .= ' ORDER BY c.received_date ASC, c.id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $cases = $stmt->fetchAll();
    foreach ($cases as &$c) {
        $isLabOut = ($c['case_type'] ?? '') === 'lab_out';
        $qty = $isLabOut ? (int) ($c['quantity'] ?? 1) : (int) ($c['outsourced_qty'] ?? 0);
        $svcId = $isLabOut ? (int) ($c['service_id'] ?? 0) : (int) ($c['outsourced_service_id'] ?? 0);
        $svcTitle = $isLabOut ? ($c['service_title'] ?? null) : ($c['outsourced_service_title'] ?? $c['service_title'] ?? null);
        $labId = $isLabOut ? (int) ($c['lab_id'] ?? 0) : (int) ($c['outsourced_lab_id'] ?? 0);
        // نرخ از دید شعبهٔ پرداخت‌کننده (= مالکِ کیس) محاسبه می‌شود.
        $payerBranch = (int) ($c['branch_id'] ?? 0);
        $rate = $c['outsourced_rate'] !== null ? (float) $c['outsourced_rate'] : getOutsourceRate($labId, $svcId, $payerBranch > 0 ? $payerBranch : null);
        $c['unit_rate'] = $rate;
        $c['_bill_qty'] = $qty;
        $c['_bill_service_id'] = $svcId;
        $c['_bill_service_title'] = $svcTitle;
        $c['_bill_amount'] = round(($rate ?? 0.0) * $qty);
    }
    return $cases;
}

/**
 * کیس‌های برون‌سپاری‌شدهٔ فاکتورنشدهٔ یک شعبهٔ همکار که می‌توان به یک فاکتور طلبِ
 * موجود اضافه کرد (همهٔ ماه‌ها، بدون محدودیت بازه). برای صفحهٔ ویرایش فاکتور.
 */
function getBranchReceivableAvailableCases(int $receivableId, ?int $partnerBranchId): array {
    return getUninvoicedInboundPartnerCases($partnerBranchId, '', '');
}

/**
 * افزودن کیس‌های انتخابی به یک فاکتور طلبِ موجود (مثل افزودن کیس از ماه‌های دیگر).
 * کیس‌هایی که قبلاً در فاکتور دیگری استفاده شده‌اند رد می‌شوند.
 * @return int تعداد کیس‌های اضافه‌شده
 */
function addCasesToBranchReceivable(int $receivableId, array $caseIds): int {
    $inv = getBranchReceivable($receivableId);
    if (!$inv) return 0;
    $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds))));
    if (empty($caseIds)) return 0;

    $partnerBranchId = (int) ($inv['partner_branch_id'] ?? 0);
    $bid = (int) ($inv['branch_id'] ?? 0);
    if ($bid <= 0) {
        $bid = currentBranchId() ?? 1;
    }

    $ph = implode(',', array_fill(0, count($caseIds), '?'));
    // فقط کیس‌های واجد شرایط: همان شعبهٔ همکار، هنوز در هیچ فاکتور طلبی نیامده‌اند.
    // (فرض: یک کیس فقط به یک فاکتور طلب می‌رود؛ ستون cases.receivable_invoice_id همین را تضمین می‌کند.)
    $params = array_merge([$bid, $bid, $partnerBranchId], $caseIds);
    $stmt = db()->prepare("SELECT c.*, p.title AS service_title, os.title AS outsourced_service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN site_prices os ON c.outsourced_service_id = os.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.source_branch_id = ?
          AND (c.branch_id IS NULL OR c.branch_id <> ?)
          AND c.branch_id = ?
          AND c.receivable_invoice_id IS NULL
          AND c.id IN ($ph)");
    $stmt->execute($params);
    $cases = $stmt->fetchAll();
    if (empty($cases)) return 0;

    // محاسبهٔ نرخ/تعداد با همان منطق getUninvoicedInboundPartnerCases
    $now = date('Y-m-d H:i:s');
    $added = 0;
    $item = db()->prepare('INSERT INTO branch_receivable_items (receivable_id, case_id, doctor_id, doctor_name, service_id, service_title, patient_name, quantity, unit_rate, total_amount, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $link = db()->prepare('UPDATE cases SET receivable_invoice_id = ? WHERE id = ? AND receivable_invoice_id IS NULL');
    foreach ($cases as $c) {
        $isLabOut = ($c['case_type'] ?? '') === 'lab_out';
        $qty = $isLabOut ? (int) ($c['quantity'] ?? 1) : (int) ($c['outsourced_qty'] ?? 0);
        $svcId = $isLabOut ? (int) ($c['service_id'] ?? 0) : (int) ($c['outsourced_service_id'] ?? 0);
        $svcTitle = $isLabOut ? ($c['service_title'] ?? null) : ($c['outsourced_service_title'] ?? $c['service_title'] ?? null);
        $labId = $isLabOut ? (int) ($c['lab_id'] ?? 0) : (int) ($c['outsourced_lab_id'] ?? 0);
        $payerBranch = (int) ($c['branch_id'] ?? 0);
        $rate = $c['outsourced_rate'] !== null
            ? (float) $c['outsourced_rate']
            : getOutsourceRate($labId, $svcId, $payerBranch > 0 ? $payerBranch : null);
        $rate = (float) ($rate ?? 0.0);
        $amount = round($rate * $qty);

        $item->execute([
            $receivableId,
            (int) $c['id'],
            $c['doctor_id'] ?? null,
            $c['doctor_name'] ?? null,
            $svcId ?: null,
            $svcTitle ?: null,
            $c['patient_name'] ?? null,
            $qty,
            $rate,
            $amount,
            $c['received_date'] ?? null,
            $now,
        ]);
        $link->execute([$receivableId, (int) $c['id']]);
        if ($link->rowCount() > 0) {
            $added++;
        }
    }

    // جمع کل و وضعیت را دوباره حساب کن
    $sum = db()->prepare('SELECT COALESCE(SUM(total_amount),0) FROM branch_receivable_items WHERE receivable_id = ?');
    $sum->execute([$receivableId]);
    $newTotal = (float) $sum->fetchColumn();
    db()->prepare('UPDATE branch_receivables SET total_amount = ? WHERE id = ?')->execute([round($newTotal), $receivableId]);
    refreshBranchReceivableStatus($receivableId);

    return $added;
}

/** Create a receivable invoice to a partner branch. */
function createBranchReceivable(int $partnerBranchId, array $cases, string $invoiceDate, ?string $periodLabel = null): int {    $now = date('Y-m-d H:i:s');
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $invoiceNumber = nextInvoiceNumber('branch_receivables', 'REC', $yearMonth);

    $total = 0;
    $rows = [];
    foreach ($cases as $c) {
        $qty = (int) ($c['_bill_qty'] ?? 1);
        $svcId = (int) ($c['_bill_service_id'] ?? 0);
        $svcTitle = $c['_bill_service_title'] ?? null;
        $rate = ($c['unit_rate'] ?? null) !== null ? (float) $c['unit_rate'] : 0.0;
        $amount = round($rate * $qty);
        $total += $amount;
        $rows[] = [$c, $rate, $qty, $amount, $svcId, $svcTitle];
    }

    $notes = 'فاکتور طلب از شعبه همکار' . ($periodLabel ? ' — بازه: ' . $periodLabel : '');
    $branchId = currentBranchId() ?? 1;
    $ins = db()->prepare('INSERT INTO branch_receivables (invoice_number, branch_id, partner_branch_id, total_amount, period_label, invoice_date, notes, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, "unpaid", ?)');
    $ins->execute([$invoiceNumber, $branchId, $partnerBranchId, $total, $periodLabel, $invoiceDate, $notes, $now]);
    $invoiceId = (int) db()->lastInsertId();

    $item = db()->prepare('INSERT INTO branch_receivable_items (receivable_id, case_id, doctor_id, doctor_name, service_id, service_title, patient_name, quantity, unit_rate, total_amount, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as [$c, $rate, $qty, $amount, $svcId, $svcTitle]) {
        $item->execute([
            $invoiceId,
            $c['id'],
            $c['doctor_id'] ?? null,
            $c['doctor_name'] ?? null,
            $svcId ?: null,
            $svcTitle ?: null,
            $c['patient_name'] ?? null,
            $qty,
            $rate,
            $amount,
            $c['received_date'] ?? null,
            $now,
        ]);
    }

    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $upd = db()->prepare("UPDATE cases SET receivable_invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $upd->execute($caseIds);
    }
    return $invoiceId;
}

function getBranchReceivable(int $id): ?array {
    $stmt = db()->prepare('SELECT r.*, b.name AS partner_branch_name FROM branch_receivables r LEFT JOIN branches b ON r.partner_branch_id = b.id WHERE r.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getBranchReceivableItems(int $receivableId): array {
    $stmt = db()->prepare('SELECT * FROM branch_receivable_items WHERE receivable_id = ? ORDER BY id ASC');
    $stmt->execute([$receivableId]);
    return $stmt->fetchAll();
}

function getAllBranchReceivables(): array {
    $bid = currentBranchId();
    $filter = $bid === null ? '' : ' WHERE r.branch_id = ' . (int) $bid;
    $stmt = db()->query('SELECT r.*, b.name AS partner_branch_name FROM branch_receivables r LEFT JOIN branches b ON r.partner_branch_id = b.id' . $filter . ' ORDER BY r.invoice_date DESC, r.id DESC');
    return $stmt->fetchAll();
}

/** Paid amount so far for a receivable invoice. */
function getBranchReceivablePaid(int $receivableId): float {
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM branch_receivable_payments WHERE receivable_id = ?');
    $stmt->execute([$receivableId]);
    return (float) $stmt->fetchColumn();
}

/** Recompute payment_status (unpaid/partial/paid) for a receivable invoice. */
function refreshBranchReceivableStatus(int $receivableId): void {
    $stmt = db()->prepare('SELECT total_amount FROM branch_receivables WHERE id = ?');
    $stmt->execute([$receivableId]);
    $total = (float) ($stmt->fetchColumn() ?: 0);
    $paid = getBranchReceivablePaid($receivableId);
    if ($total > 0 && $paid >= $total) $status = 'paid';
    elseif ($paid > 0) $status = 'partial';
    else $status = 'unpaid';
    $upd = db()->prepare('UPDATE branch_receivables SET payment_status = ? WHERE id = ?');
    $upd->execute([$status, $receivableId]);
}

/** Save a payment received against a receivable invoice. */
function saveBranchReceivablePayment(array $data): int {
    $now = date('Y-m-d H:i:s');
    $id = !empty($data['id']) ? (int) $data['id'] : 0;
    if ($id) {
        $stmt = db()->prepare('UPDATE branch_receivable_payments SET amount = ?, payment_date = ?, method = ?, reference = ?, notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            (float) $data['amount'],
            $data['payment_date'] ?? null,
            $data['method'] ?? null,
            $data['reference'] ?? null,
            $data['notes'] ?? null,
            $now,
            $id,
        ]);
        $savedId = $id;
    } else {
        $stmt = db()->prepare('INSERT INTO branch_receivable_payments (receivable_id, amount, payment_date, method, reference, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            (int) $data['receivable_id'],
            (float) $data['amount'],
            $data['payment_date'] ?? null,
            $data['method'] ?? null,
            $data['reference'] ?? null,
            $data['notes'] ?? null,
            $now,
            $now,
        ]);
        $savedId = (int) db()->lastInsertId();
    }
    refreshBranchReceivableStatus((int) $data['receivable_id']);
    return $savedId;
}

/** Delete a payment and refresh the receivable status. */
function deleteBranchReceivablePayment(int $id): void {
    $p = db()->prepare('SELECT receivable_id FROM branch_receivable_payments WHERE id = ?');
    $p->execute([$id]);
    $rid = (int) ($p->fetchColumn() ?: 0);
    db()->prepare('DELETE FROM branch_receivable_payments WHERE id = ?')->execute([$id]);
    if ($rid) refreshBranchReceivableStatus($rid);
}

/** Delete a receivable invoice (and its items/payments, release the cases). */
function deleteBranchReceivable(int $id): void {
    $inv = getBranchReceivable($id);
    if (!$inv) return;
    db()->prepare('DELETE FROM branch_receivable_payments WHERE receivable_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM branch_receivable_items WHERE receivable_id = ?')->execute([$id]);
    db()->prepare('UPDATE cases SET receivable_invoice_id = NULL WHERE receivable_invoice_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM branch_receivables WHERE id = ?')->execute([$id]);
}

/**
 * Edit a branch-receivable invoice header + line items.
 * $rows: each = [item_id, qty, unit]; items not present in the list are removed
 * and their case is released (receivable_invoice_id → NULL). Totals are recomputed
 * and the payment_status is refreshed against recorded receipts.
 */
function saveBranchReceivableEdit(int $receivableId, string $invoiceDate, ?string $periodLabel, ?string $notes, array $rows): void {
    $now = date('Y-m-d H:i:s');
    $inv = getBranchReceivable($receivableId);
    if (!$inv) return;

    $keepIds = [];
    $total = 0.0;
    $upd = db()->prepare('UPDATE branch_receivable_items SET quantity = ?, unit_rate = ?, total_amount = ? WHERE id = ? AND receivable_id = ?');
    // ردیف‌های موجود را یک‌جا بخوان تا هم ردیف‌های «یتیم» (کیس حذف‌شده) را
    // دست‌نخورده نگه داریم و هم مبلغ فعلی‌شان در جمع کل بیاید.
    $cur = db()->prepare('SELECT id, case_id, quantity, unit_rate, total_amount FROM branch_receivable_items WHERE receivable_id = ?');
    $cur->execute([$receivableId]);
    $currentById = [];
    foreach ($cur->fetchAll() as $r) {
        $currentById[(int) $r['id']] = $r;
    }

    foreach ($rows as $r) {
        $itemId = (int) ($r['item_id'] ?? 0);
        if ($itemId <= 0 || !isset($currentById[$itemId])) continue;

        // ردیف یتیم: کیسش وجود ندارد → تعداد/نرخ/مبلغ را همان‌طور که هست نگه دار.
        if (!empty($r['skip'])) {
            $total += (float) $currentById[$itemId]['total_amount'];
            $keepIds[] = $itemId;
            continue;
        }

        $qty = max(1, (int) ($r['qty'] ?? 1));
        $unit = max(0, (float) ($r['unit'] ?? 0));
        $amt = round($qty * $unit);
        $total += $amt;
        $upd->execute([$qty, $unit, $amt, $itemId, $receivableId]);
        $keepIds[] = $itemId;
    }

    // drop rows that were unchecked (removed); ردیف‌های یتیم هم اگر تیک خورده باشند حذف می‌شوند
    $removeIds = [];
    $rel = db()->prepare('UPDATE cases SET receivable_invoice_id = NULL WHERE id = ? AND receivable_invoice_id = ?');
    foreach ($currentById as $id => $it) {
        if (in_array((int) $id, $keepIds, true)) continue;
        $removeIds[] = (int) $id;
        if (!empty($it['case_id'])) {
            $rel->execute([(int) $it['case_id'], $receivableId]);
        }
    }
    if (!empty($removeIds)) {
        $ph = implode(',', array_fill(0, count($removeIds), '?'));
        db()->prepare("DELETE FROM branch_receivable_items WHERE id IN ($ph)")->execute($removeIds);
    }

    // بازهٔ دقیق: اگر کاربر دستی وارد کرده باشد ذخیره می‌شود؛ وگرنه از برچسب
    // فارسی بازسازی می‌گردد تا در PDF بتوان «کیس خارج از بازه» را تشخیص داد.
    $updInv = db()->prepare('UPDATE branch_receivables SET total_amount = ?, invoice_date = ?, period_label = ?, notes = ? WHERE id = ?');
    $updInv->execute([
        round($total),
        $invoiceDate,
        ($periodLabel !== '' ? $periodLabel : null),
        ($notes !== '' ? $notes : null),
        $receivableId,
    ]);
    refreshBranchReceivableStatus($receivableId);}

/**
 * Get the applicable price for a doctor+service combination.
 * Resolution order:
 *   1) doctor's per-service override (doctor_price_overrides)
 *   2) the current branch's custom price for that service (branch_service_prices)
 *   3) the shared catalog default (site_prices.price)
 * @return float|null
 */
function getApplicablePrice($doctor_id, $service_id) {
    $override = getDoctorPriceOverride($doctor_id, $service_id);
    if ($override) {
        return (float) $override['custom_price'];
    }
    // current branch's custom price (if set)
    $branchPrice = getBranchServiceCustomPrice((int) $service_id);
    if ($branchPrice !== null) {
        return $branchPrice;
    }
    // shared catalog default
    $price = getPrice($service_id);
    return $price ? (float) $price['price'] : null;
}

/**
 * Get all overrides (admin listing) with doctor name and service title.
 */
function getAllDoctorPriceOverrides() {
    $bid = currentBranchId();
    if ($bid === null) {
        $stmt = db()->query('
            SELECT o.*, u.full_name AS doctor_name, u.role AS target_role,
                   CASE WHEN o.price_type = "design_fee" THEN COALESCE(p.title, "هزینه طراحی") ELSE p.title END AS service_title
            FROM doctor_price_overrides o
            LEFT JOIN users u ON o.doctor_id = u.id
            LEFT JOIN site_prices p ON o.service_id = p.id
            ORDER BY u.full_name, o.price_type, p.title
        ');
        return $stmt->fetchAll();
    }
    // Branch user: shows its own overrides PLUS the SHARED inter-branch / lab
    // rates (target is a lab-role user, or the target belongs to another branch).
    // Same-branch doctor/clinic/designer prices stay private to their branch.
    $stmt = db()->prepare('
        SELECT o.*, u.full_name AS doctor_name, u.role AS target_role,
               CASE WHEN o.price_type = "design_fee" THEN COALESCE(p.title, "هزینه طراحی") ELSE p.title END AS service_title
        FROM doctor_price_overrides o
        LEFT JOIN users u ON o.doctor_id = u.id
        LEFT JOIN site_prices p ON o.service_id = p.id
        WHERE o.branch_id = ? OR o.branch_id IS NULL
           OR u.role IN ("outsource_lab","partner_lab","customer_lab","lab")
           OR (u.branch_id IS NOT NULL AND o.branch_id IS NOT NULL AND u.branch_id <> o.branch_id)
        ORDER BY u.full_name, o.price_type, p.title
    ');
    $stmt->execute([(int) $bid]);
    return $stmt->fetchAll();
}

/**
 * Get overrides for a specific doctor.
 */
function getDoctorPriceOverrides($doctor_id) {
    $bid = currentBranchId();
    $branchClause = $bid === null ? '' : ' AND (o.branch_id = ? OR o.branch_id IS NULL)';
    $stmt = db()->prepare('
        SELECT o.*, CASE WHEN o.price_type = "design_fee" THEN COALESCE(p.title, "هزینه طراحی") ELSE p.title END AS service_title
        FROM doctor_price_overrides o
        LEFT JOIN site_prices p ON o.service_id = p.id
        WHERE o.doctor_id = ?' . $branchClause . '
        ORDER BY o.price_type, p.title
    ');
    $params = [(int)$doctor_id];
    if ($bid !== null) $params[] = $bid;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Whether a price override targets a SHARED inter-branch / lab party.
 * These rates (the inter-lab «بین شعب» prices) may only be changed by the
 * central (root) admin. A target is shared if:
 *   - it is a lab-role user, OR
 *   - the target belongs to a DIFFERENT branch than the override's branch
 *     (e.g. an override recorded by the central branch for the Qazvin manager).
 */
function isSharedPriceOverrideTarget(int $targetUserId, ?int $overrideBranchId): bool {
    $u = db()->prepare('SELECT role, branch_id FROM users WHERE id = ?');
    $u->execute([$targetUserId]);
    $target = $u->fetch();
    if (!$target) return false;
    if (in_array($target['role'], ['outsource_lab', 'partner_lab', 'customer_lab', 'lab'], true)) {
        return true;
    }
    $targetBranch = $target['branch_id'] !== null ? (int) $target['branch_id'] : null;
    if ($targetBranch !== null && $overrideBranchId !== null && $targetBranch !== $overrideBranchId) {
        return true;
    }
    return false;
}

/**
 * Save (insert or update) a price override.
 * $data must contain: doctor_id, service_id, custom_price.
 * If an override already exists for that doctor+service, update it.
 * Returns the override ID.
 */
function saveDoctorPriceOverride($data) {
    $doctor_id = (int) $data['doctor_id'];
    $service_id = !empty($data['service_id']) ? (int) $data['service_id'] : null;
    $price_type = isset($data['price_type']) ? strtolower((string) $data['price_type']) : 'service';
    if (!in_array($price_type, ['service', 'design_fee'], true)) {
        $price_type = 'service';
    }
    // design_fee: service_id may be null (general rate) or specific (per-type rate)
    $custom_price = (float) $data['custom_price'];

    $existingStmt = db()->prepare('SELECT id FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND (? IS NULL AND service_id IS NULL OR service_id = ?)');
    $existingStmt->execute([$doctor_id, $price_type, $service_id, $service_id]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $stmt = db()->prepare('UPDATE doctor_price_overrides SET custom_price = ?, service_id = ?, price_type = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$custom_price, $service_id, $price_type, (int) $existing['id']]);
        return (int) $existing['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO doctor_price_overrides (doctor_id, service_id, price_type, custom_price, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$doctor_id, $service_id, $price_type, $custom_price, currentBranchId() ?? 1]);
        return (int) db()->lastInsertId();
    }
}

/**
 * Delete a price override by ID.
 */
function deleteDoctorPriceOverride($id) {
    $stmt = db()->prepare('DELETE FROM doctor_price_overrides WHERE id = ?');
    $stmt->execute([(int)$id]);
}
// =====================================================
// Functions for monthly invoice generation
// =====================================================

/**
 * Get uninvoiced completed cases for a doctor within a date range.
 * @param int $doctor_id
 * @param string $startDate YYYY-MM-DD
 * @param string $endDate   YYYY-MM-DD
 * @return array
 */
function getUninvoicedCasesForDoctor($doctor_id, $startDate, $endDate) {
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        WHERE c.doctor_id = ?
          AND c.invoice_id IS NULL
          AND c.case_type IN ("doctor", "lab_out", "lab_in")
          AND c.received_date BETWEEN ? AND ?
        ORDER BY c.received_date ASC
    ');
    $stmt->execute([$doctor_id, $startDate, $endDate]);
    return $stmt->fetchAll();
}

/**
 * Get outstanding balance (sum of unpaid invoices) for a doctor.
 * @param int $doctor_id
 * @return float
 */
function getOutstandingBalance($doctor_id) {
    $stmt = db()->prepare('
        SELECT COALESCE(SUM(total_amount), 0) 
        FROM doctor_invoices 
        WHERE doctor_id = ? AND payment_status = "unpaid"
    ');
    $stmt->execute([$doctor_id]);
    return (float) $stmt->fetchColumn();
}

/**
 * Create a monthly invoice from a list of cases and an optional balance.
 * @param int   $doctor_id
 * @param array $cases        Array of case rows (from getUninvoicedCasesForDoctor)
 * @param float $balance      Outstanding balance from previous months
 * @param string $invoiceDate YYYY-MM-DD (usually today)
 * @return int invoice_id
 */
function createMonthlyInvoice($doctor_id, $cases, $balance, $invoiceDate, $bankAccountId = null) {
    $now = date('Y-m-d H:i:s');
    
    // Generate a unique invoice number (e.g., INV-YYYYMM-001)
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $invoiceNumber = nextInvoiceNumber('doctor_invoices', 'INV', $yearMonth);

    // Calculate total amount
    $total = $balance;
    foreach ($cases as $case) {
        $total += (float) $case['total_price'];
    }

    // Insert invoice
    // (Branch: منسوب به شعبه‌ی خودِ پزشک است — بعد از افزودن «شعبه» به سامانه، فاکتورهایی که
    //  بدون branch_id صادر شدند در آمار مدیر شعبه حساب نمی‌شدند؛ اینجا اصلاح می‌شود.)
    $bs = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
    $bs->execute([$doctor_id]);
    $branchId = $bs->fetchColumn();
    $branchId = ($branchId !== null && $branchId !== '') ? (int) $branchId : null;

    $stmt = db()->prepare('
        INSERT INTO doctor_invoices 
        (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, notes, bank_account_id, branch_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    // Get doctor info
    $doctor = getDoctor($doctor_id);
    $stmt->execute([
        $invoiceNumber,
        $doctor_id,
        $doctor['name'] ?? '',
        $doctor['phone'] ?? '',
        $doctor['email'] ?? '',
        $total,
        'unpaid',
        $invoiceDate,
        'فاکتور ماهانه خودکار',
        $bankAccountId,
        $branchId,
        $now
    ]);
    $invoiceId = db()->lastInsertId();

    // Insert invoice items
    // Insert invoice items
    foreach ($cases as $case) {
        $locationStr = formatCaseLocation($case['location_type'], $case['teeth']);
        $description = '';
        if ($locationStr !== '—') {
            $description .= '' . $locationStr;
        }
        $stmt = db()->prepare('
            INSERT INTO invoice_items 
            (invoice_id, price_id, case_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $invoiceId,
            $case['service_id'],
            $case['id'],           // case_id for linking to case
            $case['service_title'] ?? 'خدمت',
            $description,
            $case['patient_name'],
            $case['quantity'] ?? 1,        // <-- use case quantity
            $case['unit_price'] ?? 0,
            $case['total_price'] ?? 0,
            $now
        ]);
    }

    // If there is a balance, add a separate item
    if ($balance > 0) {
        $stmt = db()->prepare('
            INSERT INTO invoice_items 
            (invoice_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $invoiceId,
            'مانده بدهی از ماه قبل',
            'بدهی معوق از فاکتورهای قبلی',
            '',
            1,
            $balance,
            $balance,
            $now
        ]);
    }

    // Mark cases as invoiced
    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }

    return $invoiceId;
}

// =====================================================
// Role Management Helpers
// =====================================================

function getAllRoles(): array {
    $stmt = db()->query('SELECT * FROM roles ORDER BY id ASC');
    return $stmt->fetchAll();
}

function getRole(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM roles WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getRoleByName(string $name): ?array {
    $stmt = db()->prepare('SELECT * FROM roles WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getRolePermissions(string $roleName): array {
    $role = getRoleByName($roleName);
    if (!$role || empty($role['permissions'])) return [];
    $perms = json_decode($role['permissions'], true);
    return is_array($perms) ? $perms : [];
}

function saveRole(array $data): int {
    $name = trim($data['name'] ?? '');
    $label = trim($data['label'] ?? '');
    $permissions = $data['permissions'] ?? [];
    $permsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
    
    if (isset($data['id']) && !empty($data['id'])) {
        $stmt = db()->prepare('UPDATE roles SET name = ?, label = ?, permissions = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$name, $label, $permsJson, (int)$data['id']]);
        return (int)$data['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO roles (name, label, permissions, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
        $stmt->execute([$name, $label, $permsJson]);
        return (int) db()->lastInsertId();
    }
}

function deleteRole(int $id): bool {
    $check = db()->prepare('SELECT COUNT(*) FROM users WHERE role = (SELECT name FROM roles WHERE id = ?)');
    $check->execute([$id]);
    if ((int) $check->fetchColumn() > 0) return false;
    
    $stmt = db()->prepare('DELETE FROM roles WHERE id = ?');
    $stmt->execute([$id]);
    return true;
}

function getAllPermissionDefinitions(): array {
    return [
        '*'                    => 'دسترسی کامل (مدیر)',
        'view_all_cases'       => 'مشاهده همه کیس‌ها',
        'view_own_cases'       => 'مشاهده کیس‌های خود',
        'view_assigned_cases'  => 'مشاهده کیس‌های محول شده',
        'view_clinic_cases'    => 'مشاهده کیس‌های کلینیک',
        'create_cases'         => 'ایجاد کیس',
        'edit_cases'           => 'ویرایش کیس',
        'edit_case_status'     => 'ویرایش وضعیت کیس',
        'update_case_status'   => 'بروزرسانی وضعیت',
        'upload_files'         => 'آپلود فایل',
        'upload_design_files'  => 'آپلود فایل طراحی',
        'delete_files'         => 'حذف فایل',
        'view_case_files'      => 'مشاهده فایل‌های کیس',
        'view_invoices'        => 'مشاهده فاکتورها',
        'view_clinic_invoices' => 'مشاهده فاکتورهای کلینیک',
        'view_own_invoices'    => 'مشاهده فاکتورهای خود',
        'view_payments'        => 'مشاهده پرداخت‌ها',
        'view_own_payments'    => 'مشاهده پرداخت‌های خود',
        'view_clinic_payments' => 'مشاهده پرداخت‌های کلینیک',
        'batch_print_labels'   => 'پرینت برچسب گروهی',
        'batch_update_status'  => 'تغییر وضعیت گروهی',
        'export_csv'           => 'خروجی CSV',
    ];
}

// =====================================================
// Notification Helpers
// =====================================================

function createNotification(int $userId, string $title, string $message = null, int $caseId = null, string $type = 'info'): int {
    $stmt = db()->prepare('INSERT INTO notifications (user_id, case_id, title, message, type, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$userId, $caseId, $title, $message, $type]);
    return (int) db()->lastInsertId();
}

function getUnreadNotifications(int $userId, int $limit = 10): array {
    $limit = (int) max(1, $limit);
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ' . $limit);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getUnreadNotificationCount(int $userId): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function markNotificationRead(int $notificationId): void {
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?');
    $stmt->execute([$notificationId]);
}

function markAllNotificationsRead(int $userId): void {
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
}

function getAllNotifications(int $userId, int $limit = 50): array {
    $limit = (int) max(1, $limit);
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . $limit);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// =====================================================
// Lab Billing Helpers
// =====================================================

/** Get uninvoiced cases for a lab within a date range, with billing direction */
function getUninvoicedCasesForLab(int $labId, string $startDate, string $endDate): array {
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.lab_id = ?
          AND c.invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
          AND c.case_type IN (\'lab_in\', \'lab_out\')
        ORDER BY c.doctor_id, c.received_date ASC
    ');
    $stmt->execute([$labId, $startDate, $endDate]);
    return $stmt->fetchAll();
}

/** Get lab price override for a service, or fallback to default price */
function getLabApplicablePrice(int $labId, int $serviceId): float {
    // Phase 3: specific lab link (receiver = lab) is authoritative first.
    if (defined('USE_PRICE_LINKS') && USE_PRICE_LINKS && $serviceId > 0) {
        $stmt = db()->prepare("SELECT price FROM price_links WHERE active = 1 AND price_type = 'specific' AND receiver_type = 'lab' AND receiver_id = ? AND service_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$labId, $serviceId]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) return (float) $val;
    }

    // 1) Unified override table (lab stored as target with price_type = service)
    $override = db()->prepare('SELECT custom_price FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = "service" AND service_id = ?');
    $override->execute([$labId, $serviceId]);
    $val = $override->fetchColumn();
    if ($val !== false && $val !== null) {
        return (float) $val;
    }

    // 2) Legacy lab-specific override table
    $override = db()->prepare('SELECT custom_price FROM lab_price_overrides WHERE lab_id = ? AND service_id = ?');
    $override->execute([$labId, $serviceId]);
    $val = $override->fetchColumn();
    if ($val !== false && $val !== null) {
        return (float) $val;
    }

    // 3) Default price
    $price = getPrice($serviceId);
    return $price ? (float) $price['price'] : 0.0;
}

/**
 * All lab price overrides (shared inter-lab price list).
 * These are the prices each lab charges per service. They are SHARED across all
 * branches (like the site_prices catalog): when a branch outsources to a lab —
 * including the central branch's lab — both sides see the same agreed rate.
 */
function getAllLabPriceOverrides(): array {
    $stmt = db()->query('
        SELECT o.*, u.full_name AS lab_name, p.title AS service_title
        FROM lab_price_overrides o
        LEFT JOIN users u ON o.lab_id = u.id
        LEFT JOIN site_prices p ON o.service_id = p.id
        ORDER BY u.full_name, p.title
    ');
    return $stmt->fetchAll();
}

/** Save (insert or update) a lab price override */
function saveLabPriceOverride(array $data): int {
    $lab_id = (int) $data['lab_id'];
    $service_id = (int) $data['service_id'];
    $custom_price = (float) $data['custom_price'];

    $existing = db()->prepare('SELECT id FROM lab_price_overrides WHERE lab_id = ? AND service_id = ?');
    $existing->execute([$lab_id, $service_id]);
    $existingId = $existing->fetchColumn();

    if ($existingId) {
        $stmt = db()->prepare('UPDATE lab_price_overrides SET custom_price = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$custom_price, $existingId]);
        return (int) $existingId;
    }
    $stmt = db()->prepare('INSERT INTO lab_price_overrides (lab_id, service_id, custom_price, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$lab_id, $service_id, $custom_price, currentBranchId() ?? 1]);
    return (int) db()->lastInsertId();
}

/** Delete a lab price override by ID */
function deleteLabPriceOverride(int $id): void {
    $stmt = db()->prepare('DELETE FROM lab_price_overrides WHERE id = ?');
    $stmt->execute([$id]);
}

/** Create a monthly/weekly/daily invoice for a lab, grouped by doctor, with +/- amounts */
function createMonthlyLabInvoice(int $labId, array $cases, float $balance, string $invoiceDate, ?int $bankAccountId = null, ?string $periodLabel = null): int {
    $now = date('Y-m-d H:i:s');

    // Invoice number: INV-LAB-YYYYMM-001 (prefix contains dashes → use the shared helper)
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $invoiceNumber = nextInvoiceNumber('doctor_invoices', 'INV-LAB', $yearMonth);

    $total = $balance;
    foreach ($cases as $c) {
        $price = getLabApplicablePrice($labId, (int) $c['service_id']);
        $qty = (int) ($c['quantity'] ?? 1);
        $sign = ($c['case_type'] === 'lab_out') ? -1 : 1;
        $total += $price * $qty * $sign;
    }

    $lab = db()->prepare('SELECT * FROM users WHERE id = ?');
    $lab->execute([$labId]);
    $labUser = $lab->fetch();
    $labBranch = ($labUser['branch_id'] ?? null);
    $labBranch = ($labBranch !== null && $labBranch !== '') ? (int) $labBranch : null;

    $stmt = db()->prepare('
        INSERT INTO doctor_invoices
        (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, notes, bank_account_id, branch_id, created_at)
        VALUES (?, NULL, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?)
    ');
    $notes = 'فاکتور لابراتوار';
    if ($periodLabel !== null && $periodLabel !== '') {
        $notes .= ' — بازه: ' . $periodLabel;
    }
    $stmt->execute([
        $invoiceNumber,
        $labUser['full_name'] ?? 'لابراتوار',
        $total,
        'unpaid',
        $invoiceDate,
        $notes,
        $bankAccountId,
        $labBranch,
        $now
    ]);
    $invoiceId = (int) db()->lastInsertId();

    // Insert items – one per case
    $ins = db()->prepare('INSERT INTO invoice_items (invoice_id, price_id, case_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($cases as $c) {
        $price = getLabApplicablePrice($labId, (int) $c['service_id']);
        $qty = (int) ($c['quantity'] ?? 1);
        $sign = ($c['case_type'] === 'lab_out') ? -1 : 1;
        $doctorName = $c['doctor_name'] ?? '';
        $location = formatCaseLocation($c['location_type'] ?? null, $c['teeth'] ?? null);
        // Description carries doctor name + location for grouping/context
        $desc = trim($doctorName);
        if ($location !== '—') $desc = trim($desc . ' - ' . $location);
        $ins->execute([
            $invoiceId,
            $c['service_id'],
            $c['id'],
            $c['service_title'] ?? 'خدمت',
            $desc ?: null,
            $c['patient_name'],
            $qty,
            $price * $sign,
            round($price * $qty * $sign),
            $now
        ]);
    }

    if ($balance != 0) {
        $stmt = db()->prepare('INSERT INTO invoice_items (invoice_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$invoiceId, 'مانده از قبل', 'مانده انتقالی', '', 1, $balance, $balance, $now]);
    }

    // Mark cases as invoiced
    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }

    return $invoiceId;
}