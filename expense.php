<?php
declare(strict_types=1);

session_start();
@ini_set('memory_limit', '256M');

// Error logging
ini_set('log_errors', '1');
ini_set('display_errors', '0');
if (!is_dir(__DIR__ . '/logs')) { mkdir(__DIR__ . '/logs', 0755, true); }
ini_set('error_log', __DIR__ . '/Logs/expense_error_log.txt');

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database
require_once __DIR__ . '/db_connect.php';

// MVC Files — EmailService বাদ
require_once __DIR__ . '/Models/Interfaces/ExpenseRepoInterface.php';
require_once __DIR__ . '/Models/Repositories/ExpenseRepository.php';
require_once __DIR__ . '/Services/ImageService.php';
require_once __DIR__ . '/Controllers/ExpenseController.php';

// Dependency Injection — EmailService বাদ
$repository   = new ExpenseRepository($conn);
$imageService = new ImageService();

$controller = new ExpenseController($repository, $imageService);
$controller->handleRequest();
