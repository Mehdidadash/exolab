<?php
// panel/auth.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Secure session configuration for shared hosting
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    /*
     * Dedicated session directory. On shared hosting the default save path is
     * shared with other apps, whose PHP processes may garbage-collect our
     * session files using the PHP default lifetime (~24 minutes) — that is why
     * users were being logged out after ~30 minutes or whenever their IP
     * changed. Keeping our sessions in their own folder stops other apps from
     * deleting them. The directory is created automatically if missing and is
     * protected from web access by storage/sessions/.htaccess.
     */
    $sessionDir = __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    // Keep sessions alive for a very long time (600 days) instead of the PHP
    // default (~24 minutes). GC lifetime must be set BEFORE session_start().
    if (ini_get('session.gc_maxlifetime') < 86400 * 600) {
        ini_set('session.gc_maxlifetime', 86400 * 600);
    }
    @ini_set('session.gc_probability', 1);
    @ini_set('session.gc_divisor', 100);

    session_set_cookie_params([
        'lifetime' => 86400 * 600,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Ensure CSRF token exists after session starts
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

// Note: session_write_close() is NOT called here intentionally.
// save_case.php needs to read/write session for CSRF validation.

// Permission mapping – loaded from database roles table
$GLOBALS['role_permissions'] = [];
$GLOBALS['role_labels'] = [];
try {
    $roles = getAllRoles();
    foreach ($roles as $r) {
        $GLOBALS['role_labels'][$r['name']] = $r['label'];
        $perms = json_decode($r['permissions'] ?? '[]', true);
        $GLOBALS['role_permissions'][$r['name']] = is_array($perms) ? $perms : [];
    }
} catch (\Throwable $e) {
    // Fallback if roles table doesn't exist yet
    $GLOBALS['role_permissions'] = [
        'admin'      => ['*'],
        'doctor'     => ['view_own_cases', 'view_own_invoices', 'view_own_payments', 'view_case_files', 'create_cases'],
        'staff'      => ['view_all_cases', 'create_cases', 'edit_cases', 'edit_case_status', 'upload_files', 'batch_print_labels', 'export_csv'],
        'secretary'  => ['view_all_cases', 'create_cases', 'edit_cases', 'edit_case_status', 'upload_files', 'delete_files', 'batch_print_labels', 'export_csv'],
        'designer'   => ['view_assigned_cases', 'upload_design_files', 'edit_case_status'],
        'technician' => ['view_all_cases', 'update_case_status', 'view_invoices', 'batch_print_labels'],
        'operator'   => ['view_all_cases', 'update_case_status'],
        'powder'     => ['view_all_cases'],
        'courier'    => ['view_all_cases'],
        'finance'    => ['view_all_cases', 'view_invoices', 'view_payments'],
        'outsource_lab' => ['view_assigned_cases', 'view_case_files'],
        'customer_lab' => ['view_assigned_cases', 'view_case_files'],
        'partner_lab'  => ['view_assigned_cases', 'view_case_files'],
        'lab'        => ['view_assigned_cases', 'view_case_files'],
        'clinic'     => ['view_clinic_cases', 'view_clinic_invoices', 'view_clinic_payments', 'view_case_files'],
    ];
}

function current_user() {
    static $user = null;
    if ($user !== null) return $user;
    if (empty($_SESSION[USER_SESSION_KEY])) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1 LIMIT 1');
    $stmt->execute([(int)$_SESSION[USER_SESSION_KEY]]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION[USER_SESSION_KEY]);
        session_destroy();
        return null;
    }
    return $user;
}

function is_logged_in() {
    return current_user() !== null;
}

function has_role($role) {
    $user = current_user();
    return $user && $user['role'] === $role;
}

/**
 * True for a branch manager (role = branch_admin) or root admin.
 * Branch admins have '*' permissions but their data is scoped to their branch_id.
 */
function is_admin(): bool {
    $user = current_user();
    if (!$user) return false;
    return $user['role'] === 'admin' || $user['role'] === 'branch_admin';
}

/** Root/global admin = role 'admin' (sees all branches). */
function is_root_admin(): bool {
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

/** True when the current user is scoped to a specific branch (not global). */
function is_branch_scoped(): bool {
    $user = current_user();
    if (!$user) return false;
    // Root admin (role='admin') is ALWAYS global, regardless of any branch_id.
    if ($user['role'] === 'admin') return false;
    return !empty($user['branch_id']);
}

/**
 * Whether the current user may see internal designer names on cases.
 * Designer info is internal: only our staff / secretaries / branch users see it.
 * Doctors, clinics, and external labs do NOT see designer names (unless the
 * user is a branch member / admin).
 */
function canSeeDesignerInfo(): bool {
    $user = current_user();
    if (!$user) return false;
    // Internal roles + anyone scoped to a branch
    if (in_array($user['role'] ?? '', ['admin', 'branch_admin', 'staff', 'secretary', 'technician', 'designer'], true)) {
        return true;
    }
    return is_branch_scoped();
}

function has_permission($permission) {
    $user = current_user();
    if (!$user) return false;
    $perms = $GLOBALS['role_permissions'][$user['role']] ?? [];
    return in_array('*', $perms) || in_array($permission, $perms);
}

/**
 * Get the current request URI if it is an internal panel page (safe to return to after login).
 *
 * Data/AJAX endpoints (JSON pollers, DataTables feeds, upload/download handlers)
 * are deliberately excluded: they get hit by fetch()/XHR while the user is
 * logged out, and redirecting back to them after login would land the user on
 * raw JSON instead of a real page.
 */
function getLoginRedirectUrl(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ($uri === '' || $uri[0] !== '/' || strpos($uri, '//') === 0 || strpos($uri, '://') !== false) {
        return '';
    }
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    if (!preg_match('#/panel/[A-Za-z0-9_\-]+\.php$#', $path)) {
        return '';
    }
    if (preg_match('#/panel/(login|logout)\.php$#', $path)) {
        return '';
    }
    // Skip JSON / data endpoints (never return to these after login).
    if (preg_match('#/panel/(check_notifications|cases_data|get_case|get_price|get_outsource_rate|get_all_prices|upload_user_file|upload_case_files|upload_design_file|download_case_file|download_user_upload)\.php$#', $path)) {
        return '';
    }
    // Skip XHR / fetch requests (jQuery DataTables, $.ajax, pollers).
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        return '';
    }
    return $uri;
}

function require_login() {
    if (!is_logged_in()) {
        // Remember where the user wanted to go so we can return after login
        $target = getLoginRedirectUrl();
        if ($target !== '') {
            $_SESSION['login_redirect'] = $target;
        }
        header('Location: login.php');
        exit;
    }
}

function require_role($role) {
    require_login();
    if (!has_role($role)) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
}

/**
 * Require admin-level access: root admin OR a branch manager (branch_admin).
 * Branch admins' data is scoped to their branch via branch_id.
 */
function require_admin() {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
}

/**
 * Require ROOT admin only (global, all branches): user/role/branch/audit management.
 */
function require_root_admin() {
    require_login();
    if (!is_root_admin()) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
}

function require_permission($permission) {
    require_login();
    if (!has_permission($permission)) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
}

// ─── Clinic hierarchy helpers ───

/** Get IDs of doctors belonging to the current clinic user */
function getClinicDoctorIds(): array {
    $user = current_user();
    if (!$user) return [];

    if ($user['role'] === 'clinic' || $user['role'] === 'doctor') {
        $stmt = db()->prepare('SELECT id FROM users WHERE clinic_id = ? AND active = 1');
        $stmt->execute([$user['id']]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    return [];
}

/** Get a WHERE clause snippet for clinic-scoped queries. Returns ['sql' => '...', 'params' => [...]] */
function getClinicScope(string $alias = 'c'): array {
    $user = current_user();
    if ($user && $user['role'] === 'clinic') {
        $ids = getClinicDoctorIds();
        if (empty($ids)) {
            return ['sql' => "{$alias}.doctor_id IN (0)", 'params' => []];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return ['sql' => "{$alias}.doctor_id IN ({$placeholders})", 'params' => $ids];
    }
    return ['sql' => '1=1', 'params' => []];
}

/** Check if user can access a specific doctor's data (for clinic users) */
function canAccessDoctor(int $doctorId): bool {
    $user = current_user();
    if (!$user) return false;
    if (has_permission('view_all_cases')) return true;
    if ($user['role'] === 'doctor') {
        if ($user['id'] === $doctorId) return true;
        $ids = getClinicDoctorIds();
        return in_array($doctorId, $ids);
    }
    if ($user['role'] === 'clinic') {
        $ids = getClinicDoctorIds();
        return in_array($doctorId, $ids);
    }
    return false;
}

/**
 * Check if a designer can access a target user's profile.
 * Designers may view profiles of doctors, clinics, and labs that are linked
 * to a case where they are assigned as the designer.
 */
function designerCanAccessUser(int $targetUserId): bool {
    $user = current_user();
    if (!$user || $user['role'] !== 'designer') {
        return false;
    }
    $designerId = (int) $user['id'];
    $stmt = db()->prepare('
        SELECT COUNT(*) FROM cases c
        LEFT JOIN users d ON c.doctor_id = d.id
        WHERE c.designer_id = ?
          AND (c.doctor_id = ? OR c.lab_id = ? OR d.clinic_id = ?)
    ');
    $stmt->execute([$designerId, $targetUserId, $targetUserId, $targetUserId]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Allowed case status IDs that the current user may set.
 * An empty array means ALL statuses are allowed.
 * Map each role to its allowed status IDs (case_statuses.id).
 */
function getAllowedStatusIdsForUser(): array {
    $user = current_user();
    if (!$user) return [];

    // These roles manage cases end-to-end and may set any status
    if (has_role('admin') || has_role('staff') || has_role('secretary')) {
        return [];
    }

    $map = [
        'designer' => [9, 10, 20, 21], // در حال طراحی، طراحی شده، ارسال به پزشک/مدیر برای کنترل طراحی
        // Add other roles here, e.g.:
        // 'technician' => [3, 4, 11, 12, 13, 14, 15, 16, 17],
    ];

    return $map[$user['role']] ?? [];
}

/** Check whether the current user may set a given status ID. */
function canUserSetStatus(int $statusId): bool {
    $allowed = getAllowedStatusIdsForUser();
    if (empty($allowed)) return true;
    return in_array($statusId, $allowed, true);
}

// Layout function – unified header for all pages
function panel_layout_start($title = 'پنل مدیریت') {
    $user = current_user();
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link rel="stylesheet" href="../assets/css/style.css">
        <link rel="stylesheet" href="../assets/css/datatables.min.css">
        <script src="../assets/js/jquery-3.6.0.min.js"></script>
        <script src="../assets/js/datatables.min.js"></script>
    </head>
    <body>
    <header class="site-header">
        <div class="container" style="justify-content: space-between;">
            <a class="brand" href="dashboard.php">
                <img src="../assets/icons/EXOLAB_LOGO_HORIZENTAL.svg" alt="EXOLAB" class="site-logo">
                <span><?= $user ? htmlspecialchars($user['full_name']) . ' — ' . $user['role'] : 'پنل' ?></span>
            </a>
            <button class="menu-toggle" id="menuToggle" onclick="toggleMobileMenu()">
                <img src="../assets/icons/hamburger-menu.svg" alt="☰">
            </button>
            <nav class="site-nav" id="siteNav">
                <?php
                $navCanCases  = has_permission('view_all_cases') || has_permission('view_own_cases') || has_permission('view_assigned_cases') || has_permission('view_clinic_cases') || has_role('designer');
                $navCanUpload = $user && in_array($user['role'] ?? '', ['doctor', 'designer', 'admin', 'branch_admin', 'clinic', 'lab', 'outsource_lab', 'customer_lab', 'partner_lab'], true);
                $navCanInv    = has_permission('view_invoices') || has_permission('view_clinic_invoices') || has_permission('view_own_invoices') || is_admin();
                $navCanPay    = has_permission('view_own_payments') || has_permission('view_clinic_payments') || has_permission('view_payments') || is_admin();
                $navIsAdmin   = is_admin();
                $navIsRoot    = is_root_admin();
                $navIsBranch  = is_branch_scoped();
                ?>
                <?php if ($navCanCases): ?>
                    <div class="nav-group">
                        <button type="button" class="nav-group-toggle" onclick="toggleNavGroup(this)">کیس‌ها <span class="caret">▼</span></button>
                        <div class="nav-group-menu">
                            <a href="cases.php">کیس‌ها</a>
                            <?php if ($navIsAdmin): ?><a href="case_expenses.php">کیس‌های مخارج</a><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($navCanUpload): ?>
                    <a class="nav-link" href="uploads.php">آپلود فایل</a>
                <?php endif; ?>

                <?php if ($navCanInv || $navCanPay || $navIsAdmin): ?>
                    <div class="nav-group">
                        <button type="button" class="nav-group-toggle" onclick="toggleNavGroup(this)">مالی <span class="caret">▼</span></button>
                        <div class="nav-group-menu">
                            <?php if ($navCanPay): ?>
                                <span class="menu-label">پرداخت‌ها</span>
                                <a href="payments.php">دریافتی</a>
                                <?php if ($navIsAdmin): ?><a href="expense_payments.php">هزینه</a><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($navCanInv): ?>
                                <span class="menu-label">فاکتورها</span>
                                <a href="invoices.php">فاکتورها</a>
                                <?php if ($navIsAdmin): ?><a href="expenses.php">فاکتورهای مخارج (بدهی‌ها)</a><?php endif; ?>
                                <?php if ($navIsAdmin): ?><a href="branch_receivables.php">فاکتور طلب از شعبه‌ها</a><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($navIsAdmin): ?>
                                <span class="menu-label">قیمت‌ها</span>
                                <a href="prices.php?tab=general">قیمت‌های عمومی (پیش‌فرض)</a>
                                <a href="prices.php?tab=map">نقشه و نرخ‌های اختصاصی</a>
                                <?php if ($navIsBranch): ?><a href="branch_prices.php">قیمت‌های شعبه (قدیمی)</a><?php endif; ?>
                                <span class="menu-label">حساب‌ها</span>
                                <a href="bank_accounts.php">حساب‌های بانکی</a>
                                <a href="financial_overview.php">بررسی درآمد و هزینه</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($navIsAdmin): ?>
                    <div class="nav-group">
                        <button type="button" class="nav-group-toggle" onclick="toggleNavGroup(this)">مدیریت <span class="caret">▼</span></button>
                        <div class="nav-group-menu">
                            <?php if ($navIsRoot): ?>
                                <a href="users.php">کاربران</a>
                                <a href="branches.php">شعبه‌ها</a>
                            <?php endif; ?>
                            <a href="network.php">شبکه همکاران</a>
                            <a href="case_statuses.php">وضعیت‌های کیس</a>
                        </div>
                    </div>

                    <div class="nav-group">
                        <button type="button" class="nav-group-toggle" onclick="toggleNavGroup(this)">وبسایت <span class="caret">▼</span></button>
                        <div class="nav-group-menu">
                            <a href="prices.php">قیمت‌ها</a>
                            <a href="works.php">نمونه کار</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($navIsRoot || $user): ?>
                    <div class="nav-group">
                        <button type="button" class="nav-group-toggle" onclick="toggleNavGroup(this)">تنظیمات <span class="caret">▼</span></button>
                        <div class="nav-group-menu">
                            <a href="change_password.php">تغییر رمز عبور</a>
                            <?php if ($navIsRoot): ?>
                                <a href="roles.php">نقش‌ها</a>
                                <a href="audit_log.php">لاگ فعالیت‌ها</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <a class="nav-link" href="logout.php">خروج</a>
            </nav>
            <?php if ($user): $notifCount = getUnreadNotificationCount($user['id']); ?>
                <a href="notifications.php" class="notif-bell" style="position:relative; color:#fff; text-decoration:none; font-size:1.3rem; margin-right:10px;">
                    🔔
                    <?php if ($notifCount > 0): ?>
                        <span style="position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; font-size:0.65rem; padding:1px 5px; border-radius:50%; font-weight:bold;"><?= toPersianDigits($notifCount > 99 ? '99+' : $notifCount) ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        </div>
    </header>
    <main class="section">
        <div class="container">
            <h2><?= htmlspecialchars($title) ?></h2>
    <?php
}

function panel_layout_end() {
    ?>
        </div>
    </main>
    <?= action_menu_script() ?>
    <script>
    // Auto-initialize DataTables on any table with class "datatable"
    document.addEventListener('DOMContentLoaded', function(){
        if (typeof jQuery !== 'undefined' && typeof jQuery.fn.DataTable !== 'undefined') {
            jQuery('.datatable').each(function(){
                if (jQuery.fn.dataTable.isDataTable(this)) return; // skip if already initialized
                var config = {
                    responsive: true,
                    pageLength: 25,
                    destroy: true,
                    language: {
                        search: "جستجو:",
                        lengthMenu: "نمایش _MENU_ در هر صفحه",
                        info: "نمایش _START_ تا _END_ از _TOTAL_ مورد",
                        infoEmpty: "هیچ موردی یافت نشد",
                        infoFiltered: "(فیلتر شده از _MAX_ مورد)",
                        loadingRecords: "در حال بارگذاری...",
                        zeroRecords: "موردی یافت نشد",
                        emptyTable: "داده‌ای موجود نیست",
                        paginate: { first: "اول", previous: "قبلی", next: "بعدی", last: "آخر" },
                        aria: { sortAscending: ": مرتب‌سازی صعودی", sortDescending: ": مرتب‌سازی نزولی" }
                    }
                };
                // If the table has a data-order attribute, use it
                var orderIdx = this.getAttribute('data-order');
                if (orderIdx !== null) {
                    config.order = [[parseInt(orderIdx), 'desc']];
                }
                jQuery(this).DataTable(config);
            });
        }
    });
    </script>
    <script>
    function toggleMobileMenu() {
        var nav = document.getElementById('siteNav');
        if (nav) nav.classList.toggle('mobile-open');
    }
    // Grouped nav dropdowns: open one group at a time.
    function toggleNavGroup(btn) {
        var group = btn.closest('.nav-group');
        if (!group) return;
        var wasOpen = group.classList.contains('open');
        document.querySelectorAll('#siteNav .nav-group.open').forEach(function(g){
            if (g !== group) g.classList.remove('open');
        });
        group.classList.toggle('open', !wasOpen);
    }
    // Close groups when clicking outside the nav
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#siteNav')) {
            document.querySelectorAll('#siteNav .nav-group.open').forEach(function(g){
                g.classList.remove('open');
            });
        }
    });
    // Close menu when clicking outside
    document.addEventListener('click', function(e) {
        var nav = document.getElementById('siteNav');
        var btn = document.getElementById('menuToggle');
        if (nav && nav.classList.contains('mobile-open') && !nav.contains(e.target) && !btn.contains(e.target)) {
            nav.classList.remove('mobile-open');
        }
    });
    </script>
    <?php
    // panel_layout_end is a separate function scope from panel_layout_start,
    // so re-fetch the current user here.
    $user = current_user();
    if ($user): ?>
    <script>
    // Browser notifications for logged-in users
    (function(){
        if (!('Notification' in window)) return;
        var userId = <?= (int) $user['id'] ?>;
        var storageKey = 'exolab_last_notif_' + userId;
        var lastId = 0;
        try { lastId = parseInt(localStorage.getItem(storageKey) || '0', 10) || 0; } catch(e) {}
        var granted = Notification.permission === 'granted';

        // Request permission on first user interaction (avoids auto-block)
        function requestPermission() {
            if (granted) return;
            if (Notification.permission === 'denied') return;
            Notification.requestPermission().then(function(p){
                granted = (p === 'granted');
            }).catch(function(){});
        }
        document.addEventListener('click', requestPermission, { once: true });

        function poll() {
            fetch('check_notifications.php?last_id=' + lastId, { cache: 'no-store', credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (!data || !Array.isArray(data.notifications)) return;
                    data.notifications.forEach(function(n){
                        if (granted) {
                            try {
                                var opt = { body: n.message || (n.patient ? 'بیمار: ' + n.patient : ''), icon: '../assets/icons/favicon_io/android-chrome-192x192.png', tag: 'exolab-notif-' + n.id };
                                var notif = new Notification(n.title || 'اعلان جدید', opt);
                                notif.onclick = function(){
                                    window.focus();
                                    if (n.case_id) { window.open('view_case.php?id=' + n.case_id, '_blank'); }
                                    else { window.open('notifications.php', '_blank'); }
                                    notif.close();
                                };
                            } catch(e) {}
                        }
                        if (n.id > lastId) lastId = n.id;
                    });
                    if (data.max_id > lastId) lastId = data.max_id;
                    try { localStorage.setItem(storageKey, String(lastId)); } catch(e) {}
                })
                .catch(function(){});
        }
        // initial + interval
        setTimeout(poll, 3000);
        setInterval(poll, 30000);
    })();
    </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
}