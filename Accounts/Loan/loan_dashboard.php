<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Dhaka'); 

include '../../db_connect.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../index.php"); 
    exit;
}

try {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
} catch (Exception $e) {
    $_SESSION['csrf_token'] = md5(uniqid((string)mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// 🖼️ ব্যানার ও লোগো সেটিং  (শুধু এই অংশটুকু যোগ করা হয়েছে)
// ছবি দুটো DPS ফোল্ডারে আছে — absolute path দিয়ে সেগুলোই ব্যবহার করছি।
// অন্য ছবি/নাম চাইলে শুধু নিচের ৪টা লাইন বদলান।
// ============================================================
$bannerImage = '/Accounts/Dps/banner.jpg';   // ব্যানারের ছবি
$bannerLogo  = '/Accounts/Dps/logo.png';     // লোগো
$bannerTitle = 'লোন ম্যানেজমেন্ট';
$bannerSub   = 'SADA KALO LOAN MANAGE';
$role = $_SESSION['role'] ?? 'user'; 

if(isset($conn)) { $pdo = $conn; }

// ==============================================================
// 🏗️ SOLID Interfaces & Dependency Injection Setup
// ==============================================================

interface LoggerInterface {
    public function logError(string $message): void;
}

class FileLogger implements LoggerInterface {
    private string $logDir;

    public function __construct() {
        $this->logDir = __DIR__ . '/../Logs';
    }

    public function logError(string $message): void {
        if (!is_dir($this->logDir)) { @mkdir($this->logDir, 0775, true); }
        $date = date('Y-m-d H:i:s');
        @error_log("[$date] Error: $message\n", 3, $this->logDir . '/error_log.txt');
    }
}

class SecurityHelper {
    public static function safeOutput(?string $string): string {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function verifyCsrf(string $token, string $sessionToken): bool {
        return hash_equals($sessionToken, $token);
    }
}

// ==============================================================
// 🗄️ Models (MVC - Model Layer)
// ==============================================================

class LoanModel {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function recalculateLoan(int $loanId): void {
        $this->db->prepare("SELECT id FROM sys_loans WHERE id = ? FOR UPDATE")->execute([$loanId]);

        $stmt = $this->db->prepare("SELECT COALESCE(SUM(debit_amount), 0) - COALESCE(SUM(credit_amount), 0) FROM sys_loan_ledger WHERE loan_id = ?");
        $stmt->execute([$loanId]);
        $balance = round((float)$stmt->fetchColumn(), 2);

        $stmtStatus = $this->db->prepare("SELECT status FROM sys_loans WHERE id = ?");
        $stmtStatus->execute([$loanId]);
        $currentStatus = $stmtStatus->fetchColumn();
        
        $newStatus = $currentStatus;
        if ($balance <= 0.01) { $newStatus = 'inactive'; } 
        else if ($balance > 0.01 && $currentStatus === 'inactive') { $newStatus = 'active'; }

        $stmtUpdateLoan = $this->db->prepare("UPDATE sys_loans SET current_balance = ?, status = ? WHERE id = ?");
        $stmtUpdateLoan->execute([$balance, $newStatus, $loanId]);

        $ledgers = $this->db->prepare("SELECT id, debit_amount, credit_amount FROM sys_loan_ledger WHERE loan_id = ? ORDER BY txn_date ASC, id ASC FOR UPDATE");
        $ledgers->execute([$loanId]);
        
        $runBal = 0.0;
        $stmtUpdateLedger = $this->db->prepare("UPDATE sys_loan_ledger SET balance = ? WHERE id = ?");
        
        foreach ($ledgers->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $runBal += (float)$l['debit_amount'] - (float)$l['credit_amount'];
            $stmtUpdateLedger->execute([round($runBal, 2), $l['id']]);
        }
    }

    public function getActiveLoans(): array {
        $stmt = $this->db->prepare("SELECT id, borrower_name, account_number, current_balance FROM sys_loans WHERE status = 'active' ORDER BY borrower_name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ==============================================================
// 🎮 Controller (MVC - Controller Layer)
// ==============================================================

class DashboardController {
    private PDO $db;
    private LoggerInterface $logger;
    private LoanModel $loanModel;

    public function __construct(PDO $db, LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
        $this->loanModel = new LoanModel($db);
    }

    public function handleRequest(array $postData, string $sessionCsrf): void {
        ob_clean();
        header('Content-Type: application/json');

        if (!isset($postData['csrf_token']) || !SecurityHelper::verifyCsrf($postData['csrf_token'], $sessionCsrf)) {
            echo json_encode(['status' => 'error', 'message' => 'Security Error. Please refresh the page and try again.']);
            exit;
        }

        try {
            $action = $postData['action'];

            if ($action === 'fetch_summary') {
                $today = date('Y-m-d');
                
                $stmtTodayProfit = $this->db->prepare("SELECT COALESCE(SUM(debit_amount), 0) FROM sys_loan_ledger WHERE txn_date = ? AND (description LIKE '%মুনাফা%' OR description LIKE '%Profit%')");
                $stmtTodayProfit->execute([$today]);
                $todayProfit = $stmtTodayProfit->fetchColumn();

                $stmtTotalProfit = $this->db->prepare("SELECT COALESCE(SUM(debit_amount), 0) FROM sys_loan_ledger WHERE (description LIKE '%মুনাফা%' OR description LIKE '%Profit%')");
                $stmtTotalProfit->execute();
                $totalProfit = $stmtTotalProfit->fetchColumn();

                $stmtTotalOutstanding = $this->db->prepare("SELECT COALESCE(SUM(current_balance), 0) FROM sys_loans WHERE status = 'active'");
                $stmtTotalOutstanding->execute();
                $totalOutstanding = $stmtTotalOutstanding->fetchColumn();

                echo json_encode(['status' => 'success', 'todayProfit' => (float)$todayProfit, 'totalProfit' => (float)$totalProfit, 'totalOutstanding' => (float)$totalOutstanding]); exit;
            }

            if ($action === 'fetch_active_loans') {
                $loans = $this->loanModel->getActiveLoans();
                foreach ($loans as &$loan) { 
                    $loan['borrower_name'] = SecurityHelper::safeOutput($loan['borrower_name']); 
                }
                echo json_encode(['status' => 'success', 'loans' => $loans]); exit;
            }

            if ($action === 'fetch_profiles') {
                $statusFilter = isset($postData['status']) && $postData['status'] === 'inactive' ? 'inactive' : 'active';
                $stmtProfiles = $this->db->prepare("SELECT id, borrower_name, account_number, loan_category, current_balance, status FROM sys_loans WHERE status = ? ORDER BY id DESC");
                $stmtProfiles->execute([$statusFilter]);
                $profiles = $stmtProfiles->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($profiles as &$p) {
                    $p['borrower_name'] = SecurityHelper::safeOutput($p['borrower_name']);
                    $p['account_number'] = SecurityHelper::safeOutput($p['account_number']);
                }
                echo json_encode(['status' => 'success', 'profiles' => $profiles]); exit;
            }

            if ($action === 'fetch_single_profile') {
                $id = (int)$postData['id'];
                
                $stmtInfo = $this->db->prepare("SELECT * FROM sys_loans WHERE id = ?");
                $stmtInfo->execute([$id]);
                $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
                
                if(!$info) { echo json_encode(['status' => 'error', 'message' => 'Account not found.']); exit; }

                $stmtPaid = $this->db->prepare("SELECT COALESCE(SUM(credit_amount), 0) FROM sys_loan_ledger WHERE loan_id = ?");
                $stmtPaid->execute([$id]);
                $totalPaid = $stmtPaid->fetchColumn();

                $stmtProfit = $this->db->prepare("SELECT COALESCE(SUM(debit_amount), 0) FROM sys_loan_ledger WHERE loan_id = ? AND (description LIKE '%মুনাফা%' OR description LIKE '%Profit%')");
                $stmtProfit->execute([$id]);
                $totalProfit = $stmtProfit->fetchColumn();

                $page    = max(1, (int)($postData['page'] ?? 1));
                $perPage = 20;
                $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM sys_loan_ledger WHERE loan_id = ?");
                $cntStmt->execute([$id]);
                $totalRows = (int)$cntStmt->fetchColumn();
                $pages   = max(1, (int)ceil($totalRows / $perPage));
                $page    = min($page, $pages);
                $offset  = ($page - 1) * $perPage;   // int — তাই নিরাপদ

                $stmtLedger = $this->db->prepare("SELECT * FROM sys_loan_ledger WHERE loan_id = ? ORDER BY id DESC LIMIT $perPage OFFSET $offset");
                $stmtLedger->execute([$id]);
                $ledger = $stmtLedger->fetchAll(PDO::FETCH_ASSOC);

                foreach ($ledger as &$data) { 
                    $data['description'] = SecurityHelper::safeOutput($data['description']); 
                }
                $info['borrower_name'] = SecurityHelper::safeOutput($info['borrower_name']);
                $info['account_number'] = SecurityHelper::safeOutput($info['account_number']);

                echo json_encode([
                    'status' => 'success', 
                    'info' => $info, 
                    'totalPaid' => (float)$totalPaid, 
                    'totalProfit' => (float)$totalProfit, 
                    'ledger' => $ledger,
                    'ledger_meta' => ['page' => $page, 'pages' => $pages, 'total' => $totalRows]
                ]); exit;
            }

            if ($action === 'fetch_ledger') {
                $loanId  = isset($postData['loan_id']) && $postData['loan_id'] !== 'all' ? (int)$postData['loan_id'] : 'all';
                $page    = max(1, (int)($postData['page'] ?? 1));
                $perPage = 20;

                $hasFilter = ($loanId !== 'all');
                $where = $hasFilter ? 'WHERE l.loan_id = :lid' : '';
                $bind  = $hasFilter ? [':lid' => $loanId] : [];

                $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM sys_loan_ledger l $where");
                $cntStmt->execute($bind);
                $total   = (int)$cntStmt->fetchColumn();
                $pages   = max(1, (int)ceil($total / $perPage));
                $page    = min($page, $pages);
                $offset  = ($page - 1) * $perPage;   // int — তাই নিরাপদ

                $sql = "SELECT l.*, s.borrower_name, s.loan_category
                        FROM sys_loan_ledger l JOIN sys_loans s ON l.loan_id = s.id
                        $where
                        ORDER BY l.id DESC
                        LIMIT $perPage OFFSET $offset";
                $stmtLedger = $this->db->prepare($sql);
                $stmtLedger->execute($bind);

                $ledgerData = $stmtLedger->fetchAll(PDO::FETCH_ASSOC);
                foreach ($ledgerData as &$data) {
                    $data['borrower_name'] = SecurityHelper::safeOutput($data['borrower_name']);
                    $data['loan_category'] = SecurityHelper::safeOutput($data['loan_category']);
                    $data['description']   = SecurityHelper::safeOutput($data['description']);
                }
                echo json_encode([
                    'status' => 'success',
                    'ledger' => $ledgerData,
                    'meta'   => ['page' => $page, 'pages' => $pages, 'total' => $total]
                ]); exit;
            }

            if ($action === 'add_payment') {
                $loanId = (int)$postData['payment_loan_id'];
                $amount = (float)$postData['payment_amount'];
                if ($amount <= 0 || $loanId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Invalid payment data provided.']); exit; }

                $this->db->beginTransaction();
                $stmt = $this->db->prepare("SELECT current_balance FROM sys_loans WHERE id = ? FOR UPDATE");
                $stmt->execute([$loanId]);
                $loan = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if($loan) {
                    if ($amount > (float)$loan['current_balance']) {
                        $this->db->rollBack(); echo json_encode(['status' => 'error', 'message' => 'Payment cannot exceed current due balance!']); exit;
                    }
                    $paymentDesc = 'Installment Payment';
                    $stmtPay = $this->db->prepare("INSERT INTO sys_loan_ledger (loan_id, txn_date, description, debit_amount, credit_amount, balance, created_at) VALUES (?, ?, ?, 0.00, ?, 0.00, NOW())");
                    $stmtPay->execute([$loanId, date('Y-m-d'), $paymentDesc, $amount]);
                    
                    $this->loanModel->recalculateLoan($loanId); 
                    $this->db->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Payment received successfully.']); exit;
                }
            }

            if ($action === 'add_bank_loan') {
                $borrowerName = trim($postData['borrower_name']); 
                $principalAmt = (float)$postData['principal']; 
                $annualRate = (float)$postData['interest_rate']; 
                $durationMonths = (int)$postData['duration_months'];
                
                if ($principalAmt <= 0 || empty($borrowerName)) { echo json_encode(['status' => 'error', 'message' => 'Invalid data provided.']); exit; }

                $monthlyRate = $annualRate / 100 / 12;
                $monthlyEmi = ($monthlyRate == 0) ? ($principalAmt / $durationMonths) : ($principalAmt * $monthlyRate * pow(1 + $monthlyRate, $durationMonths) / (pow(1 + $monthlyRate, $durationMonths) - 1));
                $totalPayable = round($monthlyEmi * $durationMonths, 2); 
                $totalInterest = round($totalPayable - $principalAmt, 2);
                $accountNumber = 'BNK-' . date('ymd') . rand(100, 999);

                $this->db->beginTransaction();
                $stmt = $this->db->prepare("INSERT INTO sys_loans (borrower_name, account_number, loan_category, principal_amount, interest_rate, duration, frequency, installment_amount, total_installments, total_payable, total_interest, current_balance, status, created_at, effective_rate) VALUES (?, ?, 'bank', ?, ?, ?, 'monthly', ?, ?, ?, ?, 0.00, 'active', NOW(), 0.00)");
                $stmt->execute([$borrowerName, $accountNumber, $principalAmt, $annualRate, $durationMonths, round($monthlyEmi, 2), $durationMonths, $totalPayable, $totalInterest]);
                $newLoanId = (int)$this->db->lastInsertId();
                
                $openDesc = 'Loan Opening';
                $stmtLedger = $this->db->prepare("INSERT INTO sys_loan_ledger (loan_id, txn_date, description, debit_amount, credit_amount, balance, created_at) VALUES (?, ?, ?, ?, 0.00, 0.00, NOW())");
                $stmtLedger->execute([$newLoanId, date('Y-m-d'), $openDesc, $principalAmt]);
                
                $this->loanModel->recalculateLoan($newLoanId);
                $this->db->commit();
                echo json_encode(['status' => 'success', 'message' => 'Bank Loan added. ACC: ' . $accountNumber]); exit;
            }

            if ($action === 'add_ngo_loan') {
                $borrowerName = trim($postData['ngo_borrower_name']); 
                $principal = (float)$postData['ngo_principal']; 
                $flatRate = (float)$postData['ngo_interest_rate']; 
                $installments = (int)$postData['ngo_installments']; 
                $emiAmount = (float)$postData['ngo_emi_amount']; 
                $freq = $postData['ngo_frequency'];
                
                if ($principal <= 0 || empty($borrowerName)) { echo json_encode(['status' => 'error', 'message' => 'Invalid data provided.']); exit; }

                $totalPayable = round($emiAmount * $installments, 2); 
                $totalInterest = round($totalPayable - $principal, 2);
                $accountNumber = 'NGO-' . date('ymd') . rand(100, 999);
                
                $calculatedDurationMonths = ($freq === 'monthly') ? $installments : (($freq === 'weekly') ? (int)round($installments / 4) : (int)round($installments / 30));

                $this->db->beginTransaction();
                $stmt = $this->db->prepare("INSERT INTO sys_loans (borrower_name, account_number, loan_category, principal_amount, interest_rate, duration, frequency, installment_amount, total_installments, total_payable, total_interest, current_balance, status, created_at, effective_rate) VALUES (?, ?, 'ngo', ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'active', NOW(), 0.00)");
                $stmt->execute([$borrowerName, $accountNumber, $principal, $flatRate, $calculatedDurationMonths, $freq, round($emiAmount, 2), $installments, $totalPayable, $totalInterest]);
                $newLoanId = (int)$this->db->lastInsertId();
                
                $openDesc = 'Loan Opening';
                $stmtLedger = $this->db->prepare("INSERT INTO sys_loan_ledger (loan_id, txn_date, description, debit_amount, credit_amount, balance, created_at) VALUES (?, ?, ?, ?, 0.00, 0.00, NOW())");
                $stmtLedger->execute([$newLoanId, date('Y-m-d'), $openDesc, $principal]);
                
                $this->loanModel->recalculateLoan($newLoanId);
                $this->db->commit();
                echo json_encode(['status' => 'success', 'message' => 'NGO Loan added. ACC: ' . $accountNumber]); exit;
            }

            if ($action === 'edit_loan_ledger') {
                $id = (int)$postData['id']; 
                $newDesc = trim($postData['desc']); 
                $newAmount = (float)$postData['amount'];
                
                if ($newAmount < 0) { echo json_encode(['status' => 'error', 'message' => 'Amount cannot be negative.']); exit; }

                $this->db->beginTransaction();
                $stmtLedgerInfo = $this->db->prepare("SELECT loan_id, debit_amount, credit_amount FROM sys_loan_ledger WHERE id = ? FOR UPDATE");
                $stmtLedgerInfo->execute([$id]);
                $ledger = $stmtLedgerInfo->fetch(PDO::FETCH_ASSOC);
                
                if($ledger) {
                    if((float)$ledger['debit_amount'] > 0) {
                        $stmtUpdate = $this->db->prepare("UPDATE sys_loan_ledger SET debit_amount = ?, description = ? WHERE id = ?");
                        $stmtUpdate->execute([$newAmount, $newDesc, $id]);
                    } else if((float)$ledger['credit_amount'] > 0) {
                        $stmtUpdate = $this->db->prepare("UPDATE sys_loan_ledger SET credit_amount = ?, description = ? WHERE id = ?");
                        $stmtUpdate->execute([$newAmount, $newDesc, $id]);
                    }
                    $this->loanModel->recalculateLoan((int)$ledger['loan_id']);
                    $this->db->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Record updated successfully.']); exit;
                }
            }

            if ($action === 'delete_loan_ledger') {
                $id = (int)$postData['id'];
                $this->db->beginTransaction();
                $stmtLedgerInfo = $this->db->prepare("SELECT loan_id FROM sys_loan_ledger WHERE id = ? FOR UPDATE");
                $stmtLedgerInfo->execute([$id]);
                $ledger = $stmtLedgerInfo->fetch(PDO::FETCH_ASSOC);
                
                if($ledger) {
                    $stmtDelete = $this->db->prepare("DELETE FROM sys_loan_ledger WHERE id = ?");
                    $stmtDelete->execute([$id]);
                    $this->loanModel->recalculateLoan((int)$ledger['loan_id']);
                    $this->db->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Record deleted successfully.']); exit;
                }
            }

            if ($action === 'toggle_loan_status') {
                $id = (int)$postData['id'];
                $stmtLoan = $this->db->prepare("SELECT status FROM sys_loans WHERE id = ?");
                $stmtLoan->execute([$id]);
                $loan = $stmtLoan->fetch(PDO::FETCH_ASSOC);
                
                if ($loan) {
                    $newStatus = ($loan['status'] === 'active') ? 'inactive' : 'active';
                    $stmtUpdateStatus = $this->db->prepare("UPDATE sys_loans SET status = ? WHERE id = ?");
                    $stmtUpdateStatus->execute([$newStatus, $id]);
                    echo json_encode(['status' => 'success', 'message' => 'Status updated successfully.']); exit;
                }
            }

            if ($action === 'edit_loan_info') {
                $id = (int)$postData['id']; 
                $name = trim($postData['name']);
                if(empty($name)) { echo json_encode(['status' => 'error', 'message' => 'Name cannot be empty.']); exit; }
                
                $stmtUpdateName = $this->db->prepare("UPDATE sys_loans SET borrower_name = ? WHERE id = ?");
                $stmtUpdateName->execute([$name, $id]);
                echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully.']); exit;
            }

        } catch (Throwable $e) { 
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            $this->logger->logError($e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Server encountered a temporary error.']); exit; 
        }
    }
}

// 🚀 Request Initialization
if (isset($_POST['action'])) {
    $logger = new FileLogger();
    $controller = new DashboardController($pdo, $logger);
    $controller->handleRequest($_POST, $_SESSION['csrf_token']);
}
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Loan Dashboard — Sadakalo Enterprise</title>

    <!-- Bootstrap 5.3.8 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/assets/Accounts/sadakalo-app.css">
    <script src="/assets/Account/sadakalo-app.js"></script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- ═══ শুধু ব্যানারের জন্য inline CSS (অন্য কিছু পরিবর্তন করা হয়নি) ═══ -->
    <style>
        .hero {
            position: relative; margin-top: 14px;
            border-radius: var(--radius-lg, 22px); overflow: hidden;
            box-shadow: var(--shadow-card, 0 10px 30px -12px rgba(15,23,42,.25));
            border: 1px solid var(--border, #e2e8f0);
            background: var(--header-grad, linear-gradient(135deg,#4f46e5,#7c3aed 55%,#a855f7));
            min-height: 122px;
        }
        .hero-img { display: block; width: 100%; height: 100%; min-height: 122px; max-height: 180px; object-fit: cover; position: relative; z-index: 1; }
        .hero-scrim { position: absolute; inset: 0; z-index: 2; pointer-events: none;
            background: linear-gradient(180deg, rgba(79,70,229,.12) 0%, rgba(2,6,23,.30) 55%, rgba(2,6,23,.74) 100%); }
        .hero-cap { position: absolute; left: 16px; right: 16px; bottom: 13px; z-index: 3; display: flex; align-items: center; gap: 12px; }
        .hero-logo { flex-shrink: 0; width: 48px; height: 48px; border-radius: 14px; overflow: hidden;
            display: grid; place-items: center; color: #fff; font-size: 1.3rem;
            background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.55);
            box-shadow: 0 8px 18px -8px rgba(0,0,0,.55); }
        .hero-logo img { width: 100%; height: 100%; object-fit: cover; }
        .hero-title { color: #fff; font-weight: 800; font-size: 1.05rem; line-height: 1.12; text-shadow: 0 2px 10px rgba(0,0,0,.5); }
        .hero-sub { color: rgba(255,255,255,.88); font-size: .54rem; font-weight: 800; letter-spacing: 1.6px; text-transform: uppercase; margin-top: 3px; text-shadow: 0 1px 6px rgba(0,0,0,.5); }

        /* ═══ সেভ টোস্ট নোটিফিকেশন ═══ */
        .toast-stack { position: fixed; left: 0; right: 0; top: 0; z-index: 1090; display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 14px 12px 0; pointer-events: none; max-width: 560px; margin-inline: auto; }
        .toast { pointer-events: auto; width: 100%; display: flex; align-items: center; gap: 12px; padding: 13px 15px; border-radius: 16px; border: 1px solid var(--tc-border, var(--border)); background: var(--tc-bg, var(--surface)); box-shadow: 0 14px 32px -16px rgba(15,23,42,.45); font-weight: 700; font-size: .82rem; line-height: 1.35; color: var(--tc-text, var(--text)); transform: translateY(-18px); opacity: 0; transition: transform .34s cubic-bezier(.32,.72,0,1), opacity .28s ease; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast .tc-ic { flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: .82rem; background: var(--tc-accent, var(--brand-1)); box-shadow: 0 6px 14px -6px var(--tc-accent, var(--brand-1)); }
        .toast .tc-msg { flex: 1; min-width: 0; }
        .toast.t-success { --tc-accent: var(--c-green); --tc-bg: color-mix(in srgb, var(--c-green) 12%, var(--surface)); --tc-border: color-mix(in srgb, var(--c-green) 36%, transparent); --tc-text: color-mix(in srgb, var(--c-green) 72%, var(--text)); }
        .toast.t-error   { --tc-accent: var(--c-red);   --tc-bg: color-mix(in srgb, var(--c-red) 12%, var(--surface));   --tc-border: color-mix(in srgb, var(--c-red) 36%, transparent);   --tc-text: color-mix(in srgb, var(--c-red) 74%, var(--text)); }

        /* ═══ পেজিং (১ ২ ৩) ═══ */
        .pager { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; align-items: center; padding: 12px 8px 4px; }
        .pg-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 9px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font-weight: 800; font-size: .72rem; cursor: pointer; transition: all .15s ease; }
        .pg-btn:hover:not(:disabled):not(.on) { border-color: var(--brand-1); color: var(--brand-1); }
        .pg-btn.on { background: var(--brand-1); color: #fff; border-color: var(--brand-1); }
        .pg-btn:disabled { opacity: .4; cursor: not-allowed; }
        .pg-dots { color: var(--muted); font-weight: 800; padding: 0 2px; }
        .pg-info { width: 100%; text-align: center; font-size: .6rem; font-weight: 700; color: var(--muted); margin-top: 4px; }
    </style>
</head>
<body>

    <!-- ═══ সেভ টোস্ট স্ট্যাক ═══ -->
    <div id="toastStack" class="toast-stack" aria-live="polite"></div>

    <!-- ============ HEADER ============ -->
    <header class="app-header">
        <div class="hdr-row">
            <div class="d-flex align-items-center gap-2">
                <a href="../../" class="back-btn">⏩↘️</a>
                
                <div class="brand-badge"><i class="fas fa-landmark"></i></div>
                <div>
                    <div class="brand-title">Sadakalo Loan Panel</div>
                    <div class="brand-sub">Central Control</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="icon-pill" onclick="toggleTheme()" title="Toggle theme"><i data-theme-icon class="fas fa-moon"></i></div>
                <a href="../../logout.php" class="icon-pill danger" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </header>

    <div class="app-shell px-3">

        <!-- ═══ ব্যানার (লোগো সহ) — শুধু এটুকুই যোগ করা হয়েছে ═══ -->
        <div class="hero">
            <?php if (!empty($bannerImage)): ?>
                <img class="hero-img" src="<?php echo htmlspecialchars($bannerImage, ENT_QUOTES); ?>"
                     alt="<?php echo htmlspecialchars($bannerTitle, ENT_QUOTES); ?>" loading="lazy"
                     onerror="this.remove()">
            <?php endif; ?>
            <div class="hero-scrim"></div>
            <div class="hero-cap">
                <div class="hero-logo">
                    <?php if (!empty($bannerLogo)): ?>
                        <img src="<?php echo htmlspecialchars($bannerLogo, ENT_QUOTES); ?>" alt="logo"
                             onerror="this.replaceWith(Object.assign(document.createElement('i'),{className:'fas fa-landmark'}))">
                    <?php else: ?>
                        <i class="fas fa-landmark"></i>
                    <?php endif; ?>
                </div>
                <div class="hero-text">
                    <div class="hero-title"><?php echo htmlspecialchars($bannerTitle, ENT_QUOTES); ?></div>
                    <div class="hero-sub"><?php echo htmlspecialchars($bannerSub, ENT_QUOTES); ?></div>
                </div>
            </div>
        </div>

        <!-- ============ SUMMARY ============ -->
        <div class="stat-grid mt-3" style="grid-template-columns: repeat(3, 1fr);">
            <div class="stat-card tone-green">
                <div class="si"><i class="fas fa-coins"></i></div>
                <div class="sv" id="sumTodayProfit">৳0</div>
                <div class="sl">Today's Profit</div>
            </div>
            <div class="stat-card tone-blue">
                <div class="si"><i class="fas fa-chart-line"></i></div>
                <div class="sv" id="sumTotalProfit">৳0</div>
                <div class="sl">Total Profit</div>
            </div>
            <div class="stat-card tone-red">
                <div class="si"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="sv" id="sumTotalOut">৳0</div>
                <div class="sl">Total Due</div>
            </div>
        </div>

        <!-- ============ NAV ============ -->
        <div class="app-nav mt-4" style="grid-template-columns: repeat(4, 1fr);">
            <div class="nav-cell" onclick="toggleSection('bank')"><div class="nav-orb g-bank"><i class="fas fa-building-columns"></i></div><span class="nav-label">Bank Loan</span></div>
            <div class="nav-cell" onclick="toggleSection('ngo')"><div class="nav-orb g-ngo"><i class="fas fa-seedling"></i></div><span class="nav-label">NGO Loan</span></div>
            <a class="nav-cell" href="/Accounts/Dps/dps_dashboard.php"><div class="nav-orb g-dps"><i class="fas fa-piggy-bank"></i></div><span class="nav-label">DPS / FDR</span></a>
            <div class="nav-cell" onclick="toggleSection('payment')"><div class="nav-orb g-pay"><i class="fas fa-money-bill-wave"></i></div><span class="nav-label">Payment</span></div>
            <div class="nav-cell" onclick="toggleSection('profiles')"><div class="nav-orb g-profile"><i class="fas fa-id-card"></i></div><span class="nav-label">Profiles</span></div>
            <div class="nav-cell" onclick="toggleSection('ledger')"><div class="nav-orb g-ledger"><i class="fas fa-clock-rotate-left"></i></div><span class="nav-label">History</span></div>
            <a class="nav-cell" href="../../dashboard.php"><div class="nav-orb g-back"><i class="fas fa-house"></i></div><span class="nav-label">Dashboard</span></a>
        </div>

        <!-- ============ SECTIONS ============ -->
        <div class="mt-4 d-flex flex-column gap-3 pb-3">

            <!-- PROFILES -->
            <div id="section-profiles" class="section-panel">
                <div class="app-card mb-3">
                    <div class="card-head justify-content-between">
                        <span class="d-flex align-items-center gap-2"><span class="chi" style="background:var(--c-purple)"><i class="fas fa-users"></i></span> Borrower Profiles</span>
                        <div class="seg">
                            <button onclick="loadProfileList('active')" id="btnFilterActive" class="on">Active</button>
                            <button onclick="loadProfileList('inactive')" id="btnFilterInactive">Paid</button>
                        </div>
                    </div>
                </div>
                <div id="profileGrid" class="d-grid gap-3" style="grid-template-columns: 1fr;">
                    <div class="loading"><i class="fas fa-spinner fa-spin me-1"></i> Loading...</div>
                </div>
            </div>

            <!-- PROFILE DETAIL -->
            <div id="section-profile-detail" class="section-panel app-card">
                <div class="card-body" style="background:var(--header-grad);color:#fff;display:flex;justify-content:space-between;align-items:center;border-radius:0;">
                    <div>
                        <h2 id="detName" class="mb-2" style="font-weight:800;font-size:1.25rem;">Loading...</h2>
                        <span id="detAcc" class="chip" style="background:rgba(255,255,255,.18);color:#fff;border:none;">ACC-000</span>
                        <span id="detStatus" class="chip green">Active</span>
                    </div>
                    <button onclick="toggleSection('profiles')" class="icon-pill"><i class="fas fa-times"></i></button>
                </div>

                <div class="card-body border-bottom" style="border-color:var(--border)!important;">
                    <div class="field-label mb-2"><i class="fas fa-circle-info" style="color:var(--c-blue)"></i> Loan Details</div>
                    <div class="det-tiles">
                        <div class="det-tile"><span class="dl">Principal</span><span class="dv" id="detPrincipal">৳0</span></div>
                        <div class="det-tile"><span class="dl">Interest Rate</span><span class="dv" id="detRate">0%</span></div>
                        <div class="det-tile"><span class="dl">Installment</span><span class="dv" id="detInstAmt">৳0</span></div>
                        <div class="det-tile"><span class="dl">Total Payable</span><span class="dv" id="detTotalPayable">৳0</span></div>
                        <div class="det-tile"><span class="dl">Duration/Count</span><span class="dv" id="detDuration">0</span></div>
                        <div class="det-tile"><span class="dl">Frequency</span><span class="dv text-capitalize" id="detFreq">-</span></div>
                    </div>
                </div>

                <div class="det-tiles border-bottom" style="grid-template-columns:repeat(3,1fr);gap:0;border-color:var(--border)!important;">
                    <div class="text-center p-3" style="border-right:1px solid var(--border);">
                        <p class="dl mb-1" style="font-size:.54rem;font-weight:800;text-transform:uppercase;color:var(--muted);">Total Due</p>
                        <p id="detDue" class="mb-0" style="font-weight:800;color:var(--c-red);">৳0.00</p>
                    </div>
                    <div class="text-center p-3" style="border-right:1px solid var(--border);">
                        <p class="dl mb-1" style="font-size:.54rem;font-weight:800;text-transform:uppercase;color:var(--muted);">Total Paid</p>
                        <p id="detPaid" class="mb-0" style="font-weight:800;color:var(--c-green);">৳0.00</p>
                    </div>
                    <div class="text-center p-3">
                        <p class="dl mb-1" style="font-size:.54rem;font-weight:800;text-transform:uppercase;color:var(--muted);">Total Profit</p>
                        <p id="detProfit" class="mb-0" style="font-weight:800;color:var(--c-blue);">৳0.00</p>
                    </div>
                </div>

                <div class="card-body d-flex gap-2 border-bottom" style="border-color:var(--border)!important;">
                    <button id="btnEditInfo" class="app-btn btn-blue" style="font-size:.66rem;padding:9px;"><i class="fas fa-pen"></i> Edit Name</button>
                    <button id="btnToggleStatus" class="app-btn btn-violet" style="font-size:.66rem;padding:9px;"><i class="fas fa-power-off"></i> Change Status</button>
                </div>

                <div class="toolbar"><span class="field-label mb-0"><i class="fas fa-list" style="color:var(--muted)"></i> Full Statement</span></div>
                <div class="ledger-wrap">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th class="text-end">Debit (+)</th>
                                <th class="text-end">Credit (-)</th>
                                <th class="text-end">Balance</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="detLedgerBody"></tbody>
                    </table>
                </div>
                <div id="detLedgerPager"></div>
            </div>

            <!-- PAYMENT -->
            <div id="section-payment" class="section-panel app-card">
                <div class="card-head"><span class="chi" style="background:var(--c-red)"><i class="fas fa-money-check-dollar"></i></span> Receive Payment</div>
                <div class="card-body">
                    <form id="paymentForm" class="d-flex flex-column gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div><label class="field-label">Select Borrower</label><select name="payment_loan_id" id="paySelectLoan" class="app-input" required></select></div>
                        <div><label class="field-label">Payment Amount (৳)</label><input type="number" step="0.01" name="payment_amount" class="app-input input-amount pos" placeholder="0.00" required></div>
                        <button type="submit" class="app-btn btn-red"><i class="fas fa-check-circle"></i> Confirm Payment</button>
                    </form>
                </div>
            </div>

            <!-- BANK -->
            <div id="section-bank" class="section-panel app-card">
                <div class="card-head"><span class="chi" style="background:var(--c-blue)"><i class="fas fa-building-columns"></i></span> New Bank Loan</div>
                <div class="card-body">
                    <form id="bankLoanForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="col-12"><label class="field-label">Borrower Name</label><input type="text" name="borrower_name" class="app-input" required placeholder="Enter Name"></div>
                        <div class="col-6"><label class="field-label">Principal</label><input type="number" name="principal" class="app-input" required placeholder="0.00"></div>
                        <div class="col-6"><label class="field-label">Interest Rate (%)</label><input type="number" step="0.01" name="interest_rate" class="app-input" required placeholder="0.00"></div>
                        <div class="col-12"><label class="field-label">Duration (Months)</label><input type="number" name="duration_months" class="app-input" required placeholder="Months"></div>
                        <div class="col-12"><button type="submit" class="app-btn btn-blue"><i class="fas fa-floppy-disk"></i> Save Bank Loan</button></div>
                    </form>
                </div>
            </div>

            <!-- NGO -->
            <div id="section-ngo" class="section-panel app-card">
                <div class="card-head"><span class="chi" style="background:var(--c-green)"><i class="fas fa-seedling"></i></span> New NGO Loan</div>
                <div class="card-body">
                    <form id="ngoLoanForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="col-12"><label class="field-label">Borrower Name</label><input type="text" name="ngo_borrower_name" class="app-input" required placeholder="Enter Name"></div>
                        <div class="col-6"><label class="field-label">Principal</label><input type="number" name="ngo_principal" id="n_prin" class="app-input" required placeholder="0.00"></div>
                        <div class="col-6"><label class="field-label">Total Installments</label><input type="number" name="ngo_installments" id="n_inst" class="app-input" required placeholder="0"></div>
                        <div class="col-6"><label class="field-label">Frequency</label><select name="ngo_frequency" class="app-input"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></div>
                        <div class="col-6"><label class="field-label" style="color:var(--c-red)">Flat Rate (%)</label><input type="number" step="0.01" name="ngo_interest_rate" id="n_rate" class="app-input" required placeholder="0.00"></div>
                        <div class="col-12"><label class="field-label" style="color:var(--c-green)">Installment Amount (৳)</label><input type="number" step="0.01" name="ngo_emi_amount" id="n_emi" class="app-input" required placeholder="0.00"></div>
                        <div class="col-12"><button type="submit" class="app-btn btn-green"><i class="fas fa-floppy-disk"></i> Save NGO Loan</button></div>
                    </form>
                </div>
            </div>

            <!-- LEDGER -->
            <div id="section-ledger" class="section-panel app-card">
                <div class="toolbar">
                    <label class="field-label mb-0 text-nowrap"><i class="fas fa-filter" style="color:var(--c-sky)"></i> Filter</label>
                    <select id="loanFilter" onchange="fetchLedger()" class="app-input" style="padding:7px 10px;font-size:.72rem;">
                        <option value="all">All Loans</option>
                    </select>
                </div>
                <div class="ledger-wrap">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Borrower</th>
                                <th style="min-width:120px;">Description</th>
                                <th class="text-end">Debit (+)</th>
                                <th class="text-end">Credit (-)</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody id="ledgerBody">
                            <tr><td colspan="6" class="loading"><i class="fas fa-spinner fa-spin me-1"></i> Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="ledgerPager"></div>
            </div>

        </div>
    </div>

    <script>
        const appCsrfToken = '<?php echo $csrf_token; ?>';

        // ═══ পেজিং স্টেট ═══
        let ledgerPage = 1;
        let currentDetailId = null;
        let detLedgerPage = 1;

        // ═══ টোস্ট নোটিফিকেশন (সেভ করার সময়) ═══
        const TOAST_ICON = { success: 'fa-check', error: 'fa-exclamation', info: 'fa-info' };
        function showToast(type, message, timeout) {
            const t = TOAST_ICON[type] ? type : 'info';
            const ttl = (typeof timeout === 'number') ? timeout : (t === 'error' ? 5000 : 3400);
            const $t = $(
                '<div class="toast t-' + t + '" role="status">' +
                    '<span class="tc-ic"><i class="fas ' + TOAST_ICON[t] + '"></i></span>' +
                    '<span class="tc-msg"></span>' +
                    '<button class="tc-x"><i class="fas fa-times"></i></button>' +
                '</div>'
            );
            $t.find('.tc-msg').text(message == null ? '' : String(message));
            const remove = () => { $t.removeClass('show'); setTimeout(() => $t.remove(), 340); };
            $t.find('.tc-x').on('click', remove);
            $('#toastStack').append($t);
            requestAnimationFrame(() => requestAnimationFrame(() => $t.addClass('show')));
            if (ttl > 0) setTimeout(remove, ttl);
        }

        // ═══ পেজার রেন্ডার (১ ২ ৩ + ‹ ›) ═══
        function renderPager(containerId, meta, goFnName) {
            const el = document.getElementById(containerId);
            if (!el || !meta) return;
            const page = parseInt(meta.page), pages = parseInt(meta.pages);
            if (!pages || pages <= 1) { el.innerHTML = ''; return; }
            const nums = [];
            if (pages <= 7) { for (let i = 1; i <= pages; i++) nums.push(i); }
            else {
                nums.push(1);
                let s = Math.max(2, page - 1), e = Math.min(pages - 1, page + 1);
                if (s > 2) nums.push('…');
                for (let i = s; i <= e; i++) nums.push(i);
                if (e < pages - 1) nums.push('…');
                nums.push(pages);
            }
            let h = '<button class="pg-btn" ' + (page <= 1 ? 'disabled' : '') + ' onclick="' + goFnName + '(' + (page - 1) + ')">‹</button>';
            nums.forEach(n => {
                if (n === '…') h += '<span class="pg-dots">…</span>';
                else h += '<button class="pg-btn ' + (n === page ? 'on' : '') + '" onclick="' + goFnName + '(' + n + ')">' + n + '</button>';
            });
            h += '<button class="pg-btn" ' + (page >= pages ? 'disabled' : '') + ' onclick="' + goFnName + '(' + (page + 1) + ')">›</button>';
            h += '<div class="pg-info">পেজ ' + page + ' / ' + pages + ' • মোট ' + meta.total + '</div>';
            el.innerHTML = h;
        }

        function toggleSection(secId) {
            $('.section-panel').not('#section-' + secId).slideUp(200);
            $('#section-' + secId).slideDown(200).addClass('fade-in');
            
            if(secId === 'ledger') { fetchActiveLoans(); fetchLedger(1); } 
            if(secId === 'payment') { fetchActiveLoans(); }
            if(secId === 'profiles') { loadProfileList('active'); }
        }

        function fetchSummary() {
            $.post('', { action: 'fetch_summary', csrf_token: appCsrfToken }, function(res) {
                if(res.status === 'success') {
                    $('#sumTodayProfit').text('৳' + parseFloat(res.todayProfit).toLocaleString('en-US'));
                    $('#sumTotalProfit').text('৳' + parseFloat(res.totalProfit).toLocaleString('en-US'));
                    $('#sumTotalOut').text('৳' + parseFloat(res.totalOutstanding).toLocaleString('en-US'));
                }
            });
        }

        function fetchActiveLoans() {
            $.post('', { action: 'fetch_active_loans', csrf_token: appCsrfToken }, function(res) {
                if(res.status === 'success') {
                    let filterOps = '<option value="all">All Loans</option>';
                    let payOps = '<option value="">-- Select Borrower --</option>';
                    res.loans.forEach(l => {
                        let accStr = l.account_number ? `[${l.account_number}]` : '';
                        let txt = `${l.borrower_name} ${accStr} (Due: ৳${parseFloat(l.current_balance).toLocaleString('en-US')})`;
                        filterOps += `<option value="${l.id}">${l.borrower_name}</option>`;
                        payOps += `<option value="${l.id}">${txt}</option>`;
                    });
                    let currFilter = $('#loanFilter').val();
                    $('#loanFilter').html(filterOps);
                    $('#paySelectLoan').html(payOps);
                    if(currFilter) $('#loanFilter').val(currFilter);
                }
            });
        }

        let currentProfileStatus = 'active';
        function loadProfileList(status) {
            currentProfileStatus = status;
            $('#profileGrid').html('<div class="loading"><i class="fas fa-spinner fa-spin me-1"></i> Loading...</div>');
            
            if(status === 'active') {
                $('#btnFilterActive').addClass('on');
                $('#btnFilterInactive').removeClass('on');
            } else {
                $('#btnFilterInactive').addClass('on');
                $('#btnFilterActive').removeClass('on');
            }

            $.post('', { action: 'fetch_profiles', status: status, csrf_token: appCsrfToken }, function(res) {
                if(res.status === 'success') {
                    let html = '';
                    res.profiles.forEach(p => {
                        let accNo = p.account_number ? p.account_number : 'N/A';
                        let catClass = p.loan_category === 'bank' ? 'pc-bank' : 'pc-ngo';
                        let catChip = p.loan_category === 'bank' ? 'blue' : 'emerald';
                        let statusBadge = p.status === 'active' 
                            ? `<span class="chip green"><i class="fas fa-circle" style="font-size:.4rem"></i>Active</span>` 
                            : `<span class="chip slate"><i class="fas fa-check-circle"></i>Paid</span>`;
                            
                        html += `
                        <div onclick="openProfileDetails(${p.id})" class="profile-card ${catClass}">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h3 class="mb-2" style="font-weight:800;font-size:.98rem;">${p.borrower_name}</h3>
                                    <div class="d-flex gap-1">
                                        <span class="chip">${accNo}</span>
                                        <span class="chip ${catChip}">${p.loan_category}</span>
                                    </div>
                                </div>
                                <div class="text-end">
                                    ${statusBadge}
                                    <p class="mb-0 mt-2" style="font-size:.55rem;color:var(--muted);font-weight:800;text-transform:uppercase;">Due Amount</p>
                                    <p class="mb-0" style="font-size:1.2rem;font-weight:800;color:var(--c-red);line-height:1;">৳${parseFloat(p.current_balance).toLocaleString('en-US')}</p>
                                </div>
                            </div>
                        </div>`;
                    });
                    if(res.profiles.length === 0) html = `<div class="empty-state"><i class="fas fa-box-open"></i> No ${status === 'active' ? 'Active' : 'Paid'} profiles found</div>`;
                    $('#profileGrid').html(html);
                }
            });
        }

        function openProfileDetails(id) {
            currentDetailId = id;
            detLedgerPage = 1;
            loadProfileDetail(true);
        }
        function goDetailLedger(page) {
            detLedgerPage = page || 1;
            loadProfileDetail(false);
        }
        function loadProfileDetail(doOpen) {
            $.post('', { action: 'fetch_single_profile', id: currentDetailId, page: detLedgerPage, csrf_token: appCsrfToken }, function(res) {
                if(res.status === 'success') {
                    let info = res.info;
                    
                    $('#detName').text(info.borrower_name);
                    $('#detAcc').text(info.account_number ? info.account_number : 'N/A');
                    
                    if(info.status === 'active') {
                        $('#detStatus').attr('class', 'chip green').html('<i class="fas fa-circle" style="font-size:.4rem"></i>Active');
                    } else {
                        $('#detStatus').attr('class', 'chip slate').html('<i class="fas fa-check-circle"></i>Paid/Inactive');
                    }

                    $('#detPrincipal').text('৳' + parseFloat(info.principal_amount).toLocaleString('en-US'));
                    $('#detRate').text(parseFloat(info.interest_rate) + '%');
                    $('#detInstAmt').text('৳' + parseFloat(info.installment_amount).toLocaleString('en-US'));
                    $('#detTotalPayable').text('৳' + parseFloat(info.total_payable).toLocaleString('en-US'));
                    
                    let durationText = info.loan_category === 'bank' ? info.duration + ' Months' : info.total_installments + ' Installments';
                    $('#detDuration').text(durationText);
                    $('#detFreq').text(info.frequency);

                    $('#detDue').text('৳' + parseFloat(info.current_balance).toLocaleString('en-US'));
                    $('#detPaid').text('৳' + parseFloat(res.totalPaid).toLocaleString('en-US'));
                    $('#detProfit').text('৳' + parseFloat(res.totalProfit).toLocaleString('en-US'));

                    $('#btnEditInfo').attr('onclick', `editLoanInfo(${info.id}, '${info.borrower_name}')`);
                    $('#btnToggleStatus').attr('onclick', `toggleLoanStatus(${info.id})`);

                    let lHtml = '';
                    res.ledger.forEach(r => {
                        let isDebit = parseFloat(r.debit_amount) > 0;
                        let rowClass = '';
                        if(r.description.includes('Profit') || r.description.includes('মুনাফা')) rowClass = 'row-profit'; 
                        else if(r.description.includes('Payment') || r.description.includes('জমা')) rowClass = 'row-payment'; 
                        
                        let dateObj = new Date(r.txn_date);
                        let dFormat = dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                        let amtForEdit = isDebit ? r.debit_amount : r.credit_amount;
                        let editType = isDebit ? 'debit' : 'credit';

                        lHtml += `<tr class="${rowClass}">
                            <td class="text-nowrap">${dFormat}</td>
                            <td style="white-space:normal;font-size:.66rem;">${r.description}</td>
                            <td class="text-end text-nowrap">${isDebit ? '<span class="amt-neg">+৳'+parseFloat(r.debit_amount).toLocaleString('en-US')+'</span>' : '<span style="color:var(--muted)">—</span>'}</td>
                            <td class="text-end text-nowrap">${parseFloat(r.credit_amount) > 0 ? '<span class="amt-pos">-৳'+parseFloat(r.credit_amount).toLocaleString('en-US')+'</span>' : '<span style="color:var(--muted)">—</span>'}</td>
                            <td class="text-end text-nowrap amt-bal">৳${parseFloat(r.balance).toLocaleString('en-US')}</td>
                            <td class="text-center text-nowrap">
                                <button onclick="editLoanLedger(${r.id}, '${r.description}', ${amtForEdit}, '${editType}', ${info.id})" class="mini-act act-edit"><i class="fas fa-pen"></i></button>
                                <button onclick="deleteLoanLedger(${r.id}, ${info.id})" class="mini-act act-del"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>`;
                    });
                    if(res.ledger.length === 0) lHtml = '<tr><td colspan="6" class="empty-state">No statement found.</td></tr>';
                    $('#detLedgerBody').html(lHtml);
                    renderPager('detLedgerPager', res.ledger_meta, 'goDetailLedger');
                    
                    if (doOpen) toggleSection('profile-detail');
                } else {
                    showToast('error', res.message || 'লোড করা যায়নি।');
                }
            });
        }

        function fetchLedger(page) {
            ledgerPage = page || 1;
            let selectedLoanId = $('#loanFilter').val() || 'all';
            $.post('', { action: 'fetch_ledger', loan_id: selectedLoanId, page: ledgerPage, csrf_token: appCsrfToken }, function(res) {
                if(res.status === 'success') {
                    let html = '';
                    res.ledger.forEach(r => {
                        let isDebit = parseFloat(r.debit_amount) > 0;
                        let rowClass = '';
                        if(r.description.includes('Profit') || r.description.includes('মুনাফা')) rowClass = 'row-profit'; 
                        else if(r.description.includes('Payment') || r.description.includes('জমা')) rowClass = 'row-payment'; 
                        
                        let dateObj = new Date(r.txn_date);
                        let dFormat = dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

                        html += `<tr class="${rowClass}">
                            <td class="text-nowrap">${dFormat}</td>
                            <td class="text-nowrap" style="font-weight:800;color:var(--text);">${r.borrower_name} <span class="chip blue ms-1">${r.loan_category}</span></td>
                            <td style="white-space:normal;font-size:.66rem;">${r.description}</td>
                            <td class="text-end text-nowrap">${isDebit ? '<span class="amt-neg">+৳'+parseFloat(r.debit_amount).toLocaleString('en-US')+'</span>' : '<span style="color:var(--muted)">—</span>'}</td>
                            <td class="text-end text-nowrap">${parseFloat(r.credit_amount) > 0 ? '<span class="amt-pos">-৳'+parseFloat(r.credit_amount).toLocaleString('en-US')+'</span>' : '<span style="color:var(--muted)">—</span>'}</td>
                            <td class="text-end text-nowrap amt-bal">৳${parseFloat(r.balance).toLocaleString('en-US')}</td>
                        </tr>`;
                    });
                    if(res.ledger.length === 0) html = '<tr><td colspan="6" class="empty-state">No records found.</td></tr>';
                    $('#ledgerBody').html(html);
                    renderPager('ledgerPager', res.meta, 'fetchLedger');
                }
            });
        }

        function editLoanInfo(id, oldName) {
            Swal.fire({
                title: 'Edit Borrower Name',
                input: 'text', inputValue: oldName,
                showCancelButton: true, confirmButtonText: 'Update', confirmButtonColor: '#6366f1', cancelButtonColor: '#475569'
            }).then((result) => {
                if(result.isConfirmed && result.value) {
                    $.post('', { action: 'edit_loan_info', id: id, name: result.value, csrf_token: appCsrfToken }, function(res) {
                        showToast('success', 'নাম আপডেট হয়েছে।');
                        openProfileDetails(id); 
                    });
                }
            });
        }

        function toggleLoanStatus(id) {
            Swal.fire({
                title: 'Change Status?', text: "The account will be marked active/inactive.", icon: 'warning',
                showCancelButton: true, confirmButtonText: 'Yes, Change', confirmButtonColor: '#a855f7', cancelButtonColor: '#475569'
            }).then((result) => {
                if(result.isConfirmed) {
                    $.post('', { action: 'toggle_loan_status', id: id, csrf_token: appCsrfToken }, function(res) {
                        showToast('success', 'স্ট্যাটাস পরিবর্তন হয়েছে।');
                        openProfileDetails(id); fetchSummary();
                    });
                }
            });
        }

        function deleteLoanLedger(ledgerId, profileId) {
            Swal.fire({
                title: 'Are you sure?', text: "Balances will be automatically recalculated!", icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#e11d48', cancelButtonColor: '#475569', confirmButtonText: 'Yes, Delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', { action: 'delete_loan_ledger', id: ledgerId, csrf_token: appCsrfToken }, function(res) {
                        showToast('success', res.message || 'এন্ট্রি মুছে ফেলা হয়েছে।');
                        fetchSummary(); openProfileDetails(profileId);
                    });
                }
            });
        }

        function editLoanLedger(ledgerId, oldDesc, oldAmt, type, profileId) {
            let inputColor = (type === 'debit') ? '#f43f5e' : '#10b981'; 
            Swal.fire({
                title: 'Edit Entry',
                html: `<input id="swal-desc" class="swal2-input" value="${oldDesc}" placeholder="Description" style="font-size:13px;">
                       <input id="swal-amt" type="number" class="swal2-input" value="${oldAmt}" placeholder="Amount" style="color:${inputColor};font-weight:900;font-size:20px;text-align:center">`,
                showCancelButton: true, confirmButtonText: 'Update', confirmButtonColor: '#6366f1', cancelButtonColor: '#475569',
                preConfirm: () => { return { desc: document.getElementById('swal-desc').value, amount: document.getElementById('swal-amt').value } }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', { action: 'edit_loan_ledger', id: ledgerId, desc: result.value.desc, amount: result.value.amount, csrf_token: appCsrfToken }, function(res) {
                        showToast('success', res.message || 'হিসাব আপডেট হয়েছে।');
                        fetchSummary(); openProfileDetails(profileId);
                    });
                }
            });
        }

        $('#n_prin, #n_inst, #n_rate, #n_emi').on('input', function(e) {
            let active = e.target.id;
            let p = parseFloat($('#n_prin').val()) || 0, n = parseInt($('#n_inst').val()) || 0, emi = parseFloat($('#n_emi').val()) || 0, rate = parseFloat($('#n_rate').val()) || 0;
            if(p > 0 && n > 0) {
                if(active === 'n_emi' && emi > 0) {
                    $('#n_rate').val(((((emi * n) - p) / p) * 100).toFixed(2));
                } else if((active === 'n_rate' || active === 'n_prin' || active === 'n_inst') && rate >= 0) {
                    $('#n_emi').val(((p + (p * (rate / 100))) / n).toFixed(2));
                }
            }
        });

        $(document).ready(function() {
            fetchSummary(); 
            toggleSection('profiles');

            function handleAjaxForm(formId, actionName) {
                $('#' + formId).submit(function(e) {
                    e.preventDefault();
                    let form = $(this);
                    let submitBtn = form.find('button[type="submit"]');
                    let originalText = submitBtn.html();
                    
                    submitBtn.html('<i class="fas fa-spinner fa-spin me-1"></i> Processing...').prop('disabled', true);

                    $.post('', form.serialize() + '&action=' + actionName, function(res) {
                        if(res.status === 'success') {
                            showToast('success', res.message || 'সফলভাবে সেভ হয়েছে।');
                            form[0].reset(); 
                            fetchSummary(); 
                            toggleSection('profiles');
                        } else {
                            showToast('error', res.message || 'সেভ ব্যর্থ হয়েছে।');
                        }
                    }, 'json')
                    .fail(function() {
                        showToast('error', 'সার্ভারে সমস্যা হচ্ছে — আবার চেষ্টা করুন।');
                    })
                    .always(function() {
                        submitBtn.html(originalText).prop('disabled', false);
                    });
                });
            }

            handleAjaxForm('bankLoanForm', 'add_bank_loan');
            handleAjaxForm('ngoLoanForm', 'add_ngo_loan');
            handleAjaxForm('paymentForm', 'add_payment');
        });
    </script>
</body>
</html>
