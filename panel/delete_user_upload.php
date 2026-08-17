<?php
// panel/delete_user_upload.php
// Delete one of the current user's uploaded files.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: uploads.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$user = current_user();

$stmt = db()->prepare('SELECT * FROM user_uploads WHERE id = ?');
$stmt->execute([$id]);
$up = $stmt->fetch();

if (!$up || (int) $up['user_id'] !== (int) $user['id']) {
    header('Location: uploads.php?error=forbidden');
    exit;
}

$path = __DIR__ . '/../assets/uploads/user/' . $up['filename'];
if (file_exists($path)) {
    @unlink($path);
}
db()->prepare('DELETE FROM user_uploads WHERE id = ?')->execute([$id]);

header('Location: uploads.php');
exit;
