<?php
// panel/save_user.php
// ذخیرهٔ کاربر. مدیر کل: همه | مدیر شعبه: فقط کاربرانِ شعبهٔ خودش (بدون نقش‌های مدیریتی).
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_users()) { http_response_code(403); die('دسترسی غیرمجاز'); }
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

$isRoot     = is_root_admin();
$myBranchId = managed_users_branch_id();

$id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$role = (string) ($_POST['role'] ?? 'staff');
$active = isset($_POST['active']) && $_POST['active'] === '1' ? 1 : 0;
$password = $_POST['password'] ?? '';
$notes = trim($_POST['notes'] ?? '');
$clinic_id = !empty($_POST['clinic_id']) ? (int) $_POST['clinic_id'] : null;
// کلینیک‌های عضویتِ چندگانه (یک پزشک می‌تواند در چند کلینیک کار کند)
$clinicIdsPosted = (isset($_POST['clinic_ids']) && is_array($_POST['clinic_ids'])) ? array_map('intval', $_POST['clinic_ids']) : [];
$clinicIdsPosted = array_values(array_filter(array_unique($clinicIdsPosted), function ($v) { return $v > 0; }));
$is_designer = !empty($_POST['is_designer']) ? 1 : 0;

// ─── محدودهٔ شعبه ───
$existingUser = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $existingUser = $st->fetch() ?: null;
    if (!$existingUser) {
        header('Location: users.php?error=notfound');
        exit;
    }
}
if (!$isRoot) {
    // مدیر شعبه: فقط کاربرانِ شعبهٔ خودش، و فقط با نقش‌های مجاز
    if ($id && !can_manage_target_user($existingUser)) {
        header('Location: users.php?error=not_allowed');
        exit;
    }
    $branch_id = $myBranchId;
} else {
    $branch_id = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null;
}

// نقش باید برای این کاربر قابل انتساب باشد (مدیر شعبه نمی‌تواند admin/branch_admin بسازد).
// استثنا: ویرایشِ کاربری که از قبل همان نقش را دارد (تا مقدارش از دست نرود).
if (!can_assign_user_role($role)) {
    if (!($id && $existingUser && (string) $existingUser['role'] === $role)) {
        header('Location: user_form.php?error=bad_role' . ($id ? '&id=' . $id : ''));
        exit;
    }
}

// Prevent moving the root admin (id 1) into a branch (keeps global access)
if ((int) ($id ?? 0) === 1) {
    $branch_id = null;
}

if (empty($fullName) || empty($username)) {
    header('Location: user_form.php?error=missing' . ($id ? '&id=' . $id : ''));
    exit;
}

// Check username uniqueness
$check = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND (id IS NULL OR id != ?)');
$check->execute([$username, $id ?? 0]);
if ((int) $check->fetchColumn() > 0) {
    header('Location: user_form.php?error=duplicate_username' . ($id ? '&id=' . $id : ''));
    exit;
}

if ($id) {
    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, role = ?, clinic_id = ?, is_designer = ?, active = ?, password_hash = ?, notes = ?, branch_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$fullName, $username, $email ?: null, $phone ?: null, $role, $clinic_id, $is_designer, $active, $hash, $notes ?: null, $branch_id, $id]);
    } else {
        $stmt = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, role = ?, clinic_id = ?, is_designer = ?, active = ?, notes = ?, branch_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$fullName, $username, $email ?: null, $phone ?: null, $role, $clinic_id, $is_designer, $active, $notes ?: null, $branch_id, $id]);
    }
    // Ensure only one default designer
    // ⚠️ $savedId باید قبل از این بلوک مقدار بگیرد؛ قبلاً «متغیر تعریفنشده» بود و
    // UPDATE با id = NULL اجرا میشد → تیکِ «طراح پیشفرض» ذخیره نمیشد.
    $savedId = (int) $id;
    if (!empty($_POST['is_default_designer'])) {
        // فقط کاربرانِ «طراح» می‌توانند طراح پیش‌فرض باشند. قبلاً پرچم روی هر کاربری
        // (مثلاً یک کلینیک) ست می‌شد و getDefaultDesigner() که is_designer=1 می‌خواهد
        // خالی برمی‌گشت → هیچ‌کس طراح پیش‌فرض نبود و فرم کیس «بدون طراح» می‌شد.
        db()->exec('UPDATE users SET is_default_designer = 0');
        $upd = db()->prepare('UPDATE users SET is_default_designer = 1 WHERE id = ? AND is_designer = 1');
        $upd->execute([$savedId]);
    } else {
        $upd = db()->prepare('UPDATE users SET is_default_designer = 0 WHERE id = ?');
        $upd->execute([$savedId]);
    }
} else {
    if (empty($password)) {
        header('Location: user_form.php?error=missing_password');
        exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, clinic_id, is_designer, active, notes, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$username, $hash, $fullName, $email ?: null, $phone ?: null, $role, $clinic_id, $is_designer, $active, $notes ?: null, $branch_id]);
    $savedId = (int) db()->lastInsertId();
    if (!empty($_POST['is_default_designer'])) {
        // مانند ویرایش: پرچم فقط برای کاربرانِ «طراح» مجاز است
        db()->exec('UPDATE users SET is_default_designer = 0');
        $upd = db()->prepare('UPDATE users SET is_default_designer = 1 WHERE id = ? AND is_designer = 1');
        $upd->execute([$savedId]);
    }
}

audit_log_save('user', $savedId, 'کاربر');

// ─── عضویت‌های کلینیکی (چند کلینیک) ───
// فقط برای پزشکان معنی دارد؛ کلینیک اصلی در users.clinic_id و بقیه در user_clinics ذخیره می‌شوند.
if ($role === 'doctor') {
    $memberships = $clinicIdsPosted;
    if ($clinic_id) array_unshift($memberships, (int) $clinic_id);
    // اگر «کلینیک اصلی» انتخاب نشده بود ولی عضویت دارد، اولین عضویت اصلی می‌شود
    $primary = $clinic_id ? (int) $clinic_id : (!empty($memberships) ? (int) $memberships[0] : null);
    setUserClinics((int) $savedId, $memberships, $primary);
}

header('Location: users.php');
exit;
