<?php

declare(strict_types=1);

namespace Hisab\Core;

use Hisab\Config\AppConfig;

/**
 * HTTP রেসপন্স হেল্পার।
 *
 * আগের কোডে redirect ও এরর-পেজ HTML কনফিগ/এন্ট্রি ফাইলের ভেতর ইনলাইন ছিল।
 * এখন সব এক জায়গায় — redirect(), json() এবং errorPage() (ভিউ থেকে রেন্ডার)।
 */
final class Response
{
    private function __construct()
    {
    }

    /** নিরাপদ রিডাইরেক্ট — আউটপুট বাফার ক্লিয়ার করে হেডার পাঠায়। */
    public static function redirect(string $location, int $statusCode = 303): never
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Location: ' . $location, true, $statusCode);
        exit;
    }

    /** JSON রেসপন্স (AJAX এন্ডপয়েন্টের জন্য)। */
    public static function json(array $payload, int $statusCode = 200): never
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * ইউজার-নিরাপদ এরর পেজ। raw সিস্টেম তথ্য কখনো লিক করে না —
     * শুধু সাধারণ বার্তা দেখায়, বিস্তারিত লগে যায়।
     */
    public static function errorPage(string $userMessage, int $statusCode = 503): never
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($statusCode);

        echo View::render(AppConfig::coreViewPath() . '/error.php', [
            'userMessage' => $userMessage,
            'statusCode'  => $statusCode,
        ]);
        exit;
    }
}
