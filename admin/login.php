<?php
require_once __DIR__ . '/../db.php';
session_start();

if (!empty($_SESSION[ADMIN_SESSION_KEY])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && $password !== '') {
        $admin = getAdminByUsername($username);

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION[ADMIN_SESSION_KEY] = true;
            header('Location: dashboard.php');
            exit;
        }

        $error = 'نام کاربری یا رمز عبور اشتباه است.';
    } else {
        $error = 'لطفاً نام کاربری و رمز عبور را وارد کنید.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود مدیر | <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<section class="section">
    <div class="container">
        <div class="form-card">
            <h2>ورود مدیر</h2>
            <?php if ($error): ?>
                <div class="error-box"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" action="">
                <div class="form-group">
                    <label for="username">نام کاربری</label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
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
