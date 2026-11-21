<?php
declare(strict_types=1);

/**
 * The server half of the CSV import: lib/product-import.ts. The browser reads
 * the file and sends one row at a time; every row is checked again here and
 * saved through the same code as the product form.
 */
final class ProductImport
{
    private static ?Z $row = null;

    /** rowSchema */
    public static function rowSchema(): Z
    {
        if (self::$row) {
            return self::$row;
        }
        $text = static fn (int $max) => Z::str()->max($max)->optional();
        $money = static fn () => Z::int()->min(0)->max(100_000_000);
        return self::$row = Z::obj([
            'slug' => $text(100), 'name' => $text(140), 'nameAr' => $text(140), 'summary' => $text(300), 'summaryAr' => $text(300), 'description' => $text(8000), 'descriptionAr' => $text(8000),
            'specs' => $text(4000), 'specsAr' => $text(4000), 'keywords' => $text(1000), 'videoUrl' => $text(500),
            'seoTitle' => $text(120), 'seoTitleAr' => $text(120), 'seoDescription' => $text(320), 'seoDescriptionAr' => $text(320),
            'price' => $money()->optional(), 'compareAt' => $money()->nullable()->optional(), 'cost' => $money()->optional(),
            'category' => $text(60), 'categoryAr' => $text(60), 'supplier' => $text(80), 'supplierRef' => $text(120), 'supplierUrl' => $text(500),
            'stockQty' => Z::int()->min(0)->max(1_000_000)->nullable()->optional(),
            'available' => Z::bool()->optional(), 'active' => Z::bool()->optional(), 'featured' => Z::bool()->optional(),
            'photos' => Z::arr(Z::str()->max(2000))->max(30)->optional(), 'detailPhotos' => Z::arr(Z::str()->max(2000))->max(30)->optional(),
            'variants' => Z::arr(Z::obj([
                'name' => Z::str()->min(1)->max(60), 'nameAr' => $text(60), 'colorHex' => Z::str()->regex('/^#[0-9a-f]{6}$/')->optional(),
                'price' => $money()->nullable()->optional(), 'stockQty' => Z::int()->min(0)->max(1_000_000)->optional(),
            ]))->max(60)->optional(),
            'offers' => Z::arr(Z::obj([
                'quantity' => Z::int()->min(1)->max(50), 'price' => $money()->min(1), 'freeDelivery' => Z::bool(), 'label' => $text(80), 'labelAr' => $text(80),
            ]))->max(8)->optional(),
        ]);
    }

    private static function sameName(string $a, string $b): bool
    {
        return ProductCsv::normHeader($a) === ProductCsv::normHeader($b);
    }

    /** checkImport(): which rows update a product already in the shop, and which categories and suppliers are new. */
    public static function check(array $keys, array $categories, array $suppliers): array
    {
        $unique = array_slice(array_values(array_unique(array_filter($keys, static fn ($k) => $k !== ''))), 0, 2000);
        $found = $unique ? Db::all('SELECT id, slug, "oldSlugs", name FROM "Product" WHERE slug IN (' . Db::marks($unique) . ') OR "oldSlugs" && ?::text[]', [...$unique, Db::pgArray($unique)]) : [];
        $cats = Db::all('SELECT name, slug FROM "Category"');
        $sups = Db::all('SELECT name FROM "Supplier"');
        $existing = [];
        foreach ($found as $p) {
            foreach ([$p['slug'], ...Db::parseArray($p['oldSlugs'])] as $k) {
                if (in_array($k, $unique, true) && !isset($existing[$k])) {
                    $existing[$k] = ['id' => $p['id'], 'name' => $p['name']];
                }
            }
        }
        $newCategories = array_values(array_filter(array_values(array_unique($categories)), static function ($c) use ($cats) {
            foreach ($cats as $x) {
                if (self::sameName($x['name'], $c) || $x['slug'] === Text::slugify($c)) {
                    return false;
                }
            }
            return true;
        }));
        $newSuppliers = array_values(array_filter(array_values(array_unique($suppliers)), static function ($s) use ($sups) {
            foreach ($sups as $x) {
                if (self::sameName($x['name'], $s)) {
                    return false;
                }
            }
            return true;
        }));
        return ['existing' => $existing ?: (object) [], 'newCategories' => $newCategories, 'newSuppliers' => $newSuppliers];
    }

    /** prepareImport(): the missing categories and suppliers, created once before the rows. */
    public static function prepare(array $categories, array $suppliers): array
    {
        $cats = Db::all('SELECT id, name, slug, sort FROM "Category"');
        $sort = max(0, ...array_map(static fn ($c) => (int) $c['sort'], $cats ?: [['sort' => 0]]));
        foreach ($categories as $c) {
            $slug = Text::slugify($c['name']);
            $known = false;
            foreach ($cats as $x) {
                if (self::sameName($x['name'], $c['name']) || $x['slug'] === $slug) {
                    $known = true;
                    break;
                }
            }
            if ($slug === '' || $known) {
                continue;
            }
            // A common aisle gets its Arabic name at once; the others are translated on the Categories page.
            $nameAr = Text::trim($c['nameAr'] ?? '') ?: (CategoryAr::arabic($c['name']) ?: null);
            $made = self::upsertCategory($slug, Text::trim($c['name']), $nameAr, ++$sort);
            $cats[] = $made;
        }
        $sups = Db::all('SELECT id, name FROM "Supplier"');
        foreach ($suppliers as $s) {
            if (Text::trim($s) === '') {
                continue;
            }
            foreach ($sups as $x) {
                if (self::sameName($x['name'], $s)) {
                    continue 2;
                }
            }
            $id = new_id();
            Db::insert('Supplier', ['id' => $id, 'name' => Text::trim($s), 'createdAt' => Dates::now()]);
            $sups[] = ['id' => $id, 'name' => Text::trim($s)];
        }
        return ['ok' => true];
    }

    /** prisma.category.upsert({ where: { slug }, create, update: {} }) */
    private static function upsertCategory(string $slug, string $name, ?string $nameAr, ?int $sort): array
    {
        $id = new_id();
        $cols = ['id' => $id, 'name' => $name, 'nameAr' => $nameAr, 'slug' => $slug, 'createdAt' => Dates::now()];
        if ($sort !== null) {
            $cols['sort'] = $sort;
        }
        $names = implode(', ', array_map([Db::class, 'q'], array_keys($cols)));
        $row = Db::one("INSERT INTO \"Category\" ($names) VALUES (" . Db::marks($cols) . ') ON CONFLICT (slug) DO UPDATE SET slug = EXCLUDED.slug RETURNING id, name, slug, sort', array_values($cols));
        return $row;
    }

    private static function categoryId(string $name, ?string $nameAr): ?string
    {
        $slug = Text::slugify($name);
        $cats = Db::all('SELECT id, name, slug FROM "Category"');
        foreach ($cats as $c) {
            if (self::sameName($c['name'], $name)) {
                return $c['id'];
            }
        }
        foreach ($cats as $c) {
            if ($c['slug'] === $slug) {
                return $c['id'];
            }
        }
        if ($slug === '') {
            return null;
        }
        // Not prepared (a row sent on its own): made here, safely if two rows ask at once.
        return self::upsertCategory($slug, Text::trim($name), Text::trim((string) $nameAr) ?: (CategoryAr::arabic($name) ?: null), null)['id'];
    }

    private static function supplierId(string $name): string
    {
        foreach (Db::all('SELECT id, name FROM "Supplier"') as $s) {
            if (self::sameName($s['name'], $name)) {
                return $s['id'];
            }
        }
        $id = new_id();
        Db::insert('Supplier', ['id' => $id, 'name' => Text::trim($name), 'createdAt' => Dates::now()]);
        return $id;
    }

    /**
     * importRow(): the product found by its address (or an old address), the row's values over it,
     * then the same save as the form. A row run twice gives the same product, not a copy.
     */
    public static function row(array $row, bool $activateNew, bool $translate): array
    {
        $key = ProductCsv::rowKey($row);
        if ($key === '') {
            return ['ok' => false, 'error' => 'Ni nom ni adresse : la ligne ne dit pas quel produit.'];
        }
        $foundId = Db::value('SELECT id FROM "Product" WHERE slug = ? OR ? = ANY("oldSlugs") ORDER BY "createdAt" ASC LIMIT 1', [$key, $key]);
        $found = $foundId ? (ProductWrite::load([$foundId])[$foundId] ?? null) : null;
        if (!$found) {
            if (($row['name'] ?? '') === '') {
                return ['ok' => false, 'error' => 'Nouveau produit sans nom en français.'];
            }
            if (!isset($row['price'])) {
                return ['ok' => false, 'error' => 'Nouveau produit sans prix.'];
            }
        }
        $refs = [];
        if (($row['category'] ?? '') !== '') {
            $refs['categoryId'] = self::categoryId($row['category'], $row['categoryAr'] ?? null);
        }
        if (($row['supplier'] ?? '') !== '') {
            $refs['supplierId'] = self::supplierId($row['supplier']);
        }
        $base = $found ? ProductWrite::toInput($found) : null;
        $input = ProductCsv::mergeRow($base, $row, $refs, $activateNew);
        // French changed without its Arabic: the old Arabic was removed, to be translated again.
        $arabicOf = static fn (array $p) => [$p['nameAr'], $p['summaryAr'], $p['descriptionAr'], $p['specsAr'], $p['seoTitleAr'], $p['seoDescriptionAr'], ...array_column($p['offers'], 'labelAr')];
        $removed = false;
        if ($base) {
            $after = $arabicOf($input);
            foreach ($arabicOf($base) as $i => $v) {
                if (Text::trim((string) $v) !== '' && Text::trim((string) ($after[$i] ?? '')) === '') {
                    $removed = true;
                    break;
                }
            }
        }
        $translated = false;
        if ($translate && Ai::ready() && Translate::needed(Translate::textsOf($input))) {
            try {
                $input = Translate::withTexts($input, Translate::product(Translate::textsOf($input), 'fill'));
                $translated = true;
            } catch (Throwable) {
                // The product is saved without the translation; the translation page can do it later.
            }
        }
        $w = ProductWrite::write($found['id'] ?? null, $input);
        if (!$w['ok']) {
            return $w;
        }
        $count = static fn (string $k) => $found ? (int) Db::value('SELECT count(*) FROM "ProductImage" WHERE "productId" = ? AND kind = ?', [$found['id'], $k]) : 0;
        return ['ok' => true, 'id' => $w['id'], 'slug' => $w['slug'], 'created' => $w['created'], 'translated' => $translated, 'arabicToRedo' => $removed && !$translated, 'images' => ['GALLERY' => $count('GALLERY'), 'DETAIL' => $count('DETAIL')]];
    }
}
