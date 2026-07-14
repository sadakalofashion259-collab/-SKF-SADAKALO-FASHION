<?php

declare(strict_types=1);

namespace Hisab\Core;

/**
 * একীভূত সেশন ব্যবস্থাপনা।
 *
 * আগের কোডে সেশন index.php, db_connect.php ও biometric_setup.php — তিন জায়গায়
 * ভিন্ন ভিন্নভাবে শুরু হতো (Strict বনাম Lax, secure হার্ডকোড বনাম ডাইনামিক),
 * ফলে অসামঞ্জস্য তৈরি হতো। এখন সবাই এই একটি ক্লাসের মধ্য দিয়েই সেশন শুরু করবে,
 * তাই আচরণ সবসময় সঙ্গতিপূর্ণ ও নিরাপদ থাকবে।
 */
final class Session
{
    private function __construct()
    {
    }

    /**
     * নিরাপদ প্যারামিটারসহ সেশন শুরু করে। ইতিমধ্যে শুরু থাকলে কিছু করে না,
     * তাই একাধিকবার কল করা নিরাপদ (idempotent)।
     */
    public static function start(string $sessionName): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // HTTPS ডাইনামিকভাবে ডিটেক্ট — সাইট HTTP-তে থাকলেও কুকি ভাঙবে না,
        // আবার HTTPS-এ থাকলে secure ফ্ল্যাগ স্বয়ংক্রিয়ভাবে চালু হবে।
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name($sessionName);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

        session_start();
    }

    /**
     * নিষ্ক্রিয়তার সময় ছাড়িয়ে গেলে true ফেরত দেয় এবং টাইমস্ট্যাম্প রিফ্রেশ করে।
     * DB-নির্ভর কোনো কাজ এখানে নেই — বিশুদ্ধ সেশন লজিক।
     */
    public static function isIdleExpired(int $timeoutSeconds): bool
    {
        $now  = time();
        $last = $_SESSION['last_action_time'] ?? null;

        if (is_int($last) && ($now - $last) >= $timeoutSeconds) {
            return true;
        }

        $_SESSION['last_action_time'] = $now;
        return false;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** লগইন ফিক্সেশন রোধে সেশন আইডি পুনর্জন্ম (লগইন সফল হওয়ার পর কল করুন)। */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** সেশন সম্পূর্ণ ধ্বংস + কুকি মুছে ফেলা। */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
