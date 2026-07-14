<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1200)) {
    session_unset(); session_destroy();
    echo "<script>alert('Session Expired! Auto Logout.'); window.location.href='index.php';</script>"; exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo "<script>window.location.href='../index.php';</script>"; exit;
}

include '../db_connect.php';
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'admin';
date_default_timezone_set('Asia/Dhaka');

$loan_staffs = [];
if ($role === 'admin' || $role === 'Admin') {
    try {
        $loanQuery = $conn->query("
            SELECT s.id, s.staff_name, s.profile_pic, s.staff_salary,
                COALESCE(exp_sub.total_exp,0) AS total_expenses,
                COALESCE(att_sub.payable_days,0)*(s.staff_salary/30.0) AS total_earned,
                COALESCE(exp_sub.total_exp,0)-COALESCE(att_sub.payable_days,0)*(s.staff_salary/30.0) AS real_balance
            FROM staff_info s
            LEFT JOIN (SELECT staff_id, SUM(amount) AS total_exp FROM staff_expenses GROUP BY staff_id) exp_sub ON s.id=exp_sub.staff_id
            LEFT JOIN (SELECT staff_id, SUM(CASE WHEN status='Present' THEN 1.0 WHEN status='Half' THEN 0.5 ELSE 0 END) AS payable_days FROM staff_attendance GROUP BY staff_id) att_sub ON s.id=att_sub.staff_id
            WHERE s.staff_status='Active' AND s.email_verified=1
            HAVING real_balance>0 ORDER BY real_balance DESC
        ");
        if ($loanQuery) $loan_staffs = $loanQuery->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="bn" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Staff Dashboard — SADA KALO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="theme.css">
    <style>
        /* Dashboard header */
        .dash-header {
            background: linear-gradient(135deg, #1a0940 0%, #0D0B22 60%, #0A0918 100%);
            padding: 20px 16px 28px;
            position: relative;
            overflow: hidden;
        }
        .dash-header::before {
            content: '';
            position: absolute;
            top: -40px; right: -40px;
            width: 160px; height: 160px;
            background: radial-gradient(circle, rgba(124,92,252,0.3) 0%, transparent 70%);
            border-radius: 50%;
        }
        .dash-header::after {
            content: '';
            position: absolute;
            bottom: -30px; left: -20px;
            width: 120px; height: 120px;
            background: radial-gradient(circle, rgba(0,206,136,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }
        .brand-logo {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #7C5CFC, #A07AF0);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: #fff;
            box-shadow: 0 4px 16px rgba(124,92,252,0.4);
            flex-shrink: 0;
        }
        .brand-name {
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #fff;
            line-height: 1.1;
        }
        .brand-sub {
            font-size: 9px;
            font-weight: 700;
            color: rgba(255,255,255,0.5);
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .date-chip {
            background: rgba(124,92,252,0.15);
            border: 1px solid rgba(124,92,252,0.25);
            border-radius: 20px;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 700;
            color: #A07AF0;
        }

        /* Menu Grid */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin: 20px 0;
        }
        @media (max-width: 380px) { .menu-grid { grid-template-columns: repeat(2,1fr); gap: 10px; } }
        @media (min-width: 500px) { .menu-grid { grid-template-columns: repeat(4,1fr); } }

        .menu-btn {
            background: var(--sk-card);
            border: 1px solid var(--sk-border);
            border-radius: 16px;
            padding: 18px 8px 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            text-decoration: none !important;
            transition: all 0.25s cubic-bezier(0.34,1.56,0.64,1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        .menu-btn::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 0.2s;
            border-radius: 16px;
        }
        .menu-btn:hover { transform: translateY(-4px); box-shadow: 0 8px 32px rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); }
        .menu-btn:hover::before { opacity: 0.05; }
        .menu-btn:active { transform: scale(0.94); }

        .menu-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            color: #fff;
            flex-shrink: 0;
        }
        .menu-label {
            font-size: 11px;
            font-weight: 800;
            text-align: center;
            color: var(--sk-text);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            line-height: 1.2;
        }

        /* Icon gradients */
        .gi-add    { background: linear-gradient(135deg,#00CE88,#009966); box-shadow: 0 4px 14px rgba(0,206,136,0.4); }
        .gi-list   { background: linear-gradient(135deg,#2196F3,#0052D4); box-shadow: 0 4px 14px rgba(33,150,243,0.4); }
        .gi-att    { background: linear-gradient(135deg,#FFB347,#FF7742); box-shadow: 0 4px 14px rgba(255,179,71,0.4); }
        .gi-his    { background: linear-gradient(135deg,#7C5CFC,#A855F7); box-shadow: 0 4px 14px rgba(124,92,252,0.4); }
        .gi-exp    { background: linear-gradient(135deg,#FF4A6A,#CC1133); box-shadow: 0 4px 14px rgba(255,74,106,0.4); }
        .gi-ehis   { background: linear-gradient(135deg,#F953C6,#B91D73); box-shadow: 0 4px 14px rgba(249,83,198,0.4); }
        .gi-prof   { background: linear-gradient(135deg,#00C9FF,#0072FF); box-shadow: 0 4px 14px rgba(0,201,255,0.4); }
        .gi-inact  { background: linear-gradient(135deg,#74748A,#404060); box-shadow: 0 4px 14px rgba(116,116,138,0.4); }
        .gi-pay    { background: linear-gradient(135deg,#11998E,#38EF7D); box-shadow: 0 4px 14px rgba(56,239,125,0.35); }
        .gi-adm    { background: linear-gradient(135deg,#E44D26,#F16529); box-shadow: 0 4px 14px rgba(228,77,38,0.4); }

        /* Loan Alert */
        .loan-section {
            background: rgba(255,74,106,0.06);
            border: 1px solid rgba(255,74,106,0.2);
            border-radius: 16px;
            padding: 16px;
            margin-top: 4px;
        }
        .loan-header {
            display: flex; align-items: center; gap: 8px;
            font-size: 11px; font-weight: 900;
            text-transform: uppercase; letter-spacing: 1px;
            color: #FF4A6A;
            margin-bottom: 12px;
        }
        .loan-item {
            display: flex; align-items: center; justify-content: space-between;
            background: var(--sk-card);
            border: 1px solid rgba(255,74,106,0.15);
            border-radius: 12px;
            padding: 10px 14px;
            margin-bottom: 8px;
        }
        .loan-item:last-child { margin-bottom: 0; }
        .loan-amount {
            font-size: 14px; font-weight: 900;
            color: #FF4A6A;
        }

        /* Light mode adjustments */
        [data-bs-theme="light"] .dash-header {
            background: linear-gradient(135deg, #5b3fcf 0%, #7C5CFC 100%);
        }
        [data-bs-theme="light"] .menu-btn { box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        [data-bs-theme="light"] .loan-section { background: rgba(255,74,106,0.04); }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="sk-navbar">
    <a href="../index.php" class="btn-back"><i class="fa fa-chevron-left"></i> বের হন</a>
    <div class="sk-navbar-title">Staff Dashboard<span class="sk-navbar-subtitle">SADA KALO FASHION</span></div>
    <button class="btn-theme" onclick="toggleTheme()"><i id="themeIcon" class="fas fa-sun"></i></button>
</nav>

<!-- Dashboard Header -->
<div class="dash-header">
    <div style="position:relative;z-index:1;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="brand-logo"><i class="fa fa-store"></i></div>
            <div>
                <div class="brand-name">SADA KALO</div>
                <div class="brand-sub">Fashion Staff System</div>
            </div>
        </div>
        <div class="date-chip"><i class="fa fa-calendar-day me-1"></i><?php echo date('d F, Y — D'); ?></div>
    </div>
</div>

<!-- Page Content -->
<div class="page-wrap mt-3">

    <!-- Banner -->
    <div class="sk-banner animate-in">
        <img src="banner.jpg" alt="Banner" onerror="this.parentElement.style.background='linear-gradient(135deg,#7C5CFC,#A07AF0)'; this.style.display='none'">
    </div>

    <!-- Menu Grid -->
    <div class="menu-grid animate-in delay-1">

        <a href="Sadakalo_staff.php" class="menu-btn">
            <div class="menu-icon gi-add"><i class="fa fa-user-plus"></i></div>
            <span class="menu-label">Add Staff</span>
        </a>

        <a href="Sadakalo_staff_list.php" class="menu-btn">
            <div class="menu-icon gi-list"><i class="fa fa-users"></i></div>
            <span class="menu-label">Staff List</span>
        </a>

        <a href="Sadakalo_staff_atteend.php" class="menu-btn">
            <div class="menu-icon gi-att"><i class="fa fa-fingerprint"></i></div>
            <span class="menu-label">Attendance</span>
        </a>

        <a href="sadakalo_staff_attend_history.php" class="menu-btn">
            <div class="menu-icon gi-his"><i class="fa fa-calendar-alt"></i></div>
            <span class="menu-label">Att. History</span>
        </a>

        <a href="Sadakalo_staff_expense.php" class="menu-btn">
            <div class="menu-icon gi-exp"><i class="fa fa-hand-holding-dollar"></i></div>
            <span class="menu-label">Expense</span>
        </a>

        <a href="Sadakalo_staff_expense_history.php" class="menu-btn">
            <div class="menu-icon gi-ehis"><i class="fa fa-file-invoice-dollar"></i></div>
            <span class="menu-label">Exp. History</span>
        </a>

        <a href="staff_profile_list.php" class="menu-btn">
            <div class="menu-icon gi-prof"><i class="fa fa-id-card"></i></div>
            <span class="menu-label">Staff Profile</span>
        </a>

        <a href="inactive_profile_list.php" class="menu-btn">
            <div class="menu-icon gi-inact"><i class="fa fa-archive"></i></div>
            <span class="menu-label">Inactive</span>
        </a>

        <a href="Sadakalo_staff_payslip.php" class="menu-btn">
            <div class="menu-icon gi-pay"><i class="fa fa-money-check-dollar"></i></div>
            <span class="menu-label">Payslip</span>
        </a>

        <?php if ($role === 'admin' || $role === 'Admin'): ?>
        <a href="Sadakalo_Staff_admin.php" class="menu-btn">
            <div class="menu-icon gi-adm"><i class="fa fa-user-shield"></i></div>
            <span class="menu-label">Admin Control</span>
        </a>
        <?php endif; ?>

    </div>

    <!-- Loan Alerts -->
    <?php if (!empty($loan_staffs)): ?>
    <div class="loan-section animate-in delay-3">
        <div class="loan-header">
            <i class="fa fa-triangle-exclamation"></i>
            <span>Loan / Advance Alerts — <?php echo count($loan_staffs); ?> জন</span>
        </div>
        <?php foreach($loan_staffs as $ls): ?>
        <div class="loan-item">
            <div class="d-flex align-items-center gap-3">
                <img src="uploads/<?php echo htmlspecialchars($ls['profile_pic'] ?: 'default.png'); ?>"
                     class="sk-avatar" style="border-color:rgba(255,74,106,0.4);"
                     onerror="this.src='uploads/default.png'">
                <div>
                    <div style="font-size:13px;font-weight:800;"><?php echo htmlspecialchars($ls['staff_name']); ?></div>
                    <div style="font-size:10px;color:var(--sk-text-muted);">অ্যাডভান্স / ঋণ বাকি</div>
                </div>
            </div>
            <div class="loan-amount">Tk. <?php echo number_format($ls['real_balance'],2); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="height:20px;"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    const t = localStorage.getItem('sk_theme') || 'dark';
    document.documentElement.setAttribute('data-bs-theme', t);
    document.addEventListener('DOMContentLoaded', function(){
        const icon = document.getElementById('themeIcon');
        if(icon) icon.className = t==='dark' ? 'fas fa-sun' : 'fas fa-moon';
    });
})();
function toggleTheme(){
    const cur = document.documentElement.getAttribute('data-bs-theme');
    const next = cur==='dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    localStorage.setItem('sk_theme', next);
    document.getElementById('themeIcon').className = next==='dark' ? 'fas fa-sun' : 'fas fa-moon';
}
</script>
</body>
</html>
