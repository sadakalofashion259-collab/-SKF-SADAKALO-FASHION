<?php

declare(strict_types=1);

/**
 * bootstrap.php — অ্যাপের একক প্রবেশ-ভিত্তি।
 *
 * প্রতিটি এন্ট্রি ফাইল (index.php, logout.php ইত্যাদি) শুধু এই একটি ফাইল
 * require করবে। এটি করে:
 *   1. অটোলোডার রেজিস্টার (namespace → path)
 *   2. টাইমজোন সেট
 *   3. এরর/এক্সেপশন হ্যান্ডলার → Logger (raw এরর কখনো আউটপুট হয় না)
 *   4. .env ভল্ট লোড ও ভ্যালিডেট
 *   5. Database ($db) ও Logger ($logger) তৈরি করে উপলব্ধ করা
 *   6. নিরাপদ সেশন শুরু + idle-timeout চেক
 *
 * এই ফাইল কোনো HTML আউটপুট করে না — শুধু পরিবেশ প্রস্তুত করে।
 */

use Hisab\Config\AppConfig;
use Hisab\Core\Database;
use Hisab\Core\Env;
use Hisab\Core\Logger;
use Hisab\Core\Response;
use Hisab\Core\Session;
use Hisab\Core\Settings;

/* ── 1. অটোলোডার ─────────────────────────────────────────────
   Hisab\Core\Env  →  {BASE_PATH}/Core/Env.php  ইত্যাদি। */
require_once __DIR__ . '/Config/AppConfig.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Hisab\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = AppConfig::basePath() . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/* ── 2. টাইমজোন (fallback) ───────────────────────────────────
   লগার টাইমস্ট্যাম্পের জন্য এখানে ডিফল্ট সেট। DB Settings লোড হলে
   নিচে (ধাপ ৬) আসল timezone দিয়ে override হবে। */
date_default_timezone_set(AppConfig::TIMEZONE);

/* ── 3. কেন্দ্রীয় লগার + এরর/এক্সেপশন হ্যান্ডলার ───────────────── */
$logger = new Logger(AppConfig::logFile());

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($logger): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $logger->error("{$message} in {$file}:{$line}");
    return true; // ইউজারের কাছে raw এরর যাবে না।
});

set_exception_handler(static function (Throwable $throwable) use ($logger): void {
    $logger->exception($throwable);
    Response::errorPage('সিস্টেমে অপ্রত্যাশিত সমস্যা হয়েছে। অনুগ্রহ করে পরে চেষ্টা করুন।', 500);
});

register_shutdown_function(static function () use ($logger): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $logger->error("FATAL: {$error['message']} in {$error['file']}:{$error['line']}");
    }
});

/* ── 4. .env ভল্ট লোড + ভ্যালিডেশন ───────────────────────────── */
try {
    $env = new Env(AppConfig::vaultPath());
    $env->assertRequired(AppConfig::REQUIRED_ENV_KEYS);
} catch (Throwable $throwable) {
    $logger->exception($throwable);
    Response::errorPage('সিস্টেমের সিকিউরিটি কনফিগারেশন পড়া যাচ্ছে না। ADMIN-কে জানান।');
}

/* কার্ড এনক্রিপশন কি constant হিসেবে (কার্ড মডিউলে ব্যবহৃত হবে)। */
if (!defined('CARD_ENC_KEY')) {
    define('CARD_ENC_KEY', (string) $env->get('CARD_ENC_KEY'));
}

/* ── 5. ডাটাবেস ($db) ─────────────────────────────────────────── */
try {
    $db = new Database(
        (string) $env->get('DB_HOST'),
        (string) $env->get('DB_NAME'),
        (string) $env->get('DB_USER'),
        (string) $env->get('DB_PASS'),
        $logger,
    );
} catch (Throwable $throwable) {
    // Database ক্লাস ভেতরেই লগ করেছে; ইউজারকে শুধু সাধারণ বার্তা।
    Response::errorPage('ডাটাবেজে সংযোগ স্থাপন করা যাচ্ছে না। ADMIN-কে জানান।');
}

/* ── 6. সেটিংস ($settings) — DB টেবিল থেকে টগলযোগ্য কনফিগ ────────
   টেবিল এখনো তৈরি না হলেও ভাঙে না; তখন AppConfig-এর ডিফল্ট চলে। */
$settings = new Settings($db, $logger);

/* আসল timezone DB থেকে (না থাকলে AppConfig ডিফল্ট)। */
$configuredTimezone = $settings->get('timezone', AppConfig::TIMEZONE);
if (is_string($configuredTimezone) && in_array($configuredTimezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($configuredTimezone);
}

/* ── 7. নিরাপদ সেশন + idle-timeout ───────────────────────────── */
$sessionName    = (string) $settings->get('session_name', AppConfig::SESSION_NAME);
$sessionTimeout = $settings->getInt('session_timeout', AppConfig::SESSION_TIMEOUT);

Session::start($sessionName);

if (Session::get('loggedin') === true && Session::isIdleExpired($sessionTimeout)) {
    // শুধু সেশন-লেভেল লগআউট এখানে। users টেবিল সম্পর্কিত কাজ (last_active রিসেট,
    // block-check, SessionGuard) Auth মডিউলে হবে — Core স্কিমা-নিরপেক্ষ থাকে।
    Session::destroy();
    Session::start($sessionName);
    Response::redirect('/index.php?status=timeout');
}
