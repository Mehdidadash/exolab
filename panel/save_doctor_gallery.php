<?php
// panel/save_doctor_gallery.php
// Add/edit a doctor profile photo with a caption.
// Only admins / staff / secretary (internal staff) may manage these.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: doctors.php');
    exit;
}

// Internal staff only
if (!has_role('admin') && !has_permission('edit_cases') && !has_role('staff') && !has_role('secretary')) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$doctorId = !empty($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : 0;
$galleryId = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
$caption = trim((string) ($_POST['caption'] ?? ''));

$doctor = getDoctor($doctorId);
if (!$doctor) {
    header('Location: doctors.php?error=notfound');
    exit;
}

$uploadDir = ensure_uploads_dir('doctors') . '/';

// Editing: load existing row (must belong to this doctor)
$existing = null;
if ($galleryId) {
    $stmt = db()->prepare('SELECT * FROM doctor_gallery WHERE id = ? AND doctor_id = ?');
    $stmt->execute([$galleryId, $doctorId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        header('Location: doctor_view.php?id=' . $doctorId . '&error=notfound');
        exit;
    }
}

// Handle optional new image
$imagePath = $existing['image_path'] ?? null;
if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        header('Location: doctor_view.php?id=' . $doctorId . '&error=ext');
        exit;
    }
    $safe = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $uploadDir . $safe;
    if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        @chmod($dest, 0644);
        // Remove the old image file if replacing
        if ($imagePath) {
            $oldFile = $uploadDir . basename($imagePath);
            if (is_file($oldFile)) @unlink($oldFile);
        }
        $imagePath = $safe;
    } else {
        header('Location: doctor_view.php?id=' . $doctorId . '&error=upload');
        exit;
    }
}

if ($galleryId) {
    // Edit caption and/or image
    $stmt = db()->prepare('UPDATE doctor_gallery SET image_path = ?, caption = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$imagePath, $caption !== '' ? $caption : null, $galleryId]);
} else {
    // New item – an image is required
    if (!$imagePath) {
        header('Location: doctor_view.php?id=' . $doctorId . '&error=image_required');
        exit;
    }
    $stmt = db()->prepare('INSERT INTO doctor_gallery (doctor_id, image_path, caption, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
    $stmt->execute([$doctorId, $imagePath, $caption !== '' ? $caption : null]);
}

audit_log_save('doctor_gallery', $doctorId, 'گالری پروفایل پزشک');
header('Location: doctor_view.php?id=' . $doctorId . '&ok=1');
exit;
