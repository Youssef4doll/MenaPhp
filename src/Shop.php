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

    /** "À moins de 30 DT": the small prices' shelf, in millimes. */
    private const SMALL_PRICE = 30000;

    /** The home page, built as the Next.js shop's src/app/[lang]/page.tsx builds it. */
    public static function home(): void
    {
        self::remember();
        $settings = Settings::all();
        $home = Catalog::home();
        $cats = Catalog::homeCategories();
        $ranked = Catalog::homeFeed();
        $hero = $settings['hero'];
        $slides = $hero['slides'] ?? [];
        if (!$slides && !empty($hero['imageId'])) {
            $slides = [['imageId' => $hero['imageId'], 'link' => '', 'alt' => '', 'altAr' => '']];
        }
        $banners = [];
        foreach (array_values($slides) as $i => $b) {
            $to = self::bannerLink((string) ($b['link'] ?? ''));
            $banners[] = [
                'imageId' => $b['imageId'],
                'alt' => Ui::pick($b['alt'] ?? '', $b['altAr'] ?? ''),
                'label' => t('product.photo', $i + 1, count($slides)),
                'href' => $to ? ($to['path'] ?? null ? url($to['path']) : $to['url']) : null,
                'external' => (bool) ($to['url'] ?? false),
            ];
        }
        $shown = array_slice($cats, 0, 12);
        $best = count($home['bestsellers']) >= 4 ? array_slice($home['bestsellers'], 0, 8) : [];
        $seen = [];
        foreach ([...$home['featured'], ...$best] as $c) {
            $seen[$c['id']] = true;
        }
        $fresh = array_values(array_filter($home['newest'], static fn ($c) => !isset($seen[$c['id']])));

        // The ranked shop cut into shelves that each show something new (products already shown go last).
        $feed = $ranked['feed'];
        $used = $seen;
        $shelf = static function (array $list, int $n = 8) use (&$used): array {
            $pick = array_slice([...array_filter($list, static fn ($c) => !isset($used[$c['id']])), ...array_filter($list, static fn ($c) => isset($used[$c['id']]))], 0, $n);
            foreach ($pick as $c) {
                $used[$c['id']] = true;
            }
            return array_values($pick);
        };
        $cards = array_column($feed, 'card');
        $dealList = array_values(array_filter($cards, static fn ($c) => Rules::saving($c) >= 0.1));
        $keyed = array_map(null, $dealList, array_keys($dealList));
        usort($keyed, static fn ($a, $b) => Rules::saving($b[0]) <=> Rules::saving($a[0]) ?: $a[1] <=> $b[1]);
        $deals = $shelf(array_column($keyed, 0));
        $catOrder = array_values(array_unique(array_filter(array_column($feed, 'category'))));
        $catShelves = [];
        foreach ($catOrder as $slug) {
            $cat = null;
            foreach ($ranked['categories'] as $c) {
                if ($c['slug'] === $slug) {
                    $cat = $c;
                }
            }
            $list = array_column(array_filter($feed, static fn ($f) => $f['category'] === $slug), 'card');
            if ($cat && count($list) >= 4) {
                $catShelves[] = ['cat' => $cat, 'list' => $list];
            }
        }
        $catShelves = array_map(static fn ($x) => ['cat' => $x['cat'], 'products' => $shelf($x['list'])], array_slice($catShelves, 0, 4));
        $small = $shelf(array_values(array_filter($cards, static fn ($c) => ($c['packOf'] ? $c['price'] / $c['packOf'] : $c['price']) <= self::SMALL_PRICE)));
        $seeMore = t('home.seeMore');
        $quads = [];
        foreach ($catShelves as $cs) {
            $quads[] = ['key' => 'cat-' . $cs['cat']['slug'], 'title' => t('home.bestOf', Ui::pick($cs['cat']['name'], $cs['cat']['nameAr'])), 'link' => ['href' => url('/categorie/' . $cs['cat']['slug']), 'label' => $seeMore], 'products' => $cs['products']];
        }
        $quads[] = ['key' => 'deals', 'title' => t('home.deals'), 'link' => ['href' => url('/boutique'), 'label' => $seeMore], 'products' => $deals];
        $quads[] = ['key' => 'small', 'title' => t('home.small', Ui::money(self::SMALL_PRICE)), 'link' => ['href' => url('/boutique?tri=price_asc'), 'label' => $seeMore], 'products' => $small];
        $quads[] = ['key' => 'new', 'title' => t('home.newest'), 'link' => ['href' => url('/boutique'), 'label' => $seeMore], 'products' => $fresh];
        $quads[] = ['key' => 'featured', 'title' => t('home.featured'), 'link' => ['href' => url('/boutique'), 'label' => $seeMore], 'products' => $home['featured']];
        $quads = array_slice(array_values(array_filter($quads, static fn ($q) => count($q['products']) >= 4)), 0, 8);

        View::render('shop/home', [
            'title' => '',
            'description' => null,
            'canonical' => url('/'),
            'jsonLd' => [Seo::organization($settings), Seo::website($settings)],
            'hero' => $hero,
            'shown' => $shown,
            'banners' => $banners,
            'quads' => $quads,
            'home' => $home,
            'best' => $best,
            'deals' => $deals,
            'catShelves' => $catShelves,
            'small' => $small,
            'fresh' => $fresh,
            'forClient' => array_map(static fn ($f) => ['card' => $f['card'], 'category' => $f['category']], array_slice($feed, 0, 80)),
            'settings' => $settings,
        ]);
    }

    /** Where a banner leads: a page of the shop, or a full https address; anything else is no link (bannerLink). */
    private static function bannerLink(string $link): ?array
    {
        $l = trim($link);
        if ($l === '') {
            return null;
        }
        if (preg_match('#^/(?!/)\S*$#', $l)) {
            return ['path' => (string) preg_replace('#^/(ar|fr)(?=/|$)#', '', $l) ?: '/'];
        }
        $u = parse_url($l);
        return $u && ($u['scheme'] ?? '') === 'https' && !empty($u['host']) ? ['url' => $l] : null;
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

    /** The product page, as the Next.js shop's src/app/[lang]/produit/[slug]/page.tsx. */
    public static function product(string $slug): void
    {
        self::remember();
        $lang = lang();
        $p = Catalog::productFor($slug, $lang);
        if (!$p) {
            // Renamed: the old address leads to the new one, for good.
            $moved = Catalog::productMovedTo($slug);
            if ($moved) {
                redirect(url('/produit/' . rawurlencode($moved)), 308);
            }
            self::notFound();
            return;
        }
        $settings = Settings::all();
        $base = rtrim((string) config('site_url', ''), '/');
        $pageUrl = $base . url('/produit/' . $p['slug']);
        $everywhere = Gov::everywhere($settings['delivery']['zones']);
        $title = Seo::productTitle($p);
        $lead = Rules::leadOffer($p['offers']);
        $priceNow = $lead ? $lead['price'] : min($p['price'], ...array_map(static fn ($v) => (int) $v['price'], $p['variants'] ?: [['price' => $p['price']]]));
        $crumbs = [['name' => t('nav.home'), 'url' => $base . url('/')]];
        if ($p['category']) {
            $crumbs[] = ['name' => Ui::pick($p['category']['name'], $p['category']['nameAr']), 'url' => $base . url('/categorie/' . $p['category']['slug'])];
        }
        $crumbs[] = ['name' => Ui::pick($p['name'], $p['nameAr']), 'url' => $pageUrl];
        $related = $p['related'];
        unset($p['related']);
        $public = Catalog::publicSettings();
        View::render('shop/product', [
            'title' => $title,
            'description' => Seo::productDescription($p, $everywhere),
            'canonical' => url('/produit/' . $p['slug']),
            'og' => ['title' => $title, 'description' => Seo::productDescription($p, $everywhere), 'images' => array_map(static fn ($i) => '/api/img/' . $i['id'] . '/1080', array_slice($p['images'], 0, 4))],
            'meta' => [
                'product:price:amount' => number_format($priceNow / 1000, 3, '.', ''),
                'product:price:currency' => 'TND',
                'product:availability' => $p['stock']['kind'] === 'out' ? 'out of stock' : 'in stock',
            ],
            'jsonLd' => [Seo::product($p, $settings, $pageUrl), Seo::breadcrumb($crumbs)],
            'p' => $p,
            'related' => $related,
            'settings' => $public,
            'pageUrl' => $pageUrl,
        ]);
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
    public static function rememberOrder(string $token): void
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
