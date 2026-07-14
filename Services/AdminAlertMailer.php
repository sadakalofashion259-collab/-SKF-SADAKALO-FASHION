<?php
declare(strict_types=1);

require_once __DIR__ . '/DeviceInfo.php';

/**
 * AdminAlertMailer — প্রতিটি সফল লগইনে অ্যাডমিনকে একটি সতর্কতা ইমেইল পাঠায়।
 * ────────────────────────────────────────────────────────────────────────
 *  • অ্যাডমিন ইমেইল পড়া হয় সিকিউরিটি ভল্ট (/home/sadakalo/App/.env) থেকে।
 *    নিচের EMAIL_KEYS তালিকার যে key প্রথমে পাওয়া যায় সেটি ব্যবহৃত হয় —
 *    আপনার .env-এ key-এর নাম এদের একটির সাথে মিলিয়ে নিন (যেমন ADMIN_EMAIL)।
 *  • পাঠানো হয় PHP কোরের mail() দিয়ে — কোনো বাইরের লাইব্রেরি নয়।
 *  • non-blocking: LoginController এটি register_shutdown_function-এর ভেতরে ডাকে,
 *    তাই লগইন রেসপন্স/রিডাইরেক্ট মোটেও ধীর হয় না।
 */
final class AdminAlertMailer
{
    private const VAULT_PATH = '/home/sadakalo/App/.env';

    /** .env-এ অ্যাডমিন ইমেইলের সম্ভাব্য key (উপরের দিকেরটা আগে) */
    private const EMAIL_KEYS = [
        'ADMIN_EMAIL', 'ADMIN_MAIL', 'MAIL_ADMIN', 'ALERT_EMAIL', 'MAIL_TO', 'EMAIL',
    ];

    /** From হেডারের সম্ভাব্য key */
    private const FROM_KEYS = ['MAIL_FROM', 'SMTP_USER', 'ADMIN_EMAIL'];

    /**
     * সফল লগইনের সতর্কতা ইমেইল পাঠায়। সফল হলে true।
     */
    public function sendLoginAlert(string $username, DeviceInfo $device, string $whenIso): bool
    {
        $config = $this->readVault();

        $to = $this->pickEmail($config, self::EMAIL_KEYS);
        if ($to === '') {
            return false; // অ্যাডমিন ইমেইল সেট নেই — নীরবে বাদ।
        }

        $from = $this->pickEmail($config, self::FROM_KEYS);
        if ($from === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
            $from = 'no-reply@' . $host;
        }

        $safeUser = $this->clean($username);
        $subject  = 'Sada Kalo Fashion — নতুন লগইন: ' . $safeUser;

        $body =
            "একটি অ্যাকাউন্টে সফলভাবে লগইন হয়েছে।\n\n" .
            "ইউজার      : {$safeUser}\n" .
            "সময়        : {$this->clean($whenIso)}\n" .
            "IP          : {$device->ipAddress}\n" .
            "ডিভাইস মডেল : {$device->deviceModel}\n" .
            "ধরন         : {$device->deviceType}\n" .
            "OS          : {$device->operatingSystem}\n" .
            "ব্রাউজার    : {$device->browser} {$device->browserVersion}\n\n" .
            "— এই বার্তাটি স্বয়ংক্রিয়ভাবে পাঠানো হয়েছে।\n";

        $headers = implode("\r\n", [
            'From: Sada Kalo Fashion <' . $from . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);

        try {
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** @return array<string,string> */
    private function readVault(): array
    {
        if (!is_readable(self::VAULT_PATH)) {
            return [];
        }
        $parsed = @parse_ini_file(self::VAULT_PATH);
        if (!is_array($parsed)) {
            return [];
        }
        $out = [];
        foreach ($parsed as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * @param array<string,string> $config
     * @param array<int,string>    $keys
     */
    private function pickEmail(array $config, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($config[$key])) {
                $candidate = trim($config[$key]);
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    private function clean(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return trim($value);
    }
}
