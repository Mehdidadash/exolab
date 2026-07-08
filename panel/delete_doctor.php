<?php
// panel\delete_doctor.php

require_once __DIR__ . '/auth.php';
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: doctors.php');
    exit;
}

$id = (int) $_POST['id'];
deleteDoctor($id);
header('Location: doctors.php');
