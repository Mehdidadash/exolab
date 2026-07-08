<?php
// config.php

// تنظیمات پایگاه داده و اطلاعات عمومی سایت

define('DB_HOST', 'localhost');
define('DB_NAME', 'exolabir_index');
define('DB_USER', 'exolabir_admin');
define('DB_PASS', 'Mehdi5776783');

define('BASE_URL', '/');
define('SITE_NAME', 'لابراتوار دیجیتال اگزولب');
define('SITE_DESCRIPTION', 'خدمات لابراتوار دندانسازی دیجیتال، نمونه کار و لیست قیمت');

define('USER_SESSION_KEY', 'exolab_user_id');

function base_url($path = '') {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}
