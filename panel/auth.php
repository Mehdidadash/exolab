<?php
// panel/auth.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Secure session configuration for shared hosting
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    // Set GC lifetime BEFORE session start
    if (ini_get('session.gc_maxlifetime') < 86400 * 30) {
        ini_set('session.gc_maxlifetime', 86400 * 30);
    }
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
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
        'doctor'     => ['view_own_cases', 'view_own_invoices', 'view_own_payments', 'view_case_files'],
        'staff'      => ['view_all_cases', 'create_cases', 'edit_cases', 'edit_case_status', 'upload_files'],
        'secretary'  => ['view_all_cases', 'create_cases', 'edit_cases', 'edit_case_status', 'upload_files', 'delete_files'],
        'designer'   => ['view_all_cases', 'upload_design_files', 'edit_case_status'],
        'technician' => ['view_all_cases', 'update_case_status', 'view_invoices'],
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

function has_permission($permission) {
    $user = current_user();
    if (!$user) return false;
    $perms = $GLOBALS['role_permissions'][$user['role']] ?? [];
    return in_array('*', $perms) || in_array($permission, $perms);
}

function require_login() {
    if (!is_logged_in()) {
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
    if (!$user || $user['role'] !== 'clinic') return [];
    $stmt = db()->prepare('SELECT id FROM users WHERE clinic_id = ? AND active = 1');
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
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
    if ($user['role'] === 'doctor' && ($user['id'] === $doctorId || $user['id'] === $doctorId)) return true;
    if ($user['role'] === 'clinic') {
        $ids = getClinicDoctorIds();
        return in_array($doctorId, $ids);
    }
    return false;
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
            <nav class="site-nav">
                <?php if (has_permission('view_all_cases') || has_permission('view_own_cases') || has_permission('view_clinic_cases')): ?>
                    <a href="cases.php">کیس‌ها</a>
                <?php endif; ?>
                <?php if (has_role('admin')): ?>
                    <a href="users.php">کاربران</a>
                    <a href="doctors.php">پزشکان</a>
                    <a href="prices.php">قیمت</a>
                    <a href="doctor_price_overrides.php">قیمت‌های اختصاصی</a>
                    <a href="works.php">نمونه کار</a>
                    <a href="bank_accounts.php">حساب‌های بانکی</a>
                    <a href="audit_log.php">لاگ فعالیت‌ها</a>
                    <a href="roles.php">نقش‌ها</a>
                <?php endif; ?>
                <?php if (has_permission('view_invoices') || has_permission('view_clinic_invoices')): ?>
                    <a href="invoices.php">فاکتورها</a>
                <?php endif; ?>
                <?php if (has_permission('view_own_payments') || has_role('admin') || has_permission('view_clinic_payments')): ?>
                    <a href="payments.php">پرداخت‌ها</a>
                <?php endif; ?>
                <a href="logout.php">خروج</a>
            </nav>
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
    </body>
    </html>
    <?php
}