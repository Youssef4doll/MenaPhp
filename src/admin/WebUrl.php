<?php
declare(strict_types=1);

/**
 * Web addresses as JavaScript's URL reads them, near enough for the back
 * office: an address made absolute against its page, written back in the
 * same shape (lower-case scheme and host, a "/" path, spaces as %20), and its
 * query read as URLSearchParams does.
 */
final class WebUrl
{
    public function __construct(
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $path,
        public string $query,
        public string $fragment,
        public string $user = '',
    ) {
    }

    /** new URL(raw, base), or null when it is not an address. */
    public static function parse(string $raw, ?string $base = null): ?self
    {
        $raw = Text::trim($raw);
        if ($raw === '' || preg_match('/[\x00-\x1F]/', $raw)) {
            return null;
        }
        $raw = str_replace(['\\', ' '], ['/', '%20'], $raw);
        if (preg_match('#^([a-z][a-z0-9+.\-]*):(.*)$#is', $raw, $m)) {
            $scheme = strtolower($m[1]);
            $rest = $m[2];
            if (!in_array($scheme, ['http', 'https', 'ftp', 'ws', 'wss'], true)) {
                return new self($scheme, '', null, $rest, '', '');
            }
            $rest = '//' . ltrim($rest, '/');
            return self::authority($scheme, $rest);
        }
        if ($base === null || !($b = self::parse($base)) || $b->host === '') {
            return null;
        }
        if (str_starts_with($raw, '//')) {
            return self::authority($b->scheme, $raw);
        }
        [$raw, $fragment] = array_pad(explode('#', $raw, 2), 2, '');
        if ($raw === '') {
            return new self($b->scheme, $b->host, $b->port, $b->path, $b->query, $fragment, $b->user);
        }
        [$p, $q] = array_pad(explode('?', $raw, 2), 2, null);
        if ($p === '') {
            return new self($b->scheme, $b->host, $b->port, $b->path, $q ?? '', $fragment, $b->user);
        }
        $path = str_starts_with($p, '/') ? $p : substr($b->path, 0, (int) strrpos($b->path, '/') + 1) . $p;
        return new self($b->scheme, $b->host, $b->port, self::dots($path), $q ?? '', $fragment, $b->user);
    }

    private static function authority(string $scheme, string $rest): ?self
    {
        if (!preg_match('#^//([^/?\#]*)([^?\#]*)(?:\?([^\#]*))?(?:\#(.*))?$#s', $rest, $m)) {
            return null;
        }
        $auth = $m[1];
        $user = '';
        if (str_contains($auth, '@')) {
            $user = substr($auth, 0, (int) strrpos($auth, '@'));
            $auth = substr($auth, (int) strrpos($auth, '@') + 1);
        }
        $port = null;
        if (preg_match('/^(.*):(\d*)$/', $auth, $pm) && !str_ends_with($auth, ']')) {
            $auth = $pm[1];
            $port = $pm[2] === '' ? null : (int) $pm[2];
        }
        $host = strtolower($auth);
        if ($host === '' || preg_match('/[\s<>^|%]/', $host)) {
            return null;
        }
        if (preg_match('/[^\x00-\x7F]/', $host) && function_exists('idn_to_ascii')) {
            $host = (string) (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host);
        }
        $default = ['http' => 80, 'https' => 443, 'ftp' => 21, 'ws' => 80, 'wss' => 443][$scheme] ?? null;
        if ($port === $default) {
            $port = null;
        }
        $path = $m[2] === '' ? '/' : self::dots($m[2]);
        return new self($scheme, $host, $port, $path, $m[3] ?? '', $m[4] ?? '', $user);
    }

    /** "/a/./b/../c" → "/a/c" */
    private static function dots(string $path): string
    {
        $out = [];
        $parts = explode('/', $path);
        foreach ($parts as $i => $seg) {
            if ($seg === '.' || strtolower($seg) === '%2e') {
                if ($i === count($parts) - 1) {
                    $out[] = '';
                }
                continue;
            }
            if ($seg === '..' || strtolower($seg) === '%2e%2e') {
                if (count($out) > 1) {
                    array_pop($out);
                }
                if ($i === count($parts) - 1) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $seg;
        }
        $p = implode('/', $out);
        return str_starts_with($p, '/') ? $p : '/' . $p;
    }

    public function origin(): string
    {
        return $this->scheme . '://' . $this->host . ($this->port !== null ? ':' . $this->port : '');
    }

    /** url.toString() */
    public function __toString(): string
    {
        if ($this->host === '') {
            return $this->scheme . ':' . $this->path;
        }
        return $this->scheme . '://' . ($this->user !== '' ? $this->user . '@' : '') . $this->host . ($this->port !== null ? ':' . $this->port : '')
            . $this->path . ($this->query !== '' ? '?' . $this->query : '') . ($this->fragment !== '' ? '#' . $this->fragment : '');
    }

    /** url.searchParams.get(name) */
    public function param(string $name): ?string
    {
        foreach (explode('&', $this->query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($k) === $name) {
                return urldecode($v);
            }
        }
        return null;
    }

    public function hasParam(string $name): bool
    {
        return $this->param($name) !== null;
    }

    /** url.searchParams.set(name, value) */
    public function setParam(string $name, string $value): void
    {
        $pairs = [];
        $done = false;
        foreach (explode('&', $this->query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k] = explode('=', $pair, 2);
            if (urldecode($k) === $name) {
                if (!$done) {
                    $pairs[] = rawurlencode($name) . '=' . str_replace('%20', '+', rawurlencode($value));
                    $done = true;
                }
                continue;
            }
            $pairs[] = $pair;
        }
        if (!$done) {
            $pairs[] = rawurlencode($name) . '=' . str_replace('%20', '+', rawurlencode($value));
        }
        $this->query = implode('&', $pairs);
    }
}
