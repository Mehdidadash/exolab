<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
session_start();

if (empty($_SESSION[ADMIN_SESSION_KEY])) {
    header('Location: login.php');
    exit;
}

function admin_layout_start($title = 'پنل مدیریت') {
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
                <span>پنل مدیریت</span>
            </a>
            <nav class="site-nav">
                <a href="dashboard.php">داشبورد</a>
                <a href="prices.php">قیمت</a>
                <a href="doctors.php">پزشکان</a>
                <a href="works.php">نمونه کار</a>
                <a href="bank_accounts.php">حساب‌های بانکی</a>
                <a href="invoices.php">فاکتورها</a>
                <a href="payments.php">پرداخت‌ها</a>
                <a href="logout.php">خروج</a>
            </nav>
        </div>
    </header>
    <main class="section">
        <div class="container">
            <h2><?= htmlspecialchars($title) ?></h2>
            <?php
}

function admin_layout_end() {
    ?>
        </div>
    </main>
    </body>
    </html>
    <?php
}
