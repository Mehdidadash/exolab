<?php
// panel/logout.php
require_once __DIR__ . '/config.php'; // <-- added this line
session_start();
unset($_SESSION[USER_SESSION_KEY]);
session_destroy();
header('Location: login.php');
exit;