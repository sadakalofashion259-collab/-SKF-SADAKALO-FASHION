<?php

declare(strict_types=1);

namespace Hisab\Core;

use RuntimeException;

/**
 * নিরাপদ .env ভল্ট লোডার।
 *
 * আগের কোডে parse_ini_file() ব্যবহার হতো, যা পাসওয়ার্ডে #, ;, =, " বা
 * অন্যান্য স্পেশাল ক্যারেক্টার থাকলে চুপচাপ ভুল পার্স করত। এই ক্লাসটি
 * সেই দুর্বলতা দূর করে — এটি নিজস্ব লাইন-বাই-লাইন পার্সার, যা:
 *   - # এবং ; কমেন্ট লাইন উপেক্ষা করে
 *   - "double" ও 'single' quoted ভ্যালু ঠিকভাবে হ্যান্ডল করে
 *   - ভ্যালুর ভেতরের = চিহ্ন নষ্ট করে না
 *   - খালি লাইন ও BOM বাদ দেয়
 */
final class Env
{
    /** @var array<string,string> পার্স করা কি-ভ্যালু। */
    private array $values = [];

    /**
     * @throws RuntimeException ফাইল না পেলে বা পড়া না গেলে।
     */
    public function __construct(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Security vault (.env) not found or unreadable.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Failed to read security vault (.env).');
        }

        $this->parse($contents);
    }

    /**
     * প্রয়োজনীয় সব কি উপস্থিত ও non-empty কিনা যাচাই করে।
     *
     * @param list<string> $requiredKeys
     * @throws RuntimeException কোনো কি অনুপস্থিত/খালি হলে।
     */
    public function assertRequired(array $requiredKeys): void
    {
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $this->values) || $this->values[$key] === '') {
                throw new RuntimeException("Missing required configuration key: {$key}");
            }
        }
    }

    /** নির্দিষ্ট কি-এর মান নেয়; না থাকলে $default ফেরত দেয়। */
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    /** কি-টি আছে কিনা। */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    private function parse(string $contents): void
    {
        // UTF-8 BOM থাকলে সরিয়ে দাও।
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            // খালি লাইন বা কমেন্ট বাদ।
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            // ঐচ্ছিক "export " prefix সরাও।
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separatorPos = strpos($line, '=');
            if ($separatorPos === false) {
                // = ছাড়া লাইন অবৈধ — নিরবে উপেক্ষা।
                continue;
            }

            $key   = trim(substr($line, 0, $separatorPos));
            $value = trim(substr($line, $separatorPos + 1));

            if ($key === '') {
                continue;
            }

            $this->values[$key] = $this->unwrapValue($value);
        }
    }

    /** quote সরানো ও escape সিকোয়েন্স রিজলভ করা। */
    private function unwrapValue(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last  = $value[$length - 1];

            if ($first === '"' && $last === '"') {
                $inner = substr($value, 1, -1);
                // double-quote-এ কমন escape রিজলভ।
                return str_replace(
                    ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                    ["\n", "\r", "\t", '"', '\\'],
                    $inner
                );
            }

            if ($first === "'" && $last === "'") {
                // single-quote — literal, কোনো escape নয়।
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
