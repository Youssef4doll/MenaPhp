<?php
declare(strict_types=1);

/** Text safe to print inside HTML (and inside attributes, with their quotes). */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A new id, like the old shop's: 25 lowercase letters and digits, starting with a letter. */
function new_id(): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id = 'c';
    for ($i = 0; $i < 24; $i++) {
        $id .= $alphabet[random_int(0, 35)];
    }
    return $id;
}

/** "Tapis de bain antidérapant" → "tapis-de-bain-antiderapant". Arabic-only names get a short random address. */
function slugify(string $s): string
{
    $s = trim($s);
    if (class_exists('Transliterator')) {
        $t = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        if ($t) {
            $s = (string) $t->transliterate($s);
        }
    }
    $s = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    $s = trim($s, '-');
    if ($s === '') {
        $s = 'produit-' . substr(new_id(), 1, 6);
    }
    return substr($s, 0, 120);
}

/** The page's language, set once by the router. */
function lang(): string
{
    return $GLOBALS['MENA_LANG'] ?? 'fr';
}

/** A shop address in the page's language: French at the root, Arabic under /ar. */
function url(string $path = '/', ?string $forLang = null): string
{
    $l = $forLang ?? lang();
    $path = '/' . ltrim($path, '/');
    if ($l === 'ar') {
        return $path === '/' ? '/ar' : '/ar' . $path;
    }
    return $path;
}

/** The full address (for links sent outside: WhatsApp, sitemap). */
function absolute_url(string $path): string
{
    return rtrim((string) config('site_url', ''), '/') . $path;
}

function redirect(string $to, int $code = 303): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** A posted field as trimmed text, at most $max characters. */
function post_str(string $key, int $max = 500): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function get_str(string $key, int $max = 200): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

/** The visitor's address as the host reports it. */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Whether the request came over https (directly or through the host's proxy). */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** One line of text per specification ("Matière : inox") → [label, value] pairs. */
function spec_rows(?string $specs): array
{
    $rows = [];
    foreach (preg_split('/\R/u', (string) $specs) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s*[:：]\s*/u', $line, 2);
        $rows[] = count($parts) === 2 ? [$parts[0], $parts[1]] : ['', $line];
    }
    return $rows;
}

/**
 * A social profile's address (https), or "" when it is not a profile on that
 * site (social.ts profileUrl): a bare or wrong link reads as a dead link.
 */
function profile_url(string $kind, string $raw): string
{
    $hosts = ['instagram' => ['instagram.com'], 'facebook' => ['facebook.com', 'fb.com'], 'tiktok' => ['tiktok.com']][$kind] ?? null;
    $s = trim($raw);
    if ($hosts === null || $s === '') {
        return '';
    }
    if (preg_match('/^@[\w.]{2,}$/', $s) && $kind !== 'facebook') {
        $s = 'https://www.' . $hosts[0] . '/' . ($kind === 'tiktok' ? $s : substr($s, 1));
    }
    if (!preg_match('#^https?://#i', $s)) {
        $s = 'https://' . $s;
    }
    $u = parse_url($s);
    if (!$u || empty($u['host'])) {
        return '';
    }
    $host = (string) preg_replace('/^(www|m|web|vm)\./', '', strtolower($u['host']));
    if (!in_array($host, $hosts, true)) {
        return '';
    }
    $first = array_values(array_filter(explode('/', $u['path'] ?? '')))[0] ?? '';
    $not = ['login', 'accounts', 'explore', 'sharer', 'sharer.php', 'share', 'dialog', 'home.php', 'watch', 'groups', 'hashtag', 'tag', 'search'];
    if ($first === '' || in_array(strtolower($first), $not, true)) {
        return '';
    }
    parse_str($u['query'] ?? '', $query);
    if (($kind === 'tiktok' && !str_starts_with($first, '@')) || ($kind === 'facebook' && $first === 'profile.php' && empty($query['id']))) {
        return '';
    }
    $out = 'https://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
    return rtrim($out, '/');
}

/** A file of public/ with its version, so it can be kept a year by browsers: "/assets/shop.css?v=1a2b3c4d". */
function asset(string $path): string
{
    static $v = [];
    $file = ROOT . '/public' . $path;
    $v[$path] ??= is_file($file) ? substr(md5((string) filemtime($file) . filesize($file)), 0, 8) : '0';
    return $path . '?v=' . $v[$path];
}

/**
 * Sends the page now, completely, so work left for afterwards never makes the
 * visitor wait (once per request; the PHP-FPM, LiteSpeed and Apache ways).
 */
function finish_response(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        // Other servers (Apache module, PHP's own): the page is sent now with its length, so the browser
        // has all of it and does not wait for the connection to close.
        $out = '';
        while (ob_get_level() > 0) {
            $out = (string) ob_get_clean() . $out;
        }
        if (!headers_sent()) {
            header('Content-Length: ' . strlen($out));
            header('Connection: close');
        }
        echo $out;
        @flush();
    }
    ignore_user_abort(true);
}

/**
 * Work that must happen but need not delay the answer (afterResponse): the
 * owner's new-order e-mail, the customer's SMS. A failure is logged and never
 * undoes what the answer said. In the tests it runs at once.
 */
function after_response(callable $task): void
{
    static $tasks = null;
    $run = static function (callable $t): void {
        try {
            $t();
        } catch (Throwable $e) {
            error_log('[after] ' . $e->getMessage());
        }
    };
    if (!empty($GLOBALS['MENA_TESTING'])) {
        $run($task);
        return;
    }
    if ($tasks === null) {
        $tasks = [];
        register_shutdown_function(static function () use (&$tasks, $run) {
            finish_response();
            foreach ($tasks as $t) {
                $run($t);
            }
        });
    }
    $tasks[] = $task;
}
