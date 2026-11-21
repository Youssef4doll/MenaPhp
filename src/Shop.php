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
        $r->get('/panier/{id}', [self::class, 'restore']);
        $r->get('/commande', static fn () => redirect(url('/panier'), 307));
        $r->get('/commande/merci/{ref}', [self::class, 'thanks']);
        $r->get('/suivi', [self::class, 'lookup']);
        $r->get('/suivi/t/{token}', [self::class, 'track']);
        $r->get('/suivi/{ref}', [self::class, 'order']);
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

    /** A missing page: the way home and the shop's own selection, status 404 (not-found.tsx). */
    public static function notFound(): void
    {
        http_response_code(404);
        $home = Catalog::home();
        $products = array_slice($home['featured'] ?: $home['newest'], 0, 8);
        $h = '<div class="mx-auto flex max-w-md flex-col items-center justify-center gap-6 px-4 py-14 text-center"><h1 class="text-[28px] leading-tight">' . e(t('notFound.title')) . '</h1><a href="' . e(url('/')) . '" class="inline-flex min-h-[52px] items-center rounded-full bg-ink px-7 text-[17px] font-bold text-white hover:bg-ink-2">' . e(t('notFound.back')) . '</a></div>';
        if (count($products) >= 2) {
            $h .= '<section class="mx-auto max-w-6xl border-t border-line px-4 pt-8" aria-labelledby="nf-others"><h2 id="nf-others" class="mb-4 text-[20px] font-bold">' . e(t('search.discover')) . '</h2>' . Ui::productRow($products, false, 'not_found') . '</section>';
        }
        $h .= PageUi::island('TrackView', ['name' => 'not_found', 'props' => ['path' => mb_substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), 0, 120)]], '', false);
        View::render('shop/raw', ['html' => $h, 'title' => t('notFound.title'), 'description' => null, 'robots' => 'noindex']);
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

    /** /boutique: the whole shop, in the order asked for. */
    public static function all(): void
    {
        self::remember();
        $sort = PageUi::sort();
        $products = Catalog::shop($sort);
        $title = t('nav.shop');
        View::render('shop/raw', [
            'html' => PageUi::listing($title, '/boutique', $sort, $products, t('home.empty')),
            'title' => $title,
            'description' => Seo::listDescription($title, Catalog::shop('new'), Settings::all()),
            'canonical' => url('/boutique'),
        ]);
    }

    public static function category(string $slug): void
    {
        self::remember();
        $sort = PageUi::sort();
        $page = Catalog::categoryPage($slug, $sort);
        if (!$page) {
            $moved = Catalog::categoryMovedTo($slug);
            if ($moved) {
                redirect(url('/categorie/' . rawurlencode($moved)), 308);
            }
            self::notFound();
            return;
        }
        $c = $page['category'];
        $products = $page['products'];
        $title = Ui::pick($c['name'], $c['nameAr']);
        $ids = array_flip(array_column($products, 'id'));
        // A small category does not end the visit after one card: the rest of the shop follows.
        $others = count($products) < 4 ? array_slice(array_values(array_filter(Catalog::shop('new'), static fn ($p) => !isset($ids[$p['id']]))), 0, 8) : [];
        $h = PageUi::island('TrackView', ['name' => 'category_viewed', 'props' => ['slug' => $slug]], '', false);
        $h .= PageUi::listing($title, '/categorie/' . $slug, $sort, $products, t('category.empty'), ['image' => $c['imageId'] ?? ($products[0]['images'][0]['id'] ?? null), 'line' => Ui::pick($c['headline'] ?? '', $c['headlineAr'] ?? '') ?: null]);
        if (count($others) >= 2) {
            $h .= '<section class="mx-auto max-w-6xl border-t border-line px-4 pt-8" aria-labelledby="others-title"><h2 id="others-title" class="mb-4 text-[20px] font-bold">' . e(t('category.others')) . '</h2>' . Ui::productRow($others, false, 'category_others') . '</section>';
        }
        $newest = Catalog::categoryPage($slug, 'new')['products'] ?? $products;
        View::render('shop/raw', [
            'html' => $h,
            'title' => $title,
            'description' => Seo::listDescription($title, $newest, Settings::all()),
            'canonical' => url('/categorie/' . $slug),
            'robots' => $newest ? null : 'noindex, follow',
            'jsonLd' => [Seo::itemList($products)],
        ]);
    }

    /** /recherche: results, the corrected words, or the shop's own products when nothing is found. */
    public static function search(): void
    {
        $q = mb_substr(trim(get_str('q', 200)), 0, 80);
        $r = Catalog::searchProducts($q);
        $results = $r['products'];
        $empty = !$results;
        $h = '<div class="mx-auto max-w-6xl px-4 pt-6">';
        if ($q !== '') {
            $h .= PageUi::island('TrackView', ['name' => 'search', 'props' => ['q' => mb_strtolower($q), 'results' => count($results), 'corrected' => (bool) $r['corrected'], 'relaxed' => (bool) $r['relaxed']]], '', false);
            $h .= PageUi::island('SearchPixel', ['q' => $q], '', false);
        }
        if ($q !== '' && !$empty) {
            if ($r['relaxed']) {
                $h .= '<h1 class="text-[22px] font-bold leading-snug md:text-[26px]">' . e(t('search.relaxed', $r['relaxed'])) . '</h1><p class="mb-4 mt-1 text-[16px] text-muted">' . e(t('search.notExact', $q)) . '</p>';
            } else {
                $h .= '<h1 class="text-[22px] font-bold leading-snug md:text-[26px]">' . e(t('search.results', count($results), $r['corrected'] ?? $q)) . '</h1>' . ($r['corrected'] ? '<p class="mb-4 mt-1 text-[16px] text-muted">' . e(t('search.typed', $q)) . '</p>' : '<div class="mb-4"></div>');
            }
            $h .= Ui::productGrid($results, 4, 'search', $q);
        }
        if ($empty) {
            $wa = (string) Settings::get('contact.whatsapp', '');
            if ($q !== '') {
                $h .= '<div class="flex flex-col items-center gap-4 pb-8 pt-10 text-center"><h1 class="text-[22px] font-bold">' . e(t('search.none', $q)) . '</h1><p class="max-w-sm text-[17px] text-ink-2">' . e(t('search.noneHelp')) . '</p>';
                if ($wa !== '') {
                    $text = lang() === 'ar' ? "السلام، نلوّج على: $q" : "Bonjour, je cherche : $q";
                    $h .= '<a href="https://wa.me/216' . e($wa) . '?text=' . rawurlencode($text) . '" target="_blank" rel="noopener" data-contact="whatsapp" data-where="search_none" class="inline-flex min-h-12 items-center gap-2 rounded-full bg-wa px-6 text-[17px] font-bold text-white">' . icon('WhatsApp') . ' WhatsApp</a>';
                }
                $h .= '</div>';
            } else {
                $h .= '<h1 class="mb-4 text-[22px] font-bold md:text-[26px]">' . e(t('search.title')) . '</h1>';
            }
            $cats = Catalog::navCategories();
            if ($cats) {
                $h .= '<nav aria-label="' . e(t('search.categories')) . '" class="mb-8 flex flex-wrap gap-2">';
                foreach ($cats as $c) {
                    $h .= '<a href="' . e(url('/categorie/' . $c['slug'])) . '" class="flex h-11 items-center rounded-full border border-line px-4 text-[16px] text-ink hover:border-field">' . e(Ui::pick($c['name'], $c['nameAr'])) . '</a>';
                }
                $h .= '</nav>';
            }
            $home = Catalog::home();
            $discover = array_slice($home['featured'] ?: $home['newest'], 0, 8);
            if ($discover) {
                $h .= '<section aria-labelledby="discover-title" class="border-t border-line pt-8"><h2 id="discover-title" class="mb-4 text-[20px] font-bold">' . e(t('search.discover')) . '</h2>' . Ui::productGrid($discover, 0, 'search_none') . '</section>';
            }
        }
        $h .= '</div>';
        View::render('shop/raw', ['html' => $h, 'title' => $q !== '' ? $q . ' · ' . t('search.title') : t('search.title'), 'description' => null, 'robots' => 'noindex, follow']);
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

    /** The basket lives in the browser (CartView): the page is the same for everyone. */
    public static function cart(): void
    {
        $ssr = '<div class="mx-auto min-h-[70vh] max-w-6xl px-4 pb-16 pt-5 md:pt-8"><h1 id="cart-title" tabindex="-1" class="text-[26px] font-bold leading-tight text-ink outline-none md:text-[32px]">' . e(t('cart.title')) . '<!-- --> </h1></div>';
        View::render('shop/raw', ['html' => PageUi::island('CartView', ['lang' => lang(), 'settings' => Catalog::publicSettings()], $ssr), 'title' => t('cart.title'), 'description' => null, 'robots' => 'noindex, follow']);
    }

    /** The link of a reminder: one product back to its page and choice, several back to the basket. */
    public static function restore(string $id): void
    {
        if (!preg_match('/^[a-z0-9]{20,40}$/', $id)) {
            redirect(url('/'), 307);
        }
        $cart = Db::one('SELECT lines FROM "Cart" WHERE id = ?', [$id]);
        $all = $cart ? (json_decode((string) $cart['lines'], true) ?: []) : [];
        if (count($all) > 1) {
            redirect(url('/panier?relance=' . $id), 307);
        }
        $first = $all[0] ?? null;
        $slug = !empty($first['productId']) ? Db::value('SELECT slug FROM "Product" WHERE id = ? AND active = true', [$first['productId']]) : null;
        if (!$slug) {
            redirect(url('/'), 307);
        }
        $q = ['relance' => $id];
        if (!empty($first['offerId'])) {
            $q['offre'] = $first['offerId'];
        }
        if (!empty($first['variantId'])) {
            $q['option'] = $first['variantId'];
        }
        $qty = $first['qty'] ?? null;
        if (empty($first['offerId']) && is_int($qty) && $qty > 1) {
            $q['qte'] = (string) min(10, $qty);
        }
        redirect(url('/produit/' . $slug . '?' . http_build_query($q)) . '#commander', 307);
    }

    /**
     * rememberOrder: the order's token in the httpOnly "mena_orders" cookie (the last five orders of this
     * browser), the same cookie as the Next.js shop: no script, pixels included, can read it.
     */
    public static function rememberOrder(string $token): void
    {
        $current = Orders::cookieTokens();
        $next = array_slice(array_values(array_unique([$token, ...$current])), 0, 5);
        setcookie('mena_orders', implode('.', $next), ['expires' => time() + 60 * 60 * 24 * 120, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['mena_orders'] = implode('.', $next);
    }

    /** The thank-you page (commande/merci/[ref]): the order, when this browser placed it. */
    public static function thanks(string $ref): void
    {
        $s = Settings::all();
        $id = Orders::accessible($ref);
        $o = $id ? Orders::customerView($id) : null;
        $h = '<div class="mx-auto flex max-w-md flex-col gap-6 px-4 pb-12 pt-10"><div class="flex flex-col items-center gap-3 text-center"><span class="flex h-14 w-14 items-center justify-center rounded-full bg-olive-soft text-olive">' . icon('Check', 'h-7 w-7') . '</span>'
            . '<h1 class="text-[26px] font-bold leading-tight">' . e($o && $o['firstName'] !== '' ? t('thanks.titleName', $o['firstName']) : t('thanks.title')) . '</h1>'
            . '<p class="text-[16px] text-muted">' . e(t('thanks.ref')) . ' <strong class="text-[19px] tracking-wider text-ink ltr-nums" data-testid="order-ref">' . e($ref) . '</strong></p>';
        if ($o && $o['status'] === 'PENDING') {
            $props = ['lang' => lang(), 'firstName' => $o['firstName'], 'whatsapp' => $s['contact']['whatsapp'] ?: null, 'orderRef' => $o['ref'], 'shopPhone' => $s['shop']['phone'] ?: null];
            $from = $s['shop']['phone'] ? Phone::normalize($s['shop']['phone']) : null;
            $ssr = '<div class="flex flex-col items-center"><p class="text-[17px] text-ink">' . e(t('thanks.callSoon')) . '</p>' . ($from ? '<p class="text-[16px] text-ink-2">' . e(t('thanks.callFrom', Phone::format($from))) . '</p>' : '') . '</div>';
            $h .= PageUi::island('ThanksPhone', $props, $ssr);
        }
        $h .= '</div>';
        if ($o) {
            $h .= PageUi::island('PurchasePixel', ['ref_' => $o['ref'], 'total' => $o['total'], 'items' => array_map(static fn ($i) => ['id' => $i['productId'] ?? $i['id'], 'name' => $i['name'], 'price' => $i['unitPrice'], 'qty' => $i['qty']], $o['items']), 'enabled' => $s['tracking']['purchaseAt'] === 'placed'], '', false);
            if ($s['contact']['whatsapp'] && $o['status'] === 'PENDING') {
                $h .= '<a href="https://wa.me/216' . e($s['contact']['whatsapp']) . '?text=' . rawurlencode(t('thanks.confirmText', $o['ref'])) . '" target="_blank" rel="noopener" data-contact="whatsapp" data-where="thanks" class="flex min-h-14 items-center justify-center gap-2 rounded-full bg-wa px-6 text-[17px] font-bold text-white">' . icon('WhatsApp', 'h-6 w-6 shrink-0') . e(t('thanks.confirmNow')) . '</a>';
            }
            $h .= '<div class="rounded-2xl border border-line bg-white p-5"><ul class="flex flex-col gap-2 text-[16px]">';
            foreach ($o['items'] as $i) {
                $h .= '<li class="flex justify-between gap-3"><span><span class="ltr-nums">' . (int) $i['qty'] . ' × </span>' . e(Ui::pick($i['name'], $i['product']['nameAr'] ?? null))
                    . ($i['variantName'] ? '<span class="text-muted"> · ' . e(Ui::pick($i['variantName'], ($i['variant']['name'] ?? null) === $i['variantName'] ? $i['variant']['nameAr'] : null)) . '</span>' : '')
                    . ($i['offerLabel'] ? '<span class="text-muted"> · ' . e(Ui::pick($i['offerLabel'], $i['offerLabelAr'])) . '</span>' : '')
                    . '</span><span class="shrink-0 whitespace-nowrap price-nums ltr-nums">' . e(Ui::money($i['lineTotal'])) . '</span></li>';
            }
            $h .= '</ul>';
            if ($o['discount'] > 0) {
                $h .= '<div class="mt-2 flex justify-between gap-3 text-[16px] text-olive"><span>' . e(t('checkout.discount')) . '</span><span class="whitespace-nowrap price-nums ltr-nums">−' . e(Ui::money($o['discount'])) . '</span></div>';
            }
            $h .= '<div class="mt-2 flex justify-between gap-3 text-[16px] text-muted"><span>' . e(t('cart.delivery')) . '</span><span class="whitespace-nowrap price-nums ltr-nums">' . e($o['deliveryFee'] === 0 ? t('product.free') : Ui::money($o['deliveryFee'])) . '</span></div>'
                . '<div class="mt-3 flex justify-between gap-3 border-t border-line pt-3 text-[20px] font-bold"><span>' . e($o['paymentMethod'] === 'COD' ? t('track.toPay') : t('cart.total')) . '</span><span class="whitespace-nowrap price-nums ltr-nums">' . e(Ui::money($o['total'])) . '</span></div></div>';
        }
        $h .= '<a href="' . e(url($o ? '/suivi/' . $o['ref'] : '/suivi')) . '" class="flex min-h-14 items-center justify-center rounded-full border border-field bg-white text-[17px] font-bold text-ink hover:bg-sand">' . e(t('thanks.track')) . '</a>'
            . '<a href="' . e(url('/boutique')) . '" class="-mt-2 inline-flex min-h-11 items-center self-center text-[16px] text-sea underline underline-offset-4">' . e(t('thanks.keepShopping')) . '</a></div>';
        View::render('shop/raw', ['html' => $h, 'title' => '', 'description' => null, 'robots' => 'noindex']);
    }

    /** /suivi: the reference and phone form, and this browser's orders. */
    public static function lookup(): void
    {
        $mine = Orders::remembered();
        $h = '<div class="mx-auto flex max-w-md flex-col gap-8 px-4 pt-10"><div><h1 class="text-[28px] font-bold leading-tight">' . e(t('track.title')) . '</h1></div>' . PageUi::island('LookupForm', ['lang' => lang()], PageUi::lookupForm());
        if ($mine) {
            $h .= '<section><h2 class="mb-2 text-[19px] font-bold">' . e(t('track.yours')) . '</h2><ul class="divide-y divide-line rounded-2xl border border-line bg-white">';
            foreach ($mine as $o) {
                $h .= '<li><a href="' . e(url('/suivi/' . $o['ref'])) . '" class="flex items-center justify-between gap-3 px-4 py-3.5"><span class="flex flex-col"><span class="font-medium ltr-nums">' . e($o['ref']) . '</span><span class="text-[16px] text-muted">' . e(t('track.status.' . $o['status'])) . ' · <span class="ltr-nums">' . e(Ui::money((int) $o['total'])) . '</span></span></span>' . icon('Chevron', 'h-4 w-4 text-muted') . '</a></li>';
            }
            $h .= '</ul></section>';
        }
        $forget = $mine ? '<button type="button" class="inline-flex min-h-11 items-center self-start text-[15px] text-ink-2 underline decoration-field underline-offset-4">' . e(t('track.forget')) . '</button>' : '';
        $h .= PageUi::island('ForgetDevice', ['lang' => lang(), 'hasOrders' => (bool) $mine], $forget, $forget !== '') . '</div>';
        View::render('shop/raw', ['html' => $h, 'title' => '', 'description' => null, 'robots' => 'noindex']);
    }

    /** The SMS link: sets the access cookie and leaves the secret out of the address at once. */
    public static function track(string $token): void
    {
        $o = preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) ? Db::one('SELECT id, ref FROM "Order" WHERE "accessHash" = ?', [Tokens::hash($token)]) : null;
        if (!$o) {
            redirect(url('/suivi'), 307);
        }
        self::rememberOrder($token);
        $keep = array_filter(['avis' => $_GET['avis'] ?? null, 'paiement' => $_GET['paiement'] ?? null], static fn ($v) => is_string($v));
        header('Referrer-Policy: no-referrer');
        redirect(url('/suivi/' . $o['ref']) . ($keep ? '?' . http_build_query($keep) : ''), 307);
    }

    private const STEPS = ['PENDING', 'CONFIRMED', 'SUPPLIER_ORDERED', 'SHIPPED', 'DELIVERED'];

    /** /suivi/[ref]: one order of this browser, its steps, its lines, a review or a return when it is time. */
    public static function order(string $ref): void
    {
        $id = Orders::accessible($ref);
        if (!$id) {
            redirect(url('/suivi'), 307);
        }
        $o = Orders::customerView($id);
        $s = Settings::all();
        $step = array_search($o['status'], self::STEPS, true);
        $step = $step === false ? -1 : $step;
        $ended = in_array($o['status'], ['REFUSED', 'RETURNED', 'CANCELLED'], true);
        $returnOpen = $o['returns'] && in_array($o['returns'][0]['status'], ['REQUESTED', 'ACCEPTED', 'RECEIVED'], true);
        $canReturn = $o['status'] === 'DELIVERED' && $o['deliveredAt'] && time() - strtotime($o['deliveredAt']) < $s['returns']['days'] * 86400 && !$returnOpen;
        $reviewable = $o['status'] === 'DELIVERED' ? array_values(array_filter($o['items'], static fn ($i) => $i['productId'] && !$i['review'])) : [];
        $h = '<div class="mx-auto flex max-w-xl flex-col gap-6 px-4 pt-8">' . PageUi::island('TrackView', ['name' => 'tracking_viewed', 'props' => ['status' => $o['status']]], '', false)
            . '<div><p class="text-[15px] text-muted">' . e(t('thanks.ref')) . '</p><h1 class="text-[28px] font-bold tracking-wide ltr-nums">' . e($o['ref']) . '</h1><p class="mt-1 text-[16px] text-muted">' . e(PageUi::dayMonth($o['createdAt'])) . '</p></div>';
        if (($_GET['paiement'] ?? '') === 'ok') {
            $h .= '<p class="rounded-xl bg-olive-soft px-4 py-3 text-[16px] text-olive">' . e(t('track.paymentOk')) . '</p>';
        }
        if (($_GET['paiement'] ?? '') === 'echec') {
            $h .= '<p class="rounded-xl bg-clay-soft px-4 py-3 text-[16px] text-clay">' . e(t('track.paymentFail')) . '</p>';
        }
        $h .= '<div class="rounded-2xl border border-line bg-white p-5"><p class="text-[22px] font-bold ' . ($ended ? 'text-clay' : 'text-ink') . '">' . e(t('track.status.' . $o['status'])) . '</p>';
        if (!$ended) {
            $timeline = Lang::list('track.timeline');
            $h .= '<ol class="mt-4 flex flex-col gap-0">';
            foreach (self::STEPS as $i => $st) {
                $done = $i <= $step;
                $when = ['CONFIRMED' => $o['confirmedAt'], 'SHIPPED' => $o['shippedAt'], 'DELIVERED' => $o['deliveredAt']][$st] ?? null;
                $h .= '<li class="flex gap-3"><span class="flex flex-col items-center"><span class="mt-1 h-3.5 w-3.5 rounded-full ' . ($done ? 'bg-sea' : 'border-2 border-line bg-white') . '"></span>'
                    . ($i < count(self::STEPS) - 1 ? '<span class="w-px flex-1 ' . ($i < $step ? 'bg-sea' : 'bg-line') . '"></span>' : '') . '</span><span class="pb-4 text-[16px] ' . ($done ? 'text-ink' : 'text-muted') . '">' . e($timeline[$i] ?? '')
                    . ($when ? '<span class="text-muted"> · ' . e(PageUi::dayMonth($when)) . '</span>' : '') . '</span></li>';
            }
            $h .= '</ol>';
        }
        if ($o['carrier'] || $o['trackingNumber']) {
            $h .= '<dl class="mt-2 grid grid-cols-2 gap-3 border-t border-line pt-3 text-[16px]">'
                . ($o['carrier'] ? '<div><dt class="text-[16px] text-muted">' . e(t('track.carrier')) . '</dt><dd>' . e($o['carrier']) . '</dd></div>' : '')
                . ($o['trackingNumber'] ? '<div><dt class="text-[16px] text-muted">' . e(t('track.tracking')) . '</dt><dd class="ltr-nums">' . e($o['trackingNumber']) . '</dd></div>' : '') . '</dl>';
        }
        $h .= '</div><div class="rounded-2xl border border-line bg-white p-5"><h2 class="mb-3 text-[19px] font-bold">' . e(t('track.items')) . '</h2><ul class="flex flex-col gap-3">';
        foreach ($o['items'] as $i) {
            $name = Ui::pick($i['name'], $i['product']['nameAr'] ?? null);
            $h .= '<li class="flex items-center gap-3 text-[16px]"><span class="relative h-14 w-12 shrink-0 overflow-hidden rounded-lg bg-sand">' . (!empty($i['product']['images'][0]) ? '<img src="/api/img/' . e($i['product']['images'][0]['mediaId']) . '/128" alt="" class="h-full w-full object-cover">' : '') . '</span>'
                . '<span class="flex-1">' . ($i['product'] ? '<a href="' . e(url('/produit/' . $i['product']['slug'])) . '">' . e($name) . '</a>' : e($i['name']))
                . ($i['variantName'] ? '<span class="block text-[16px] text-muted">' . e(Ui::pick($i['variantName'], ($i['variant']['name'] ?? null) === $i['variantName'] ? $i['variant']['nameAr'] : null)) . '</span>' : '')
                . ($i['offerLabel'] ? '<span class="block text-[16px] text-muted">' . e(Ui::pick($i['offerLabel'], $i['offerLabelAr'])) . '</span>' : '')
                . '</span><span class="shrink-0 text-end"><span class="block text-[16px] text-muted ltr-nums">× ' . (int) $i['qty'] . '</span><span class="whitespace-nowrap price-nums ltr-nums">' . e(Ui::money($i['lineTotal'])) . '</span></span></li>';
        }
        $h .= '</ul><dl class="mt-4 flex flex-col gap-1 border-t border-line pt-3 text-[16px]"><div class="flex justify-between text-muted"><dt>' . e(t('cart.delivery')) . '</dt><dd class="ltr-nums">' . e($o['deliveryFee'] === 0 ? t('product.free') : Ui::money($o['deliveryFee'])) . '</dd></div>'
            . ($o['discount'] > 0 ? '<div class="flex justify-between text-olive"><dt>' . e(t('checkout.discount')) . '</dt><dd class="ltr-nums">−' . e(Ui::money($o['discount'])) . '</dd></div>' : '')
            . '<div class="flex justify-between text-[19px] font-bold"><dt>' . e($o['paymentStatus'] === 'PAID' ? t('track.paid') : t('track.toPay')) . '</dt><dd class="ltr-nums">' . e(Ui::money($o['total'])) . '</dd></div></dl>'
            . '<p class="mt-3 text-[16px] text-muted">' . e(t('track.deliveryTo')) . ' ' . e(implode(lang() === 'ar' ? '، ' : ', ', array_filter([$o['city'], Gov::label($o['governorate'])]))) . '</p></div>';
        if ($reviewable) {
            $h .= '<div id="avis" class="rounded-2xl border border-line bg-white p-5"><h2 class="text-[20px] font-bold">' . e(t('track.reviewTitle')) . '</h2><p class="mb-4 mt-1 text-[16px] text-muted">' . e(t('track.reviewSub')) . '</p><div class="flex flex-col gap-6">';
            foreach ($reviewable as $i) {
                $h .= PageUi::island('ReviewForm', ['lang' => lang(), 'orderRef' => $o['ref'], 'itemId' => $i['id'], 'productName' => Ui::pick($i['name'], $i['product']['nameAr'] ?? null), 'defaultName' => $o['firstName'], 'autoFocus' => ($_GET['avis'] ?? '') === '1'], '', false);
            }
            $h .= '</div></div>';
        }
        if ($returnOpen) {
            $h .= '<p class="rounded-xl bg-sea-soft px-4 py-3 text-[16px] text-sea-2">' . e(t('track.returnOpen')) . '</p>';
        }
        if ($canReturn) {
            $h .= PageUi::island('ReturnForm', ['lang' => lang(), 'orderRef' => $o['ref'], 'days' => $s['returns']['days']], '', false);
        }
        if ($s['contact']['whatsapp']) {
            $h .= '<a href="https://wa.me/216' . e($s['contact']['whatsapp']) . '?text=' . rawurlencode(t('track.help') . ' ' . $o['ref']) . '" target="_blank" rel="noopener" data-contact="whatsapp" data-where="tracking" class="flex min-h-14 items-center justify-center gap-2 rounded-full border border-field bg-white text-[17px] font-bold text-ink hover:bg-sand">' . icon('WhatsApp', 'h-5 w-5 text-wa') . e(t('track.help')) . '</a>';
        }
        View::render('shop/raw', ['html' => $h . '</div>', 'title' => '', 'description' => null, 'robots' => 'noindex']);
    }

    /** The information pages, built from the settings (the (info) pages of the Next.js shop). */
    public static function info(string $page): void
    {
        $titles = ['livraison-retours' => t('footer.deliveryReturns'), 'contact' => t('footer.contact'), 'conditions' => t('footer.terms'), 'confidentialite' => t('footer.privacy')];
        $freeOffers = Cache::remember('freeOffers', static fn () => (bool) Db::value('SELECT 1 FROM "ProductOffer" o JOIN "Product" p ON p.id = o."productId" WHERE o.active = true AND o."freeDelivery" = true AND p.active = true AND (o."endsAt" IS NULL OR o."endsAt" > ?) LIMIT 1', [Db::now()]));
        $html = View::capture('shop/info/' . $page, ['s' => Settings::all(), 'freeOffers' => $freeOffers]);
        View::render('shop/raw', ['html' => $html, 'title' => $titles[$page], 'description' => null, 'canonical' => url('/' . $page)]);
    }

    /** robots.txt, as the Next.js shop's (app/robots.ts): pictures open to Google, the rest of /api closed. */
    public static function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $off = [];
        foreach (['/commande', '/suivi', '/recherche', '/panier'] as $p) {
            array_push($off, $p, '/ar' . $p);
        }
        echo "User-Agent: *\nAllow: /\nAllow: /api/img/\nDisallow: /admin\nDisallow: /api\n" . implode('', array_map(static fn ($p) => "Disallow: $p\n", $off)) . "\nSitemap: " . absolute_url('/sitemap.xml') . "\n";
    }

    /** sitemap.xml, as the Next.js shop's (app/sitemap.ts): both languages of each page, the products' photos. */
    public static function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $data = Cache::remember('sitemap-v2', static fn () => [
            'categories' => array_column(Db::all('SELECT c.slug FROM "Category" c WHERE c.active = true AND EXISTS (SELECT 1 FROM "Product" p WHERE p."categoryId" = c.id AND p.active = true)'), 'slug'),
            'products' => Db::all('SELECT p.slug, to_char(p."updatedAt", \'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"\') AS "updatedAt", (SELECT string_agg(x."mediaId", \',\' ORDER BY x.sort, x.id) FROM (SELECT pi."mediaId", pi.sort, pi.id FROM "ProductImage" pi WHERE pi."productId" = p.id AND pi.kind = \'GALLERY\' ORDER BY pi.sort, pi.id LIMIT 8) x) AS images FROM "Product" p WHERE p.active = true'),
        ], 3600);
        $entry = static function (string $path, ?string $lastmod = null, array $images = []): string {
            $fr = e(absolute_url(url($path, 'fr')));
            $ar = e(absolute_url(url($path, 'ar')));
            $x = "<url>\n<loc>$fr</loc>\n<xhtml:link rel=\"alternate\" hreflang=\"fr\" href=\"$fr\" />\n<xhtml:link rel=\"alternate\" hreflang=\"ar\" href=\"$ar\" />\n<xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"$fr\" />\n";
            foreach ($images as $id) {
                $x .= '<image:image>' . "\n" . '<image:loc>' . e(absolute_url('/api/img/' . $id . '/1080')) . "</image:loc>\n</image:image>\n";
            }
            return $x . ($lastmod ? "<lastmod>$lastmod</lastmod>\n" : '') . "</url>\n";
        };
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        echo $entry('/') . $entry('/boutique');
        foreach ($data['categories'] as $c) {
            echo $entry('/categorie/' . $c);
        }
        foreach ($data['products'] as $p) {
            echo $entry('/produit/' . $p['slug'], $p['updatedAt'], $p['images'] ? explode(',', $p['images']) : []);
        }
        foreach (['/livraison-retours', '/contact', '/conditions', '/confidentialite'] as $p) {
            echo $entry($p);
        }
        echo '</urlset>' . "\n";
    }
}
