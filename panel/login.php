<?php
// panel/login.php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/captcha.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

// Rate limiting: add small delay for every failed attempt
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$stmt = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
$stmt->execute([$ip]);
$failedAttempts = (int) $stmt->fetchColumn();

$showCaptcha = $failedAttempts >= 3;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: delay response if multiple failures
    if ($failedAttempts > 0) {
        usleep(min($failedAttempts * 200000, 2000000)); // 0.2s per failure, max 2s
    }

    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Verify CAPTCHA if needed
    if ($showCaptcha) {
        $captchaInput = $_POST['captcha'] ?? '';
        if (!captcha_verify($captchaInput)) {
            $error = 'پاسخ امنیتی اشتباه است. لطفاً دوباره تلاش کنید.';
            // Record attempt as failed
            captcha_record_attempt(false);
        }
    }
    
    if (empty($error) && $identifier && $password) {
        $userStmt = db()->prepare('SELECT * FROM users WHERE (username = ? OR email = ? OR phone = ?) AND active = 1 LIMIT 1');
        $userStmt->execute([$identifier, $identifier, $identifier]);
        $user = $userStmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            // Success — clear attempts
            captcha_record_attempt(true);
            session_regenerate_id(true);
            $_SESSION[USER_SESSION_KEY] = $user['id'];
            $upd = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $upd->execute([$user['id']]);
            // Return the user to the page they originally requested (if internal)
            $redirect = $_SESSION['login_redirect'] ?? '';
            unset($_SESSION['login_redirect']);
            if ($redirect !== '' && $redirect[0] === '/' && strpos($redirect, '//') !== 0 && strpos($redirect, '://') === false) {
                $path = parse_url($redirect, PHP_URL_PATH) ?: '';
                if (preg_match('#/panel/[A-Za-z0-9_\-]+\.php$#', $path) && !preg_match('#/panel/(login|logout)\.php$#', $path)) {
                    header('Location: ' . $redirect);
                    exit;
                }
            }
            header('Location: dashboard.php');
            exit;
        }
        $error = 'نام کاربری یا رمز عبور اشتباه است.';
        captcha_record_attempt(false);
    } elseif (empty($identifier) || empty($password)) {
        $error = 'لطفاً همه فیلدها را پر کنید.';
    }
    
    // Re-check CAPTCHA need after this attempt
    $retryStmt = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $retryStmt->execute([$ip]);
    $failedAttempts = (int) $retryStmt->fetchColumn();
    $showCaptcha = $failedAttempts >= 3;
}
$captchaQuestion = $showCaptcha ? captcha_generate() : '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود | <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<section class="section">
    <div class="container">
        <div class="form-card">
            <h2>ورود به پنل</h2>
            <?php if ($failedAttempts > 0): ?>
                <div style="font-size:0.85rem; color:#92400e; background:#fef3c7; padding:8px 12px; border-radius:8px; margin-bottom:12px;">
                    تعداد تلاش‌های ناموفق: <?= toPersianDigits($failedAttempts) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-box"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="identifier">نام کاربری / ایمیل / تلفن</label>
                    <input type="text" id="identifier" name="identifier" value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">رمز عبور</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <?php if ($showCaptcha): ?>
                <div class="form-group" style="background:#f0f5ff; padding:12px; border-radius:8px; border:1px solid #d2dff0;">
                    <label><?= $captchaQuestion ?></label>
                    <input type="text" id="captcha" name="captcha" placeholder="جواب را وارد کنید" required autocomplete="off">
                </div>
                <?php endif; ?>
                <button type="submit">ورود</button>
            </form>
        </div>
    </div>
</section>
</body>
</html>