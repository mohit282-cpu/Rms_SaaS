<?php
// admin/logout.php - Tenant & Support Impersonation Logout Controller
require_once __DIR__ . '/../config.php';

Auth::startSession();

if (isset($_GET['exit_impersonation']) && isset($_SESSION['impersonating_superadmin'])) {
    $saId = $_SESSION['impersonating_superadmin'];
    Auth::logout();
    Auth::startSession();
    $_SESSION['admin_id'] = $saId;
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['is_super_admin'] = true;
    $_SESSION['role'] = 'SUPER_ADMIN';
    $_SESSION['restaurant_id'] = 1;
    header('Location: ../super-admin/restaurants.php');
    exit;
}

Auth::logout();
header('Location: login.php');
exit;
