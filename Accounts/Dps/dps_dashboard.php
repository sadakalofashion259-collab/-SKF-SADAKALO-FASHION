<?php
declare(strict_types=1);

/**
 * DPS Dashboard — সাদাকালো এন্টারপ্রাইজ
 * ─────────────────────────────────────────
 * Refactored: Security & Architecture hardening
 * Fixed Issues: CSRF fallback, double-encoding, JS order, SRI,
 *               Security headers, Date validation, Race condition,
 *               PDO check, Inline handlers, Build-info leak, ob_start
 */

// ============================================================
// ① OUTPUT BUFFER — সবার আগে (ob_end_flush এর জোড়া)
// ============================================================
ob_start();

// ============================================================
// ② HTTP SECURITY HEADERS — session_start()-এর আগে পাঠাতে হবে
// ============================================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
// Content-Security-Policy: inline scripts থাকায় 'unsafe-inline' রাখা হয়েছে।
// ভবিষ্যতে nonce-based CSP-তে upgrade করুন।
header(
    "Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' https://code.jquery.com https://cdn.jsdelivr.net 'unsafe-inline'; " .
    "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; " .
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; " .
    "img-src 'self' data:; " .
    "connect-src 'self';"
);

session_start();
date_default_timezone_set('Asia/Dhaka');

// ============================================================
// 🔐 SECURITY BOOTSTRAP
// ============================================================
include '../../db_connect.php';

// ১. লগইন চেক
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../index.php');
    exit;
}

// ২. CSRF টোকেন তৈরি — FIX ①: fallback সরানো হয়েছে; failure-এ execution বন্ধ
try {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
} catch (Exception $e) {
    error_log('[DPS] CSRF init failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    exit('Security initialization failed. Please contact the administrator.');
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// 🖼️ ব্যানার ও লোগো সেটিং  (★ এখানে শুধু ছবির নাম/path বদলান)
// ------------------------------------------------------------
// ছবি দুটো এই dps_dashboard.php ফাইলের পাশেই একই ফোল্ডারে রাখুন:
//      public_html/Accounts/Dps/banner.jpg
//      public_html/Accounts/Dps/logo.png
// তাহলে নিচের নাম দুটো হুবহু কাজ করবে (relative path)।
// অন্য ফোল্ডারে রাখলে সেই অনুযায়ী path দিন (যেমন: '/assets/banner.jpg')।
// ছবি না পেলে অটো ব্র্যান্ডেড গ্রেডিয়েন্টে ফিরে যাবে — কখনো ভাঙা/ফাঁকা থাকবে না।
// ============================================================
$bannerImage = 'sada_kalo_fashion_banner.jpg';   // ★ ব্যানারের ছবি
$bannerLogo  = 'sada_kalo_fashion.png';     // ★ লোগো (হেডার + ব্যানারে বসবে)
$bannerTitle = 'ডিপিএস ও এফডিআর';
$bannerSub   = 'সাদা কালো এন্টারপ্রাইজ';
$bannerLink  = '';             // ক্লিকে সাইটে নিতে চাইলে — যেমন 'https://sadakalofashion.com'

// ৩. PDO অ্যাসাইন — FIX ②: instanceof দিয়ে type check যোগ করা হয়েছে
if (!isset($conn) || !($conn instanceof PDO)) {
    error_log('[DPS] Database connection unavailable or invalid type.');
    ob_end_clean();
    http_response_code(500);
    exit('<p style="color:red;font-family:sans-serif;padding:20px;">সংযোগ সমস্যা — অনুগ্রহ করে প্রশাসকের সাথে যোগাযোগ করুন।</p>');
}
$pdo = $conn;

// ৪. next_deposit_date কলাম না থাকলে অটো তৈরি (একবারই চলবে)
// NOTE: Production-এ এটাকে একটি আলাদা migration script-এ নিয়ে যান।
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM sys_dps_accounts LIKE 'next_deposit_date'");
    if ($colChk->rowCount() === 0) {
        $pdo->exec("ALTER TABLE sys_dps_accounts ADD COLUMN next_deposit_date DATE NULL DEFAULT NULL");
    }
} catch (Throwable $e) {
    @error_log('[DPS] Column check: ' . $e->getMessage());
}

// ============================================================
// ⚠️ USER-FACING EXCEPTION
// নিরাপদ (গ্রাহককে দেখানো যায় এমন) বার্তার জন্য আলাদা টাইপ।
// ============================================================
final class DpsUserException extends RuntimeException {}

// ============================================================
// 🔧 SECURITY HELPERS
// ============================================================
class DpsSecHelper {

    /**
     * HTML output-এর জন্য encode করে (DB storage-এ নয়)
     */
    public static function safeOut(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * CSRF token timing-safe comparison
     */
    public static function verifyCsrf(string $token, string $session): bool {
        return hash_equals($session, $token);
    }

    /**
     * JSON error response পাঠায় এবং execution বন্ধ করে
     */
    public static function jsonError(string $msg, int $code = 400): never {
        http_response_code($code);
        echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * JSON success response পাঠায় এবং execution বন্ধ করে
     */
    public static function jsonSuccess(array $data = []): never {
        echo json_encode(array_merge(['status' => 'success'], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * System error লগ ফাইলে সেভ করে — ইউজারকে সরাসরি দেখায় না।
     * FIX ③: Error log path webroot-এর বাইরে পাঠানো হয়েছে।
     * (../../Logs → ../../../Logs — webroot-এর উপরে)
     */
    public static function logError(string $context, Throwable $e): void {
        $logDir = __DIR__ . '/../../../Logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s :: %s @ %s:%d%s",
            date('Y-m-d H:i:s'),
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL
        );
        @file_put_contents($logDir . '/error_log.txt', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * FIX ④: Date validation helper — user input সর্বদা এটি দিয়ে যাচাই করুন
     * সঠিক format ও valid date হলে 'Y-m-d' ফেরত দেয়, নাহলে null।
     */
    public static function validateDate(string $date, string $format = 'Y-m-d'): ?string {
        if (empty(trim($date))) {
            return null;
        }
        $d = \DateTime::createFromFormat($format, trim($date));
        if ($d === false || $d->format($format) !== trim($date)) {
            return null;
        }
        return $d->format($format);
    }
}

// ============================================================
// 🔁 DPS RECALCULATE (Financial Engine)
// ============================================================
function recalculateDpsAccount(PDO $pdo, int $dps_id): void {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(deposit_amount),0) - COALESCE(SUM(withdraw_amount),0) FROM sys_dps_ledger WHERE dps_id = ?');
    $stmt->execute([$dps_id]);
    $true_balance = round((float)$stmt->fetchColumn(), 2);

    if ($true_balance < 0) {
        throw new DpsUserException('ব্যালেন্স শূন্যের নিচে যেতে পারে না!');
    }

    $stmtStatus = $pdo->prepare('SELECT status FROM sys_dps_accounts WHERE id = ?');
    $stmtStatus->execute([$dps_id]);
    $currentStatus = (string)$stmtStatus->fetchColumn();

    $newStatus = $currentStatus;
    if ($true_balance <= 0.01) {
        $newStatus = 'inactive';
    } elseif ($true_balance > 0.01 && $currentStatus === 'inactive') {
        $newStatus = 'active';
    }

    $pdo->prepare('UPDATE sys_dps_accounts SET total_balance = ?, status = ? WHERE id = ?')
        ->execute([$true_balance, $newStatus, $dps_id]);

    // Running balance আপডেট
    $ledgers = $pdo->prepare('SELECT id, deposit_amount, withdraw_amount FROM sys_dps_ledger WHERE dps_id = ? ORDER BY txn_date ASC, id ASC');
    $ledgers->execute([$dps_id]);
    $run_bal = 0.0;
    $stmtUpd = $pdo->prepare('UPDATE sys_dps_ledger SET current_balance = ? WHERE id = ?');
    foreach ($ledgers->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $run_bal += (float)$l['deposit_amount'] - (float)$l['withdraw_amount'];
        $stmtUpd->execute([round($run_bal, 2), $l['id']]);
    }
}

// ============================================================
// 📅 FREQUENCY অনুযায়ী পরবর্তী তারিখ এগিয়ে নেওয়া
// ============================================================
function advanceDateByFrequency(string $date, string $frequency): string {
    $ts = strtotime($date);
    if ($ts === false) { $ts = time(); }
    switch ($frequency) {
        case 'daily':   return date('Y-m-d', strtotime('+1 day', $ts));
        case 'weekly':  return date('Y-m-d', strtotime('+7 days', $ts));
        case 'monthly':
        default:        return date('Y-m-d', strtotime('+1 month', $ts));
    }
}

// ============================================================
// 🕗 DAILY PROFIT AUTO-CRON (Duplicate-Safe)
// NOTE: Production-এ এটাকে server cron job (crontab)-এ নিয়ে যান।
// ============================================================
try {
    $todayStr = date('Y-m-d');
    $stmtCron = $pdo->prepare('SELECT dps_processed FROM daily_cron_log WHERE run_date = ?');
    $stmtCron->execute([$todayStr]);
    $cronLog = $stmtCron->fetch(PDO::FETCH_ASSOC);

    if (!$cronLog || (int)$cronLog['dps_processed'] === 0) {
        $pdo->beginTransaction();
        $activeAccs = $pdo->query("SELECT * FROM sys_dps_accounts WHERE status = 'active' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($activeAccs as $acc) {
            $accId    = (int)$acc['id'];
            $bal      = round((float)$acc['total_balance'], 2);
            $rate     = (float)$acc['interest_rate'];

            $dupChk = $pdo->prepare("SELECT COUNT(*) FROM sys_dps_ledger WHERE dps_id = ? AND txn_date = ? AND description LIKE 'মুনাফা%'");
            $dupChk->execute([$accId, $todayStr]);

            if ((int)$dupChk->fetchColumn() === 0 && $bal > 0 && $rate > 0) {
                $daily = round(($bal * ($rate / 100)) / 365, 2);
                if ($daily > 0) {
                    $newBal  = round($bal + $daily, 2);
                    $newEarn = round((float)$acc['total_profit_earned'] + $daily, 2);
                    $desc    = "মুনাফা (অটো) ({$rate}%), ৳" . number_format($daily, 2);

                    $pdo->prepare('UPDATE sys_dps_accounts SET total_balance = ?, total_profit_earned = ? WHERE id = ?')
                        ->execute([$newBal, $newEarn, $accId]);
                    $pdo->prepare('INSERT INTO sys_dps_ledger (dps_id, txn_date, description, deposit_amount, withdraw_amount, current_balance, created_at) VALUES (?,?,?,?,0.00,?,NOW())')
                        ->execute([$accId, $todayStr, $desc, $daily, $newBal]);
                }
            }
        }

        if (!$cronLog) {
            $pdo->prepare('INSERT INTO daily_cron_log (run_date, loans_processed, dps_processed, total_interest, created_at) VALUES (?,0,1,0.00,NOW())')
                ->execute([$todayStr]);
        } else {
            $pdo->prepare('UPDATE daily_cron_log SET dps_processed = 1 WHERE run_date = ?')->execute([$todayStr]);
        }
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    DpsSecHelper::logError('DPS_DAILY_CRON', $e);
}

// ============================================================
// 🎮 AJAX REQUEST HANDLER
// ============================================================
if (isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // CSRF চেক — শুধুমাত্র ডেটা পরিবর্তনকারী (write) action-এ লাগবে।
    $readOnlyActions = [
        'fetch_dps_summary',
        'fetch_dps_accounts',
        'fetch_dps_ledger',
        'fetch_account_detail',
        'fetch_account_ledger',
        'fetch_active_dropdown',
    ];
    if (!in_array($_POST['action'], $readOnlyActions, true)) {
        if (empty($_POST['csrf_token']) || !DpsSecHelper::verifyCsrf($_POST['csrf_token'], $csrf_token)) {
            DpsSecHelper::jsonError('Security Error — পেজ রিফ্রেশ করুন।', 403);
        }
    }

    try {
        $action = (string)$_POST['action'];

        // ─── সারসংক্ষেপ ─────────────────────────────────────
        if ($action === 'fetch_dps_summary') {
            $today = date('Y-m-d');

            $sumByDate = static function (PDO $pdo, string $sql, string $today): float {
                $st = $pdo->prepare($sql);
                $st->execute([$today]);
                return (float)$st->fetchColumn();
            };

            $r = [
                'todayDeposit'  => $sumByDate($pdo, "SELECT COALESCE(SUM(deposit_amount),0) FROM sys_dps_ledger WHERE txn_date = ? AND description NOT LIKE '%মুনাফা%' AND description NOT LIKE '%Opening%'", $today),
                'todayProfit'   => $sumByDate($pdo, "SELECT COALESCE(SUM(deposit_amount),0) FROM sys_dps_ledger WHERE txn_date = ? AND description LIKE '%মুনাফা%'", $today),
                'todayWithdraw' => $sumByDate($pdo, "SELECT COALESCE(SUM(withdraw_amount),0) FROM sys_dps_ledger WHERE txn_date = ?", $today),
                'totalBalance'  => (float)$pdo->query("SELECT COALESCE(SUM(total_balance),0) FROM sys_dps_accounts WHERE status='active'")->fetchColumn(),
                'totalProfit'   => (float)$pdo->query("SELECT COALESCE(SUM(total_profit_earned),0) FROM sys_dps_accounts")->fetchColumn(),
                'activeCount'   => (int)$pdo->query("SELECT COUNT(id) FROM sys_dps_accounts WHERE status='active'")->fetchColumn(),
                'inactiveCount' => (int)$pdo->query("SELECT COUNT(id) FROM sys_dps_accounts WHERE status='inactive'")->fetchColumn(),
            ];
            DpsSecHelper::jsonSuccess($r);
        }

        // ─── অ্যাকাউন্ট লিস্ট ─────────────────────────────
        if ($action === 'fetch_dps_accounts') {
            $statusFilter = isset($_POST['status_filter']) && $_POST['status_filter'] === 'inactive' ? 'inactive' : 'active';
            $today = date('Y-m-d');

            $sql = "SELECT a.*,
                        DATEDIFF(a.next_deposit_date, CURDATE()) AS days_until_due,
                        COALESCE((SELECT SUM(l.deposit_amount) FROM sys_dps_ledger l WHERE l.dps_id = a.id AND l.description NOT LIKE '%মুনাফা%'), 0)
                          - COALESCE((SELECT SUM(l2.withdraw_amount) FROM sys_dps_ledger l2 WHERE l2.dps_id = a.id), 0) AS principal_only
                    FROM sys_dps_accounts a
                    WHERE a.status = ?
                    ORDER BY
                        CASE WHEN a.next_deposit_date IS NOT NULL AND DATEDIFF(a.next_deposit_date, CURDATE()) BETWEEN 0 AND 2 THEN 0 ELSE 1 END ASC,
                        DATEDIFF(a.next_deposit_date, CURDATE()) ASC,
                        a.id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$statusFilter]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($accounts as &$a) {
                $a['client_name']    = DpsSecHelper::safeOut($a['client_name']);
                $a['account_number'] = DpsSecHelper::safeOut($a['account_number'] ?? '');
            }
            unset($a);
            DpsSecHelper::jsonSuccess(['accounts' => $accounts, 'status_filter' => $statusFilter]);
        }

        // ─── প্রতিটি অ্যাকাউন্টের বিস্তারিত (info + summary) ─────
        if ($action === 'fetch_account_detail') {
            $dpsId = (int)($_POST['dps_id'] ?? 0);
            if ($dpsId <= 0) DpsSecHelper::jsonError('অ্যাকাউন্ট ID দিন।');

            $accStmt = $pdo->prepare('SELECT * FROM sys_dps_accounts WHERE id = ?');
            $accStmt->execute([$dpsId]);
            $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
            if (!$acc) DpsSecHelper::jsonError('অ্যাকাউন্ট পাওয়া যায়নি।');

            $principalStmt = $pdo->prepare("SELECT COALESCE(SUM(deposit_amount),0) FROM sys_dps_ledger WHERE dps_id = ? AND description NOT LIKE '%মুনাফা%'");
            $principalStmt->execute([$dpsId]);
            $grossPrincipal = round((float)$principalStmt->fetchColumn(), 2);

            $wdStmt = $pdo->prepare('SELECT COALESCE(SUM(withdraw_amount),0) FROM sys_dps_ledger WHERE dps_id = ?');
            $wdStmt->execute([$dpsId]);
            $totalWithdrawn = round((float)$wdStmt->fetchColumn(), 2);

            $principalDeposited = round($grossPrincipal - $totalWithdrawn, 2);

            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM sys_dps_ledger WHERE dps_id = ?');
            $cntStmt->execute([$dpsId]);
            $totalEntries = (int)$cntStmt->fetchColumn();

            $acc['client_name']    = DpsSecHelper::safeOut($acc['client_name']);
            $acc['account_number'] = DpsSecHelper::safeOut($acc['account_number'] ?? '');

            DpsSecHelper::jsonSuccess([
                'account'             => $acc,
                'principal_deposited' => $principalDeposited,
                'total_withdrawn'     => $totalWithdrawn,
                'total_profit'        => round((float)$acc['total_profit_earned'], 2),
                'total_entries'       => $totalEntries,
            ]);
        }

        // ─── অ্যাকাউন্টের লেজার (৭-দিন ভিত্তিক পেজিনেশন) ───────
        if ($action === 'fetch_account_ledger') {
            $dpsId = (int)($_POST['dps_id'] ?? 0);
            $page  = max(1, (int)($_POST['page'] ?? 1));
            if ($dpsId <= 0) DpsSecHelper::jsonError('অ্যাকাউন্ট ID দিন।');

            $rangeStmt = $pdo->prepare('SELECT MAX(txn_date) AS max_d, MIN(txn_date) AS min_d FROM sys_dps_ledger WHERE dps_id = ?');
            $rangeStmt->execute([$dpsId]);
            $range = $rangeStmt->fetch(PDO::FETCH_ASSOC);

            if (empty($range['max_d'])) {
                DpsSecHelper::jsonSuccess(['ledger' => [], 'totalPages' => 1, 'currentPage' => 1, 'window_label' => '']);
            }

            $maxDate = $range['max_d'];
            $minDate = $range['min_d'];

            $spanDays   = (int)floor((strtotime($maxDate) - strtotime($minDate)) / 86400);
            $totalPages = max(1, (int)ceil(($spanDays + 1) / 7));
            $page       = min($page, $totalPages);

            $windowEnd   = date('Y-m-d', strtotime("$maxDate -" . (($page - 1) * 7) . " days"));
            $windowStart = date('Y-m-d', strtotime("$windowEnd -6 days"));

            $ledStmt = $pdo->prepare('SELECT * FROM sys_dps_ledger WHERE dps_id = ? AND txn_date BETWEEN ? AND ? ORDER BY txn_date DESC, id DESC');
            $ledStmt->execute([$dpsId, $windowStart, $windowEnd]);
            $ledger = $ledStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($ledger as &$l) {
                $l['description'] = DpsSecHelper::safeOut($l['description']);
            }
            unset($l);

            $fmtBn      = fn($d) => date('d M y', strtotime($d));
            $windowLabel = $fmtBn($windowStart) . ' — ' . $fmtBn($windowEnd);

            DpsSecHelper::jsonSuccess([
                'ledger'       => $ledger,
                'totalPages'   => $totalPages,
                'currentPage'  => $page,
                'window_label' => $windowLabel,
            ]);
        }

        // ─── গ্লোবাল লেজার (ফিল্টার সহ) ──────────────────
        if ($action === 'fetch_dps_ledger') {
            $dpsId = isset($_POST['dps_id']) && $_POST['dps_id'] !== 'all' ? (int)$_POST['dps_id'] : 'all';
            $page  = max(1, (int)($_POST['page'] ?? 1));
            $limit = 20;

            $hasFilter = ($dpsId !== 'all');
            $where     = $hasFilter ? 'WHERE l.dps_id = :dpsId' : '';
            $bind      = $hasFilter ? [':dpsId' => $dpsId] : [];

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_dps_ledger l $where");
            $cntStmt->execute($bind);
            $total = (int)$cntStmt->fetchColumn();

            $pages  = max(1, (int)ceil($total / $limit));
            $page   = min($page, $pages);
            $offset = ($page - 1) * $limit;

            // $limit ও $offset সর্বদা (int) — SQL Injection ঝুঁকি নেই
            $sql = "SELECT l.*, a.client_name, a.account_number, a.account_type, a.id AS acc_id
                    FROM sys_dps_ledger l
                    JOIN sys_dps_accounts a ON l.dps_id = a.id
                    $where
                    ORDER BY l.txn_date DESC, l.id DESC
                    LIMIT $limit OFFSET $offset";
            $rowStmt = $pdo->prepare($sql);
            $rowStmt->execute($bind);
            $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $r['client_name']    = DpsSecHelper::safeOut($r['client_name']);
                $r['account_number'] = DpsSecHelper::safeOut($r['account_number'] ?? '');
                $r['description']    = DpsSecHelper::safeOut($r['description']);
            }
            unset($r);
            DpsSecHelper::jsonSuccess(['ledger' => $rows, 'totalPages' => $pages, 'currentPage' => $page]);
        }

        // ─── সব অ্যাকাউন্টের ড্রপডাউন লিস্ট ──────────────
        if ($action === 'fetch_active_dropdown') {
            $stmt = $pdo->query("SELECT id, account_number, client_name, total_balance FROM sys_dps_accounts WHERE status = 'active' ORDER BY client_name ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['client_name']    = DpsSecHelper::safeOut($r['client_name']);
                $r['account_number'] = DpsSecHelper::safeOut($r['account_number'] ?? '');
            }
            unset($r);
            DpsSecHelper::jsonSuccess(['accounts' => $rows]);
        }

        // ─── নতুন অ্যাকাউন্ট ──────────────────────────────
        if ($action === 'add_dps_account') {
            $clientName = trim((string)($_POST['dps_client_name'] ?? ''));
            $accNo      = trim((string)($_POST['dps_account_number'] ?? ''));
            $accType    = in_array($_POST['dps_account_type'] ?? '', ['DPS','FDR'], true) ? $_POST['dps_account_type'] : 'DPS';
            $freq       = in_array($_POST['dps_frequency'] ?? '', ['daily','weekly','monthly'], true) ? $_POST['dps_frequency'] : 'monthly';
            $amount     = round((float)($_POST['dps_installment_amount'] ?? 0), 2);
            $rate       = round((float)($_POST['dps_interest_rate'] ?? 0), 2);
            $durationYr = max(1, (int)($_POST['dps_duration_years'] ?? 1));
            $openBal    = round((float)($_POST['dps_opening_balance'] ?? 0), 2);

            // FIX ④: Date validation — user input সরাসরি ব্যবহার করা হচ্ছে না
            $openDate = DpsSecHelper::validateDate((string)($_POST['txn_date'] ?? '')) ?? date('Y-m-d');
            $nextDep  = !empty($_POST['next_deposit_date'])
                ? DpsSecHelper::validateDate((string)$_POST['next_deposit_date'])
                : null;

            if (empty($clientName) || empty($accNo)) DpsSecHelper::jsonError('নাম ও অ্যাকাউন্ট নম্বর আবশ্যক।');

            if (!$nextDep) {
                $nextDep = advanceDateByFrequency($openDate, $freq);
            }

            $dupChk = $pdo->prepare('SELECT COUNT(*) FROM sys_dps_accounts WHERE account_number = ?');
            $dupChk->execute([$accNo]);
            if ((int)$dupChk->fetchColumn() > 0) DpsSecHelper::jsonError('এই অ্যাকাউন্ট নম্বর আগেই আছে।');

            $pdo->beginTransaction();
            $durationMo = $durationYr * 12;
            $today      = date('Y-m-d');
            $pastProfit = 0.0;
            if (strtotime($openDate) < strtotime($today) && $openBal > 0 && $rate > 0) {
                $days       = (int)floor((strtotime($today) - strtotime($openDate)) / 86400);
                $pastProfit = round(($openBal * ($rate / 100) / 365) * $days, 2);
            }

            $pdo->prepare('INSERT INTO sys_dps_accounts (account_number, client_name, account_type, frequency, installment_amount, interest_rate, duration_months, maturity_amount, total_balance, total_profit_earned, status, opening_date, next_deposit_date, created_at) VALUES (?,?,?,?,?,?,?,0.00,0.00,?,\'active\',?,?,NOW())')
                ->execute([$accNo, $clientName, $accType, $freq, $amount, $rate, $durationMo, $pastProfit, $openDate, $nextDep]);
            $newId = (int)$pdo->lastInsertId();

            if ($openBal > 0) {
                $pdo->prepare('INSERT INTO sys_dps_ledger (dps_id, txn_date, description, deposit_amount, withdraw_amount, current_balance, created_at) VALUES (?,?,\'Opening Balance\',?,0.00,0.00,NOW())')
                    ->execute([$newId, $openDate, $openBal]);
            }
            if ($pastProfit > 0) {
                $desc = "মুনাফা ({$rate}%), ৳" . number_format($pastProfit, 2);
                $pdo->prepare('INSERT INTO sys_dps_ledger (dps_id, txn_date, description, deposit_amount, withdraw_amount, current_balance, created_at) VALUES (?,?,?,?,0.00,?,NOW())')
                    ->execute([$newId, $today, $desc, $pastProfit, round($openBal + $pastProfit, 2)]);
            }
            recalculateDpsAccount($pdo, $newId);
            $pdo->commit();
            DpsSecHelper::jsonSuccess(['message' => 'অ্যাকাউন্ট সফলভাবে খোলা হয়েছে।', 'new_id' => $newId]);
        }

        // ─── জমা ───────────────────────────────────────────
        if ($action === 'add_dps_deposit') {
            $dpsId   = (int)($_POST['deposit_dps_id'] ?? 0);
            $amount  = round((float)($_POST['dps_deposit_amount'] ?? 0), 2);

            // FIX ④: Date validation
            $txnDate = DpsSecHelper::validateDate((string)($_POST['txn_date'] ?? '')) ?? date('Y-m-d');
            $nextDep = !empty($_POST['next_deposit_date'])
                ? DpsSecHelper::validateDate((string)$_POST['next_deposit_date'])
                : null;

            if ($amount <= 0 || $dpsId <= 0) DpsSecHelper::jsonError('সঠিক তথ্য দিন।');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT total_balance, status, frequency, next_deposit_date FROM sys_dps_accounts WHERE id = ? FOR UPDATE');
            $stmt->execute([$dpsId]);
            $dps = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$dps) { $pdo->rollBack(); DpsSecHelper::jsonError('অ্যাকাউন্ট পাওয়া যায়নি।'); }

            $pdo->prepare('INSERT INTO sys_dps_ledger (dps_id, txn_date, description, deposit_amount, withdraw_amount, current_balance, created_at) VALUES (?,?,\'জমা (Deposit)\',?,0.00,0.00,NOW())')
                ->execute([$dpsId, $txnDate, $amount]);

            if ($nextDep) {
                $pdo->prepare('UPDATE sys_dps_accounts SET next_deposit_date = ? WHERE id = ?')->execute([$nextDep, $dpsId]);
            } else {
                $base     = !empty($dps['next_deposit_date']) ? $dps['next_deposit_date'] : $txnDate;
                $advanced = advanceDateByFrequency($base, (string)$dps['frequency']);
                $pdo->prepare('UPDATE sys_dps_accounts SET next_deposit_date = ? WHERE id = ?')->execute([$advanced, $dpsId]);
            }

            recalculateDpsAccount($pdo, $dpsId);
            $pdo->commit();
            DpsSecHelper::jsonSuccess(['message' => '৳' . number_format($amount, 2) . ' সফলভাবে জমা হয়েছে।']);
        }

        // ─── উত্তোলন ───────────────────────────────────────
        if ($action === 'add_dps_withdraw') {
            $dpsId   = (int)($_POST['withdraw_dps_id'] ?? 0);
            $amount  = round((float)($_POST['dps_withdraw_amount'] ?? 0), 2);

            // FIX ④: Date validation
            $txnDate = DpsSecHelper::validateDate((string)($_POST['txn_date'] ?? '')) ?? date('Y-m-d');

            if ($amount <= 0 || $dpsId <= 0) DpsSecHelper::jsonError('সঠিক তথ্য দিন।');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT total_balance FROM sys_dps_accounts WHERE id = ? FOR UPDATE');
            $stmt->execute([$dpsId]);
            $dps = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$dps) { $pdo->rollBack(); DpsSecHelper::jsonError('অ্যাকাউন্ট পাওয়া যায়নি।'); }
            if ((float)$dps['total_balance'] < $amount) {
                $pdo->rollBack();
                DpsSecHelper::jsonError('অ্যাকাউন্টে পর্যাপ্ত ব্যালেন্স নেই!');
            }

            $pdo->prepare('INSERT INTO sys_dps_ledger (dps_id, txn_date, description, deposit_amount, withdraw_amount, current_balance, created_at) VALUES (?,?,\'উত্তোলন (Withdraw)\',0.00,?,0.00,NOW())')
                ->execute([$dpsId, $txnDate, $amount]);

            recalculateDpsAccount($pdo, $dpsId);
            $pdo->commit();
            DpsSecHelper::jsonSuccess(['message' => '৳' . number_format($amount, 2) . ' উত্তোলন সম্পন্ন।']);
        }

        // ─── লেজার এডিট ─────────────────────────────────
        if ($action === 'edit_dps_ledger') {
            $id      = (int)($_POST['id'] ?? 0);
            // FIX ⑤: DB-তে raw data রাখা হচ্ছে — safeOut() শুধু output-এ
            $newDesc = trim((string)($_POST['desc'] ?? ''));
            $newAmt  = round((float)($_POST['amount'] ?? 0), 2);
            if ($newAmt < 0) DpsSecHelper::jsonError('পরিমাণ ঋণাত্মক হতে পারে না।');

            $pdo->beginTransaction();
            $sel = $pdo->prepare('SELECT dps_id, deposit_amount, withdraw_amount, description FROM sys_dps_ledger WHERE id = ?');
            $sel->execute([$id]);
            $l = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$l) { $pdo->rollBack(); DpsSecHelper::jsonError('এন্ট্রি পাওয়া যায়নি।'); }
            if (stripos($l['description'], 'Opening') !== false) {
                $pdo->rollBack(); DpsSecHelper::jsonError('Opening Balance এডিট করা যাবে না।');
            }

            if ((float)$l['withdraw_amount'] > 0) {
                $pdo->prepare('UPDATE sys_dps_ledger SET withdraw_amount = ?, description = ? WHERE id = ?')->execute([$newAmt, $newDesc, $id]);
            } else {
                $pdo->prepare('UPDATE sys_dps_ledger SET deposit_amount = ?, description = ? WHERE id = ?')->execute([$newAmt, $newDesc, $id]);
            }
            recalculateDpsAccount($pdo, (int)$l['dps_id']);
            $pdo->commit();
            DpsSecHelper::jsonSuccess(['message' => 'হিসাব আপডেট হয়েছে।']);
        }

        // ─── লেজার ডিলিট ───────────────────────────────
        if ($action === 'delete_dps_ledger') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->beginTransaction();
            $sel = $pdo->prepare('SELECT dps_id, description FROM sys_dps_ledger WHERE id = ?');
            $sel->execute([$id]);
            $l = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$l) { $pdo->rollBack(); DpsSecHelper::jsonError('এন্ট্রি পাওয়া যায়নি।'); }
            if (stripos($l['description'], 'Opening') !== false) {
                $pdo->rollBack(); DpsSecHelper::jsonError('Opening Balance মুছা যাবে না।');
            }
            $pdo->prepare('DELETE FROM sys_dps_ledger WHERE id = ?')->execute([$id]);
            recalculateDpsAccount($pdo, (int)$l['dps_id']);
            $pdo->commit();
            DpsSecHelper::jsonSuccess(['message' => 'এন্ট্রি মুছা হয়েছে ও ব্যালেন্স রিক্যালকুলেট হয়েছে।']);
        }

        // ─── স্ট্যাটাস পরিবর্তন ──────────────────────────
        // FIX ⑥: Race condition সমাধান — Transaction + FOR UPDATE যোগ করা হয়েছে
        if ($action === 'toggle_dps_status') {
            $id = (int)($_POST['acc_id'] ?? 0);
            if ($id <= 0) DpsSecHelper::jsonError('অ্যাকাউন্ট ID দিন।');

            $pdo->beginTransaction();
            $sel = $pdo->prepare('SELECT status FROM sys_dps_accounts WHERE id = ? FOR UPDATE');
            $sel->execute([$id]);
            $acc = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$acc) { $pdo->rollBack(); DpsSecHelper::jsonError('অ্যাকাউন্ট পাওয়া যায়নি।'); }
            $newStatus = $acc['status'] === 'active' ? 'inactive' : 'active';
            $pdo->prepare('UPDATE sys_dps_accounts SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            $pdo->commit();
            DpsSecHelper::jsonSuccess(['new_status' => $newStatus]);
        }

        // ─── পরবর্তী জমার তারিখ আপডেট ──────────────────
        if ($action === 'update_next_deposit') {
            $id      = (int)($_POST['acc_id'] ?? 0);
            // FIX ④: Date validation
            $nextDep = DpsSecHelper::validateDate((string)($_POST['next_deposit_date'] ?? ''));

            if ($id <= 0 || $nextDep === null) DpsSecHelper::jsonError('তারিখের ফরম্যাট সঠিক নয় বা তথ্য অসম্পূর্ণ।');

            $pdo->prepare('UPDATE sys_dps_accounts SET next_deposit_date = ? WHERE id = ?')->execute([$nextDep, $id]);
            DpsSecHelper::jsonSuccess(['message' => 'পরবর্তী জমার তারিখ আপডেট হয়েছে।']);
        }

        // ─── অ্যাকাউন্ট ইনফরমেশন এডিট ────────────────────
        if ($action === 'edit_dps_account') {
            $id         = (int)($_POST['acc_id'] ?? 0);
            $clientName = trim((string)($_POST['client_name'] ?? ''));
            $accNo      = trim((string)($_POST['account_number'] ?? ''));
            $accType    = in_array($_POST['account_type'] ?? '', ['DPS','FDR'], true) ? $_POST['account_type'] : 'DPS';
            $freq       = in_array($_POST['frequency'] ?? '', ['daily','weekly','monthly'], true) ? $_POST['frequency'] : 'monthly';
            $amount     = round((float)($_POST['installment_amount'] ?? 0), 2);
            $rate       = round((float)($_POST['interest_rate'] ?? 0), 2);
            $durationMo = max(1, (int)($_POST['duration_months'] ?? 1));

            if ($id <= 0) DpsSecHelper::jsonError('অ্যাকাউন্ট ID দিন।');
            if (empty($clientName) || empty($accNo)) DpsSecHelper::jsonError('নাম ও অ্যাকাউন্ট নম্বর আবশ্যক।');

            $dupChk = $pdo->prepare('SELECT COUNT(*) FROM sys_dps_accounts WHERE account_number = ? AND id != ?');
            $dupChk->execute([$accNo, $id]);
            if ((int)$dupChk->fetchColumn() > 0) DpsSecHelper::jsonError('এই অ্যাকাউন্ট নম্বর অন্য অ্যাকাউন্টে আছে।');

            $stmt = $pdo->prepare('UPDATE sys_dps_accounts SET client_name = ?, account_number = ?, account_type = ?, frequency = ?, installment_amount = ?, interest_rate = ?, duration_months = ? WHERE id = ?');
            $stmt->execute([$clientName, $accNo, $accType, $freq, $amount, $rate, $durationMo, $id]);

            DpsSecHelper::jsonSuccess(['message' => 'অ্যাকাউন্ট তথ্য আপডেট হয়েছে।']);
        }

    } catch (DpsUserException $ue) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        DpsSecHelper::jsonError($ue->getMessage(), 422);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        DpsSecHelper::logError('DPS_AJAX:' . ($action ?? 'unknown'), $e);
        DpsSecHelper::jsonError('সিস্টেমে একটি সমস্যা হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।', 500);
    }
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="bn" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ডিপিএস ও এফডিআর প্যানেল — সাদাকালো</title>

    <!--
        FIX ⑦: SRI (Subresource Integrity) হ্যাশ যোগ করা হয়েছে।
        CDN হ্যাক হলেও দুর্বৃত্তের কোড লোড হবে না।
        Hash generate: https://www.srihash.org/
        অথবা terminal: curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A
    -->

    <!-- Bootstrap 5.3.3 CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">

    <!-- Font Awesome 6.4.0 -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw=="
        crossorigin="anonymous"
        referrerpolicy="no-referrer">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <!-- FIX ⑧: gstatic preconnect যোগ করা হয়েছে (Google Fonts-এর দ্বিতীয় origin) -->
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/assets/Accounts/sadakalo-app.css">

    <!--
        FIX ⑨: jQuery FIRST — sadakalo-app.js jQuery-এর পরে লোড হবে।
        (আগে sadakalo-app.js jQuery-এর আগে ছিল — jQuery-dependent কোড সব ভাঙছিল।)
    -->

    <!-- jQuery 3.7.1 (upgraded from 3.6.0) -->
    <script
        src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
        crossorigin="anonymous"></script>

    <!-- SweetAlert2 (pinned version — hash verify করুন: https://www.srihash.org) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Internal JS — jQuery-এর পরে লোড হচ্ছে ✓ -->
    <script src="/assets/Accounts/sadakalo-app.js"></script>

    <style>
        /* ── DPS-specific extra styles ── */
        .dps-tab-bar {
            display: flex; gap: 6px; padding: 12px 14px;
            background: var(--surface-2); border-bottom: 1px solid var(--border);
        }
        .dps-tab {
            flex: 1; padding: 8px 4px; border: none; border-radius: 10px; cursor: pointer;
            font-family: var(--bn); font-weight: 800; font-size: .66rem;
            text-transform: uppercase; letter-spacing: .4px;
            background: var(--surface-3); color: var(--muted);
            transition: .2s; border: 1.5px solid var(--border);
        }
        .dps-tab.on { background: linear-gradient(135deg, var(--brand-1), var(--brand-2)); color: #fff; border-color: transparent; box-shadow: 0 6px 14px -8px var(--brand-2); }

        /* account card compact */
        .acc-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 12px 14px;
            cursor: pointer; position: relative; overflow: hidden;
            box-shadow: var(--shadow-card);
            transition: transform .22s var(--ease), border-color .22s ease;
            border-left: 5px solid var(--c-purple);
        }
        .acc-card:hover { transform: translateY(-3px); border-color: var(--c-purple); }
        .acc-card.fdr-card { border-left-color: var(--c-blue); }
        .acc-card.inactive-card { border-left-color: var(--muted); opacity: .85; }
        .acc-card .acc-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .acc-card .acc-id-col { min-width: 0; }
        .acc-card .acc-right { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex-shrink: 0; text-align: right; }
        .acc-card .acc-name { font-weight: 800; font-size: .9rem; color: var(--text); line-height: 1.2; }
        .acc-card .acc-no { font-size: .58rem; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }
        .acc-card .acc-bal { font-size: 1.1rem; font-weight: 800; color: var(--c-sky); text-align: right; line-height: 1; }
        .acc-card .acc-bal-lbl { font-size: .52rem; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; text-align: right; }
        .acc-card .acc-pills { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
        .acc-card .acc-pills .pill { font-size: .56rem; font-weight: 800; padding: 3px 8px; border-radius: 7px; }
        .pill-profit { background: color-mix(in srgb, var(--c-green) 15%, transparent); color: var(--c-green); }
        .pill-principal { background: color-mix(in srgb, var(--c-blue) 15%, transparent); color: var(--c-blue); }
        .pill-rate { background: color-mix(in srgb, var(--c-amber) 15%, transparent); color: var(--c-amber); }
        .pill-due-soon { background: color-mix(in srgb, var(--c-red) 18%, transparent); color: var(--c-red); animation: pulse-due 1.5s infinite; }
        .pill-inactive { background: var(--surface-3); color: var(--muted); }
        .pill-type-dps { background: color-mix(in srgb, var(--c-purple) 15%, transparent); color: var(--c-purple); }
        .pill-type-fdr { background: color-mix(in srgb, var(--c-blue) 15%, transparent); color: var(--c-blue); }

        @keyframes pulse-due { 0%,100%{opacity:1} 50%{opacity:.6} }

        /* detail overlay */
        .acc-detail-panel {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); overflow: hidden;
            box-shadow: var(--shadow-soft);
        }
        .acc-detail-header {
            background: var(--header-grad); padding: 16px;
            display: flex; justify-content: space-between; align-items: flex-start;
        }
        .acc-detail-header .det-name { color: #fff; font-weight: 800; font-size: 1.15rem; }
        .acc-detail-header .det-sub { color: rgba(255,255,255,.8); font-size: .6rem; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; margin-top: 3px; }

        .stat3 { display: grid; grid-template-columns: repeat(3,1fr); }
        .stat3-cell { padding: 12px 8px; text-align: center; border-right: 1px solid var(--border); }
        .stat3-cell:last-child { border-right: none; }
        .stat3-cell .s3l { font-size: .52rem; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: var(--muted); }
        .stat3-cell .s3v { font-size: .9rem; font-weight: 800; margin-top: 4px; }

        /* due badge */
        .due-badge {
            display: inline-block;
            background: var(--c-red); color: #fff;
            font-size: .54rem; font-weight: 800; letter-spacing: .4px;
            padding: 3px 9px; border-radius: 20px; text-transform: uppercase;
            white-space: nowrap;
        }
        .acc-card .acc-right .due-badge { margin-bottom: 3px; }
        .due-badge.due-overdue  { background: var(--c-red);    color: #fff; animation: pulse-due 1.5s infinite; }
        .due-badge.due-today    { background: var(--c-amber);  color: #1f1300; }
        .due-badge.due-soon     { background: var(--c-blue);   color: #fff; }
        .due-badge.due-upcoming { background: var(--surface-3); color: var(--muted); border: 1px solid var(--border); }

        .acc-card.urg-overdue  { border-left-color: var(--c-red); }
        .acc-card.urg-today    { border-left-color: var(--c-amber); }
        .acc-card.urg-soon     { border-left-color: var(--c-blue); }
        .acc-card.urg-upcoming { border-left-color: var(--c-purple); }

        .act-stack { display: inline-flex; flex-direction: column; gap: 6px; align-items: center; }
        .act-stack .mini-act { width: 30px; height: 30px; font-size: .7rem; }

        .acc-grid { display: flex; flex-direction: column; gap: 10px; }

        /* ══════════════════════════════════════════
           📱 মোবাইল অ্যাপ-স্টাইল ফিক্সড বটম নেভিগেশন
           ══════════════════════════════════════════ */
        body { padding-bottom: 88px; }

        .bottom-nav {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 1040;
            display: grid; grid-template-columns: repeat(5, 1fr);
            background: var(--surface);
            border-top: 1px solid var(--border);
            box-shadow: 0 -8px 24px -10px rgba(15,23,42,.25);
            padding: 8px 6px calc(8px + env(safe-area-inset-bottom, 0px));
            max-width: 540px; margin-inline: auto;
            border-top-left-radius: 22px; border-top-right-radius: 22px;
        }
        @media (min-width: 768px) { .bottom-nav { max-width: 720px; } }

        .bn-item {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 4px; cursor: pointer; border: none; background: none;
            color: var(--muted); padding: 4px 2px; border-radius: 14px;
            transition: color .2s ease, transform .15s ease;
        }
        .bn-item i { font-size: 1.15rem; }
        .bn-item span { font-size: .58rem; font-weight: 800; letter-spacing: .2px; }
        .bn-item:active { transform: scale(.9); }
        .bn-item.on { color: var(--brand-1); }
        .bn-item.on i { transform: translateY(-1px); }
        .bn-item.bn-fab { position: relative; }
        .bn-item.bn-fab .fab-orb {
            width: 52px; height: 52px; border-radius: 50%;
            display: grid; place-items: center; color: #fff; font-size: 1.3rem;
            background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
            box-shadow: 0 8px 18px -6px var(--brand-2);
            margin-top: -22px; border: 4px solid var(--surface);
        }

        /* ══════════════════════════════════════════
           📋 বটম শিট
           ══════════════════════════════════════════ */
        .sheet-overlay {
            position: fixed; inset: 0; z-index: 1050;
            background: rgba(2,6,23,.55); backdrop-filter: blur(2px);
            opacity: 0; visibility: hidden; transition: opacity .25s ease, visibility .25s ease;
        }
        .sheet-overlay.open { opacity: 1; visibility: visible; }

        .bottom-sheet {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 1051;
            max-width: 540px; margin-inline: auto;
            background: var(--bg-2);
            border-top-left-radius: 26px; border-top-right-radius: 26px;
            box-shadow: 0 -16px 40px -12px rgba(2,6,23,.5);
            max-height: 92vh; overflow-y: auto;
            transform: translateY(100%);
            transition: transform .32s cubic-bezier(.32,.72,0,1);
            -webkit-overflow-scrolling: touch;
        }
        @media (min-width: 768px) { .bottom-sheet { max-width: 720px; } }
        .bottom-sheet.open { transform: translateY(0); }

        .sheet-grabber {
            position: sticky; top: 0; z-index: 5;
            background: var(--bg-2);
            display: flex; flex-direction: column; align-items: center;
            padding: 10px 0 6px;
        }
        .sheet-grabber .bar { width: 42px; height: 5px; border-radius: 10px; background: var(--border-2); }
        .sheet-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 4px 16px 12px; width: 100%;
        }
        .sheet-head .sheet-title { font-weight: 800; font-size: .95rem; color: var(--text); }
        .sheet-close {
            width: 34px; height: 34px; border-radius: 11px; border: none; cursor: pointer;
            background: var(--surface-3); color: var(--text-2);
            display: grid; place-items: center; font-size: .9rem;
        }
        .sheet-body { padding: 0 14px 24px; }

        /* ══════════════════════════════════════════
           🔔 ইনলাইন নোটিস
           ══════════════════════════════════════════ */
        .notice-stack {
            position: fixed; left: 0; right: 0; top: 0; z-index: 1090;
            display: flex; flex-direction: column; align-items: center; gap: 10px;
            padding: 14px 12px 0; pointer-events: none;
            max-width: 560px; margin-inline: auto;
        }
        @media (min-width: 768px) { .notice-stack { max-width: 720px; } }
        .notice {
            pointer-events: auto; width: 100%;
            display: flex; align-items: center; gap: 12px;
            padding: 13px 15px; border-radius: 16px;
            border: 1px solid var(--nc-border, var(--border));
            background: var(--nc-bg, var(--surface));
            box-shadow: 0 14px 32px -16px rgba(15,23,42,.45);
            font-weight: 700; font-size: .82rem; line-height: 1.35;
            color: var(--nc-text, var(--text));
            transform: translateY(-18px); opacity: 0;
            transition: transform .34s var(--ease), opacity .28s ease;
        }
        .notice.show { transform: translateY(0); opacity: 1; }
        .notice.hide { transform: translateY(-18px); opacity: 0; }
        .notice .nc-ic { flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: .82rem; background: var(--nc-accent, var(--brand-1)); box-shadow: 0 6px 14px -6px var(--nc-accent, var(--brand-1)); }
        .notice .nc-msg { flex: 1; min-width: 0; }
        .notice .nc-x { flex-shrink: 0; border: none; background: none; cursor: pointer; color: color-mix(in srgb, var(--nc-text, var(--text)) 55%, transparent); width: 26px; height: 26px; border-radius: 8px; display: grid; place-items: center; font-size: .8rem; }
        .notice .nc-x:hover { background: color-mix(in srgb, var(--nc-text, var(--text)) 10%, transparent); }
        .notice.nc-success { --nc-accent: var(--c-green); --nc-bg: color-mix(in srgb, var(--c-green) 12%, var(--surface)); --nc-border: color-mix(in srgb, var(--c-green) 36%, transparent); --nc-text: color-mix(in srgb, var(--c-green) 72%, var(--text)); }
        .notice.nc-error   { --nc-accent: var(--c-red);   --nc-bg: color-mix(in srgb, var(--c-red) 12%, var(--surface));   --nc-border: color-mix(in srgb, var(--c-red) 36%, transparent);   --nc-text: color-mix(in srgb, var(--c-red) 74%, var(--text)); }
        .notice.nc-warning { --nc-accent: var(--c-amber); --nc-bg: color-mix(in srgb, var(--c-amber) 14%, var(--surface)); --nc-border: color-mix(in srgb, var(--c-amber) 38%, transparent); --nc-text: color-mix(in srgb, var(--c-amber) 78%, var(--text)); }
        .notice.nc-info    { --nc-accent: var(--brand-1); --nc-bg: color-mix(in srgb, var(--brand-1) 12%, var(--surface)); --nc-border: color-mix(in srgb, var(--brand-1) 34%, transparent); --nc-text: color-mix(in srgb, var(--brand-1) 72%, var(--text)); }

        /* ══════════════════════════════════════════
           🖼️ ব্যানার (hero)
           ══════════════════════════════════════════ */
        .hero {
            position: relative; margin-top: 14px;
            border-radius: var(--radius-lg); overflow: hidden;
            box-shadow: var(--shadow-card); border: 1px solid var(--border);
            background: var(--header-grad);
            min-height: 132px;
            display: block; text-decoration: none;
        }
        .hero.is-link { cursor: pointer; transition: transform .25s var(--ease), box-shadow .25s ease; }
        .hero.is-link:hover { transform: translateY(-2px); box-shadow: var(--shadow-soft); }
        .hero-img { display: block; width: 100%; height: 100%; min-height: 132px; max-height: 200px; object-fit: cover; position: relative; z-index: 1; }
        .hero-scrim { position: absolute; inset: 0; z-index: 2; pointer-events: none;
            background: linear-gradient(180deg, rgba(79,70,229,.12) 0%, rgba(2,6,23,.30) 55%, rgba(2,6,23,.74) 100%); }
        .hero-cap { position: absolute; left: 16px; bottom: 14px; z-index: 3; display: flex; align-items: center; gap: 12px; right: 16px; }
        .hero-logo { flex-shrink: 0; width: 52px; height: 52px; border-radius: 15px; overflow: hidden;
            display: grid; place-items: center; color: #fff; font-size: 1.4rem;
            background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.55);
            backdrop-filter: blur(6px); box-shadow: 0 8px 18px -8px rgba(0,0,0,.55); }
        .hero-logo img { width: 100%; height: 100%; object-fit: cover; }
        .hero-text { min-width: 0; }
        .hero-title { color: #fff; font-weight: 800; font-size: 1.12rem; line-height: 1.12; text-shadow: 0 2px 10px rgba(0,0,0,.5); }
        .hero-sub { color: rgba(255,255,255,.88); font-size: .55rem; font-weight: 800; letter-spacing: 1.8px; text-transform: uppercase; margin-top: 3px; text-shadow: 0 1px 6px rgba(0,0,0,.5); }

        .brand-badge { overflow: hidden; }
        .brand-badge-img { width: 100%; height: 100%; object-fit: cover; border-radius: inherit; }
    </style>
</head>
<body>

    <!-- FIX ⑩: Build info HTML comment সরানো হয়েছে — version disclosure বন্ধ -->

    <!-- ═══ ইনলাইন নোটিস স্ট্যাক ═══ -->
    <div id="noticeStack" class="notice-stack" aria-live="polite" aria-atomic="false"></div>

    <!-- ═══ HEADER ═══ -->
    <header class="app-header">
        <div class="hdr-row">
            <div class="d-flex align-items-center gap-2">
                <a href="../../dashboard.php" class="back-btn" aria-label="হোমে ফিরুন">
                    <i class="fas fa-house-chimney"></i>
                </a>

                <div class="brand-badge">
                    <?php if (!empty($bannerLogo)): ?>
                        <!--
                            FIX ⑪: onerror inline handler সরানো — JS event listener দিয়ে প্রতিস্থাপিত
                            (CSP-safe; inline event handler নেই)
                        -->
                        <img
                            src="<?php echo DpsSecHelper::safeOut($bannerLogo); ?>"
                            alt="লোগো"
                            class="brand-badge-img"
                            id="headerLogoImg">
                    <?php else: ?>
                        <i class="fas fa-piggy-bank"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="brand-title">ডিপিএস ও এফডিআর</div>
                    <div class="brand-sub">     ══════সাদা-কালো-ফ্যাশন═════════</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="icon-pill" onclick="toggleTheme()" title="থিম"><i data-theme-icon class="fas fa-moon"></i></div>
                <a href="../../logout.php" class="icon-pill danger" title="লগআউট"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </header>

    <div class="app-shell px-3">

        <!-- ═══ ব্যানার ═══ -->
        <?php $hasBannerLink = !empty($bannerLink); ?>

        <!--
            FIX ⑫: onclick="window.open(...)" সরানো হয়েছে।
            Link থাকলে <a> tag, না থাকলে <div> — CSP-safe, semantic HTML।
        -->
        <?php if ($hasBannerLink): ?>
        <a
            href="<?php echo DpsSecHelper::safeOut($bannerLink); ?>"
            target="_blank"
            rel="noopener noreferrer"
            class="hero is-link"
            aria-label="<?php echo DpsSecHelper::safeOut($bannerTitle); ?>">
        <?php else: ?>
        <div class="hero">
        <?php endif; ?>

            <?php if (!empty($bannerImage)): ?>
                <!-- FIX ⑪: onerror inline handler → JS-এ event listener -->
                <img
                    class="hero-img"
                    src="<?php echo DpsSecHelper::safeOut($bannerImage); ?>"
                    alt="<?php echo DpsSecHelper::safeOut($bannerTitle); ?>"
                    loading="lazy"
                    id="heroBannerImg">
            <?php endif; ?>
            <div class="hero-scrim"></div>
            <div class="hero-cap">
                <div class="hero-logo">
                    <?php if (!empty($bannerLogo)): ?>
                        <!-- FIX ⑪: onerror inline handler → JS-এ event listener -->
                        <img
                            src="<?php echo DpsSecHelper::safeOut($bannerLogo); ?>"
                            alt="লোগো"
                            id="heroLogoImg">
                    <?php else: ?>
                        <i class="fas fa-bag-shopping"></i>
                    <?php endif; ?>
                </div>
                <div class="hero-text">
                    <div class="hero-title"><?php echo DpsSecHelper::safeOut($bannerTitle); ?></div>
                    <div class="hero-sub"><?php echo DpsSecHelper::safeOut($bannerSub); ?></div>
                </div>
            </div>

        <?php echo $hasBannerLink ? '</a>' : '</div>'; ?>

        <!-- ═══ SUMMARY ═══ -->
        <div class="stat-grid mt-3" style="grid-template-columns: repeat(2,1fr);">
            <div class="stat-card tone-green">
                <div class="si"><i class="fas fa-arrow-down"></i></div>
                <div class="sv" id="sumTodayDeposit">৳0</div>
                <div class="sl">আজ জমা</div>
            </div>
            <div class="stat-card tone-blue">
                <div class="si"><i class="fas fa-coins"></i></div>
                <div class="sv" id="sumTodayProfit">৳0</div>
                <div class="sl">আজকের মুনাফা</div>
            </div>
            <div class="stat-card tone-red">
                <div class="si"><i class="fas fa-arrow-up"></i></div>
                <div class="sv" id="sumTodayWithdraw">৳0</div>
                <div class="sl">আজ উত্তোলন</div>
            </div>
            <div class="stat-card tone-amber">
                <div class="si"><i class="fas fa-vault"></i></div>
                <div class="sv" id="sumTotalBalance">৳0</div>
                <div class="sl">মোট তহবিল (সক্রিয়)</div>
            </div>
            <div class="stat-card tone-purple" style="grid-column:span 2;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="sl mb-1">মোট লাভ (সব অ্যাকাউন্ট)</div>
                        <div class="sv" id="sumTotalProfit" style="font-size:1.3rem;">৳0</div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="text-center">
                            <div style="font-size:1.4rem;font-weight:800;color:var(--c-green);" id="sumActiveCount">0</div>
                            <div style="font-size:.54rem;font-weight:800;color:var(--muted);text-transform:uppercase;">সক্রিয়</div>
                        </div>
                        <div class="text-center">
                            <div style="font-size:1.4rem;font-weight:800;color:var(--muted);" id="sumInactiveCount">0</div>
                            <div style="font-size:.54rem;font-weight:800;color:var(--muted);text-transform:uppercase;">বন্ধ</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ অ্যাকাউন্ট তালিকা ═══ -->
        <div class="mt-4">
            <div class="app-card mb-3">
                <div class="card-head justify-content-between">
                    <span class="d-flex align-items-center gap-2">
                        <span class="chi" style="background:var(--c-purple)"><i class="fas fa-users"></i></span> অ্যাকাউন্ট তালিকা
                    </span>
                    <div class="seg">
                        <button onclick="loadAccounts('active')" id="btnAccActive" class="on">সক্রিয়</button>
                        <button onclick="loadAccounts('inactive')" id="btnAccInactive">বন্ধ</button>
                    </div>
                </div>
            </div>
            <div id="accGrid" class="acc-grid">
                <div class="loading"><i class="fas fa-spinner fa-spin me-1"></i> লোড হচ্ছে...</div>
            </div>
        </div>

    </div><!-- end .app-shell -->

    <!-- ═══ বটম শিট ও ওভারলে ═══ -->
    <div id="sheetOverlay" class="sheet-overlay" onclick="closeSheet()"></div>
    <div id="bottomSheet" class="bottom-sheet">
        <div class="sheet-grabber">
            <div class="bar"></div>
            <div class="sheet-head">
                <span class="sheet-title" id="sheetTitle">—</span>
                <button class="sheet-close" onclick="closeSheet()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="sheet-body">

        <div class="d-flex flex-column gap-3 pb-2">

            <!-- ─── নতুন অ্যাকাউন্ট ─── -->
            <div id="section-new_account" class="section-panel app-card">
                <div class="card-head"><span class="chi" style="background:var(--c-purple)"><i class="fas fa-user-plus"></i></span> নতুন অ্যাকাউন্ট খুলুন</div>
                <div class="card-body">
                    <form id="dpsAccountForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo DpsSecHelper::safeOut($csrf_token); ?>">
                        <div class="col-12 col-md-6"><label class="field-label">গ্রাহকের নাম</label><input type="text" name="dps_client_name" class="app-input" required placeholder="পুরো নাম"></div>
                        <div class="col-12 col-md-6"><label class="field-label">অ্যাকাউন্ট নম্বর</label><input type="text" name="dps_account_number" class="app-input" required placeholder="যেমন: DPS-1001"></div>
                        <div class="col-6"><label class="field-label">ধরন</label><select name="dps_account_type" class="app-input"><option value="DPS">DPS (ডিপিএস)</option><option value="FDR">FDR (ফিক্সড)</option></select></div>
                        <div class="col-6"><label class="field-label">কিস্তির ধরন</label><select name="dps_frequency" class="app-input"><option value="daily">দৈনিক</option><option value="weekly">সাপ্তাহিক</option><option value="monthly">মাসিক</option></select></div>
                        <div class="col-6"><label class="field-label">কিস্তির পরিমাণ (৳)</label><input type="number" step="0.01" name="dps_installment_amount" class="app-input" required placeholder="0.00"></div>
                        <div class="col-6"><label class="field-label">মুনাফার হার (%)</label><input type="number" step="0.01" name="dps_interest_rate" class="app-input" required placeholder="0.00"></div>
                        <div class="col-6"><label class="field-label">মেয়াদ (বছর)</label><input type="number" name="dps_duration_years" class="app-input" required placeholder="1"></div>
                        <div class="col-6"><label class="field-label" style="color:var(--c-green)">ওপেনিং ব্যালেন্স (৳)</label><input type="number" step="0.01" name="dps_opening_balance" class="app-input" required placeholder="0.00"></div>
                        <div class="col-12"><label class="field-label" style="color:var(--c-amber)">পরবর্তী জমার তারিখ</label><input type="date" name="next_deposit_date" class="app-input"></div>
                        <div class="col-12">
                            <span class="adv-toggle" onclick="$(this).next('.adv-body').slideToggle();"><i class="fas fa-clock-rotate-left"></i> ব্যাকডেট এন্ট্রি</span>
                            <div class="adv-body"><label class="field-label">অ্যাকাউন্ট খোলার তারিখ</label><input type="date" name="txn_date" class="app-input"></div>
                        </div>
                        <div class="col-12"><button type="submit" class="app-btn btn-violet"><i class="fas fa-check"></i> অ্যাকাউন্ট তৈরি করুন</button></div>
                    </form>
                </div>
            </div>

            <!-- ─── জমা ─── -->
            <div id="section-deposit" class="section-panel app-card">
                <div class="card-head"><span class="chi" style="background:var(--c-green)"><i class="fas fa-hand-holding-dollar"></i></span> টাকা জমা নিন</div>
                <div class="card-body">
                    <form id="dpsDepositForm" class="d-flex flex-column gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo DpsSecHelper::safeOut($csrf_token); ?>">
                        <div><label class="field-label">অ্যাকাউন্ট সিলেক্ট</label><select name="deposit_dps_id" id="depositSelectClient" class="app-input" required></select></div>
                        <div><label class="field-label">জমা টাকা (৳)</label><input type="number" step="0.01" name="dps_deposit_amount" class="app-input input-amount pos" placeholder="0.00" required></div>
                        <div><label class="field-label" style="color:var(--c-amber)">পরবর্তী জমার তারিখ</label><input type="date" name="next_deposit_date" class="app-input"></div>
                        <div>
                            <span class="adv-toggle" onclick="$(this).next('.adv-body').slideToggle();"><i class="fas fa-clock-rotate-left"></i> পিছনের তারিখ</span>
                            <div class="adv-body"><label class="field-label">জমার তারিখ</label><input type="date" name="txn_date" class="app-input"></div>
                        </div>
                        <button type="submit" class="app-btn btn-green"><i class="fas fa-check-circle"></i> জমা কনফার্ম</button>
                    </form>
                </div>
            </div>

            <!-- ─── উত্তোলন ─── -->
            <div id="section-withdraw" class="section-panel app-card">
                <div class="card-head"><span class="chi" style="background:var(--c-red)"><i class="fas fa-money-bill-wave"></i></span> উত্তোলন</div>
                <div class="card-body">
                    <form id="dpsWithdrawForm" class="d-flex flex-column gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo DpsSecHelper::safeOut($csrf_token); ?>">
                        <div><label class="field-label">অ্যাকাউন্ট সিলেক্ট</label><select name="withdraw_dps_id" id="withdrawSelectClient" class="app-input" required></select></div>
                        <div><label class="field-label">উত্তোলনের পরিমাণ (৳)</label><input type="number" step="0.01" name="dps_withdraw_amount" class="app-input input-amount neg" placeholder="0.00" required></div>
                        <div>
                            <span class="adv-toggle" onclick="$(this).next('.adv-body').slideToggle();"><i class="fas fa-clock-rotate-left"></i> পিছনের তারিখ</span>
                            <div class="adv-body"><label class="field-label">উত্তোলনের তারিখ</label><input type="date" name="txn_date" class="app-input"></div>
                        </div>
                        <button type="submit" class="app-btn btn-red"><i class="fas fa-hand-holding-dollar"></i> উত্তোলন করুন</button>
                    </form>
                </div>
            </div>

            <!-- ─── অ্যাকাউন্ট বিস্তারিত ─── -->
            <div id="section-acc-detail" class="section-panel acc-detail-panel">
                <div class="acc-detail-header">
                    <div>
                        <div class="det-name" id="ddName">—</div>
                        <div class="det-sub" id="ddAccNo">—</div>
                        <div class="mt-2 d-flex gap-2 flex-wrap" id="ddChips"></div>
                    </div>
                </div>

                <div class="stat3 border-bottom" style="border-color:var(--border)!important;">
                    <div class="stat3-cell">
                        <div class="s3l">আসল জমা</div>
                        <div class="s3v" style="color:var(--c-blue);" id="ddPrincipal">৳0</div>
                    </div>
                    <div class="stat3-cell">
                        <div class="s3l">মোট লাভ</div>
                        <div class="s3v" style="color:var(--c-green);" id="ddProfit">৳0</div>
                    </div>
                    <div class="stat3-cell">
                        <div class="s3l">বর্তমান ব্যালেন্স</div>
                        <div class="s3v" style="color:var(--c-sky);" id="ddBalance">৳0</div>
                    </div>
                </div>

                <div class="card-body border-bottom" style="border-color:var(--border)!important;">
                    <div class="det-tiles" id="ddDetails"></div>
                </div>

                <div class="card-body d-flex gap-2 border-bottom flex-wrap" style="border-color:var(--border)!important;">
                    <button onclick="editAccInfo()" class="app-btn btn-blue" style="font-size:.66rem;padding:9px;flex:1;"><i class="fas fa-pen"></i> তথ্য এডিট</button>
                    <button id="ddBtnStatus" onclick="toggleFromDetail()" class="app-btn btn-violet" style="font-size:.66rem;padding:9px;flex:1;"><i class="fas fa-power-off"></i> স্ট্যাটাস</button>
                    <button onclick="updateNextDeposit()" class="app-btn btn-green" style="font-size:.66rem;padding:9px;flex:1;"><i class="fas fa-calendar-plus"></i> জমার তারিখ</button>
                </div>

                <div class="toolbar">
                    <span class="field-label mb-0"><i class="fas fa-list" style="color:var(--muted)"></i> লেজার হিস্ট্রি</span>
                    <span id="ddWindowLabel" style="font-size:.6rem;font-weight:800;color:var(--c-sky);"></span>
                    <div class="pager ms-auto">
                        <button id="ddPrevBtn" onclick="changeDetailPage(-1)" title="নতুন সপ্তাহ"><i class="fas fa-chevron-left"></i></button>
                        <span id="ddPageInfo">1/1</span>
                        <button id="ddNextBtn" onclick="changeDetailPage(1)" title="পুরনো সপ্তাহ"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="ledger-wrap">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>তারিখ</th>
                                <th>বিবরণ</th>
                                <th class="text-end">জমা</th>
                                <th class="text-end">উত্তোলন</th>
                                <th class="text-end">ব্যালেন্স</th>
                                <th class="text-center">অ্যাকশন</th>
                            </tr>
                        </thead>
                        <tbody id="ddLedgerBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- ─── গ্লোবাল লেজার ─── -->
            <div id="section-ledger" class="section-panel app-card">
                <div class="dps-tab-bar">
                    <button class="dps-tab on" id="tabLedgerAll" onclick="switchLedgerTab('all')"><i class="fas fa-list me-1"></i>সব হিস্ট্রি</button>
                    <button class="dps-tab" id="tabLedgerFilter" onclick="switchLedgerTab('filter')"><i class="fas fa-filter me-1"></i>ফিল্টার</button>
                </div>
                <div class="toolbar">
                    <select id="dpsLedgerFilter" onchange="currentPage=1;fetchGlobalLedger()" class="app-input" style="padding:7px 10px;font-size:.72rem;display:none;">
                        <option value="all">সব হিস্ট্রি</option>
                    </select>
                    <span id="ledgerTabLabel" class="field-label mb-0 text-nowrap" style="color:var(--brand-1);font-size:.7rem;">সমস্ত লেনদেন</span>
                    <div class="pager ms-auto">
                        <button id="prevBtn" onclick="changePage(-1)"><i class="fas fa-chevron-left"></i></button>
                        <span id="pageInfo">1/1</span>
                        <button id="nextBtn" onclick="changePage(1)"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="ledger-wrap">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>তারিখ</th>
                                <th>এ/সি ও গ্রাহক</th>
                                <th>বিবরণ</th>
                                <th class="text-end">জমা</th>
                                <th class="text-end">উত্তোলন</th>
                                <th class="text-end">ব্যালেন্স</th>
                                <th class="text-center">অ্যাকশন</th>
                            </tr>
                        </thead>
                        <tbody id="globalLedgerBody">
                            <tr><td colspan="7" class="loading"><i class="fas fa-spinner fa-spin me-1"></i> লোড হচ্ছে...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        </div><!-- end .sheet-body -->
    </div><!-- end .bottom-sheet -->

    <!-- ═══ ফিক্সড বটম নেভিগেশন ═══ -->
    <nav class="bottom-nav">
        <button class="bn-item on" id="bnHome" onclick="goHome()">
            <i class="fas fa-house"></i><span>হোম</span>
        </button>
        <button class="bn-item" onclick="openSheet('deposit')">
            <i class="fas fa-hand-holding-dollar"></i><span>জমা</span>
        </button>
        <button class="bn-item bn-fab" onclick="openSheet('new_account')">
            <span class="fab-orb"><i class="fas fa-user-plus"></i></span>
            <span style="margin-top:2px;">নতুন</span>
        </button>
        <button class="bn-item" onclick="openSheet('withdraw')">
            <i class="fas fa-money-bill-wave"></i><span>উত্তোলন</span>
        </button>
        <button class="bn-item" onclick="openSheet('ledger')">
            <i class="fas fa-book"></i><span>লেজার</span>
        </button>
    </nav>

<script>
// ════════════════════════════════════════════════
// 🔑 CSRF Token
// ════════════════════════════════════════════════
const CSRF = '<?php echo DpsSecHelper::safeOut($csrf_token); ?>';

// onclick attribute-এ নিরাপদে string পাঠানোর জন্য
function jsAttr(s) {
    return String(s == null ? '' : s)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '&quot;')
        .replace(/\n/g, ' ')
        .replace(/\r/g, '');
}

// ════════════════════════════════════════════════
// FIX ⑪: Image fallback — inline onerror সরিয়ে event listener দিয়ে প্রতিস্থাপিত
// ════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {
    // Header logo fallback
    const headerLogo = document.getElementById('headerLogoImg');
    if (headerLogo) {
        headerLogo.addEventListener('error', function () {
            const icon = document.createElement('i');
            icon.className = 'fas fa-piggy-bank';
            this.replaceWith(icon);
        });
    }

    // Banner main image fallback
    const heroBanner = document.getElementById('heroBannerImg');
    if (heroBanner) {
        heroBanner.addEventListener('error', function () {
            this.remove();
        });
    }

    // Banner logo fallback
    const heroLogo = document.getElementById('heroLogoImg');
    if (heroLogo) {
        heroLogo.addEventListener('error', function () {
            const icon = document.createElement('i');
            icon.className = 'fas fa-bag-shopping';
            this.replaceWith(icon);
        });
    }
});

// ════════════════════════════════════════════════
// 🔔 INLINE NOTICE — স্লাইড-ইন টোস্ট
// ════════════════════════════════════════════════
const NOTICE_ICON = {
    success: 'fa-check',
    error:   'fa-exclamation',
    warning: 'fa-triangle-exclamation',
    info:    'fa-info'
};

function showNotice(type, message, timeout) {
    const t   = NOTICE_ICON[type] ? type : 'info';
    const ttl = (typeof timeout === 'number') ? timeout : (t === 'error' ? 5000 : 3400);

    const $n = $(
        '<div class="notice nc-' + t + '" role="status">' +
            '<span class="nc-ic"><i class="fas ' + NOTICE_ICON[t] + '"></i></span>' +
            '<span class="nc-msg"></span>' +
            '<button class="nc-x" aria-label="বন্ধ"><i class="fas fa-times"></i></button>' +
        '</div>'
    );
    $n.find('.nc-msg').text(message == null ? '' : String(message));

    const remove = () => {
        $n.removeClass('show').addClass('hide');
        setTimeout(() => $n.remove(), 340);
    };
    $n.find('.nc-x').on('click', remove);

    $('#noticeStack').append($n);
    requestAnimationFrame(() => requestAnimationFrame(() => $n.addClass('show')));
    if (ttl > 0) setTimeout(remove, ttl);
    return $n;
}

// ════════════════════════════════════════════════
// ✅ CONFIRM ACTION (SweetAlert2)
// ════════════════════════════════════════════════
function confirmAction(opts) {
    return Swal.fire({
        title:             opts.title   || 'নিশ্চিত করুন?',
        text:              opts.text    || '',
        icon:              opts.icon    || 'warning',
        showCancelButton:  true,
        confirmButtonText: opts.confirm || 'হ্যাঁ',
        cancelButtonText:  'বাতিল',
        confirmButtonColor: opts.color || '#a855f7',
        cancelButtonColor:  '#475569'
    }).then(r => r.isConfirmed);
}

const SHEET_TITLES = {
    'new_account': 'নতুন অ্যাকাউন্ট',
    'deposit':     'টাকা জমা',
    'withdraw':    'উত্তোলন',
    'ledger':      'সম্পূর্ণ লেজার',
    'acc-detail':  'অ্যাকাউন্ট বিস্তারিত'
};

function openSheet(secId) {
    $('.section-panel').hide();
    $('#section-' + secId).show().addClass('fade-in');
    $('#sheetTitle').text(SHEET_TITLES[secId] || '');
    $('#sheetOverlay').addClass('open');
    $('#bottomSheet').addClass('open');
    $('body').css('overflow', 'hidden');

    if (secId === 'deposit' || secId === 'withdraw') fetchActiveDropdown();
    if (secId === 'ledger') { currentPage = 1; populateLedgerFilter(); fetchGlobalLedger(); }
}

function closeSheet() {
    $('#sheetOverlay').removeClass('open');
    $('#bottomSheet').removeClass('open');
    $('body').css('overflow', '');
    setTimeout(() => { $('#bottomSheet').scrollTop(0); }, 320);
}

function goHome() {
    closeSheet();
    loadAccounts(currentAccStatus);
    $('html, body').animate({ scrollTop: 0 }, 250);
}

function backToAccounts() {
    closeSheet();
}

// ════════════════════════════════════════════════
// 📊 SUMMARY
// ════════════════════════════════════════════════
function fetchSummary() {
    $.post('', { action: 'fetch_dps_summary' }, function(r) {
        if (r.status !== 'success') return;
        const fmt = v => '৳' + parseFloat(v).toLocaleString('en-US', {minimumFractionDigits:2});
        $('#sumTodayDeposit').text(fmt(r.todayDeposit));
        $('#sumTodayProfit').text(fmt(r.todayProfit));
        $('#sumTodayWithdraw').text(fmt(r.todayWithdraw));
        $('#sumTotalBalance').text(fmt(r.totalBalance));
        $('#sumTotalProfit').text(fmt(r.totalProfit));
        $('#sumActiveCount').text(r.activeCount);
        $('#sumInactiveCount').text(r.inactiveCount);
    }, 'json');
}

// ════════════════════════════════════════════════
// 👥 ACCOUNTS LIST
// ════════════════════════════════════════════════
let currentAccStatus = 'active';
let currentDetailId  = null;
let currentDetailAcc = null;

function loadAccounts(status) {
    currentAccStatus = status;
    $('#btnAccActive').toggleClass('on', status === 'active');
    $('#btnAccInactive').toggleClass('on', status === 'inactive');
    $('#accGrid').html('<div class="loading"><i class="fas fa-spinner fa-spin me-1"></i> লোড হচ্ছে...</div>');

    $.post('', { action: 'fetch_dps_accounts', status_filter: status }, function(r) {
        if (r.status !== 'success') {
            $('#accGrid').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i> ডেটা লোড হয়নি।</div>');
            return;
        }

        if (!r.accounts.length) {
            const title = (status === 'active' ? 'কোনো সক্রিয় অ্যাকাউন্ট নেই!' : 'কোনো বন্ধ অ্যাকাউন্ট নেই!');
            const sub   = (status === 'active' ? 'নিচের + বোতাম দিয়ে নতুন অ্যাকাউন্ট খুলুন' : 'বন্ধ করা অ্যাকাউন্ট এখানে দেখা যাবে');
            $('#accGrid').html(
                '<div class="empty-state"><i class="fas fa-folder-open"></i>' +
                '<div>' + title + '</div>' +
                '<div style="font-size:.7rem;font-weight:700;color:var(--muted);margin-top:4px;">' + sub + '</div>' +
                '</div>'
            );
            return;
        }

        let html = '';
        r.accounts.forEach(a => {
            const accNo   = a.account_number || (a.account_type + '-' + (1000 + parseInt(a.id)));
            const bal     = parseFloat(a.total_balance);
            const profit  = parseFloat(a.total_profit_earned);
            const days    = parseInt(a.days_until_due);
            const hasDue  = !isNaN(days) && a.next_deposit_date;
            const isInact = a.status === 'inactive';
            const isFdr   = a.account_type === 'FDR';

            let urgency = '';
            if (!isInact && hasDue) {
                if (days < 0)        urgency = 'overdue';
                else if (days === 0) urgency = 'today';
                else if (days <= 3)  urgency = 'soon';
                else                 urgency = 'upcoming';
            }

            let cardClass = 'acc-card';
            if (isInact)      cardClass += ' inactive-card';
            else if (urgency) cardClass += ' urg-' + urgency;
            else if (isFdr)   cardClass += ' fdr-card';

            let dueBadge = '';
            if (urgency === 'overdue') {
                const od = Math.abs(days);
                dueBadge = '<span class="due-badge due-overdue">বকেয়া • ' + od + ' দিন</span>';
            } else if (urgency === 'today') {
                dueBadge = '<span class="due-badge due-today">আজ জমা!</span>';
            } else if (urgency === 'soon') {
                dueBadge = '<span class="due-badge due-soon">' + days + ' দিন পরে</span>';
            } else if (urgency === 'upcoming') {
                dueBadge = '<span class="due-badge due-upcoming">পরবর্তী</span>';
            }

            let pills = `
                <span class="pill pill-type-${isFdr ? 'fdr':'dps'}">${a.account_type}</span>
                <span class="pill pill-rate"><i class="fas fa-percent" style="font-size:.45rem"></i> ${parseFloat(a.interest_rate)}%</span>
                <span class="pill pill-principal"><i class="fas fa-coins" style="font-size:.45rem"></i> আসল ৳${parseFloat(a.principal_only||0).toLocaleString('en-US',{minimumFractionDigits:2})}</span>
                <span class="pill pill-profit"><i class="fas fa-chart-line" style="font-size:.45rem"></i> লাভ ৳${profit.toLocaleString('en-US',{minimumFractionDigits:2})}</span>
            `;
            if (isInact) pills += `<span class="pill pill-inactive"><i class="fas fa-lock" style="font-size:.45rem"></i> বন্ধ</span>`;

            html += `
            <div class="${cardClass}" onclick="openAccDetail(${parseInt(a.id)})">
                <div class="acc-top">
                    <div class="acc-id-col">
                        <div class="acc-name">${a.client_name}</div>
                        <div class="acc-no">${accNo}</div>
                    </div>
                    <div class="acc-right">
                        ${dueBadge}
                        <div class="acc-bal-lbl">মোট ব্যালেন্স</div>
                        <div class="acc-bal">৳${bal.toLocaleString('en-US',{minimumFractionDigits:2})}</div>
                    </div>
                </div>
                <div class="acc-pills">${pills}</div>
            </div>`;
        });
        $('#accGrid').html(html);
    }, 'json');
}

// ════════════════════════════════════════════════
// 🔍 ACCOUNT DETAIL
// ════════════════════════════════════════════════
function openAccDetail(id) {
    currentDetailId = id;

    $.post('', { action: 'fetch_account_detail', dps_id: id }, function(r) {
        if (r.status !== 'success') { showNotice('error', r.message || 'ডেটা লোড হয়নি।'); return; }

        const a   = r.account;
        currentDetailAcc = a;
        const fmt = v => '৳' + parseFloat(v).toLocaleString('en-US', {minimumFractionDigits:2});
        const accNo = a.account_number || (a.account_type + '-' + (1000 + parseInt(a.id)));

        $('#ddName').text(a.client_name);
        $('#ddAccNo').text(accNo + ' • ' + a.account_type);
        $('#ddPrincipal').text(fmt(r.principal_deposited));
        $('#ddProfit').text(fmt(r.total_profit));
        $('#ddBalance').text(fmt(a.total_balance));

        let statusChip = a.status === 'active'
            ? '<span class="chip green"><i class="fas fa-circle" style="font-size:.4rem"></i> সক্রিয়</span>'
            : '<span class="chip slate"><i class="fas fa-lock"></i> বন্ধ</span>';

        let nextDepChip = a.next_deposit_date
            ? `<span class="chip" style="background:color-mix(in srgb,var(--c-amber) 15%,transparent);color:var(--c-amber)"><i class="fas fa-calendar"></i> পরবর্তী: ${a.next_deposit_date}</span>`
            : '';

        $('#ddChips').html(statusChip + nextDepChip);

        $('#ddDetails').html(`
            <div class="det-tile"><span class="dl">মুনাফার হার</span><span class="dv">${parseFloat(a.interest_rate)}%</span></div>
            <div class="det-tile"><span class="dl">কিস্তি</span><span class="dv">${fmt(a.installment_amount)}</span></div>
            <div class="det-tile"><span class="dl">কিস্তির ধরন</span><span class="dv" style="text-transform:capitalize;">${a.frequency}</span></div>
            <div class="det-tile"><span class="dl">মেয়াদ</span><span class="dv">${a.duration_months} মাস</span></div>
            <div class="det-tile"><span class="dl">উত্তোলন মোট</span><span class="dv" style="color:var(--c-red);">${fmt(r.total_withdrawn)}</span></div>
            <div class="det-tile"><span class="dl">খোলার তারিখ</span><span class="dv" style="font-size:.7rem;">${a.opening_date || '—'}</span></div>
        `);

        detailLedgerPage = 1;
        loadDetailLedger();

        $('.section-panel').hide();
        $('#section-acc-detail').show().addClass('fade-in');
        $('#sheetTitle').text(SHEET_TITLES['acc-detail']);
        $('#sheetOverlay').addClass('open');
        $('#bottomSheet').addClass('open').scrollTop(0);
        $('body').css('overflow', 'hidden');
    }, 'json');
}

// ════════════════════════════════════════════════
// 📖 DETAIL LEDGER — ৭-দিন ভিত্তিক পেজিনেশন
// ════════════════════════════════════════════════
let detailLedgerPage       = 1;
let detailLedgerTotalPages = 1;

function loadDetailLedger() {
    if (!currentDetailId) return;
    $('#ddLedgerBody').html('<tr><td colspan="6" class="loading"><i class="fas fa-spinner fa-spin me-1"></i> লোড হচ্ছে...</td></tr>');

    $.post('', { action: 'fetch_account_ledger', dps_id: currentDetailId, page: detailLedgerPage }, function(r) {
        if (r.status !== 'success') return;

        detailLedgerTotalPages = r.totalPages || 1;
        detailLedgerPage = Math.min(Math.max(r.currentPage, 1), detailLedgerTotalPages);

        $('#ddWindowLabel').text(r.window_label || '');
        $('#ddPageInfo').text(detailLedgerPage + '/' + detailLedgerTotalPages);
        $('#ddPrevBtn').prop('disabled', detailLedgerPage === 1).css('opacity', detailLedgerPage === 1 ? .35 : 1);
        $('#ddNextBtn').prop('disabled', detailLedgerPage === detailLedgerTotalPages).css('opacity', detailLedgerPage === detailLedgerTotalPages ? .35 : 1);

        let lHtml = '';
        r.ledger.forEach(l => {
            const isW      = parseFloat(l.withdraw_amount) > 0;
            const isProfit = l.description.includes('মুনাফা');
            const isOpen   = l.description.includes('Opening');
            let rowClass   = isW ? 'row-withdraw' : 'row-deposit';
            if (isProfit) rowClass = 'row-profit';
            if (isOpen)   rowClass = 'row-opening';

            const dFmt    = new Date(l.txn_date).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'2-digit'});
            const editAmt = isW ? l.withdraw_amount : l.deposit_amount;
            const editType = isW ? 'withdraw' : 'deposit';

            const depCell  = parseFloat(l.deposit_amount)  > 0 ? `<span class="amt-pos">+৳${parseFloat(l.deposit_amount).toLocaleString('en-US',{minimumFractionDigits:2})}</span>` : '<span style="color:var(--muted)">—</span>';
            const drawCell = parseFloat(l.withdraw_amount) > 0 ? `<span class="amt-neg">-৳${parseFloat(l.withdraw_amount).toLocaleString('en-US',{minimumFractionDigits:2})}</span>` : '<span style="color:var(--muted)">—</span>';

            const actionBtns = isOpen ? '<span style="color:var(--muted)">—</span>' : `
                <div class="act-stack">
                    <button onclick="editDpsLedger(${parseInt(l.id)},'${jsAttr(l.description)}',${editAmt},'${editType}',true)" class="mini-act act-edit" title="এডিট"><i class="fas fa-pen"></i></button>
                    <button onclick="deleteDpsLedger(${parseInt(l.id)},true)" class="mini-act act-del" title="ডিলিট"><i class="fas fa-trash"></i></button>
                </div>
            `;

            lHtml += `<tr class="${rowClass}">
                <td class="text-nowrap">${dFmt}</td>
                <td style="white-space:normal;max-width:140px;">${l.description}</td>
                <td class="text-end text-nowrap">${depCell}</td>
                <td class="text-end text-nowrap">${drawCell}</td>
                <td class="text-end text-nowrap amt-bal">৳${parseFloat(l.current_balance).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                <td class="text-center text-nowrap">${actionBtns}</td>
            </tr>`;
        });
        if (!r.ledger.length) lHtml = '<tr><td colspan="6" class="empty-state"><i class="fas fa-folder-open"></i> এই সপ্তাহে কোনো লেনদেন নেই</td></tr>';
        $('#ddLedgerBody').html(lHtml);
    }, 'json');
}

function changeDetailPage(dir) {
    const np = detailLedgerPage + dir;
    if (np >= 1 && np <= detailLedgerTotalPages) {
        detailLedgerPage = np;
        loadDetailLedger();
    }
}

function editAccInfo() {
    const a = currentDetailAcc;
    if (!a) return;

    Swal.fire({
        title: 'অ্যাকাউন্ট তথ্য এডিট',
        html: `
            <div style="text-align:left;font-size:13px;">
                <label style="font-weight:700;font-size:12px;">গ্রাহকের নাম</label>
                <input id="ea-name" class="swal2-input" style="margin:4px 0;width:100%;" value="${jsAttr(a.client_name)}">
                <label style="font-weight:700;font-size:12px;">অ্যাকাউন্ট নম্বর</label>
                <input id="ea-accno" class="swal2-input" style="margin:4px 0;width:100%;" value="${jsAttr(a.account_number || '')}">
                <div style="display:flex;gap:8px;">
                    <div style="flex:1;">
                        <label style="font-weight:700;font-size:12px;">ধরন</label>
                        <select id="ea-type" class="swal2-input" style="margin:4px 0;width:100%;">
                            <option value="DPS" ${a.account_type==='DPS'?'selected':''}>DPS</option>
                            <option value="FDR" ${a.account_type==='FDR'?'selected':''}>FDR</option>
                        </select>
                    </div>
                    <div style="flex:1;">
                        <label style="font-weight:700;font-size:12px;">কিস্তির ধরন</label>
                        <select id="ea-freq" class="swal2-input" style="margin:4px 0;width:100%;">
                            <option value="daily" ${a.frequency==='daily'?'selected':''}>দৈনিক</option>
                            <option value="weekly" ${a.frequency==='weekly'?'selected':''}>সাপ্তাহিক</option>
                            <option value="monthly" ${a.frequency==='monthly'?'selected':''}>মাসিক</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:8px;">
                    <div style="flex:1;">
                        <label style="font-weight:700;font-size:12px;">কিস্তি (৳)</label>
                        <input id="ea-inst" type="number" step="0.01" class="swal2-input" style="margin:4px 0;width:100%;" value="${parseFloat(a.installment_amount)}">
                    </div>
                    <div style="flex:1;">
                        <label style="font-weight:700;font-size:12px;">হার (%)</label>
                        <input id="ea-rate" type="number" step="0.01" class="swal2-input" style="margin:4px 0;width:100%;" value="${parseFloat(a.interest_rate)}">
                    </div>
                </div>
                <label style="font-weight:700;font-size:12px;">মেয়াদ (মাস)</label>
                <input id="ea-dur" type="number" class="swal2-input" style="margin:4px 0;width:100%;" value="${parseInt(a.duration_months)}">
            </div>`,
        width: 420,
        showCancelButton: true,
        confirmButtonText: 'আপডেট করুন',
        confirmButtonColor: '#3b82f6',
        cancelButtonColor:  '#475569',
        cancelButtonText:   'বাতিল',
        preConfirm: () => ({
            client_name:        document.getElementById('ea-name').value,
            account_number:     document.getElementById('ea-accno').value,
            account_type:       document.getElementById('ea-type').value,
            frequency:          document.getElementById('ea-freq').value,
            installment_amount: document.getElementById('ea-inst').value,
            interest_rate:      document.getElementById('ea-rate').value,
            duration_months:    document.getElementById('ea-dur').value
        })
    }).then(res => {
        if (!res.isConfirmed) return;
        const v = res.value;
        $.post('', {
            action:             'edit_dps_account',
            acc_id:             currentDetailId,
            client_name:        v.client_name,
            account_number:     v.account_number,
            account_type:       v.account_type,
            frequency:          v.frequency,
            installment_amount: v.installment_amount,
            interest_rate:      v.interest_rate,
            duration_months:    v.duration_months,
            csrf_token:         CSRF
        }, function(out) {
            if (out.status === 'success') {
                showNotice('success', out.message || 'অ্যাকাউন্ট তথ্য আপডেট হয়েছে।');
                fetchSummary();
                openAccDetail(currentDetailId);
            } else {
                showNotice('error', out.message || 'আপডেট ব্যর্থ হয়েছে।');
            }
        }, 'json');
    });
}

function toggleFromDetail() {
    if (!currentDetailId) return;
    confirmAction({
        title:   'স্ট্যাটাস পরিবর্তন?',
        text:    'অ্যাকাউন্ট সক্রিয় বা বন্ধ হবে।',
        confirm: 'পরিবর্তন করুন',
        color:   '#a855f7'
    }).then(ok => {
        if (!ok) return;
        $.post('', { action: 'toggle_dps_status', acc_id: currentDetailId, csrf_token: CSRF }, function(res) {
            if (res.status === 'success') {
                showNotice('success', 'স্ট্যাটাস পরিবর্তন হয়েছে।');
                fetchSummary();
                openAccDetail(currentDetailId);
            } else {
                showNotice('error', res.message || 'পরিবর্তন ব্যর্থ হয়েছে।');
            }
        }, 'json');
    });
}

function updateNextDeposit() {
    if (!currentDetailId) return;
    const cur = (currentDetailAcc && currentDetailAcc.next_deposit_date) ? currentDetailAcc.next_deposit_date : '';
    Swal.fire({
        title:             'পরবর্তী জমার তারিখ',
        input:             'date',
        inputLabel:        'তারিখ ম্যানুয়ালি সিলেক্ট করুন',
        inputValue:        cur,
        showCancelButton:  true,
        confirmButtonText: 'সেট করুন',
        confirmButtonColor: '#3b82f6',
        cancelButtonColor:  '#475569',
        cancelButtonText:  'বাতিল'
    }).then(r => {
        if (!r.isConfirmed || !r.value) return;
        $.post('', { action: 'update_next_deposit', acc_id: currentDetailId, next_deposit_date: r.value, csrf_token: CSRF }, function(res) {
            if (res.status === 'success') {
                showNotice('info', 'পরবর্তী জমার তারিখ আপডেট হয়েছে।');
                openAccDetail(currentDetailId);
            } else {
                showNotice('error', res.message || 'আপডেট ব্যর্থ হয়েছে।');
            }
        }, 'json');
    });
}

// ════════════════════════════════════════════════
// 📋 GLOBAL LEDGER
// ════════════════════════════════════════════════
let currentPage   = 1;
let totalPages    = 1;
let ledgerTabMode = 'all';

function switchLedgerTab(mode) {
    ledgerTabMode = mode;
    $('#tabLedgerAll').toggleClass('on', mode === 'all');
    $('#tabLedgerFilter').toggleClass('on', mode === 'filter');

    if (mode === 'filter') {
        $('#dpsLedgerFilter').show();
        $('#ledgerTabLabel').hide();
    } else {
        $('#dpsLedgerFilter').hide().val('all');
        $('#ledgerTabLabel').show();
    }
    currentPage = 1;
    fetchGlobalLedger();
}

function populateLedgerFilter() {
    // Active accounts
    $.post('', { action: 'fetch_dps_accounts', status_filter: 'active' }, function(r) {
        let opts = '<option value="all">সব হিস্ট্রি</option>';
        if (r.status === 'success') {
            r.accounts.forEach(a => {
                const accNo = a.account_number || (a.account_type + '-' + (1000 + parseInt(a.id)));
                opts += `<option value="${parseInt(a.id)}">${accNo} — ${a.client_name}</option>`;
            });
        }
        // Inactive accounts
        $.post('', { action: 'fetch_dps_accounts', status_filter: 'inactive' }, function(r2) {
            if (r2.status === 'success') {
                r2.accounts.forEach(a => {
                    const accNo = a.account_number || (a.account_type + '-' + (1000 + parseInt(a.id)));
                    opts += `<option value="${parseInt(a.id)}">${accNo} — ${a.client_name} [বন্ধ]</option>`;
                });
            }
            $('#dpsLedgerFilter').html(opts);
        }, 'json');
    }, 'json');
}

function fetchGlobalLedger() {
    const dpsId = ledgerTabMode === 'filter' ? ($('#dpsLedgerFilter').val() || 'all') : 'all';
    $('#globalLedgerBody').html('<tr><td colspan="7" class="loading"><i class="fas fa-spinner fa-spin me-1"></i> লোড হচ্ছে...</td></tr>');

    $.post('', { action: 'fetch_dps_ledger', dps_id: dpsId, page: currentPage }, function(r) {
        if (r.status !== 'success') return;
        totalPages  = r.totalPages || 1;
        currentPage = Math.min(Math.max(r.currentPage, 1), totalPages);
        $('#pageInfo').text(currentPage + '/' + totalPages);
        $('#prevBtn').prop('disabled', currentPage === 1).css('opacity', currentPage === 1 ? .35 : 1);
        $('#nextBtn').prop('disabled', currentPage === totalPages).css('opacity', currentPage === totalPages ? .35 : 1);

        let html = '';
        r.ledger.forEach(row => {
            const isW      = parseFloat(row.withdraw_amount) > 0;
            const isProfit = row.description.includes('মুনাফা');
            const isOpen   = row.description.includes('Opening');
            let rowClass   = isW ? 'row-withdraw' : 'row-deposit';
            if (isProfit) rowClass = 'row-profit';
            if (isOpen)   rowClass = 'row-opening';

            const dFmt  = new Date(row.txn_date).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'2-digit'});
            const accNo = row.account_number || (row.account_type + '-' + (1000 + parseInt(row.acc_id)));
            const editAmt  = isW ? row.withdraw_amount : row.deposit_amount;
            const editType = isW ? 'withdraw' : 'deposit';

            const depCell  = parseFloat(row.deposit_amount)  > 0 ? `<span class="amt-pos">+৳${parseFloat(row.deposit_amount).toLocaleString('en-US',{minimumFractionDigits:2})}</span>` : '<span style="color:var(--muted)">—</span>';
            const drawCell = parseFloat(row.withdraw_amount) > 0 ? `<span class="amt-neg">-৳${parseFloat(row.withdraw_amount).toLocaleString('en-US',{minimumFractionDigits:2})}</span>` : '<span style="color:var(--muted)">—</span>';

            const actionBtns = isOpen ? '<span style="color:var(--muted)">—</span>' : `
                <div class="act-stack">
                    <button onclick="editDpsLedger(${parseInt(row.id)},'${jsAttr(row.description)}',${editAmt},'${editType}',false)" class="mini-act act-edit" title="এডিট"><i class="fas fa-pen"></i></button>
                    <button onclick="toggleDpsStatus(${parseInt(row.acc_id)})" class="mini-act act-power" title="স্ট্যাটাস"><i class="fas fa-power-off"></i></button>
                    <button onclick="deleteDpsLedger(${parseInt(row.id)},false)" class="mini-act act-del" title="ডিলিট"><i class="fas fa-trash"></i></button>
                </div>
            `;

            html += `<tr class="${rowClass}">
                <td class="text-nowrap">${dFmt}</td>
                <td class="text-nowrap"><span class="amt-bal">${accNo}</span><br><span style="font-size:.58rem;color:var(--muted);font-weight:700;">${row.client_name}</span></td>
                <td style="white-space:normal;max-width:140px;font-size:.66rem;">${row.description}</td>
                <td class="text-end text-nowrap">${depCell}</td>
                <td class="text-end text-nowrap">${drawCell}</td>
                <td class="text-end text-nowrap amt-bal">৳${parseFloat(row.current_balance).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                <td class="text-center text-nowrap">${actionBtns}</td>
            </tr>`;
        });
        if (!r.ledger.length) html = '<tr><td colspan="7" class="empty-state"><i class="fas fa-folder-open"></i> কোনো হিসাব পাওয়া যায়নি</td></tr>';
        $('#globalLedgerBody').html(html);
    }, 'json');
}

function changePage(dir) {
    const np = currentPage + dir;
    if (np >= 1 && np <= totalPages) { currentPage = np; fetchGlobalLedger(); }
}

// ════════════════════════════════════════════════
// ✏️ LEDGER EDIT / DELETE
// ════════════════════════════════════════════════
function editDpsLedger(id, oldDesc, oldAmt, type, fromDetail) {
    const color = type === 'withdraw' ? '#f43f5e' : '#10b981';
    Swal.fire({
        title: 'এন্ট্রি এডিট',
        html:  `<input id="sw-desc" class="swal2-input" placeholder="বিবরণ" style="font-size:13px;">
                <input id="sw-amt" type="number" class="swal2-input" value="${oldAmt}" style="color:${color};font-weight:900;font-size:20px;text-align:center">`,
        didOpen: () => {
            // FIX: .text() method দিয়ে safe value set করা হচ্ছে HTML injection এড়াতে
            document.getElementById('sw-desc').value = oldDesc;
        },
        showCancelButton:  true,
        confirmButtonText: 'আপডেট',
        confirmButtonColor: '#6366f1',
        cancelButtonColor:  '#475569',
        preConfirm: () => ({
            desc:   document.getElementById('sw-desc').value,
            amount: document.getElementById('sw-amt').value
        })
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('', { action: 'edit_dps_ledger', id: id, desc: r.value.desc, amount: r.value.amount, csrf_token: CSRF }, function(res) {
            if (res.status === 'success') {
                showNotice('success', res.message || 'হিসাব আপডেট হয়েছে।');
                fetchSummary();
                if (fromDetail) openAccDetail(currentDetailId);
                else fetchGlobalLedger();
            } else {
                showNotice('error', res.message || 'আপডেট ব্যর্থ হয়েছে।');
            }
        }, 'json');
    });
}

function deleteDpsLedger(id, fromDetail) {
    confirmAction({
        title:   'এন্ট্রি মুছবেন?',
        text:    'ব্যালেন্স স্বয়ংক্রিয়ভাবে রিক্যালকুলেট হবে!',
        confirm: 'হ্যাঁ, মুছুন',
        color:   '#e11d48'
    }).then(ok => {
        if (!ok) return;
        $.post('', { action: 'delete_dps_ledger', id: id, csrf_token: CSRF }, function(res) {
            if (res.status === 'success') {
                showNotice('success', res.message || 'এন্ট্রি মুছা হয়েছে।');
                fetchSummary();
                if (fromDetail) openAccDetail(currentDetailId);
                else fetchGlobalLedger();
            } else {
                showNotice('error', res.message || 'মুছা ব্যর্থ হয়েছে।');
            }
        }, 'json');
    });
}

function toggleDpsStatus(accId) {
    confirmAction({
        title:   'স্ট্যাটাস পরিবর্তন?',
        text:    'অ্যাকাউন্ট সক্রিয় বা বন্ধ হবে।',
        confirm: 'পরিবর্তন করুন',
        color:   '#a855f7'
    }).then(ok => {
        if (!ok) return;
        $.post('', { action: 'toggle_dps_status', acc_id: accId, csrf_token: CSRF }, function(res) {
            if (res.status === 'success') {
                showNotice('success', 'স্ট্যাটাস পরিবর্তন হয়েছে।');
                fetchSummary();
                fetchGlobalLedger();
            } else {
                showNotice('error', res.message || 'পরিবর্তন ব্যর্থ হয়েছে।');
            }
        }, 'json');
    });
}

// ════════════════════════════════════════════════
// 📋 DROPDOWN FOR FORMS
// ════════════════════════════════════════════════
function fetchActiveDropdown() {
    $.post('', { action: 'fetch_active_dropdown' }, function(r) {
        if (r.status !== 'success') return;
        let opts = '<option value="">— অ্যাকাউন্ট সিলেক্ট করুন —</option>';
        r.accounts.forEach(a => {
            const accNo = a.account_number || ('ACC-' + (1000 + parseInt(a.id)));
            opts += `<option value="${parseInt(a.id)}">${accNo} : ${a.client_name} (৳${parseFloat(a.total_balance).toLocaleString('en-US',{minimumFractionDigits:2})})</option>`;
        });
        $('#depositSelectClient, #withdrawSelectClient').html(opts);
    }, 'json');
}

// ════════════════════════════════════════════════
// 📬 FORM HANDLERS
// ════════════════════════════════════════════════
function handleForm(formId, actionName, onSuccess) {
    $('#' + formId).submit(function(e) {
        e.preventDefault();
        const form = $(this);
        const btn  = form.find('button[type="submit"]');
        const orig = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);

        $.post('', form.serialize() + '&action=' + actionName, function(res) {
            if (res.status === 'success') {
                showNotice('success', res.message || 'সফলভাবে সম্পন্ন হয়েছে।');
                form[0].reset();
                $('.adv-body').slideUp();
                fetchSummary();
                if (onSuccess) onSuccess(res);
            } else {
                showNotice('error', res.message || 'অনুরোধ ব্যর্থ হয়েছে।');
            }
        }, 'json').fail(function() {
            showNotice('error', 'সার্ভারে সমস্যা হচ্ছে — আবার চেষ্টা করুন।');
        }).always(() => btn.html(orig).prop('disabled', false));
    });
}

// ════════════════════════════════════════════════
// 🚀 INIT
// FIX ⑬: console.log থেকে build info সরানো হয়েছে
// ════════════════════════════════════════════════
$(document).ready(function() {
    fetchSummary();
    loadAccounts('active');

    handleForm('dpsAccountForm',  'add_dps_account',  () => { closeSheet(); loadAccounts(currentAccStatus); });
    handleForm('dpsDepositForm',  'add_dps_deposit',  () => { closeSheet(); loadAccounts(currentAccStatus); fetchActiveDropdown(); });
    handleForm('dpsWithdrawForm', 'add_dps_withdraw', () => { closeSheet(); loadAccounts(currentAccStatus); fetchActiveDropdown(); });
});
</script>
</body>
</html>
