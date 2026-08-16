<?php
declare(strict_types=1);

final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $cfg = App::config('database');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']
        );
        $this->pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Parameterized query — the only sanctioned query path. */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        $this->run("UPDATE $table SET $sets WHERE $where", array_merge(array_values($data), $whereParams));
        return $this->run("SELECT ROW_COUNT() AS c")->fetch()['c'];
    }

    public function delete(string $table, string $where, array $params = []): void
    {
        $this->run("DELETE FROM $table WHERE $where", $params);
    }

    public function transaction(callable $fn): mixed
    {
        $nested = $this->pdo->inTransaction();
        $savepoint = null;
        if ($nested) {
            $savepoint = 'sp' . bin2hex(random_bytes(4));
            $this->pdo->exec("SAVEPOINT $savepoint");
        } else {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if (!$nested) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($nested) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
            } else {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
