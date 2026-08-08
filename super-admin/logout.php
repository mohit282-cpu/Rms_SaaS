<?php
// super-admin/logout.php
require_once __DIR__ . '/../config.php';
Auth::logout();
header('Location: login.php');
exit;
