<?php

namespace App\Services\Backup;

use PDO;

/** فحص ملف SQLite باتصال مستقل للقراءة فقط (لا يمس اتصال التطبيق). */
class SqliteInspector
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new BackupFailed("ملف القاعدة غير موجود: {$path}");
        }

        $this->pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 30000');
    }

    public function integrity(): string
    {
        return (string) $this->pdo->query('PRAGMA integrity_check')->fetchColumn();
    }

    public function foreignKeyViolations(): int
    {
        return count($this->pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function hasTable(string $table): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$table]);

        return (bool) $st->fetchColumn();
    }

    /** @return array<string,int|null> عدد الصفوف لكل جدول (null إن لم يوجد) */
    public function rowCounts(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = $this->hasTable($table)
                ? (int) $this->pdo->query('SELECT COUNT(*) FROM "'.str_replace('"', '', $table).'"')->fetchColumn()
                : null;
        }

        return $out;
    }

    /** @return array{count:int,last:?string} */
    public function migrations(): array
    {
        if (!$this->hasTable('migrations')) {
            return ['count' => 0, 'last' => null];
        }

        return [
            'count' => (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn(),
            'last' => $this->pdo->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null,
        ];
    }

    public function journalMode(): string
    {
        return (string) $this->pdo->query('PRAGMA journal_mode')->fetchColumn();
    }

    public function sqliteVersion(): string
    {
        return (string) $this->pdo->query('SELECT sqlite_version()')->fetchColumn();
    }
}
