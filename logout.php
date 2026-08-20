<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
logoutUser();
header('Location: ' . APP_URL . '/login.php');
exit;
