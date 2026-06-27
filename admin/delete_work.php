<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    $work = getPortfolioWork((int) $_POST['id']);
    
    if ($work) {
        // حذف فایل تصویر
        $image_path = __DIR__ . '/../assets/uploads/' . $work['image_filename'];
        if (file_exists($image_path)) {
            unlink($image_path);
        }
        
        // حذف از دیتابیس
        $stmt = db()->prepare('DELETE FROM portfolio_works WHERE id = ?');
        $stmt->execute([(int) $_POST['id']]);
    }
}

header('Location: works.php');
exit;
