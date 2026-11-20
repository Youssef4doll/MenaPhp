<?php
declare(strict_types=1);

/** Reading the catalog: products, their photos, options and offers, categories. Only active products reach the shop. */
final class Catalog
{
    /** The first gallery picture of each product, by product id. */
    private static function firstImages(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $rows = Db::all(
            'SELECT DISTINCT ON ("productId") "productId", "mediaId" FROM "ProductImage" WHERE "productId" IN (' . Db::marks($productIds) . ') AND kind = \'GALLERY\' ORDER BY "productId", sort, id',
            $productIds
        );
        return array_column($rows, 'mediaId', 'productId');
    }

    private static function variantsOf(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT id, "productId", name, "nameAr", "colorHex", price, "stockQty", available, sort FROM "ProductVariant" WHERE "productId" IN (' . Db::marks($productIds) . ') ORDER BY sort, id', $productIds) as $v) {
            $v['available'] = Db::bool($v['available']);
            $v['price'] = $v['price'] === null ? null : (int) $v['price'];
            $v['stockQty'] = (int) $v['stockQty'];
            $out[$v['productId']][] = $v;
        }
        return $out;
    }

    private static function offersOf(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT id, "productId", label, "labelAr", quantity, price, "freeDelivery", "endsAt", active, sort FROM "ProductOffer" WHERE "productId" IN (' . Db::marks($productIds) . ') ORDER BY sort, quantity, id', $productIds) as $o) {
            $o['active'] = Db::bool($o['active']);
            $o['freeDelivery'] = Db::bool($o['freeDelivery']);
            $o['quantity'] = (int) $o['quantity'];
            $o['price'] = (int) $o['price'];
            $out[$o['productId']][] = $o;
        }
        return $out;
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

    /** What pricing needs, for these product ids (loadCatalog): keyed by id, with options and offers. */
    public static function forPricing(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if (!$ids) {
            return [];
        }
        $rows = Db::all('SELECT id, slug, name, "nameAr", price, cost, active, "stockMode", "stockQty", available FROM "Product" WHERE id IN (' . Db::marks($ids) . ')', $ids);
        $img = self::firstImages($ids);
        $vars = self::variantsOf($ids);
        $offs = self::offersOf($ids);
        $out = [];
        foreach ($rows as $p) {
            $p = self::shape($p);
            $p['imageId'] = $img[$p['id']] ?? null;
            $p['variants'] = $vars[$p['id']] ?? [];
            $p['offers'] = $offs[$p['id']] ?? [];
            $out[$p['id']] = $p;
        }
        return $out;
    }

    /** A product page by its address; ['redirect' => slug] when the address is an old one. */
    public static function product(string $slug): ?array
    {
        $p = Db::one('SELECT * FROM "Product" WHERE slug = ? AND active = true', [$slug]);
        if (!$p) {
            $new = Db::value('SELECT slug FROM "Product" WHERE ? = ANY("oldSlugs") AND active = true LIMIT 1', [$slug]);
            return $new ? ['redirect' => $new] : null;
        }
        $p = self::shape($p);
        $p['images'] = Db::all('SELECT "mediaId", alt, kind, lang, sort FROM "ProductImage" WHERE "productId" = ? ORDER BY sort, id', [$p['id']]);
        $p['variants'] = self::variantsOf([$p['id']])[$p['id']] ?? [];
        $p['offers'] = self::offersOf([$p['id']])[$p['id']] ?? [];
        $p['category'] = $p['categoryId'] ? Db::one('SELECT id, slug, name, "nameAr" FROM "Category" WHERE id = ? AND active = true', [$p['categoryId']]) : null;
        return $p;
    }

    /** The columns a product card needs. */
    private const CARD = 'p.id, p.slug, p.name, p."nameAr", p.price, p."compareAt", p."stockMode", p."stockQty", p.available, p.active, p."createdAt"';

    /** Product cards: photo, name, price, options' lowest price, whether it can be bought. */
    public static function cards(string $where = 'true', array $params = [], string $order = 'p."createdAt" DESC', int $limit = 24, int $offset = 0): array
    {
        $rows = Db::all('SELECT ' . self::CARD . ' FROM "Product" p WHERE p.active = true AND (' . $where . ') ORDER BY ' . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $params);
        return self::decorate($rows);
    }

    public static function count(string $where = 'true', array $params = []): int
    {
        return (int) Db::value('SELECT count(*) FROM "Product" p WHERE p.active = true AND (' . $where . ')', $params);
    }

    private static function decorate(array $rows): array
    {
        $ids = array_column($rows, 'id');
        $img = self::firstImages($ids);
        $vars = self::variantsOf($ids);
        $offs = self::offersOf($ids);
        $now = Db::now();
        $out = [];
        foreach ($rows as $p) {
            $p = self::shape($p);
            $p['imageId'] = $img[$p['id']] ?? null;
            $v = $vars[$p['id']] ?? [];
            $prices = array_map(static fn ($x) => $x['price'] ?? $p['price'], $v);
            $p['priceFrom'] = $prices && min($prices) !== max($prices) ? min($prices) : null;
            if ($prices && min($prices) === max($prices)) {
                $p['price'] = $prices[0];
            }
            $p['sellable'] = Pricing::anySellable($p, $v);
            $live = array_values(array_filter($offs[$p['id']] ?? [], static fn ($o) => Pricing::offerLive($o, $now) && $o['quantity'] > 1));
            $p['freeDelivery'] = (bool) array_filter($offs[$p['id']] ?? [], static fn ($o) => Pricing::offerLive($o, $now) && $o['freeDelivery']);
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
        $cats = Db::all('SELECT c.id, c.slug, c.name, c."nameAr", c.headline, c."headlineAr", c."imageId", (SELECT count(*) FROM "Product" p WHERE p."categoryId" = c.id AND p.active = true) AS n FROM "Category" c WHERE c.active = true ORDER BY c.sort, c.name');
        $cats = array_values(array_filter($cats, static fn ($c) => (int) $c['n'] > 0));
        foreach ($cats as &$c) {
            if (!$c['imageId']) {
                $c['imageId'] = Db::value('SELECT pi."mediaId" FROM "ProductImage" pi JOIN "Product" p ON p.id = pi."productId" WHERE p."categoryId" = ? AND p.active = true AND pi.kind = \'GALLERY\' ORDER BY p.featured DESC, p."createdAt" DESC, pi.sort LIMIT 1', [$c['id']]);
            }
        }
        return $cats;
    }

    public static function category(string $slug): ?array
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
