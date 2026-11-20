<?php
declare(strict_types=1);

/** The storefront's pages. Every price shown is computed here, from the database. */
final class Shop
{
    public static function routes(Router $r): void
    {
        $r->get('/', [self::class, 'home']);
        $r->get('/boutique', [self::class, 'all']);
        $r->get('/categorie/{slug}', [self::class, 'category']);
        $r->get('/produit/{slug}', [self::class, 'product']);
        $r->get('/recherche', [self::class, 'search']);
        $r->get('/panier', [self::class, 'cart']);
        $r->post('/panier', [self::class, 'cartAction']);
        $r->post('/commander', [self::class, 'order']);
        $r->get('/commande/merci/{ref}', [self::class, 'thanks']);
        $r->get('/suivi', [self::class, 'lookup']);
        $r->post('/suivi', [self::class, 'lookup']);
        $r->get('/suivi/t/{token}', [self::class, 'track']);
        foreach (['livraison-retours', 'contact', 'conditions', 'confidentialite'] as $page) {
            $r->get("/$page", static fn () => self::info($page));
        }
    }

    /** First touch from an ad or a post (utm_source / utm_campaign), kept for the order. */
    private static function remember(): void
    {
        $src = get_str('utm_source', 80);
        if ($src !== '') {
            Cart::session();
            $_SESSION['attribution'] ??= ['source' => $src, 'campaign' => get_str('utm_campaign', 120)];
        }
    }

    private static function page(string $template, array $data, string $title, ?string $description = null, ?string $canonical = null): void
    {
        View::render($template, $data + [
            'title' => $title,
            'description' => $description ?? (lang() === 'ar' ? Settings::get('shop.taglineAr') : Settings::get('shop.tagline')),
            'canonical' => $canonical,
        ]);
    }

    public static function notFound(): void
    {
        http_response_code(404);
        self::page('shop/notfound', [], t('notFound.title'));
    }

    public static function home(): void
    {
        self::remember();
        $featured = Catalog::cards('p.featured = true', [], 'p."updatedAt" DESC', 12);
        $newest = Catalog::cards('true', [], 'p."createdAt" DESC', 12);
        self::page('shop/home', [
            'categories' => Catalog::categories(),
            'featured' => $featured,
            'newest' => $newest,
        ], Settings::get('shop.name'), null, url('/'));
    }

    private const SORTS = ['new' => 'p."createdAt" DESC', 'price_asc' => 'p.price ASC, p."createdAt" DESC', 'price_desc' => 'p.price DESC, p."createdAt" DESC'];
    private const PER_PAGE = 24;

    private static function listing(string $where, array $params, string $title, string $path, ?array $category = null): void
    {
        self::remember();
        $sort = array_key_exists(get_str('tri'), self::SORTS) ? get_str('tri') : 'new';
        $pageNo = max(1, (int) get_str('page'));
        $total = Catalog::count($where, $params);
        $cards = Catalog::cards($where, $params, self::SORTS[$sort], self::PER_PAGE, ($pageNo - 1) * self::PER_PAGE);
        self::page('shop/listing', [
            'heading' => $title,
            'cards' => $cards,
            'total' => $total,
            'sort' => $sort,
            'pageNo' => $pageNo,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'path' => $path,
            'category' => $category,
        ], $title, null, url($path));
    }

    public static function all(): void
    {
        self::listing('true', [], t('nav.shop'), '/boutique');
    }

    public static function category(string $slug): void
    {
        $c = Catalog::category($slug);
        if (!$c) {
            self::notFound();
            return;
        }
        if (isset($c['redirect'])) {
            redirect(url('/categorie/' . rawurlencode($c['redirect'])), 301);
        }
        self::listing('p."categoryId" = ?', [$c['id']], Catalog::name($c), '/categorie/' . $slug, $c);
    }

    public static function search(): void
    {
        $q = get_str('q', 80);
        $cards = $q !== '' ? Catalog::search($q) : [];
        header('X-Robots-Tag: noindex');
        self::page('shop/search', ['q' => $q, 'cards' => $cards], t('search.title'));
    }

    /** The product page. $form: what was typed, when an order attempt comes back with a problem. */
    public static function product(string $slug, array $form = []): void
    {
        self::remember();
        $p = Catalog::product($slug);
        if (!$p) {
            self::notFound();
            return;
        }
        if (isset($p['redirect'])) {
            redirect(url('/produit/' . rawurlencode($p['redirect'])), 301);
        }
        $now = Db::now();
        $offers = array_values(array_filter($p['offers'], static fn ($o) => Pricing::offerLive($o, $now) && $o['quantity'] >= 1));
        $catalog = Catalog::forDisplay([$p['id']]);
        $variants = $p['variants'];
        // The choice shown first: what was sent back, else the first offer, else one unit; the first option that can be sold.
        $variantId = $form['variantId'] ?? null;
        if ($variants && !in_array($variantId, array_column($variants, 'id'), true)) {
            $variantId = null;
            foreach ($variants as $v) {
                if (Pricing::sellable($p, $v) > 0) {
                    $variantId = $v['id'];
                    break;
                }
            }
        }
        $offerId = array_key_exists('offerId', $form) ? $form['offerId'] : ($offers[0]['id'] ?? null);
        $qty = max(1, (int) ($form['qty'] ?? 1));
        $gov = $form['governorate'] ?? null;
        $quote = Pricing::quote([['productId' => $p['id'], 'variantId' => $variantId, 'offerId' => $offerId, 'qty' => $qty]], $catalog, $gov ?: null, Settings::get('delivery'));
        $related = $p['categoryId'] ? Catalog::cards('p."categoryId" = ? AND p.id <> ?', [$p['categoryId'], $p['id']], 'p.featured DESC, p."createdAt" DESC', 8) : [];
        $name = Catalog::name($p);
        $summary = Catalog::name($p, 'summary');
        self::page('shop/product', [
            'p' => $p,
            'offers' => $offers,
            'variantId' => $variantId,
            'offerId' => $offerId,
            'qty' => $qty,
            'quote' => $quote,
            'related' => $related,
            'form' => $form,
            'sellable' => Pricing::anySellable($p, $variants),
        ], $name . ' · ' . Settings::get('shop.name'), $summary !== '' ? $summary : null, url('/produit/' . $p['slug']));
    }

    public static function cart(array $form = []): void
    {
        $lines = Cart::lines();
        $catalog = Catalog::forDisplay(array_column($lines, 'productId'));
        $quote = Pricing::quote($lines, $catalog, ($form['governorate'] ?? '') ?: null, Settings::get('delivery'));
        $notes = [];
        foreach ($quote['problems'] as $pb) {
            $p = $catalog[$pb['productId']] ?? null;
            $n = $p ? Catalog::name($p) : '';
            $notes[] = match ($pb['kind']) {
                'qty_reduced' => t('cart.qtyReduced', $n, (int) $pb['available']),
                'offer_unavailable' => t('cart.offerGone', $n),
                'variant_required' => t('cart.needsOption', $n),
                default => t('cart.gone', $n !== '' ? $n : '…'),
            };
        }
        if ($quote['problems']) {
            Cart::settle($quote);
            $quote = Pricing::quote(Cart::lines(), $catalog, ($form['governorate'] ?? '') ?: null, Settings::get('delivery'));
        }
        header('X-Robots-Tag: noindex');
        self::page('shop/cart', ['quote' => $quote, 'notes' => $notes, 'form' => $form], t('cart.title'));
    }

    public static function cartAction(): void
    {
        if (!Csrf::valid()) {
            redirect(url('/panier'));
        }
        if (!RateLimit::hit('cart', client_ip())) {
            http_response_code(429);
            exit;
        }
        $do = post_str('do', 10);
        if ($do === 'add') {
            $pid = post_str('productId', 40);
            $p = Catalog::forDisplay([$pid])[$pid] ?? null;
            if ($p && $p['active']) {
                $vid = post_str('variantId', 40) ?: null;
                $oid = post_str('offerId', 40) ?: null;
                if ($oid === 'unit') {
                    $oid = null;
                }
                if (!Cart::add($pid, $vid, $oid, (int) post_str('qty', 3))) {
                    $_SESSION['flash'] = t('cart.full');
                } else {
                    $_SESSION['flash'] = t('cart.added');
                }
            }
        } elseif ($do === 'qty') {
            Cart::setQty((int) post_str('i', 3), (int) post_str('qty', 3));
        } elseif ($do === 'remove') {
            Cart::setQty((int) post_str('i', 3), 0);
        }
        redirect(url('/panier'));
    }

    /** An order, from the product page's form or from the basket. */
    public static function order(): void
    {
        $origin = post_str('origin', 10) === 'cart' ? 'cart' : 'product';
        $form = [
            'name' => post_str('name', 120),
            'phone' => post_str('phone', 30),
            'governorate' => post_str('governorate', 40),
            'address' => post_str('address', 500),
            'variantId' => post_str('variantId', 40) ?: null,
            'offerId' => post_str('offerId', 40) ?: null,
            'qty' => (int) post_str('qty', 3),
        ];
        if ($form['offerId'] === 'unit') {
            $form['offerId'] = null;
        }
        $back = static function (string $error, array $fields = []) use ($origin, $form) {
            $form['error'] = $error;
            $form['bad'] = $fields;
            if ($origin === 'cart') {
                self::cart($form);
            } else {
                $slug = Db::value('SELECT slug FROM "Product" WHERE id = ?', [post_str('productId', 40)]);
                $slug ? self::product((string) $slug, $form) : self::cart($form);
            }
        };

        if (!Csrf::valid()) {
            $back('expired');
            return;
        }
        // Honeypot: invisible to people, filled by bots.
        if (post_str('website', 200) !== '') {
            redirect(url('/'));
        }
        if (!RateLimit::hit('order', client_ip())) {
            http_response_code(429);
            $back('rate_limited');
            return;
        }
        $lines = $origin === 'cart'
            ? Cart::lines()
            : [['productId' => post_str('productId', 40), 'variantId' => $form['variantId'], 'offerId' => $form['offerId'], 'qty' => max(1, $form['qty'])]];

        Cart::session();
        $att = $_SESSION['attribution'] ?? [];
        $result = Orders::place([
            'name' => $form['name'],
            'phone' => $form['phone'],
            'governorate' => $form['governorate'],
            'address' => $form['address'],
            'lines' => $lines,
            'expectedTotal' => (int) post_str('expectedTotal', 12),
            'submitKey' => post_str('submitKey', 64),
            'locale' => lang(),
            'source' => $att['source'] ?? '',
            'campaign' => $att['campaign'] ?? '',
            'device' => preg_match('/Mobi|Android|iPhone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ? 'mobile' : 'desktop',
            'ipHash' => Tokens::hashIp(client_ip()),
        ]);
        if (!$result['ok']) {
            // The page was shown from an outdated copy (a price, an offer or stock changed meanwhile, e.g. in the
            // other back office): the copy goes, and the customer now sees the real figures to confirm again.
            if (in_array($result['error'], ['price_changed', 'cart_changed', 'empty', 'delivery_unavailable'], true)) {
                Cache::flush();
                $GLOBALS['MENA_NO_CACHE'] = true;
                Settings::forget();
            }
            if ($result['error'] === 'price_changed' && isset($result['quote'])) {
                $form['shownTotal'] = $result['quote']['total'];
            }
            $back($result['error'], $result['fields'] ?? []);
            return;
        }
        if ($origin === 'cart') {
            Cart::clear();
        }
        self::rememberOrder($result['token']);
        redirect(url('/commande/merci/' . $result['ref']));
    }

    /**
     * The order's token in an httpOnly cookie, the same "mena_orders" cookie as
     * the Next.js shop: the thank-you page and "my orders" open without the link.
     */
    private static function rememberOrder(string $token): void
    {
        $current = array_filter(explode('.', (string) ($_COOKIE['mena_orders'] ?? '')), [Tokens::class, 'looksLikeToken']);
        $next = array_slice(array_values(array_unique([$token, ...$current])), 0, 5);
        setcookie('mena_orders', implode('.', $next), ['expires' => time() + 60 * 60 * 24 * 120, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['mena_orders'] = implode('.', $next);
    }

    /** An order this browser holds the token of. */
    private static function accessible(string $ref): ?array
    {
        $tokens = array_filter(explode('.', (string) ($_COOKIE['mena_orders'] ?? '')), [Tokens::class, 'looksLikeToken']);
        foreach ($tokens as $t) {
            $o = Orders::byToken($t);
            if ($o && $o['ref'] === $ref) {
                $o['token'] = $t;
                return $o;
            }
        }
        return null;
    }

    public static function thanks(string $ref): void
    {
        header('X-Robots-Tag: noindex');
        $o = self::accessible($ref);
        if (!$o) {
            redirect(url('/suivi'));
        }
        self::page('shop/thanks', ['o' => $o], t('thanks.title'));
    }

    public static function lookup(): void
    {
        header('X-Robots-Tag: noindex');
        $error = null;
        if (is_post()) {
            if (!Csrf::valid()) {
                $error = t('errors.expired');
            } elseif (!RateLimit::hit('lookup', client_ip())) {
                $error = t('errors.rate_limited');
            } else {
                $o = Orders::byRefAndPhone(post_str('ref', 12), post_str('phone', 30));
                if ($o) {
                    $token = Tokens::orderToken($o['accessSalt']);
                    self::rememberOrder($token);
                    redirect(url('/suivi/t/' . $token));
                }
                $error = t('track.notFound');
            }
        }
        self::page('shop/lookup', ['error' => $error], t('track.title'));
    }

    public static function track(string $token): void
    {
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: no-referrer');
        $o = Orders::byToken($token);
        if (!$o) {
            self::notFound();
            return;
        }
        self::page('shop/track', ['o' => $o], t('track.title') . ' ' . $o['ref']);
    }

    public static function info(string $page): void
    {
        $titles = ['livraison-retours' => t('footer.deliveryReturns'), 'contact' => t('footer.contact'), 'conditions' => t('footer.terms'), 'confidentialite' => t('footer.privacy')];
        $freeOffers = Cache::remember('freeOffers', static fn () => (bool) Db::value('SELECT 1 FROM "ProductOffer" o JOIN "Product" p ON p.id = o."productId" WHERE o.active = true AND o."freeDelivery" = true AND p.active = true AND (o."endsAt" IS NULL OR o."endsAt" > ?) LIMIT 1', [Db::now()]));
        self::page('shop/info/' . $page, ['s' => Settings::all(), 'freeOffers' => $freeOffers, 'heading' => $titles[$page]], $titles[$page] . ' · ' . Settings::get('shop.name'), null, url('/' . $page));
    }

    public static function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\nAllow: /media/\nDisallow: /admin\nDisallow: /panier\nDisallow: /suivi\nDisallow: /commande\nDisallow: /recherche\n\nSitemap: " . absolute_url('/sitemap.xml') . "\n";
    }

    public static function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $urls = Cache::remember('sitemap', static function () {
            $urls = ['/', '/boutique', '/livraison-retours', '/contact', '/conditions', '/confidentialite'];
            foreach (Db::all('SELECT slug FROM "Category" WHERE active = true ORDER BY sort') as $c) {
                $urls[] = '/categorie/' . rawurlencode($c['slug']);
            }
            foreach (Db::all('SELECT slug FROM "Product" WHERE active = true ORDER BY "createdAt" DESC LIMIT 5000') as $p) {
                $urls[] = '/produit/' . rawurlencode($p['slug']);
            }
            return $urls;
        }, 3600);
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach ($urls as $u) {
            $fr = e(absolute_url($u));
            $ar = e(absolute_url($u === '/' ? '/ar' : '/ar' . $u));
            echo "<url><loc>$fr</loc><xhtml:link rel=\"alternate\" hreflang=\"fr\" href=\"$fr\"/><xhtml:link rel=\"alternate\" hreflang=\"ar\" href=\"$ar\"/></url>\n";
            echo "<url><loc>$ar</loc><xhtml:link rel=\"alternate\" hreflang=\"fr\" href=\"$fr\"/><xhtml:link rel=\"alternate\" hreflang=\"ar\" href=\"$ar\"/></url>\n";
        }
        echo '</urlset>';
    }
}
