<?php
declare(strict_types=1);

/**
 * JavaScript's own string rules, for the code ported from the Next.js back
 * office: lengths and cuts count UTF-16 units (as "".length and .slice do),
 * trim() removes every Unicode space, and slugify() is lib/slug.ts.
 */
final class Text
{
    /** JavaScript's \s: every Unicode space, line ends included. */
    public const WS = '[\s\x{FEFF}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

    /** "".length */
    public static function len(string $s): int
    {
        if ($s === '' || !preg_match('/[^\x00-\x7F]/', $s)) {
            return strlen($s);
        }
        return intdiv(strlen((string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** "".slice(start, end) with a start of 0 or more. A character cut in two is dropped, never half kept. */
    public static function slice(string $s, int $start, ?int $end = null): string
    {
        if (!preg_match('/[^\x00-\x7F]/', $s)) {
            return $end === null ? substr($s, $start) : substr($s, $start, max(0, $end - $start));
        }
        $u = (string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $n = intdiv(strlen($u), 2);
        $end = $end === null ? $n : min($n, max(0, $end));
        if ($end <= $start) {
            return '';
        }
        $part = substr($u, $start * 2, ($end - $start) * 2);
        // A surrogate pair cut at either end: that half goes.
        $first = ord($part[1] ?? "\0");
        if ($first >= 0xDC && $first <= 0xDF) {
            $part = substr($part, 2);
        }
        $last = ord($part[strlen($part) - 1] ?? "\0");
        if (strlen($part) >= 2 && $last >= 0xD8 && $last <= 0xDB) {
            $part = substr($part, 0, -2);
        }
        return (string) mb_convert_encoding($part, 'UTF-8', 'UTF-16LE');
    }

    /** String.prototype.trim: every space Unicode knows, line ends included. */
    public static function trim(string $s): string
    {
        return (string) preg_replace('/^' . self::WS . '+|' . self::WS . '+$/u', '', $s);
    }

    /** Boolean(s && s.trim()) */
    public static function filled(?string $s): bool
    {
        return $s !== null && self::trim($s) !== '';
    }

    /** lib/slug.ts: "Lampe de chevet — Édition 2" → "lampe-de-chevet-edition-2"; Arabic only → "". */
    public static function slugify(string $s): string
    {
        if (class_exists('Normalizer')) {
            $s = (string) Normalizer::normalize($s, Normalizer::FORM_D);
        }
        $s = (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace(['œ', 'æ'], ['oe', 'ae'], $s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = (string) preg_replace('/^-+|-+$/', '', $s);
        return substr($s, 0, 100);
    }

    /** What a WHATWG URL parser accepts (zod's z.url()), near enough: a scheme, and a host for web addresses. */
    public static function isUrl(string $s): bool
    {
        if (!preg_match('#^([a-z][a-z0-9+.\-]*):(.*)$#is', $s, $m) || preg_match('/[\s<>"`{}|\\\\^]/', $s)) {
            return false;
        }
        if (in_array(strtolower($m[1]), ['http', 'https', 'ftp', 'ws', 'wss'], true)) {
            $rest = ltrim($m[2], '/');
            $host = (string) preg_replace('#[/?\#].*$#s', '', $rest);
            $host = (string) preg_replace('/^[^@]*@/', '', $host);
            $host = (string) preg_replace('/:\d*$/', '', $host);
            return $host !== '' && !str_contains($host, '%');
        }
        return true;
    }

    /** One line: every run of spaces and line ends becomes one space. */
    public static function oneLine(?string $s, int $max): string
    {
        return self::slice(self::trim((string) preg_replace('/' . self::WS . '+/u', ' ', (string) $s)), 0, $max);
    }

    /**
     * JSON.stringify(value, null, indent): the same text as JavaScript writes, so a request
     * built here reads exactly as the Next.js shop's. Lists are PHP lists; objects are
     * arrays with keys (an empty one is written "{}" only when given as an object).
     */
    public static function json(mixed $v, int $indent = 0, string $at = ''): string
    {
        if ($v instanceof stdClass) {
            $v = (array) $v;
            if ($v === []) {
                return '{}';
            }
        }
        if (!is_array($v)) {
            if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1e21) {
                return (string) (int) $v;
            }
            return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $list = array_is_list($v);
        if ($v === []) {
            return '[]';
        }
        $inner = $at . str_repeat(' ', $indent);
        $parts = [];
        foreach ($v as $k => $x) {
            $val = self::json($x, $indent, $inner);
            $parts[] = $list ? $val : self::json((string) $k) . ($indent ? ': ' : ':') . $val;
        }
        [$open, $close] = $list ? ['[', ']'] : ['{', '}'];
        if (!$indent) {
            return $open . implode(',', $parts) . $close;
        }
        return $open . "\n" . $inner . implode(",\n" . $inner, $parts) . "\n" . $at . $close;
    }
}
