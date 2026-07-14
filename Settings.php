<?php

declare(strict_types=1);

namespace Hisab\Core;

use Hisab\Config\AppConfig;
use Throwable;

/**
 * DB-চালিত সেটিংস সার্ভিস।
 *
 * SMS, Email, timezone, session ইত্যাদি টগলযোগ্য/এডিটযোগ্য সব মান কোডে
 * হার্ডকোড না রেখে `settings` টেবিলে থাকে। অ্যাডমিন প্যানেল থেকে এগুলো
 * পরিবর্তন বা on/off করা যায়, কোনো কোড এডিট ছাড়াই।
 *
 * টেবিল স্কিমা (Config/schema/settings.sql):
 *   setting_key   VARCHAR(100) PRIMARY KEY
 *   setting_value TEXT NULL
 *   is_enabled    TINYINT(1)   -- on/off টগল
 *
 * নিরাপত্তা/স্থিতিশীলতা: টেবিল এখনো তৈরি না হলেও (ফ্রেশ ইনস্টল) অ্যাপ ভাঙে না —
 * তখন সব কল AppConfig-এর নিরাপদ ডিফল্টে ফিরে যায়।
 */
final class Settings
{
    /** @var array<string,array{value:?string,enabled:bool}> */
    private array $cache = [];

    private bool $loaded = false;

    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
        $this->load();
    }

    /** সব সেটিংস একবারে লোড করে ইন-মেমরি ক্যাশ করে (একটি ক্যোয়ারি)। */
    private function load(): void
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT setting_key, setting_value, is_enabled FROM settings'
            );

            foreach ($rows as $row) {
                $this->cache[(string) $row['setting_key']] = [
                    'value'   => $row['setting_value'] !== null ? (string) $row['setting_value'] : null,
                    'enabled' => (int) $row['is_enabled'] === 1,
                ];
            }

            $this->loaded = true;
        } catch (Throwable $throwable) {
            // টেবিল না থাকলে বা অন্য সমস্যা — ডিফল্টে চলবে, ইউজার কিছু টের পাবে না।
            $this->logger->warning('Settings load skipped: ' . $throwable->getMessage());
            $this->loaded = false;
        }
    }

    /**
     * স্ট্রিং মান নেয়। সেটিং না থাকলে বা is_enabled=0 হলে $default ফেরত দেয়
     * (অর্থাৎ কোনো সেটিং off করলেই কোডের নিরাপদ ডিফল্টে ফিরে যায়)।
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $entry = $this->cache[$key] ?? null;
        if ($entry === null || !$entry['enabled'] || $entry['value'] === null) {
            return $default;
        }
        return $entry['value'];
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, null);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, null);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * কোনো সার্ভিস (যেমন 'sms', 'email') চালু আছে কিনা।
     * সার্ভিসের master row-এর is_enabled টগলই এখানে নির্ধারক।
     */
    public function isServiceOn(string $service): bool
    {
        return ($this->cache[$service]['enabled'] ?? false) === true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }

    /** ডিবাগ/অ্যাডমিন ভিউয়ের জন্য সব সেটিং। */
    public function all(): array
    {
        return $this->cache;
    }

    /**
     * একটি সেটিং তৈরি/আপডেট (upsert) — অ্যাডমিন প্যানেল থেকে ব্যবহৃত হবে।
     * ইন-মেমরি ক্যাশও সঙ্গে সঙ্গে আপডেট হয়।
     */
    public function set(string $key, ?string $value, bool $enabled = true): void
    {
        $this->db->execute(
            'INSERT INTO settings (setting_key, setting_value, is_enabled)
             VALUES (:k, :v, :e)
             ON DUPLICATE KEY UPDATE setting_value = :v, is_enabled = :e',
            ['k' => $key, 'v' => $value, 'e' => $enabled ? 1 : 0]
        );

        $this->cache[$key] = ['value' => $value, 'enabled' => $enabled];
    }
}
