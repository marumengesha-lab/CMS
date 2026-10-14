<?php
/**
 * Thin PDO wrapper. One lazily created connection per request plus a few
 * convenience helpers so controllers never have to build statements by hand.
 */
declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    /** @var int number of executed statements (handy for tests/debug) */
    public static int $queryCount = 0;

    public static function config(): array
    {
        return $GLOBALS['app_config']['db'];
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $c   = self::config();
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $c['host'],
            $c['port'],
            $c['name'],
            $c['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new HttpError(500, 'Database connection failed: ' . $e->getMessage());
        }

        return self::$pdo;
    }

    /** Run a prepared statement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        self::$queryCount++;
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch all rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Fetch a single row (or null). */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch a single scalar value. */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    /**
     * Insert an associative array and return the new id.
     */
    public static function insert(string $table, array $data): int
    {
        $cols   = array_keys($data);
        $marks  = array_map(static fn ($c) => ':' . $c, $cols);
        $sql    = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(', ', $marks)
        );
        $params = [];
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Update rows matched by $where (associative, AND-ed) and return the
     * number of affected rows.
     */
    public static function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $k => $v) {
            $set[] = "`$k` = :set_$k";
            $params[':set_' . $k] = $v;
        }
        $cond = [];
        foreach ($where as $k => $v) {
            $cond[] = "`$k` = :w_$k";
            $params[':w_' . $k] = $v;
        }
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $set),
            implode(' AND ', $cond)
        );
        return self::run($sql, $params)->rowCount();
    }

    /** Delete rows matched by an associative WHERE map. */
    public static function delete(string $table, array $where): int
    {
        $cond = [];
        $params = [];
        foreach ($where as $k => $v) {
            $cond[] = "`$k` = ?";
            $params[] = $v;
        }
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, implode(' AND ', $cond));
        return self::run($sql, $params)->rowCount();
    }

    /** Wrap a callable in a transaction. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Does a table exist? (used by the installer / health check) */
    public static function tableExists(string $table): bool
    {
        $row = self::one('SHOW TABLES LIKE ?', [$table]);
        return $row !== null;
    }
}
