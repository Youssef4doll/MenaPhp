<?php
declare(strict_types=1);

/** Reading the catalog: products, their photos, options and offers, categories. Only active products reach the shop. */
final class Catalog
{
    /*
     * Each product comes with its first photo, its options and its offers in the
     * same query (JSON built by PostgreSQL): the database may be far away, and
     * every separate question costs a round trip.
     */
    private const IMAGE = '(SELECT pi."mediaId" FROM "ProductImage" pi WHERE pi."productId" = p.id AND pi.kind = \'GALLERY\' ORDER BY pi.sort, pi.id LIMIT 1) AS "imageId"';
    private const VARIANTS = '(SELECT coalesce(json_agg(json_build_object(\'id\', v.id, \'productId\', v."productId", \'name\', v.name, \'nameAr\', v."nameAr", \'colorHex\', v."colorHex", \'price\', v.price, \'stockQty\', v."stockQty", \'available\', v.available, \'sort\', v.sort) ORDER BY v.sort, v.id), \'[]\') FROM "ProductVariant" v WHERE v."productId" = p.id) AS "variantsJson"';
    private const OFFERS = '(SELECT coalesce(json_agg(json_build_object(\'id\', o.id, \'productId\', o."productId", \'label\', o.label, \'labelAr\', o."labelAr", \'quantity\', o.quantity, \'price\', o.price, \'freeDelivery\', o."freeDelivery", \'endsAt\', to_char(o."endsAt", \'YYYY-MM-DD HH24:MI:SS\'), \'active\', o.active, \'sort\', o.sort) ORDER BY o.sort, o.quantity, o.id), \'[]\') FROM "ProductOffer" o WHERE o."productId" = p.id) AS "offersJson"';

    /** Options and offers out of their JSON columns. */
    private static function unpack(array $p): array
    {
        $p['variants'] = json_decode((string) ($p['variantsJson'] ?? '[]'), true) ?: [];
        $p['offers'] = json_decode((string) ($p['offersJson'] ?? '[]'), true) ?: [];
        unset($p['variantsJson'], $p['offersJson']);
        return $p;
    }

    private static function shape(array $p): array
    {
        foreach (['price', 'stockQty'] as $k) {
            $p[$k] = (int) $p[$k];
        }
        foreach (['compareAt', 'cost'] as $k) {
            if (array_key_exists($k, $p)) {
                $p[$k] = $p[$k] === null ? null : (int) $p[$k];
            }
        }
        foreach (['active', 'available', 'featured'] as $k) {
            if (array_key_exists($k, $p)) {
                $p[$k] = Db::bool($p[$k]);
            }
        }
        return $p;
    }

    /** The same, kept a minute: for what pages show. An order uses forPricing (always fresh). */
    public static function forDisplay(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        sort($ids);
        return $ids ? Cache::remember('pricing|' . implode(',', $ids), static fn () => self::forPricing($ids)) : [];
    }

    /** What pricing needs, for these product ids (loadCatalog): keyed by id, with options and offers. Read fresh. */
    public static function forPricing(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if (!$ids) {
            return [];
        }
        $rows = Db::all('SELECT p.id, p.slug, p.name, p."nameAr", p.price, p.cost, p.active, p."stockMode", p."stockQty", p.available, ' . self::IMAGE . ', ' . self::VARIANTS . ', ' . self::OFFERS . ' FROM "Product" p WHERE p.id IN (' . Db::marks($ids) . ')', $ids);
        $out = [];
        foreach ($rows as $p) {
            $p = self::unpack(self::shape($p));
            $out[$p['id']] = $p;
        }
        return $out;
    }

    /** A product page by its address; ['redirect' => slug] when the address is an old one. */
    public static function product(string $slug): ?array
    {
        return Cache::remember('product|' . $slug, static fn () => self::loadProduct($slug));
    }

    private static function loadProduct(string $slug): ?array
    {
        $p = Db::one(
            'SELECT p.*, ' . self::VARIANTS . ', ' . self::OFFERS . ',
              (SELECT coalesce(json_agg(json_build_object(\'mediaId\', i."mediaId", \'alt\', i.alt, \'kind\', i.kind, \'lang\', i.lang, \'sort\', i.sort) ORDER BY i.sort, i.id), \'[]\') FROM "ProductImage" i WHERE i."productId" = p.id) AS "imagesJson",
              (SELECT json_build_object(\'id\', c.id, \'slug\', c.slug, \'name\', c.name, \'nameAr\', c."nameAr") FROM "Category" c WHERE c.id = p."categoryId" AND c.active = true) AS "categoryJson"
             FROM "Product" p WHERE p.slug = ? AND p.active = true',
            [$slug]
        );
        if (!$p) {
            $new = Db::value('SELECT slug FROM "Product" WHERE ? = ANY("oldSlugs") AND active = true LIMIT 1', [$slug]);
            return $new ? ['redirect' => $new] : null;
        }
        $p = self::unpack(self::shape($p));
        $p['images'] = json_decode((string) $p['imagesJson'], true) ?: [];
        $p['category'] = $p['categoryJson'] ? json_decode((string) $p['categoryJson'], true) : null;
        unset($p['imagesJson'], $p['categoryJson']);
        return $p;
    }

    /** The columns a product card needs. */
    private const CARD = 'p.id, p.slug, p.name, p."nameAr", p.price, p."compareAt", p."stockMode", p."stockQty", p.available, p.active, p."createdAt"';

    /** Product cards: photo, name, price, options' lowest price, whether it can be bought. */
    public static function cards(string $where = 'true', array $params = [], string $order = 'p."createdAt" DESC', int $limit = 24, int $offset = 0): array
    {
        return Cache::remember('cards|' . md5(serialize(func_get_args())), static fn () => self::loadCards($where, $params, $order, $limit, $offset));
    }

    private static function loadCards(string $where, array $params, string $order, int $limit, int $offset): array
    {
        $rows = Db::all('SELECT ' . self::CARD . ', ' . self::IMAGE . ', ' . self::VARIANTS . ', ' . self::OFFERS . ' FROM "Product" p WHERE p.active = true AND (' . $where . ') ORDER BY ' . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $params);
        return self::decorate($rows);
    }

    public static function count(string $where = 'true', array $params = []): int
    {
        return (int) Cache::remember('count|' . md5(serialize([$where, $params])), static fn () => (int) Db::value('SELECT count(*) FROM "Product" p WHERE p.active = true AND (' . $where . ')', $params));
    }

    private static function decorate(array $rows): array
    {
        $now = Db::now();
        $out = [];
        foreach ($rows as $p) {
            $p = self::unpack(self::shape($p));
            $v = $p['variants'];
            $offers = $p['offers'];
            unset($p['variants'], $p['offers']);
            $prices = array_map(static fn ($x) => $x['price'] ?? $p['price'], $v);
            $p['priceFrom'] = $prices && min($prices) !== max($prices) ? min($prices) : null;
            if ($prices && min($prices) === max($prices)) {
                $p['price'] = $prices[0];
            }
            $p['sellable'] = Pricing::anySellable($p, $v);
            $live = array_values(array_filter($offers, static fn ($o) => Pricing::offerLive($o, $now) && $o['quantity'] > 1));
            $p['freeDelivery'] = (bool) array_filter($offers, static fn ($o) => Pricing::offerLive($o, $now) && $o['freeDelivery']);
            // The pack with the lowest price per unit, shown on the card ("3 unités : 138,000 DT").
            usort($live, static fn ($a, $b) => $a['price'] / $a['quantity'] <=> $b['price'] / $b['quantity']);
            $p['deal'] = $live && $live[0]['price'] < $p['price'] * $live[0]['quantity'] ? $live[0] : null;
            $out[] = $p;
        }
        return $out;
    }

    /** Active categories, in the owner's order, each with a picture: its own, or its first product's. */
    public static function categories(): array
    {
        return Cache::remember('categories', static fn () => self::loadCategories());
    }

    private static function loadCategories(): array
    {
        $cats = Db::all('SELECT c.id, c.slug, c.name, c."nameAr", c.headline, c."headlineAr",
            coalesce(c."imageId", (SELECT pi."mediaId" FROM "ProductImage" pi JOIN "Product" p ON p.id = pi."productId" WHERE p."categoryId" = c.id AND p.active = true AND pi.kind = \'GALLERY\' ORDER BY p.featured DESC, p."createdAt" DESC, pi.sort LIMIT 1)) AS "imageId",
            (SELECT count(*) FROM "Product" p WHERE p."categoryId" = c.id AND p.active = true) AS n
            FROM "Category" c WHERE c.active = true ORDER BY c.sort, c.name');
        return array_values(array_filter($cats, static fn ($c) => (int) $c['n'] > 0));
    }

    public static function category(string $slug): ?array
    {
        return Cache::remember('category|' . $slug, static fn () => self::loadCategory($slug));
    }

    private static function loadCategory(string $slug): ?array
    {
        $c = Db::one('SELECT id, slug, name, "nameAr", headline, "headlineAr" FROM "Category" WHERE slug = ? AND active = true', [$slug]);
        if ($c) {
            return $c;
        }
        $new = Db::value('SELECT slug FROM "Category" WHERE ? = ANY("oldSlugs") AND active = true LIMIT 1', [$slug]);
        return $new ? ['redirect' => $new] : null;
    }

    /** Products whose name (FR or AR), keywords or summary contain every word typed. */
    public static function search(string $q, int $limit = 48): array
    {
        $words = array_slice(array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($q))) ?: [], static fn ($w) => mb_strlen($w) >= 2)), 0, 6);
        if (!$words) {
            return [];
        }
        $parts = [];
        $params = [];
        foreach ($words as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $parts[] = '(p.name ILIKE ? OR p."nameAr" ILIKE ? OR p.keywords ILIKE ? OR p.summary ILIKE ? OR p."summaryAr" ILIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return self::cards(implode(' AND ', $parts), $params, 'p.featured DESC, p."createdAt" DESC', $limit);
    }


    // ======================================================================
    // Cards, shelves and the home page, as the Next.js shop's src/lib/catalog.ts
    // (cardSelect, toCard, getHome, getHomeCategories, getHomeFeed, getShop,
    // getCategoryPage, searchProducts, suggest). Same queries, same order.
    // ======================================================================

    /** cardSelect: what a card needs, in one query (first two gallery pictures, options, active offers). */
    private const CARD_SELECT = 'p.id, p.slug, p.name, p."nameAr", p.price, p."compareAt", p."stockMode", p."stockQty", p.available, p.featured,
        to_char(p."createdAt", \'YYYY-MM-DD HH24:MI:SS.MS\') AS "createdAt",
        (SELECT c.slug FROM "Category" c WHERE c.id = p."categoryId") AS "categorySlug",
        (SELECT coalesce(json_agg(json_build_object(\'id\', x."mediaId", \'alt\', x.alt, \'width\', x.width, \'height\', x.height, \'blur\', x.blur) ORDER BY x.sort, x.id), \'[]\')
           FROM (SELECT pi."mediaId", pi.alt, pi.sort, pi.id, m.width, m.height, m.blur FROM "ProductImage" pi JOIN "Media" m ON m.id = pi."mediaId"
                 WHERE pi."productId" = p.id AND pi.kind = \'GALLERY\' ORDER BY pi.sort, pi.id LIMIT 2) x) AS "imagesJson",
        (SELECT coalesce(json_agg(json_build_object(\'stockQty\', v."stockQty", \'available\', v.available, \'price\', v.price) ORDER BY v.sort, v.id), \'[]\') FROM "ProductVariant" v WHERE v."productId" = p.id) AS "variantsJson",
        (SELECT coalesce(json_agg(json_build_object(\'quantity\', o.quantity, \'price\', o.price, \'endsAt\', to_char(o."endsAt", \'YYYY-MM-DD HH24:MI:SS\'), \'label\', o.label, \'labelAr\', o."labelAr", \'freeDelivery\', o."freeDelivery") ORDER BY o.sort, o.quantity, o.id), \'[]\') FROM "ProductOffer" o WHERE o."productId" = p.id AND o.active = true) AS "offersJson"';

    /** The shop's own order: its selection first, then the newest. */
    public const NEWEST = 'p.featured DESC, p."createdAt" DESC, p.id';

    /** Card rows (active products only) with what toCard needs. */
    private static function cardRows(string $where, array $params, string $order, int $take): array
    {
        $rows = Db::all('SELECT ' . self::CARD_SELECT . ' FROM "Product" p WHERE p.active = true AND (' . $where . ')' . ($order !== '' ? ' ORDER BY ' . $order : '') . ' LIMIT ' . max(0, $take), $params);
        foreach ($rows as &$r) {
            $r['price'] = (int) $r['price'];
            $r['compareAt'] = $r['compareAt'] === null ? null : (int) $r['compareAt'];
            $r['stockQty'] = (int) $r['stockQty'];
            $r['available'] = Db::bool($r['available']);
            $r['featured'] = Db::bool($r['featured']);
            $r['images'] = json_decode((string) $r['imagesJson'], true) ?: [];
            $r['variants'] = json_decode((string) $r['variantsJson'], true) ?: [];
            $r['offers'] = json_decode((string) $r['offersJson'], true) ?: [];
            unset($r['imagesJson'], $r['variantsJson'], $r['offersJson']);
        }
        return $rows;
    }

    /** toCard: the card the lists show. */
    private static function toCard(array $r, array $ratings, array $sold): array
    {
        return [
            'id' => $r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'nameAr' => $r['nameAr'],
            ...Rules::cardPrice($r),
            ...Rules::cardDeal($r),
            'images' => array_map(static fn ($i) => ['id' => $i['id'], 'alt' => $i['alt'], 'width' => (int) $i['width'], 'height' => (int) $i['height'], 'blur' => $i['blur']], $r['images']),
            'stock' => Rules::stockState($r),
            'rating' => $ratings[$r['id']] ?? null,
            'sold' => Rules::soldStep($sold[$r['id']] ?? 0),
        ];
    }

    /** Orders of each product in the last 30 days that the shop confirmed (or got further). */
    private static function soldFor(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $rows = Db::all('SELECT oi."productId", COUNT(DISTINCT oi."orderId") AS n FROM "OrderItem" oi JOIN "Order" o ON o.id = oi."orderId"
            WHERE oi."productId" IN (' . Db::marks($ids) . ') AND o."createdAt" >= ? AND o.status IN (\'CONFIRMED\', \'SUPPLIER_ORDERED\', \'SHIPPED\', \'DELIVERED\') GROUP BY oi."productId"', [...$ids, $since]);
        return array_column(array_map(static fn ($r) => [$r['productId'], (int) $r['n']], $rows), 1, 0);
    }

    /** Approved reviews: average and count per product. */
    private static function ratingsFor(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT "productId", avg(rating)::float8 AS avg, count(*) AS n FROM "Review" WHERE "productId" IN (' . Db::marks($ids) . ') AND status = \'APPROVED\' GROUP BY "productId"', $ids) as $r) {
            $out[$r['productId']] = ['avg' => (float) $r['avg'], 'count' => (int) $r['n']];
        }
        return $out;
    }

    /** cards(): active products as cards. */
    public static function cardList(string $where, array $params, string $order, int $take): array
    {
        $rows = self::cardRows($where, $params, $order, $take);
        $ids = array_column($rows, 'id');
        $ratings = self::ratingsFor($ids);
        $sold = self::soldFor($ids);
        return array_map(static fn ($r) => self::toCard($r, $ratings, $sold), $rows);
    }

    /** Cards for these ids, in this order. */
    public static function cardsByIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }
        $found = self::cardList('p.id IN (' . Db::marks($ids) . ')', $ids, '', count($ids));
        $at = array_flip($ids);
        usort($found, static fn ($a, $b) => $at[$a['id']] <=> $at[$b['id']]);
        return $found;
    }

    /** The price a card shows, per unit for a pack. */
    private static function shownUnit(array $c): float
    {
        return $c['packOf'] ? $c['price'] / $c['packOf'] : (float) $c['price'];
    }

    /** A list in the order asked for; prices sorted on the price each card shows, over every product, then cut. */
    private static function sorted(string $where, array $params, string $sort, int $take): array
    {
        if ($sort === 'popular') {
            return Rules::outLast(self::popularIn($where, $params, $take));
        }
        if ($sort === 'new') {
            return Rules::outLast(self::cardList($where, $params, self::NEWEST, $take));
        }
        $list = self::cardList($where, $params, self::NEWEST, 2000);
        $keyed = array_map(null, $list, array_keys($list));
        usort($keyed, static fn ($a, $b) => ($sort === 'price_asc' ? self::shownUnit($a[0]) <=> self::shownUnit($b[0]) : self::shownUnit($b[0]) <=> self::shownUnit($a[0])) ?: $a[1] <=> $b[1]);
        return array_slice(Rules::outLast(array_column($keyed, 0)), 0, $take);
    }

    /** Units really sold in 60 days (confirmed orders), best first; topped up with the newest unless $fill is false. */
    private static function popularIn(string $where, array $params, int $take, bool $fill = true): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 60 * 86400);
        $rows = Db::all('SELECT oi."productId" AS id, sum(oi.qty) AS n FROM "OrderItem" oi JOIN "Order" o ON o.id = oi."orderId" JOIN "Product" p ON p.id = oi."productId"
            WHERE oi."productId" IS NOT NULL AND o."createdAt" >= ? AND o.status IN (\'CONFIRMED\', \'SUPPLIER_ORDERED\', \'SHIPPED\', \'DELIVERED\') AND p.active = true AND (' . $where . ')
            GROUP BY oi."productId" ORDER BY sum(oi.qty) DESC LIMIT ' . max(0, $take), [$since, ...$params]);
        $ids = array_column($rows, 'id');
        $ranked = self::cardsByIds($ids);
        if (count($ranked) >= $take || !$fill) {
            return $ranked;
        }
        $not = $ids ? ' AND p.id NOT IN (' . Db::marks($ids) . ')' : '';
        return [...$ranked, ...self::cardList('(' . $where . ')' . $not, [...$params, ...$ids], self::NEWEST, $take - count($ranked))];
    }

    /** getShop: the whole shop in an order. */
    public static function shop(string $sort): array
    {
        return Cache::remember('shop|' . $sort, static fn () => self::sorted('true', [], $sort, 120));
    }

    /** getCategoryPage: a category and its products (60), or null. */
    public static function categoryPage(string $slug, string $sort): ?array
    {
        return Cache::remember('category-page|' . $slug . '|' . $sort, static function () use ($slug, $sort) {
            $c = Db::one('SELECT id, slug, name, "nameAr", headline, "headlineAr", "imageId" FROM "Category" WHERE slug = ? AND active = true', [$slug]);
            return $c ? ['category' => $c, 'products' => self::sorted('p."categoryId" = ?', [$c['id']], $sort, 60)] : null;
        });
    }

    /** categoryMovedTo: a category's former address → its current one. */
    public static function categoryMovedTo(string $slug): ?string
    {
        $v = Cache::remember('category-moved|' . $slug, static fn () => Db::value('SELECT slug FROM "Category" WHERE ? = ANY("oldSlugs") AND active = true LIMIT 1', [$slug]) ?: '');
        return $v !== '' ? $v : null;
    }

    /** getCategories: active categories with products on sale, in the owner's order. */
    public static function navCategories(): array
    {
        return Cache::remember('nav-categories', static fn () => Db::all('SELECT c.id, c.slug, c.name, c."nameAr", c."imageId",
            (SELECT count(*) FROM "Product" p WHERE p."categoryId" = c.id AND p.active = true) AS n
            FROM "Category" c WHERE c.active = true AND EXISTS (SELECT 1 FROM "Product" p WHERE p."categoryId" = c.id AND p.active = true) ORDER BY c.sort, c.name'));
    }

    /** getHomeCategories: each category with the first picture of its first product (selection first, then newest). */
    public static function homeCategories(): array
    {
        return Cache::remember('home-categories', static fn () => Db::all('SELECT c.id, c.slug, c.name, c."nameAr", c.headline, c."headlineAr", c."imageId",
            (SELECT (SELECT pi."mediaId" FROM "ProductImage" pi WHERE pi."productId" = p.id AND pi.kind = \'GALLERY\' ORDER BY pi.sort, pi.id LIMIT 1)
               FROM "Product" p WHERE p."categoryId" = c.id AND p.active = true ORDER BY p.featured DESC, p."createdAt" DESC, p.id LIMIT 1) AS "firstImage"
            FROM "Category" c WHERE c.active = true AND EXISTS (SELECT 1 FROM "Product" p WHERE p."categoryId" = c.id AND p.active = true) ORDER BY c.sort, c.name'));
    }

    /** getHome: the selection, the newest, the most ordered (when really sold), the latest good reviews. */
    public static function home(): array
    {
        return Cache::remember('home', static function () {
            $since = gmdate('Y-m-d H:i:s', time() - 60 * 86400);
            $soldCount = (int) Db::value('SELECT count(*) FROM "OrderItem" oi JOIN "Order" o ON o.id = oi."orderId" WHERE o."createdAt" >= ? AND o.status IN (\'CONFIRMED\', \'SUPPLIER_ORDERED\', \'SHIPPED\', \'DELIVERED\')', [$since]);
            $reviews = Db::all('SELECT r.id, r.rating, r.text, r."authorName", r.governorate, p.name AS "productName", p."nameAr" AS "productNameAr", p.slug AS "productSlug"
                FROM "Review" r JOIN "Product" p ON p.id = r."productId" WHERE r.status = \'APPROVED\' AND r.rating >= 4 AND r.text IS NOT NULL ORDER BY r."createdAt" DESC LIMIT 6');
            return [
                'featured' => self::cardList('p.featured = true', [], 'p."createdAt" DESC, p.id', 8),
                'newest' => self::cardList('true', [], 'p."createdAt" DESC, p.id', 8),
                'bestsellers' => $soldCount >= 5 ? self::popularIn('true', [], 8, false) : [],
                'reviews' => array_map(static fn ($r) => ['rating' => (int) $r['rating']] + $r, $reviews),
            ];
        });
    }

    /** getHomeFeed: every product on sale, ranked (home-feed.ts), with the categories. */
    public static function homeFeed(): array
    {
        return Cache::remember('home-feed', static function () {
            $rows = self::cardRows('true', [], self::NEWEST, 400);
            $ids = array_column($rows, 'id');
            $ratings = self::ratingsFor($ids);
            $sold = self::soldFor($ids);
            $views = [];
            $pics = [];
            if ($ids) {
                $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
                foreach (Db::all('SELECT "productId", count(*) AS n FROM "AnalyticsEvent" WHERE name = \'product_viewed\' AND "productId" IN (' . Db::marks($ids) . ') AND "createdAt" >= ? GROUP BY "productId"', [...$ids, $since]) as $v) {
                    $views[$v['productId']] = (int) $v['n'];
                }
                foreach (Db::all('SELECT "productId", kind, count(*) AS n FROM "ProductImage" WHERE "productId" IN (' . Db::marks($ids) . ') GROUP BY "productId", kind', $ids) as $g) {
                    $pics[$g['productId'] . '|' . $g['kind']] = (int) $g['n'];
                }
            }
            $items = array_map(static fn ($r) => [
                'card' => self::toCard($r, $ratings, $sold),
                'category' => $r['categorySlug'],
                'featured' => $r['featured'],
                'createdAt' => (float) ((new DateTimeImmutable($r['createdAt'], new DateTimeZone('UTC')))->format('U.v')) * 1000,
                'orders' => $sold[$r['id']] ?? 0,
                'views' => $views[$r['id']] ?? 0,
                'photos' => $pics[$r['id'] . '|GALLERY'] ?? 0,
                'details' => $pics[$r['id'] . '|DETAIL'] ?? 0,
            ], $rows);
            return [
                'feed' => Rules::rankFeed($items, microtime(true) * 1000),
                'categories' => Db::all('SELECT slug, name, "nameAr" FROM "Category" WHERE active = true ORDER BY sort, name'),
            ];
        });
    }

    // ------------------------------------------------------------------ search

    /** getSearchIndex: each product's words, the categories, the phrases offered as completions. */
    private static function searchIndex(): array
    {
        return Cache::remember('search-index', static function () {
            $rows = Db::all('SELECT p.id, p.name, p."nameAr", p.keywords, p.summary, p."summaryAr", p.featured, c.name AS "catName", c."nameAr" AS "catNameAr",
                (SELECT coalesce(string_agg(v.name || \' \' || coalesce(v."nameAr", \'\'), \' \'), \'\') FROM "ProductVariant" v WHERE v."productId" = p.id) AS "variantWords"
                FROM "Product" p LEFT JOIN "Category" c ON c.id = p."categoryId" WHERE p.active = true ORDER BY p.featured DESC, p."createdAt" DESC, p.id');
            $cats = Db::all('SELECT c.slug, c.name, c."nameAr" FROM "Category" c WHERE c.active = true AND EXISTS (SELECT 1 FROM "Product" p WHERE p."categoryId" = c.id AND p.active = true)');
            return [
                'docs' => array_map(static fn ($r) => [
                    'id' => $r['id'],
                    'featured' => Db::bool($r['featured']),
                    'fields' => [
                        ['tokens' => Search::withSynonyms(Search::tokenize($r['name'] . ' ' . ($r['nameAr'] ?? ''))), 'weight' => 3],
                        ['tokens' => Search::withSynonyms(Search::tokenize((string) $r['keywords'])), 'weight' => 2.5],
                        ['tokens' => Search::withSynonyms(Search::tokenize(($r['catName'] ?? '') . ' ' . ($r['catNameAr'] ?? ''))), 'weight' => 1.5],
                        ['tokens' => Search::tokenize((string) $r['variantWords']), 'weight' => 1.5],
                        ['tokens' => Search::tokenize($r['summary'] . ' ' . ($r['summaryAr'] ?? '')), 'weight' => 1],
                    ],
                ], $rows),
                'categories' => array_map(static fn ($c) => $c + ['doc' => ['id' => $c['slug'], 'fields' => [['tokens' => Search::tokenize($c['name'] . ' ' . ($c['nameAr'] ?? '')), 'weight' => 3]]]], $cats),
                'phrases' => [
                    'fr' => [...array_column($cats, 'name'), ...array_column($rows, 'name')],
                    'ar' => array_values(array_filter([...array_map(static fn ($c) => $c['nameAr'] ?? '', $cats), ...array_map(static fn ($r) => $r['nameAr'] ?? '', $rows)])),
                ],
            ];
        }, 300);
    }

    /** findProducts: the ranked ids for a query (best first, far worse ones dropped), and the word used alone when not every word was found. */
    private static function findProducts(string $q, bool $prefix, int $limit): array
    {
        $index = self::searchIndex();
        $featured = [];
        foreach ($index['docs'] as $d) {
            if ($d['featured']) {
                $featured[$d['id']] = true;
            }
        }
        $boost = static fn ($r) => ['score' => $r['score'] + (isset($featured[$r['id']]) ? 0.02 : 0)] + $r;
        $ranked = array_map($boost, Search::rank($q, $index['docs'], $prefix, $limit));
        $relaxed = null;
        $words = Search::words($q);
        if (!$ranked && count($words) >= 2) {
            $strong = array_map(static fn ($d) => ['id' => $d['id'], 'fields' => array_slice($d['fields'], 0, 2)], $index['docs']);
            $best = null;
            foreach ($words as $i => $w) {
                $r = Search::rank($w, $strong, $prefix && $i === count($words) - 1, $limit);
                if ($r && (!$best || $r[0]['score'] > $best['r'][0]['score'])) {
                    $best = ['w' => $w, 'r' => $r];
                }
            }
            if ($best) {
                $ranked = array_map($boost, $best['r']);
                $relaxed = $best['r'][0]['corrected'][$best['w']] ?? $best['w'];
            }
        }
        $keyed = array_map(null, $ranked, array_keys($ranked));
        usort($keyed, static fn ($a, $b) => $b[0]['score'] <=> $a[0]['score'] ?: $a[1] <=> $b[1]);
        $ranked = array_column($keyed, 0);
        $top = $ranked[0]['score'] ?? 0;
        return ['ranked' => array_values(array_filter($ranked, static fn ($r) => $r['score'] >= $top * 0.5)), 'index' => $index, 'relaxed' => $relaxed];
    }

    /** searchProducts: the results page, with the corrected query or the word used alone. */
    public static function searchProducts(string $q, int $take = 40): array
    {
        $term = mb_substr(trim($q), 0, 80);
        if (mb_strlen($term) < 2) {
            return ['products' => [], 'corrected' => null, 'relaxed' => null];
        }
        ['ranked' => $ranked, 'relaxed' => $relaxed] = self::findProducts($term, true, $take);
        if (!$ranked) {
            return ['products' => [], 'corrected' => null, 'relaxed' => null];
        }
        $list = Rules::outLast(self::cardsByIds(array_column($ranked, 'id')));
        if ($relaxed !== null) {
            return ['products' => $list, 'corrected' => null, 'relaxed' => $relaxed];
        }
        $fix = $ranked[0]['corrected'];
        $corrected = $fix ? implode(' ', array_map(static fn ($w) => $fix[$w] ?? $w, Search::words($term))) : null;
        return ['products' => $list, 'corrected' => $corrected !== null && $corrected !== Search::normalize($term) ? $corrected : null, 'relaxed' => null];
    }

    /** suggest: completions, products with picture and price, categories, as the customer types. */
    public static function suggest(string $q): array
    {
        $term = mb_substr(trim($q), 0, 60);
        if (mb_strlen($term) < 2) {
            return ['completions' => [], 'products' => [], 'categories' => []];
        }
        ['ranked' => $ranked, 'index' => $index] = self::findProducts($term, true, 6);
        $ids = array_column($ranked, 'id');
        $products = array_map(static fn ($c) => ['slug' => $c['slug'], 'name' => $c['name'], 'nameAr' => $c['nameAr'], 'price' => $c['price'], 'imageId' => $c['images'][0]['id'] ?? null], self::cardsByIds($ids));
        $cats = [];
        foreach (Search::rank($term, array_column($index['categories'], 'doc'), true, 3) as $r) {
            foreach ($index['categories'] as $c) {
                if ($c['slug'] === $r['id']) {
                    $cats[] = ['slug' => $c['slug'], 'name' => $c['name'], 'nameAr' => $c['nameAr']];
                }
            }
        }
        $arabic = (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $term);
        return ['completions' => Search::complete($term, $index['phrases'][$arabic ? 'ar' : 'fr'], 4), 'products' => $products, 'categories' => $cats];
    }

    /** Name in the page's language (French when there is no Arabic). */
    public static function name(array $row, string $field = 'name'): string
    {
        $ar = $row[$field . 'Ar'] ?? null;
        return lang() === 'ar' && is_string($ar) && trim($ar) !== '' ? $ar : (string) ($row[$field] ?? '');
    }
}
