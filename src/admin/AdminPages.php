<?php
declare(strict_types=1);

/**
 * What each ported page of the back office reads in the database: the
 * queries of its Next.js page.tsx, giving the props of its component.
 * Each returns ['component', 'props', 'title'] (and 'bare' without the menu).
 */
final class AdminPages
{
    private const PAGE_SIZE = 100;
    private const FILTERS = ['all', 'active', 'inactive', 'out', 'unchecked'];

    /** app/admin/connexion/page.tsx */
    public static function login(array $q): array
    {
        if (Auth::current()) {
            AdminApp::redirectTo('/admin');
        }
        return ['component' => 'LoginPage', 'props' => (object) [], 'title' => 'Connexion', 'bare' => true];
    }

    // ------------------------------------------------------------ products

    /** list-params.ts readListParams(): "?f=&q=&c=&page=" as typed by anyone. */
    public static function listParams(array $q): array
    {
        $one = static fn ($v) => is_string($v) ? $v : (is_array($v) ? (string) ($v[0] ?? '') : '');
        $f = $one($q['f'] ?? null);
        $n = preg_match('/^\s*([+-]?\d+)/', $one($q['page'] ?? null), $m) ? (int) $m[1] : null;
        return [
            'f' => in_array($f, self::FILTERS, true) ? $f : 'all',
            'q' => Text::slice(Text::trim($one($q['q'] ?? null)), 0, 100),
            'c' => Text::slice(Text::trim($one($q['c'] ?? null)), 0, 40),
            'page' => $n !== null && $n >= 1 ? min($n, 10_000) : 1,
        ];
    }

    /** OUT_OF_STOCK: sold out, exactly as stockState says it, as a database filter. */
    private const OUT_OF_STOCK = "((p.\"stockMode\" = 'SUPPLIER' AND p.available = false)
        OR (p.\"stockMode\" = 'SUPPLIER' AND EXISTS (SELECT 1 FROM \"ProductVariant\" v WHERE v.\"productId\" = p.id) AND NOT EXISTS (SELECT 1 FROM \"ProductVariant\" v WHERE v.\"productId\" = p.id AND v.available = true))
        OR (p.\"stockMode\" = 'TRACKED' AND NOT EXISTS (SELECT 1 FROM \"ProductVariant\" v WHERE v.\"productId\" = p.id) AND p.\"stockQty\" <= 0)
        OR (p.\"stockMode\" = 'TRACKED' AND EXISTS (SELECT 1 FROM \"ProductVariant\" v WHERE v.\"productId\" = p.id) AND NOT EXISTS (SELECT 1 FROM \"ProductVariant\" v WHERE v.\"productId\" = p.id AND v.\"stockQty\" > 0)))";

    /**
     * listWhere(): the search and the category, shared by every tab and its count. "%" and "_" are
     * searched as typed, not as the database's wildcards.
     * @return array{0: string, 1: list<mixed>}
     */
    private static function listWhere(array $p): array
    {
        $sql = ['TRUE'];
        $args = [];
        if ($p['q'] !== '') {
            $like = '%' . preg_replace('/[\\\\%_]/', '\\\\$0', $p['q']) . '%';
            $sql[] = '(p.name ILIKE ? OR p."nameAr" ILIKE ? OR p.slug ILIKE ? OR p."supplierRef" ILIKE ? OR p.keywords ILIKE ?)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        if ($p['c'] === 'none') {
            $sql[] = 'p."categoryId" IS NULL';
        } elseif ($p['c'] !== '') {
            $sql[] = 'p."categoryId" = ?';
            $args[] = $p['c'];
        }
        return [implode(' AND ', $sql), $args];
    }

    /** tabWhere(): the list for one tab. */
    private static function tabWhere(array $p): array
    {
        [$w, $a] = self::listWhere($p);
        return match ($p['f']) {
            'active' => ["$w AND p.active = true", $a],
            'inactive' => ["$w AND p.active = false", $a],
            'out' => ["$w AND " . self::OUT_OF_STOCK, $a],
            // Online and not yet checked with the supplier: the ones to verify before advertising them.
            'unchecked' => ["$w AND p.active = true AND p.\"checkedAt\" IS NULL", $a],
            default => [$w, $a],
        };
    }

    /** listHref(): the list's address with some filters changed. */
    public static function listHref(array $p, array $change = []): string
    {
        $n = array_merge($p, $change);
        $s = [];
        if ($n['f'] !== 'all') {
            $s['f'] = $n['f'];
        }
        if ($n['q'] !== '') {
            $s['q'] = $n['q'];
        }
        if ($n['c'] !== '') {
            $s['c'] = $n['c'];
        }
        if ($n['page'] > 1) {
            $s['page'] = (string) $n['page'];
        }
        $qs = http_build_query($s, '', '&', PHP_QUERY_RFC1738);
        return $qs !== '' ? "/admin/produits?$qs" : '/admin/produits';
    }

    /** app/admin/(panel)/produits/page.tsx: every product, 100 a page, filtered and counted by the database. */
    public static function products(array $q): array
    {
        $params = self::listParams($q);
        [$base, $baseArgs] = self::listWhere($params);
        [$where, $whereArgs] = self::tabWhere($params);
        $byActive = Db::all("SELECT p.active, count(*) AS n FROM \"Product\" p WHERE $base GROUP BY p.active", $baseArgs);
        $online = 0;
        $offline = 0;
        foreach ($byActive as $g) {
            Db::bool($g['active']) ? $online = (int) $g['n'] : $offline = (int) $g['n'];
        }
        $outCount = (int) Db::value("SELECT count(*) FROM \"Product\" p WHERE ($base) AND " . self::OUT_OF_STOCK, $baseArgs);
        $uncheckedCount = (int) Db::value("SELECT count(*) FROM \"Product\" p WHERE $base AND p.active = true AND p.\"checkedAt\" IS NULL", $baseArgs);
        $demoCount = (int) Db::value('SELECT count(*) FROM "Product" WHERE "isDemo" = true');
        $untranslated = (int) Db::value('SELECT count(*) FROM "Product" WHERE "nameAr" IS NULL OR "nameAr" = \'\'');
        $categories = Db::all('SELECT id, name FROM "Category" ORDER BY sort ASC, name ASC');
        // Each category's number of products for the search and tab shown, whatever category is chosen.
        [$allCats, $allCatsArgs] = self::tabWhere(['c' => ''] + $params);
        $perCategory = [];
        foreach (Db::all("SELECT p.\"categoryId\" AS id, count(*) AS n FROM \"Product\" p WHERE $allCats GROUP BY p.\"categoryId\"", $allCatsArgs) as $g) {
            $perCategory[(string) $g['id']] = (int) $g['n'];
        }
        $total = ['all' => $online + $offline, 'active' => $online, 'inactive' => $offline, 'out' => $outCount, 'unchecked' => $uncheckedCount][$params['f']];
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        // A page past the end (the last products of it were just deleted): the last page instead.
        $page = min($params['page'], $pages);
        $products = Db::all("SELECT p.id, p.name, p.price, p.cost, p.active, p.featured, p.\"stockMode\", p.\"stockQty\", p.available, p.\"isDemo\", p.\"categoryId\", s.name AS supplier,
            (SELECT i.\"mediaId\" FROM \"ProductImage\" i WHERE i.\"productId\" = p.id AND i.kind = 'GALLERY' ORDER BY i.sort ASC, i.id ASC LIMIT 1) AS image,
            (SELECT count(*) FROM \"ProductOffer\" o WHERE o.\"productId\" = p.id) AS offers
            FROM \"Product\" p LEFT JOIN \"Supplier\" s ON s.id = p.\"supplierId\" WHERE $where ORDER BY p.\"createdAt\" DESC, p.id DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $whereArgs);
        $ids = array_column($products, 'id');
        $variants = [];
        $views = [];
        $sold = [];
        if ($ids) {
            $in = Db::marks($ids);
            foreach (Db::all("SELECT \"productId\", \"stockQty\", available FROM \"ProductVariant\" WHERE \"productId\" IN ($in)", $ids) as $v) {
                $variants[$v['productId']][] = ['stockQty' => (int) $v['stockQty'], 'available' => Db::bool($v['available'])];
            }
            $since = Dates::fromMs((int) floor(microtime(true) * 1000) - 30 * 86_400_000);
            foreach (Db::all("SELECT \"productId\", count(*) AS n FROM \"AnalyticsEvent\" WHERE name = 'product_viewed' AND \"createdAt\" >= ? AND \"productId\" IN ($in) GROUP BY \"productId\"", [$since, ...$ids]) as $r) {
                $views[$r['productId']] = (int) $r['n'];
            }
            foreach (Db::all("SELECT i.\"productId\", sum(i.qty) AS n FROM \"OrderItem\" i JOIN \"Order\" o ON o.id = i.\"orderId\" WHERE i.\"productId\" IN ($in) AND o.\"createdAt\" >= ? AND o.status <> 'CANCELLED' GROUP BY i.\"productId\"", [...$ids, $since]) as $r) {
                $sold[$r['productId']] = (int) $r['n'];
            }
        }
        $rows = array_map(static function ($p) use ($variants, $views, $sold) {
            $vs = $variants[$p['id']] ?? [];
            $price = (int) $p['price'];
            $cost = $p['cost'] === null ? null : (int) $p['cost'];
            $state = ['stockMode' => $p['stockMode'], 'stockQty' => (int) $p['stockQty'], 'available' => Db::bool($p['available']), 'variants' => $vs];
            return [
                'id' => $p['id'], 'name' => $p['name'], 'image' => $p['image'], 'price' => $price, 'offers' => (int) $p['offers'],
                'margin' => $cost !== null && $price > 0 ? ($price - $cost) / $price : null,
                'categoryId' => $p['categoryId'], 'supplier' => $p['supplier'], 'active' => Db::bool($p['active']), 'featured' => Db::bool($p['featured']), 'isDemo' => Db::bool($p['isDemo']),
                'stockMode' => $p['stockMode'], 'stockQty' => (int) $p['stockQty'], 'available' => Db::bool($p['available']),
                'variants' => count($vs), 'variantStock' => array_sum(array_map(static fn ($v) => max(0, $v['stockQty']), $vs)),
                'stock' => Rules::stockState($state), 'views' => $views[$p['id']] ?? 0, 'sold' => $sold[$p['id']] ?? 0,
            ];
        }, $products);
        return ['component' => 'ProductsPage', 'title' => 'Produits', 'props' => [
            'params' => $params, 'page' => $page, 'pages' => $pages, 'total' => $total, 'online' => $online, 'offline' => $offline, 'outCount' => $outCount,
            'uncheckedCount' => $uncheckedCount, 'demoCount' => $demoCount, 'untranslated' => $untranslated,
            'categories' => array_map(static fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'count' => $perCategory[$c['id']] ?? 0], $categories),
            'rows' => $rows,
        ]];
    }

    /** app/admin/(panel)/produits/[id]/page.tsx: the editor, for a product or a new one ("nouveau"). */
    public static function product(array $q, string $id): array
    {
        $isNew = $id === 'nouveau';
        $p = $isNew ? null : (ProductWrite::load([$id])[$id] ?? null);
        if (!$isNew && !$p) {
            throw new AdminNotFound();
        }
        $images = $p ? Db::all('SELECT id, "mediaId", alt, kind, sort, lang FROM "ProductImage" WHERE "productId" = ? ORDER BY sort ASC, id ASC', [$id]) : [];
        $categories = Db::all('SELECT id, name, "nameAr" FROM "Category" ORDER BY sort ASC, name ASC');
        $suppliers = Db::all('SELECT id, name FROM "Supplier" ORDER BY name ASC');
        $s = Settings::all();
        // The products before and after this one in the list's order (newest first): two ids, not the catalog.
        $order = $isNew ? null : Db::one('SELECT prev, next FROM (SELECT id, LAG(id) OVER w AS prev, LEAD(id) OVER w AS next FROM "Product" WINDOW w AS (ORDER BY "createdAt" DESC, id DESC)) t WHERE id = ?', [$id]);
        $editor = [
            // Every save bumps updatedAt: the form remounts from the saved rows (new offers keep their ids).
            'version' => $p ? $p['id'] . ':' . Dates::ms($p['updatedAt']) : 'new',
            'id' => $p['id'] ?? null,
            'nextId' => $order['next'] ?? null,
            'categories' => $categories,
            'shopName' => (string) $s['shop']['name'],
            'everywhere' => Gov::everywhere((array) $s['delivery']['zones']),
            'shipping' => ['fee' => (int) $s['delivery']['fee'], 'carrier' => isset($s['costs']['carrierPerParcel']) ? (int) $s['costs']['carrierPerParcel'] : null],
            'aiReady' => Ai::ready(),
            'videoReady' => AdminRoutes::videoReady(),
            'siteUrl' => rtrim((string) config('site_url', ''), '/'),
            'oldSlugs' => $p ? Db::parseArray($p['oldSlugs']) : [],
            'suppliers' => $suppliers,
            'images' => array_map(static fn ($i) => ['id' => $i['id'], 'mediaId' => $i['mediaId'], 'alt' => $i['alt'] ?? '', 'kind' => $i['kind'], 'sort' => (int) $i['sort'], 'lang' => $i['lang'] === 'ar' || $i['lang'] === 'fr' ? $i['lang'] : null], $images),
            'initial' => $p ? ProductWrite::toInput($p) : null,
        ];
        return ['component' => 'ProductEditPage', 'title' => 'Produit', 'props' => [
            'name' => $p['name'] ?? null, 'slug' => $p['slug'] ?? null, 'active' => $p ? Db::bool($p['active']) : false,
            'prev' => $order['prev'] ?? null, 'next' => $order['next'] ?? null, 'editor' => $editor,
        ]];
    }

    /** app/admin/(panel)/produits/import/page.tsx: what the database holds now, for the estimate of what an import adds. */
    public static function import(array $q): array
    {
        try {
            $r = Db::one('SELECT (SELECT count(*) FROM "Media") AS photos, (SELECT sum(octet_length(bytes)) FROM "Media") AS "photoBytes", pg_database_size(current_database()) AS "dbBytes"');
            $storage = ['photos' => (int) $r['photos'], 'photoBytes' => (int) ($r['photoBytes'] ?? 0), 'dbBytes' => (int) $r['dbBytes']];
        } catch (Throwable) {
            $storage = null;
        }
        return ['component' => 'ImportPage', 'title' => 'Importer des produits', 'props' => ['aiReady' => Ai::ready(), 'storage' => $storage]];
    }

    /** app/admin/(panel)/produits/fournisseur/page.tsx */
    public static function supplier(array $q): array
    {
        $categories = array_column(Db::all('SELECT name FROM "Category" ORDER BY sort ASC, name ASC'), 'name');
        return ['component' => 'SupplierImportPage', 'title' => 'Ajouter depuis Ecomness', 'props' => ['aiReady' => Ai::ready(), 'categories' => $categories]];
    }

    /** app/admin/(panel)/produits/traduction/page.tsx: every product with a text in one language only, newest first. */
    public static function translate(array $q): array
    {
        $ids = Translate::idsParam($q['ids'] ?? null);
        $list = $ids === null
            ? array_column(Db::all('SELECT id FROM "Product" ORDER BY "createdAt" DESC, id DESC'), 'id')
            : ($ids ? array_column(Db::all('SELECT id FROM "Product" WHERE id IN (' . Db::marks($ids) . ') ORDER BY "createdAt" DESC, id DESC', $ids), 'id') : []);
        $todo = [];
        foreach (array_chunk($list, 500) as $chunk) {
            $rows = Translate::stored($chunk);
            foreach ($chunk as $id) {
                if (!isset($rows[$id])) {
                    continue;
                }
                $texts = Translate::storedTexts($rows[$id]);
                if (Translate::needed($texts)) {
                    $todo[] = ['id' => $id, 'name' => $rows[$id]['name'], 'texts' => $texts];
                }
            }
        }
        $max = 500;
        return ['component' => 'TranslateBatchPage', 'title' => 'Traduire en lot', 'props' => [
            'products' => array_slice($todo, 0, $max), 'aiReady' => Ai::ready(), 'ids' => $ids !== null, 'total' => count($todo), 'max' => $max,
        ]];
    }

    /**
     * The products as productQuality reads them, newest first.
     * @return list<array> each with its 'images' (kind, mediaId, source) and 'offers'
     */
    private static function forQuality(string $where, array $args): array
    {
        $rows = Db::all('SELECT id, name, "nameAr", active, summary, "summaryAr", description, "descriptionAr", specs, "specsAr", keywords, "seoTitle", "seoTitleAr", "seoDescription", "seoDescriptionAr", "categoryId", cost, "videoUrl", "checkedAt"
            FROM "Product" WHERE ' . $where . ' ORDER BY "createdAt" DESC LIMIT 2000', $args);
        $ids = array_column($rows, 'id');
        $images = [];
        $offers = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = Db::marks($chunk);
            foreach (Db::all("SELECT \"productId\", kind, \"mediaId\", source FROM \"ProductImage\" WHERE \"productId\" IN ($in) ORDER BY sort ASC, id ASC", $chunk) as $i) {
                $images[$i['productId']][] = $i;
            }
            foreach (Db::all("SELECT \"productId\", quantity, label, \"labelAr\", active FROM \"ProductOffer\" WHERE \"productId\" IN ($in)", $chunk) as $o) {
                $offers[$o['productId']][] = $o;
            }
        }
        foreach ($rows as &$r) {
            $r['images'] = $images[$r['id']] ?? [];
            $r['offers'] = $offers[$r['id']] ?? [];
            $gallery = array_values(array_filter($r['images'], static fn ($i) => $i['kind'] === 'GALLERY'));
            $r['gallery'] = count($gallery);
            $r['details'] = count($r['images']) - count($gallery);
            $r['photo'] = $gallery[0]['mediaId'] ?? null;
        }
        return $rows;
    }

    /** String.prototype.localeCompare, near enough: the language-neutral order of Unicode's collation. */
    private static function compare(string $a, string $b): int
    {
        static $c = null;
        if ($c === null) {
            $c = class_exists('Collator') ? new Collator('root') : false;
        }
        return $c ? (int) $c->compare($a, $b) : strcmp($a, $b);
    }

    /** app/admin/(panel)/produits/qualite/page.tsx: every product's page scored out of 100, the weakest first. */
    public static function quality(array $q): array
    {
        $one = static fn ($v) => is_string($v) ? $v : '';
        $scope = $one($q['f'] ?? null) === 'all' ? 'all' : 'active';
        $wanted = $one($q['manque'] ?? null);
        $want = in_array($wanted, array_column(ProductQuality::CHECKS, 'key'), true) ? $wanted : null;
        $rows = self::forQuality($scope === 'active' ? 'active = true' : 'TRUE', []);
        $scored = array_map(static fn ($p) => ['p' => ['id' => $p['id'], 'name' => $p['name'], 'active' => Db::bool($p['active'])], 'photo' => $p['photo']] + ProductQuality::score($p), $rows);
        usort($scored, static fn ($a, $b) => $a['score'] <=> $b['score'] ?: self::compare($a['p']['name'], $b['p']['name']));
        return ['component' => 'QualityPage', 'title' => 'Qualité des fiches', 'props' => ['scope' => $scope, 'want' => $want, 'scored' => $scored]];
    }

    /** app/admin/(panel)/produits/completer/page.tsx: the products whose page lacks what the web can bring, the weakest first. */
    public static function complete(array $q): array
    {
        $webKeys = ['photos', 'details', 'description', 'summary', 'specs', 'video', 'google', 'keywords', 'arabic'];
        $one = static fn ($v) => is_string($v) ? $v : '';
        $scope = $one($q['f'] ?? null) === 'all' ? 'all' : 'active';
        $ids = Translate::idsParam($q['ids'] ?? null);
        if ($ids !== null) {
            $rows = $ids ? self::forQuality('id IN (' . Db::marks($ids) . ')', $ids) : [];
        } else {
            $rows = self::forQuality($scope === 'active' ? 'active = true' : 'TRUE', []);
        }
        $list = [];
        foreach ($rows as $p) {
            $qy = ProductQuality::score($p);
            $lacks = array_values(array_map(static fn ($m) => $m['text'], array_filter($qy['missing'], static fn ($m) => in_array($m['key'], $webKeys, true))));
            if ($ids === null && !$lacks) {
                continue;
            }
            $list[] = [
                'id' => $p['id'], 'name' => $p['name'], 'nameAr' => $p['nameAr'] ?? '', 'active' => Db::bool($p['active']), 'score' => $qy['score'], 'lacks' => $lacks,
                'photo' => $p['photo'], 'sources' => array_values(array_filter(array_column($p['images'], 'source'), static fn ($s) => $s !== null && $s !== '')),
                'gallery' => $p['gallery'], 'details' => $p['details'], 'hasVideo' => Text::filled($p['videoUrl']),
                'current' => [
                    'summary' => $p['summary'], 'summaryAr' => $p['summaryAr'] ?? '', 'description' => $p['description'], 'descriptionAr' => $p['descriptionAr'] ?? '',
                    'specs' => $p['specs'], 'specsAr' => $p['specsAr'] ?? '', 'keywords' => $p['keywords'], 'seoTitle' => $p['seoTitle'], 'seoTitleAr' => $p['seoTitleAr'] ?? '',
                    'seoDescription' => $p['seoDescription'], 'seoDescriptionAr' => $p['seoDescriptionAr'] ?? '',
                ],
            ];
        }
        usort($list, static fn ($a, $b) => $a['score'] <=> $b['score'] ?: self::compare($a['name'], $b['name']));
        $max = 200;
        return ['component' => 'CompletePage', 'title' => 'Compléter depuis le web', 'props' => [
            'scope' => $scope, 'ids' => $ids !== null, 'total' => count($list), 'max' => $max, 'products' => array_slice($list, 0, $max), 'aiReady' => Ai::ready(),
        ]];
    }
}
