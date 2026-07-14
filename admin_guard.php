<?php
/**
 * =====================================================================
 *  সাদা-কালো ফ্যাশন — Admin Auth Guard (admin_guard.php)
 *  ---------------------------------------------------------------------
 *  কাজ: যে পেজ শুধু Admin দেখতে পারবে, সেই পেজের একদম উপরে একবার
 *       এই ফাইলটি require করুন। লগইন/Admin না হলে ভেতরে ঢুকতে দেবে না।
 *
 *  ব্যবহার (পেজের সবচেয়ে উপরে, যেকোনো HTML/echo এর আগে):
 *      <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/admin_guard.php'; ?>
 *
 *  এই গার্ড আপনার বর্তমান সেশন কনভেনশন মেনে চলে:
 *      $_SESSION['loggedin']      === true
 *      $_SESSION['role']          === 'admin'
 *      $_SESSION['last_activity'] (২০ মিনিট নিষ্ক্রিয়তা = টাইমআউট)
 *
 *  AJAX রিকোয়েস্ট ($_POST['ajax_action'] থাকলে) হলে HTML না দেখিয়ে
 *  JSON ফেরত দেয় — যাতে আপনার fetch/ajax কোড ঠিকমতো বোঝে।
 * =====================================================================
 */

declare(strict_types=1);

/* ১) সেশন চালু (আগে চালু না থাকলে) ------------------------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* পেজ যেন কখনো ক্যাশ না হয় (লগআউটের পর Back চাপলেও ডাটা দেখাবে না) ---- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* এই রিকোয়েস্টটি কি AJAX? ------------------------------------------- */
$isAjaxRequest = isset($_POST['ajax_action']);

/* লগইন পেজের ঠিকানা (রুট লেভেলের index.php) -------------------------- */
$loginUrl = '/index.php';

/* ছোট সহায়ক: অ্যাক্সেস আটকে দেওয়া -------------------------------------- */
function skf_block_access(bool $isAjax, string $status, string $message, string $loginUrl): void
{
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['status' => $status, 'message' => $message]);
        exit;
    }
    // সাধারণ পেজ হলে: বার্তা দেখিয়ে লগইন পেজে পাঠাই
    echo '<script>alert(' . json_encode($message) . ');'
       . 'window.location.href=' . json_encode($loginUrl) . ';</script>';
    exit;
}

/* ২) সেশন টাইমআউট (২০ মিনিট = 1200 সেকেন্ড) ------------------------- */
$lastActivity = $_SESSION['last_activity'] ?? null;
if ($lastActivity !== null && is_int($lastActivity) && (time() - $lastActivity > 1200)) {
    session_unset();
    session_destroy();
    skf_block_access($isAjaxRequest, 'session_expired', 'Session Expired!', $loginUrl);
}
$_SESSION['last_activity'] = time();

/* ৩) লগইন + Admin রোল চেক ------------------------------------------ */
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAdmin    = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if (!$isLoggedIn || !$isAdmin) {
    skf_block_access($isAjaxRequest, 'access_denied', 'Access Denied!', $loginUrl);
}

/* এখানে পৌঁছানো মানে: ইউজার লগইন করা ও Admin — পেজ চলতে পারবে। */
