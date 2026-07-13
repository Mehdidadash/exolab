<?php
// config.php

// Load environment variables from .env file
require_once __DIR__ . '/../includes/EnvLoader.php';
EnvLoader::load();

// تنظیمات پایگاه داده و اطلاعات عمومی سایت
define('DB_HOST', EnvLoader::get('DB_HOST', 'localhost'));
define('DB_NAME', EnvLoader::get('DB_NAME', 'exolabir_index'));
define('DB_USER', EnvLoader::get('DB_USER', 'root'));
define('DB_PASS', EnvLoader::get('DB_PASS', ''));

define('BASE_URL', EnvLoader::get('BASE_URL', '/'));
define('SITE_NAME', EnvLoader::get('SITE_NAME', 'لابراتوار دیجیتال اگزولب'));
define('SITE_DESCRIPTION', EnvLoader::get('SITE_DESCRIPTION', 'خدمات لابراتوار دندانسازی دیجیتال، نمونه کار و لیست قیمت'));

define('USER_SESSION_KEY', EnvLoader::get('USER_SESSION_KEY', 'exolab_user_id'));

function base_url($path = '') {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}
