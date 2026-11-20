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
