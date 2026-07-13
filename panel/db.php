<?php
// db.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\Connection;

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = Connection::getInstance();
        try {
            ensureSitePricesOrderColumn($pdo);
        } catch (Throwable $e) {}
    }
    return $pdo;
}

function ensureSitePricesOrderColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'site_prices' AND COLUMN_NAME = 'display_order'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE site_prices ADD COLUMN display_order INT DEFAULT 0");
    }
}

// ----- Price functions (unchanged) -----
function getPrices() {
    $stmt = db()->prepare("SELECT * FROM site_prices WHERE active = 1 ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getPrice($id) {
    $stmt = db()->prepare('SELECT * FROM site_prices WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function getAllPrices() {
    $stmt = db()->query("SELECT * FROM site_prices ORDER BY (display_order = 0) ASC, CASE WHEN display_order = 0 THEN id ELSE display_order END ASC");
    return $stmt->fetchAll();
}

// ----- Portfolio functions (unchanged) -----
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

// ----- User/Doctor functions (using users table) -----
function getAllDoctors() {
    $stmt = db()->query('SELECT id, full_name AS name, email, phone, notes, active FROM users WHERE role = "doctor" ORDER BY full_name ASC');
    return $stmt->fetchAll();
}

function getDoctor($id) {
    $stmt = db()->prepare('SELECT id, full_name AS name, email, phone, notes, active, last_login FROM users WHERE id = ? AND role = "doctor" LIMIT 1');
    $stmt->execute([(int) $id]);
    $result = $stmt->fetch();
    return $result;
}

function saveDoctor($data) {
    $now = date('Y-m-d H:i:s');
    $newPasswordHash = !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null;

    if (isset($data['id']) && !empty($data['id'])) {
        // Update existing user
        if ($newPasswordHash !== null) {
            $stmt = db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, active = ?, notes = ?, password_hash = ?, updated_at = ? WHERE id = ? AND role = "doctor"');
            $stmt->execute([
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['active'] ?? 1,
                $data['notes'] ?? null,
                $newPasswordHash,
                $now,
                (int) $data['id']
            ]);
        } else {
            $stmt = db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, active = ?, notes = ?, updated_at = ? WHERE id = ? AND role = "doctor"');
            $stmt->execute([
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['active'] ?? 1,
                $data['notes'] ?? null,
                $now,
                (int) $data['id']
            ]);
        }
        return (int) $data['id'];
    } else {
        // Insert new user with role 'doctor'
        $username = $data['email'] ?? $data['phone'] ?? 'doc_' . uniqid();
        $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, active, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "doctor", ?, ?, ?, ?)');
        $stmt->execute([
            $username,
            $newPasswordHash,
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['active'] ?? 1,
            $data['notes'] ?? null,
            $now,
            $now
        ]);
        return db()->lastInsertId();
    }
}

function deleteDoctor($id) {
    // Delete the user (cascade will handle foreign keys if set)
    $stmt = db()->prepare('DELETE FROM users WHERE id = ? AND role = "doctor"');
    $stmt->execute([(int) $id]);
}

// ----- Authentication helpers -----
function getUserByLogin($identifier) {
    $stmt = db()->prepare('SELECT * FROM users WHERE (username = ? OR email = ? OR phone = ?) AND active = 1 LIMIT 1');
    $stmt->execute([$identifier, $identifier, $identifier]);
    return $stmt->fetch();
}

// Legacy alias for doctors login (still works)
function getDoctorByLogin($identifier) {
    return getUserByLogin($identifier);
}

function updateDoctorLastLogin($id) {
    $stmt = db()->prepare('UPDATE users SET last_login = ? WHERE id = ?');
    $stmt->execute([date('Y-m-d H:i:s'), (int) $id]);
}

function setDoctorPassword($id, $plainPassword) {
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = "doctor"');
    $stmt->execute([$hash, (int) $id]);
}

// ----- Scoped data access (using users.id as doctor_id) -----
function getCaseForDoctor($caseId, $doctorId) {
    $stmt = db()->prepare('SELECT c.*, u.full_name AS doctor_name, p.title AS service_title, cs.name AS status_name,
            di.invoice_number, di.id AS invoice_id
        FROM cases c
        LEFT JOIN users u ON c.doctor_id = u.id
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN case_statuses cs ON c.status_id = cs.id
        LEFT JOIN doctor_invoices di ON c.invoice_id = di.id
        WHERE c.id = ? AND c.doctor_id = ?');
    $stmt->execute([(int) $caseId, (int) $doctorId]);
    return $stmt->fetch();
}

function getCaseFiles($caseId) {
    $stmt = db()->prepare('SELECT * FROM case_files WHERE case_id = ? ORDER BY id ASC');
    $stmt->execute([(int) $caseId]);
    return $stmt->fetchAll();
}

function getInvoicesForDoctor($doctorId) {
    $stmt = db()->prepare('SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
        FROM doctor_invoices i
        LEFT JOIN users u ON i.doctor_id = u.id
        WHERE i.doctor_id = ?
        ORDER BY i.invoice_date DESC, i.id DESC');
    $stmt->execute([(int) $doctorId]);
    return $stmt->fetchAll();
}

function getInvoiceForDoctor($invoiceId, $doctorId) {
    $stmt = db()->prepare('SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
        FROM doctor_invoices i
        LEFT JOIN users u ON i.doctor_id = u.id
        WHERE i.id = ? AND i.doctor_id = ?');
    $stmt->execute([(int) $invoiceId, (int) $doctorId]);
    return $stmt->fetch();
}

function getPaymentsForDoctor($doctorId) {
    $stmt = db()->prepare('SELECT p.*, b.bank_name, b.account_owner_name,
            COALESCE(u.full_name, p.doctor_name) AS doctor_name,
            GROUP_CONCAT(DISTINCT i.invoice_number SEPARATOR ", ") AS linked_invoices
        FROM doctor_payments p
        LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
        LEFT JOIN users u ON p.doctor_id = u.id
        LEFT JOIN doctor_payment_invoices pi ON pi.payment_id = p.id
        LEFT JOIN doctor_invoices i ON i.id = pi.invoice_id
        WHERE p.doctor_id = ?
        GROUP BY p.id
        ORDER BY p.payment_date DESC, p.id DESC');
    $stmt->execute([(int) $doctorId]);
    return $stmt->fetchAll();
}

// ----- Invoice and payment functions (global) -----
function getAllInvoices() {
    $stmt = db()->query('SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
        FROM doctor_invoices i
        LEFT JOIN users u ON i.doctor_id = u.id
        ORDER BY invoice_date DESC, id DESC');
    return $stmt->fetchAll();
}

function getInvoice($id) {
    $stmt = db()->prepare('SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name,
            COALESCE(u.phone, i.doctor_phone) AS doctor_phone,
            COALESCE(u.email, i.doctor_email) AS doctor_email,
            u.id AS doctor_id,
            b.account_owner_name AS bank_owner, b.bank_name, b.account_number, b.card_number, b.iban_sheba
        FROM doctor_invoices i
        LEFT JOIN users u ON i.doctor_id = u.id
        LEFT JOIN bank_accounts b ON i.bank_account_id = b.id
        WHERE i.id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch();
}

function getInvoiceItems($invoice_id) {
    $stmt = db()->prepare('SELECT ii.*, p.title AS price_title
        FROM invoice_items ii
        LEFT JOIN site_prices p ON ii.price_id = p.id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC');
    $stmt->execute([(int) $invoice_id]);
    $items = $stmt->fetchAll();
    // Round amounts to integers (Toman has no decimals)
    foreach ($items as &$item) {
        $item['unit_price'] = round((float) $item['unit_price']);
        $item['total_amount'] = round((float) $item['total_amount']);
    }
    return $items;
}

function saveInvoice($data) {
    $now = date('Y-m-d H:i:s');
    $items = [];
    foreach ($data['items'] ?? [] as $item) {
        $itemDescription = trim($item['item_description'] ?? '');
        $itemTitle = trim($item['item_title'] ?? '');
        if ($itemTitle === '' && $itemDescription !== '') $itemTitle = $itemDescription;
        if ($itemTitle === '') continue;
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $items[] = [
            'price_id' => !empty($item['price_id']) ? (int) $item['price_id'] : null,
            'case_id' => !empty($item['case_id']) ? (int) $item['case_id'] : null,
            'item_title' => $itemTitle,
            'item_description' => $itemDescription ?: null,
            'patient_name' => trim($item['patient_name'] ?? '') ?: null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => round($quantity * $unitPrice),
        ];
    }
    $totalAmount = round(array_sum(array_column($items, 'total_amount')));

    $bankAccountId = !empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;

    if (isset($data['id']) && !empty($data['id'])) {
        $stmt = db()->prepare('UPDATE doctor_invoices SET invoice_number = ?, doctor_id = ?, doctor_name = ?, doctor_phone = ?, doctor_email = ?, total_amount = ?, payment_status = ?, invoice_date = ?, due_date = ?, notes = ?, bank_account_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['invoice_number'],
            $data['doctor_id'] ?: null,
            $data['doctor_name'] ?? null,
            $data['doctor_phone'] ?? null,
            $data['doctor_email'] ?? null,
            $totalAmount,
            $data['payment_status'] ?? 'unpaid',
            $data['invoice_date'],
            $data['due_date'] ?? null,
            $data['notes'] ?? null,
            $bankAccountId,
            $now,
            (int) $data['id']
        ]);
        $invoiceId = (int) $data['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO doctor_invoices (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, due_date, notes, bank_account_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['invoice_number'],
            $data['doctor_id'] ?: null,
            $data['doctor_name'] ?? null,
            $data['doctor_phone'] ?? null,
            $data['doctor_email'] ?? null,
            $totalAmount,
            $data['payment_status'] ?? 'unpaid',
            $data['invoice_date'],
            $data['due_date'] ?? null,
            $data['notes'] ?? null,
            $bankAccountId,
            $now
        ]);
        $invoiceId = db()->lastInsertId();
    }

    // Delete old items and insert new ones (with case_id support)
    $del = db()->prepare('DELETE FROM invoice_items WHERE invoice_id = ?');
    $del->execute([$invoiceId]);
    $ins = db()->prepare('INSERT INTO invoice_items (invoice_id, price_id, case_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $item) {
        $ins->execute([
            $invoiceId,
            $item['price_id'],
            $item['case_id'],
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
    // Clear invoice_id from cases
    $stmt = db()->prepare('UPDATE cases SET invoice_id = NULL WHERE invoice_id = ?');
    $stmt->execute([(int) $id]);

    // Delete the invoice
    $stmt = db()->prepare('DELETE FROM doctor_invoices WHERE id = ?');
    $stmt->execute([(int) $id]);
}

// ----- Payment functions -----
function getAllPayments() {
    $stmt = db()->query('SELECT p.*, b.bank_name, b.account_owner_name,
            COALESCE(u.full_name, p.doctor_name) AS doctor_name,
            GROUP_CONCAT(DISTINCT i.invoice_number SEPARATOR ", ") AS linked_invoices
        FROM doctor_payments p
        LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
        LEFT JOIN users u ON p.doctor_id = u.id
        LEFT JOIN doctor_payment_invoices pi ON pi.payment_id = p.id
        LEFT JOIN doctor_invoices i ON i.id = pi.invoice_id
        GROUP BY p.id
        ORDER BY p.payment_date DESC, p.id DESC');
    return $stmt->fetchAll();
}

function getPayment($id) {
    $stmt = db()->prepare('SELECT p.*, b.bank_name, b.account_owner_name,
            COALESCE(u.full_name, p.doctor_name) AS doctor_name
        FROM doctor_payments p
        LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
        LEFT JOIN users u ON p.doctor_id = u.id
        WHERE p.id = ?');
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
            $data['doctor_id'] ?: null,
            $data['doctor_name'],
            $data['amount'],
            $data['payment_method'],
            $data['payment_date'],
            $data['transaction_number'] ?? null,
            $data['bank_account_id'] ?? null,
            $data['notes'] ?? null,
            $now,
            (int) $data['id']
        ]);
        $paymentId = (int) $data['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO doctor_payments (doctor_id, doctor_name, amount, payment_method, payment_date, transaction_number, bank_account_id, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['doctor_id'] ?: null,
            $data['doctor_name'],
            $data['amount'],
            $data['payment_method'],
            $data['payment_date'],
            $data['transaction_number'] ?? null,
            $data['bank_account_id'] ?? null,
            $data['notes'] ?? null,
            $now
        ]);
        $paymentId = db()->lastInsertId();
    }

    // Update payment-invoice links
    $del = db()->prepare('DELETE FROM doctor_payment_invoices WHERE payment_id = ?');
    $del->execute([$paymentId]);
    $ins = db()->prepare('INSERT INTO doctor_payment_invoices (payment_id, invoice_id, amount_applied) VALUES (?, ?, ?)');
    foreach ($data['invoice_ids'] ?? [] as $invoiceId) {
        $ins->execute([$paymentId, (int) $invoiceId, $data['amount_applied'] ?? 0]);
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

// ----- Bank Account functions (unchanged) -----
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
            $data['account_owner_name'],
            $data['bank_name'],
            $data['account_number'] ?: null,
            $data['card_number'] ?: null,
            $data['iban_sheba'] ?: null,
            $data['is_active'] ?? 1,
            $data['notes'] ?? null,
            $now,
            (int) $data['id']
        ]);
        return (int) $data['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO bank_accounts (account_owner_name, bank_name, account_number, card_number, iban_sheba, is_active, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['account_owner_name'],
            $data['bank_name'],
            $data['account_number'] ?: null,
            $data['card_number'] ?: null,
            $data['iban_sheba'] ?: null,
            $data['is_active'] ?? 1,
            $data['notes'] ?? null,
            $now
        ]);
        return db()->lastInsertId();
    }
}

function deleteBankAccount($id) {
    $stmt = db()->prepare('DELETE FROM bank_accounts WHERE id = ?');
    $stmt->execute([(int) $id]);
}

function calculateDoctorDebt($doctor_id) {
    $stmt = db()->prepare("SELECT COALESCE(SUM(i.total_amount), 0) - COALESCE(SUM(p.amount), 0) as debt
        FROM doctor_invoices i
        LEFT JOIN doctor_payments p ON p.doctor_id = i.doctor_id
        WHERE i.doctor_id = ?");
    $stmt->execute([(int) $doctor_id]);
    return $stmt->fetchColumn();
}
// =====================================================
// Doctor Price Overrides
// =====================================================

/**
 * Get a single override for a doctor and service.
 * @return array|null
 */
function getDoctorPriceOverride($doctor_id, $service_id) {
    $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND service_id = ?');
    $stmt->execute([(int)$doctor_id, (int)$service_id]);
    return $stmt->fetch();
}

/**
 * Get the applicable price for a doctor+service combination.
 * Returns the override price if set, otherwise the default price from site_prices.
 * @return float|null
 */
function getApplicablePrice($doctor_id, $service_id) {
    $override = getDoctorPriceOverride($doctor_id, $service_id);
    if ($override) {
        return (float) $override['custom_price'];
    }
    // fallback to default
    $price = getPrice($service_id);
    return $price ? (float) $price['price'] : null;
}

/**
 * Get all overrides (admin listing) with doctor name and service title.
 */
function getAllDoctorPriceOverrides() {
    $stmt = db()->query('
        SELECT o.*, u.full_name AS doctor_name, p.title AS service_title
        FROM doctor_price_overrides o
        LEFT JOIN users u ON o.doctor_id = u.id
        LEFT JOIN site_prices p ON o.service_id = p.id
        ORDER BY u.full_name, p.title
    ');
    return $stmt->fetchAll();
}

/**
 * Get overrides for a specific doctor.
 */
function getDoctorPriceOverrides($doctor_id) {
    $stmt = db()->prepare('
        SELECT o.*, p.title AS service_title
        FROM doctor_price_overrides o
        LEFT JOIN site_prices p ON o.service_id = p.id
        WHERE o.doctor_id = ?
        ORDER BY p.title
    ');
    $stmt->execute([(int)$doctor_id]);
    return $stmt->fetchAll();
}

/**
 * Save (insert or update) a price override.
 * $data must contain: doctor_id, service_id, custom_price.
 * If an override already exists for that doctor+service, update it.
 * Returns the override ID.
 */
function saveDoctorPriceOverride($data) {
    $doctor_id = (int) $data['doctor_id'];
    $service_id = (int) $data['service_id'];
    $custom_price = (float) $data['custom_price'];

    // Check if exists
    $existing = getDoctorPriceOverride($doctor_id, $service_id);
    if ($existing) {
        $stmt = db()->prepare('UPDATE doctor_price_overrides SET custom_price = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$custom_price, $existing['id']]);
        return $existing['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO doctor_price_overrides (doctor_id, service_id, custom_price, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
        $stmt->execute([$doctor_id, $service_id, $custom_price]);
        return db()->lastInsertId();
    }
}

/**
 * Delete a price override by ID.
 */
function deleteDoctorPriceOverride($id) {
    $stmt = db()->prepare('DELETE FROM doctor_price_overrides WHERE id = ?');
    $stmt->execute([(int)$id]);
}
// =====================================================
// Functions for monthly invoice generation
// =====================================================

/**
 * Get uninvoiced completed cases for a doctor within a date range.
 * @param int $doctor_id
 * @param string $startDate YYYY-MM-DD
 * @param string $endDate   YYYY-MM-DD
 * @return array
 */
function getUninvoicedCasesForDoctor($doctor_id, $startDate, $endDate) {
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        WHERE c.doctor_id = ?
          AND c.status_id = 4   -- Delivered
          AND c.invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
        ORDER BY c.received_date ASC
    ');
    $stmt->execute([$doctor_id, $startDate, $endDate]);
    return $stmt->fetchAll();
}

/**
 * Get outstanding balance (sum of unpaid invoices) for a doctor.
 * @param int $doctor_id
 * @return float
 */
function getOutstandingBalance($doctor_id) {
    $stmt = db()->prepare('
        SELECT COALESCE(SUM(total_amount), 0) 
        FROM doctor_invoices 
        WHERE doctor_id = ? AND payment_status = "unpaid"
    ');
    $stmt->execute([$doctor_id]);
    return (float) $stmt->fetchColumn();
}

/**
 * Create a monthly invoice from a list of cases and an optional balance.
 * @param int   $doctor_id
 * @param array $cases        Array of case rows (from getUninvoicedCasesForDoctor)
 * @param float $balance      Outstanding balance from previous months
 * @param string $invoiceDate YYYY-MM-DD (usually today)
 * @return int invoice_id
 */
function createMonthlyInvoice($doctor_id, $cases, $balance, $invoiceDate, $bankAccountId = null) {
    $now = date('Y-m-d H:i:s');
    
    // Generate a unique invoice number (e.g., INV-YYYYMM-001)
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $stmt = db()->prepare('SELECT MAX(invoice_number) FROM doctor_invoices WHERE invoice_number LIKE ?');
    $stmt->execute(['INV-' . $yearMonth . '-%']);
    $max = $stmt->fetchColumn();
    if ($max) {
        $parts = explode('-', $max);
        $num = (int) $parts[2] + 1;
    } else {
        $num = 1;
    }
    $invoiceNumber = 'INV-' . $yearMonth . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);

    // Calculate total amount
    $total = $balance;
    foreach ($cases as $case) {
        $total += (float) $case['total_price'];
    }

    // Insert invoice
    $stmt = db()->prepare('
        INSERT INTO doctor_invoices 
        (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, notes, bank_account_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    // Get doctor info
    $doctor = getDoctor($doctor_id);
    $stmt->execute([
        $invoiceNumber,
        $doctor_id,
        $doctor['name'] ?? '',
        $doctor['phone'] ?? '',
        $doctor['email'] ?? '',
        $total,
        'unpaid',
        $invoiceDate,
        'فاکتور ماهانه خودکار',
        $bankAccountId,
        $now
    ]);
    $invoiceId = db()->lastInsertId();

    // Insert invoice items
    // Insert invoice items
    foreach ($cases as $case) {
        $locationStr = formatCaseLocation($case['location_type'], $case['teeth']);
        $description = 'کیس #' . $case['id'];
        if ($locationStr !== '—') {
            $description .= ' - ' . $locationStr;
        }
        $stmt = db()->prepare('
            INSERT INTO invoice_items 
            (invoice_id, price_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $invoiceId,
            $case['service_id'],
            $case['service_title'] ?? 'خدمت',
            $description,
            $case['patient_name'],
            $case['quantity'] ?? 1,        // <-- use case quantity
            $case['unit_price'] ?? 0,
            $case['total_price'] ?? 0,
            $now
        ]);
    }

    // If there is a balance, add a separate item
    if ($balance > 0) {
        $stmt = db()->prepare('
            INSERT INTO invoice_items 
            (invoice_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $invoiceId,
            'مانده بدهی از ماه قبل',
            'بدهی معوق از فاکتورهای قبلی',
            '',
            1,
            $balance,
            $balance,
            $now
        ]);
    }

    // Mark cases as invoiced
    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }

    return $invoiceId;
}