<?php

declare(strict_types=1);

namespace Hisab\Core;

use RuntimeException;

/**
 * হালকা ভিউ রেন্ডারার।
 *
 * ইনলাইন HTML কন্ট্রোলার/কনফিগ থেকে বের করে আলাদা View ফাইলে রাখা যায় —
 * এতে MVC-এর "V" পরিষ্কার থাকে। ভিউ ফাইলের ভেতরে $data অ্যারের কি-গুলো
 * ভেরিয়েবল হিসেবে পাওয়া যায় এবং Security::e() সবসময় উপলব্ধ।
 */
final class View
{
    private function __construct()
    {
    }

    /**
     * ভিউ রেন্ডার করে স্ট্রিং ফেরত দেয় (echo করে না)।
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $viewFile, array $data = []): string
    {
        if (!is_file($viewFile)) {
            throw new RuntimeException("View file not found: {$viewFile}");
        }

        // ভিউয়ের ভেতর সংক্ষিপ্ত escape হেল্পার।
        $e = static fn (?string $v): string => Security::e($v);

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        return (string) ob_get_clean();
    }
}
