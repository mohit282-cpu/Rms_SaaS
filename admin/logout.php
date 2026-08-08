<?php
// admin/logout.php - Tenant & Support Impersonation Logout Controller
require_once __DIR__ . '/../config.php';

Auth::startSession();

if (isset($_GET['exit_impersonation']) && isset($_SESSION['impersonating_superadmin'])) {
    $saId = $_SESSION['impersonating_superadmin'];
    $saUsername = $_SESSION['username'] ?? 'superadmin';
    $saFullName = $_SESSION['full_name'] ?? 'Super Admin';
    $saRestaurantId = isset($_SESSION['sa_restaurant_id']) ? (int)$_SESSION['sa_restaurant_id'] : 1;

    // Rotate the session ID in place so a fresh session cookie is issued.
    // (Auth::logout() + startSession() reuses the old session ID, so the
    // browser keeps the deleted cookie and the next request loses the session.)
    Auth::regenerateSession();

    $_SESSION = [];
    $_SESSION['admin_id'] = $saId;
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['is_super_admin'] = true;
    $_SESSION['role'] = 'SUPER_ADMIN';
    $_SESSION['username'] = $saUsername;
    $_SESSION['full_name'] = $saFullName;
    $_SESSION['restaurant_id'] = $saRestaurantId;

    header('Location: ../super-admin/restaurants.php');
    exit;
}

Auth::logout();
header('Location: login.php');
exit;
