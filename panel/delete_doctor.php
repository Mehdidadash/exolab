<?php
// panel\delete_doctor.php

require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: doctors.php');
    exit;
}

$id = (int) $_POST['id'];
audit_log_delete('doctor', $id, 'پزشک');
deleteDoctor($id);
header('Location: doctors.php');
