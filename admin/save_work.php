<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: works.php');
    exit;
}

$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$display_order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
$active = isset($_POST['active']) && $_POST['active'] === '1' ? 1 : 0;

if ($title === '') {
    header('Location: work_form.php?error=title');
    exit;
}

// پوشه آپلود
$upload_dir = __DIR__ . '/../assets/uploads';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$image_filename = null;

if (!empty($_FILES['image']['name'])) {
    $file = $_FILES['image'];
    
    // بررسی فایل
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed) || $file['error'] !== UPLOAD_ERR_OK) {
        header('Location: work_form.php?error=image');
        exit;
    }
    
    // نام فایل یکتا
    $image_filename = uniqid('work_') . '.' . $ext;
    $upload_path = $upload_dir . '/' . $image_filename;
    
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        header('Location: work_form.php?error=upload');
        exit;
    }
}

// ذخیره در دیتابیس
if (!empty($_POST['id'])) {
    // ویرایش
    $work = getPortfolioWork((int) $_POST['id']);
    
    if ($image_filename) {
        // حذف تصویر قدیم
        $old_path = $upload_dir . '/' . $work['image_filename'];
        if (file_exists($old_path)) {
            unlink($old_path);
        }
    } else {
        $image_filename = $work['image_filename'];
    }
    
    $stmt = db()->prepare('UPDATE portfolio_works SET title = ?, description = ?, image_filename = ?, display_order = ?, active = ? WHERE id = ?');
    $stmt->execute([$title, $description, $image_filename, $display_order, $active, (int) $_POST['id']]);
} else {
    // درج جدید
    if (!$image_filename) {
        header('Location: work_form.php?error=noimage');
        exit;
    }
    
    $stmt = db()->prepare('INSERT INTO portfolio_works (title, description, image_filename, display_order, active) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$title, $description, $image_filename, $display_order, $active]);
}

header('Location: works.php');
exit;
