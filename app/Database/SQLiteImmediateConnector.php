<?php

namespace App\Database;

use Illuminate\Database\Connectors\SQLiteConnector;

/**
 * موصّل SQLite يعطي ImmediateTransactionPdo حين transaction_mode غير DEFERRED وPHP أقدم من 8.4.
 * على 8.4 فأحدث يبقى سلوك Laravel نفسه (BEGIN {mode} TRANSACTION من SQLiteConnection).
 */
final class SQLiteImmediateConnector extends SQLiteConnector
{
    private ?string $mode = null;

    public static function needed(?string $mode): ?string
    {
        $mode = strtoupper(trim((string) $mode));

        return PHP_VERSION_ID < 80400 && in_array($mode, ['IMMEDIATE', 'EXCLUSIVE'], true) ? $mode : null;
    }

    public function connect(array $config)
    {
        $this->mode = self::needed($config['transaction_mode'] ?? null);

        return parent::connect($config);
    }

    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        if ($this->mode === null) {
            return parent::createPdoConnection($dsn, $username, $password, $options);
        }

        $pdo = new ImmediateTransactionPdo($dsn, $username, $password, $options);
        $pdo->setTransactionMode($this->mode);

        return $pdo;
    }
}
