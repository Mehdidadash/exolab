<?php

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

    $formatted = number_format((float) $clean, 0, '.', ',');
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
        $jDate = new \Morilog\Jalali\Jd();
        $jalaliDate = $jDate->toJalali($gregorianDate);
        if (is_array($jalaliDate)) {
            return implode('/', $jalaliDate);
        }
        return $jalaliDate;
    } catch (\Throwable $e) {
        return $gregorianDate;
    }
}

function toJalaliDateFormatted($gregorianDate) {
    if (empty($gregorianDate)) return '';
    try {
        $jDate = new \Morilog\Jalali\Jd();
        $jalaliDate = $jDate->toJalali($gregorianDate);
        if (is_array($jalaliDate)) {
            $year = $jalaliDate[0];
            $month = str_pad($jalaliDate[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($jalaliDate[2], 2, '0', STR_PAD_LEFT);
            return toPersianDigits("$year/$month/$day");
        }
        return $jalaliDate;
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
        $jDate = new \Morilog\Jalali\Jd();
        $jalaliDate = $jDate->toJalali($gregorianDate);
        if (is_array($jalaliDate)) {
            $year = $jalaliDate[0];
            $month = $jalaliDate[1];
            $day = $jalaliDate[2];
            return toPersianDigits("$day") . ' ' . $months[$month - 1] . ' ' . toPersianDigits("$year");
        }
        return $jalaliDate;
    } catch (\Throwable $e) {
        return $gregorianDate;
    }
}
