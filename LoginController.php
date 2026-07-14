<?php
declare(strict_types=1);

require_once __DIR__ . '/../Models/LoginModel.php';
require_once __DIR__ . '/../Services/CaptchaService.php';
require_once __DIR__ . '/../Services/SessionGuard.php';
require_once __DIR__ . '/../Services/DeviceInfo.php';
require_once __DIR__ . '/../Services/LoginLogger.php';

// ═══════════════════════════════════════════════════════════════
// PHP 8.1 ENUM — LoginResultType
// ═══════════════════════════════════════════════════════════════
enum LoginResultType: string {
    case Success        = 'success';
    case WrongPassword  = 'wrong_password';
    case AccountLocked  = 'account_locked';
    case AccountBlocked = 'account_blocked';
    case UserNotFound   = 'user_not_found';
    case SetupRequired  = 'setup_required';
    case CsrfInvalid    = 'csrf_invalid';
    case CaptchaInvalid = 'captcha_invalid';
    case SystemError    = 'system_error';
}

// ═══════════════════════════════════════════════════════════════
// PHP 8.1 READONLY CLASS — LoginResult
// ═══════════════════════════════════════════════════════════════
readonly class LoginResult {
    public function __construct(
        public LoginResultType $type,
        public string          $htmlMessage,
        public ?array          $userData = null,
    ) {}
    public function isSuccess(): bool       { return $this->type === LoginResultType::Success; }
    public function isSetupRequired(): bool { return $this->type === LoginResultType::SetupRequired; }
}

// ═══════════════════════════════════════════════════════════════
// CONTROLLER — LoginController
// ═══════════════════════════════════════════════════════════════
class LoginController {

    private \PDO                    $db;
    private LoginModelInterface     $loginModel;
    private CaptchaServiceInterface $captchaService;
    private LoginLogger             $loginLogger;
    private DeviceInfo              $deviceInfo;

    public function __construct(\PDO $db) {
        $this->db             = $db;
        $this->loginModel     = new LoginModel($db);
        $this->captchaService = new CaptchaService();
        $this->loginLogger    = new LoginLogger();
        $this->deviceInfo     = DeviceInfo::fromRequest();
        $this->bootSession();
        date_default_timezone_set('Asia/Dhaka');
    }

    /* ── Session ──────────────────────────────────────────────── */
    private function bootSession(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    /* ── Redirect If Logged In ────────────────────────────────── */
    public function redirectIfLoggedIn(): void {
        if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
            header('Location: dashboard.php'); exit;
        }
    }

    /* ── CSRF ─────────────────────────────────────────────────── */
    public function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_login_token'])) {
            $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_login_token'];
    }

    private function verifyCsrfToken(): bool {
        $in  = trim((string)($_POST['csrf_token'] ?? ''));
        $str = (string)($_SESSION['csrf_login_token'] ?? '');
        return $str !== '' && $in !== '' && hash_equals($str, $in);
    }

    /* ── View Data ────────────────────────────────────────────── */
    public function getViewData(): array {
        return [
            'siteNoticeText' => $this->loginModel->getSiteNotice(),
            'sliderPosts'    => $this->loginModel->getSliderPosts(),
            'csrfToken'      => $this->generateCsrfToken(),
        ];
    }

    /* ── Handle POST ──────────────────────────────────────────── */
    public function handlePost(): ?LoginResult {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['login_btn'])) return null;

        // 1. CSRF
        if (!$this->verifyCsrfToken()) {
            return new LoginResult(LoginResultType::CsrfInvalid,
                $this->errHtml('❌ সিকিউরিটি টোকেন অবৈধ! পেজ রিফ্রেশ করুন।'));
        }

        // 2. Cloudflare CAPTCHA
        if (!$this->captchaService->verify()) {
            return new LoginResult(LoginResultType::CaptchaInvalid,
                $this->errHtml($this->captchaService->getErrorMessage()));
        }

        // 3. Input
        $id   = trim((string)($_POST['username'] ?? ''));
        $pass = trim((string)($_POST['password'] ?? ''));
        if ($id === '' || $pass === '') {
            return new LoginResult(LoginResultType::UserNotFound,
                $this->errHtml('❌ ইউজার আইডি এবং পাসওয়ার্ড দিন!'));
        }

        try {
            $user = $this->loginModel->findByIdentifier($id);
            if ($user === null) {
                $this->loginLogger->record('LOGIN_FAILED', $id, $this->deviceInfo, 'User not found');
                return new LoginResult(LoginResultType::UserNotFound,
                    $this->errHtml('❌ আইডি বা ইমেইল পাওয়া যায়নি!'));
            }

            // 4. Lock check
            $lockResult = $this->checkLock($user);
            if ($lockResult !== null) return $lockResult;

            // 5. Password
            $stored    = (string)($user['password'] ?? '');
            $passOk    = password_verify($pass, $stored) || $pass === $stored;
            if (!$passOk) return $this->processFailedAttempt($user);

            // 6. Block check
            $blockResult = $this->checkBlock($user);
            if ($blockResult !== null) return $blockResult;

            // 7. Success
            return $this->processSuccess($user);

        } catch (\Throwable $e) {
            $this->loginModel->logError("handlePost failed: {$id}", $e);
            return new LoginResult(LoginResultType::SystemError,
                $this->errHtml('❌ সিস্টেম এরর! কিছুক্ষণ পর চেষ্টা করুন।'));
        }
    }

    /* ── Lock Checker ─────────────────────────────────────────── */
    private function checkLock(array $u): ?LoginResult {
        if (empty($u['lock_until'])) return null;
        $ts = strtotime((string)$u['lock_until']);
        if ($ts === false || $ts <= time()) return null;
        $until = date('h:i A', $ts);
        return new LoginResult(LoginResultType::AccountLocked, $this->lockHtml($until), $u);
    }

    /* ── Block Checker ────────────────────────────────────────── */
    private function checkBlock(array $u): ?LoginResult {
        if (($u['status'] ?? 'active') !== 'blocked') return null;
        $end = (string)($u['block_end'] ?? '');
        if ($end !== '') {
            if ($this->loginModel->autoUnblockIfExpired((int)$u['id'], $end)) return null;
            $t = date('d M, h:i A', strtotime($end));
            $html = $this->blockHtml("❌ অ্যাক্সেস সাময়িকভাবে স্থগিত।", "<br>🕒 আনব্লক হবে: <b>{$t}</b>");
            return new LoginResult(LoginResultType::AccountBlocked, $html, $u);
        }
        return new LoginResult(LoginResultType::AccountBlocked,
            $this->blockHtml("❌ অ্যাক্সেস সাময়িকভাবে স্থগিত রাখা হয়েছে।", ''), $u);
    }

    /* ── Failed Attempt ───────────────────────────────────────── */
    private function processFailedAttempt(array $u): LoginResult {
        $userId  = (int)$u['id'];
        $account = (string)($u['username'] ?? $u['id']);
        $cur     = (int)($u['failed_attempts'] ?? 0) + 1;
        $max     = LoginModel::getMaxFailedAttempts();
        if ($cur >= $max) {
            $lockUntil = date('Y-m-d H:i:s', strtotime('+' . LoginModel::getLockDurationMinutes() . ' minutes'));
            $this->loginModel->lockAccount($userId, $lockUntil);
            $this->loginLogger->record('LOGIN_LOCKED', $account, $this->deviceInfo, 'Too many wrong passwords');
            return new LoginResult(LoginResultType::AccountLocked,
                $this->lockHtml(date('h:i A', strtotime($lockUntil))), $u);
        }
        $this->loginModel->incrementFailedAttempts($userId);
        $this->loginLogger->record('LOGIN_FAILED', $account, $this->deviceInfo, "Wrong password (attempt {$cur})");
        $left = $max - $cur;
        $html = "<div style='padding:12px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;color:#ef4444;font-weight:bold;font-size:13px;text-align:center;'>
            ❌ পাসওয়ার্ড ভুল! আরও <span style='font-size:18px;color:#991b1b;font-weight:900;'>{$left}</span> বার ভুল দিলে অ্যাকাউন্ট লক হবে।
        </div>";
        return new LoginResult(LoginResultType::WrongPassword, $html, $u);
    }

    /* ── Success ──────────────────────────────────────────────── */
    private function processSuccess(array $u): LoginResult {
        $userId = (int)$u['id'];
        $this->loginModel->resetFailedAttempts($userId);
        if (isset($u['is_verified']) && (int)$u['is_verified'] === 0) {
            $_SESSION['setup_id']       = $userId;
            $_SESSION['setup_username'] = (string)($u['username'] ?? '');
            return new LoginResult(LoginResultType::SetupRequired, '', $u);
        }

        // সেশন-ফিক্সেশন ঠেকাতে নতুন সেশন আইডি (আগের ডেটা রক্ষা করে)।
        session_regenerate_id(true);

        $_SESSION['loggedin']    = true;
        $_SESSION['user_id']     = $userId;
        $_SESSION['role']        = (string)($u['role']        ?? 'user');
        $_SESSION['username']    = (string)($u['username']    ?? '');
        $_SESSION['email']       = (string)($u['email']       ?? '');
        $_SESSION['profile_pic'] = (string)($u['profile_pic'] ?? 'default_user.png');

        // Single Active Session — নতুন টোকেন DB + সেশনে বসাও (পুরোনো ডিভাইস বাতিল)।
        SessionGuard::issueToken($this->db, $userId);

        $this->loginModel->updateLastLogin($userId);

        // Login_Log.txt-এ সফল লগইন রেকর্ড।
        $this->loginLogger->record(
            'LOGIN_SUCCESS',
            (string)($u['username'] ?? $userId),
            $this->deviceInfo,
            'Password login'
        );

        unset($_SESSION['csrf_login_token']);
        return new LoginResult(LoginResultType::Success, '', $u);
    }

    /* ── HTML Builders ────────────────────────────────────────── */
    private function errHtml(string $msg): string {
        $s = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        return "<div style='padding:12px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;color:#ef4444;font-weight:bold;font-size:13px;text-align:center;'>{$s}</div>";
    }

    private function lockHtml(string $unlockTime): string {
        return "
        <div style='background:#fef2f2;border:2px solid #ef4444;border-radius:14px;padding:18px;text-align:center;box-shadow:0 4px 10px rgba(239,68,68,0.2);'>
            <div style='font-size:36px;margin-bottom:8px;'>🔒</div>
            <div style='color:#991b1b;font-size:16px;font-weight:900;margin-bottom:8px;text-transform:uppercase;'>অ্যাকাউন্ট লক!</div>
            <p style='color:#b91c1c;font-size:12px;font-weight:bold;margin:0 0 12px;line-height:1.6;'>পরপর ৩ বার ভুল পাসওয়ার্ড দেওয়ায় অ্যাকাউন্ট সাময়িকভাবে লক হয়েছে।</p>
            <div style='background:#fff;color:#1d4ed8;font-weight:900;padding:8px;border-radius:8px;margin-bottom:12px;border:1px solid #bfdbfe;font-size:13px;'>
                🕒 আনলক হবে: {$unlockTime}
            </div>
            <a href='auth/otp_auth.php' style='color:#2563eb;text-decoration:none;background:#eff6ff;padding:7px 14px;border-radius:20px;border:1px solid #bfdbfe;font-size:12px;font-weight:900;display:inline-block;'>
                <i class='fas fa-key'></i> পাসওয়ার্ড রিসেট করুন
            </a>
        </div>";
    }

    private function blockHtml(string $mainMsg, string $extra): string {
        return "
        <div style='padding:12px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;font-size:13px;font-weight:bold;'>
            <p style='color:#ef4444;margin:0 0 4px;font-weight:900;'>সাময়িক অসুবিধার জন্য দুঃখিত।</p>
            <p style='color:#7f1d1d;margin:0;'>{$mainMsg}{$extra}</p>
        </div>";
    }
}
