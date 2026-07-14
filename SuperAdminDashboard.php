<?php
declare(strict_types=1);

// সেশন এবং ক্যাশ কন্ট্রোল
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
date_default_timezone_set('Asia/Dhaka');

// সিকিউরিটি চেক
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1200)) {
    session_unset(); 
    session_destroy();
    header("Location: index.php"); 
    exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: index.php"); 
    exit;
}

// ডাটাবেস সংযোগ (মূল পাবলিক ডিরেক্টরিতেই আছে বলে ধরে নেওয়া হচ্ছে)
require_once 'db_connect.php'; 

// নতুন ফোল্ডার স্ট্রাকচার অনুযায়ী MVC ফাইলগুলো লোড করা
require_once 'Models/Super_AdminDashboard/SuperAdminDashboardModel.php';
require_once 'Controllers/Super_AdminDashboard/SuperAdminDashboardController.php';

// MVC প্রসেস
$model = new SuperAdminDashboardModel($conn);
$controller = new SuperAdminDashboardController($model);
$data = $controller->getDashboardData();

// ডাটা View ফাইলে পাঠানো হচ্ছে
extract($data);
require_once 'Views/Super_AdminDashboard/SuperAdminDashboard_view.php';