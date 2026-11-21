<?php
declare(strict_types=1);

/**
 * What the shop's pages read from the database, kept for a minute in small
 * files (storage/cache). The database can be far away (Neon in another
 * country: each question costs a round trip), so a page that would ask ten
 * questions asks none while its data is fresh.
 *
 * Only for what pages SHOW. An order always reads prices, offers and stock
 * again from the database (Orders::place), so a cached price can never be
 * charged: a total that changed meanwhile is shown to the customer to
 * confirm again.
 *
 * Past its minute, a copy is still served (for up to a day), and read again
 * from the database AFTER the page has been sent: no visitor waits for the
 * database except the very first one after a change (as the Next.js shop
 * does with its pages).
 *
 * Every change made in this back office, and every order that takes counted
 * stock, empties it at once. A change made in the Next.js back office shows
 * here after a minute and one visit.
 */
final class Cache
{
    public const TTL = 60;
    /** How long a copy past its minute may still be served while it is read again. */
    public const STALE = 86400;
    private static array $memory = [];
    /** Copies to read again once the page is sent: key => [file, make, ttl]. */
    private static array $pending = [];

    private static function dir(): string
    {
        return ROOT . '/storage/cache';
    }

    /** The current generation: flush() moves to a new one, so every old file is ignored at once. */
    private static function generation(): string
    {
        $f = self::dir() . '/generation';
        $g = @file_get_contents($f);
        return $g !== false && $g !== '' ? trim($g) : '0';
    }

    /** The value under $key, made by $make when missing or older than $ttl seconds. */
    public static function remember(string $key, callable $make, int $ttl = self::TTL): mixed
    {
        if (!empty($GLOBALS['MENA_NO_CACHE'])) {
            return $make();
        }
        $full = self::generation() . '|' . $key;
        if (array_key_exists($full, self::$memory)) {
            return self::$memory[$full];
        }
        $file = self::dir() . '/' . hash('sha256', $full) . '.cache';
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($data) && array_key_exists('value', $data)) {
                $until = (int) ($data['until'] ?? 0);
                if ($until > time()) {
                    return self::$memory[$full] = $data['value'];
                }
                if ($until + self::STALE > time()) {
                    self::later($full, $file, $make, $ttl);
                    return self::$memory[$full] = $data['value'];
                }
            }
        }
        $value = $make();
        self::write($file, $value, $ttl);
        return self::$memory[$full] = $value;
    }

    private static function write(string $file, mixed $value, int $ttl): void
    {
        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0775, true);
        }
        if (random_int(1, 200) === 1) {
            self::sweep();
        }
        // Written aside then renamed: a visitor never reads half a file.
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, serialize(['until' => time() + $ttl, 'value' => $value])) !== false) {
            @rename($tmp, $file);
        }
    }

    /** Reads a copy again once the page is sent (once per request, one visitor at a time per copy). */
    private static function later(string $key, string $file, callable $make, int $ttl): void
    {
        if (!self::$pending) {
            register_shutdown_function([self::class, 'refresh']);
        }
        self::$pending[$key] = [$file, $make, $ttl];
    }

    /** @internal Runs after the page: sends it first, then reads the expired copies again. */
    public static function refresh(): void
    {
        $jobs = self::$pending;
        self::$pending = [];
        if (!$jobs) {
            return;
        }
        finish_response();
        ignore_user_abort(true);
        foreach ($jobs as [$file, $make, $ttl]) {
            $lock = "$file.lock";
            // Another visitor's request is already reading it: leave it.
            $fh = @fopen($lock, 'x');
            if (!$fh) {
                if (@filemtime($lock) < time() - 30) {
                    @unlink($lock);
                }
                continue;
            }
            try {
                self::write($file, $make(), $ttl);
            } catch (Throwable $e) {
                error_log('[cache] refresh: ' . $e->getMessage());
            } finally {
                fclose($fh);
                @unlink($lock);
            }
        }
    }

    /** Forget everything (a change in the back office, an order). Old files are removed now and then. */
    public static function flush(): void
    {
        self::$memory = [];
        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0775, true);
        }
        @file_put_contents(self::dir() . '/generation', (string) (time() . random_int(100, 999)));
        if (random_int(1, 20) === 1) {
            self::sweep();
        }
    }

    /** Removes files no longer used (older than the longest a copy can be served). */
    private static function sweep(): void
    {
        foreach (glob(self::dir() . '/*.cache') ?: [] as $f) {
            if (@filemtime($f) < time() - self::STALE - 3600) {
                @unlink($f);
            }
        }
    }
}
