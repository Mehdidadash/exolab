<?php
// panel\delete_work.php

require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    $delId = (int) $_POST['id'];
    audit_log_delete('work', $delId, 'نمونه کار');
    $work = getPortfolioWork($delId);
    
    if ($work) {
        // حذف فایل تصویر
        $image_path = resolve_upload_path($work['image_filename']);
        if (file_exists($image_path)) {
            unlink($image_path);
        }
        $legacy_path = legacy_uploads_path($work['image_filename']);
        if ($legacy_path !== $image_path && file_exists($legacy_path)) {
            unlink($legacy_path);
        }
        
        // حذف از دیتابیس
        $stmt = db()->prepare('DELETE FROM portfolio_works WHERE id = ?');
        $stmt->execute([(int) $_POST['id']]);
    }
}

header('Location: works.php');
exit;
