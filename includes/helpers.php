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
    $archHtml = function ($quads) use ($halfHtml, $bridgeHtml) {
        $firstHalf = $halfHtml($quads[0]);
        // Cross-midline bridge between the central incisors (11-21, 41-31)
        $firstHalf .= $bridgeHtml($quads[0][count($quads[0]) - 1], $quads[1][0]);
        return '<div class="ctc-row">'
            . '<div class="ctc-half">' . $firstHalf . '</div>'
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
 * Convert Persian (Farsi) text to a readable Latin transliteration.
 * Used e.g. for ZIP file names so Persian patient names become Latin.
 * Example: "محمد حسینی" => "Mohammad Hosseini".
 *
 * Strategy (much better than pure letter-by-letter rules):
 *   1) Split into words and check a curated dictionary of common Iranian
 *      first/last names — these give the standard correct spellings
 *      ("حسینی" → "Hosseini", not "haseyni"; "محمد" → "Mohammad", not "mahamad").
 *   2) Unknown words fall back to an improved rule-based transliteration.
 * Persian omits most short vowels, so arbitrary names are still best-effort.
 */
function persian_to_finglish($text) {
    static $names = [
        // ── نام‌های کوچک ──
        'محمد' => 'Mohammad', 'محمدرضا' => 'Mohammadreza', 'محمدحسین' => 'Mohammadhossein',
        'محمدعلی' => 'Mohammadali', 'محمدامین' => 'Mohammadamin', 'علی' => 'Ali',
        'علیرضا' => 'Alireza', 'رضا' => 'Reza', 'حسین' => 'Hossein', 'حسن' => 'Hassan',
        'مهدی' => 'Mehdi', 'امیر' => 'Amir', 'امیرحسین' => 'Amirhossein', 'امیرعلی' => 'Amirali',
        'امیرمحمد' => 'Amirmohammad', 'سید' => 'Seyed', 'سعید' => 'Saeid', 'علی‌اکبر' => 'Aliakbar',
        'مرتضی' => 'Morteza', 'مجتبی' => 'Mojtaba', 'مصطفی' => 'Mostafa', 'احمد' => 'Ahmad',
        'حمید' => 'Hamid', 'حمیدرضا' => 'Hamidreza', 'کاظم' => 'Kazem', 'قاسم' => 'Ghasem',
        'عباس' => 'Abbas', 'ناصر' => 'Naser', 'داوود' => 'Davoud', 'حبیب' => 'Habib',
        'رحیم' => 'Rahim', 'کریم' => 'Karim', 'جواد' => 'Javad', 'محسن' => 'Mohsen',
        'فرهاد' => 'Farhad', 'بهرام' => 'Bahram', 'بابک' => 'Babak', 'کامران' => 'Kamran',
        'کیوان' => 'Keyvan', 'امید' => 'Omid', 'آرمان' => 'Arman', 'آرش' => 'Arash',
        'پیمان' => 'Peyman', 'شاهین' => 'Shahin', 'شهاب' => 'Shahab', 'روزبه' => 'Rouzbeh',
        'سیامک' => 'Siamak', 'سهراب' => 'Sohrab', 'سروش' => 'Soroush', 'میلاد' => 'Milad',
        'پارسا' => 'Parsa', 'ایلیا' => 'Ilya', 'آریا' => 'Arya', 'باران' => 'Baran',
        'فاطمه' => 'Fatemeh', 'زهرا' => 'Zahra', 'مریم' => 'Maryam', 'محدثه' => 'Mohaddeseh',
        'معصومه' => 'Masoumeh', 'رقیه' => 'Roghayeh', 'صدیقه' => 'Sedigheh', 'خدیجه' => 'Khadijeh',
        'نرگس' => 'Narges', 'لیلا' => 'Leila', 'سارا' => 'Sara', 'نگار' => 'Negar',
        'شیما' => 'Shima', 'شیدا' => 'Sheida', 'نسیم' => 'Nasim', 'الهام' => 'Elham',
        'مرجان' => 'Marjan', 'مهسا' => 'Mahsa', 'مینا' => 'Mina', 'نازنین' => 'Nazanin',
        'پریسا' => 'Parisa', 'سمیرا' => 'Samira', 'رویا' => 'Roya', 'شیرین' => 'Shirin',
        'آتنا' => 'Athena', 'هانیه' => 'Haniyeh', 'فائزه' => 'Faezeh', 'زینب' => 'Zeynab',
        'طاهره' => 'Tahereh', 'سمیه' => 'Somayeh', 'حمیده' => 'Hamideh', 'آزاده' => 'Azadeh',
        'مرضیه' => 'Marziyeh', 'محبوبه' => 'Mahboobeh', 'فریبا' => 'Fariba', 'فرشته' => 'Fereshteh',
        'مهین' => 'Mahin', 'پروین' => 'Parvin', 'اکرم' => 'Akram', 'اعظم' => 'Azam',
        'بتول' => 'Batoul', 'فرزانه' => 'Farzaneh', 'سمانه' => 'Samaneh', 'مونا' => 'Mona',
        'دنیا' => 'Donya', 'سحر' => 'Sahar', 'یاسمن' => 'Yasaman',
        // ── نام خانوادگی ──
        'حسینی' => 'Hosseini', 'حسینیان' => 'Hosseinian', 'محمدی' => 'Mohammadi',
        'محمدیان' => 'Mohammadian', 'رضایی' => 'Rezaei', 'رضوی' => 'Razavi',
        'احمدی' => 'Ahmadi', 'قاسمی' => 'Ghasemi', 'کاظمی' => 'Kazemi', 'موسوی' => 'Mousavi',
        'حیدری' => 'Heydari', 'نوری' => 'Nouri', 'کریمی' => 'Karimi', 'عباسی' => 'Abbasi',
        'جعفری' => 'Jafari', 'اکبری' => 'Akbari', 'صادقی' => 'Sadeghi', 'قربانی' => 'Ghorbani',
        'محمدزاده' => 'Mohammadzadeh', 'حسین‌زاده' => 'Hosseinzadeh', 'زاده' => 'Zadeh',
        'نادری' => 'Naderi', 'کمالی' => 'Kamali', 'شفیعی' => 'Shafiei', 'صالحی' => 'Salehi',
        'عباس‌نژاد' => 'Abbasnejad', 'نژاد' => 'Nejad', 'پور' => 'Pour', 'مقدم' => 'Moghaddam',
        'تقوی' => 'Taghavi', 'روحانی' => 'Rouhani', 'سلیمانی' => 'Soleimani',
        'مرادی' => 'Moradi', 'محمدپور' => 'Mohammadpour', 'رحیمی' => 'Rahimi',
        'یزدانی' => 'Yazdani', 'میرزایی' => 'Mirzaei', 'صادق‌زاده' => 'Sadeghzadeh',
        'امینی' => 'Amini', 'بابایی' => 'Babaei', 'جهانی' => 'Jahani', 'شفیع‌زاده' => 'Shafiezadeh',
        'غفاری' => 'Ghaffari', 'فلاحی' => 'Falahi', 'قلی‌زاده' => 'Gholizadeh',
        'طاهری' => 'Taheri', 'مظاهری' => 'Mazaheri', 'صفری' => 'Safari', 'کوهی' => 'Kouhi',
        'رستمی' => 'Rostami', 'صمدی' => 'Samadi', 'زارعی' => 'Zarei', 'مهدوی' => 'Mahdavi',
        'دبیری' => 'Dabiri', 'شریفی' => 'Sharifi', 'بهرامی' => 'Bahrami', 'فرجی' => 'Faraji',
        'نجفی' => 'Najafi', 'هاشمی' => 'Hashemi', 'حاجی' => 'Haji', 'پناهی' => 'Panahi',
        'خدایی' => 'Khodaei', 'علی‌پور' => 'Alipour', 'حسین‌پور' => 'Hosseinpour',
        'قنبری' => 'Ghanbari', 'سلطانی' => 'Soltani', 'عظیمی' => 'Azimi', 'گودرزی' => 'Goudarzi',
        'کیانی' => 'Kiani', 'مجیدی' => 'Majidi', 'یوسفی' => 'Yousefi', 'رحمانی' => 'Rahmani',
        'شاه‌حسینی' => 'Shahhosseini', 'ترکاشوند' => 'Torkashvand', 'رحمتی' => 'Rahmati',
        'مومنی' => 'Momeni', 'براتی' => 'Barati', 'معصومی' => 'Masoumi', 'داودی' => 'Davoudi',
        'شمس' => 'Shams', 'علیزاده' => 'Alizadeh', 'خلیلی' => 'Khalili', 'مصطفوی' => 'Mostafavi',
        'یعقوبی' => 'Yaghoubi', 'صفرزاده' => 'Safarzadeh', 'بهاری' => 'Bahari',
        'گل‌محمدی' => 'Golmohammadi', 'عظیم‌زاده' => 'Azimzadeh', 'ملکی' => 'Maleki',
        'قاسم‌پور' => 'Ghasempour', 'کرمی' => 'Karami', 'حقیقی' => 'Haghighi', 'فتحی' => 'Fathi',
        'باقری' => 'Bagheri', 'خسروی' => 'Khosravi', 'پورمند' => 'Pourmand', 'نجاری' => 'Najjari',
        'مظفری' => 'Mozaffari', 'شجاعی' => 'Shojaei', 'همتی' => 'Hemmati', 'رئیسی' => 'Raeisi',
        'جمشیدی' => 'Jamshidi', 'نعمتی' => 'Nematy', 'کبیری' => 'Kabiri', 'ایزدی' => 'Izadi',
        'پورمحمدی' => 'Pourmohammadi', 'آرزوبخش' => 'Arzoubakhsh', 'میرجوادی' => 'Mirjavadi',
        'یگانه' => 'Yeganeh', 'ساجدی' => 'Sajedi', 'خطیب' => 'Khatib', 'شیخی' => 'Sheikhi',
        'حسینی‌نژاد' => 'Hosseininejad',
        // ── کلمات متداول ──
        'خانم' => 'Khanom', 'خانوم' => 'Khanom', 'آقای' => 'Aghay', 'بیمار' => 'Bimar',
    ];
    // حذف نشانه‌ها و نیم‌فاصله
    $text = trim((string) $text);
    $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}\x{200C}]/u', '', $text);

    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $outParts = [];
    foreach ($words as $w) {
        if (isset($names[$w])) {
            $outParts[] = $names[$w];
            continue;
        }
        $outParts[] = ucfirst(persian_word_to_finglish_fallback($w));
    }
    return implode(' ', $outParts);
}

/** Improved rule-based fallback for a single Persian word (بدون فاصله). */
function persian_word_to_finglish_fallback(string $word): string {
    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
    $out = '';
    $lastVowel = true;
    $wordStart = true;
    $vowelLetters = ['ا', 'آ', 'و', 'ی'];
    $consonantMap = [
        'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'ch', 'ح' => 'h',
        'خ' => 'kh', 'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's',
        'ش' => 'sh', 'ص' => 's', 'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'غ' => 'gh', 'ف' => 'f',
        'ق' => 'gh', 'ک' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h',
        'و' => 'v', 'ی' => 'y',
    ];
    $vowelMap = ['ا' => 'a', 'آ' => 'a', 'ء' => '', 'ة' => 'h'];
    foreach ($chars as $i => $ch) {
        $next = $chars[$i + 1] ?? '';
        $prev = $chars[$i - 1] ?? '';
        if ($ch === 'ع') {
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
            if ($wordStart || in_array($next, $vowelLetters, true)) {
                $seg = 'v';
                $isV = false;
            } else {
                $seg = 'ou';
                $isV = true;
            }
        } elseif ($ch === 'ی') {
            if ($wordStart || in_array($next, $vowelLetters, true)) {
                $seg = 'y';
                $isV = false;
            } elseif ($prev !== '' && !in_array($prev, $vowelLetters, true) && $next !== '' && !in_array($next, $vowelLetters, true)) {
                $seg = 'ey';
                $isV = true;
            } else {
                $seg = 'i';
                $isV = true;
            }
        } elseif (isset($vowelMap[$ch])) {
            $seg = $vowelMap[$ch];
            $isV = true;
        } elseif (isset($consonantMap[$ch])) {
            $seg = $consonantMap[$ch];
            $isV = false;
        } else {
            $out .= $ch;
            $lastVowel = true;
            $wordStart = true;
            continue;
        }
        if (!$isV && !$lastVowel && $out !== '') {
            $out .= 'a';
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
 * تاریخ شمسی با ارقام لاتین و خط تیره برای نام فایل (مثلاً: 1405-06-20).
 */
function jalaliDateForFilename($gregorianDate): string {
    if (empty($gregorianDate)) return '';
    try {
        $date = new DateTime($gregorianDate);
        return \Morilog\Jalali\Jalalian::fromDateTime($date)->format('Y-m-d');
    } catch (\Throwable $e) {
        return (string) $gregorianDate;
    }
}

/**
 * رنگ‌های استاندارد سایه (VITA/Bleach). فقط همین‌ها در فرم کیس قابل انتخاب‌اند؛
 * سایه‌های غیراستاندارد (مثل نوریتاکه NW0/NW0.5) باید در «توضیحات» نوشته شوند.
 */
function caseShadeOptions(): array {
    return [
        'A1'   => '#d7cdc1', 'A2'   => '#d5c8b8', 'A3'   => '#d1bea9',
        'A3.5' => '#ccb499', 'A4'   => '#c7aa89',
        'B1'   => '#dad4c3', 'B2'   => '#d8d1ba', 'B3'   => '#cec29c', 'B4' => '#cebf92',
        'C1'   => '#cac5be', 'C2'   => '#c9c3ba', 'C3'   => '#b9afa2', 'C4' => '#b4a897',
        'D2'   => '#d1c5bd', 'D3'   => '#c8b8ac', 'D4'   => '#cdbdb1',
        '0M1'  => '#e8e6e3', '0M2'  => '#e1dfdb', '0M3'  => '#dbd8d1', '0M4' => '#d8d2ca',
        'BL1'  => '#f0f0ef', 'BL2'  => '#eae9e6', 'BL3'  => '#e3e1de', 'BL4' => '#dddad5',
    ];
}

/** رنگ مربوط به یک کد سایه (یا رشتهٔ خالی اگر استاندارد نباشد). */
function caseShadeColor(?string $code): string {
    if ($code === null || trim($code) === '') return '';
    $opts = caseShadeOptions();
    return $opts[strtoupper(trim($code))] ?? '';
}

/**
 * گروه‌های رنگ سایه — هر گروه یک ردیف جداگانه در فرم (A / B / C / D / 0M / BL).
 */
function caseShadeGroups(): array {
    $all = caseShadeOptions();
    $order = [
        'A'  => ['A1', 'A2', 'A3', 'A3.5', 'A4'],
        'B'  => ['B1', 'B2', 'B3', 'B4'],
        'C'  => ['C1', 'C2', 'C3', 'C4'],
        'D'  => ['D2', 'D3', 'D4'],
        '0M' => ['0M1', '0M2', '0M3', '0M4'],
        'BL' => ['BL1', 'BL2', 'BL3', 'BL4'],
    ];
    $groups = [];
    foreach ($order as $name => $codes) {
        $row = [];
        foreach ($codes as $c) {
            if (isset($all[$c])) $row[$c] = $all[$c];
        }
        if ($row) $groups[$name] = $row;
    }
    return $groups;
}

/**
 * Human-readable file size (e.g. "۱.۲ مگابایت").
 */
function formatFileSize($bytes): string {
    $bytes = (int) $bytes;
    if ($bytes >= 1073741824) return toPersianDigits(round($bytes / 1073741824, 1)) . ' گیگابایت';
    if ($bytes >= 1048576)   return toPersianDigits(round($bytes / 1048576, 1)) . ' مگابایت';
    if ($bytes >= 1024)      return toPersianDigits(round($bytes / 1024, 1)) . ' کیلوبایت';
    return toPersianDigits((string) $bytes) . ' بایت';
}

/**
 * محدودیت‌های آپلود بر اساس تنظیمات PHP (به بایت / تعداد).
 * برای نمایش به کاربر و بررسی قبل از ارسال استفاده می‌شود.
 */
function uploadLimits(): array {
    $toBytes = function ($v): int {
        $v = trim((string) $v);
        if ($v === '' || $v === '-1') return 0;   // ۰ = بدون محدودیت
        $unit = strtolower(substr($v, -1));
        $num = (float) $v;
        if ($unit === 'g') $num *= 1073741824;
        elseif ($unit === 'm') $num *= 1048576;
        elseif ($unit === 'k') $num *= 1024;
        return (int) $num;
    };
    return [
        'max_file'  => $toBytes(ini_get('upload_max_filesize')),
        'post_max'  => $toBytes(ini_get('post_max_size')),
        'max_files' => (int) (ini_get('max_file_uploads') ?: 20),
    ];
}

/**
 * پیام فارسیِ قابل‌فهم برای کدهای خطای آپلود (فایل‌های کیس / آپلودهای کاربر).
 * کدها در upload_case_files.php ساخته می‌شوند: upload_error_{idx}_{phpCode} و …
 */
function uploadErrorLabel(string $code): string {
    if (preg_match('/^upload_error_(\d+)_(\d+)$/', $code, $m)) {
        $which = 'فایل شماره ' . toPersianDigits((string) ((int) $m[1] + 1)) . ': ';
        $label = match ((int) $m[2]) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل از محدودیت سرور بیشتر است.',
            UPLOAD_ERR_PARTIAL => 'فایل کامل ارسال نشد (اتصال قطع شد یا حجم/زمان زیاد بود). دوباره تلاش کنید؛ برای چند فایل، گزینهٔ ZIP را فعال کنید.',
            UPLOAD_ERR_NO_FILE => 'فایلی انتخاب نشده بود.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقتِ سرور در دسترس نیست (با پشتیبانی هاست تماس بگیرید).',
            UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی سرور ناموفق بود.',
            UPLOAD_ERR_EXTENSION => 'آپلود توسط یکی از افزونه‌های سرور متوقف شد.',
            default => 'خطای نامشخص در آپلود (کد ' . toPersianDigits((string) (int) $m[2]) . ').',
        };
        return $which . $label;
    }
    if (preg_match('/^ext_not_allowed_(.+)$/', $code, $m)) {
        return 'پسوند «' . $m[1] . '» مجاز نیست.';
    }
    if (str_starts_with($code, 'move_failed')) {
        return 'انتقال فایل روی سرور ناموفق بود (دسترسی پوشه را بررسی کنید).';
    }
    return match ($code) {
        'db_insert_error'   => 'فایل روی سرور ذخیره شد اما ثبت آن در دیتابیس ناموفق بود.',
        'cannot_create_dir' => 'ساخت پوشهٔ آپلود ناموفق بود (دسترسی پوشه را بررسی کنید).',
        'zip_open_failed'   => 'ساخت فایل ZIP ناموفق بود؛ بدون ZIP دوباره تلاش کنید.',
        default             => 'خطا: ' . $code,
    };
}

/**
 * Config for the «نوع فایل» selector on uploads (options + badge colors).
 */
function caseFileTypeConfig(): array {
    $options = [
        'raw_scan'      => 'اسکن خام',
        'final_design'  => 'طراحی نهایی',
        'patient_photo' => 'عکس بیمار',
        'design_html'   => 'HTML طراحی',
        'other'         => 'سایر',
    ];
    $badge = [
        'raw_scan'      => ['اسکن خام', '#e0f2fe', '#0369a1'],
        'final_design'  => ['طراحی نهایی', '#dcfce7', '#166534'],
        'patient_photo' => ['عکس بیمار', '#fef3c7', '#92400e'],
        'design_html'   => ['HTML طراحی', '#ede9fe', '#5b21b6'],
        'other'         => ['سایر', '#f3f4f6', '#374151'],
    ];
    return ['options' => $options, 'badge' => $badge];
}

/** Default «نوع فایل» بر اساس نقش: طراح → طراحی نهایی؛ بقیه → اسکن خام. */
function caseFileTypeDefault(array $user): string {
    $isDesigner = (($user['role'] ?? '') === 'designer') || !empty($user['is_designer']);
    return $isDesigner ? 'final_design' : 'raw_scan';
}

/** Badge «نوع فایل» برای نمایش: اول نوع ذخیره‌شده، وگرنه تشخیص خودکار از پسوند. */
function caseFileBadge(?string $fileType, string $ext): string {
    $cfg = caseFileTypeConfig();
    if ($fileType && isset($cfg['badge'][$fileType])) {
        [$label, $bg, $color] = $cfg['badge'][$fileType];
        return '<span style="background:' . $bg . '; color:' . $color . '; border-radius:6px; padding:0 6px; white-space:nowrap;">' . $label . '</span>';
    }
    $map = [
        'stl' => ['اسکن/مدل', '#dcfce7', '#166534'], 'ply' => ['اسکن/مدل', '#dcfce7', '#166534'],
        'stp' => ['مدل سه‌بعدی', '#e0e7ff', '#3730a3'], 'step' => ['مدل سه‌بعدی', '#e0e7ff', '#3730a3'],
        'obj' => ['مدل سه‌بعدی', '#e0e7ff', '#3730a3'], '3mf' => ['مدل سه‌بعدی', '#e0e7ff', '#3730a3'],
        'jpg' => ['تصویر/کنترل', '#fef9c3', '#854d0e'], 'jpeg' => ['تصویر/کنترل', '#fef9c3', '#854d0e'],
        'png' => ['تصویر/کنترل', '#fef9c3', '#854d0e'], 'gif' => ['تصویر/کنترل', '#fef9c3', '#854d0e'],
        'webp' => ['تصویر/کنترل', '#fef9c3', '#854d0e'], 'bmp' => ['تصویر/کنترل', '#fef9c3', '#854d0e'],
        'zip' => ['بایگانی', '#f3f4f6', '#374151'], 'rar' => ['بایگانی', '#f3f4f6', '#374151'],
    ];
    $t = $map[strtolower((string) $ext)] ?? ['سایر', '#f3f4f6', '#374151'];
    return '<span style="background:' . $t[1] . '; color:' . $t[2] . '; border-radius:6px; padding:0 6px; white-space:nowrap;">' . $t[0] . '</span>';
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

// =====================================================
// Case Activity Log Helpers (لاگ فعالیت‌های هر کیس)
// =====================================================
/**
 * ثبت رویداد روی یک کیس (ایجاد، ویرایش، آپلود/دانلود فایل، مشاهده، کامنت، تغییر وضعیت و...).
 * جزئیات می‌تواند رشته‌ی ساده یا JSON باشد.
 */
function log_case_activity(int $caseId, string $action, ?string $details = null): void
{
    if ($caseId <= 0) return;
    try {
        $userId = null;
        if (function_exists('current_user')) {
            $user = current_user();
            $userId = $user['id'] ?? null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = db()->prepare('INSERT INTO case_activity_log (case_id, user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$caseId, $userId, $action, $details, $ip]);
    } catch (\Throwable $e) {
        // لاگ نباید جریان اصلی را بشکند
        error_log('case_activity_log: ' . $e->getMessage());
    }
}

/** ردیف‌های لاگ یک کیس (جدیدترین اول). */
function getCaseActivityLog(int $caseId): array
{
    $stmt = db()->prepare('SELECT l.*, u.full_name AS user_name FROM case_activity_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.case_id = ? ORDER BY l.id DESC LIMIT 500');
    $stmt->execute([$caseId]);
    return $stmt->fetchAll();
}

/**
 * آدرس پایهٔ عمومیِ برنامه (بدون اسلش انتهایی) — برای ساخت لینک‌های مطلق مثل QR روی برچسب.
 *   لوکال (XAMPP):  http://localhost/exolab
 *   روی هاست:        https://exolab.ir
 * ترتیب تشخیص: مقدار APP_URL در .env (اگر کامل داده شده باشد) → تشخیص خودکار از
 * هدرهای درخواست + محلِ همین فایلِ اجراشده (پوشهٔ panel چسبیده به ریشهٔ برنامه).
 */
function appBaseUrl(): string
{
    if (class_exists('EnvLoader')) {
        EnvLoader::load();
        $configured = trim((string) EnvLoader::get('APP_URL', ''));
        if ($configured !== '') return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') $host = 'localhost';

    // مسیر برنامه: از محل اسکریپت جاری محاسبه می‌شود تا هم /exolab (لوکال) و هم / (هاست) درست باشد.
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $root = $script !== '' ? dirname(dirname($script)) : '';
    if ($root === '/' || $root === '.' || $root === '\\') $root = '';

    return $scheme . '://' . $host . $root;
}