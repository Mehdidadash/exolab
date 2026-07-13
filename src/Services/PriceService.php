<?php

namespace App\Services;

use App\Database\Connection;

/**
 * Service for managing site prices
 */
class PriceService
{
    /**
     * Get all active prices ordered by display_order
     */
    public static function getActive(): array
    {
        $stmt = Connection::getInstance()->prepare(
            "SELECT * FROM site_prices WHERE active = 1 
             ORDER BY (display_order = 0) ASC, 
             CASE WHEN display_order = 0 THEN id ELSE display_order END ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get a single price by ID
     */
    public static function get(int $id): ?array
    {
        $stmt = Connection::getInstance()->prepare('SELECT * FROM site_prices WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all prices (including inactive)
     */
    public static function getAll(): array
    {
        $stmt = Connection::getInstance()->query(
            "SELECT * FROM site_prices 
             ORDER BY (display_order = 0) ASC, 
             CASE WHEN display_order = 0 THEN id ELSE display_order END ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Save a price (create or update)
     */
    public static function save(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        if (!empty($data['id'])) {
            $stmt = Connection::getInstance()->prepare(
                'UPDATE site_prices SET title = ?, description = ?, price = ?, category = ?, active = ?, display_order = ? WHERE id = ?'
            );
            $stmt->execute([
                $data['title'],
                $data['description'],
                $data['price'],
                $data['category'] ?? '',
                $data['active'] ?? 1,
                $data['display_order'] ?? 0,
                (int) $data['id']
            ]);
            return (int) $data['id'];
        }

        $stmt = Connection::getInstance()->prepare(
            'INSERT INTO site_prices (title, description, price, category, active, display_order) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['title'],
            $data['description'],
            $data['price'],
            $data['category'] ?? '',
            $data['active'] ?? 1,
            $data['display_order'] ?? 0
        ]);
        return (int) Connection::getInstance()->lastInsertId();
    }

    /**
     * Delete a price by ID
     */
    public static function delete(int $id): void
    {
        $stmt = Connection::getInstance()->prepare('DELETE FROM site_prices WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Update display order for multiple items
     */
    public static function updateOrder(array $ids): void
    {
        foreach ($ids as $index => $id) {
            $stmt = Connection::getInstance()->prepare('UPDATE site_prices SET display_order = ? WHERE id = ?');
            $stmt->execute([($index + 1) * 10, (int) $id]);
        }
    }
}
