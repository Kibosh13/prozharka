<?php

declare(strict_types=1);

namespace Prozharka;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(private readonly string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create database directory: {$directory}");
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(string $migrationFile): void
    {
        $sql = file_get_contents($migrationFile);
        if ($sql === false) {
            throw new RuntimeException("Cannot read migration: {$migrationFile}");
        }
        $this->pdo->exec($sql);
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}

