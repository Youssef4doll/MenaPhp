<?php
declare(strict_types=1);

/**
 * Saving a product: lib/product-write.ts of the Next.js shop, shared by the
 * editor, the CSV import and the copy of a product. Every check lives here,
 * so a row from a CSV file goes through exactly what the form goes through,
 * with the same messages.
 */
final class ProductWrite
{
    public const CHANGED_ELSEWHERE = "Ce produit a été modifié ailleurs depuis que vous l'avez ouvert (liste, import, traduction ou un autre onglet). Rien n'a été enregistré : notez vos changements, rechargez la page, puis refaites-les.";

    /** French names of the fields, for "Champ invalide : …" messages a person can act on. */
    private const FIELD = [
        'name' => 'nom', 'nameAr' => 'nom arabe', 'slug' => 'adresse', 'summary' => 'description courte', 'summaryAr' => 'description courte arabe',
        'description' => 'description', 'descriptionAr' => 'description arabe', 'specs' => 'caractéristiques', 'specsAr' => 'caractéristiques arabes',
        'videoUrl' => 'vidéo', 'keywords' => 'mots-clés', 'seoTitle' => 'titre Google', 'seoTitleAr' => 'titre Google arabe', 'seoDescription' => 'description Google',
        'seoDescriptionAr' => 'description Google arabe', 'price' => 'prix', 'compareAt' => 'ancien prix', 'cost' => 'coût', 'supplierRef' => 'réf. fournisseur',
        'supplierUrl' => 'lien fournisseur', 'stockQty' => 'stock', 'variants' => 'variantes', 'offers' => 'offres', 'label' => "nom de l'offre", 'quantity' => 'quantité',
        'colorHex' => 'couleur',
    ];

    private static ?Z $schema = null;

    public static function schema(): Z
    {
        if (self::$schema) {
            return self::$schema;
        }
        $variant = Z::obj([
            'id' => Z::str()->max(40)->optional(),
            'name' => Z::str()->trim()->min(1)->max(60),
            'nameAr' => Z::str()->trim()->max(60)->optional(),
            'colorHex' => Z::str()->trim()->regex('/^(#[0-9a-fA-F]{6})?$/')->optional(),
            'sku' => Z::str()->trim()->max(60)->optional(),
            'price' => Z::int()->min(0)->nullable(),
            'stockQty' => Z::int()->min(0)->max(1_000_000),
            'available' => Z::bool(),
        ]);
        $offer = Z::obj([
            'id' => Z::str()->max(40)->optional(),
            'label' => Z::str()->trim()->min(2)->max(80),
            'labelAr' => Z::str()->trim()->max(80),
            'quantity' => Z::int()->min(1)->max(50),
            'price' => Z::int()->min(1)->max(100_000_000),
            'freeDelivery' => Z::bool(),
            'endsOn' => Z::union([Z::lit(''), Z::str()->regex('/^\d{4}-\d{2}-\d{2}$/')]),
            'active' => Z::bool(),
        ]);
        return self::$schema = Z::obj([
            'name' => Z::str()->trim()->min(2)->max(140),
            'nameAr' => Z::str()->trim()->max(140),
            'slug' => Z::str()->trim()->max(140),
            'summary' => Z::str()->trim()->max(300),
            'summaryAr' => Z::str()->trim()->max(300),
            'description' => Z::str()->max(8000),
            'descriptionAr' => Z::str()->max(8000),
            'specs' => Z::str()->max(4000),
            'specsAr' => Z::str()->max(4000),
            'videoUrl' => Z::str()->trim()->max(500),
            'keywords' => Z::str()->max(1000),
            'seoTitle' => Z::str()->trim()->max(120),
            'seoTitleAr' => Z::str()->trim()->max(120),
            'seoDescription' => Z::str()->trim()->max(320),
            'seoDescriptionAr' => Z::str()->trim()->max(320),
            'price' => Z::int()->min(0)->max(100_000_000),
            'compareAt' => Z::int()->min(0)->nullable(),
            'cost' => Z::int()->min(0)->nullable(),
            'categoryId' => Z::str()->max(40)->nullable(),
            'supplierId' => Z::str()->max(40)->nullable(),
            'supplierRef' => Z::str()->trim()->max(120),
            'supplierUrl' => Z::union([Z::lit(''), Z::url()->max(500)]),
            'stockMode' => Z::enum(['TRACKED', 'SUPPLIER']),
            'stockQty' => Z::int()->min(0)->max(1_000_000),
            'available' => Z::bool(),
            'active' => Z::bool(),
            'featured' => Z::bool(),
            'checked' => Z::bool()->optional(),
            'variants' => Z::arr($variant)->max(60),
            'offers' => Z::arr($offer)->max(8),
        ]);
    }

    /** @param list<string|int> $path */
    public static function issueText(array $path): string
    {
        $parts = array_map(static fn ($p) => is_int($p) ? (string) ($p + 1) : (self::FIELD[$p] ?? $p), $path);
        $s = implode(' ', $parts);
        return $s !== '' ? $s : 'produit';
    }

    /** Endpoint of a Tunisian day: an offer "until 2026-10-20" stops at midnight in Tunis after that day (23:00 UTC). */
    public static function endsAtOf(string $endsOn): ?string
    {
        return $endsOn !== '' ? $endsOn . ' 23:00:00' : null;
    }

    /**
     * Creates (id null) or updates a product with its options and offers. Only what changed is
     * written. `loadedAt` is the product's updatedAt (milliseconds) when the form was opened: a
     * save from a form opened before a change made elsewhere is refused instead of undoing it.
     *
     * @return array{ok: true, id: string, slug: string, created: bool}|array{ok: false, error: string, conflict?: true}
     */
    public static function write(?string $id, mixed $raw, ?int $loadedAt = null): array
    {
        $parsed = self::schema()->safeParse($raw);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => 'Champ invalide : ' . self::issueText($parsed['path'])];
        }
        $d = $parsed['data'];
        if ($d['price'] <= 0) {
            return ['ok' => false, 'error' => 'Prix invalide : il doit être plus grand que 0 (ex. 59,900).'];
        }
        if ($d['compareAt'] !== null && $d['compareAt'] <= $d['price']) {
            return ['ok' => false, 'error' => "L'ancien prix doit être plus élevé que le prix actuel, sinon laissez-le vide."];
        }
        if ($d['offers'] && array_filter($d['variants'], static fn ($v) => $v['price'] !== null && $v['price'] !== $d['price'])) {
            return ['ok' => false, 'error' => 'Les offres utilisent le prix du produit : retirez le prix propre des variantes, ou retirez les offres.'];
        }
        foreach ($d['offers'] as $i => $o) {
            $problem = self::offerLabelProblem($o);
            if ($problem) {
                return ['ok' => false, 'error' => 'Offre ' . ($i + 1) . " : $problem. Corrigez le nom ou la quantité."];
            }
        }
        if ($d['videoUrl'] !== '' && !Rules::parseVideo($d['videoUrl'])) {
            return ['ok' => false, 'error' => "Vidéo : collez l'adresse complète d'une vidéo YouTube, Vimeo, TikTok, Instagram ou Facebook, ou l'adresse https d'un fichier .mp4 ou d'une vidéo .m3u8 (Amazon)."];
        }
        $slug = Text::slugify($d['slug'] !== '' ? $d['slug'] : $d['name']);
        if ($slug === '') {
            return ['ok' => false, 'error' => 'Adresse (slug) invalide.'];
        }

        $clash = Db::value('SELECT id FROM "Product" WHERE slug = ?' . ($id ? ' AND id <> ?' : '') . ' LIMIT 1', $id ? [$slug, $id] : [$slug]);
        $before = $id ? Db::one('SELECT slug, "oldSlugs", "updatedAt", "checkedAt" FROM "Product" WHERE id = ?', [$id]) : null;
        if ($clash) {
            return ['ok' => false, 'error' => "L'adresse « $slug » est déjà utilisée par un autre produit."];
        }
        if ($id && !$before) {
            return ['ok' => false, 'error' => 'Produit introuvable : il a peut-être été supprimé.'];
        }
        if ($before && $loadedAt !== null && Dates::ms($before['updatedAt']) !== $loadedAt) {
            return ['ok' => false, 'error' => self::CHANGED_ELSEWHERE, 'conflict' => true];
        }

        $oldSlugsBefore = $before ? Db::parseArray($before['oldSlugs']) : [];
        $moved = $before !== null && $before['slug'] !== $slug;
        $oldSlugs = $moved
            ? array_slice(array_values(array_filter(array_values(array_unique([$before['slug'], ...$oldSlugsBefore])), static fn ($x) => $x !== $slug)), 0, 20)
            : array_values(array_filter($oldSlugsBefore, static fn ($x) => $x !== $slug));
        if ($moved && $oldSlugs) {
            $other = Db::value('SELECT slug FROM "Product" WHERE slug IN (' . Db::marks($oldSlugs) . ') AND id <> ? LIMIT 1', [...$oldSlugs, $id]);
            if ($other) {
                return ['ok' => false, 'error' => "L'ancienne adresse « $other » est déjà prise par un autre produit."];
            }
        }

        $now = Dates::now();
        $data = [
            'name' => $d['name'], 'nameAr' => $d['nameAr'] ?: null, 'slug' => $slug, 'summary' => $d['summary'], 'summaryAr' => $d['summaryAr'] ?: null,
            'description' => $d['description'], 'descriptionAr' => $d['descriptionAr'] ?: null,
            'specs' => Text::trim($d['specs']), 'specsAr' => Text::trim($d['specsAr']) ?: null, 'videoUrl' => $d['videoUrl'] ?: null,
            'keywords' => Text::trim($d['keywords']), 'seoTitle' => $d['seoTitle'], 'seoTitleAr' => $d['seoTitleAr'] ?: null,
            'seoDescription' => $d['seoDescription'], 'seoDescriptionAr' => $d['seoDescriptionAr'] ?: null,
            'price' => $d['price'], 'compareAt' => $d['compareAt'], 'cost' => $d['cost'], 'categoryId' => $d['categoryId'], 'supplierId' => $d['supplierId'],
            'supplierRef' => $d['supplierRef'] ?: null, 'supplierUrl' => $d['supplierUrl'] ?: null, 'stockMode' => $d['stockMode'], 'stockQty' => $d['stockQty'],
            'available' => $d['available'], 'active' => $d['active'], 'featured' => $d['featured'],
        ];
        // Ticked: the first date it was ticked is kept; unticked: cleared; not sent: unchanged.
        if (array_key_exists('checked', $d)) {
            $data['checkedAt'] = $d['checked'] ? ($before['checkedAt'] ?? $now) : null;
        }
        $variantRow = static fn (array $v, int $i) => [
            'name' => $v['name'], 'nameAr' => ($v['nameAr'] ?? '') ?: null, 'colorHex' => ($v['colorHex'] ?? '') !== '' ? strtolower($v['colorHex']) : null,
            'sku' => ($v['sku'] ?? '') ?: null, 'price' => $v['price'], 'stockQty' => $v['stockQty'], 'available' => $v['available'], 'sort' => $i,
        ];
        $offerRow = static fn (array $o, int $i) => [
            'label' => $o['label'], 'labelAr' => $o['labelAr'] ?: null, 'quantity' => $o['quantity'], 'price' => $o['price'],
            'freeDelivery' => $o['freeDelivery'], 'endsAt' => self::endsAtOf($o['endsOn']), 'active' => $o['active'], 'sort' => $i,
        ];
        // Another product that had this address before gives it up.
        $freeSlug = static fn () => Db::run('UPDATE "Product" SET "oldSlugs" = array_remove("oldSlugs", ?) WHERE ? = ANY("oldSlugs") AND id <> ?', [$slug, $slug, $id ?? '']);

        if (!$before) {
            $newId = new_id();
            Db::tx(static function () use ($freeSlug, $data, $newId, $now, $d, $variantRow, $offerRow) {
                $freeSlug();
                Db::insert('Product', ['id' => $newId] + $data + ['oldSlugs' => '{}', 'createdAt' => $now, 'updatedAt' => $now]);
                foreach ($d['variants'] as $i => $v) {
                    Db::insert('ProductVariant', ['id' => new_id(), 'productId' => $newId] + $variantRow($v, $i));
                }
                foreach ($d['offers'] as $i => $o) {
                    Db::insert('ProductOffer', ['id' => new_id(), 'productId' => $newId] + $offerRow($o, $i));
                }
            });
            return ['ok' => true, 'id' => $newId, 'slug' => $slug, 'created' => true];
        }

        $oldV = [];
        foreach (Db::all('SELECT id, name, "nameAr", "colorHex", sku, price, "stockQty", available, sort FROM "ProductVariant" WHERE "productId" = ?', [$id]) as $r) {
            $oldV[$r['id']] = $r;
        }
        $oldO = [];
        foreach (Db::all('SELECT id, label, "labelAr", quantity, price, "freeDelivery", "endsAt", active, sort FROM "ProductOffer" WHERE "productId" = ?', [$id]) as $r) {
            $oldO[$r['id']] = $r;
        }
        try {
            Db::tx(static function () use ($moved, $freeSlug, $data, $oldSlugs, $now, $id, $before, $d, $oldV, $oldO, $variantRow, $offerRow): void {
            if ($moved) {
                $freeSlug();
            }
            // Written only if nobody saved it since it was read: a save in between makes this one fail as a whole.
            $set = $data + ['oldSlugs' => Db::pgArray($oldSlugs), 'updatedAt' => $now];
            if (Db::update('Product', $set, 'id = ? AND "updatedAt" = ?', [$id, $before['updatedAt']]) !== 1) {
                throw new ProductConflict();
            }
            $keepV = [];
            foreach ($d['variants'] as $v) {
                if (isset($v['id']) && isset($oldV[$v['id']])) {
                    $keepV[$v['id']] = true;
                }
            }
            $goneV = array_values(array_filter(array_keys($oldV), static fn ($k) => !isset($keepV[$k])));
            if ($goneV) {
                Db::run('DELETE FROM "ProductVariant" WHERE "productId" = ? AND id IN (' . Db::marks($goneV) . ')', [$id, ...$goneV]);
            }
            foreach ($d['variants'] as $i => $v) {
                $row = $variantRow($v, $i);
                $was = isset($v['id']) ? ($oldV[$v['id']] ?? null) : null;
                if (!$was) {
                    Db::insert('ProductVariant', ['id' => new_id(), 'productId' => $id] + $row);
                } elseif (!self::same($was, $row)) {
                    Db::update('ProductVariant', $row, 'id = ?', [$was['id']]);
                }
            }
            $keepO = [];
            foreach ($d['offers'] as $o) {
                if (isset($o['id']) && isset($oldO[$o['id']])) {
                    $keepO[$o['id']] = true;
                }
            }
            $goneO = array_values(array_filter(array_keys($oldO), static fn ($k) => !isset($keepO[$k])));
            if ($goneO) {
                Db::run('DELETE FROM "ProductOffer" WHERE "productId" = ? AND id IN (' . Db::marks($goneO) . ')', [$id, ...$goneO]);
            }
            foreach ($d['offers'] as $i => $o) {
                $row = $offerRow($o, $i);
                $was = isset($o['id']) ? ($oldO[$o['id']] ?? null) : null;
                if (!$was) {
                    Db::insert('ProductOffer', ['id' => new_id(), 'productId' => $id] + $row);
                } elseif (!self::same($was, $row)) {
                    Db::update('ProductOffer', $row, 'id = ?', [$was['id']]);
                }
            }
            });
        } catch (ProductConflict) {
            return ['ok' => false, 'error' => self::CHANGED_ELSEWHERE, 'conflict' => true];
        }
        return ['ok' => true, 'id' => $id, 'slug' => $slug, 'created' => false];
    }

    /** Whether a stored row already holds these values (as PostgreSQL gives them back: text). */
    private static function same(array $was, array $row): bool
    {
        foreach ($row as $k => $v) {
            $w = $was[$k] ?? null;
            if (is_bool($v)) {
                if (Db::bool($w) !== $v) {
                    return false;
                }
            } elseif ($k === 'endsAt') {
                if (($w === null) !== ($v === null) || ($v !== null && Dates::ms($w) !== Dates::ms($v))) {
                    return false;
                }
            } elseif ($v === null || $w === null) {
                if ($v !== $w) {
                    return false;
                }
            } elseif ((string) $w !== (string) $v) {
                return false;
            }
        }
        return true;
    }

    /** lib/offer-label.ts offerLabelProblem: the first name of an offer that speaks of another quantity. */
    public static function offerLabelProblem(array $o): ?string
    {
        foreach ([$o['label'] ?? null, $o['labelAr'] ?? null] as $l) {
            if ($l && !OfferLabel::fits($l, (int) $o['quantity'])) {
                return 'le nom « ' . Text::trim($l) . ' » annonce ' . OfferLabel::unitsIn($l) . ' unités, mais la quantité est ' . $o['quantity'];
            }
        }
        return null;
    }

    /** The columns the form shows, for the editor, the import and the copy. */
    public const FORM_COLUMNS = 'id, name, "nameAr", slug, summary, "summaryAr", description, "descriptionAr", specs, "specsAr", "videoUrl", keywords, "seoTitle", "seoTitleAr", "seoDescription", "seoDescriptionAr", price, "compareAt", cost, "categoryId", "supplierId", "supplierRef", "supplierUrl", "stockMode", "stockQty", available, active, featured, "checkedAt", "oldSlugs", "updatedAt", "createdAt"';

    /**
     * Stored products with their options and offers in the editor's order, by id (productForForm).
     * @param list<string> $ids
     * @return array<string, array>
     */
    public static function load(array $ids, string $extraColumns = ''): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT ' . self::FORM_COLUMNS . $extraColumns . ' FROM "Product" WHERE id IN (' . Db::marks($ids) . ')', $ids) as $p) {
            $p['variants'] = [];
            $p['offers'] = [];
            $out[$p['id']] = $p;
        }
        if (!$out) {
            return [];
        }
        $found = array_keys($out);
        foreach (Db::all('SELECT id, "productId", name, "nameAr", "colorHex", sku, price, "stockQty", available FROM "ProductVariant" WHERE "productId" IN (' . Db::marks($found) . ') ORDER BY sort ASC, id ASC', $found) as $v) {
            $out[$v['productId']]['variants'][] = $v;
        }
        foreach (Db::all('SELECT id, "productId", label, "labelAr", quantity, price, "freeDelivery", active, "endsAt" FROM "ProductOffer" WHERE "productId" IN (' . Db::marks($found) . ') ORDER BY sort ASC, id ASC', $found) as $o) {
            $out[$o['productId']]['offers'][] = $o;
        }
        return $out;
    }

    /** toInput(): the form's view of a stored product. */
    public static function toInput(array $p): array
    {
        $int = static fn ($v) => $v === null ? null : (int) $v;
        return [
            'name' => $p['name'], 'nameAr' => $p['nameAr'] ?? '', 'slug' => $p['slug'], 'summary' => $p['summary'], 'summaryAr' => $p['summaryAr'] ?? '',
            'description' => $p['description'], 'descriptionAr' => $p['descriptionAr'] ?? '',
            'specs' => $p['specs'], 'specsAr' => $p['specsAr'] ?? '', 'videoUrl' => $p['videoUrl'] ?? '',
            'keywords' => $p['keywords'], 'seoTitle' => $p['seoTitle'], 'seoTitleAr' => $p['seoTitleAr'] ?? '', 'seoDescription' => $p['seoDescription'], 'seoDescriptionAr' => $p['seoDescriptionAr'] ?? '',
            'price' => (int) $p['price'], 'compareAt' => $int($p['compareAt']), 'cost' => $int($p['cost']), 'categoryId' => $p['categoryId'], 'supplierId' => $p['supplierId'],
            'supplierRef' => $p['supplierRef'] ?? '', 'supplierUrl' => $p['supplierUrl'] ?? '', 'stockMode' => $p['stockMode'], 'stockQty' => (int) $p['stockQty'],
            'available' => Db::bool($p['available']), 'active' => Db::bool($p['active']), 'featured' => Db::bool($p['featured']), 'checked' => $p['checkedAt'] !== null,
            'variants' => array_map(static fn ($v) => [
                'id' => $v['id'], 'name' => $v['name'], 'nameAr' => $v['nameAr'] ?? '', 'colorHex' => $v['colorHex'] ?? '', 'sku' => $v['sku'] ?? '',
                'price' => $int($v['price']), 'stockQty' => (int) $v['stockQty'], 'available' => Db::bool($v['available']),
            ], $p['variants']),
            'offers' => array_map(static fn ($o) => [
                'id' => $o['id'], 'label' => $o['label'], 'labelAr' => $o['labelAr'] ?? '', 'quantity' => (int) $o['quantity'], 'price' => (int) $o['price'],
                'freeDelivery' => Db::bool($o['freeDelivery']), 'active' => Db::bool($o['active']),
                // Stored as midnight in Tunis after the last day; shown as that last day.
                'endsOn' => $o['endsAt'] ? gmdate('Y-m-d', intdiv(Dates::ms($o['endsAt']) - 23 * 3_600_000, 1000)) : '',
            ], $p['offers']),
        ];
    }
}

/** A save refused because the product changed since it was read. */
final class ProductConflict extends Exception
{
}
