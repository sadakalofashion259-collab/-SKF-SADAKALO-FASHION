<?php

declare(strict_types=1);

namespace Hisab\Config;

/**
 * অ্যাপ্লিকেশন-ওয়াইড কনফিগারেশন।
 *
 * গুরুত্বপূর্ণ: পাথগুলো এখন হার্ডকোড নয় — __DIR__ থেকে নিজে থেকে হিসাব হয়।
 * এই ফাইলটি {প্রজেক্ট}/Config/AppConfig.php-এ থাকে, তাই dirname(__DIR__) মানেই
 * প্রজেক্ট রুট। ফলে হোস্টিং প্যানেল বা ডোমেইন বদলালেও (Sadakalohisabsystem.com →
 * sadakalofashion.com) কোনো কোড এডিট ছাড়াই সব ঠিক থাকবে।
 */
final class AppConfig
{
    /*
     * নিচের তিনটি শুধু FALLBACK ডিফল্ট — আসল মান settings টেবিল থেকে আসে।
     * settings টেবিল খালি/অনুপস্থিত থাকলে (ফ্রেশ ইনস্টল) এগুলো ব্যবহৃত হয়।
     */
    public const TIMEZONE = 'Asia/Dhaka';

    /** নিষ্ক্রিয়তায় অটো-লগআউট (সেকেন্ড) — ২০ মিনিট। */
    public const SESSION_TIMEOUT = 1200;

    /** কাস্টম সেশন নাম — ডিফল্ট PHPSESSID লুকিয়ে ফিঙ্গারপ্রিন্টিং কমানো হয়। */
    public const SESSION_NAME = 'HISAB_SID';

    /** .env-এ যে কি-গুলো অবশ্যই থাকতে হবে; একটিও অনুপস্থিত হলে অ্যাপ থামে। */
    public const REQUIRED_ENV_KEYS = [
        'DB_HOST',
        'DB_USER',
        'DB_PASS',
        'DB_NAME',
        'CARD_ENC_KEY',
    ];

    /** প্রোডাকশনে সবসময় false — raw এরর কখনো আউটপুট হয় না। */
    public const DEBUG = false;

    private function __construct()
    {
    }

    /**
     * প্রজেক্ট রুট — স্বয়ংক্রিয়ভাবে ডিটেক্ট।
     * এই ফাইল Config/ ফোল্ডারে, তাই এর প্যারেন্টই রুট।
     */
    public static function basePath(): string
    {
        return dirname(__DIR__);
    }

    /** এরর লগ ফাইল — সব এরর এখানে যায়, ইউজারকে কখনো দেখানো হয় না। */
    public static function logFile(): string
    {
        return self::basePath() . '/Logs/error_log.txt';
    }

    /** শেয়ার্ড ভিউ (এরর পেজ ইত্যাদি)। */
    public static function coreViewPath(): string
    {
        return self::basePath() . '/Core/Views';
    }

    /**
     * সিকিউরিটি ভল্ট (.env)-এর অবস্থান।
     *
     * নিরাপত্তার জন্য .env সবসময় web root-এর বাইরে থাকে। খোঁজার ক্রম:
     *   1) HISAB_VAULT_PATH কনস্ট্যান্ট (লোকাল ওভাররাইড ফাইলে সেট থাকলে)
     *   2) HISAB_VAULT_PATH এনভায়রনমেন্ট ভেরিয়েবল
     *   3) ডিফল্ট: প্রজেক্ট রুটের এক ধাপ উপরে "App/.env"
     *
     * ডিফল্টটি আপনার বর্তমান লেআউটের সাথে মেলে:
     *   প্রজেক্ট = /home/sadakalo/Hisab  →  ভল্ট = /home/sadakalo/App/.env
     * নতুন হোস্টিং প্যানেলেও (App ফোল্ডার প্রজেক্টের এক ধাপ উপরে রাখলে) একইভাবে চলবে।
     */
    public static function vaultPath(): string
    {
        if (defined('HISAB_VAULT_PATH') && is_string(\HISAB_VAULT_PATH) && \HISAB_VAULT_PATH !== '') {
            return \HISAB_VAULT_PATH;
        }

        $fromEnv = getenv('HISAB_VAULT_PATH');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return dirname(self::basePath()) . '/App/.env';
    }
}
