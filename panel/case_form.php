<?php
/**
 * panel/case_form.php
 * ─────────────────────────────────────────────────────────────────────────────
 * اندپوینتِ قطعهٔ HTML فرمِ کیس (منبعِ واحد: includes/case_form.php).
 *
 * ورودی:
 *   ?id=123        → فرمِ ویرایشِ همان کیس (با مقادیرِ ذخیره‌شده)
 *   ?new=1 | خالی  → فرمِ ایجاد (خالی، با پیش‌فرض‌ها)
 *   ?files=0       → بخشِ «فایل‌های کیس» رندر نشود (پیش‌فرض: در ویرایش هست)
 *   ?parent=123    → فقط برای «کیس زیرمجموعه» (در پاسخ meta.parent_id برمی‌گردد)
 *
 * خروجی JSON: { ok, mode, id, parent_id, html, csrf_token }
 * مصرف‌کننده‌ها: مودالِ صفحهٔ کیس‌ها، مودالِ صفحهٔ مشاهدهٔ کیس، دکمهٔ سراسری «افزودن کیس».
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/../includes/case_form.php';

header('Content-Type: application/json; charset=utf-8');

$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string) $_SESSION['_csrf_token'];
$user = current_user();

$id       = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
$parentId = !empty($_GET['parent']) ? (int) $_GET['parent'] : 0;
$isEdit   = ($id > 0);
$showFiles = (string) ($_GET['files'] ?? '1') !== '0';

if ($isEdit) {
    // ویرایش: همان شرطِ صفحهٔ مشاهده/کیس‌ها
    if (!(has_role('admin') || has_role('branch_admin') || has_permission('edit_cases'))) {
        $json(['ok' => false, 'message' => 'دسترسی غیرمجاز — شما مجوز ویرایش کیس را ندارید.'], 403);
    }
    $st = db()->prepare('SELECT * FROM cases WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $case = $st->fetch() ?: null;
    if (!$case) {
        $json(['ok' => false, 'message' => 'کیس یافت نشد.'], 404);
    }
    if (!userCanViewCase((int) $id, $user, $case)) {
        $json(['ok' => false, 'message' => 'دسترسی غیرمجاز — این کیس به حساب شما مرتبط نیست.'], 403);
    }
    $values = caseFormValuesFromCase($case);
} else {
    if (!has_permission('create_cases')) {
        $json(['ok' => false, 'message' => 'دسترسی غیرمجاز — شما مجوز ساخت کیس را ندارید.'], 403);
    }
    $values = [];
}

$ctx = caseFormContextForCurrentUser([
    'mode'              => $isEdit ? 'edit' : 'create',
    'csrf_token'        => $csrf_token,
    'values'            => $values,
    // طبق تصمیم: در فرم ویرایشِ صفحهٔ مشاهده فایل نمایش داده نمی‌شود؛ در صفحهٔ کیس‌ها بله.
    'showFiles'         => $isEdit ? $showFiles : true,
    'showSubcaseButton' => !$isEdit,
]);

$json([
    'ok'         => true,
    'mode'       => $ctx['mode'],
    'id'         => $id,
    'parent_id'  => $parentId,
    'csrf_token' => $csrf_token,
    'html'       => renderCaseForm($ctx),
]);
