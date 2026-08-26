<?php

require_once __DIR__ . '/icons.php';

function toPersianDigits($input) {
    $numbers = ['0','1','2','3','4','5','6','7','8','9'];
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($numbers, $persian, $input);
}

function formatAmountToman($value) {
    $clean = preg_replace('/[^\d\.\-]/', '', (string) $value);
    if ($clean === '' || !is_numeric($clean)) {
        return htmlspecialchars((string) $value);
    }

    $formatted = number_format((float) round($clean), 0, '.', ',');
    $formatted = str_replace(',', '٬', $formatted);
    return toPersianDigits($formatted) . ' تومان';
}

/**
 * Integer string of a toman amount for use inside <input> value attributes.
 * Prices never need decimals, so "1000000.00" → "1000000".
 */
function formatTomanInput($value) {
    if ($value === '' || $value === null) return '';
    return number_format((float) $value, 0, '.', '');
}

/**
 * Read-only dental chart for the VIEW-CASE page: renders the standard 32-tooth
 * chart and highlights the teeth selected on the case (and bridge connectors).
 * $teeth uses the same format as the picker, e.g. "26,42_43_44_45" or "11,12".
 */
function renderTeethChart($teeth) {
    $arches = [
        'upper' => [['18','17','16','15','14','13','12','11'], ['21','22','23','24','25','26','27','28']],
        'lower' => [['48','47','46','45','44','43','42','41'], ['31','32','33','34','35','36','37','38']],
    ];

    $selected = [];
    $bridges = [];
    $raw = str_replace('،', ',', (string) $teeth);
    foreach (explode(',', $raw) as $group) {
        $group = trim($group);
        if ($group === '') continue;
        $parts = array_filter(array_map('trim', explode('_', $group)), fn($t) => $t !== '');
        foreach ($parts as $t) {
            $n = (int) $t;
            if ($n > 0) $selected[$n] = true;
        }
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $a = (int) $parts[$i];
            $b = (int) $parts[$i + 1];
            if ($a > 0 && $b > 0) $bridges[min($a, $b) . '-' . max($a, $b)] = true;
        }
    }

    $toothHtml = function ($t) use ($selected) {
        $sel = isset($selected[(int) $t]);
        $bg = $sel ? '#06b6d4' : '#ffffff';
        $color = $sel ? '#ffffff' : '#334155';
        return '<span style="display:inline-flex;flex-direction:column;align-items:center;gap:2px;min-width:30px;">'
            . '<span style="width:26px;height:26px;border:1px solid #cbd5e1;border-radius:50%;background:' . $bg . ';color:' . $color
            . ';font-size:11px;font-weight:' . ($sel ? '700' : '400') . ';display:flex;align-items:center;justify-content:center;">' . $t . '</span>'
            . '<span style="font-size:9px;color:#475569;">' . $t . '</span>'
            . '</span>';
    };
    $bridgeHtml = function ($a, $b) use ($bridges) {
        $active = isset($bridges[min($a, $b) . '-' . max($a, $b)]);
        $bg = $active ? '#d97706' : '#ffffff';
        $bd = $active ? '#b45309' : '#94a3b8';
        return '<span style="display:inline-block;width:11px;height:16px;border:1px solid ' . $bd . ';border-radius:3px;background:' . $bg . ';margin:0 1px;align-self:flex-end;"></span>';
    };
    $halfHtml = function ($quad) use ($toothHtml, $bridgeHtml) {
        $h = '';
        foreach ($quad as $i => $t) {
            $h .= $toothHtml($t);
            if ($i < count($quad) - 1) $h .= $bridgeHtml($t, $quad[$i + 1]);
        }
        return $h;
    };
    $archHtml = function ($quads) use ($halfHtml) {
        return '<div class="ctc-row">'
            . '<div class="ctc-half">' . $halfHtml($quads[0]) . '</div>'
            . '<div class="ctc-midline"></div>'
            . '<div class="ctc-half">' . $halfHtml($quads[1]) . '</div>'
            . '</div>';
    };

    return '<div class="case-teeth-chart">'
        . '<div class="ctc-arch-label">فک بالا</div>'
        . $archHtml($arches['upper'])
        . '<div class="ctc-arch-label">فک پایین</div>'
        . $archHtml($arches['lower'])
        . '</div>';
}

function normalizePersianDigits($input) {
    $numbers = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $latin = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($numbers, $latin, trim($input));
}

function parseDateInput($input) {
    $value = normalizePersianDigits($input);
    if ($value === '') {
        return '';
    }

    $value = str_replace(['.', '\\', '-', ' '], '/', $value);
    if (!preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $value)) {
        return '';
    }

    [$year, $month, $day] = explode('/', $value);
    if ((int) $year >= 1300 && (int) $year <= 1500) {
        try {
            $jalali = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $value);
            return $jalali->toCarbon()->toDateString();
        } catch (Throwable $e) {
            return '';
        }
    }

    try {
        $date = new DateTime($value);
        return $date->format('Y-m-d');
    } catch (Throwable $e) {
        return '';
    }
}

function toJalaliDate($gregorianDate) {
    if (empty($gregorianDate)) return '';
    try {
        $date = new DateTime($gregorianDate);
        $jalaliDate = \Morilog\Jalali\Jalalian::fromDateTime($date);
        return $jalaliDate->format('Y/m/d');
    } catch (\Throwable $e) {
        return $gregorianDate;
    }
}

/**
 * Convert Persian (Farsi) text to a readable Finglish (Latin) transliteration.
 * Used e.g. for ZIP file names so Persian patient names become Latin.
 * Example: "خانوم حیدری" => "khanoum heydari".
 * This is a best-effort approximation (Persian omits most short vowels).
 */
function persian_to_finglish($text) {
    $text = trim((string) $text);
    $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}\x{200C}]/u', '', $text);
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $out = '';
    $lastVowel = true;          // avoid inserting a short vowel at word start
    $wordStart = true;
    $vowelLetters = ['ا', 'آ', 'و', 'ی'];
    $consonantMap = [
        'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd',
        'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'z', 'ط' => 't',
        'ظ' => 'z', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'gh', 'ک' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
        'ه' => 'h', 'و' => 'v', 'ی' => 'y',
    ];
    $vowelMap = ['ا' => 'a', 'آ' => 'a', 'ء' => '', 'ة' => 'h'];
    foreach ($chars as $i => $ch) {
        $next = $chars[$i + 1] ?? '';
        $prev = $chars[$i - 1] ?? '';

        if ($ch === 'ع') {
            // Word-initial ع carries the "a" vowel; otherwise silent but acts as a vowel.
            if ($wordStart) {
                $out .= 'a';
                $lastVowel = true;
            } else {
                $lastVowel = true;
            }
            $wordStart = false;
            continue;
        }
        if ($ch === 'و') {
            // Consonant "v" at word start or before a vowel letter; otherwise vowel "ou".
            if ($wordStart || in_array($next, $vowelLetters, true)) { $seg = 'v'; $isV = false; }
            else { $seg = 'ou'; $isV = true; }
        } elseif ($ch === 'ی') {
            if ($wordStart || in_array($next, $vowelLetters, true)) { $seg = 'y'; $isV = false; }
            elseif ($prev !== '' && !in_array($prev, $vowelLetters, true) && $next !== '' && !in_array($next, $vowelLetters, true)) { $seg = 'ey'; $isV = true; }
            else { $seg = 'i'; $isV = true; }
        } elseif (isset($vowelMap[$ch])) {
            $seg = $vowelMap[$ch];
            $isV = true;
        } elseif (isset($consonantMap[$ch])) {
            $seg = $consonantMap[$ch];
            $isV = false;
        } else {
            // Space or punctuation: keep as-is, reset word state.
            $out .= $ch;
            $lastVowel = true;
            $wordStart = true;
            continue;
        }
        if (!$isV && !$lastVowel && $out !== '') {
            $out .= 'a'; // insert a short "a" between consecutive consonants for readability
        }
        $out .= $seg;
        $lastVowel = $isV;
        $wordStart = false;
    }
    return $out;
}

function toJalaliDateFormatted($gregorianDate) {
    if (empty($gregorianDate)) return '';
    try {
        $date = new DateTime($gregorianDate);
        $jalaliDate = \Morilog\Jalali\Jalalian::fromDateTime($date);
        return toPersianDigits($jalaliDate->format('Y/m/d'));
    } catch (\Throwable $e) {
        return $gregorianDate;
    }
}

/**
 * Human-readable elapsed time (e.g. "۲ ساعت و ۱۵ دقیقه پیش").
 * Used to show how long ago scan files were added / how long a case is waiting.
 * Returns '' on invalid input.
 */
function formatElapsedTime($datetime) {
    if (empty($datetime)) return '';
    try {
        $dt = new DateTime($datetime);
    } catch (\Throwable $e) {
        return '';
    }
    $now = new DateTime();
    $diff = $now->diff($dt);
    if ($diff->invert) return ''; // future date → treat as just now

    $days = (int) $diff->days;
    $hours = (int) $diff->h;
    $mins = (int) $diff->i;

    if ($days >= 365) {
        $y = intdiv($days, 365);
        return toPersianDigits((string) $y) . ' سال پیش';
    }
    if ($days >= 30) {
        $m = intdiv($days, 30);
        return toPersianDigits((string) $m) . ' ماه پیش';
    }
    if ($days >= 1) {
        return toPersianDigits((string) $days) . ' روز و ' . toPersianDigits((string) $hours) . ' ساعت پیش';
    }
    if ($hours >= 1) {
        return toPersianDigits((string) $hours) . ' ساعت و ' . toPersianDigits((string) $mins) . ' دقیقه پیش';
    }
    if ($mins >= 1) {
        return toPersianDigits((string) $mins) . ' دقیقه پیش';
    }
    return 'لحظاتی پیش';
}

/**
 * Jalali date + time for display (e.g. "۱۴۰۵/۰۵/۲۸ - ۱۴:۳۰").
 */
function toJalaliDateTimeFormatted($gregorianDate) {
    if (empty($gregorianDate)) return '';
    try {
        $date = new DateTime($gregorianDate);
        $jalaliDate = \Morilog\Jalali\Jalalian::fromDateTime($date);
        $time = $date->format('H:i');
        return toPersianDigits($jalaliDate->format('Y/m/d')) . ' - ' . toPersianDigits($time);
    } catch (\Throwable $e) {
        return $gregorianDate;
    }
}

function toJalaliDateWithMonth($gregorianDate) {
    if (empty($gregorianDate)) return '';
    $months = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
    ];
    try {
        $date = new DateTime($gregorianDate);
        $jalaliDate = \Morilog\Jalali\Jalalian::fromDateTime($date);
        $year = $jalaliDate->getYear();
        $month = $jalaliDate->getMonth();
        $day = $jalaliDate->getDay();
        return toPersianDigits((string) $day) . ' ' . $months[$month - 1] . ' ' . toPersianDigits((string) $year);
    } catch (\Throwable $e) {
        return $gregorianDate;
    }
}

function formatCaseLocation($locationType, $teeth)
{
    $map = [
        'teeth' => 'دندان',
        'upper' => 'فک بالا',
        'lower' => 'فک پایین',
        'both' => 'هر دو',
    ];

    if (empty($locationType)) {
        return $teeth ? htmlspecialchars($teeth) : '—';
    }

    $label = $map[$locationType] ?? htmlspecialchars($locationType);
    if (!empty($teeth)) {
        return $label . ' (' . htmlspecialchars($teeth) . ')';
    }

    return $label;
}
function parseJalaliToGregorian($jalaliDate) {
    $value = trim($jalaliDate);
    if ($value === '') return '';

    // Convert Persian digits to Latin
    $value = normalizePersianDigits($value);

    // Remove any non-digit characters (except maybe allow slashes? but we'll just extract numbers)
    preg_match_all('/\d+/', $value, $matches);
    $numbers = $matches[0] ?? [];
    if (count($numbers) < 3) {
        // Not enough numbers; try using parseDateInput as fallback
        $fallback = parseDateInput($value);
        if ($fallback !== '') return $fallback;
        return '';
    }

    // Take first three numbers as year, month, day
    list($year, $month, $day) = array_slice($numbers, 0, 3);
    $month = str_pad($month, 2, '0', STR_PAD_LEFT);
    $day = str_pad($day, 2, '0', STR_PAD_LEFT);
    $dateStr = $year . '/' . $month . '/' . $day;

    // If year is between 1300 and 1500, treat as Jalali
    if ($year >= 1300 && $year <= 1500) {
        try {
            // Use the library to convert
            $jalali = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $dateStr);
            return $jalali->toCarbon()->toDateString(); // returns YYYY-MM-DD
        } catch (\Throwable $e) {
            // Log error if possible
            if (function_exists('error_log')) {
                error_log('Jalali conversion failed for: ' . $dateStr . ' - ' . $e->getMessage());
            }
            return '';
        }
    } else {
        // Assume Gregorian
        try {
            $dt = new DateTime($dateStr);
            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }
}

// =====================================================
// CSRF Protection Helpers
// =====================================================
function csrf_token() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

function csrf_meta() {
    return '<meta name="csrf-token" content="' . csrf_token() . '">';
}

function verify_csrf($token = null) {
    if ($token === null) {
        $token = $_POST['_csrf_token'] ?? '';
        // Also check X-CSRF-Token header (hosting security filters may strip POST field)
        if (empty($token)) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
    }
    if (empty($_SESSION['_csrf_token']) || !hash_equals($_SESSION['_csrf_token'], $token)) {
        return false;
    }
    return true;
}

function require_csrf() {
    if (!verify_csrf()) {
        http_response_code(403);
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'csrf_invalid', 'message' => 'CSRF token نامعتبر است. لطفاً صفحه را Refresh کنید.']);
        } else {
            die('CSRF token نامعتبر است. لطفاً صفحه را Refresh کنید.');
        }
        exit;
    }
}

// =====================================================
// Audit Logging Helpers
// =====================================================
function audit_log(string $action, string $entityType = null, int $entityId = null, string $details = null): void
{
    $userId = null;
    if (function_exists('current_user')) {
        $user = current_user();
        $userId = $user['id'] ?? null;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $stmt = db()->prepare(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$userId, $action, $entityType, $entityId, $details, $ip]);
}

function audit_log_save(string $entityType, int $entityId, string $label): void
{
    $action = isset($_POST['id']) && !empty($_POST['id']) ? 'update' : 'create';
    audit_log("{$action}_{$entityType}", $entityType, $entityId, "{$label} #{$entityId}");
}

function audit_log_delete(string $entityType, int $entityId, string $label): void
{
    audit_log("delete_{$entityType}", $entityType, $entityId, "حذف {$label} #{$entityId}");
}