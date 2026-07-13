<?php

namespace App\Services;

use App\Database\Connection;

/**
 * Service for managing invoices
 */
class InvoiceService
{
    public static function getAll(): array
    {
        $stmt = Connection::getInstance()->query(
            'SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
             FROM doctor_invoices i
             LEFT JOIN users u ON i.doctor_id = u.id
             ORDER BY invoice_date DESC, id DESC'
        );
        return $stmt->fetchAll();
    }

    public static function get(int $id): ?array
    {
        $stmt = Connection::getInstance()->prepare(
            'SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name,
                    COALESCE(u.phone, i.doctor_phone) AS doctor_phone,
                    COALESCE(u.email, i.doctor_email) AS doctor_email,
                    u.id AS doctor_id
             FROM doctor_invoices i
             LEFT JOIN users u ON i.doctor_id = u.id
             WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function getItems(int $invoiceId): array
    {
        $stmt = Connection::getInstance()->prepare(
            'SELECT ii.*, p.title AS price_title
             FROM invoice_items ii
             LEFT JOIN site_prices p ON ii.price_id = p.id
             WHERE ii.invoice_id = ?
             ORDER BY ii.id ASC'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /**
     * Save invoice with items
     */
    public static function save(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $items = [];
        
        foreach ($data['items'] ?? [] as $item) {
            $itemDescription = trim($item['item_description'] ?? '');
            $itemTitle = trim($item['item_title'] ?? '');
            if ($itemTitle === '' && $itemDescription !== '') {
                $itemTitle = $itemDescription;
            }
            if ($itemTitle === '') continue;
            
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $items[] = [
                'price_id' => !empty($item['price_id']) ? (int) $item['price_id'] : null,
                'item_title' => $itemTitle,
                'item_description' => $itemDescription ?: null,
                'patient_name' => trim($item['patient_name'] ?? '') ?: null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $quantity * $unitPrice,
            ];
        }

        $totalAmount = array_sum(array_column($items, 'total_amount'));

        if (!empty($data['id'])) {
            $stmt = Connection::getInstance()->prepare(
                'UPDATE doctor_invoices SET invoice_number = ?, doctor_id = ?, doctor_name = ?, 
                 doctor_phone = ?, doctor_email = ?, total_amount = ?, payment_status = ?, 
                 invoice_date = ?, due_date = ?, notes = ?, updated_at = ? WHERE id = ?'
            );
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
                $now,
                (int) $data['id']
            ]);
            $invoiceId = (int) $data['id'];
        } else {
            $stmt = Connection::getInstance()->prepare(
                'INSERT INTO doctor_invoices (invoice_number, doctor_id, doctor_name, doctor_phone, 
                 doctor_email, total_amount, payment_status, invoice_date, due_date, notes, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
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
                $now
            ]);
            $invoiceId = (int) Connection::getInstance()->lastInsertId();
        }

        // Replace items
        $del = Connection::getInstance()->prepare('DELETE FROM invoice_items WHERE invoice_id = ?');
        $del->execute([$invoiceId]);
        
        $ins = Connection::getInstance()->prepare(
            'INSERT INTO invoice_items (invoice_id, price_id, item_title, item_description, 
             patient_name, quantity, unit_price, total_amount, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $ins->execute([
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

    public static function delete(int $id): void
    {
        // Unlink cases
        $stmt = Connection::getInstance()->prepare('UPDATE cases SET invoice_id = NULL WHERE invoice_id = ?');
        $stmt->execute([$id]);

        $stmt = Connection::getInstance()->prepare('DELETE FROM doctor_invoices WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function calculateDebt(int $doctorId): float
    {
        $stmt = Connection::getInstance()->prepare(
            "SELECT COALESCE(SUM(i.total_amount), 0) - COALESCE(SUM(p.amount), 0) as debt
             FROM doctor_invoices i
             LEFT JOIN doctor_payments p ON p.doctor_id = i.doctor_id
             WHERE i.doctor_id = ?"
        );
        $stmt->execute([$doctorId]);
        return (float) $stmt->fetchColumn();
    }
}
