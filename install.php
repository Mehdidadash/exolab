<?php
require_once __DIR__ . '/db.php';

function columnExists($table, $column) {
    $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function addColumnIfNotExists($table, $column, $definition) {
    if (!columnExists($table, $column)) {
        db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function tableExists($table) {
    $stmt = db()->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetch();
}

function createTables() {
    $created = [];

    $tables = [
        'admins' => "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            name VARCHAR(150) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        'site_prices' => "CREATE TABLE IF NOT EXISTS site_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            price VARCHAR(100) NOT NULL,
            display_order INT DEFAULT 0,
            category VARCHAR(120) DEFAULT 'عمومی',
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        'portfolio_works' => "CREATE TABLE IF NOT EXISTS portfolio_works (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            image_filename VARCHAR(255) NOT NULL UNIQUE,
            active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        'bank_accounts' => "CREATE TABLE IF NOT EXISTS bank_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_owner_name VARCHAR(200) NOT NULL,
            bank_name VARCHAR(200) NOT NULL,
            account_number VARCHAR(50),
            card_number VARCHAR(50),
            iban_sheba VARCHAR(50),
            is_active TINYINT(1) DEFAULT 1,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        'doctors' => "CREATE TABLE IF NOT EXISTS doctors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(50),
            email VARCHAR(120),
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        'doctor_invoices' => "CREATE TABLE IF NOT EXISTS doctor_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) NOT NULL UNIQUE,
            doctor_id INT DEFAULT NULL,
            doctor_name VARCHAR(255) DEFAULT NULL,
            doctor_phone VARCHAR(20) DEFAULT NULL,
            doctor_email VARCHAR(120) DEFAULT NULL,
            total_amount DECIMAL(15, 2) DEFAULT 0,
            payment_status VARCHAR(20) DEFAULT 'unpaid',
            invoice_date DATE,
            due_date DATE,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        'invoice_items' => "CREATE TABLE IF NOT EXISTS invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            price_id INT DEFAULT NULL,
            item_title VARCHAR(255) NOT NULL,
            item_description TEXT,
            patient_name VARCHAR(255),
            quantity INT DEFAULT 1,
            unit_price DECIMAL(15, 2) DEFAULT 0,
            total_amount DECIMAL(15, 2) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES doctor_invoices(id) ON DELETE CASCADE,
            FOREIGN KEY (price_id) REFERENCES site_prices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        'doctor_payments' => "CREATE TABLE IF NOT EXISTS doctor_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doctor_id INT DEFAULT NULL,
            doctor_name VARCHAR(255) NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL COMMENT 'کارت به کارت, شبا, نقدی, چک',
            payment_date DATE NOT NULL,
            transaction_number VARCHAR(100),
            bank_account_id INT,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
            FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        'doctor_payment_invoices' => "CREATE TABLE IF NOT EXISTS doctor_payment_invoices (
            payment_id INT NOT NULL,
            invoice_id INT NOT NULL,
            amount_applied DECIMAL(15, 2) DEFAULT 0,
            PRIMARY KEY (payment_id, invoice_id),
            FOREIGN KEY (payment_id) REFERENCES doctor_payments(id) ON DELETE CASCADE,
            FOREIGN KEY (invoice_id) REFERENCES doctor_invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    foreach ($tables as $name => $statement) {
        if (!tableExists($name)) {
            db()->exec($statement);
            $created[] = "table:$name";
        } else {
            db()->exec($statement);
        }
    }

    $columns = [
        ['site_prices', 'display_order', 'INT DEFAULT 0'],
        ['site_prices', 'category', "VARCHAR(120) DEFAULT 'عمومی'"],
        ['site_prices', 'active', 'TINYINT(1) DEFAULT 1'],
        ['portfolio_works', 'display_order', 'INT DEFAULT 0'],
        ['bank_accounts', 'card_number', 'VARCHAR(50)'],
        ['bank_accounts', 'iban_sheba', 'VARCHAR(50)'],
        ['bank_accounts', 'is_active', 'TINYINT(1) DEFAULT 1'],
        ['doctors', 'active', 'TINYINT(1) DEFAULT 1'],
        ['doctor_invoices', 'doctor_id', 'INT DEFAULT NULL'],
        ['doctor_invoices', 'doctor_name', 'VARCHAR(255) DEFAULT NULL'],
        ['doctor_invoices', 'doctor_phone', 'VARCHAR(20) DEFAULT NULL'],
        ['doctor_invoices', 'doctor_email', 'VARCHAR(120) DEFAULT NULL'],
        ['doctor_invoices', 'total_amount', 'DECIMAL(15, 2) DEFAULT 0'],
        ['doctor_invoices', 'payment_status', "VARCHAR(20) DEFAULT 'unpaid'"],
        ['doctor_invoices', 'due_date', 'DATE'],
        ['invoice_items', 'price_id', 'INT DEFAULT NULL'],
        ['invoice_items', 'patient_name', 'VARCHAR(255)'],
        ['invoice_items', 'quantity', 'INT DEFAULT 1'],
        ['invoice_items', 'unit_price', 'DECIMAL(15, 2) DEFAULT 0'],
        ['invoice_items', 'total_amount', 'DECIMAL(15, 2) DEFAULT 0'],
        ['doctor_payments', 'doctor_id', 'INT DEFAULT NULL'],
        ['doctor_payments', 'doctor_name', 'VARCHAR(255) NOT NULL'],
        ['doctor_payments', 'payment_method', 'VARCHAR(50) NOT NULL'],
        ['doctor_payments', 'transaction_number', 'VARCHAR(100)'],
        ['doctor_payments', 'bank_account_id', 'INT'],
        ['doctor_payment_invoices', 'amount_applied', 'DECIMAL(15, 2) DEFAULT 0'],
    ];

    foreach ($columns as [$table, $column, $definition]) {
        if (!columnExists($table, $column)) {
            addColumnIfNotExists($table, $column, $definition);
            $created[] = "column:$table.$column";
        }
    }

    return $created;
}

function createDefaultAdmin() {
    $stmt = db()->query('SELECT COUNT(*) AS total FROM admins');
    $row = $stmt->fetch();

    if ($row['total'] == 0) {
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = db()->prepare('INSERT INTO admins (username, password, name) VALUES (?, ?, ?)');
        $stmt->execute(['admin', $password, 'مدیر سایت']);
        return true;
    }

    return false;
}

$message = '';
$error = '';

try {
    $createdItems = createTables();
    $adminCreated = createDefaultAdmin();

    if ($adminCreated || !empty($createdItems)) {
        $message = 'پایگاه داده نصب یا به‌روزرسانی شد.';
        if ($adminCreated) {
            $message .= "\nکاربر مدیر اولیه با موفقیت ایجاد شد. نام کاربری: admin | رمز عبور: admin123";
        }
        if (!empty($createdItems)) {
            $message .= '\nموارد زیر ایجاد یا کامل شدند:';
            foreach ($createdItems as $item) {
                [$type, $name] = explode(':', $item, 2);
                if ($type === 'table') {
                    $message .= "\n - جدول $name";
                } else {
                    $message .= "\n - ستون $name";
                }
            }
        }
        $message .= '\nلطفاً پس از ورود، این فایل را حذف کنید.';
    } else {
        $message = 'جدول‌ها و کاربر مدیر اولیه قبلاً ایجاد شده‌اند. اگر هنوز جدول‌های جدید را نمی‌بینید، لطفاً دیتابیس را بررسی کنید و دوباره نصب را اجرا کنید.';
    }
} catch (Exception $exception) {
    $error = 'خطا در اتصال یا ایجاد پایگاه داده: ' . $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نصب اولیه اگزولب</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container" style="padding: 60px 0;">
    <div class="form-card">
        <h2>نصب اولیه سایت</h2>
        <?php if ($message): ?>
            <div class="badge"><?= nl2br(htmlspecialchars($message)) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <p>اگر نصب با موفقیت انجام شد، لطفاً <strong>فایل install.php</strong> را از روی هاست حذف کنید تا امنیت سایت حفظ شود.</p>
    </div>
</div>
</body>
</html>
