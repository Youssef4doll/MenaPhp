<?php
declare(strict_types=1);

/**
 * The JSON addresses the shop's pages call, the same as the Next.js shop's
 * src/app/api/*: same paths, same answers, so the browser code is the same.
 */
final class Api
{
    public static function routes(Router $r): void
    {
        $r->get('/api/suggest', [self::class, 'suggest']);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $k => $v) {
            header("$k: $v");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function limited(string $kind): void
    {
        if (!RateLimit::hit($kind, client_ip())) {
            self::json(['error' => 'Trop de demandes. Réessayez dans une minute.'], 429, ['Retry-After' => '60']);
        }
    }

    /** The JSON body of a POST, at most $max bytes; null when it is not JSON. */
    public static function body(int $max = 65536): ?array
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /** As the customer types: completions, products, categories (suggest). */
    public static function suggest(): void
    {
        self::limited('suggest');
        $GLOBALS['MENA_LANG'] = 'fr';
        self::json(Catalog::suggest(get_str('q', 200)), 200, ['Cache-Control' => 'public, max-age=60, s-maxage=60']);
    }
}
