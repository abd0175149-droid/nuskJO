<?php

namespace App\Database;

use PDO;
use PDOException;

/**
 * اتصال SQLite تبدأ معاملاته بـ BEGIN IMMEDIATE على PHP أقدم من 8.4.
 *
 * Laravel يطبّق transaction_mode على PHP 8.4 وحده؛ قبله يستدعي PDO::beginTransaction فتبدأ المعاملة DEFERRED،
 * وترقية قراءتها إلى كتابة تفشل فوراً بـ «database is locked» إن كتب غيرها بينهما، بلا انتظار busy_timeout
 * (حجز وظيفة من طابور القاعدة كان يفشل هكذا). هنا تحجز المعاملة الكتابة من بدايتها فتنتظر دورها.
 * PDO على 8.3 يتتبع المعاملة بعلمه هو، فتُدار الحالة هنا مع COMMIT وROLLBACK صريحين.
 */
final class ImmediateTransactionPdo extends PDO
{
    private bool $active = false;

    private string $mode = 'IMMEDIATE';

    public function setTransactionMode(string $mode): void
    {
        $this->mode = in_array($mode, ['IMMEDIATE', 'EXCLUSIVE'], true) ? $mode : 'IMMEDIATE';
    }

    public function transactionMode(): string
    {
        return $this->mode;
    }

    public function beginTransaction(): bool
    {
        if ($this->active) {
            throw new PDOException('There is already an active transaction');
        }
        $this->exec("BEGIN {$this->mode} TRANSACTION");
        $this->active = true;

        return true;
    }

    public function commit(): bool
    {
        if (!$this->active) {
            throw new PDOException('There is no active transaction');
        }
        // فشل COMMIT (مشغول مثلاً) يُبقي المعاملة مفتوحة عند SQLite، فتبقى الحالة كما هي حتى rollBack
        $this->exec('COMMIT');
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->active) {
            throw new PDOException('There is no active transaction');
        }
        try {
            $this->exec('ROLLBACK');
        } catch (PDOException $e) {
            // SQLite تتراجع وحدها عن المعاملة عند بعض الأخطاء (امتلاء القرص مثلاً): لا شيء يُتراجع عنه، والخطأ الأصلي يبقى هو المرئي
            if (!str_contains($e->getMessage(), 'no transaction is active')) {
                throw $e;
            }
        } finally {
            $this->active = false;
        }

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }
}
