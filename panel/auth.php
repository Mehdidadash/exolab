<?php
// panel/auth.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Secure session configuration for shared hosting
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
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

// Permission mapping
$GLOBALS['role_permissions'] = [
    'admin'      => ['*'],
    'doctor'     => ['view_own_cases', 'view_own_invoices', 'view_own_payments', 'view_case_files'],
    'staff'      => ['view_all_cases', 'create_cases', 'edit_cases', 'upload_files'],
    'secretary'  => ['view_all_cases', 'create_cases', 'edit_cases', 'upload_files', 'delete_files'],
    'designer'   => ['view_all_cases', 'upload_design_files', 'edit_case_status'],
    'technician' => ['view_all_cases', 'update_case_status', 'view_invoices'],
    'operator'   => ['view_all_cases', 'update_case_status'],
    'powder'     => ['view_all_cases'],
    'courier'    => ['view_all_cases'],
    'finance'    => ['view_all_cases', 'view_invoices', 'view_payments'],
    'lab'        => ['view_assigned_cases', 'view_case_files'],
];

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
    </head>
    <body>
    <header class="site-header">
        <div class="container" style="justify-content: space-between;">
            <a class="brand" href="dashboard.php">
                <img src="../assets/icons/EXOLAB_LOGO_HORIZENTAL.svg" alt="EXOLAB" class="site-logo">
                <span><?= $user ? htmlspecialchars($user['full_name']) . ' — ' . $user['role'] : 'پنل' ?></span>
            </a>
            <nav class="site-nav">
                <?php if (has_permission('view_all_cases') || has_permission('view_own_cases')): ?>
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
                <?php endif; ?>
                <?php if (has_permission('view_invoices')): ?>
                    <a href="invoices.php">فاکتورها</a>
                <?php endif; ?>
                <?php if (has_permission('view_own_payments') || has_role('admin')): ?>
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
    </body>
    </html>
    <?php
}