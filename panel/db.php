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
            ensureEntityCommentsTable($pdo);
            ensureNotificationsTable($pdo);
            ensureCasesDesignFeeColumn($pdo);
            ensureDoctorPriceOverrideTypeColumn($pdo);
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

function ensureCasesDesignFeeColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'design_fee'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN design_fee DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total_price");
    }
}

function ensureDoctorPriceOverrideTypeColumn($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'doctor_price_overrides' AND COLUMN_NAME = 'price_type'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("ALTER TABLE doctor_price_overrides ADD COLUMN price_type VARCHAR(30) NOT NULL DEFAULT 'service' AFTER doctor_id");
    }

    try {
        $pdo->exec("ALTER TABLE doctor_price_overrides MODIFY COLUMN service_id INT NULL");
    } catch (Throwable $e) {}
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

function getAllBillingTargets() {
    $stmt = db()->query('SELECT id, full_name AS name, role, email, phone, notes, active FROM users WHERE role IN ("doctor", "clinic", "designer", "partner_lab", "customer_lab", "outsource_lab", "lab") ORDER BY full_name ASC');
    return $stmt->fetchAll();
}

function getAllDoctorAndClinicUsers() {
    $stmt = db()->query('SELECT id, full_name AS name, role, active FROM users WHERE role IN ("doctor", "clinic") ORDER BY full_name ASC');
    return $stmt->fetchAll();
}

function getDoctor($id) {
    $stmt = db()->prepare('SELECT id, full_name AS name, email, phone, notes, active, clinic_id, last_login FROM users WHERE id = ? AND role = "doctor" LIMIT 1');
    $stmt->execute([(int) $id]);
    $result = $stmt->fetch();
    return $result;
}

/** Doctor profile gallery (photos + captions showing work style/taste). */
function getDoctorGallery(int $doctorId): array {
    $stmt = db()->prepare('SELECT * FROM doctor_gallery WHERE doctor_id = ? ORDER BY id DESC');
    $stmt->execute([$doctorId]);
    return $stmt->fetchAll();
}

function saveDoctor($data) {
    $now = date('Y-m-d H:i:s');
    $newPasswordHash = !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null;
    $clinicId = !empty($data['clinic_id']) ? (int) $data['clinic_id'] : null;

    if (isset($data['id']) && !empty($data['id'])) {
        // Update existing user
        if ($newPasswordHash !== null) {
            $stmt = db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, active = ?, notes = ?, clinic_id = ?, password_hash = ?, updated_at = ? WHERE id = ? AND role = "doctor"');
            $stmt->execute([
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['active'] ?? 1,
                $data['notes'] ?? null,
                $clinicId,
                $newPasswordHash,
                $now,
                (int) $data['id']
            ]);
        } else {
            $stmt = db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, active = ?, notes = ?, clinic_id = ?, updated_at = ? WHERE id = ? AND role = "doctor"');
            $stmt->execute([
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['active'] ?? 1,
                $data['notes'] ?? null,
                $clinicId,
                $now,
                (int) $data['id']
            ]);
        }
        return (int) $data['id'];
    } else {
        // Insert new user with role 'doctor'
        $username = $data['email'] ?? $data['phone'] ?? 'doc_' . uniqid();
        $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, active, notes, clinic_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "doctor", ?, ?, ?, ?, ?)');
        $stmt->execute([
            $username,
            $newPasswordHash,
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['active'] ?? 1,
            $data['notes'] ?? null,
            $clinicId,
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

function ensureEntityCommentsTable($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'entity_comments'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS entity_comments (
            id INT NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_entity_comments_entity (entity_type, entity_id),
            KEY idx_entity_comments_user (user_id),
            CONSTRAINT fk_entity_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

function ensureNotificationsTable($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications'");
    $stmt->execute([DB_NAME]);
    $row = $stmt->fetch();
    if (empty($row) || (int)$row['cnt'] === 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            case_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notifications_user (user_id, is_read),
            KEY idx_notifications_case (case_id),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

function notifyCaseCommentParticipants(int $caseId, int $authorUserId, string $commentMessage): void {
    $stmt = db()->prepare('SELECT c.id, c.patient_name, c.doctor_id, c.designer_id, c.lab_id FROM cases c WHERE c.id = ? LIMIT 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case) {
        return;
    }

    $recipientIds = [];
    if (!empty($case['doctor_id'])) {
        $recipientIds[] = (int) $case['doctor_id'];
    }
    if (!empty($case['designer_id'])) {
        $recipientIds[] = (int) $case['designer_id'];
    }
    if (!empty($case['lab_id'])) {
        $recipientIds[] = (int) $case['lab_id'];
    }

    $recipientIds = array_values(array_unique(array_filter($recipientIds)));
    if (empty($recipientIds)) {
        return;
    }

    $authorStmt = db()->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
    $authorStmt->execute([$authorUserId]);
    $authorUser = $authorStmt->fetch();
    $authorName = !empty($authorUser['full_name']) ? trim((string) $authorUser['full_name']) : 'کاربر';

    $preview = trim((string) $commentMessage);
    if (mb_strlen($preview) > 80) {
        $preview = mb_substr($preview, 0, 80) . '...';
    }

    $caseTitle = !empty($case['patient_name']) ? trim((string) $case['patient_name']) : 'کیس #' . $case['id'];

    foreach ($recipientIds as $recipientId) {
        if ((int) $recipientId === (int) $authorUserId) {
            continue;
        }
        createNotification(
            (int) $recipientId,
            'کامنت جدید در ' . $caseTitle,
            $authorName . ' یک پیام جدید در این کیس ثبت کرد: ' . $preview,
            $caseId,
            'comment'
        );
    }
}

function saveEntityComment($entityType, $entityId, $userId, $message) {
    $message = trim((string) $message);
    if ($message === '') {
        return null;
    }
    $stmt = db()->prepare('INSERT INTO entity_comments (entity_type, entity_id, user_id, message, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$entityType, (int) $entityId, (int) $userId, $message]);
    $commentId = (int) db()->lastInsertId();

    if ($entityType === 'case') {
        notifyCaseCommentParticipants((int) $entityId, (int) $userId, $message);
    }

    return $commentId;
}

function getEntityComments($entityType, $entityId) {
    $stmt = db()->prepare('SELECT c.*, u.full_name AS user_name, u.role AS user_role FROM entity_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.entity_type = ? AND c.entity_id = ? ORDER BY c.created_at ASC');
    $stmt->execute([$entityType, (int) $entityId]);
    return $stmt->fetchAll();
}

function deleteEntityComment($commentId, $userId, $isAdmin = false) {
    if ($isAdmin) {
        $stmt = db()->prepare('DELETE FROM entity_comments WHERE id = ?');
        $stmt->execute([(int) $commentId]);
        return true;
    }

    $stmt = db()->prepare('DELETE FROM entity_comments WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $commentId, (int) $userId]);
    return $stmt->rowCount() > 0;
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
    $stmt = db()->prepare('SELECT ii.*, p.title AS price_title, c.received_date AS case_received_date,
                  c.case_type, u.full_name AS case_doctor_name
        FROM invoice_items ii
        LEFT JOIN site_prices p ON ii.price_id = p.id
        LEFT JOIN cases c ON ii.case_id = c.id
        LEFT JOIN users u ON c.doctor_id = u.id
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

function refreshInvoicePaymentStatuses(array $invoiceIds): void {
    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    if (empty($invoiceIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $stmt = db()->prepare("SELECT invoice_id, SUM(amount_applied) AS applied_amount
        FROM doctor_payment_invoices
        WHERE invoice_id IN ($placeholders)
        GROUP BY invoice_id");
    $stmt->execute($invoiceIds);

    $appliedByInvoice = [];
    while ($row = $stmt->fetch()) {
        $appliedByInvoice[(int) $row['invoice_id']] = (float) $row['applied_amount'];
    }

    $invoiceStmt = db()->prepare("SELECT id, total_amount FROM doctor_invoices WHERE id IN ($placeholders)");
    $invoiceStmt->execute($invoiceIds);
    while ($invoice = $invoiceStmt->fetch()) {
        $invoiceId = (int) $invoice['id'];
        $totalAmount = (float) ($invoice['total_amount'] ?? 0);
        $appliedAmount = $appliedByInvoice[$invoiceId] ?? 0.0;
        $paymentStatus = ($appliedAmount >= $totalAmount && $totalAmount > 0) ? 'paid' : 'unpaid';
        $updateStmt = db()->prepare('UPDATE doctor_invoices SET payment_status = ? WHERE id = ?');
        $updateStmt->execute([$paymentStatus, $invoiceId]);
    }
}

function savePayment($data) {
    $now = date('Y-m-d H:i:s');
    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $data['invoice_ids'] ?? []))));

    if (isset($data['id']) && !empty($data['id'])) {
        $existingInvoiceIds = getPaymentInvoiceIds((int) $data['id']);
        $stmt = db()->prepare('UPDATE doctor_payments SET doctor_id = ?, doctor_name = ?, amount = ?, payment_method = ?, payment_date = ?, transaction_number = ?, bank_account_id = ?, notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $data['doctor_id'] ?: null,
            $data['doctor_name'] ?? '',
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
            $data['doctor_name'] ?? '',
            $data['amount'],
            $data['payment_method'],
            $data['payment_date'],
            $data['transaction_number'] ?? null,
            $data['bank_account_id'] ?? null,
            $data['notes'] ?? null,
            $now
        ]);
        $paymentId = db()->lastInsertId();
        $existingInvoiceIds = [];
    }

    // Update payment-invoice links
    $del = db()->prepare('DELETE FROM doctor_payment_invoices WHERE payment_id = ?');
    $del->execute([$paymentId]);
    $ins = db()->prepare('INSERT INTO doctor_payment_invoices (payment_id, invoice_id, amount_applied) VALUES (?, ?, ?)');
    foreach ($invoiceIds as $invoiceId) {
        $ins->execute([$paymentId, (int) $invoiceId, $data['amount_applied'] ?? $data['amount']]);
    }

    $affectedInvoiceIds = array_values(array_unique(array_merge($existingInvoiceIds, $invoiceIds)));
    refreshInvoicePaymentStatuses($affectedInvoiceIds);
    return $paymentId;
}

function deletePayment($id) {
    $invoiceIds = getPaymentInvoiceIds((int) $id);
    $stmt = db()->prepare('DELETE FROM doctor_payments WHERE id = ?');
    $stmt->execute([(int) $id]);
    refreshInvoicePaymentStatuses($invoiceIds);
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
function getParentClinicUserId($doctor_id) {
    $stmt = db()->prepare('SELECT clinic_id FROM users WHERE id = ? AND role IN ("doctor", "clinic") LIMIT 1');
    $stmt->execute([(int) $doctor_id]);
    $row = $stmt->fetch();
    return $row && !empty($row['clinic_id']) ? (int) $row['clinic_id'] : null;
}

function getDoctorPriceOverride($doctor_id, $service_id) {
    $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?');
    $stmt->execute([(int)$doctor_id, 'service', (int)$service_id]);
    $override = $stmt->fetch();
    if ($override) {
        return $override;
    }

    $parentClinicId = getParentClinicUserId((int) $doctor_id);
    if ($parentClinicId) {
        $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?');
        $stmt->execute([$parentClinicId, 'service', (int)$service_id]);
        return $stmt->fetch();
    }

    return null;
}

function getDesignerDesignFeeOverride($designer_id, $service_id = null) {
    $designer_id = (int) $designer_id;
    if ($service_id !== null && (int) $service_id > 0) {
        // 1) designer + specific service (per-type design fee)
        $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id = ?');
        $stmt->execute([$designer_id, 'design_fee', (int) $service_id]);
        $override = $stmt->fetch();
        if ($override) {
            return $override;
        }
    }
    // 2) fallback to general per-designer design fee
    $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND service_id IS NULL');
    $stmt->execute([$designer_id, 'design_fee']);
    return $stmt->fetch();
}

/** Per-unit design fee (تومان) for a designer and optionally a specific service. */
function getApplicableDesignFee($designer_id, $service_id = null) {
    $override = getDesignerDesignFeeOverride((int) $designer_id, $service_id);
    return $override ? (float) $override['custom_price'] : null;
}

// =====================================================
// Designer (freelance) Invoice Helpers
// =====================================================

/**
 * Uninvoiced cases done by a designer within a date range.
 * Each case's per-unit design fee is resolved from the designer's override.
 */
function getUninvoicedCasesForDesigner(int $designerId, string $startDate, string $endDate): array {
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.designer_id = ?
          AND c.designer_invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
        ORDER BY c.received_date ASC, c.id ASC
    ');
    $stmt->execute([$designerId, $startDate, $endDate]);
    $cases = $stmt->fetchAll();
    foreach ($cases as &$c) {
        $c['unit_design_fee'] = getApplicableDesignFee($designerId, (int) ($c['service_id'] ?? 0));
    }
    return $cases;
}

/** Create a designer invoice from a list of cases (design fee = unit fee x quantity). */
function createDesignerInvoice(int $designerId, array $cases, string $invoiceDate, ?string $periodLabel = null): int {
    $now = date('Y-m-d H:i:s');

    // Invoice number: INV-DES-YYYYMM-001
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $stmt = db()->prepare("SELECT MAX(invoice_number) FROM designer_invoices WHERE invoice_number LIKE ?");
    $stmt->execute(['INV-DES-' . $yearMonth . '-%']);
    $max = $stmt->fetchColumn();
    $num = $max ? ((int) explode('-', $max)[3] + 1) : 1;
    $invoiceNumber = 'INV-DES-' . $yearMonth . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);

    $total = 0;
    $rows = [];
    foreach ($cases as $c) {
        $unitFee = getApplicableDesignFee($designerId, (int) ($c['service_id'] ?? 0));
        if ($unitFee === null) {
            $unitFee = 0.0;
        }
        $qty = (int) ($c['quantity'] ?? 1);
        $amount = round($unitFee * $qty);
        $total += $amount;
        $rows[] = [$c, $unitFee, $qty, $amount];
    }

    $notes = 'فاکتور طراحی' . ($periodLabel ? ' — بازه: ' . $periodLabel : '');
    $ins = db()->prepare('INSERT INTO designer_invoices (invoice_number, designer_id, total_amount, period_label, invoice_date, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$invoiceNumber, $designerId, $total, $periodLabel, $invoiceDate, $notes, $now]);
    $invoiceId = (int) db()->lastInsertId();

    $item = db()->prepare('INSERT INTO designer_invoice_items (invoice_id, case_id, doctor_id, doctor_name, service_id, service_title, patient_name, quantity, unit_design_fee, total_amount, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as [$c, $unitFee, $qty, $amount]) {
        $item->execute([
            $invoiceId,
            $c['id'],
            $c['doctor_id'] ?? null,
            $c['doctor_name'] ?? null,
            $c['service_id'] ?? null,
            $c['service_title'] ?? null,
            $c['patient_name'] ?? null,
            $qty,
            $unitFee,
            $amount,
            $c['received_date'] ?? null,
            $now,
        ]);
    }

    // Mark cases as invoiced for the designer (separate from doctor invoice)
    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET designer_invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }

    return $invoiceId;
}

/** Get a designer invoice by id. */
function getDesignerInvoice(int $id): ?array {
    $stmt = db()->prepare('SELECT i.*, u.full_name AS designer_name FROM designer_invoices i LEFT JOIN users u ON i.designer_id = u.id WHERE i.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Get items of a designer invoice. */
function getDesignerInvoiceItems(int $invoiceId): array {
    $stmt = db()->prepare('SELECT * FROM designer_invoice_items WHERE invoice_id = ? ORDER BY id ASC');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

/** All designer invoices (admin listing). */
function getAllDesignerInvoices(): array {
    $stmt = db()->query('SELECT i.*, u.full_name AS designer_name FROM designer_invoices i LEFT JOIN users u ON i.designer_id = u.id ORDER BY i.invoice_date DESC, i.id DESC');
    return $stmt->fetchAll();
}

// =====================================================
// Clinic Invoice Helpers
// =====================================================

/**
 * Uninvoiced doctor-type cases of a clinic's subordinate doctors in a date range.
 * The clinic (not the individual doctors) is the payer.
 */
function getUninvoicedCasesForClinic(int $clinicId, string $startDate, string $endDate): array {
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.case_type = "doctor"
          AND c.invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
          AND c.doctor_id IN (SELECT id FROM users WHERE clinic_id = ?)
        ORDER BY c.doctor_id, c.received_date ASC
    ');
    $stmt->execute([$startDate, $endDate, $clinicId]);
    return $stmt->fetchAll();
}

/** Create a clinic invoice (grouped by doctor) from a list of cases. */
function createClinicInvoice(int $clinicId, array $cases, string $invoiceDate, ?string $periodLabel = null, ?int $bankAccountId = null): int {
    $now = date('Y-m-d H:i:s');

    // Invoice number: INV-CLN-YYYYMM-001
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $stmt = db()->prepare("SELECT MAX(invoice_number) FROM doctor_invoices WHERE invoice_number LIKE ?");
    $stmt->execute(['INV-CLN-' . $yearMonth . '-%']);
    $max = $stmt->fetchColumn();
    $num = $max ? ((int) explode('-', $max)[4] + 1) : 1;
    $invoiceNumber = 'INV-CLN-' . $yearMonth . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);

    $total = 0;
    foreach ($cases as $c) {
        $total += (float) $c['total_price'];
    }

    $clinic = db()->prepare('SELECT * FROM users WHERE id = ?');
    $clinic->execute([$clinicId]);
    $clinicUser = $clinic->fetch();
    $clinicName = $clinicUser['full_name'] ?? 'کلینیک';

    $notes = 'فاکتور کلینیک' . ($periodLabel ? ' — بازه: ' . $periodLabel : '');
    $stmt = db()->prepare('INSERT INTO doctor_invoices (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, notes, bank_account_id, created_at) VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $invoiceNumber,
        $clinicId,
        $clinicName,
        $total,
        'unpaid',
        $invoiceDate,
        $notes,
        $bankAccountId,
        $now,
    ]);
    $invoiceId = (int) db()->lastInsertId();

    $ins = db()->prepare('INSERT INTO invoice_items (invoice_id, price_id, case_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($cases as $c) {
        $desc = trim((string) ($c['doctor_name'] ?? ''));
        $location = formatCaseLocation($c['location_type'] ?? null, $c['teeth'] ?? null);
        if ($location !== '—') $desc = trim($desc . ' - ' . $location);
        $ins->execute([
            $invoiceId,
            $c['service_id'],
            $c['id'],
            $c['service_title'] ?? 'خدمت',
            $desc ?: null,
            $c['patient_name'],
            $c['quantity'] ?? 1,
            $c['unit_price'] ?? 0,
            $c['total_price'] ?? 0,
            $now,
        ]);
    }

    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }

    return $invoiceId;
}

// =====================================================
// Outsourcing (برون‌سپاری) Helpers – per-lab per-service rates + invoices
// =====================================================

/** Get the outsourcing rate for a lab+service (what we pay the lab). */
function getOutsourceRate(int $labId, int $serviceId): ?float {
    $stmt = db()->prepare('SELECT rate FROM outsource_rates WHERE lab_id = ? AND service_id = ?');
    $stmt->execute([$labId, $serviceId]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null) ? (float) $val : null;
}

/** Save (insert or update) an outsourcing rate. */
function saveOutsourceRate(int $labId, int $serviceId, float $rate): void {
    $existing = db()->prepare('SELECT id FROM outsource_rates WHERE lab_id = ? AND service_id = ?');
    $existing->execute([$labId, $serviceId]);
    $id = $existing->fetchColumn();
    if ($id) {
        $stmt = db()->prepare('UPDATE outsource_rates SET rate = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$rate, $id]);
    } else {
        $stmt = db()->prepare('INSERT INTO outsource_rates (lab_id, service_id, rate, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
        $stmt->execute([$labId, $serviceId, $rate]);
    }
}

/** All outsourcing rates (admin listing). */
function getAllOutsourceRates(): array {
    $stmt = db()->query('
        SELECT r.*, u.full_name AS lab_name, p.title AS service_title
        FROM outsource_rates r
        LEFT JOIN users u ON r.lab_id = u.id
        LEFT JOIN site_prices p ON r.service_id = p.id
        ORDER BY u.full_name, p.title
    ');
    return $stmt->fetchAll();
}

function deleteOutsourceRate(int $id): void {
    $stmt = db()->prepare('DELETE FROM outsource_rates WHERE id = ?');
    $stmt->execute([$id]);
}

/** Uninvoiced outsourced (lab_out) cases for a lab within a date range. */
function getUninvoicedOutsourceCases(int $labId, string $startDate, string $endDate): array {
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.case_type = "lab_out"
          AND c.lab_id = ?
          AND c.outsource_invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
        ORDER BY c.received_date ASC, c.id ASC
    ');
    $stmt->execute([$labId, $startDate, $endDate]);
    $cases = $stmt->fetchAll();
    foreach ($cases as &$c) {
        $c['unit_rate'] = getOutsourceRate($labId, (int) ($c['service_id'] ?? 0));
    }
    return $cases;
}

/** Create an outsourcing invoice from a list of outsourced cases. */
function createOutsourceInvoice(int $labId, array $cases, string $invoiceDate, ?string $periodLabel = null): int {
    $now = date('Y-m-d H:i:s');
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $stmt = db()->prepare("SELECT MAX(invoice_number) FROM outsource_invoices WHERE invoice_number LIKE ?");
    $stmt->execute(['OUT-' . $yearMonth . '-%']);
    $max = $stmt->fetchColumn();
    $num = $max ? ((int) explode('-', $max)[2] + 1) : 1;
    $invoiceNumber = 'OUT-' . $yearMonth . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);

    $total = 0;
    $rows = [];
    foreach ($cases as $c) {
        $rate = getOutsourceRate($labId, (int) ($c['service_id'] ?? 0));
        if ($rate === null) $rate = 0.0;
        $qty = (int) ($c['quantity'] ?? 1);
        $amount = round($rate * $qty);
        $total += $amount;
        $rows[] = [$c, $rate, $qty, $amount];
    }

    $notes = 'فاکتور برون‌سپاری' . ($periodLabel ? ' — بازه: ' . $periodLabel : '');
    $ins = db()->prepare('INSERT INTO outsource_invoices (invoice_number, lab_id, total_amount, period_label, invoice_date, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$invoiceNumber, $labId, $total, $periodLabel, $invoiceDate, $notes, $now]);
    $invoiceId = (int) db()->lastInsertId();

    $item = db()->prepare('INSERT INTO outsource_invoice_items (invoice_id, case_id, doctor_id, doctor_name, service_id, service_title, patient_name, quantity, unit_rate, total_amount, received_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as [$c, $rate, $qty, $amount]) {
        $item->execute([
            $invoiceId,
            $c['id'],
            $c['doctor_id'] ?? null,
            $c['doctor_name'] ?? null,
            $c['service_id'] ?? null,
            $c['service_title'] ?? null,
            $c['patient_name'] ?? null,
            $qty,
            $rate,
            $amount,
            $c['received_date'] ?? null,
            $now,
        ]);
    }

    $caseIds = array_column($cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = db()->prepare("UPDATE cases SET outsource_invoice_id = ? WHERE id IN ($placeholders)");
        array_unshift($caseIds, $invoiceId);
        $stmt->execute($caseIds);
    }
    return $invoiceId;
}

function getOutsourceInvoice(int $id): ?array {
    $stmt = db()->prepare('SELECT i.*, u.full_name AS lab_name FROM outsource_invoices i LEFT JOIN users u ON i.lab_id = u.id WHERE i.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getOutsourceInvoiceItems(int $invoiceId): array {
    $stmt = db()->prepare('SELECT * FROM outsource_invoice_items WHERE invoice_id = ? ORDER BY id ASC');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

function getAllOutsourceInvoices(): array {
    $stmt = db()->query('SELECT i.*, u.full_name AS lab_name FROM outsource_invoices i LEFT JOIN users u ON i.lab_id = u.id ORDER BY i.invoice_date DESC, i.id DESC');
    return $stmt->fetchAll();
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
        SELECT o.*, u.full_name AS doctor_name,
               CASE WHEN o.price_type = "design_fee" THEN COALESCE(p.title, "هزینه طراحی") ELSE p.title END AS service_title
        FROM doctor_price_overrides o
        LEFT JOIN users u ON o.doctor_id = u.id
        LEFT JOIN site_prices p ON o.service_id = p.id
        ORDER BY u.full_name, o.price_type, p.title
    ');
    return $stmt->fetchAll();
}

/**
 * Get overrides for a specific doctor.
 */
function getDoctorPriceOverrides($doctor_id) {
    $stmt = db()->prepare('
        SELECT o.*, CASE WHEN o.price_type = "design_fee" THEN COALESCE(p.title, "هزینه طراحی") ELSE p.title END AS service_title
        FROM doctor_price_overrides o
        LEFT JOIN site_prices p ON o.service_id = p.id
        WHERE o.doctor_id = ?
        ORDER BY o.price_type, p.title
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
    $service_id = !empty($data['service_id']) ? (int) $data['service_id'] : null;
    $price_type = isset($data['price_type']) ? strtolower((string) $data['price_type']) : 'service';
    if (!in_array($price_type, ['service', 'design_fee'], true)) {
        $price_type = 'service';
    }
    // design_fee: service_id may be null (general rate) or specific (per-type rate)
    $custom_price = (float) $data['custom_price'];

    $existingStmt = db()->prepare('SELECT id FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = ? AND (? IS NULL AND service_id IS NULL OR service_id = ?)');
    $existingStmt->execute([$doctor_id, $price_type, $service_id, $service_id]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $stmt = db()->prepare('UPDATE doctor_price_overrides SET custom_price = ?, service_id = ?, price_type = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$custom_price, $service_id, $price_type, (int) $existing['id']]);
        return (int) $existing['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO doctor_price_overrides (doctor_id, service_id, price_type, custom_price, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$doctor_id, $service_id, $price_type, $custom_price]);
        return (int) db()->lastInsertId();
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
          AND c.invoice_id IS NULL
          AND c.case_type = "doctor"
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
        $description = '';
        if ($locationStr !== '—') {
            $description .= '' . $locationStr;
        }
        $stmt = db()->prepare('
            INSERT INTO invoice_items 
            (invoice_id, price_id, case_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $invoiceId,
            $case['service_id'],
            $case['id'],           // case_id for linking to case
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

// =====================================================
// Role Management Helpers
// =====================================================

function getAllRoles(): array {
    $stmt = db()->query('SELECT * FROM roles ORDER BY id ASC');
    return $stmt->fetchAll();
}

function getRole(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM roles WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getRoleByName(string $name): ?array {
    $stmt = db()->prepare('SELECT * FROM roles WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getRolePermissions(string $roleName): array {
    $role = getRoleByName($roleName);
    if (!$role || empty($role['permissions'])) return [];
    $perms = json_decode($role['permissions'], true);
    return is_array($perms) ? $perms : [];
}

function saveRole(array $data): int {
    $name = trim($data['name'] ?? '');
    $label = trim($data['label'] ?? '');
    $permissions = $data['permissions'] ?? [];
    $permsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
    
    if (isset($data['id']) && !empty($data['id'])) {
        $stmt = db()->prepare('UPDATE roles SET name = ?, label = ?, permissions = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$name, $label, $permsJson, (int)$data['id']]);
        return (int)$data['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO roles (name, label, permissions, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
        $stmt->execute([$name, $label, $permsJson]);
        return (int) db()->lastInsertId();
    }
}

function deleteRole(int $id): bool {
    $check = db()->prepare('SELECT COUNT(*) FROM users WHERE role = (SELECT name FROM roles WHERE id = ?)');
    $check->execute([$id]);
    if ((int) $check->fetchColumn() > 0) return false;
    
    $stmt = db()->prepare('DELETE FROM roles WHERE id = ?');
    $stmt->execute([$id]);
    return true;
}

function getAllPermissionDefinitions(): array {
    return [
        '*'                    => 'دسترسی کامل (مدیر)',
        'view_all_cases'       => 'مشاهده همه کیس‌ها',
        'view_own_cases'       => 'مشاهده کیس‌های خود',
        'view_assigned_cases'  => 'مشاهده کیس‌های محول شده',
        'view_clinic_cases'    => 'مشاهده کیس‌های کلینیک',
        'create_cases'         => 'ایجاد کیس',
        'edit_cases'           => 'ویرایش کیس',
        'edit_case_status'     => 'ویرایش وضعیت کیس',
        'update_case_status'   => 'بروزرسانی وضعیت',
        'upload_files'         => 'آپلود فایل',
        'upload_design_files'  => 'آپلود فایل طراحی',
        'delete_files'         => 'حذف فایل',
        'view_case_files'      => 'مشاهده فایل‌های کیس',
        'view_invoices'        => 'مشاهده فاکتورها',
        'view_clinic_invoices' => 'مشاهده فاکتورهای کلینیک',
        'view_own_invoices'    => 'مشاهده فاکتورهای خود',
        'view_payments'        => 'مشاهده پرداخت‌ها',
        'view_own_payments'    => 'مشاهده پرداخت‌های خود',
        'view_clinic_payments' => 'مشاهده پرداخت‌های کلینیک',
        'batch_print_labels'   => 'پرینت برچسب گروهی',
        'batch_update_status'  => 'تغییر وضعیت گروهی',
        'export_csv'           => 'خروجی CSV',
    ];
}

// =====================================================
// Notification Helpers
// =====================================================

function createNotification(int $userId, string $title, string $message = null, int $caseId = null, string $type = 'info'): int {
    $stmt = db()->prepare('INSERT INTO notifications (user_id, case_id, title, message, type, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$userId, $caseId, $title, $message, $type]);
    return (int) db()->lastInsertId();
}

function getUnreadNotifications(int $userId, int $limit = 10): array {
    $limit = (int) max(1, $limit);
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ' . $limit);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getUnreadNotificationCount(int $userId): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function markNotificationRead(int $notificationId): void {
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?');
    $stmt->execute([$notificationId]);
}

function markAllNotificationsRead(int $userId): void {
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
}

function getAllNotifications(int $userId, int $limit = 50): array {
    $limit = (int) max(1, $limit);
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . $limit);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// =====================================================
// Lab Billing Helpers
// =====================================================

/** Get uninvoiced cases for a lab within a date range, with billing direction */
function getUninvoicedCasesForLab(int $labId, string $startDate, string $endDate): array {
    $stmt = db()->prepare('
        SELECT c.*, p.title AS service_title, u.full_name AS doctor_name
        FROM cases c
        LEFT JOIN site_prices p ON c.service_id = p.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.lab_id = ?
          AND c.invoice_id IS NULL
          AND c.received_date BETWEEN ? AND ?
          AND c.case_type IN (\'lab_in\', \'lab_out\')
        ORDER BY c.doctor_id, c.received_date ASC
    ');
    $stmt->execute([$labId, $startDate, $endDate]);
    return $stmt->fetchAll();
}

/** Get lab price override for a service, or fallback to default price */
function getLabApplicablePrice(int $labId, int $serviceId): float {
    // 1) Unified override table (lab stored as target with price_type = service)
    $override = db()->prepare('SELECT custom_price FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = "service" AND service_id = ?');
    $override->execute([$labId, $serviceId]);
    $val = $override->fetchColumn();
    if ($val !== false && $val !== null) {
        return (float) $val;
    }

    // 2) Legacy lab-specific override table
    $override = db()->prepare('SELECT custom_price FROM lab_price_overrides WHERE lab_id = ? AND service_id = ?');
    $override->execute([$labId, $serviceId]);
    $val = $override->fetchColumn();
    if ($val !== false && $val !== null) {
        return (float) $val;
    }

    // 3) Default price
    $price = getPrice($serviceId);
    return $price ? (float) $price['price'] : 0.0;
}

/** Get all lab price overrides (admin listing) */
function getAllLabPriceOverrides(): array {
    $stmt = db()->query('
        SELECT o.*, u.full_name AS lab_name, p.title AS service_title
        FROM lab_price_overrides o
        LEFT JOIN users u ON o.lab_id = u.id
        LEFT JOIN site_prices p ON o.service_id = p.id
        ORDER BY u.full_name, p.title
    ');
    return $stmt->fetchAll();
}

/** Save (insert or update) a lab price override */
function saveLabPriceOverride(array $data): int {
    $lab_id = (int) $data['lab_id'];
    $service_id = (int) $data['service_id'];
    $custom_price = (float) $data['custom_price'];

    $existing = db()->prepare('SELECT id FROM lab_price_overrides WHERE lab_id = ? AND service_id = ?');
    $existing->execute([$lab_id, $service_id]);
    $existingId = $existing->fetchColumn();

    if ($existingId) {
        $stmt = db()->prepare('UPDATE lab_price_overrides SET custom_price = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$custom_price, $existingId]);
        return (int) $existingId;
    }
    $stmt = db()->prepare('INSERT INTO lab_price_overrides (lab_id, service_id, custom_price, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
    $stmt->execute([$lab_id, $service_id, $custom_price]);
    return (int) db()->lastInsertId();
}

/** Delete a lab price override by ID */
function deleteLabPriceOverride(int $id): void {
    $stmt = db()->prepare('DELETE FROM lab_price_overrides WHERE id = ?');
    $stmt->execute([$id]);
}

/** Create a monthly/weekly/daily invoice for a lab, grouped by doctor, with +/- amounts */
function createMonthlyLabInvoice(int $labId, array $cases, float $balance, string $invoiceDate, ?int $bankAccountId = null, ?string $periodLabel = null): int {
    $now = date('Y-m-d H:i:s');

    // Invoice number: INV-LAB-YYYYMM-001
    $yearMonth = date('Ym', strtotime($invoiceDate));
    $stmt = db()->prepare("SELECT MAX(invoice_number) FROM doctor_invoices WHERE invoice_number LIKE ?");
    $stmt->execute(['INV-LAB-' . $yearMonth . '-%']);
    $max = $stmt->fetchColumn();
    if ($max) {
        $parts = explode('-', $max);
        $num = (int) $parts[3] + 1;
    } else {
        $num = 1;
    }
    $invoiceNumber = 'INV-LAB-' . $yearMonth . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);

    $total = $balance;
    foreach ($cases as $c) {
        $price = getLabApplicablePrice($labId, (int) $c['service_id']);
        $qty = (int) ($c['quantity'] ?? 1);
        $sign = ($c['case_type'] === 'lab_out') ? -1 : 1;
        $total += $price * $qty * $sign;
    }

    $lab = db()->prepare('SELECT * FROM users WHERE id = ?');
    $lab->execute([$labId]);
    $labUser = $lab->fetch();

    $stmt = db()->prepare('
        INSERT INTO doctor_invoices
        (invoice_number, doctor_id, doctor_name, doctor_phone, doctor_email, total_amount, payment_status, invoice_date, notes, bank_account_id, created_at)
        VALUES (?, NULL, ?, NULL, NULL, ?, ?, ?, ?, ?, ?)
    ');
    $notes = 'فاکتور لابراتوار';
    if ($periodLabel !== null && $periodLabel !== '') {
        $notes .= ' — بازه: ' . $periodLabel;
    }
    $stmt->execute([
        $invoiceNumber,
        $labUser['full_name'] ?? 'لابراتوار',
        $total,
        'unpaid',
        $invoiceDate,
        $notes,
        $bankAccountId,
        $now
    ]);
    $invoiceId = (int) db()->lastInsertId();

    // Insert items – one per case
    $ins = db()->prepare('INSERT INTO invoice_items (invoice_id, price_id, case_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($cases as $c) {
        $price = getLabApplicablePrice($labId, (int) $c['service_id']);
        $qty = (int) ($c['quantity'] ?? 1);
        $sign = ($c['case_type'] === 'lab_out') ? -1 : 1;
        $doctorName = $c['doctor_name'] ?? '';
        $location = formatCaseLocation($c['location_type'] ?? null, $c['teeth'] ?? null);
        // Description carries doctor name + location for grouping/context
        $desc = trim($doctorName);
        if ($location !== '—') $desc = trim($desc . ' - ' . $location);
        $ins->execute([
            $invoiceId,
            $c['service_id'],
            $c['id'],
            $c['service_title'] ?? 'خدمت',
            $desc ?: null,
            $c['patient_name'],
            $qty,
            $price * $sign,
            round($price * $qty * $sign),
            $now
        ]);
    }

    if ($balance != 0) {
        $stmt = db()->prepare('INSERT INTO invoice_items (invoice_id, item_title, item_description, patient_name, quantity, unit_price, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$invoiceId, 'مانده از قبل', 'مانده انتقالی', '', 1, $balance, $balance, $now]);
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