<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * لایهٔ دادهٔ سبک روی PDO/SQLite با کمک‌متدهای امن برای کوئری‌های پارامتری.
 */
final class Db
{
    private static ?Db $instance = null;

    private \PDO $pdo;
    private int $txDepth = 0;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function instance(): Db
    {
        if (self::$instance === null) {
            self::$instance = self::make();
        }

        return self::$instance;
    }

    /**
     * اتصال تازه با تنظیمات کانفیگ (برای worker و تست‌ها).
     */
    public static function make(): Db
    {
        $driver = Config::str('db.driver', 'sqlite');
        if ($driver !== 'sqlite') {
            throw new \RuntimeException('در حال حاضر فقط درایور sqlite پشتیبانی می‌شود.');
        }

        $path = Config::str('db.path', '');
        if ($path === '') {
            throw new \RuntimeException('مسیر دیتابیس در کانفیگ تعریف نشده است (db.path).');
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $busyTimeout = Config::int('db.busy_timeout', 10);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . ($busyTimeout * 1000));

        return new self($pdo);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * @param  array<string|int, mixed> $params
     */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param  array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param  array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function value(string $sql, array $params = [])
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function count(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params);
    }

    /**
     * درج یک رکورد و بازگرداندن شناسهٔ آن.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $this->run($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * به‌روزرسانی یک رکورد بر اساس شرط.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }

        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = $this->quoteIdentifier($column) . ' = :where_' . $column;
            $params['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $set),
            $conditions === [] ? '1=1' : implode(' AND ', $conditions)
        );

        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $conditions = [];
        $params = [];
        foreach ($where as $column => $value) {
            $conditions[] = $this->quoteIdentifier($column) . ' = :' . $column;
            $params[$column] = $value;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $conditions === [] ? '1=1' : implode(' AND ', $conditions)
        );

        return $this->run($sql, $params)->rowCount();
    }

    /**
     * تراکنش با پشتیبانی از تراکنش‌های تودرتو (ذخیره‌سازی نقطهٔ ذخیره).
     *
     * @template T
     * @param  callable(self):T $callback
     * @return T
     */
    public function transaction(callable $callback)
    {
        $this->begin();
        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function begin(): void
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT trans' . $this->txDepth);
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        if ($this->txDepth === 0) {
            return;
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT trans' . $this->txDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->txDepth === 0) {
            return;
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT trans' . $this->txDepth);
        }
    }

    public function tableExists(string $table): bool
    {
        $found = $this->value(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        return $found !== null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException('نام ستون/جدول نامعتبر است: ' . $identifier);
        }

        return '"' . $identifier . '"';
    }
}