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
        $r->get('/api/products/{slug}', [self::class, 'product']);
        $r->post('/api/quote', [self::class, 'quote']);
        $r->post('/api/orders', [self::class, 'order']);
        $r->post('/api/cart', [self::class, 'cart']);
        $r->post('/api/events', [self::class, 'events']);
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

    /** What the quick order form needs: the product as its page shows it, and the public settings. */
    public static function product(string $slug): void
    {
        if (!preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
            self::json(['error' => 'not_found'], 404);
        }
        $p = Catalog::productFor($slug, null);
        if (!$p) {
            self::json(['error' => 'not_found'], 404);
        }
        // The sheet shows the form, not the page: no reviews, related products or long texts.
        unset($p['related']);
        $p = array_replace($p, ['reviews' => [], 'description' => '', 'descriptionAr' => null, 'specs' => [], 'specsAr' => null, 'detailImages' => [], 'video' => null]);
        self::json(['product' => $p, 'settings' => Catalog::publicSettings()], 200, ['Cache-Control' => 'public, max-age=30, s-maxage=60']);
    }

    /** Writes from another site are refused: the shop's own pages are the only callers (sameOrigin). */
    private static function sameOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        return $host !== null && ($host . ($port ? ":$port" : '')) === ($_SERVER['HTTP_HOST'] ?? '');
    }

    /** The lines of a basket as the pages send them, or null when they are not lines. */
    private static function lines(mixed $raw): ?array
    {
        if (!is_array($raw) || count($raw) > 30) {
            return null;
        }
        $out = [];
        foreach ($raw as $l) {
            if (!is_array($l) || !is_string($l['productId'] ?? null) || strlen($l['productId']) > 40 || !is_int($l['qty'] ?? null) || $l['qty'] < 1 || $l['qty'] > 10) {
                return null;
            }
            foreach (['variantId', 'offerId'] as $k) {
                if (isset($l[$k]) && (!is_string($l[$k]) || strlen($l[$k]) > 40)) {
                    return null;
                }
            }
            $out[] = ['productId' => $l['productId'], 'variantId' => $l['variantId'] ?? null, 'offerId' => $l['offerId'] ?? null, 'qty' => $l['qty']];
        }
        return $out;
    }

    /** Prices a basket for display; the same function prices the order itself. */
    public static function quote(): void
    {
        self::limited('quote');
        $d = self::body();
        $lines = self::lines($d['lines'] ?? null);
        $str = static fn ($k, $max) => isset($d[$k]) ? (is_string($d[$k]) && mb_strlen($d[$k]) <= $max ? $d[$k] : false) : null;
        $gov = $str('governorate', 40);
        $code = $str('promoCode', 40);
        $phone = $str('phone', 30);
        if ($d === null || $lines === null || $gov === false || $code === false || $phone === false) {
            self::json(['ok' => false], 400);
        }
        // A price check with a code also says whether the code exists: tighter, so codes cannot be guessed.
        if ($code) {
            self::limited('promo');
        }
        $GLOBALS['MENA_NO_CACHE'] = true;
        self::json(Pricing::publicQuote(Pricing::quoteCart($lines, $gov ?: null, $code ?: null, $phone ? Phone::normalize($phone) : null)));
    }

    /** Places an order (the order form, the quick form, the basket). */
    public static function order(): void
    {
        if (!self::sameOrigin()) {
            self::json(['ok' => false, 'error' => 'server'], 403);
        }
        self::limited('order');
        $raw = self::body(32768);
        if ($raw === null) {
            self::json(['ok' => false, 'error' => 'invalid', 'fields' => []], 400);
        }
        [$in, $bad] = Orders::validate($raw);
        if ($bad) {
            self::json(['ok' => false, 'error' => 'invalid', 'fields' => $bad], 400);
        }
        // A filled honeypot gets a plausible refusal and nothing is written.
        if (($in['website'] ?? '') !== '') {
            self::json(['ok' => false, 'error' => 'server'], 400);
        }
        $GLOBALS['MENA_NO_CACHE'] = true;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ip = client_ip();
        $r = Orders::place($in, ['ua' => $ua, 'ip' => $ip, 'ipHash' => Tokens::hashIp($ip), 'device' => Events::device($ua)]);
        if ($r['ok']) {
            Shop::rememberOrder($r['token']);
            self::json(['ok' => true, 'ref' => $r['ref']]);
        }
        self::json($r, $r['error'] === 'server' ? 500 : ($r['error'] === 'invalid' ? 400 : 409));
    }

    /**
     * The server's copy of an unfinished form, for the back office's list of
     * forms to call back. Ids and quantities only. A form that became an order
     * is never reopened by a late write.
     */
    public static function cart(): void
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (!self::sameOrigin() || Events::isBot($ua)) {
            self::json(['ok' => false], 403);
        }
        // The shop's own tests are not unfinished orders to call back.
        if (Events::isInternal()) {
            self::json(['ok' => true]);
        }
        self::limited('cart');
        $d = self::body();
        $lines = self::lines($d['lines'] ?? null);
        $ok = $d !== null && $lines !== null && is_string($d['token'] ?? null) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $d['token']);
        foreach (['phone' => 30, 'name' => 80, 'governorate' => 40, 'visitorId' => 64, 'source' => 60, 'campaign' => 120] as $k => $max) {
            $ok = $ok && (!isset($d[$k]) || (is_string($d[$k]) && mb_strlen($d[$k]) <= $max));
        }
        if (!$ok) {
            self::json(['ok' => false], 400);
        }
        $hash = Tokens::hash($d['token']);
        $phone = !empty($d['phone']) ? Phone::normalize($d['phone']) : null;
        $gov = !empty($d['governorate']) && Gov::valid($d['governorate']) ? $d['governorate'] : null;
        $name = ($d['name'] ?? '') !== '' ? $d['name'] : null;
        $json = json_encode($lines, JSON_UNESCAPED_SLASHES);
        $now = Db::now();
        $existing = Db::one('SELECT id, status, to_char("updatedAt", \'YYYY-MM-DD HH24:MI:SS\') AS "updatedAt" FROM "Cart" WHERE "tokenHash" = ?', [$hash]);
        if ($existing && $existing['status'] === 'CONVERTED') {
            // Much later, this browser fills a new form (its earlier order was placed elsewhere): a new token, so it is seen.
            $renew = time() - strtotime($existing['updatedAt'] . ' UTC') > 30 * 60;
            self::json(['ok' => true] + ($renew ? ['renew' => true] : []));
        }
        if ($existing) {
            $set = ['lines' => $json, 'updatedAt' => $now];
            if ($phone) {
                $set['phone'] = $phone;
            }
            if ($name) {
                $set['name'] = $name;
            }
            if ($gov) {
                $set['governorate'] = $gov;
            }
            Db::update('Cart', $set, 'id = ?', [$existing['id']]);
        } else {
            try {
                Db::insert('Cart', ['id' => new_id(), 'tokenHash' => $hash, 'lines' => $json, 'phone' => $phone, 'name' => $name, 'governorate' => $gov, 'visitorId' => $d['visitorId'] ?? null, 'source' => $d['source'] ?? null, 'campaign' => $d['campaign'] ?? null, 'status' => 'ACTIVE', 'createdAt' => $now, 'updatedAt' => $now]);
            } catch (PDOException $e) {
                // The same form saved twice at once: the other save made the row.
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
            }
        }
        self::json(['ok' => true]);
    }

    /** First-party statistics: unknown names, bots and the shop's own visits are not counted. */
    public static function events(): void
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (Events::isBot($ua) || Events::isInternal()) {
            self::json(['ok' => true, 'accepted' => 0]);
        }
        self::limited('events');
        $d = self::body(32768);
        try {
            self::json(['ok' => true, 'accepted' => $d === null ? throw new InvalidArgumentException('body') : Events::intake($d, $ua)]);
        } catch (InvalidArgumentException) {
            self::json(['ok' => false], 400);
        }
    }
}
