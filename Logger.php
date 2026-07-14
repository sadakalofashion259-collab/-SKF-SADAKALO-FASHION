<?php

declare(strict_types=1);

namespace Hisab\Core;

use Throwable;

/**
 * কেন্দ্রীয় এরর লগার।
 *
 * আপনার শর্ত #৭ অনুযায়ী: কোনো এরর কখনো সরাসরি ইউজারকে দেখানো হবে না,
 * সব লেখা হবে Logs/error_log.txt-এ। এই ক্লাসটি সেই একমাত্র লেখার পয়েন্ট।
 */
final class Logger
{
    public function __construct(private readonly string $logFile)
    {
        $directory = dirname($this->logFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    /** Throwable-এর পূর্ণ প্রসঙ্গসহ লগ। */
    public function exception(Throwable $throwable): void
    {
        $this->write('EXCEPTION', sprintf(
            '%s: %s in %s:%d',
            $throwable::class,
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        ));
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf(
            "[%s] [%s] [IP:%s] %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $this->clientIp(),
            str_replace(["\r", "\n"], ' ', $message),
            PHP_EOL
        );

        // LOCK_EX — একই সময়ে একাধিক রিকোয়েস্টে লগ মিশে যাওয়া রোধ করে।
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return filter_var($ip, FILTER_VALIDATE_IP) ?: 'invalid';
    }
}
