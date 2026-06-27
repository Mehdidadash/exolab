<?php
// تنظیمات پایگاه داده و اطلاعات عمومی سایت

define('DB_HOST', 'localhost');
define('DB_NAME', 'exolabir_index');
define('DB_USER', 'exolabir_admin');
define('DB_PASS', 'Mehdi5776783');

define('BASE_URL', '/');
define('SITE_NAME', 'لابراتوار دیجیتال اگزولب');
define('SITE_DESCRIPTION', 'خدمات لابراتوار دندانسازی دیجیتال، نمونه کار و لیست قیمت');
define('ADMIN_SESSION_KEY', 'exolab_admin_logged_in');

function base_url($path = '') {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}
