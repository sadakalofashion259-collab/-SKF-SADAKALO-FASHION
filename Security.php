<?php

declare(strict_types=1);

namespace Hisab\Core;

/**
 * সাধারণ সিকিউরিটি হেল্পার।
 *
 * - e()            : XSS প্রতিরোধে htmlspecialchars র‍্যাপার (শর্ত #৪)।
 * - base64UrlEncode/Decode : WebAuthn ও টোকেনের জন্য স্ট্যান্ডার্ড, নিরাপদ
 *                    URL-safe Base64 (শর্ত #২ — এনকোডিং দরকার হলে standard format)।
 */
final class Security
{
    private function __construct()
    {
    }

    /** আউটপুটের আগে XSS-নিরাপদ করা। null হলে খালি স্ট্রিং। */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** URL-safe Base64 এনকোড (padding ছাড়া)। */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** URL-safe Base64 ডিকোড। অবৈধ হলে খালি স্ট্রিং। */
    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    /** নিরাপদ র‍্যান্ডম টোকেন (hex)। */
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
