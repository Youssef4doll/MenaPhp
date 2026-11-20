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

    /** Name in the page's language (French when there is no Arabic). */
    public static function name(array $row, string $field = 'name'): string
    {
        $ar = $row[$field . 'Ar'] ?? null;
        return lang() === 'ar' && is_string($ar) && trim($ar) !== '' ? $ar : (string) ($row[$field] ?? '');
    }
}
