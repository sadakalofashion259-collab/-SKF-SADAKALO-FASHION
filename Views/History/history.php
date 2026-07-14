<?php
session_start();
date_default_timezone_set('Asia/Dhaka');

include '../../db_connect.php';
require_once '../../Controllers/History/HistoryController.php';

if (!isset($_SESSION['loggedin'])) {
    header("Location: ../../index.php");
    exit;
}

$role = $_SESSION['role'];

$from = $_GET['from_date'] ?? date('Y-m-01');
$to = $_GET['to_date'] ?? date('Y-m-d');

$controller = new HistoryController($conn);
$data = $controller->index($from, $to);
?>

<link rel="stylesheet" href="../../assets/style_css/history.css">

<div class="summary-box">
    <h3>History Dashboard</h3>
</div>

<?php foreach($data as $date => $info): ?>
<div class="date-card">
    <div class="sec-title"><?= htmlspecialchars($date) ?></div>

    <div class="summary-box">
        Sales: <?= count($info['sales']) ?> |
        Customers: <?= count($info['customers']) ?> |
        Suppliers: <?= count($info['suppliers']) ?>
    </div>
</div>
<?php endforeach; ?>