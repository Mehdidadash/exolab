<?php
// panel/login.php
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($identifier && $password) {
        $stmt = db()->prepare('SELECT * FROM users WHERE (username = ? OR email = ? OR phone = ?) AND active = 1 LIMIT 1');
        $stmt->execute([$identifier, $identifier, $identifier]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION[USER_SESSION_KEY] = $user['id'];
            $upd = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $upd->execute([$user['id']]);
            header('Location: dashboard.php');
            exit;
        }
        $error = 'نام کاربری یا رمز عبور اشتباه است.';
    } else {
        $error = 'لطفاً همه فیلدها را پر کنید.';
    }
}
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
                <button type="submit">ورود</button>
            </form>
        </div>
    </div>
</section>
</body>
</html>