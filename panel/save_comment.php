<?php
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$entityType = trim($_POST['entity_type'] ?? '');
$entityId = !empty($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
$commentId = !empty($_POST['comment_id']) ? (int) $_POST['comment_id'] : 0;
$action = $_POST['action'] ?? 'save';
$message = trim($_POST['message'] ?? '');
$user = current_user();

if ($action === 'delete') {
    deleteEntityComment($commentId, $user['id'], has_role('admin'));
} elseif ($entityType !== '' && $entityId > 0 && $message !== '') {
    saveEntityComment($entityType, $entityId, $user['id'], $message);
    if ($entityType === 'case') {
        log_case_activity($entityId, 'comment', $commentId ? 'ویرایش کامنت' : 'ثبت کامنت');
    }
}

$redirect = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header('Location: ' . $redirect);
exit;
