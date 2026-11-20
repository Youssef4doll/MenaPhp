<?php
declare(strict_types=1);

/**
 * The shop's database: the same PostgreSQL (Neon) the Next.js shop uses, with
 * its tables as Prisma made them. So, everywhere in this code:
 *
 *  - table and column names are quoted ("Product", "nameAr", "Order");
 *  - ids are made here (new_id), like Prisma makes them in its client;
 *  - "updatedAt" has no default in the database: every write sets it;
 *  - times are stored in UTC ("timestamp without time zone"): written with
 *    Db::now(), shown in Tunisia's time with View::time();
 *  - lists ("flags", "oldSlugs") are text[]: Db::pgArray() / Db::parseArray().
 *
 * The tables belong to the Next.js shop (prisma/schema.prisma in the Mena
 * repository): this code never creates or changes them.
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** The parts of DATABASE_URL a PDO connection needs. */
    public static function parseUrl(string $url): array
    {
        $u = parse_url($url);
        if (!$u || !in_array($u['scheme'] ?? '', ['postgres', 'postgresql'], true) || empty($u['host'])) {
            throw new RuntimeException('database_url in config.php is not a postgresql:// address.');
        }
        parse_str($u['query'] ?? '', $q);
        return [
            'host' => $u['host'],
            'port' => (int) ($u['port'] ?? 5432),
            'dbname' => ltrim($u['path'] ?? '/neondb', '/') ?: 'neondb',
            'user' => rawurldecode($u['user'] ?? ''),
            'pass' => rawurldecode($u['pass'] ?? ''),
            'sslmode' => is_string($q['sslmode'] ?? null) ? $q['sslmode'] : (self::isLocal($u['host']) ? 'disable' : 'require'),
        ];
    }

    public static function isLocal(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '/');
    }

    /**
     * Neon finds the database from the host name the client sends (SNI). An
     * old PostgreSQL client library on a shared host may not send it; Neon
     * then asks for the endpoint id, which is the host's first label without
     * "-pooler".
     */
    public static function endpointId(string $host): ?string
    {
        if (!preg_match('/^(ep-[a-z0-9-]+?)(-pooler)?\./', $host, $m)) {
            return null;
        }
        return $m[1];
    }

    public static function dsn(array $c, bool $withEndpoint = false): string
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $c['host'], $c['port'], $c['dbname'], $c['sslmode']);
        if ($withEndpoint && ($ep = self::endpointId($c['host']))) {
            $dsn .= ";options='endpoint=$ep'";
        }
        return $dsn;
    }

    public static function connect(string $url): PDO
    {
        $c = self::parseUrl($url);
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Neon's pooled address shares server connections: prepared statements live per query only.
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => 10,
        ];
        try {
            return new PDO(self::dsn($c), $c['user'], $c['pass'], $opts);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'endpoint') === false || !self::endpointId($c['host'])) {
                throw $e;
            }
            return new PDO(self::dsn($c, true), $c['user'], $c['pass'], $opts);
        }
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect((string) config('database_url', ''));
    }

    /** For the tests: start again from a new connection. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** "Product" → "\"Product\"": every name is quoted (Prisma's tables use capitals). */
    public static function q(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** Booleans as PostgreSQL reads them; everything else as given. */
    private static function params(array $params): array
    {
        return array_map(static fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : $v, $params);
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(self::params($params));
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Insert one row from a column => value map. */
    public static function insert(string $table, array $row): void
    {
        $cols = array_map([self::class, 'q'], array_keys($row));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', self::q($table), implode(',', $cols), implode(',', array_fill(0, count($cols), '?')));
        self::run($sql, array_values($row));
    }

    /** Update rows: $set column => value; $where uses quoted names and ? marks. Returns rows changed. */
    public static function update(string $table, array $set, string $where, array $params): int
    {
        $parts = [];
        foreach (array_keys($set) as $c) {
            $parts[] = self::q($c) . ' = ?';
        }
        return self::run(sprintf('UPDATE %s SET %s WHERE %s', self::q($table), implode(', ', $parts), $where), [...array_values($set), ...$params])->rowCount();
    }

    /** Run $work inside a transaction; rolled back if it throws. */
    public static function tx(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $out = $work();
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** "?, ?, ?" for an IN (...) list. */
    public static function marks(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }

    /** Now, in UTC, as Prisma stores it. */
    public static function now(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    /** A PHP list as a PostgreSQL text[] value: {"a","b"}. */
    public static function pgArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn ($v) => '"' . addcslashes((string) $v, '"\\') . '"', $values)) . '}';
    }

    /** A text[] value as PHP reads it ("{a,\"b c\"}") → a list. */
    public static function parseArray(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v) || $v === '' || $v === '{}') {
            return [];
        }
        $inner = substr($v, 1, -1);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $m, PREG_SET_ORDER);
        return array_map(static fn ($x) => isset($x[2]) && $x[2] !== '' ? $x[2] : stripcslashes($x[1]), $m);
    }

    /** A boolean column as PHP gets it from PostgreSQL. */
    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
    }
}
