<?php
require_once __DIR__ . '/config.php';

function db() {
    static $pdo;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]);
        // ensure site_prices has a display_order column for manual ordering
        try {
            ensureSitePricesOrderColumn($pdo);
        } catch (Throwable $e) {
            // fail silently — site will continue to work without the column
        }
    }

    return $pdo;
}

function ensureSitePricesOrderColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'site_prices' AND COLUMN_NAME = 'display_order'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        // add column if it does not exist
        $pdo->exec("ALTER TABLE site_prices ADD COLUMN display_order INT DEFAULT 0");
    }
}

function getPrices() {
    // Items with a non-zero display_order come first (ordered by display_order).
    // Items with display_order = 0 are ordered by id (older/low id first).
    $stmt = db()->prepare("SELECT * FROM site_prices WHERE active = 1 ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getPrice($id) {
    $stmt = db()->prepare('SELECT * FROM site_prices WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function getAdminByUsername($username) {
    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    return $stmt->fetch();
}

function getAllPrices() {
    $stmt = db()->query("SELECT * FROM site_prices ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    return $stmt->fetchAll();
}

function getPortfolioWorks() {
    $stmt = db()->prepare("SELECT * FROM portfolio_works WHERE active = 1 ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getPortfolioWork($id) {
    $stmt = db()->prepare('SELECT * FROM portfolio_works WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function getAllPortfolioWorks() {
    $stmt = db()->query("SELECT * FROM portfolio_works ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    return $stmt->fetchAll();
}

// Doctor functions
function getAllDoctors() {
    $stmt = db()->query('SELECT * FROM doctors ORDER BY active DESC, name ASC');
    return $stmt->fetchAll();
}

function getDoctor($id) {
    $stmt = db()->prepare('SELECT * FROM doctors WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function saveDoctor($data) {
    $now = date('Y-m-d H:i:s');
    if (isset($data['id']) && !empty($data['id'])) {
        $stmt = db()->prepare('UPDATE doctors SET name = ?, phone = ?, email = ?, active = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['name'], $data['phone'] ?? null, $data['email'] ?? null,
            $data['active'] ?? 1, $now, (int) $data['id']
        ]);
        return (int) $data['id'];
    }
    $stmt = db()->prepare('INSERT INTO doctors (name, phone, email, active, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['name'], $data['phone'] ?? null, $data['email'] ?? null,
        $data['active'] ?? 1, $now
    ]);
    return db()->lastInsertId();
}

function deleteDoctor($id) {
    $stmt = db()->prepare('DELETE FROM doctors WHERE id = ?');
    $stmt->execute([(int) $id]);
}

// Invoice and invoice item functions
function getAllInvoices() {
    $stmt = db()->query('SELECT i.*, COALESCE(d.name, i.doctor_name) AS doctor_name FROM doctor_invoices i LEFT JOIN doctors d ON i.doctor_id = d.id ORDER BY invoice_date DESC, id DESC');
    return $stmt->fetchAll();
}

function getInvoice($id) {
    $stmt = db()->prepare('SELECT i.*, COALESCE(d.name, i.doctor_name) AS doctor_name, COALESCE(d.phone, i.doctor_phone) AS doctor_phone, COALESCE(d.email, i.doctor_email) AS doctor_email, d.id AS doctor_id FROM doctor_invoices i LEFT JOIN doctors d ON i.doctor_id = d.id WHERE i.id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function getInvoiceItems($invoice_id) {
    $stmt = db()->prepare('SELECT ii.*, p.title AS price_title FROM invoice_items ii LEFT JOIN site_prices p ON ii.price_id = p.id WHERE ii.invoice_id = ? ORDER BY ii.id ASC');
    $stmt->execute([(int) $invoice_id]);
    return $stmt->fetchAll();
}

function saveInvoice($data) {
    $now = date('Y-m-d H:i:s');
    $rawItems = $data['items'] ?? [];
    $items = [];

    foreach ($rawItems as $item) {
        $itemDescription = trim($item['item_description'] ?? '');
        $itemTitle = trim($item['item_title'] ?? '');
        if ($itemTitle === '' && $itemDescription !== '') {
            $itemTitle = $itemDescription;
        }

        if ($itemTitle === '') {
            continue;
        }

        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $itemTotal = $quantity * $unitPrice;

        $items[] = [
            'price_id' => !empty($item['price_id']) ? (int) $item['price_id'] : null,
            'item_title' => $itemTitle,
            'item_description' => $itemDescription ?: null,
            'patient_name' => trim($item['patient_name'] ?? '') ?: null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => $itemTotal,
        ];
    }

    $totalAmount = array_sum(array_column($items, 'total_amount'));

    if (isset($data['id']) && !empty($data['id'])) {
        $stmt = db()->prepare('UPDATE doctor_invoices SET invoice_number = ?, doctor_id = ?, doctor_name = ?, doctor_phone = ?, doctor_email = ?, total_amount = ?, payment_status = ?, invoice_date = ?, due_date = ?, notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['invoice_number'], $data['doctor_id'] ?: null, $data['doctor_name'] ?? null,
            $data['doctor_phone'] ?? null, $data['doctor_email'] ?? null, $totalAmount,
            $data['payment_status'] ?? 'unpaid', $data['invoice_date'], $data['due_date'] ?? null,
            $data['notes'] ?? null, $now, (int) $data['id']
        ]);
        $invoiceId = (int) $data['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO doctor_invoices (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, due_date, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['invoice_number'], $data['doctor_id'] ?: null, $data['doctor_name'] ?? null,
            $data['doctor_phone'] ?? null, $data['doctor_email'] ?? null, $totalAmount,
            $data['payment_status'] ?? 'unpaid', $data['invoice_date'], $data['due_date'] ?? null,
            $data['notes'] ?? null, $now
        ]);
        $invoiceId = db()->lastInsertId();
    }

    $stmt = db()->prepare('DELETE FROM invoice_items WHERE invoice_id = ?');
    $stmt->execute([$invoiceId]);
    $stmt = db()->prepare('INSERT INTO invoice_items (invoice_id, price_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    foreach ($items as $item) {
        $stmt->execute([
            $invoiceId,
            $item['price_id'],
            $item['item_title'],
            $item['item_description'],
            $item['patient_name'],
            $item['quantity'],
            $item['unit_price'],
            $item['total_amount'],
            $now
        ]);
    }

    return $invoiceId;
}

function deleteInvoice($id) {
    $stmt = db()->prepare('DELETE FROM doctor_invoices WHERE id = ?');
    $stmt->execute([(int) $id]);
}

// Payment functions
function getAllPayments() {
    $stmt = db()->query('SELECT p.*, b.bank_name, b.account_owner_name, COALESCE(d.name, p.doctor_name) AS doctor_name, GROUP_CONCAT(DISTINCT i.invoice_number SEPARATOR ", ") AS linked_invoices FROM doctor_payments p LEFT JOIN bank_accounts b ON p.bank_account_id = b.id LEFT JOIN doctors d ON p.doctor_id = d.id LEFT JOIN doctor_payment_invoices pi ON pi.payment_id = p.id LEFT JOIN doctor_invoices i ON i.id = pi.invoice_id GROUP BY p.id ORDER BY p.payment_date DESC, p.id DESC');
    return $stmt->fetchAll();
}

function getPayment($id) {
    $stmt = db()->prepare('SELECT p.*, b.bank_name, b.account_owner_name, COALESCE(d.name, p.doctor_name) AS doctor_name FROM doctor_payments p LEFT JOIN bank_accounts b ON p.bank_account_id = b.id LEFT JOIN doctors d ON p.doctor_id = d.id WHERE p.id = ?');
    $stmt->execute([(int) $id]);
    $payment = $stmt->fetch();
    if ($payment) {
        $payment['invoice_ids'] = getPaymentInvoiceIds($payment['id']);
    }
    return $payment;
}

function getPaymentInvoiceIds($payment_id) {
    $stmt = db()->prepare('SELECT invoice_id FROM doctor_payment_invoices WHERE payment_id = ?');
    $stmt->execute([(int) $payment_id]);
    return array_map('current', $stmt->fetchAll(PDO::FETCH_NUM));
}

function savePayment($data) {
    $now = date('Y-m-d H:i:s');
    if (isset($data['id']) && !empty($data['id'])) {
        $stmt = db()->prepare('UPDATE doctor_payments SET doctor_id = ?, doctor_name = ?, amount = ?, payment_method = ?, payment_date = ?, transaction_number = ?, bank_account_id = ?, notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['doctor_id'] ?: null, $data['doctor_name'], $data['amount'], $data['payment_method'],
            $data['payment_date'], $data['transaction_number'] ?? null, $data['bank_account_id'] ?? null,
            $data['notes'] ?? null, $now, (int) $data['id']
        ]);
        $paymentId = (int) $data['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO doctor_payments (doctor_id, doctor_name, amount, payment_method, payment_date, transaction_number, bank_account_id, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['doctor_id'] ?: null, $data['doctor_name'], $data['amount'], $data['payment_method'],
            $data['payment_date'], $data['transaction_number'] ?? null, $data['bank_account_id'] ?? null,
            $data['notes'] ?? null, $now
        ]);
        $paymentId = db()->lastInsertId();
    }

    $stmt = db()->prepare('DELETE FROM doctor_payment_invoices WHERE payment_id = ?');
    $stmt->execute([$paymentId]);

    $stmt = db()->prepare('INSERT INTO doctor_payment_invoices (payment_id, invoice_id, amount_applied) VALUES (?, ?, ?)');
    $invoiceIds = $data['invoice_ids'] ?? [];
    foreach ($invoiceIds as $invoiceId) {
        $stmt->execute([$paymentId, (int) $invoiceId, $data['amount_applied'] ?? 0]);
    }

    return $paymentId;
}

function deletePayment($id) {
    $stmt = db()->prepare('DELETE FROM doctor_payments WHERE id = ?');
    $stmt->execute([(int) $id]);
}

function getAllInvoiceLinks($invoice_id) {
    $stmt = db()->prepare('SELECT p.* FROM doctor_payments p JOIN doctor_payment_invoices pi ON pi.payment_id = p.id WHERE pi.invoice_id = ?');
    $stmt->execute([(int) $invoice_id]);
    return $stmt->fetchAll();
}

function getAllBankAccounts() {
    $stmt = db()->query('SELECT * FROM bank_accounts ORDER BY is_active DESC, id DESC');
    return $stmt->fetchAll();
}

function getBankAccount($id) {
    $stmt = db()->prepare('SELECT * FROM bank_accounts WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function saveBankAccount($data) {
    $now = date('Y-m-d H:i:s');
    if (isset($data['id']) && !empty($data['id'])) {
        $stmt = db()->prepare('UPDATE bank_accounts SET account_owner_name = ?, bank_name = ?, account_number = ?, card_number = ?, iban_sheba = ?, is_active = ?, notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['account_owner_name'], $data['bank_name'], $data['account_number'] ?: null,
            $data['card_number'] ?: null, $data['iban_sheba'] ?: null,
            $data['is_active'] ?? 1, $data['notes'] ?? null, $now, (int) $data['id']
        ]);
        return (int) $data['id'];
    }
    $stmt = db()->prepare('INSERT INTO bank_accounts (account_owner_name, bank_name, account_number, card_number, iban_sheba, is_active, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['account_owner_name'], $data['bank_name'], $data['account_number'] ?: null,
        $data['card_number'] ?: null, $data['iban_sheba'] ?: null,
        $data['is_active'] ?? 1, $data['notes'] ?? null, $now
    ]);
    return db()->lastInsertId();
}

function deleteBankAccount($id) {
    $stmt = db()->prepare('DELETE FROM bank_accounts WHERE id = ?');
    $stmt->execute([(int) $id]);
}

function calculateDoctorDebt($doctor_name) {
    $stmt = db()->prepare("SELECT COALESCE(SUM(i.total_amount), 0) - COALESCE(SUM(p.amount), 0) as debt FROM doctor_invoices i LEFT JOIN doctor_payments p ON (i.doctor_id IS NOT NULL AND p.doctor_id = i.doctor_id) OR (i.doctor_name = p.doctor_name) WHERE i.doctor_name = ? OR i.doctor_id IN (SELECT id FROM doctors WHERE name = ?) GROUP BY i.doctor_name");
    $stmt->execute([$doctor_name, $doctor_name]);
    return $stmt->fetchColumn();
}
