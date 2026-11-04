<?php
// includes/price_tabs.php
// منطقِ مشترکِ «تب‌های نرخ اختصاصی» در prices.php
//
// هر تب یک «نوع طرفِ حساب» را نشان می‌دهد:
//   designers → طراح‌ها      (price_type = design_fee, provider_type = designer)
//   doctors   → پزشکان       (price_type = specific,   provider_type = branch, receiver_type = doctor)
//   clinics   → کلینیک‌ها     (price_type = specific,   provider_type = branch, receiver_type = doctor)
//   labs      → لابراتوارها   (price_type = outsource,  provider_type = lab,    receiver_type = branch)
//   branches  → نرخ بین‌شعبه‌ای (provider/receiver = branch)
//
// ⚠️ Semantics (طبق مستنداتِ price_links):
//   provider = انجام‌دهندهٔ کار (طرفی که کار را انجام می‌دهد)
//   receiver = پرداخت‌کننده
//   • specific : ما از پزشک/کلینیک می‌گیریم → provider=branch (شعبهٔ ما) ، receiver=doctor
//   • outsource: ما به لابراتوار می‌پردازیم → provider=lab ، receiver=branch (شعبهٔ ما)
//   • design_fee: پرداخت به طراح → provider=designer ، receiver=branch
//   • بین‌شعبه‌ای: provider=شعبهٔ انجام‌دهنده ، receiver=شعبهٔ پرداخت‌کننده

if (!function_exists('priceTabKinds')) {
    /**
     * تعریفِ تب‌ها: هر تب نوعِ طرفِ حساب، برچسب‌ها و پارامترهای price_links.
     * @return array<string, array>
     */
    function priceTabKinds(): array
    {
        return [
            'designers' => [
                'title'         => 'نرخ طراحی طراحان',
                'entityLabel'   => 'طراح',
                'priceType'     => 'design_fee',
                'providerType'  => 'designer',
                'receiverType'  => 'branch',       // شعبه‌ای که می‌پردازد
                'receiverFixedToMyBranch' => true,
                'amountLabel'   => 'فی طراحی',
                'color'         => '#d97706',
            ],
            'doctors' => [
                'title'         => 'نرخ پزشکان',
                'entityLabel'   => 'پزشک',
                'priceType'     => 'specific',
                'providerType'  => 'branch',       // شعبه = انجام‌دهنده
                'receiverType'  => 'doctor',       // پزشک = پرداخت‌کننده
                'providerFixedToMyBranch' => true,
                'amountLabel'   => 'فی خدمت',
                'color'         => '#059669',
            ],
            'clinics' => [
                'title'         => 'نرخ کلینیک‌ها',
                'entityLabel'   => 'کلینیک',
                'priceType'     => 'specific',
                'providerType'  => 'branch',
                'receiverType'  => 'doctor',       // کلینیک هم مثل پزشک در users ذخیره می‌شود
                'providerFixedToMyBranch' => true,
                'amountLabel'   => 'فی خدمت',
                'color'         => '#0d9488',
            ],
            'labs' => [
                'title'         => 'نرخ لابراتوارها',
                'entityLabel'   => 'لابراتوار',
                'priceType'     => 'outsource',
                'providerType'  => 'lab',          // لابراتوار = انجام‌دهنده
                'receiverType'  => 'branch',       // شعبه = پرداخت‌کننده
                'receiverFixedToMyBranch' => true,
                'amountLabel'   => 'نرخ برون‌سپاری',
                'color'         => '#0891b2',
            ],
            'branches' => [
                'title'         => 'نرخ بین‌شعبه‌ای',
                'entityLabel'   => 'شعبه',
                'priceType'     => 'outsource',
                'providerType'  => 'branch',       // انجام‌دهنده (شعبه‌ای که کار را انجام می‌دهد)
                'receiverType'  => 'branch',       // پرداخت‌کننده
                'amountLabel'   => 'نرخ',
                'color'         => '#6366f1',
            ],
        ];
    }
}

if (!function_exists('priceTabKind')) {
    /** تعریفِ یک تب (یا null). */
    function priceTabKind(string $key): ?array
    {
        return priceTabKinds()[$key] ?? null;
    }
}

if (!function_exists('priceTabIsAllowed')) {
    /** آیا این تب در این نسخه فعال است؟ (فقط کلیدهای شناخته‌شده) */
    function priceTabIsAllowed(string $key): bool
    {
        return isset(priceTabKinds()[$key]);
    }
}

if (!function_exists('priceTabEntityOptions')) {
    /**
     * فهرستِ طرف‌های حسابِ یک تب (برای dropdownِ انتخاب).
     * محدودهٔ شعبه:
     *   • تب‌های طراح/پزشک/کلینیک/لابراتوار → همهٔ فعال‌ها (نرخ توسط مدیریت شعبه تعیین می‌شود)
     *   • تبِ بین‌شعبه‌ای → همهٔ شعبه‌ها (مدیر شعبه فقط نرخِ «دریافتی» را می‌بیند)
     *
     * @return array<int, array{id:int, name:string, sub:string}>
     */
    function priceTabEntityOptions(string $kindKey): array
    {
        $kind = priceTabKind($kindKey);
        if (!$kind) return [];
        $out = [];

        switch ($kindKey) {
            case 'designers':
                $rows = db()->query("SELECT u.id, u.full_name, u.is_default_designer, b.name AS branch_name
                                     FROM users u LEFT JOIN branches b ON b.id = u.branch_id
                                     WHERE u.is_designer = 1 AND u.active = 1
                                     ORDER BY u.is_default_designer DESC, u.full_name ASC")->fetchAll();
                foreach ($rows as $r) {
                    $out[] = [
                        'id'   => (int) $r['id'],
                        'name' => (string) $r['full_name'],
                        'sub'  => !empty($r['is_default_designer']) ? 'پیش‌فرض' : (string) ($r['branch_name'] ?? ''),
                    ];
                }
                break;

            case 'doctors':
                $rows = db()->query("SELECT u.id, u.full_name, b.name AS branch_name
                                     FROM users u LEFT JOIN branches b ON b.id = u.branch_id
                                     WHERE u.role = 'doctor' AND u.active = 1
                                     ORDER BY u.full_name ASC")->fetchAll();
                foreach ($rows as $r) {
                    $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['full_name'], 'sub' => (string) ($r['branch_name'] ?? '')];
                }
                break;

            case 'clinics':
                $rows = db()->query("SELECT u.id, u.full_name, b.name AS branch_name
                                     FROM users u LEFT JOIN branches b ON b.id = u.branch_id
                                     WHERE u.role = 'clinic' AND u.active = 1
                                     ORDER BY u.full_name ASC")->fetchAll();
                foreach ($rows as $r) {
                    $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['full_name'], 'sub' => (string) ($r['branch_name'] ?? '')];
                }
                break;

            case 'labs':
                // همان getAllLabs() — شاملِ لابراتوارهای کلاسیک + شرکای برون‌سپاری + شعبه‌های همکار
                foreach (getAllLabs() as $r) {
                    $out[] = [
                        'id'   => (int) $r['id'],
                        'name' => (string) $r['full_name'],
                        'sub'  => (string) ($r['branch_name'] ?? ''),
                    ];
                }
                break;

            case 'branches':
                foreach (getAllBranches() as $b) {
                    $out[] = ['id' => (int) $b['id'], 'name' => (string) $b['name'], 'sub' => !empty($b['code']) ? (string) $b['code'] : ''];
                }
                break;
        }
        return $out;
    }
}

if (!function_exists('priceTabLinks')) {
    /**
     * نرخ‌های ثبت‌شده برای یک طرفِ حساب در یک تب.
     * کلید خروجی = service_id (اخرین ردیف برنده است، مثل ORDER BY id DESC).
     *
     * @param string $kindKey  کلیدِ تب
     * @param int    $entityId شناسهٔ طرفِ حساب
     * @param int    $myBranch شعبهٔ جاری (برای تب‌های پزشک/طراح/کلینیک/لابراتوار)
     * @return array<int, array{price:float, link_id:int}>
     */
    function priceTabLinks(string $kindKey, int $entityId, int $myBranch, ?int $counterpartyId = null, ?string $dir = null): array
    {
        $kind = priceTabKind($kindKey);
        if (!$kind || $entityId <= 0) return [];

        $out = [];
        try {
            switch ($kindKey) {
                case 'designers':
                    $st = db()->prepare("SELECT id, service_id, price FROM price_links
                                         WHERE active = 1 AND price_type = 'design_fee'
                                           AND provider_type IN ('designer','doctor') AND provider_id = ?
                                           AND service_id IS NOT NULL
                                         ORDER BY id ASC");
                    $st->execute([$entityId]);
                    break;

                case 'doctors':
                case 'clinics':
                    // شعبهٔ ما (انجام‌دهنده) ← پزشک/کلینیک (پرداخت‌کننده)
                    $st = db()->prepare("SELECT id, service_id, price FROM price_links
                                         WHERE active = 1 AND price_type = 'specific'
                                           AND provider_type = 'branch' AND provider_id = ?
                                           AND receiver_type = 'doctor' AND receiver_id = ?
                                           AND service_id IS NOT NULL
                                         ORDER BY id ASC");
                    $st->execute([$myBranch, $entityId]);
                    break;

                case 'labs':
                    // دو جهت (مثل تبِ بین‌شعبه‌ای):
                    //   out (پیش‌فرض) = برون‌سپاری به لابراتوار ← لابراتوار انجام‌دهنده، شعبهٔ ما پرداخت‌کننده
                    //   in            = کار از لابراتوار همکار ← شعبهٔ ما انجام‌دهنده، لابراتوار پرداخت‌کننده
                    $labBranchId = ((int) ($counterpartyId ?? 0)) > 0 ? (int) $counterpartyId : $myBranch;
                    if (($dir ?? 'out') === 'in') {
                        $st = db()->prepare("SELECT id, service_id, price FROM price_links
                                             WHERE active = 1 AND price_type IN ('specific','outsource')
                                               AND provider_type = 'branch' AND provider_id = ?
                                               AND receiver_type = 'lab' AND receiver_id = ?
                                               AND service_id IS NOT NULL
                                             ORDER BY id ASC");
                        $st->execute([$labBranchId, $entityId]);
                    } else {
                        $st = db()->prepare("SELECT id, service_id, price FROM price_links
                                             WHERE active = 1 AND price_type IN ('outsource','specific')
                                               AND provider_type = 'lab' AND provider_id = ?
                                               AND receiver_type = 'branch' AND receiver_id = ?
                                               AND service_id IS NOT NULL
                                             ORDER BY id ASC");
                        $st->execute([$entityId, $labBranchId]);
                    }
                    break;

                case 'branches':
                    // جفتِ صریح: provider = خدمت‌دهنده (انجام‌دهنده) ، entityId = خدمت‌گیرنده (پرداخت‌کننده)
                    $counterpartyId = (int) ($counterpartyId ?? 0);
                    if ($counterpartyId > 0) {
                        $st = db()->prepare("SELECT id, service_id, price FROM price_links
                                             WHERE active = 1 AND price_type IN ('outsource','specific')
                                               AND provider_type = 'branch' AND provider_id = ?
                                               AND receiver_type = 'branch' AND receiver_id = ?
                                               AND service_id IS NOT NULL
                                             ORDER BY id ASC");
                        $st->execute([$counterpartyId, $entityId]);
                    } else {
                        // بدون جفتِ مشخص: همهٔ نرخ‌هایی که این شعبه پرداخت‌کنندهٔ آن‌هاست
                        $st = db()->prepare("SELECT id, service_id, price FROM price_links
                                             WHERE active = 1 AND price_type IN ('outsource','specific')
                                               AND provider_type = 'branch' AND provider_id <> ?
                                               AND receiver_type = 'branch' AND receiver_id = ?
                                               AND service_id IS NOT NULL
                                             ORDER BY id ASC");
                        $st->execute([$entityId, $entityId]);
                    }
                    break;

                default:
                    return [];
            }

            foreach ($st->fetchAll() as $row) {
                $sid = (int) $row['service_id'];
                $out[$sid] = ['price' => (float) $row['price'], 'link_id' => (int) $row['id']];
            }
        } catch (Throwable $e) {
            error_log('priceTabLinks: ' . $e->getMessage());
            return [];
        }
        return $out;
    }
}

if (!function_exists('priceTabCanEdit')) {
    /**
     * آیا کاربرِ جاری اجازهٔ تغییرِ نرخِ این طرفِ حساب را دارد؟
     *
     * قواعد:
     *   • ادمین اصلی (root) → همه‌چیز
     *   • مدیر/کاربرِ شعبه:
     *       - پزشک/کلینیک/طراح: فقط طرف‌هایی که شعبهٔ خودش یا بدون شعبه‌اند؟ → خیر.
     *         طبق تصمیم کاربر، هر شعبه نرخِ پزشک/کلینیک/لابراتوار/طراحِ خودش را مدیریت می‌کند.
     *         اما نرخ‌های «مشترک» (طرفِ مقابل از شعبهٔ دیگر یا لابراتوارِ بین‌شعبه‌ای) فقط ادمین اصلی.
     *       - بین‌شعبه‌ای: فقط نرخی که «شعبهٔ من پرداخت‌کننده است» (receiver = شعبهٔ من).
     *
     * @param string   $kindKey       کلیدِ تب
     * @param int      $entityId      طرفِ حساب
     * @param int      $myBranch      شعبهٔ کاربر
     * @param bool     $isRoot        ادمین اصلی
     * @param int|null $counterpartyId فقط برای تبِ بین‌شعبه‌ای (شعبهٔ انجام‌دهنده) / تبِ لابراتوار (شعبهٔ من)
     * @param string|null $dir        تبِ لابراتوار: out = ما می‌پردازیم ، in = لابراتوار می‌پردازد
     */
    function priceTabCanEdit(string $kindKey, int $entityId, int $myBranch, bool $isRoot, ?int $counterpartyId = null, ?string $dir = null): bool
    {
        if ($isRoot) return true;
        if ($kindKey === 'branches') {
            // نرخ بین‌شعبه‌ای: هر شعبه فقط نرخی را تعیین می‌کند که **خودش خدمت‌دهنده** است.
            // (counterpartyId = پایان‌دهنده/خدمت‌دهنده)
            $providerBranch = (int) ($counterpartyId ?? 0);
            return $providerBranch > 0 && $providerBranch === $myBranch;
        }
        // لابراتوار — جهتِ «کار از لابراتوار همکار» (in): شعبه‌ای که کار را انجام می‌دهد
        // نرخِ خودش را تعیین می‌کند؛ پس فقط وقتی شعبهٔ انجام‌دهنده = شعبهٔ من قابل ویرایش است.
        if ($kindKey === 'labs' && ($dir ?? 'out') === 'in') {
            $labBranchId = ((int) ($counterpartyId ?? 0)) > 0 ? (int) $counterpartyId : $myBranch;
            return $labBranchId === $myBranch;
        }
        // طراح‌ها: نرخِ طراحی همیشه متعلق به همان شعبه‌ای است که پرداخت می‌کند
        // (receiver = شعبه). پس مدیر هر شعبه نرخِ طراحی «برای شعبهٔ خودش» را مدیریت
        // می‌کند — حتی اگر خودِ طراح به شعبهٔ دیگری تعلق داشته باشد یا بدون شعبه باشد.
        if ($kindKey === 'designers') {
            return true;
        }
        // تب‌های پزشک/کلینیک/لابراتوار: طرفِ حساب باید متعلق به شعبهٔ من باشد.
        // (طرفِ بدون شعبه = مشترک → فقط ادمین اصلی)
        return priceTabEntityBranchId($kindKey, $entityId) === $myBranch;
    }
}

if (!function_exists('priceTabEntityBranchId')) {
    /** شعبهٔ طرفِ حساب (null = مشترک/بدون شعبه). */
    function priceTabEntityBranchId(string $kindKey, int $entityId): ?int
    {
        if ($entityId <= 0) return null;
        try {
            if ($kindKey === 'branches') {
                $st = db()->prepare('SELECT id FROM branches WHERE id = ?');
                $st->execute([$entityId]);
                return $st->fetchColumn() ? $entityId : null;
            }
            $st = db()->prepare('SELECT branch_id FROM users WHERE id = ? LIMIT 1');
            $st->execute([$entityId]);
            $b = $st->fetchColumn();
            return ($b !== false && $b !== null && $b !== '') ? (int) $b : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('priceTabUpsert')) {
    /**
     * درج/به‌روزرسانی/حذفِ یک نرخ در price_links + هم‌گام‌سازی با جدول قدیمی.
     *
     * @param string   $kindKey       کلیدِ تب
     * @param int      $entityId      طرفِ حساب
     * @param int      $serviceId     خدمت
     * @param float|null $price       null = حذف
     * @param int      $myBranch      شعبهٔ کاربر
     * @param int|null $counterpartyId تبِ بین‌شعبه‌ای: شعبهٔ انجام‌دهنده / تبِ لابراتوار: شعبهٔ من
     * @param string|null $dir        تبِ لابراتوار: out = برون‌سپاری به لابراتوار ، in = کار از لابراتوار
     * @return bool موفقیت
     */
    function priceTabUpsert(string $kindKey, int $entityId, int $serviceId, ?float $price, int $myBranch, ?int $counterpartyId = null, ?string $dir = null): bool
    {
        $kind = priceTabKind($kindKey);
        if (!$kind || $entityId <= 0 || $serviceId <= 0) return false;

        // تعیینِ provider / receiver بر اساس تب
        $providerType = $kind['providerType'];
        $providerId   = $entityId;
        $receiverType = $kind['receiverType'];
        $receiverId   = null;
        $priceTypeOverride = null;

        switch ($kindKey) {
            case 'designers':
                $providerType = 'designer';
                $providerId   = $entityId;
                $receiverType = 'branch';
                $receiverId   = $myBranch;
                break;

            case 'doctors':
            case 'clinics':
                $providerType = 'branch';
                $providerId   = $myBranch;
                $receiverType = 'doctor';
                $receiverId   = $entityId;
                break;

            case 'labs':
                $labBranchId = ((int) ($counterpartyId ?? 0)) > 0 ? (int) $counterpartyId : $myBranch;
                if (($dir ?? 'out') === 'in') {
                    // کار از لابراتوار همکار: شعبهٔ ما انجام‌دهنده ، لابراتوار پرداخت‌کننده
                    $providerType = 'branch';
                    $providerId   = $labBranchId;
                    $receiverType = 'lab';
                    $receiverId   = $entityId;
                    $priceTypeOverride = 'specific';
                } else {
                    // برون‌سپاری به لابراتوار: لابراتوار انجام‌دهنده ، شعبهٔ ما پرداخت‌کننده
                    $providerType = 'lab';
                    $providerId   = $entityId;
                    $receiverType = 'branch';
                    $receiverId   = $labBranchId;
                    $priceTypeOverride = 'outsource';
                }
                break;

            case 'branches':
                // provider = شعبهٔ خدمت‌دهنده (انجام‌دهنده) ، receiver = شعبهٔ خدمت‌گیرنده (پرداخت‌کننده)
                $counterpartyId = (int) ($counterpartyId ?? 0);
                if ($counterpartyId <= 0) {
                    // بدون جفتِ مشخص نمی‌توان نرخ ساخت/تغییر داد (طرفِ مقابل معلوم نیست)
                    $GLOBALS['priceTabLastError'] = 'no_pair';
                    return false;
                }
                $providerType = 'branch';
                $providerId   = $counterpartyId;
                $receiverType = 'branch';
                $receiverId   = $entityId;
                break;
        }

        try {
            // ── پیدا کردن ردیفِ موجود (کلید: provider + receiver + service) ──
            $st = db()->prepare("SELECT id FROM price_links
                                 WHERE price_type IN ('design_fee','outsource','specific')
                                   AND provider_type = ? AND provider_id = ?
                                   AND receiver_type = ? AND receiver_id = ?
                                   AND service_id = ?
                                 ORDER BY id DESC LIMIT 1");
            $st->execute([$providerType, $providerId, $receiverType, $receiverId, $serviceId]);
            $row = $st->fetch();

            $priceType = $priceTypeOverride ?? $kind['priceType'];

            if ($price === null) {
                if ($row) {
                    $del = db()->prepare('DELETE FROM price_links WHERE id = ?');
                    $del->execute([(int) $row['id']]);
                }
                priceTabSyncLegacy($kindKey, $entityId, $serviceId, null, $myBranch, $counterpartyId, $dir);
                return true;
            }

            $price = round((float) $price, 2);
            if ($row) {
                db()->prepare('UPDATE price_links SET price = ?, price_type = ?, branch_id = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$price, $priceType, $myBranch, (int) $row['id']]);
                $linkId = (int) $row['id'];
            } else {
                db()->prepare("INSERT INTO price_links
                        (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', 1, NOW(), NOW())")
                    ->execute([$serviceId, $providerType, $providerId, $receiverType, $receiverId, $price, $priceType, $myBranch]);
                $linkId = (int) db()->lastInsertId();
            }

            // هم‌گام‌سازی با جدول‌های قدیمی (تا فاکتور/گزارش‌ها همان عدد را ببینند)
            priceTabSyncLegacy($kindKey, $entityId, $serviceId, $price, $myBranch, $counterpartyId, $dir);
            return true;
        } catch (Throwable $e) {
            error_log('priceTabUpsert: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('priceTabSyncLegacy')) {
    /** آینه‌کردنِ نرخ در جدول‌های قدیمی (doctor_price_overrides / outsource_rates). */
    function priceTabSyncLegacy(string $kindKey, int $entityId, int $serviceId, ?float $price, int $myBranch, ?int $counterpartyId = null, ?string $dir = null): void
    {
        try {
            if ($kindKey === 'designers') {
                if ($price === null) {
                    deleteDoctorPriceOverrideByTarget($entityId, $serviceId, 'design_fee');
                } else {
                    upsertDoctorPriceOverrideLegacy($entityId, $serviceId, 'design_fee', $price, $myBranch);
                }
            } elseif ($kindKey === 'doctors' || $kindKey === 'clinics') {
                if ($price === null) {
                    deleteDoctorPriceOverrideByTarget($entityId, $serviceId, 'service');
                } else {
                    upsertDoctorPriceOverrideLegacy($entityId, $serviceId, 'service', $price, $myBranch);
                }
            } elseif ($kindKey === 'labs') {
                if (($dir ?? 'out') === 'in') {
                    // «کار از لابراتوار همکار»: معادلِ قدیمیِ آن doctor_price_overrides با
                    // price_type='service' است (getLabApplicablePrice مرحلهٔ ۲ همین را می‌خواند).
                    if ($price === null) {
                        deleteDoctorPriceOverrideByTarget($entityId, $serviceId, 'service');
                    } else {
                        upsertDoctorPriceOverrideLegacy($entityId, $serviceId, 'service', $price, $myBranch);
                    }
                } elseif ($price === null) {
                    deleteOutsourceRateByLabService($entityId, $serviceId);
                    deleteLabPriceOverrideByLabService($entityId, $serviceId);
                } else {
                    upsertOutsourceRateLegacy($entityId, $serviceId, $price, $myBranch, $myBranch);
                    upsertLabPriceOverrideLegacy($entityId, $serviceId, $price);
                }
            }
            // تبِ بین‌شعبه‌ای: جدول قدیمیِ معادلی ندارد؛ price_links تنها منبع است.
        } catch (Throwable $e) {
            error_log('priceTabSyncLegacy: ' . $e->getMessage());
        }
    }
}

if (!function_exists('priceTabParseAmount')) {
    /**
     * تفسیرِ مبلغ: 'empty' (خالی/صفر) | 'invalid' (متن/منفی) | float
     * @return string|float
     */
    function priceTabParseAmount($raw)
    {
        $s = trim((string) $raw);
        if ($s === '') return 'empty';
        $s = normalizePersianDigits($s);
        $s = str_replace([',', '،', ' ', '٫', '/'], '', $s);
        if (!is_numeric($s)) return 'invalid';
        $v = (float) $s;
        if ($v < 0) return 'invalid';
        if ($v == 0.0) return 'empty';
        return round($v, 2);
    }
}
