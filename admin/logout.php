<?php
session_start();
require_once __DIR__ . '/../config.php';

unset($_SESSION[ADMIN_SESSION_KEY]);
session_destroy();
header('Location: login.php');
exit;
