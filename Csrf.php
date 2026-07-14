<?php

declare(strict_types=1);

namespace Hisab\Core;

/**
 * CSRF সুরক্ষা।
 *
 * শর্ত #৪ অনুযায়ী প্রতিটি state-changing (POST) রিকোয়েস্টে টোকেন বাধ্যতামূলক।
 * টোকেন সেশনে সংরক্ষিত থাকে এবং যাচাই hash_equals() দিয়ে করা হয়, যা
 * timing-attack প্রতিরোধী।
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    private function __construct()
    {
    }

    /**
     * বর্তমান টোকেন ফেরত দেয়; না থাকলে নতুন তৈরি করে।
     * ফর্মের hidden ইনপুটে এই মান বসাতে হবে।
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /** সাবমিট করা টোকেন সঠিক কিনা যাচাই করে (const-time তুলনা)। */
    public static function validate(?string $submittedToken): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($stored) || !is_string($submittedToken) || $submittedToken === '') {
            return false;
        }

        return hash_equals($stored, $submittedToken);
    }

    /** POST ডেটা থেকে টোকেন নিয়ে সরাসরি যাচাই করার সুবিধা। */
    public static function validateRequest(string $fieldName = 'csrf_token'): bool
    {
        $submitted = $_POST[$fieldName] ?? null;
        return self::validate(is_string($submitted) ? $submitted : null);
    }

    /** লগইন সফল হলে টোকেন রোটেট করা ভালো অভ্যাস। */
    public static function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
