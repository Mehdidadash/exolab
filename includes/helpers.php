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