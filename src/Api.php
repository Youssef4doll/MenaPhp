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
        $r->get('/api/cart/{id}', [self::class, 'restoreCart']);
        $r->post('/api/orders/lookup', [self::class, 'lookup']);
        $r->post('/api/orders/{ref}/review', [self::class, 'review']);
        $r->post('/api/orders/{ref}/return', [self::class, 'returnRequest']);
        $r->post('/api/forget-device', [self::class, 'forget']);
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

    /** The lines of an unfinished order, for a reminder's link: ids, options, offers and names, no person, no price. */
    public static function restoreCart(string $id): void
    {
        self::limited('cart');
        if (!preg_match('/^[a-z0-9]{20,40}$/', $id)) {
            self::json(['items' => []], 404);
        }
        $cart = Db::one('SELECT lines, status FROM "Cart" WHERE id = ?', [$id]);
        if (!$cart || $cart['status'] !== 'ACTIVE') {
            self::json(['items' => []], 404);
        }
        $lines = json_decode((string) $cart['lines'], true) ?: [];
        $catalog = Catalog::forPricing(array_values(array_filter(array_map(static fn ($l) => $l['productId'] ?? '', $lines))));
        $now = Db::now();
        $items = [];
        foreach (array_slice($lines, 0, 30) as $l) {
            $p = $catalog[$l['productId'] ?? ''] ?? null;
            if (!$p || !$p['active']) {
                continue;
            }
            $v = null;
            foreach ($p['variants'] as $x) {
                if ($x['id'] === ($l['variantId'] ?? null)) {
                    $v = $x;
                }
            }
            $o = null;
            foreach ($p['offers'] as $x) {
                if ($x['id'] === ($l['offerId'] ?? null) && Pricing::offerLive($x, $now)) {
                    $o = $x;
                }
            }
            $qty = is_int($l['qty'] ?? null) ? min(10, max(1, $l['qty'])) : 1;
            $items[] = ['productId' => $p['id'], 'variantId' => $v['id'] ?? null, 'offerId' => $o['id'] ?? null, 'qty' => $o ? 1 : $qty, 'slug' => $p['slug'], 'name' => $p['name'], 'nameAr' => $p['nameAr'], 'variantName' => $v['name'] ?? null, 'variantNameAr' => $v['nameAr'] ?? null, 'offerLabel' => $o ? OfferLabel::line($o, 'fr') : null, 'offerLabelAr' => $o ? OfferLabel::line($o, 'ar') : null, 'imageId' => $p['imageId']];
        }
        self::json(['items' => $items]);
    }

    /** Order reference AND the full phone number, both, or nothing. */
    public static function lookup(): void
    {
        if (!self::sameOrigin()) {
            self::json(['ok' => false], 403);
        }
        self::limited('lookup');
        $d = self::body();
        if (!is_string($d['ref'] ?? null) || strlen($d['ref']) > 20 || !is_string($d['phone'] ?? null) || strlen($d['phone']) > 30) {
            self::json(['ok' => false], 400);
        }
        $phone = Phone::normalize($d['phone']);
        $ref = (string) preg_replace('/^MN(?!-)/', 'MN-', strtoupper(trim($d['ref'])));
        if (!$phone) {
            self::json(['ok' => false], 404);
        }
        $o = Db::one('SELECT ref, "accessSalt" FROM "Order" WHERE ref = ? AND phone = ?', [$ref, $phone]);
        if (!$o) {
            self::json(['ok' => false], 404);
        }
        Shop::rememberOrder(Tokens::orderToken($o['accessSalt']));
        self::json(['ok' => true, 'ref' => $o['ref']]);
    }

    /** A review: this browser's order, delivered, a line of it with no review yet. Then it waits for moderation. */
    public static function review(string $ref): void
    {
        if (!self::sameOrigin()) {
            self::json(['ok' => false], 403);
        }
        self::limited('review');
        $orderId = Orders::accessible($ref);
        if (!$orderId) {
            self::json(['ok' => false], 403);
        }
        $d = self::body();
        $text = is_string($d['text'] ?? null) ? trim($d['text']) : null;
        $name = is_string($d['authorName'] ?? null) ? trim($d['authorName']) : '';
        $rating = $d['rating'] ?? null;
        if (!is_string($d['itemId'] ?? null) || strlen($d['itemId']) > 40 || !is_int($rating) || $rating < 1 || $rating > 5 || ($text !== null && mb_strlen($text) > 800) || $name === '' || mb_strlen($name) > 40) {
            self::json(['ok' => false], 400);
        }
        $item = Db::one('SELECT i.id, i."productId", o.governorate, (SELECT r.id FROM "Review" r WHERE r."orderItemId" = i.id) AS "reviewId" FROM "OrderItem" i JOIN "Order" o ON o.id = i."orderId" WHERE i.id = ? AND i."orderId" = ? AND o.status = \'DELIVERED\' AND i."productId" IS NOT NULL', [$d['itemId'], $orderId]);
        if (!$item || $item['reviewId']) {
            self::json(['ok' => false], 409);
        }
        Db::insert('Review', ['id' => new_id(), 'productId' => $item['productId'], 'orderItemId' => $item['id'], 'rating' => $rating, 'text' => $text ?: null, 'authorName' => $name, 'governorate' => $item['governorate'], 'status' => 'PENDING', 'createdAt' => Db::now()]);
        self::json(['ok' => true]);
    }

    /** A return request, within the return days of a delivered order, one open at a time. */
    public static function returnRequest(string $ref): void
    {
        if (!self::sameOrigin()) {
            self::json(['ok' => false], 403);
        }
        self::limited('review');
        $orderId = Orders::accessible($ref);
        if (!$orderId) {
            self::json(['ok' => false], 403);
        }
        $d = self::body();
        $reason = is_string($d['reason'] ?? null) ? trim($d['reason']) : '';
        $details = is_string($d['details'] ?? null) ? trim($d['details']) : null;
        if (mb_strlen($reason) < 2 || mb_strlen($reason) > 120 || ($details !== null && mb_strlen($details) > 600)) {
            self::json(['ok' => false], 400);
        }
        $o = Db::one('SELECT status, to_char("deliveredAt", \'YYYY-MM-DD HH24:MI:SS\') AS "deliveredAt", (SELECT count(*) FROM "ReturnRequest" r WHERE r."orderId" = "Order".id AND r.status IN (\'REQUESTED\', \'ACCEPTED\', \'RECEIVED\')) AS open FROM "Order" WHERE id = ?', [$orderId]);
        if (!$o || $o['status'] !== 'DELIVERED' || !$o['deliveredAt']) {
            self::json(['ok' => false], 409);
        }
        if (time() - strtotime($o['deliveredAt'] . ' UTC') > (int) Settings::get('returns.days', 10) * 86400) {
            self::json(['ok' => false, 'error' => 'too_late'], 409);
        }
        if ((int) $o['open'] > 0) {
            self::json(['ok' => false, 'error' => 'open'], 409);
        }
        $now = Db::now();
        Db::insert('ReturnRequest', ['id' => new_id(), 'orderId' => $orderId, 'reason' => $reason, 'details' => $details ?: null, 'createdAt' => $now, 'updatedAt' => $now]);
        self::json(['ok' => true]);
    }

    /** "Forget this device": this browser no longer lists its orders (they stay with the shop). */
    public static function forget(): void
    {
        if (!self::sameOrigin()) {
            self::json(['ok' => false], 403);
        }
        setcookie('mena_orders', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        self::json(['ok' => true]);
    }
}
