<?php

declare(strict_types=1);

namespace Hisab\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO-ভিত্তিক ডাটাবেস অ্যাক্সেস লেয়ার।
 *
 * শর্ত #৩ অনুযায়ী সব ক্যোয়ারি Prepared Statement, এবং Transaction হেল্পার
 * অন্তর্ভুক্ত। SQL Injection ঠেকানোর জন্য কোথাও raw ইন্টারপোলেশন করা হয় না —
 * সবসময় placeholder + bound parameter ব্যবহার হয়।
 */
final class Database
{
    private PDO $pdo;

    public function __construct(
        string $host,
        string $name,
        string $user,
        string $password,
        private readonly Logger $logger,
    ) {
        try {
            $this->pdo = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // True prepared statements — এমুলেশন বন্ধ, injection-প্রতিরোধ শক্ত।
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $exception) {
            $this->logger->exception($exception);
            throw new RuntimeException('Database connection failed.', 0, $exception);
        }
    }

    /** সরাসরি PDO দরকার হলে (বিশেষ ক্ষেত্রে)। */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Prepared statement চালিয়ে PDOStatement ফেরত দেয়।
     *
     * @param array<string,mixed>|list<mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /**
     * একটি রো ফেরত দেয় (না পেলে null)।
     *
     * @param array<string,mixed>|list<mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * সব রো ফেরত দেয়।
     *
     * @param array<string,mixed>|list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** একটি স্কেলার (single column) মান ফেরত দেয়। */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * INSERT/UPDATE/DELETE — প্রভাবিত রো সংখ্যা ফেরত দেয়।
     *
     * @param array<string,mixed>|list<mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ক্লোজার-ভিত্তিক ট্রানজেকশন র‍্যাপার।
     * ক্লোজার সফল হলে commit, exception হলে rollback ও পুনরায় throw।
     *
     * @template T
     * @param callable(self):T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->exception($throwable);
            throw $throwable;
        }
    }
}
