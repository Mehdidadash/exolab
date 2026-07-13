<?php

namespace App\Services;

use App\Database\Connection;

/**
 * Service for managing portfolio works
 */
class WorkService
{
    public static function getActive(): array
    {
        $stmt = Connection::getInstance()->prepare(
            "SELECT * FROM portfolio_works WHERE active = 1 
             ORDER BY (display_order = 0) ASC, 
             CASE WHEN display_order = 0 THEN id ELSE display_order END ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function get(int $id): ?array
    {
        $stmt = Connection::getInstance()->prepare('SELECT * FROM portfolio_works WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function getAll(): array
    {
        $stmt = Connection::getInstance()->query(
            "SELECT * FROM portfolio_works 
             ORDER BY (display_order = 0) ASC, 
             CASE WHEN display_order = 0 THEN id ELSE display_order END ASC"
        );
        return $stmt->fetchAll();
    }

    public static function save(array $data): int
    {
        if (!empty($data['id'])) {
            $stmt = Connection::getInstance()->prepare(
                'UPDATE portfolio_works SET title = ?, description = ?, image_filename = ?, display_order = ?, active = ? WHERE id = ?'
            );
            $stmt->execute([
                $data['title'],
                $data['description'] ?? '',
                $data['image_filename'],
                $data['display_order'] ?? 0,
                $data['active'] ?? 1,
                (int) $data['id']
            ]);
            return (int) $data['id'];
        }

        $stmt = Connection::getInstance()->prepare(
            'INSERT INTO portfolio_works (title, description, image_filename, display_order, active) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['title'],
            $data['description'] ?? '',
            $data['image_filename'],
            $data['display_order'] ?? 0,
            $data['active'] ?? 1
        ]);
        return (int) Connection::getInstance()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        // Delete the image file first
        $work = self::get($id);
        if ($work && !empty($work['image_filename'])) {
            $path = __DIR__ . '/../../assets/uploads/' . $work['image_filename'];
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $stmt = Connection::getInstance()->prepare('DELETE FROM portfolio_works WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function updateOrder(array $ids): void
    {
        foreach ($ids as $index => $id) {
            $stmt = Connection::getInstance()->prepare('UPDATE portfolio_works SET display_order = ? WHERE id = ?');
            $stmt->execute([($index + 1) * 10, (int) $id]);
        }
    }
}
