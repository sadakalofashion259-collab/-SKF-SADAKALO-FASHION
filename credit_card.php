<?php
session_start();
date_default_timezone_set('Asia/Dhaka');
include 'db_connect.php';

// ============================================================
// এরর লগিং (ইউজারকে ডাটাবেজ এরর না দেখিয়ে ফাইলে সেভ করা)
// ============================================================
function logError($message) {
    $dir = 'Logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/Card_error_log.txt';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[$time] $message" . PHP_EOL, FILE_APPEND);
}

// ============================================================
// CSRF টোকেন জেনারেট
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================================
// অ্যাক্সেস কন্ট্রোল — শুধু Admin
// ============================================================
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: index.php"); exit;
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php"); exit;
}
$sess_usr = $_SESSION['username'] ?? 'admin';

function encryptCard($plainText) {
    if (empty($plainText)) return '';
    $key    = hash('sha256', CARD_ENC_KEY, true);
    $iv     = openssl_random_pseudo_bytes(16);
    $cipher = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}
function decryptCard($encText) {
    if (empty($encText)) return '';
    try {
        $key  = hash('sha256', CARD_ENC_KEY, true);
        $data = base64_decode($encText);
        if ($data === false || strlen($data) < 16) return '';
        $iv     = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    } catch (Exception $e) { 
        logError("Decrypt Error: " . $e->getMessage()); return ''; 
    }
}
function verifyAdminPass($conn, $username, $pass) {
    try {
        $st = $conn->prepare("SELECT password FROM users WHERE username = ? AND role = 'admin' LIMIT 1");
        $st->execute([$username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $stored = $row['password'];
        if ($stored === $pass) return true;
        if (md5($pass) === $stored) return true;
        if (password_verify($pass, $stored)) return true;
        return false;
    } catch (Exception $e) { return false; }
}

// ============================================================
// সেশন ফ্ল্যাশ মেসেজ
// ============================================================
$success_msg = ''; $error_msg = '';
if (isset($_SESSION['success_msg'])) { $success_msg = $_SESSION['success_msg']; unset($_SESSION['success_msg']); }
if (isset($_SESSION['error_msg']))   { $error_msg   = $_SESSION['error_msg'];   unset($_SESSION['error_msg']); }

// ============================================================
// বিলিং সাইকেল ক্যালকুলেটর (২৭ থেকে ২৭ তারিখ লজিক)
// ============================================================
function getBillingCycle($txn_date, $billing_date) {
    $ts = strtotime($txn_date);
    $y = date('Y', $ts); $m = date('n', $ts); $d = date('j', $ts);
    if ($d >= $billing_date) {
        $nextMonth = strtotime("+1 month", strtotime("$y-$m-01"));
        return date('Y-m', $nextMonth);
    } else {
        return date('Y-m', $ts);
    }
}
function getBillingCycleLabel($cycleStr, $billing_date) {
    $endTs = strtotime($cycleStr . '-' . str_pad($billing_date, 2, '0', STR_PAD_LEFT));
    $startTs = strtotime("-1 month + 1 day", $endTs);
    return date('d M', $startTs) . ' — ' . date('d M Y', $endTs);
}

// ============================================================
// রানিং ডিউ (বকেয়া) রিক্যালকুলেশন
// ============================================================
function recalculateRunningDue($conn, $card_id) {
    $st = $conn->prepare("SELECT id, card_due_impact FROM sys_card_ledger WHERE card_id=? ORDER BY txn_date ASC, id ASC");
    $st->execute([$card_id]);
    $run = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $run += floatval($r['card_due_impact']);
        $conn->prepare("UPDATE sys_card_ledger SET running_due=? WHERE id=?")->execute([round($run,2), $r['id']]);
    }
}

// ============================================================
// AJAX রিকোয়েস্ট হ্যান্ডলিং (ডিলিট, স্ট্যাটাস, আনমাস্ক)
// ============================================================
if (isset($_POST['ajax_action'])) {
    ob_clean(); header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['status'=>'error','message'=>'❌ সিকিউরিটি টোকেন ইনভ্যালিড!']); exit;
    }
    $pass = trim($_POST['pass'] ?? '');
    if (!verifyAdminPass($conn, $sess_usr, $pass)) {
        echo json_encode(['status'=>'error','message'=>'❌ ভুল পাসওয়ার্ড!']); exit;
    }
    $action = $_POST['ajax_action'];
    try {
        if ($action === 'unmask_card') {
            $cid = intval($_POST['card_id'] ?? 0);
            $st = $conn->prepare("SELECT card_number_enc, card_pin_enc FROM sys_credit_cards WHERE id=?");
            $st->execute([$cid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("কার্ড পাওয়া যায়নি।");
            echo json_encode(['status'=>'success','card_number'=> decryptCard($row['card_number_enc']),'card_pin'=> decryptCard($row['card_pin_enc'])]); exit;
        }
        if ($action === 'toggle_status') {
            $cid = intval($_POST['card_id'] ?? 0);
            $st = $conn->prepare("SELECT status FROM sys_credit_cards WHERE id=?");
            $st->execute([$cid]);
            $new = ($st->fetchColumn() === 'active') ? 'inactive' : 'active';
            $conn->prepare("UPDATE sys_credit_cards SET status=? WHERE id=?")->execute([$new, $cid]);
            echo json_encode(['status'=>'success','message'=>'✅ স্ট্যাটাস পরিবর্তন হয়েছে।']); exit;
        }
        if ($action === 'delete_card') {
            $cid = intval($_POST['card_id'] ?? 0);
            $conn->beginTransaction();
            $st1 = $conn->prepare("SELECT card_image FROM sys_credit_cards WHERE id=?"); $st1->execute([$cid]); $img = $st1->fetchColumn();
            if (!empty($img) && file_exists($img)) unlink($img);
            $st2 = $conn->prepare("SELECT receipt_image FROM sys_card_ledger WHERE card_id=? AND receipt_image IS NOT NULL"); $st2->execute([$cid]);
            foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $r) { if (!empty($r) && file_exists($r)) unlink($r); }
            $conn->prepare("DELETE FROM sys_card_ledger WHERE card_id=?")->execute([$cid]);
            $conn->prepare("DELETE FROM sys_credit_cards WHERE id=?")->execute([$cid]);
            $conn->commit();
            echo json_encode(['status'=>'success','message'=>'✅ কার্ড ডিলিট হয়েছে।']); exit;
        }
        if ($action === 'delete_ledger') {
            $lid = intval($_POST['ledger_id'] ?? 0);
            $conn->beginTransaction();
            $st = $conn->prepare("SELECT card_id, receipt_image FROM sys_card_ledger WHERE id=?"); $st->execute([$lid]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("এন্ট্রি পাওয়া যায়নি।");
            if (!empty($row['receipt_image']) && file_exists($row['receipt_image'])) unlink($row['receipt_image']);
            $conn->prepare("DELETE FROM sys_card_ledger WHERE id=?")->execute([$lid]);
            recalculateRunningDue($conn, $row['card_id']);
            $conn->commit();
            echo json_encode(['status'=>'success','message'=>'✅ এন্ট্রি ডিলিট হয়েছে।']); exit;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        logError("Ajax Error: " . $e->getMessage());
        echo json_encode(['status'=>'error','message'=>'সার্ভার এরর!']); exit;
    }
}

// ============================================================
// POST ফর্ম সাবমিশন (অ্যাড/এডিট কার্ড এবং ট্রানজেকশন)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_msg'] = '❌ সিকিউরিটি টোকেন ইনভ্যালিড!'; header("Location: credit_card.php"); exit;
    }
    
    try {
        if ($_POST['action'] === 'add_card') {
            $card_name    = trim($_POST['card_name'] ?? '');
            $card_number  = preg_replace('/\s+/', '', trim($_POST['card_number'] ?? ''));
            $card_pin     = trim($_POST['card_pin'] ?? '');
            $card_expiry  = trim($_POST['card_expiry'] ?? '');
            $billing_date = intval($_POST['billing_date'] ?? 1);
            $grace_days   = intval($_POST['grace_days'] ?? 15);
            $limit        = floatval($_POST['credit_limit'] ?? 0);
            $notes        = trim($_POST['notes'] ?? '');

            if (empty($card_name) || empty($card_number)) throw new Exception("কার্ডের নাম ও নাম্বার দিতে হবে।");

            $enc_num = encryptCard($card_number); $enc_pin = !empty($card_pin) ? encryptCard($card_pin) : null;
            $img_path = null;
            if (isset($_FILES['card_image']) && $_FILES['card_image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['card_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','webp']) && $_FILES['card_image']['size'] <= 5*1024*1024) {
                    $folder = 'uploads/credit_cards/'; if (!is_dir($folder)) @mkdir($folder, 0755, true);
                    $fname = 'card_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['card_image']['tmp_name'], $folder . $fname)) $img_path = $folder . $fname;
                }
            }
            $st = $conn->prepare("INSERT INTO sys_credit_cards (card_name, card_number_enc, card_last4, card_pin_enc, card_expiry, credit_limit, billing_date, grace_days, card_image, notes, status, entry_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
            $st->execute([$card_name, $enc_num, substr($card_number,-4), $enc_pin, $card_expiry, $limit, $billing_date, $grace_days, $img_path, $notes, $sess_usr]);
            $_SESSION['success_msg'] = '✅ নতুন ক্রেডিট কার্ড যোগ হয়েছে।';
            header("Location: credit_card.php"); exit;
        }

        if ($_POST['action'] === 'update_card') {
            $cid          = intval($_POST['card_id'] ?? 0);
            $card_name    = trim($_POST['card_name'] ?? '');
            $card_expiry  = trim($_POST['card_expiry'] ?? '');
            $billing_date = intval($_POST['billing_date'] ?? 1);
            $grace_days   = intval($_POST['grace_days'] ?? 15);
            $limit        = floatval($_POST['credit_limit'] ?? 0);
            $notes        = trim($_POST['notes'] ?? '');

            $img_path = null; $update_img = false;
            if (isset($_FILES['card_image']) && $_FILES['card_image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['card_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                    $folder = 'uploads/credit_cards/';
                    $fname = 'card_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['card_image']['tmp_name'], $folder . $fname)) {
                        $st = $conn->prepare("SELECT card_image FROM sys_credit_cards WHERE id=?"); $st->execute([$cid]); $old = $st->fetchColumn();
                        if (!empty($old) && file_exists($old)) unlink($old);
                        $img_path = $folder . $fname; $update_img = true;
                    }
                }
            }
            if ($update_img) {
                $st = $conn->prepare("UPDATE sys_credit_cards SET card_name=?, card_expiry=?, billing_date=?, grace_days=?, credit_limit=?, notes=?, card_image=? WHERE id=?");
                $st->execute([$card_name, $card_expiry, $billing_date, $grace_days, $limit, $notes, $img_path, $cid]);
            } else {
                $st = $conn->prepare("UPDATE sys_credit_cards SET card_name=?, card_expiry=?, billing_date=?, grace_days=?, credit_limit=?, notes=? WHERE id=?");
                $st->execute([$card_name, $card_expiry, $billing_date, $grace_days, $limit, $notes, $cid]);
            }
            $_SESSION['success_msg'] = '✅ কার্ড আপডেট হয়েছে।';
            header("Location: credit_card.php?view=" . $cid); exit;
        }

        if ($_POST['action'] === 'add_transaction') {
            $cid       = intval($_POST['card_id'] ?? 0);
            $type      = $_POST['txn_type'] ?? '';
            $amount    = floatval($_POST['amount'] ?? 0);
            $charge    = floatval($_POST['charge_amount'] ?? 0);
            $txn_date  = $_POST['txn_date'] ?? date('Y-m-d');
            $note      = trim($_POST['description'] ?? '');
            $page_ret  = intval($_POST['current_page'] ?? 1);

            $st_c = $conn->prepare("SELECT billing_date FROM sys_credit_cards WHERE id=?");
            $st_c->execute([$cid]);
            $b_date = intval($st_c->fetchColumn());
            $billing_cycle = getBillingCycle($txn_date, $b_date);

            $receipt = null;
            if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','webp','pdf'])) {
                    $folder = 'uploads/card_receipts/'; if (!is_dir($folder)) @mkdir($folder, 0755, true);
                    $fname = 'rcp_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['receipt_image']['tmp_name'], $folder . $fname)) $receipt = $folder . $fname;
                }
            }

            $due_imp = 0; $cash_imp = 0;
            switch ($type) {
                case 'purchase':     $due_imp = $amount + $charge; $cash_imp = 0; break;
                case 'cash_advance': $due_imp = $amount + $charge; $cash_imp = $amount; break;
                case 'bill_pay': case 'min_pay': case 'full_pay':
                    $due_imp = -$amount; $cash_imp = -($amount + $charge); break;
                case 'charge_pay':   $due_imp = 0; $cash_imp = -$amount; break;
            }

            $conn->beginTransaction();
            $st = $conn->prepare("INSERT INTO sys_card_ledger (card_id, txn_date, billing_cycle, txn_type, amount, charge_amount, card_due_impact, cash_impact, running_due, description, receipt_image, entry_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)");
            $st->execute([$cid, $txn_date, $billing_cycle, $type, $amount, $charge, $due_imp, $cash_imp, $note, $receipt, $sess_usr]);
            recalculateRunningDue($conn, $cid);
            $conn->commit();
            $_SESSION['success_msg'] = '✅ ট্রানজেকশন রেকর্ড হয়েছে।';
            header("Location: credit_card.php?view=" . $cid . "&page=" . $page_ret); exit;
        }

        // এডিট ট্রানজেকশন লজিক
        if ($_POST['action'] === 'update_transaction') {
            $lid       = intval($_POST['ledger_id'] ?? 0);
            $cid       = intval($_POST['card_id'] ?? 0);
            $type      = $_POST['txn_type'] ?? '';
            $amount    = floatval($_POST['amount'] ?? 0);
            $charge    = floatval($_POST['charge_amount'] ?? 0);
            $txn_date  = $_POST['txn_date'] ?? date('Y-m-d');
            $note      = trim($_POST['description'] ?? '');
            $page_ret  = intval($_POST['current_page'] ?? 1);

            $st_c = $conn->prepare("SELECT billing_date FROM sys_credit_cards WHERE id=?");
            $st_c->execute([$cid]);
            $b_date = intval($st_c->fetchColumn());
            $billing_cycle = getBillingCycle($txn_date, $b_date);

            $due_imp = 0; $cash_imp = 0;
            switch ($type) {
                case 'purchase':     $due_imp = $amount + $charge; $cash_imp = 0; break;
                case 'cash_advance': $due_imp = $amount + $charge; $cash_imp = $amount; break;
                case 'bill_pay': case 'min_pay': case 'full_pay':
                    $due_imp = -$amount; $cash_imp = -($amount + $charge); break;
                case 'charge_pay':   $due_imp = 0; $cash_imp = -$amount; break;
            }

            $conn->beginTransaction();
            $st = $conn->prepare("UPDATE sys_card_ledger SET txn_date=?, billing_cycle=?, txn_type=?, amount=?, charge_amount=?, card_due_impact=?, cash_impact=?, description=? WHERE id=? AND card_id=?");
            $st->execute([$txn_date, $billing_cycle, $type, $amount, $charge, $due_imp, $cash_imp, $note, $lid, $cid]);
            recalculateRunningDue($conn, $cid);
            $conn->commit();
            $_SESSION['success_msg'] = '✅ ট্রানজেকশন আপডেট হয়েছে।';
            header("Location: credit_card.php?view=" . $cid . "&page=" . $page_ret); exit;
        }

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        logError("Form Action Error: " . $e->getMessage());
        $_SESSION['error_msg'] = 'সার্ভার এরর! লগে দেখুন।';
        header("Location: credit_card.php"); exit;
    }
}

// ============================================================
// সামারি ক্যালকুলেশন (ওভারলিমিট সহ)
// ============================================================
function getCardSummary($conn, $card) {
    $cid = $card['id'];
    $limit = floatval($card['credit_limit']);
    
    $st = $conn->prepare("SELECT 
        COALESCE(SUM(CASE WHEN txn_type IN ('purchase','cash_advance') THEN amount ELSE 0 END), 0) AS total_used,
        COALESCE(SUM(CASE WHEN txn_type IN ('bill_pay','min_pay','full_pay') THEN amount ELSE 0 END), 0) AS total_paid,
        COALESCE(SUM(charge_amount), 0) AS total_charge,
        COALESCE(SUM(card_due_impact), 0) AS raw_due
    FROM sys_card_ledger WHERE card_id = ?");
    $st->execute([$cid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    $raw_due = floatval($r['raw_due']);
    $current_due = max(0, $raw_due);
    
    $available = $limit - $current_due;
    $is_overlimit = $available < 0;
    $overlimit_amt = $is_overlimit ? abs($available) : 0;
    $available_balance = $is_overlimit ? 0 : $available;

    return [
        'total_used'        => floatval($r['total_used']),
        'total_paid'        => floatval($r['total_paid']),
        'total_charge'      => floatval($r['total_charge']),
        'current_due'       => $current_due,
        'available_balance' => $available_balance,
        'is_overlimit'      => $is_overlimit,
        'overlimit_amt'     => $overlimit_amt,
        'limit'             => $limit
    ];
}

// ============================================================
// ৩-বাতি লজিক এবং ডিউ অ্যালার্ট
// ============================================================
function getCardLight($conn, $card, $current_due) {
    $today = date('Y-m-d');
    if ($current_due <= 0.01) return ['color'=>'green', 'label'=>'সম্পূর্ণ পরিশোধিত', 'pulse'=>true];
    $current_cycle = getBillingCycle($today, $card['billing_date']);
    $st = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM sys_card_ledger WHERE card_id=? AND txn_type IN ('bill_pay','min_pay','full_pay') AND billing_cycle=?");
    $st->execute([$card['id'], $current_cycle]);
    $paid_this_cycle = floatval($st->fetchColumn());
    $last_cycle_end = date('Y-m-d', strtotime($current_cycle . '-' . str_pad($card['billing_date'], 2, '0', STR_PAD_LEFT)));
    $due_date = date('Y-m-d', strtotime($last_cycle_end . " + {$card['grace_days']} days"));
    
    if ($today > $due_date && $current_due > 0 && $paid_this_cycle <= 0) {
        return ['color'=>'red', 'label'=>'ওভারডিউ! চার্জ যুক্ত হবে', 'pulse'=>true];
    }
    if ($paid_this_cycle > 0) return ['color'=>'yellow', 'label'=>'আংশিক/মিনিমাম পরিশোধ', 'pulse'=>true];
    return ['color'=>'red', 'label'=>'বিল বাকি আছে', 'pulse'=>false];
}

// ============================================================
// ডাটা ফেচিং (লিস্টের জন্য)
// ============================================================
$active_cards = []; $inactive_cards = [];
$rows = $conn->query("SELECT * FROM sys_credit_cards ORDER BY status ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $c) {
    $c['summary'] = getCardSummary($conn, $c);
    $c['light']   = getCardLight($conn, $c, $c['summary']['current_due']);
    if ($c['status'] === 'active') $active_cards[] = $c; else $inactive_cards[] = $c;
}

$g_total_due=0; $g_total_paid=0; $g_total_charge=0; $g_total_used=0;
foreach ($active_cards as $c) {
    $g_total_due += $c['summary']['current_due']; $g_total_paid += $c['summary']['total_paid'];
    $g_total_charge += $c['summary']['total_charge']; $g_total_used += $c['summary']['total_used'];
}

// ============================================================
// সিঙ্গেল কার্ড ভিউ (বিলিং সাইকেল ভিত্তিক পেজিনেশন)
// ============================================================
$view_card = null; $view_summary = null; $view_light = null; $grouped_ledger = [];
$total_pages = 1; $current_page = 1;

if (isset($_GET['view']) && intval($_GET['view']) > 0) {
    $vid = intval($_GET['view']);
    $st  = $conn->prepare("SELECT * FROM sys_credit_cards WHERE id=?");
    $st->execute([$vid]);
    $view_card = $st->fetch(PDO::FETCH_ASSOC);
    if ($view_card) {
        $view_summary = getCardSummary($conn, $view_card);
        $view_light   = getCardLight($conn, $view_card, $view_summary['current_due']);
        
        // বিলিং সাইকেল ফেচ করা (পেজিনেশনের জন্য)
        $st_cycles = $conn->prepare("SELECT DISTINCT billing_cycle FROM sys_card_ledger WHERE card_id=? ORDER BY billing_cycle DESC");
        $st_cycles->execute([$vid]);
        $all_cycles = $st_cycles->fetchAll(PDO::FETCH_COLUMN);
        
        $total_pages = count($all_cycles);
        if ($total_pages == 0) $total_pages = 1;

        $current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
        
        if (!empty($all_cycles)) {
            $active_cycle = $all_cycles[$current_page - 1]; // Current cycle for this page
            
            // শুধু ঐ সাইকেলের ডাটা ফেচ করা
            $st_l = $conn->prepare("SELECT * FROM sys_card_ledger WHERE card_id=? AND billing_cycle=? ORDER BY txn_date DESC, id DESC");
            $st_l->execute([$vid, $active_cycle]);
            $grouped_ledger[$active_cycle] = $st_l->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$type_labels = [
    'bill_pay'=>'বিল পরিশোধ',
    'min_pay'=>'মিনিমাম বিল পরিশোধ', 
    'full_pay'=>'ফুল পরিশোধ',
    'charge_pay'=>'চার্জ পরিশোধ',
    'cash_advance'=>'ক্যাশ অ্যাডভান্স',
    'purchase'=>'কেনাকাটা'
];
?>

<!DOCTYPE html>
<html lang="bn" data-bs-theme="dark">
<head>
<script>(function(){try{var t=localStorage.getItem('cc_theme');document.documentElement.setAttribute('data-bs-theme',t==='light'?'light':'dark');}catch(e){}})();</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Credit Card Manager — Sada Kalo Fashion</title>

<!-- Bootstrap 5.3.8 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Icons & Fonts -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">

<style>
/* ============================================================
   নতুন ডিজাইন সিস্টেম — Bootstrap 5.3.8 + Custom App Theme
   লজিক ১০০% অপরিবর্তিত; শুধু প্রেজেন্টেশন নতুন করে সাজানো
   ============================================================ */
:root{
  --brand-1:#6366f1; --brand-2:#8b5cf6; --brand-3:#a855f7;
  --grad-brand:linear-gradient(135deg,#6366f1,#8b5cf6);
  --c-green:#10b981; --c-green-2:#059669;
  --c-red:#f43f5e;   --c-red-2:#e11d48;
  --c-amber:#f59e0b; --c-amber-2:#d97706;
  --c-sky:#0ea5e9;   --c-sky-2:#0369a1;
  --c-violet:#a855f7;--c-violet-2:#7c3aed;
  --c-yellow:#facc15;
  --r-sm:12px; --r-md:18px; --r-lg:26px;
  --ff:'Plus Jakarta Sans',system-ui,sans-serif;
  --mono:'JetBrains Mono',monospace;
  --ease:cubic-bezier(.34,1.56,.64,1);
  --app-w:480px;
}
/* ---- Dark theme tokens ---- */
[data-bs-theme="dark"]{
  --bs-primary:#6366f1; --bs-primary-rgb:99,102,241;
  --app-bg:#05070f;
  --app-shell:#0a0f1c;
  --surface:#121a2c;
  --surface-2:#0e1525;
  --surface-3:#172238;
  --txt:#e9eefb; --txt-2:#9fb0d0; --txt-mut:#5f6f8e;
  --border:rgba(255,255,255,.07);
  --border-2:rgba(255,255,255,.12);
  --shadow:0 18px 44px -18px rgba(0,0,0,.85);
  --glass:rgba(12,18,32,.72);
}
/* ---- Light theme tokens ---- */
[data-bs-theme="light"]{
  --bs-primary:#6366f1; --bs-primary-rgb:99,102,241;
  --app-bg:#c3ccdd;
  --app-shell:#eef1f8;
  --surface:#ffffff;
  --surface-2:#f4f7fc;
  --surface-3:#eaeef8;
  --txt:#0f172a; --txt-2:#46566f; --txt-mut:#8493ab;
  --border:rgba(15,23,42,.08);
  --border-2:rgba(15,23,42,.14);
  --shadow:0 16px 40px -20px rgba(30,41,90,.4);
  --glass:rgba(255,255,255,.78);
}
*{box-sizing:border-box}
html,body{max-width:100vw;overflow-x:hidden}
body{
  font-family:var(--ff); background:var(--app-bg); color:var(--txt);
  margin:0; padding:0; -webkit-font-smoothing:antialiased;
  transition:background .35s ease,color .35s ease;
}
/* App shell — centered phone-like column */
.app-shell{
  max-width:var(--app-w); margin:0 auto; min-height:100vh;
  background:var(--app-shell); position:relative;
  box-shadow:0 0 80px rgba(0,0,0,.45);
  padding-bottom:108px;
}
@media(min-width:540px){
  body{padding:22px 0}
  .app-shell{min-height:calc(100vh - 44px); border-radius:30px; overflow:hidden; border:1px solid var(--border)}
}
.wrap{padding:0 14px; position:relative; z-index:1}

/* ============ Top app bar ============ */
.appbar{
  position:sticky; top:0; z-index:1020;
  display:flex; align-items:center; gap:10px;
  padding:12px 14px; height:62px;
  background:var(--glass); backdrop-filter:blur(16px);
  border-bottom:1px solid var(--border);
}
.appbar-side{display:flex; align-items:center; gap:8px}
.appbar-center{flex:1; display:flex; align-items:center; justify-content:center; gap:9px}
.ic-btn{
  width:40px; height:40px; flex:0 0 40px; border-radius:13px;
  border:1px solid var(--border); background:var(--surface); color:var(--txt-2);
  display:flex; align-items:center; justify-content:center; font-size:15px;
  cursor:pointer; text-decoration:none; transition:.2s;
}
.ic-btn:hover{color:var(--brand-2); border-color:var(--brand-2); transform:translateY(-1px)}
.ic-btn:active{transform:scale(.94)}
.brand-mark{
  position:relative; width:38px; height:38px; border-radius:12px;
  background:var(--grad-brand); display:flex; align-items:center; justify-content:center;
  color:#fff; font-weight:800; font-size:14px; letter-spacing:.5px; overflow:hidden;
  box-shadow:0 6px 18px -4px rgba(99,102,241,.6);
}
.brand-logo{position:absolute; inset:0; width:100%; height:100%; object-fit:cover}
.brand-txt{display:flex; flex-direction:column; line-height:1}
.brand-name{font-size:15px; font-weight:800; letter-spacing:.5px; color:var(--txt)}
.brand-sub{font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:var(--txt-mut); font-family:var(--mono); margin-top:3px}

/* ============ Marquee strip ============ */
.strip{
  background:var(--surface-2); border-bottom:1px solid var(--border);
  overflow:hidden; white-space:nowrap; position:relative; padding:6px 0;
}
.strip-tag{
  position:absolute; left:10px; top:50%; transform:translateY(-50%); z-index:2;
  background:var(--grad-brand); color:#fff; font-size:8px; font-weight:800;
  letter-spacing:1px; padding:3px 9px; border-radius:20px; font-family:var(--mono);
}
.strip-run{display:inline-block; padding-left:100%; animation:strip 24s linear infinite; font-size:10.5px; color:var(--txt-2); font-weight:600}
@keyframes strip{from{transform:translateX(0)}to{transform:translateX(-100%)}}

/* ============ Section header ============ */
.sec-head{display:flex; align-items:center; justify-content:space-between; padding:18px 2px 10px}
.sec-title{display:flex; align-items:center; gap:9px; font-size:13px; font-weight:800; letter-spacing:.4px; color:var(--txt)}
.sec-title i{width:30px; height:30px; border-radius:10px; background:rgba(var(--bs-primary-rgb),.14); color:var(--brand-2); display:flex; align-items:center; justify-content:center; font-size:13px}
.sec-pill{font-family:var(--mono); font-size:10px; font-weight:700; color:var(--txt-2); background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:5px 12px}

/* ============ Alerts ============ */
.app-alert{border:none; border-radius:var(--r-sm); font-size:12.5px; font-weight:700; padding:11px 14px; margin:12px 0; display:flex; align-items:center; gap:9px}
.app-alert .btn-close{margin-left:auto; --bs-btn-close-opacity:.5}
.app-alert-ok{background:rgba(16,185,129,.13); color:var(--c-green); box-shadow:inset 0 0 0 1px rgba(16,185,129,.3)}
.app-alert-err{background:rgba(244,63,94,.13); color:var(--c-red); box-shadow:inset 0 0 0 1px rgba(244,63,94,.3)}

/* ============ Stat tiles ============ */
.stat-grid{display:grid; grid-template-columns:repeat(4,1fr); gap:9px}
@media(max-width:520px){.stat-grid{grid-template-columns:repeat(2,1fr)}}
.stat{
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md);
  padding:13px 11px; position:relative; overflow:hidden; box-shadow:var(--shadow); transition:transform .2s var(--ease);
}
.stat:hover{transform:translateY(-3px)}
.stat::before{content:''; position:absolute; top:0; left:0; right:0; height:3px}
.stat-1::before{background:linear-gradient(90deg,var(--c-red),#fb7185)}
.stat-2::before{background:linear-gradient(90deg,var(--c-green),#34d399)}
.stat-3::before{background:linear-gradient(90deg,var(--c-amber),#fbbf24)}
.stat-4::before{background:linear-gradient(90deg,var(--brand-1),var(--brand-2))}
.stat-ico{width:30px; height:30px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:13px; margin-bottom:9px}
.stat-1 .stat-ico{background:rgba(244,63,94,.14); color:var(--c-red)}
.stat-2 .stat-ico{background:rgba(16,185,129,.14); color:var(--c-green)}
.stat-3 .stat-ico{background:rgba(245,158,11,.14); color:var(--c-amber)}
.stat-4 .stat-ico{background:rgba(99,102,241,.16); color:var(--brand-2)}
.stat-lbl{font-size:9px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:var(--txt-mut); font-family:var(--mono); margin-bottom:4px}
.stat-val{font-size:16px; font-weight:800; font-family:var(--mono); color:var(--txt); line-height:1}

/* ============ Credit-card hero (single view) ============ */
.cc-hero{
  border-radius:var(--r-lg); padding:18px; position:relative; overflow:hidden;
  background:linear-gradient(135deg,#1e1b4b 0%,#312e81 45%,#4338ca 100%);
  box-shadow:0 24px 50px -22px rgba(67,56,202,.8); margin-top:4px;
}
.cc-hero.is-over{background:linear-gradient(135deg,#3b0a18 0%,#7f1d1d 50%,#b91c1c 100%); box-shadow:0 24px 50px -22px rgba(220,38,38,.7)}
.cc-hero::after{content:''; position:absolute; top:-50px; right:-40px; width:170px; height:170px; background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%); border-radius:50%}
.cc-hero-top{display:flex; align-items:center; justify-content:space-between; position:relative; z-index:1}
.cc-chip{width:38px; height:28px; border-radius:7px; background:linear-gradient(135deg,#fde68a,#d4a017); position:relative; box-shadow:inset 0 0 0 1px rgba(0,0,0,.15)}
.cc-chip::before{content:''; position:absolute; inset:5px 7px; border:1px solid rgba(0,0,0,.25); border-radius:3px}
.cc-lights{display:flex; gap:5px; align-items:center}
.lt{width:11px; height:11px; border-radius:50%; background:rgba(255,255,255,.16)}
.lt.on-green{background:var(--c-green); box-shadow:0 0 10px var(--c-green)}
.lt.on-yellow{background:var(--c-yellow); box-shadow:0 0 10px var(--c-yellow)}
.lt.on-red{background:var(--c-red); box-shadow:0 0 10px var(--c-red)}
.lt.pulse{animation:ltp 1.4s ease-in-out infinite}
@keyframes ltp{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}
.cc-num{font-family:var(--mono); font-size:19px; font-weight:800; letter-spacing:4px; color:#fff; margin:22px 0 14px; position:relative; z-index:1; text-shadow:0 2px 8px rgba(0,0,0,.4)}
.cc-bottom{display:flex; align-items:flex-end; justify-content:space-between; position:relative; z-index:1; gap:10px}
.cc-name{font-size:15px; font-weight:800; color:#fff; display:flex; align-items:center; gap:7px}
.cc-meta{font-size:9.5px; font-weight:600; color:rgba(255,255,255,.7); font-family:var(--mono); margin-top:4px; letter-spacing:.5px}
.cc-statline{font-size:10px; font-weight:700; color:#fff; margin-top:7px; display:flex; align-items:center; gap:6px}
.cc-avatar{width:46px; height:46px; border-radius:14px; object-fit:cover; border:2px solid rgba(255,255,255,.35); flex:0 0 46px; background:rgba(255,255,255,.1); display:flex; align-items:center; justify-content:center; color:#fff; font-size:18px}

/* ============ Generic surface card ============ */
.card-soft{background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md); box-shadow:var(--shadow); overflow:hidden}

/* Collapsible profile info */
.acc-head{display:flex; align-items:center; gap:11px; padding:14px; cursor:pointer; background:none; border:none; width:100%; text-align:left; color:var(--txt)}
.acc-head i.lead-ic{width:34px; height:34px; border-radius:11px; background:rgba(var(--bs-primary-rgb),.14); color:var(--brand-2); display:flex; align-items:center; justify-content:center; font-size:14px; flex:0 0 34px}
.acc-head .acc-t{flex:1; font-size:12.5px; font-weight:800; letter-spacing:.3px}
.acc-head .chev{color:var(--txt-mut); transition:transform .3s; font-size:12px}
.acc-head[aria-expanded="true"] .chev{transform:rotate(180deg)}
.info-grid{display:grid; grid-template-columns:1fr 1fr; gap:9px; padding:0 14px 14px}
@media(max-width:420px){.info-grid{grid-template-columns:1fr}}
.info-cell{background:var(--surface-2); border:1px solid var(--border); border-radius:11px; padding:9px 11px}
.info-k{font-size:8px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; color:var(--txt-mut); font-family:var(--mono); margin-bottom:3px}
.info-v{font-size:12px; font-weight:700; color:var(--txt); font-family:var(--mono)}
.note-box{margin:0 14px 14px; padding:11px; background:var(--surface-2); border:1px solid var(--border); border-radius:11px}

/* ============ Card list rows ============ */
.card-row{
  display:flex; align-items:center; gap:12px; padding:13px 14px;
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md);
  margin-bottom:10px; cursor:pointer; box-shadow:var(--shadow); position:relative; overflow:hidden;
  transition:transform .18s var(--ease), border-color .18s;
}
.card-row:hover{transform:translateY(-2px); border-color:var(--border-2)}
.card-row::before{content:''; position:absolute; left:0; top:14px; bottom:14px; width:4px; border-radius:0 4px 4px 0; background:var(--grad-brand)}
.card-row.overlimit::before{background:linear-gradient(180deg,var(--c-red),var(--c-red-2))}
.card-row.inactive{opacity:.6}
.card-row.inactive::before{background:var(--txt-mut)}
.card-av{width:46px; height:46px; border-radius:14px; object-fit:cover; flex:0 0 46px; background:var(--surface-3); border:1px solid var(--border)}
.card-av-def{width:46px; height:46px; border-radius:14px; flex:0 0 46px; background:var(--grad-brand); color:#fff; display:flex; align-items:center; justify-content:center; font-size:17px; box-shadow:0 6px 16px -6px rgba(99,102,241,.7)}
.card-av-def.dead{background:var(--surface-3); color:var(--txt-mut); box-shadow:none}
.card-body-x{flex:1; min-width:0}
.card-r1{display:flex; align-items:center; gap:8px; margin-bottom:5px}
.card-nm{font-size:13.5px; font-weight:800; color:var(--txt); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1}
.card-mask{font-family:var(--mono); font-size:10px; font-weight:700; color:var(--brand-2); background:rgba(var(--bs-primary-rgb),.12); padding:3px 8px; border-radius:7px}
.card-r2{display:flex; align-items:center; gap:6px; font-size:10.5px; flex-wrap:wrap}
.k-due{color:var(--c-red); font-weight:800; font-family:var(--mono)}
.k-avail{color:var(--c-green); font-weight:800; font-family:var(--mono)}
.k-lbl{color:var(--txt-mut); font-weight:600; font-size:9.5px}
.card-lights{display:flex; gap:4px; flex:0 0 auto}
.card-lights .lt{width:9px;height:9px}

.sub-head{display:flex; align-items:center; gap:8px; font-size:10px; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--txt-2); margin:16px 0 11px; padding:0 2px}
.sub-head .dot{width:8px; height:8px; border-radius:50%}
.sub-head .cnt{margin-left:auto; font-family:var(--mono); background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:3px 9px; font-size:9px}

/* ============ Empty state ============ */
.empty{text-align:center; padding:54px 22px; background:var(--surface); border:1px dashed var(--border-2); border-radius:var(--r-md)}
.empty-ic{width:62px; height:62px; border-radius:20px; background:var(--surface-2); color:var(--txt-mut); display:flex; align-items:center; justify-content:center; font-size:26px; margin:0 auto 14px}
.empty-tx{font-size:13px; color:var(--txt-2); font-weight:600; line-height:1.6}

/* ============ Transaction list (mobile-first) ============ */
.cycle-head{display:flex; align-items:center; justify-content:space-between; padding:11px 13px; margin:16px 0 10px; border-radius:13px; background:var(--surface-2); border:1px solid var(--border)}
.cycle-head .cy-l{font-size:11px; font-weight:800; color:var(--txt); font-family:var(--mono); display:flex; align-items:center; gap:8px}
.cycle-head .cy-l i{color:var(--brand-2)}
.cycle-head .cy-b{font-size:9px; font-weight:700; font-family:var(--mono); color:var(--txt-2); background:var(--surface); border:1px solid var(--border); padding:3px 9px; border-radius:20px}

.txn{display:flex; gap:11px; padding:12px 13px; background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md); margin-bottom:9px; box-shadow:var(--shadow)}
.txn-ic{width:40px; height:40px; border-radius:13px; flex:0 0 40px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:15px}
.ti-pur{background:linear-gradient(135deg,#f43f5e,#9f1239)}
.ti-adv{background:linear-gradient(135deg,#a855f7,#6d28d9)}
.ti-bill{background:linear-gradient(135deg,#10b981,#047857)}
.ti-min{background:linear-gradient(135deg,#f59e0b,#b45309)}
.ti-full{background:linear-gradient(135deg,#0ea5e9,#0369a1)}
.ti-chg{background:linear-gradient(135deg,#fbbf24,#d97706)}
.txn-main{flex:1; min-width:0}
.txn-r1{display:flex; align-items:center; gap:8px; margin-bottom:4px}
.txn-type{font-size:12px; font-weight:800; color:var(--txt); flex:1}
.txn-amt{font-size:13px; font-weight:800; font-family:var(--mono); color:var(--txt)}
.txn-r2{display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:9.5px}
.txn-date{font-family:var(--mono); font-weight:600; color:var(--txt-mut)}
.chiplet{font-family:var(--mono); font-weight:700; font-size:9px; padding:2px 7px; border-radius:20px; border:1px solid}
.chip-pos{color:var(--c-green); border-color:rgba(16,185,129,.35); background:rgba(16,185,129,.1)}
.chip-neg{color:var(--c-red); border-color:rgba(244,63,94,.35); background:rgba(244,63,94,.1)}
.chip-mut{color:var(--txt-mut); border-color:var(--border); background:var(--surface-2)}
.chip-warn{color:var(--c-amber); border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.1)}
.txn-note{font-size:10.5px; color:var(--txt-2); margin-top:6px; line-height:1.45; font-weight:500}
.txn-side{display:flex; flex-direction:column; align-items:center; gap:5px; flex:0 0 auto}
.txn-thumb{width:30px; height:30px; border-radius:8px; object-fit:cover; border:1px solid var(--border); cursor:pointer}
.txn-act{display:flex; gap:5px}
.mini-btn{width:26px; height:26px; border-radius:8px; border:none; display:flex; align-items:center; justify-content:center; font-size:10px; cursor:pointer; transition:.15s}
.mini-btn:active{transform:scale(.9)}
.mb-edit{background:rgba(245,158,11,.16); color:var(--c-amber)}
.mb-del{background:rgba(244,63,94,.16); color:var(--c-red)}

/* ============ Pagination ============ */
.pager{display:flex; align-items:center; justify-content:center; gap:7px; margin:20px 0 4px}
.pg{width:38px; height:38px; border-radius:12px; background:var(--surface); border:1px solid var(--border); color:var(--txt-2); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; transition:.18s}
.pg:hover{color:var(--brand-2); border-color:var(--brand-2)}
.pg-txt{font-size:11px; font-weight:800; font-family:var(--mono); color:var(--txt-2); padding:0 6px}

/* ============ Bottom nav ============ */
.bottom-nav{
  position:fixed; left:0; right:0; bottom:0; z-index:1030;
  display:flex; justify-content:center; pointer-events:none;
}
.bn-inner{
  width:100%; max-width:var(--app-w); margin:0 14px 14px; pointer-events:auto;
  background:var(--glass); backdrop-filter:blur(18px);
  border:1px solid var(--border); border-radius:22px;
  box-shadow:0 -2px 30px -8px rgba(0,0,0,.5), var(--shadow);
  display:flex; align-items:center; justify-content:space-around; gap:4px; padding:9px 8px;
}
.bn{flex:1; display:flex; flex-direction:column; align-items:center; gap:5px; background:none; border:none; cursor:pointer; text-decoration:none; padding:2px; min-width:0}
.bn-ic{width:42px; height:42px; border-radius:15px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:16px; transition:transform .18s var(--ease), box-shadow .18s; box-shadow:0 8px 18px -8px rgba(0,0,0,.6)}
.bn:hover .bn-ic{transform:translateY(-3px)}
.bn:active .bn-ic{transform:scale(.92)}
.bn-lbl{font-size:9.5px; font-weight:700; color:var(--txt-2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:64px}
.g-pur{background:linear-gradient(135deg,#f43f5e,#be123c)}
.g-adv{background:linear-gradient(135deg,#a855f7,#7c3aed)}
.g-bill{background:linear-gradient(135deg,#10b981,#047857)}
.g-more{background:linear-gradient(135deg,#64748b,#334155)}
.g-home{background:linear-gradient(135deg,#0ea5e9,#0369a1)}
.g-add{background:var(--grad-brand)}

/* ============ Bottom sheet (offcanvas) ============ */
.sheet{background:var(--surface) !important; border-top-left-radius:26px; border-top-right-radius:26px; border:1px solid var(--border); max-width:var(--app-w); margin:0 auto; height:auto !important}
.sheet .grip{width:42px; height:5px; border-radius:5px; background:var(--border-2); margin:12px auto 4px}
.sheet-title{text-align:center; font-size:11px; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--txt-2); font-family:var(--mono); padding:6px 0 14px}
.sheet-grid{display:grid; grid-template-columns:repeat(4,1fr); gap:11px; padding:0 16px 26px}
@media(max-width:420px){.sheet-grid{grid-template-columns:repeat(3,1fr)}}
.sheet-item{display:flex; flex-direction:column; align-items:center; gap:7px; background:var(--surface-2); border:1px solid var(--border); border-radius:16px; padding:13px 6px; cursor:pointer; text-decoration:none; color:var(--txt); transition:transform .15s var(--ease)}
.sheet-item:hover{transform:translateY(-3px); border-color:var(--brand-2)}
.sheet-ic{width:40px; height:40px; border-radius:13px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:15px}
.sheet-lbl{font-size:9.5px; font-weight:700; color:var(--txt); text-align:center; line-height:1.2}

/* ============ Modals ============ */
.app-modal .modal-content{background:var(--surface); border:1px solid var(--border-2); border-radius:var(--r-lg); box-shadow:0 30px 70px -20px rgba(0,0,0,.7)}
.app-modal .modal-header{border:none; padding:18px 20px 6px}
.app-modal .modal-title{font-size:14px; font-weight:800; color:var(--txt); display:flex; align-items:center; gap:9px}
.app-modal .modal-title .m-ic{width:32px;height:32px;border-radius:11px;background:rgba(var(--bs-primary-rgb),.15);color:var(--brand-2);display:flex;align-items:center;justify-content:center;font-size:13px}
.app-modal .modal-body{padding:10px 20px 20px}
.app-modal .btn-close{filter:var(--bs-btn-close-white-filter,none)}
[data-bs-theme="dark"] .app-modal .btn-close{filter:invert(1) grayscale(1) brightness(2)}
.txn-hint{font-size:10.5px; font-weight:600; color:var(--txt-2); background:var(--surface-2); border:1px dashed var(--border-2); border-radius:11px; padding:10px 12px; margin-bottom:14px; line-height:1.5}

/* Form fields */
.fld{margin-bottom:13px}
.fld-lbl{font-size:9.5px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; color:var(--txt-mut); font-family:var(--mono); display:block; margin-bottom:6px}
.fld-row{display:grid; grid-template-columns:1fr 1fr; gap:10px}
@media(max-width:420px){.fld-row{grid-template-columns:1fr}}
.form-control,.form-select{
  background:var(--surface-2) !important; color:var(--txt) !important;
  border:1px solid var(--border) !important; border-radius:12px !important;
  font-size:13px; font-weight:600; padding:11px 13px; font-family:var(--ff);
}
.form-control:focus,.form-select:focus{border-color:var(--brand-2) !important; box-shadow:0 0 0 .22rem rgba(var(--bs-primary-rgb),.2) !important}
.form-control::placeholder{color:var(--txt-mut); opacity:.7}
.fld-help{font-size:9.5px; color:var(--txt-mut); font-weight:600; margin-top:5px; font-family:var(--mono)}
.fld-file{border:1px dashed var(--border-2) !important; text-align:center; cursor:pointer}
textarea.form-control{min-height:64px; resize:vertical}

/* 3D pill buttons */
.btn3d{
  flex:1; border:none; border-radius:14px; padding:13px 16px;
  font-family:var(--ff); font-size:12.5px; font-weight:800; letter-spacing:.4px;
  color:#fff; display:inline-flex; align-items:center; justify-content:center; gap:8px;
  cursor:pointer; text-decoration:none; transition:transform .14s var(--ease), box-shadow .14s;
}
.btn3d:active{transform:translateY(2px)}
.b-primary{background:var(--grad-brand); box-shadow:0 8px 20px -8px rgba(99,102,241,.8)}
.b-green{background:linear-gradient(135deg,#10b981,#047857); box-shadow:0 8px 20px -8px rgba(16,185,129,.7)}
.b-amber{background:linear-gradient(135deg,#f59e0b,#b45309); box-shadow:0 8px 20px -8px rgba(245,158,11,.7)}
.b-ghost{background:var(--surface-3); color:var(--txt-2); box-shadow:inset 0 0 0 1px var(--border)}
.b-danger{background:linear-gradient(135deg,#f43f5e,#be123c); box-shadow:0 8px 20px -8px rgba(244,63,94,.7)}
.btn3d:hover{box-shadow:0 12px 26px -8px rgba(0,0,0,.5)}
.modal-foot{display:flex; gap:10px; margin-top:6px}

/* Unmask reveal */
.reveal-box{text-align:center; padding:6px 0 8px}
.reveal-lbl{font-size:9px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; color:var(--txt-mut); font-family:var(--mono); margin:10px 0 6px}
.reveal-num{font-family:var(--mono); font-size:20px; font-weight:800; letter-spacing:3px; color:var(--brand-2); background:var(--surface-2); border:1px solid var(--border); border-radius:13px; padding:13px}
.reveal-pin{font-family:var(--mono); font-size:17px; font-weight:800; letter-spacing:3px; color:var(--c-amber); background:var(--surface-2); border:1px solid var(--border); border-radius:13px; padding:11px}

/* Password modal */
.pw-ic{width:58px; height:58px; border-radius:18px; background:rgba(244,63,94,.14); color:var(--c-red); display:flex; align-items:center; justify-content:center; font-size:24px; margin:6px auto 12px}
.pw-inp{text-align:center; letter-spacing:3px}
.pw-err{font-size:11px; color:var(--c-red); font-weight:700; min-height:16px; margin:8px 0; text-align:center}

/* Image viewer */
#imgModal img{max-width:90vw; max-height:84vh; border-radius:16px; box-shadow:0 30px 70px rgba(0,0,0,.8)}

/* Toast */
.toast-pop{position:fixed; bottom:120px; left:50%; transform:translateX(-50%); z-index:2000; padding:11px 20px; border-radius:14px; font-size:12.5px; font-weight:800; color:#fff; box-shadow:0 16px 40px -12px rgba(0,0,0,.6); animation:tin .3s var(--ease)}
@keyframes tin{from{opacity:0; transform:translateX(-50%) translateY(10px)}to{opacity:1; transform:translateX(-50%) translateY(0)}}
.txn-history-title{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:800;color:var(--txt);padding:20px 2px 4px}
.txn-history-title i{color:var(--brand-2)}
.txn-history-title small{color:var(--txt-mut);font-weight:700;font-family:var(--mono);font-size:9px}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:var(--border-2);border-radius:10px}
</style>
</head>
<body>
<div class="app-shell">

<!-- ===== Top App Bar ===== -->
<nav class="appbar">
  <div class="appbar-side">
    <a href="dashboard.php" class="ic-btn" title="ড্যাশবোর্ড"><i class="fas fa-house"></i></a>
  </div>
  <div class="appbar-center">
    <span class="brand-mark"><img src="logo.png" class="brand-logo" alt="SKF" onerror="this.remove()"><b>SK</b></span>
    <span class="brand-txt">
      <span class="brand-name">Sada Kalo</span>
      <span class="brand-sub">Credit Card Vault</span>
    </span>
  </div>
  <div class="appbar-side">
    <button type="button" class="ic-btn" onclick="toggleTheme()" title="থিম"><i id="themeIco" class="fas fa-moon"></i></button>
  </div>
</nav>

<!-- ===== Marquee ===== -->
<div class="strip">
  <span class="strip-tag">CARD</span>
  <span class="strip-run">💳 আপনার সব ক্রেডিট কার্ড — এক জায়গায়  •  মাসভিত্তিক পেজিনেশন  •  এডিট অপশন  •  ক্যাশ ইমপ্যাক্ট  •  এনক্রিপ্টেড সিকিউরিটি 🔒</span>
</div>

<div class="wrap">

<?php if (!empty($success_msg)): ?>
<div class="app-alert app-alert-ok"><i class="fas fa-circle-check"></i><span><?php echo $success_msg; ?></span><button type="button" class="btn-close btn-close-sm" onclick="this.parentElement.remove()"></button></div>
<?php endif; ?>
<?php if (!empty($error_msg)): ?>
<div class="app-alert app-alert-err"><i class="fas fa-triangle-exclamation"></i><span><?php echo $error_msg; ?></span><button type="button" class="btn-close btn-close-sm" onclick="this.parentElement.remove()"></button></div>
<?php endif; ?>

<?php if ($view_card): // ============ সিঙ্গেল কার্ড ভিউ ============ ?>

<div class="sec-head">
  <div class="sec-title"><i class="fas fa-id-card"></i> কার্ড প্রোফাইল</div>
  <div class="sec-pill">ID #<?php echo $view_card['id']; ?></div>
</div>

<!-- Credit-card hero -->
<div class="cc-hero <?php echo $view_summary['is_overlimit'] ? 'is-over' : ''; ?>">
  <div class="cc-hero-top">
    <div class="cc-chip"></div>
    <div class="cc-lights" title="<?php echo $view_light['label']; ?>">
      <div class="lt <?php echo $view_light['color']==='green'?'on-green pulse':''; ?>"></div>
      <div class="lt <?php echo $view_light['color']==='yellow'?'on-yellow pulse':''; ?>"></div>
      <div class="lt <?php echo $view_light['color']==='red'?'on-red'.($view_light['pulse']?' pulse':''):''; ?>"></div>
    </div>
  </div>
  <div class="cc-num">**** **** **** <?php echo $view_card['card_last4']; ?></div>
  <div class="cc-bottom">
    <div style="min-width:0">
      <div class="cc-name">
        <?php echo htmlspecialchars($view_card['card_name']); ?>
        <?php if($view_summary['is_overlimit']): ?><i class="fas fa-triangle-exclamation" style="color:#fecaca;font-size:12px" title="লিমিট ক্রস করেছে!"></i><?php endif; ?>
      </div>
      <div class="cc-meta">বিলিং: <?php echo $view_card['billing_date']; ?> তারিখ · গ্রেস: <?php echo $view_card['grace_days']; ?> দিন</div>
      <div class="cc-statline" style="color:<?php echo $view_light['color']==='red'?'#fecaca':'#fff'; ?>">
        <i class="fas fa-circle" style="font-size:6px"></i> <?php echo $view_light['label']; ?>
      </div>
    </div>
    <?php if (!empty($view_card['card_image']) && file_exists($view_card['card_image'])): ?>
      <img src="<?php echo $view_card['card_image']; ?>" class="cc-avatar" alt="">
    <?php else: ?>
      <div class="cc-avatar"><i class="fas fa-credit-card"></i></div>
    <?php endif; ?>
  </div>
</div>

<!-- Stat tiles -->
<div class="stat-grid" style="margin-top:12px">
  <div class="stat stat-4">
    <div class="stat-ico"><i class="fas fa-coins"></i></div>
    <div class="stat-lbl">ক্রেডিট লিমিট</div>
    <div class="stat-val">৳<?php echo number_format($view_summary['limit']); ?></div>
  </div>
  <div class="stat stat-2">
    <div class="stat-ico"><i class="fas fa-unlock"></i></div>
    <div class="stat-lbl">ব্যবহারযোগ্য</div>
    <div class="stat-val" style="color:<?php echo $view_summary['is_overlimit']?'var(--txt-mut)':'var(--c-green)'; ?>">৳<?php echo number_format($view_summary['available_balance']); ?></div>
  </div>
  <div class="stat stat-1">
    <div class="stat-ico"><i class="fas fa-circle-exclamation"></i></div>
    <div class="stat-lbl"><?php echo $view_summary['is_overlimit'] ? 'ওভারলিমিট' : 'বর্তমান বকেয়া'; ?></div>
    <div class="stat-val" style="color:var(--c-red)">৳<?php echo number_format($view_summary['is_overlimit'] ? $view_summary['overlimit_amt'] : $view_summary['current_due']); ?></div>
  </div>
  <div class="stat stat-3">
    <div class="stat-ico"><i class="fas fa-circle-check"></i></div>
    <div class="stat-lbl">মোট পরিশোধ</div>
    <div class="stat-val" style="color:var(--c-amber)">৳<?php echo number_format($view_summary['total_paid']); ?></div>
  </div>
</div>

<!-- Collapsible profile -->
<div class="card-soft" style="margin-top:12px">
  <button class="acc-head" type="button" data-bs-toggle="collapse" data-bs-target="#ddProfile" aria-expanded="false">
    <i class="lead-ic fas fa-circle-info"></i>
    <span class="acc-t">কার্ড প্রোফাইল তথ্য</span>
    <i class="chev fas fa-chevron-down"></i>
  </button>
  <div class="collapse" id="ddProfile">
    <div class="info-grid">
      <div class="info-cell"><div class="info-k">কার্ড নাম</div><div class="info-v"><?php echo htmlspecialchars($view_card['card_name']); ?></div></div>
      <div class="info-cell"><div class="info-k">মেয়াদ</div><div class="info-v"><?php echo $view_card['card_expiry'] ?: '—'; ?></div></div>
      <div class="info-cell"><div class="info-k">স্ট্যাটাস</div><div class="info-v" style="color:<?php echo $view_card['status']==='active'?'var(--c-green)':'var(--txt-mut)'; ?>"><?php echo strtoupper($view_card['status']); ?></div></div>
      <div class="info-cell"><div class="info-k">বিলিং ও গ্রেস</div><div class="info-v"><?php echo $view_card['billing_date']; ?> তা. (+<?php echo $view_card['grace_days']; ?> দিন)</div></div>
      <div class="info-cell"><div class="info-k">অ্যাড করেছেন</div><div class="info-v"><?php echo htmlspecialchars($view_card['entry_by'] ?? 'admin'); ?></div></div>
    </div>
    <?php if (!empty($view_card['notes'])): ?>
    <div class="note-box">
      <div class="info-k" style="margin-bottom:5px">নোটস</div>
      <div style="font-size:11.5px;color:var(--txt-2);font-weight:500;line-height:1.5"><?php echo nl2br(htmlspecialchars($view_card['notes'])); ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Transaction history -->
<div class="txn-history-title">
  <i class="fas fa-clock-rotate-left"></i> লেনদেনের ইতিহাস
  <?php if($total_pages > 1): ?><small>(পেজ <?php echo $current_page; ?> / <?php echo $total_pages; ?>)</small><?php endif; ?>
</div>

<?php if (empty($grouped_ledger)): ?>
  <div class="empty">
    <div class="empty-ic"><i class="fas fa-inbox"></i></div>
    <div class="empty-tx">এখনো কোনো ট্রানজেকশন নেই।</div>
  </div>
<?php else: ?>
  <?php
    $txn_icons = ['bill_pay'=>'fa-money-bill-wave','min_pay'=>'fa-receipt','full_pay'=>'fa-check-double','charge_pay'=>'fa-percent','cash_advance'=>'fa-wallet','purchase'=>'fa-bag-shopping'];
    $txn_ic_cls = ['bill_pay'=>'ti-bill','min_pay'=>'ti-min','full_pay'=>'ti-full','charge_pay'=>'ti-chg','cash_advance'=>'ti-adv','purchase'=>'ti-pur'];
  ?>
  <?php foreach ($grouped_ledger as $cycle => $ledgers): ?>
    <div class="cycle-head">
      <span class="cy-l"><i class="fas fa-calendar-days"></i> <?php echo getBillingCycleLabel($cycle, $view_card['billing_date']); ?></span>
      <span class="cy-b"><?php echo count($ledgers); ?> টি এন্ট্রি</span>
    </div>
    <?php foreach ($ledgers as $l):
      $tlbl = $type_labels[$l['txn_type']] ?? $l['txn_type'];
      $ic   = $txn_icons[$l['txn_type']] ?? 'fa-arrow-right-arrow-left';
      $iccl = $txn_ic_cls[$l['txn_type']] ?? 'ti-bill';
    ?>
    <div class="txn">
      <div class="txn-ic <?php echo $iccl; ?>"><i class="fas <?php echo $ic; ?>"></i></div>
      <div class="txn-main">
        <div class="txn-r1">
          <span class="txn-type"><?php echo $tlbl; ?></span>
          <span class="txn-amt">৳<?php echo number_format($l['amount']); ?></span>
        </div>
        <div class="txn-r2">
          <span class="txn-date"><i class="fas fa-calendar" style="opacity:.6"></i> <?php echo date('d M Y', strtotime($l['txn_date'])); ?></span>
          <?php if($l['charge_amount']>0): ?><span class="chiplet chip-warn">চার্জ ৳<?php echo number_format($l['charge_amount']); ?></span><?php endif; ?>
          <span class="chiplet <?php echo $l['card_due_impact']<0?'chip-pos':'chip-neg'; ?>">বকেয়া <?php echo ($l['card_due_impact']>=0?'+':'').'৳'.number_format($l['card_due_impact']); ?></span>
          <?php if($l['cash_impact']!=0): ?><span class="chiplet <?php echo $l['cash_impact']<0?'chip-neg':'chip-pos'; ?>">ক্যাশ <?php echo ($l['cash_impact']>0?'+':'').'৳'.number_format($l['cash_impact']); ?></span><?php endif; ?>
        </div>
        <?php if(!empty($l['description'])): ?><div class="txn-note"><i class="fas fa-quote-left" style="opacity:.4;font-size:8px"></i> <?php echo htmlspecialchars($l['description']); ?></div><?php endif; ?>
      </div>
      <div class="txn-side">
        <?php if(!empty($l['receipt_image'])): ?><img src="<?php echo $l['receipt_image']; ?>" loading="lazy" class="txn-thumb" onclick="showBig(this.src)" alt="রিসিট"><?php endif; ?>
        <div class="txn-act">
          <button class="mini-btn mb-edit" title="এডিট" onclick="openEditTxnModal(<?php echo $l['id']; ?>, '<?php echo $l['txn_type']; ?>', '<?php echo $l['txn_date']; ?>', <?php echo $l['amount']; ?>, <?php echo $l['charge_amount']; ?>, '<?php echo addslashes(htmlspecialchars($l['description'])); ?>')"><i class="fas fa-pen"></i></button>
          <button class="mini-btn mb-del" title="ডিলিট" onclick="openDeleteLedger(<?php echo $l['id']; ?>)"><i class="fas fa-trash"></i></button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <?php if ($total_pages > 1): ?>
  <div class="pager">
    <?php if ($current_page > 1): ?>
      <a href="?view=<?php echo $vid; ?>&page=1" class="pg" title="প্রথম পেজ"><i class="fas fa-angles-left"></i></a>
      <a href="?view=<?php echo $vid; ?>&page=<?php echo $current_page-1; ?>" class="pg" title="আগের মাস"><i class="fas fa-angle-left"></i></a>
    <?php endif; ?>
    <span class="pg-txt">পেজ <?php echo $current_page; ?> / <?php echo $total_pages; ?></span>
    <?php if ($current_page < $total_pages): ?>
      <a href="?view=<?php echo $vid; ?>&page=<?php echo $current_page+1; ?>" class="pg" title="পরের মাস"><i class="fas fa-angle-right"></i></a>
      <a href="?view=<?php echo $vid; ?>&page=<?php echo $total_pages; ?>" class="pg" title="শেষ পেজ"><i class="fas fa-angles-right"></i></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php else: // ============ মেইন কার্ড লিস্ট ============ ?>

<div class="sec-head">
  <div class="sec-title"><i class="fas fa-wallet"></i> ক্রেডিট কার্ড ম্যানেজার</div>
  <div class="sec-pill"><?php echo count($active_cards); ?> এক্টিভ · <?php echo count($inactive_cards); ?> নিষ্ক্রিয়</div>
</div>

<div class="stat-grid">
  <div class="stat stat-1"><div class="stat-ico"><i class="fas fa-circle-exclamation"></i></div><div class="stat-lbl">মোট বকেয়া</div><div class="stat-val" style="color:var(--c-red)">৳<?php echo number_format($g_total_due); ?></div></div>
  <div class="stat stat-2"><div class="stat-ico"><i class="fas fa-circle-check"></i></div><div class="stat-lbl">মোট পরিশোধ</div><div class="stat-val" style="color:var(--c-green)">৳<?php echo number_format($g_total_paid); ?></div></div>
  <div class="stat stat-3"><div class="stat-ico"><i class="fas fa-percent"></i></div><div class="stat-lbl">মোট চার্জ</div><div class="stat-val" style="color:var(--c-amber)">৳<?php echo number_format($g_total_charge); ?></div></div>
  <div class="stat stat-4"><div class="stat-ico"><i class="fas fa-coins"></i></div><div class="stat-lbl">মোট ব্যবহার</div><div class="stat-val" style="color:var(--brand-2)">৳<?php echo number_format($g_total_used); ?></div></div>
</div>

<?php if (empty($active_cards) && empty($inactive_cards)): ?>
<div class="empty" style="margin-top:16px">
  <div class="empty-ic"><i class="fas fa-credit-card"></i></div>
  <div class="empty-tx">এখনো কোনো কার্ড অ্যাড করা হয়নি।<br>নিচে "নতুন কার্ড" বাটনে ক্লিক করুন।</div>
</div>
<?php else: ?>

<?php if (!empty($active_cards)): ?>
<div class="sub-head"><span class="dot" style="background:var(--c-green)"></span> এক্টিভ কার্ডসমূহ <span class="cnt"><?php echo count($active_cards); ?></span></div>
<?php foreach ($active_cards as $c): $light = $c['light']; $sum = $c['summary']; ?>
<div class="card-row <?php echo $sum['is_overlimit']?'overlimit':''; ?>" onclick="location.href='credit_card.php?view=<?php echo $c['id']; ?>'">
  <?php if (!empty($c['card_image']) && file_exists($c['card_image'])): ?>
    <img src="<?php echo $c['card_image']; ?>" class="card-av" alt="">
  <?php else: ?>
    <div class="card-av-def"><i class="fas fa-credit-card"></i></div>
  <?php endif; ?>
  <div class="card-body-x">
    <div class="card-r1">
      <div class="card-nm"><?php echo htmlspecialchars($c['card_name']); ?></div>
      <div class="card-mask">**<?php echo $c['card_last4']; ?></div>
    </div>
    <div class="card-r2">
      <span class="k-lbl"><?php echo $sum['is_overlimit']?'ওভারলিমিট:':'বকেয়া:'; ?></span>
      <span class="k-due">৳<?php echo number_format($sum['is_overlimit'] ? $sum['overlimit_amt'] : $sum['current_due']); ?></span>
      <span class="k-lbl" style="margin-left:4px">অ্যাভেইলেবল:</span>
      <span class="k-avail" style="color:<?php echo $sum['is_overlimit']?'var(--txt-mut)':'var(--c-green)'; ?>">৳<?php echo number_format($sum['available_balance']); ?></span>
    </div>
  </div>
  <div class="card-lights" title="<?php echo $light['label']; ?>">
    <div class="lt <?php echo $light['color']==='green'?'on-green pulse':''; ?>"></div>
    <div class="lt <?php echo $light['color']==='yellow'?'on-yellow pulse':''; ?>"></div>
    <div class="lt <?php echo $light['color']==='red'?'on-red'.($light['pulse']?' pulse':''):''; ?>"></div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($inactive_cards)): ?>
<div class="sub-head"><span class="dot" style="background:var(--txt-mut)"></span> নিষ্ক্রিয় কার্ডসমূহ <span class="cnt"><?php echo count($inactive_cards); ?></span></div>
<?php foreach ($inactive_cards as $c): $sum = $c['summary']; ?>
<div class="card-row inactive" onclick="location.href='credit_card.php?view=<?php echo $c['id']; ?>'">
  <div class="card-av-def dead"><i class="fas fa-ban"></i></div>
  <div class="card-body-x">
    <div class="card-r1">
      <div class="card-nm"><?php echo htmlspecialchars($c['card_name']); ?></div>
      <div class="card-mask">**<?php echo $c['card_last4']; ?></div>
    </div>
    <div class="card-r2">
      <span class="k-lbl">বকেয়া:</span>
      <span class="k-due">৳<?php echo number_format($sum['current_due']); ?></span>
      <span class="k-lbl" style="margin-left:auto">নিষ্ক্রিয়</span>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>
<?php endif; ?>

</div><!-- /.wrap -->

<!-- ===== Bottom nav ===== -->
<?php if ($view_card): // সিঙ্গেল কার্ড ভিউ ?>
<div class="bottom-nav">
  <div class="bn-inner">
    <button class="bn" onclick="openTxnModal('purchase')"><span class="bn-ic g-pur"><i class="fas fa-bag-shopping"></i></span><span class="bn-lbl">কেনাকাটা</span></button>
    <button class="bn" onclick="openTxnModal('cash_advance')"><span class="bn-ic g-adv"><i class="fas fa-wallet"></i></span><span class="bn-lbl">ক্যাশ অ্যাড</span></button>
    <button class="bn" onclick="openTxnModal('bill_pay')"><span class="bn-ic g-bill"><i class="fas fa-money-bill-wave"></i></span><span class="bn-lbl">বিল পে</span></button>
    <button class="bn" onclick="toggleMoreMenu()"><span class="bn-ic g-more"><i class="fas fa-ellipsis"></i></span><span class="bn-lbl">আরও</span></button>
  </div>
</div>

<!-- Bottom sheet (More) -->
<div class="offcanvas offcanvas-bottom sheet" tabindex="-1" id="moreSheet" aria-labelledby="moreSheetLbl">
  <div class="grip"></div>
  <div class="sheet-title" id="moreSheetLbl">আরও অপশন</div>
  <div class="sheet-grid">
    <div class="sheet-item" onclick="closeMoreMenu();openTxnModal('min_pay')"><span class="sheet-ic ti-min"><i class="fas fa-receipt"></i></span><span class="sheet-lbl">মিনিমাম পে</span></div>
    <div class="sheet-item" onclick="closeMoreMenu();openTxnModal('full_pay')"><span class="sheet-ic ti-full"><i class="fas fa-check-double"></i></span><span class="sheet-lbl">ফুল পে</span></div>
    <div class="sheet-item" onclick="closeMoreMenu();openTxnModal('charge_pay')"><span class="sheet-ic ti-chg"><i class="fas fa-percent"></i></span><span class="sheet-lbl">চার্জ পে</span></div>
    <div class="sheet-item" onclick="closeMoreMenu();openEditCardModal()"><span class="sheet-ic" style="background:linear-gradient(135deg,#f59e0b,#b45309)"><i class="fas fa-pen-to-square"></i></span><span class="sheet-lbl">এডিট প্রোফাইল</span></div>
    <div class="sheet-item" onclick="closeMoreMenu();openUnmaskModal(<?php echo $view_card['id']; ?>)"><span class="sheet-ic ti-full"><i class="fas fa-eye"></i></span><span class="sheet-lbl">কার্ড নাম্বার</span></div>
    <div class="sheet-item" onclick="closeMoreMenu();openToggleStatus(<?php echo $view_card['id']; ?>)"><span class="sheet-ic" style="background:linear-gradient(135deg,#6366f1,#4338ca)"><i class="fas fa-power-off"></i></span><span class="sheet-lbl"><?php echo $view_card['status']==='active'?'নিষ্ক্রিয় করুন':'সক্রিয় করুন'; ?></span></div>
    <div class="sheet-item" onclick="closeMoreMenu();openDeleteCard(<?php echo $view_card['id']; ?>)"><span class="sheet-ic" style="background:linear-gradient(135deg,#f43f5e,#9f1239)"><i class="fas fa-trash"></i></span><span class="sheet-lbl">কার্ড ডিলিট</span></div>
    <a href="credit_card.php" class="sheet-item"><span class="sheet-ic g-more"><i class="fas fa-arrow-left"></i></span><span class="sheet-lbl">লিস্টে যান</span></a>
  </div>
</div>

<?php else: // মেইন কার্ড লিস্ট ?>
<div class="bottom-nav">
  <div class="bn-inner" style="justify-content:center;gap:60px">
    <a href="dashboard.php" class="bn" style="flex:0 0 auto"><span class="bn-ic g-home"><i class="fas fa-gauge-high"></i></span><span class="bn-lbl">ড্যাশবোর্ড</span></a>
    <button class="bn" style="flex:0 0 auto" onclick="openAddCardModal()"><span class="bn-ic g-add"><i class="fas fa-plus"></i></span><span class="bn-lbl">নতুন কার্ড</span></button>
  </div>
</div>
<?php endif; ?>

</div><!-- /.app-shell -->

<!-- ===== Add Card Modal ===== -->
<div class="modal fade app-modal" id="addCardModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><span class="m-ic"><i class="fas fa-plus"></i></span> নতুন কার্ড প্রোফাইল</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="add_card">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <div class="fld"><label class="fld-lbl">কার্ড নাম *</label><input type="text" name="card_name" class="form-control" placeholder="উদা:  ব্যাংক Amex" required></div>
          <div class="fld"><label class="fld-lbl">কার্ড নাম্বার *</label><input type="text" name="card_number" class="form-control" placeholder="4532 1234 5678 9012" required maxlength="25"><div class="fld-help">এনক্রিপ্ট হয়ে সেভ হবে।</div></div>
          <div class="fld-row">
            <div class="fld"><label class="fld-lbl">৬-ডিজিট পিন</label><input type="password" name="card_pin" class="form-control" placeholder="••••••" maxlength="6"></div>
            <div class="fld"><label class="fld-lbl">মেয়াদ (MM/YY)</label><input type="text" name="card_expiry" class="form-control" placeholder="12/28" maxlength="7"></div>
          </div>
          <div class="fld"><label class="fld-lbl">ক্রেডিট লিমিট *</label><input type="number" name="credit_limit" class="form-control" step="0.01" value="100000" required><div class="fld-help">সর্বোচ্চ কত ধার নেওয়া যাবে।</div></div>
          <div class="fld-row">
            <div class="fld"><label class="fld-lbl">বিলিং ডেট (১-৩১) *</label><input type="number" name="billing_date" class="form-control" min="1" max="31" value="1" required></div>
            <div class="fld"><label class="fld-lbl">গ্রেস পিরিয়ড (দিন) *</label><input type="number" name="grace_days" class="form-control" min="1" max="60" value="15" required><div class="fld-help">বিলের পর কতদিন সময়।</div></div>
          </div>
          <div class="fld"><label class="fld-lbl">কার্ডের ছবি</label><input type="file" name="card_image" class="form-control fld-file" accept="image/*"></div>
          <div class="fld"><label class="fld-lbl">নোটস</label><textarea name="notes" class="form-control" placeholder="অতিরিক্ত তথ্য…"></textarea></div>
          <div class="modal-foot">
            <button type="button" class="btn3d b-ghost" data-bs-dismiss="modal"><i class="fas fa-xmark"></i> বাতিল</button>
            <button type="submit" class="btn3d b-primary"><i class="fas fa-floppy-disk"></i> সংরক্ষণ</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($view_card): ?>
<!-- ===== Edit Card Modal ===== -->
<div class="modal fade app-modal" id="editCardModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><span class="m-ic"><i class="fas fa-pen-to-square"></i></span> কার্ড প্রোফাইল এডিট</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="update_card">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="card_id" value="<?php echo $view_card['id']; ?>">
          <div class="fld"><label class="fld-lbl">কার্ড নাম *</label><input type="text" name="card_name" class="form-control" value="<?php echo htmlspecialchars($view_card['card_name']); ?>" required></div>
          <div class="fld-row">
            <div class="fld"><label class="fld-lbl">মেয়াদ (MM/YY)</label><input type="text" name="card_expiry" class="form-control" value="<?php echo htmlspecialchars($view_card['card_expiry'] ?? ''); ?>" maxlength="7"></div>
            <div class="fld"><label class="fld-lbl">ক্রেডিট লিমিট *</label><input type="number" name="credit_limit" class="form-control" step="0.01" value="<?php echo $view_card['credit_limit']; ?>" required></div>
          </div>
          <div class="fld-row">
            <div class="fld"><label class="fld-lbl">বিলিং ডেট (১-৩১) *</label><input type="number" name="billing_date" class="form-control" min="1" max="31" value="<?php echo $view_card['billing_date']; ?>" required></div>
            <div class="fld"><label class="fld-lbl">গ্রেস পিরিয়ড (দিন) *</label><input type="number" name="grace_days" class="form-control" min="1" max="60" value="<?php echo $view_card['grace_days']; ?>" required></div>
          </div>
          <div class="fld"><label class="fld-lbl">কার্ডের ছবি (পরিবর্তন)</label><input type="file" name="card_image" class="form-control fld-file" accept="image/*"><div class="fld-help">খালি রাখলে পুরোনো ছবি বহাল থাকবে।</div></div>
          <div class="fld"><label class="fld-lbl">নোটস</label><textarea name="notes" class="form-control"><?php echo htmlspecialchars($view_card['notes'] ?? ''); ?></textarea></div>
          <div class="modal-foot">
            <button type="button" class="btn3d b-ghost" data-bs-dismiss="modal"><i class="fas fa-xmark"></i> বাতিল</button>
            <button type="submit" class="btn3d b-amber"><i class="fas fa-floppy-disk"></i> আপডেট</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===== Transaction Modal ===== -->
<div class="modal fade app-modal" id="txnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><span class="m-ic"><i class="fas fa-arrow-right-arrow-left"></i></span> <span id="txnModalTitle">ট্রানজেকশন</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="add_transaction">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="card_id" value="<?php echo $view_card['id']; ?>">
          <input type="hidden" name="txn_type" id="txnType" value="">
          <input type="hidden" name="current_page" value="<?php echo $current_page; ?>">
          <div class="txn-hint" id="txnDescBadge"></div>
          <div class="fld-row">
            <div class="fld"><label class="fld-lbl">তারিখ *</label><input type="date" name="txn_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="fld"><label class="fld-lbl">অ্যামাউন্ট *</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
          </div>
          <div class="fld" id="chargeGrp"><label class="fld-lbl">চার্জ অ্যামাউন্ট (যদি থাকে)</label><input type="number" name="charge_amount" class="form-control" step="0.01" min="0" value="0"></div>
          <div class="fld"><label class="fld-lbl">নোট / বিবরণ (ঐচ্ছিক)</label><textarea name="description" class="form-control" placeholder="এখানে কিছু লিখতে পারেন বা খালি রাখতে পারেন..."></textarea></div>
          <div class="fld"><label class="fld-lbl">রিসিট/বিলের ছবি</label><input type="file" name="receipt_image" class="form-control fld-file" accept="image/*,application/pdf"></div>
          <div class="modal-foot">
            <button type="button" class="btn3d b-ghost" data-bs-dismiss="modal"><i class="fas fa-xmark"></i> বাতিল</button>
            <button type="submit" class="btn3d b-green"><i class="fas fa-check"></i> এন্ট্রি করুন</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===== Edit Transaction Modal ===== -->
<div class="modal fade app-modal" id="editTxnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><span class="m-ic"><i class="fas fa-pen"></i></span> এডিট ট্রানজেকশন</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form method="POST">
          <input type="hidden" name="action" value="update_transaction">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="card_id" value="<?php echo $view_card['id']; ?>">
          <input type="hidden" name="ledger_id" id="editTxnId" value="">
          <input type="hidden" name="txn_type" id="editTxnType" value="">
          <input type="hidden" name="current_page" value="<?php echo $current_page; ?>">
          <div class="fld-row">
            <div class="fld"><label class="fld-lbl">তারিখ *</label><input type="date" name="txn_date" id="editTxnDate" class="form-control" required></div>
            <div class="fld"><label class="fld-lbl">অ্যামাউন্ট *</label><input type="number" name="amount" id="editTxnAmt" class="form-control" step="0.01" required></div>
          </div>
          <div class="fld"><label class="fld-lbl">চার্জ অ্যামাউন্ট</label><input type="number" name="charge_amount" id="editTxnCharge" class="form-control" step="0.01"></div>
          <div class="fld"><label class="fld-lbl">বিবরণ</label><textarea name="description" id="editTxnDesc" class="form-control"></textarea></div>
          <div class="modal-foot">
            <button type="button" class="btn3d b-ghost" data-bs-dismiss="modal"><i class="fas fa-xmark"></i> বাতিল</button>
            <button type="submit" class="btn3d b-amber"><i class="fas fa-floppy-disk"></i> আপডেট করুন</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===== Unmask Modal ===== -->
<div class="modal fade app-modal" id="unmaskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><span class="m-ic"><i class="fas fa-eye"></i></span> কার্ড নাম্বার ও পিন</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="reveal-box">
          <div class="reveal-lbl">কার্ড নাম্বার</div>
          <div id="unmaskNum" class="reveal-num">****</div>
          <div class="reveal-lbl">পিন</div>
          <div id="unmaskPin" class="reveal-pin">****</div>
        </div>
        <div class="modal-foot"><button type="button" class="btn3d b-ghost" data-bs-dismiss="modal"><i class="fas fa-xmark"></i> বন্ধ করুন</button></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== Password Modal ===== -->
<div class="modal fade app-modal" id="pwModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body" style="text-align:center;padding:24px 22px">
        <div class="pw-ic"><i class="fas fa-shield-halved"></i></div>
        <div style="font-size:14px;font-weight:800;color:var(--txt)" id="pwTitle">সিকিউরিটি যাচাই</div>
        <div style="font-size:11px;color:var(--txt-2);margin:6px 0 14px;line-height:1.5" id="pwSub">Admin পাসওয়ার্ড দিন</div>
        <input type="password" id="pwInp" class="form-control pw-inp" placeholder="••••••••" autocomplete="off">
        <div class="pw-err" id="pwErr"></div>
        <div class="modal-foot">
          <button type="button" class="btn3d b-ghost" onclick="closePwModal()">বাতিল</button>
          <button type="button" class="btn3d b-danger" id="pwOkBtn" onclick="pwConfirm()">নিশ্চিত করুন</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== Image Viewer ===== -->
<div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" style="display:flex;justify-content:center">
    <img id="bigImg" alt="">
  </div>
</div>

<!-- Bootstrap 5.3.8 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── থিম (Bootstrap 5.3 data-bs-theme) ──
function applyTheme(t){
  document.documentElement.setAttribute('data-bs-theme', t);
  const i=document.getElementById('themeIco');
  if(i) i.className = (t==='dark') ? 'fas fa-moon' : 'fas fa-sun';
}
function toggleTheme(){
  const cur=document.documentElement.getAttribute('data-bs-theme');
  const next=(cur==='dark')?'light':'dark';
  localStorage.setItem('cc_theme', next);
  applyTheme(next);
}
(function(){ applyTheme(localStorage.getItem('cc_theme')==='light'?'light':'dark'); })();

// ── Bootstrap helpers ──
const _modal = id => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
function closeModal(id){ const el=document.getElementById(id); if(el){ const m=bootstrap.Modal.getInstance(el); if(m) m.hide(); } }

// ── More sheet (offcanvas) ──
function _sheet(){ const el=document.getElementById('moreSheet'); return el?bootstrap.Offcanvas.getOrCreateInstance(el):null; }
function toggleMoreMenu(){ const s=_sheet(); if(s) s.toggle(); }
function closeMoreMenu(){ const s=_sheet(); if(s) s.hide(); }

// ── ছবি জুম ──
function showBig(src){ document.getElementById('bigImg').src=src; _modal('imgModal').show(); }

// ── কার্ড মডাল ──
function openAddCardModal(){ _modal('addCardModal').show(); }
function openEditCardModal(){ _modal('editCardModal').show(); }

// ── ট্রানজেকশন মডাল (Add) ──
const TXN_TYPES = {
  'purchase':     { title:'🛍️ কার্ড দিয়ে কেনাকাটা', desc:'<b style="color:var(--c-red)">কার্ডের বকেয়া বাড়বে</b> · ক্যাশ বক্সে কোনো টাকা যোগ হবে না।', hideCharge:false },
  'cash_advance': { title:'💰 কার্ড থেকে ক্যাশ অ্যাড', desc:'<b style="color:var(--c-red)">কার্ডের বকেয়া বাড়বে</b> · ক্যাশ বক্সে মূল টাকা যোগ হবে।', hideCharge:false },
  'bill_pay':     { title:'💳 বিল পরিশোধ', desc:'<b style="color:var(--c-green)">কার্ডের বকেয়া কমবে</b> · ক্যাশ বক্স থেকে টাকা মাইনাস হবে।', hideCharge:false },
  'min_pay':      { title:'📋 মিনিমাম বিল পরিশোধ', desc:'<b style="color:var(--c-green)">কার্ডের বকেয়া কমবে</b> · ক্যাশ বক্স থেকে টাকা মাইনাস হবে।', hideCharge:false },
  'full_pay':     { title:'✅ ফুল আউটস্ট্যান্ডিং পরিশোধ', desc:'<b style="color:var(--c-green)">কার্ডের বকেয়া কমবে</b> · ক্যাশ বক্স থেকে টাকা মাইনাস হবে।', hideCharge:false },
  'charge_pay':   { title:'⚡ চার্জ পরিশোধ', desc:'কার্ডের বকেয়া পরিবর্তন হবে না · ক্যাশ বক্স থেকে টাকা মাইনাস হবে।', hideCharge:true }
};
function openTxnModal(type){
  closeMoreMenu();
  const cfg=TXN_TYPES[type]; if(!cfg) return;
  document.getElementById('txnType').value=type;
  document.getElementById('txnModalTitle').innerHTML=cfg.title;
  document.getElementById('txnDescBadge').innerHTML=cfg.desc;
  document.getElementById('chargeGrp').style.display=cfg.hideCharge?'none':'block';
  _modal('txnModal').show();
}
function openEditTxnModal(id, type, date, amount, charge, desc){
  document.getElementById('editTxnId').value=id;
  document.getElementById('editTxnType').value=type;
  document.getElementById('editTxnDate').value=date;
  document.getElementById('editTxnAmt').value=amount;
  document.getElementById('editTxnCharge').value=charge;
  document.getElementById('editTxnDesc').value=desc;
  _modal('editTxnModal').show();
}

// ── পাসওয়ার্ড মডাল ──
let pwState={action:'',data:{}};
function _openPw(title,sub,btnTxt){
  document.getElementById('pwTitle').textContent=title;
  document.getElementById('pwSub').innerHTML=sub;
  document.getElementById('pwOkBtn').innerHTML=btnTxt;
  document.getElementById('pwInp').value='';
  document.getElementById('pwErr').textContent='';
  _modal('pwModal').show();
}
function closePwModal(){ closeModal('pwModal'); }
const _pwEl=document.getElementById('pwModal');
if(_pwEl) _pwEl.addEventListener('shown.bs.modal', ()=>{ const i=document.getElementById('pwInp'); if(i) i.focus(); });

function openUnmaskModal(cid){ closeMoreMenu(); pwState={action:'unmask_card',data:{card_id:cid}}; _openPw('🔓 কার্ড নাম্বার দেখুন','Admin পাসওয়ার্ড দিন<br><small style="color:var(--c-red)">শুধু আপনিই দেখতে পারবেন</small>','দেখুন'); }
function openToggleStatus(cid){ closeMoreMenu(); pwState={action:'toggle_status',data:{card_id:cid}}; _openPw('⚡ স্ট্যাটাস পরিবর্তন','Admin পাসওয়ার্ড দিন<br><small style="color:var(--c-red)">কার্ড সক্রিয়/নিষ্ক্রিয় হবে</small>','পরিবর্তন করুন'); }
function openDeleteCard(cid){ closeMoreMenu(); pwState={action:'delete_card',data:{card_id:cid}}; _openPw('⚠️ কার্ড ডিলিট','Admin পাসওয়ার্ড দিন<br><small style="color:var(--c-red)">পুরো কার্ড ও সব ট্রানজেকশন মুছে যাবে</small>','ডিলিট করুন'); }
function openDeleteLedger(lid){ pwState={action:'delete_ledger',data:{ledger_id:lid}}; _openPw('🗑️ এন্ট্রি ডিলিট','Admin পাসওয়ার্ড দিন<br><small style="color:var(--c-red)">এই ট্রানজেকশন মুছে যাবে</small>','ডিলিট করুন'); }

function pwConfirm(){
  const pass=document.getElementById('pwInp').value.trim();
  const btn=document.getElementById('pwOkBtn');
  const err=document.getElementById('pwErr');
  if(!pass){ err.textContent='পাসওয়ার্ড লিখুন।'; return; }
  btn.textContent='যাচাই হচ্ছে…'; btn.disabled=true;
  const fd=new FormData();
  fd.append('pass',pass);
  fd.append('ajax_action', pwState.action);
  fd.append('csrf_token', '<?php echo $_SESSION["csrf_token"]; ?>');
  for(let k in pwState.data) fd.append(k, pwState.data[k]);

  fetch('credit_card.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
      btn.textContent='নিশ্চিত করুন'; btn.disabled=false;
      if(res.status==='success'){
        if(pwState.action==='unmask_card'){
          closePwModal();
          document.getElementById('unmaskNum').textContent=(res.card_number||'').replace(/(.{4})/g,'$1 ').trim();
          document.getElementById('unmaskPin').textContent=res.card_pin||'(সেট করা নেই)';
          setTimeout(()=>_modal('unmaskModal').show(),350);
        } else if(pwState.action==='delete_card'){
          closePwModal(); showToast(res.message,true);
          setTimeout(()=>location.href='credit_card.php',1200);
        } else {
          closePwModal(); showToast(res.message,true);
          setTimeout(()=>location.reload(),1000);
        }
      } else { err.textContent=res.message; }
    })
    .catch(()=>{ btn.textContent='নিশ্চিত করুন'; btn.disabled=false; err.textContent='সার্ভার সমস্যা।'; });
}

document.addEventListener('keydown',e=>{
  if(e.key==='Enter'){
    const pw=document.getElementById('pwModal');
    if(pw && pw.classList.contains('show')){ e.preventDefault(); pwConfirm(); }
  }
});

function showToast(msg,ok){
  const t=document.createElement('div');
  t.className='toast-pop'; t.textContent=msg;
  t.style.background=ok?'linear-gradient(135deg,#10b981,#047857)':'linear-gradient(135deg,#f43f5e,#be123c)';
  document.body.appendChild(t);
  setTimeout(()=>t.remove(),2500);
}
</script>
</body>
</html>
