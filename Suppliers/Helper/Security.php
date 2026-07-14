<?php
declare(strict_types=1);
if (!defined('SK_APP')) { http_response_code(403); exit('403 Forbidden'); }

/**
 * সেশন, CSRF, রোল-অনুমতি ও অ্যাকাউন্ট-ব্লক নিয়ন্ত্রণ — সব এক জায়গায়।
 */
final class Security
{
    private const IDLE_LIMIT   = 1200;          // ২০ মিনিট
    private const LOGIN_PAGE    = '/index.php';

    /** প্রতি entry ফাইলের শুরুতে একবার কল করুন */
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
            session_start();
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: text/html; charset=utf-8');
    }

    public static function requireLogin(): void
    {
        if (empty($_SESSION['loggedin'])) {
            header('Location: ' . self::LOGIN_PAGE);
            exit;
        }
    }

    public static function role(): string
    {
        return (string)($_SESSION['role'] ?? 'viewer');
    }

    public static function username(): string
    {
        return (string)($_SESSION['username'] ?? self::role());
    }

    /** নির্দিষ্ট রোল না থাকলে redirect */
    public static function requireRole(array $roles, string $redirect = '/Suppliers/suppliers.php'): void
    {
        if (!in_array(self::role(), $roles, true)) {
            header('Location: ' . $redirect);
            exit;
        }
    }

    public static function hasRole(array $roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    /* ---------------- CSRF ---------------- */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    public static function verifyCsrf(): bool
    {
        $sent = (string)($_POST['csrf_token'] ?? '');
        return $sent !== '' && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $sent);
    }

    /** আউটপুট এস্কেপ শর্টকাট */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * নিষ্ক্রিয়তা-টাইমআউট + অ্যাকাউন্ট ব্লক যাচাই।
     * লগইন করা থাকলে প্রতি রিকোয়েস্টে কল করুন।
     */
    public static function enforceSession(\PDO $conn): void
    {
        // ২০ মিনিট নিষ্ক্রিয় → লগআউট
        if (isset($_SESSION['loggedin'], $_SESSION['last_activity'])
            && (time() - (int)$_SESSION['last_activity']) >= self::IDLE_LIMIT) {

            $user = self::username();
            if ($user !== '') {
                try {
                    $conn->prepare("UPDATE users SET last_active = '2000-01-01 00:00:00' WHERE username = ?")
                         ->execute([$user]);
                } catch (\PDOException $e) { /* ignore */ }
            }
            session_unset();
            session_destroy();
            header('Location: ' . self::LOGIN_PAGE . '?status=timeout');
            exit;
        }
        $_SESSION['last_activity'] = time();

        if (empty($_SESSION['loggedin'])) {
            return;
        }

        $user = self::username();
        $role = self::role();
        if ($user === '') {
            return;
        }

        try {
            $conn->prepare("UPDATE users SET last_active = NOW() WHERE username = ?")->execute([$user]);

            $stmt = $conn->prepare("SELECT status, block_start, block_end FROM users WHERE username = ?");
            $stmt->execute([$user]);
            $u = $stmt->fetch();
        } catch (\PDOException $e) {
            Logger::error('Session enforcement query failed', $e);
            return;
        }

        if (!is_array($u)) {
            return;
        }

        $now        = date('Y-m-d H:i:s');
        $blocked    = (($u['status'] ?? '') === 'blocked');
        $blockStart = is_string($u['block_start'] ?? null) ? (string)$u['block_start'] : '';
        $blockEnd   = is_string($u['block_end'] ?? null)   ? (string)$u['block_end']   : '';

        if (!$blocked && $blockStart !== '' && $blockEnd !== ''
            && $now >= $blockStart && $now <= $blockEnd) {
            $blocked = true;
        }

        if ($blocked && $role !== 'admin') {
            http_response_code(403);
            exit('<div style="font-family:sans-serif;background:#0f172a;color:#fff;height:100vh;'
                . 'display:flex;align-items:center;justify-content:center;text-align:center;padding:20px">'
                . '<div style="background:#fff;color:#1e293b;padding:36px;border-radius:14px;max-width:400px;'
                . 'border-top:9px solid #ef4444"><h1 style="color:#ef4444;margin:0;font-size:22px">⛔ Sada Kalo Fashion</h1>'
                . '<p style="color:#475569;line-height:1.6;margin:18px 0">সাময়িক অসুবিধার জন্য আপনার অ্যাক্সেস '
                . 'সাময়িকভাবে স্থগিত রাখা হয়েছে।</p>'
                . '<a href="/logout.php" style="display:block;background:#ef4444;color:#fff;padding:11px;'
                . 'text-decoration:none;border-radius:8px;font-weight:bold">লগআউট</a></div></div>');
        }
    }
}
