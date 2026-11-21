<?php
declare(strict_types=1);

/**
 * Products in a spreadsheet, the server's half of lib/product-csv.ts: a row
 * merged onto a product, and the catalog written back in the same format
 * (French column names, prices "59,900", "oui"/"non", lists separated by ";").
 * The browser reads the file itself (the Next.js ImportWizard).
 */
final class ProductCsv
{
    private static ?array $columns = null;

    /** COLUMNS, in the template's order: key, header, example (copied by tools/admin-sync.mts). */
    public static function columns(): array
    {
        return self::$columns ??= json_decode((string) file_get_contents(__DIR__ . '/csv-columns.json'), true);
    }

    /** The column the file names ("prix"), for a field's key ("price"). */
    public static function header(string $key): ?string
    {
        foreach (self::columns() as $c) {
            if ($c['key'] === $key) {
                return $c['header'];
            }
        }
        return null;
    }

    /** normHeader(): "Nom (arabe)", "nom_ar", "NOM-AR" → "nomar". */
    public static function normHeader(string $h): string
    {
        if (class_exists('Normalizer')) {
            $h = (string) Normalizer::normalize($h, Normalizer::FORM_D);
        }
        $h = mb_strtolower((string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $h), 'UTF-8');
        return (string) preg_replace('/[^a-z0-9]+/', '', $h);
    }

    /** rowKey(): the address a row is saved under. */
    public static function rowKey(array $d): string
    {
        return ($d['slug'] ?? '') !== '' ? $d['slug'] : Text::slugify($d['name'] ?? '');
    }

    /** autoOfferLabel(): labels for an offer written without a name. */
    public static function autoOfferLabel(int $q): array
    {
        return [
            'label' => $q === 1 ? '1 unité' : "$q unités",
            'labelAr' => $q === 1 ? 'حبة وحدة' : ($q === 2 ? 'زوز حبات' : ($q <= 10 ? "$q حبات" : "$q حبة")),
        ];
    }

    public const EMPTY_PRODUCT = [
        'name' => '', 'nameAr' => '', 'slug' => '', 'summary' => '', 'summaryAr' => '', 'description' => '', 'descriptionAr' => '', 'specs' => '', 'specsAr' => '', 'videoUrl' => '', 'keywords' => '',
        'seoTitle' => '', 'seoTitleAr' => '', 'seoDescription' => '', 'seoDescriptionAr' => '', 'price' => 0, 'compareAt' => null, 'cost' => null, 'categoryId' => null, 'supplierId' => null,
        'supplierRef' => '', 'supplierUrl' => '', 'stockMode' => 'SUPPLIER', 'stockQty' => 0, 'available' => true, 'active' => false, 'featured' => false, 'variants' => [], 'offers' => [],
    ];
    private const PAIRS = [['name', 'nameAr'], ['summary', 'summaryAr'], ['description', 'descriptionAr'], ['specs', 'specsAr'], ['seoTitle', 'seoTitleAr'], ['seoDescription', 'seoDescriptionAr']];
    private const TEXT_KEYS = ['name', 'nameAr', 'summary', 'summaryAr', 'description', 'descriptionAr', 'specs', 'specsAr', 'keywords', 'videoUrl', 'seoTitle', 'seoTitleAr', 'seoDescription', 'seoDescriptionAr', 'supplierRef', 'supplierUrl'];

    /** Spaces and line endings a spreadsheet changes do not count as a change. */
    private static function differs(string $a, string $b): bool
    {
        $n = static fn (string $s) => Text::trim((string) preg_replace('/' . Text::WS . '+/u', ' ', $s));
        return $n($a) !== $n($b);
    }

    /**
     * mergeRow(): the product as it will be saved, what the row gives over what the product already
     * has. Options and offers are matched by name and by quantity, so the ones there keep their history.
     */
    public static function mergeRow(?array $base, array $d, array $refs, bool $activateNew = false): array
    {
        $p = $base ?? self::EMPTY_PRODUCT;
        foreach (self::TEXT_KEYS as $k) {
            if (array_key_exists($k, $d)) {
                $p[$k] = $d[$k];
            }
        }
        // A French text changed by a sheet without its Arabic: the old Arabic is translated again.
        if ($base) {
            foreach (self::PAIRS as [$fr, $ar]) {
                if (array_key_exists($fr, $d) && !array_key_exists($ar, $d) && self::differs($base[$fr], $d[$fr])) {
                    $p[$ar] = '';
                }
            }
        }
        $p['slug'] = ($base['slug'] ?? '') !== '' ? $base['slug'] : self::rowKey($d);
        foreach (['price', 'compareAt', 'cost'] as $k) {
            if (array_key_exists($k, $d)) {
                $p[$k] = $d[$k];
            }
        }
        if (array_key_exists('categoryId', $refs)) {
            $p['categoryId'] = $refs['categoryId'];
        }
        if (array_key_exists('supplierId', $refs)) {
            $p['supplierId'] = $refs['supplierId'];
        }
        if (array_key_exists('stockQty', $d)) {
            if ($d['stockQty'] === null) {
                $p['stockMode'] = 'SUPPLIER';
            } else {
                $p['stockMode'] = 'TRACKED';
                $p['stockQty'] = $d['stockQty'];
            }
        }
        foreach (['available', 'featured'] as $k) {
            if (array_key_exists($k, $d)) {
                $p[$k] = $d[$k];
            }
        }
        $p['active'] = $d['active'] ?? ($base ? $base['active'] : $activateNew);

        if (isset($d['variants'])) {
            $old = [];
            foreach ($base['variants'] ?? [] as $v) {
                // Map keeps the last value for a repeated key, as JavaScript's new Map(entries) does.
                $old[mb_strtolower(Text::trim($v['name']))] = $v;
            }
            $p['variants'] = array_map(static function ($v) use ($old) {
                $was = $old[mb_strtolower($v['name'])] ?? null;
                return (($was['id'] ?? null) ? ['id' => $was['id']] : []) + [
                    'name' => $v['name'],
                    'nameAr' => $v['nameAr'] ?? ($was['nameAr'] ?? ''),
                    'colorHex' => $v['colorHex'] ?? ($was['colorHex'] ?? ''),
                    'sku' => $was['sku'] ?? '',
                    'price' => array_key_exists('price', $v) ? $v['price'] : ($was['price'] ?? null),
                    'stockQty' => $v['stockQty'] ?? ($was['stockQty'] ?? 0),
                    'available' => $was['available'] ?? true,
                ];
            }, $d['variants']);
        }
        if (isset($d['offers'])) {
            $old = [];
            foreach ($base['offers'] ?? [] as $o) {
                $old[(int) $o['quantity']] = $o;
            }
            $p['offers'] = array_map(static function ($o) use ($old) {
                $was = $old[$o['quantity']] ?? null;
                $auto = self::autoOfferLabel($o['quantity']);
                $labelAr = $o['labelAr'] ?? (array_key_exists('label', $o) && $was && self::differs($was['label'], $o['label']) ? '' : ($was['labelAr'] ?? $auto['labelAr']));
                return (($was['id'] ?? null) ? ['id' => $was['id']] : []) + [
                    'label' => $o['label'] ?? ($was['label'] ?? $auto['label']),
                    'labelAr' => $labelAr,
                    'quantity' => $o['quantity'],
                    'price' => $o['price'],
                    'freeDelivery' => $o['freeDelivery'],
                    'endsOn' => $was['endsOn'] ?? '',
                    'active' => $was['active'] ?? true,
                ];
            }, $d['offers']);
        }
        return $p;
    }

    private static function written(bool $arabic): array
    {
        return array_values(array_filter(self::columns(), static fn ($c) => $arabic || !str_ends_with($c['key'], 'Ar')));
    }

    private static function money(?int $m): string
    {
        return $m === null ? '' : str_replace('.', ',', sprintf('%.3f', $m / 1000));
    }

    private static function yesNo(bool $b): string
    {
        return $b ? 'oui' : 'non';
    }

    /** A cell a spreadsheet would run as a formula is written with a leading apostrophe. */
    private static function quote(string $raw, string $sep): string
    {
        $v = preg_match('/^[=+\-@\t\r]/', $raw) ? "'$raw" : $raw;
        return preg_match('/["\n\r]/', $v) || str_contains($v, $sep) || preg_match('/^\s|\s$/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
    }

    /**
     * productsToCsv(): the catalog in the import format, ";"-separated with a BOM so Excel in French
     * opens it with its accents. Each product: toInput() plus category, supplier, photos, detailPhotos.
     */
    public static function productsToCsv(array $products, string $sep = ';', bool $arabic = false): string
    {
        $cols = self::written($arabic);
        $lines = [implode($sep, array_column($cols, 'header'))];
        foreach ($products as $p) {
            $ownPrices = (bool) array_filter($p['variants'], static fn ($v) => $v['price'] !== null);
            $counted = $p['stockMode'] === 'TRACKED';
            $join = static fn (array $xs) => implode(' ; ', $xs);
            $cell = [
                'slug' => $p['slug'], 'name' => $p['name'], 'nameAr' => $p['nameAr'], 'summary' => $p['summary'], 'summaryAr' => $p['summaryAr'], 'description' => $p['description'], 'descriptionAr' => $p['descriptionAr'],
                'specs' => $p['specs'], 'specsAr' => $p['specsAr'], 'keywords' => $p['keywords'], 'price' => self::money($p['price']), 'compareAt' => self::money($p['compareAt']), 'cost' => self::money($p['cost']),
                'category' => $p['category']['name'] ?? '', 'categoryAr' => $p['category']['nameAr'] ?? '', 'supplier' => $p['supplier'] ?? '', 'supplierRef' => $p['supplierRef'], 'supplierUrl' => $p['supplierUrl'],
                'stock' => $counted ? (string) $p['stockQty'] : 'fournisseur', 'available' => self::yesNo($p['available']), 'active' => self::yesNo($p['active']), 'featured' => self::yesNo($p['featured']),
                'photos' => $join($p['photos']), 'detailPhotos' => $join($p['detailPhotos']), 'videoUrl' => $p['videoUrl'],
                'variants' => $join(array_column($p['variants'], 'name')),
                'variantsAr' => array_filter($p['variants'], static fn ($v) => ($v['nameAr'] ?? '') !== '') ? $join(array_map(static fn ($v) => $v['nameAr'] ?? '', $p['variants'])) : '',
                'variantColors' => array_filter($p['variants'], static fn ($v) => ($v['colorHex'] ?? '') !== '') ? $join(array_map(static fn ($v) => $v['colorHex'] ?? '', $p['variants'])) : '',
                'variantPrices' => $ownPrices ? $join(array_map(static fn ($v) => self::money($v['price']), $p['variants'])) : '',
                'variantStock' => $counted && $p['variants'] ? $join(array_map(static fn ($v) => (string) $v['stockQty'], $p['variants'])) : '',
                'offers' => $join(array_map(static fn ($o) => $o['quantity'] . ' = ' . self::money($o['price']) . ($o['freeDelivery'] ? ' livraison offerte' : ''), $p['offers'])),
                'offerLabels' => $join(array_column($p['offers'], 'label')), 'offerLabelsAr' => $join(array_column($p['offers'], 'labelAr')),
                'seoTitle' => $p['seoTitle'], 'seoTitleAr' => $p['seoTitleAr'], 'seoDescription' => $p['seoDescription'], 'seoDescriptionAr' => $p['seoDescriptionAr'],
            ];
            $lines[] = implode($sep, array_map(static fn ($c) => self::quote((string) ($cell[$c['key']] ?? ''), $sep), $cols));
        }
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    /** templateCsv(): the empty template, or the template with two filled example lines. */
    public static function templateCsv(bool $withExamples, string $sep = ';', bool $arabic = false): string
    {
        $cols = self::written($arabic);
        $lines = [implode($sep, array_column($cols, 'header'))];
        if ($withExamples) {
            $lines[] = implode($sep, array_map(static fn ($c) => self::quote($c['example'], $sep), $cols));
            $second = [
                'name' => "Diffuseur d'huiles essentielles 300 ml", 'price' => '79,000', 'cost' => '41,500', 'category' => 'Maison', 'categoryAr' => 'الدار',
                'photos' => 'diffuseur-1.jpg ; diffuseur-2.jpg', 'summary' => 'Brume fine, sept couleurs, arrêt automatique.', 'stock' => '12', 'active' => 'non', 'available' => 'oui', 'featured' => 'non',
            ];
            $lines[] = implode($sep, array_map(static fn ($c) => self::quote($second[$c['key']] ?? '', $sep), $cols));
        }
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }
}
