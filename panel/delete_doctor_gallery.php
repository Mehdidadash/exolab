<?php
// panel/delete_doctor_gallery.php
// Delete a doctor profile photo + caption.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: doctors.php');
    exit;
}

// Internal staff only
if (!has_role('admin') && !has_role('staff') && !has_role('secretary') && !has_permission('edit_cases')) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$galleryId = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
if ($galleryId <= 0) {
    header('Location: doctors.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM doctor_gallery WHERE id = ?');
$stmt->execute([$galleryId]);
$item = $stmt->fetch();
if (!$item) {
    header('Location: doctors.php');
    exit;
}

$uploadDir = __DIR__ . '/../assets/uploads/doctors/';
$file = $uploadDir . basename($item['image_path']);
if (is_file($file)) @unlink($file);

db()->prepare('DELETE FROM doctor_gallery WHERE id = ?')->execute([$galleryId]);
audit_log_delete('doctor_gallery', $galleryId, 'گالری پروفایل پزشک');
header('Location: doctor_view.php?id=' . (int) $item['doctor_id'] . '&ok=1');
exit;
