<?php
namespace App\Core;

use PDO;

class Database
{
    private static ?PDO $pdo = null;
    private static array $config = [];

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function driver(): string
    {
        return self::$config['driver'] ?? 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $driver = self::driver();
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($driver === 'mysql') {
            $c = self::$config['mysql'];
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset={$c['charset']}";
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], $opts);
        } elseif ($driver === 'pgsql') {
            $c = self::$config['pgsql'];
            $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}";
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], $opts);
            self::$pdo->exec("SET client_encoding TO 'UTF8'");
        } else {
            $path = self::$config['sqlite']['path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            self::$pdo = new PDO('sqlite:' . $path, null, null, $opts);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            // WAL + ожидание блокировки: резко снижает «database is locked» при конкурентных записях
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
        }

        return self::$pdo;
    }

    /**
     * Привести колонку к тексту для substr() по дате. В SQLite/MySQL даты хранятся
     * строками и substr работает напрямую — возвращаем колонку как есть (нулевой риск).
     * В PostgreSQL DATE/TIMESTAMP — настоящие типы, substr по ним не работает, поэтому
     * приводим к тексту ('YYYY-MM-DD HH:MM:SS' / 'YYYY-MM-DD' — позиции совпадают с SQLite).
     */
    public static function txt(string $col): string
    {
        return self::driver() === 'pgsql' ? "($col)::text" : $col;
    }

    /** SQL-выражение склейки в группе (портативно по драйверам): GROUP_CONCAT/string_agg. */
    public static function groupConcat(string $expr, string $sep = ', '): string
    {
        $s = "'" . str_replace("'", "''", $sep) . "'";
        switch (self::driver()) {
            case 'pgsql': return "string_agg($expr, $s)";
            case 'mysql': return "GROUP_CONCAT($expr SEPARATOR $s)";
            default:      return "GROUP_CONCAT($expr, $s)";
        }
    }

    /** Кэш: есть ли у таблицы колонка id (для RETURNING в PostgreSQL). */
    private static array $idCache = [];
    private static function tableHasId(string $table): bool
    {
        if (isset(self::$idCache[$table])) { return self::$idCache[$table]; }
        $has = (int) self::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [strtolower($table), 'id']) > 0;
        return self::$idCache[$table] = $has;
    }

    /** Запрос с параметрами, возвращает PDOStatement. */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        if (self::driver() === 'pgsql') { $sql = self::pgCompat($sql); }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Совместимость SQL для PostgreSQL без правки контроллеров:
     * substr() по дате/времени применяется к колонкам типа DATE/TIMESTAMP — приводим
     * аргумент к тексту: substr(col, → substr((col)::text,. В SQLite/MySQL даты хранятся
     * строками, поэтому там этот метод не вызывается (SQL остаётся прежним).
     */
    private static function pgCompat(string $sql): string
    {
        return preg_replace(
            '/\bsubstr\(\s*([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)?)\s*,/i',
            'substr(($1)::text,',
            $sql
        );
    }

    /** Все строки. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Одна строка или null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Скалярное значение из первой колонки. */
    public static function scalar(string $sql, array $params = [])
    {
        return self::run($sql, $params)->fetchColumn();
    }

    /** INSERT, возвращает id. */
    public static function insert(string $sql, array $params = []): int
    {
        // PostgreSQL: lastInsertId не работает без имени последовательности — используем RETURNING id.
        if (self::driver() === 'pgsql') {
            if (preg_match('/^\s*INSERT\s+INTO\s+["`]?(\w+)/i', $sql, $m) && self::tableHasId($m[1])
                && stripos($sql, 'returning') === false) {
                return (int) self::run($sql . ' RETURNING id', $params)->fetchColumn();
            }
            self::run($sql, $params);
            return 0; // таблица без колонки id — идентификатор не нужен вызывающему
        }
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Драйвер-портативный «вставить, игнорируя дубль по уникальному ключу». */
    public static function insertIgnore(string $sql, array $params, string $conflictTarget): void
    {
        $driver = self::driver();
        if ($driver === 'pgsql') {
            self::run($sql . " ON CONFLICT ($conflictTarget) DO NOTHING", $params);
        } elseif ($driver === 'mysql') {
            self::run(preg_replace('/^\s*INSERT\s+INTO/i', 'INSERT IGNORE INTO', $sql, 1), $params);
        } else {
            self::run(preg_replace('/^\s*INSERT\s+INTO/i', 'INSERT OR IGNORE INTO', $sql, 1), $params);
        }
    }
}
