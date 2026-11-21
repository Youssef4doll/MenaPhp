<?php
declare(strict_types=1);

/** app/admin/(panel)/produits/actions.ts */
final class ProductActions
{
    private const ID = '/^[a-z0-9]{20,40}$/';

    /** The products changed (or the addresses of those deleted): see refreshCatalog. */
    private static function done(array $productIds = [], array $slugs = []): void
    {
        Refresh::catalog($productIds, $slugs);
        Refresh::path();
    }

    public static function saveProduct(?string $id, mixed $raw, mixed $loadedAt = null): array
    {
        $r = ProductWrite::write($id, $raw, is_int($loadedAt) || (is_float($loadedAt) && is_finite($loadedAt)) ? (int) $loadedAt : null);
        if (!$r['ok']) {
            return $r;
        }
        self::done([$r['id']]);
        return ['ok' => true, 'id' => $r['id'], 'message' => 'Produit enregistré.'];
    }

    private static function kindOf(mixed $v): string
    {
        return $v === 'DETAIL' ? 'DETAIL' : 'GALLERY';
    }

    public static function uploadImages(string $productId, FormDataIn $form): array
    {
        $kind = self::kindOf($form->get('kind'));
        $files = array_slice(array_values(array_filter($form->getAll('files'), static fn ($f) => is_array($f) && ($f['size'] ?? 0) > 0 && ($f['error'] ?? 1) === UPLOAD_ERR_OK)), 0, 10);
        if (!$files) {
            return ['ok' => false, 'error' => 'Aucune image.'];
        }
        $sort = (int) (Db::value('SELECT max(sort) FROM "ProductImage" WHERE "productId" = ? AND kind = ?', [$productId, $kind]) ?? -1) + 1;
        try {
            foreach ($files as $f) {
                if ($f['size'] > ProductImages::MAX_UPLOAD) {
                    return ['ok' => false, 'error' => "{$f['name']} : 8 Mo maximum."];
                }
                $m = ProductImages::store((string) file_get_contents($f['tmp_name']), (string) $f['type']);
                Db::insert('ProductImage', ['id' => new_id(), 'productId' => $productId, 'mediaId' => $m, 'sort' => $sort++, 'kind' => $kind]);
            }
        } catch (ImageRefused $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        self::done([$productId]);
        return ['ok' => true];
    }

    public static function importImage(string $productId, string $url, mixed $kindRaw = 'GALLERY'): array
    {
        $r = ProductImages::add($productId, self::kindOf($kindRaw), ['url' => Text::trim($url)]);
        if (!$r['ok']) {
            return $r;
        }
        self::done([$productId]);
        return $r['skipped'] ? ['ok' => true, 'message' => 'Cette image est déjà dans le produit.'] : ['ok' => true];
    }

    /** After pictures sent through the images route: the shop and this page show them at once. */
    public static function imagesChanged(mixed $productId = null): array
    {
        self::done(is_string($productId) ? [$productId] : []);
        return ['ok' => true];
    }

    public static function moveImage(string $imageId, int $dir): array
    {
        $img = Db::one('SELECT "productId", kind FROM "ProductImage" WHERE id = ?', [$imageId]);
        if (!$img) {
            return ['ok' => false, 'error' => 'Image introuvable.'];
        }
        $all = Db::all('SELECT id FROM "ProductImage" WHERE "productId" = ? AND kind = ? ORDER BY sort ASC, id ASC', [$img['productId'], $img['kind']]);
        $ids = array_column($all, 'id');
        $i = array_search($imageId, $ids, true);
        $j = $i + $dir;
        if ($j < 0 || $j >= count($ids)) {
            return ['ok' => true];
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        self::writeOrder($ids);
        self::done([$img['productId']]);
        return ['ok' => true];
    }

    private static function writeOrder(array $ids): void
    {
        Db::tx(static function () use ($ids) {
            foreach ($ids as $n => $id) {
                Db::run('UPDATE "ProductImage" SET sort = ? WHERE id = ?', [$n, $id]);
            }
        });
    }

    /** A product's pictures of one kind in the order given; pictures left out keep their place after the others. */
    public static function orderImages(string $productId, string $kind, mixed $ids): array
    {
        if (!is_array($ids) || count($ids) > 200 || array_filter($ids, static fn ($i) => !is_string($i))) {
            return ['ok' => false, 'error' => 'Ordre refusé.'];
        }
        $all = array_column(Db::all('SELECT id FROM "ProductImage" WHERE "productId" = ? AND kind = ? ORDER BY sort ASC, id ASC', [$productId, $kind === 'DETAIL' ? 'DETAIL' : 'GALLERY']), 'id');
        $order = array_values(array_unique(array_values(array_filter($ids, static fn ($i) => in_array($i, $all, true)))));
        foreach ($all as $x) {
            if (!in_array($x, $order, true)) {
                $order[] = $x;
            }
        }
        self::writeOrder($order);
        self::done([$productId]);
        return ['ok' => true];
    }

    /** Several pictures deleted at once; a picture no other product uses is deleted from the storage too. */
    public static function deleteImages(mixed $imageIds): array
    {
        if (!is_array($imageIds) || count($imageIds) > 200) {
            return ['ok' => false, 'error' => 'Suppression refusée.'];
        }
        $ids = array_values(array_filter($imageIds, 'is_string'));
        $imgs = $ids ? Db::all('SELECT id, "mediaId", "productId" FROM "ProductImage" WHERE id IN (' . Db::marks($ids) . ')', $ids) : [];
        if (!$imgs) {
            return ['ok' => true];
        }
        $found = array_column($imgs, 'id');
        Db::run('DELETE FROM "ProductImage" WHERE id IN (' . Db::marks($found) . ')', $found);
        ProductImages::dropUnused(array_column($imgs, 'mediaId'));
        self::done(array_values(array_unique(array_column($imgs, 'productId'))));
        return ['ok' => true];
    }

    public static function setImageAlt(string $imageId, string $alt): array
    {
        $alt = Text::slice($alt, 0, 140);
        $pid = Db::value('UPDATE "ProductImage" SET alt = ? WHERE id = ? RETURNING "productId"', [$alt !== '' ? $alt : null, $imageId]);
        if (!$pid) {
            throw new RuntimeException('No record was found for an update.');
        }
        self::done([$pid]);
        return ['ok' => true];
    }

    /** The page a long picture shows on: the Arabic one, the French one, or both (null). */
    public static function setImageLang(string $imageId, ?string $lang): array
    {
        $pid = Db::value('SELECT "productId" FROM "ProductImage" WHERE id = ? AND kind = \'DETAIL\'', [$imageId]);
        if (!$pid) {
            return ['ok' => false, 'error' => 'Image introuvable.'];
        }
        Db::run('UPDATE "ProductImage" SET lang = ? WHERE id = ?', [$lang === 'ar' || $lang === 'fr' ? $lang : null, $imageId]);
        self::done([$pid]);
        return ['ok' => true];
    }

    public static function deleteImage(string $imageId): array
    {
        $img = Db::one('DELETE FROM "ProductImage" WHERE id = ? RETURNING "productId", "mediaId"', [$imageId]);
        if (!$img) {
            throw new RuntimeException('No record was found for a delete.');
        }
        ProductImages::dropUnused([$img['mediaId']]);
        self::done([$img['productId']]);
        return ['ok' => true];
    }

    public static function toggleActive(string $id, bool $active): array
    {
        Db::run('UPDATE "Product" SET active = ?, "updatedAt" = ? WHERE id = ?', [$active, Dates::now(), $id]);
        self::done([$id]);
        return ['ok' => true];
    }

    /** Deleting keeps past orders intact: their lines are snapshots. */
    public static function deleteProduct(string $id): array
    {
        $p = Db::one('SELECT slug, "oldSlugs" FROM "Product" WHERE id = ?', [$id]);
        $media = array_column(Db::all('SELECT "mediaId" FROM "ProductImage" WHERE "productId" = ?', [$id]), 'mediaId');
        if (!$p) {
            throw new RuntimeException('No record was found for a delete.');
        }
        Db::run('DELETE FROM "Product" WHERE id = ?', [$id]);
        ProductImages::dropUnused($media);
        self::done([], [$p['slug'], ...Db::parseArray($p['oldSlugs'])]);
        return ['ok' => true];
    }

    public static function deleteDemo(): array
    {
        $demo = Db::all('SELECT id, slug, "oldSlugs" FROM "Product" WHERE "isDemo" = true');
        $ids = array_column($demo, 'id');
        $media = $ids ? array_column(Db::all('SELECT "mediaId" FROM "ProductImage" WHERE "productId" IN (' . Db::marks($ids) . ')', $ids), 'mediaId') : [];
        Db::run('DELETE FROM "Product" WHERE "isDemo" = true');
        ProductImages::dropUnused($media);
        $slugs = [];
        foreach ($demo as $p) {
            $slugs = [...$slugs, $p['slug'], ...Db::parseArray($p['oldSlugs'])];
        }
        self::done([], $slugs);
        return ['ok' => true, 'message' => count($demo) . ' produits de démonstration supprimés.'];
    }

    private static function textsSchema(): Z
    {
        return Z::obj([
            'name' => Z::str()->max(140), 'nameAr' => Z::str()->max(140), 'summary' => Z::str()->max(300), 'summaryAr' => Z::str()->max(300),
            'description' => Z::str()->max(8000), 'descriptionAr' => Z::str()->max(8000), 'specs' => Z::str()->max(4000), 'specsAr' => Z::str()->max(4000),
            'keywords' => Z::str()->max(1000), 'seoTitle' => Z::str()->max(120), 'seoTitleAr' => Z::str()->max(120),
            'seoDescription' => Z::str()->max(320), 'seoDescriptionAr' => Z::str()->max(320),
            'variants' => Z::arr(Z::obj(['name' => Z::str()->max(60), 'nameAr' => Z::str()->max(60)]))->max(60),
            'offers' => Z::arr(Z::obj(['label' => Z::str()->max(80), 'labelAr' => Z::str()->max(80)]))->max(8),
        ]);
    }

    /** Translates the product's texts (French ↔ Tunisian Arabic) and writes its keywords. Nothing is saved. */
    public static function translateTexts(mixed $raw, mixed $mode): array
    {
        if (!Ai::ready()) {
            return ['ok' => false, 'error' => Ai::NOT_READY_TRANSLATE];
        }
        $parsed = self::textsSchema()->safeParse($raw);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => 'Textes trop longs pour être traduits.'];
        }
        try {
            return ['ok' => true, 'texts' => Translate::product($parsed['data'], $mode === 'to-ar' ? 'to-ar' : 'fill')];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => Ai::failure($e, 'Trop de traductions d\'un coup : réessayez dans une minute.', 'Le service de traduction ne répond pas pour le moment. Réessayez plus tard.', 'Traduction impossible.')];
        }
    }

    /** The tall pictures of a product, planned by the AI from its texts. Nothing is saved. */
    public static function planImages(mixed $raw): array
    {
        if (!Ai::ready()) {
            return ['ok' => false, 'error' => 'IA non configurée : ajoutez la clé anthropic_api_key dans config.php, sur l\'hébergement.'];
        }
        $parsed = Z::obj([
            'name' => Z::str()->max(140), 'nameAr' => Z::str()->max(140), 'summary' => Z::str()->max(300),
            'description' => Z::str()->max(8000), 'descriptionAr' => Z::str()->max(8000), 'specs' => Z::str()->max(4000),
        ])->safeParse($raw);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => 'Textes trop longs.'];
        }
        if (Text::trim($parsed['data']['name']) === '' && Text::trim($parsed['data']['nameAr']) === '') {
            return ['ok' => false, 'error' => "Écrivez d'abord le nom et la description du produit."];
        }
        try {
            $ideas = ImageIdeas::forProduct($parsed['data']);
            return $ideas ? ['ok' => true, 'ideas' => $ideas] : ['ok' => false, 'error' => "Pas assez d'informations dans les textes du produit."];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => Ai::failure($e, "Trop de demandes d'un coup : réessayez dans une minute.", "L'IA ne répond pas pour le moment. Réessayez plus tard.", 'Impossible pour le moment.')];
        }
    }

    /** A copy of a product, offline: the same texts, prices, options, offers and pictures (shared, not stored twice). */
    public static function duplicateProduct(mixed $id): array
    {
        if (!is_string($id) || !preg_match(self::ID, $id)) {
            return ['ok' => false, 'error' => 'Produit introuvable.'];
        }
        $p = ProductWrite::load([$id])[$id] ?? null;
        if (!$p) {
            return ['ok' => false, 'error' => 'Produit introuvable : il a peut-être été supprimé.'];
        }
        $images = Db::all('SELECT "mediaId", kind, sort, alt, lang FROM "ProductImage" WHERE "productId" = ? ORDER BY sort ASC, id ASC', [$id]);
        // "<adresse>-copie", then "-copie-2", "-copie-3"…: the first one no product uses, as its address or a former one.
        $base = rtrim(substr($p['slug'], 0, 88), '-') . '-copie';
        $tries = [$base];
        for ($i = 2; $i <= 50; $i++) {
            $tries[] = "$base-$i";
        }
        $taken = Db::all('SELECT slug, "oldSlugs" FROM "Product" WHERE slug IN (' . Db::marks($tries) . ') OR "oldSlugs" && ?::text[]', [...$tries, Db::pgArray($tries)]);
        $used = [];
        foreach ($taken as $t) {
            $used[$t['slug']] = true;
            foreach (Db::parseArray($t['oldSlugs']) as $o) {
                $used[$o] = true;
            }
        }
        $slug = null;
        foreach ($tries as $s) {
            if (!isset($used[$s])) {
                $slug = $s;
                break;
            }
        }
        if (!$slug) {
            return ['ok' => false, 'error' => "Trop de copies de ce produit : renommez-en une avant d'en faire une autre."];
        }
        $input = ProductWrite::toInput($p);
        $input['name'] = rtrim(Text::slice($p['name'], 0, 132)) . ' (copie)';
        $input['slug'] = $slug;
        $input['active'] = false;
        $input['featured'] = false;
        $input['variants'] = array_map(static function ($v) { unset($v['id']); return $v; }, $input['variants']);
        $input['offers'] = array_map(static function ($o) { unset($o['id']); return $o; }, $input['offers']);
        $r = ProductWrite::write(null, $input);
        if (!$r['ok']) {
            return $r;
        }
        foreach ($images as $i) {
            Db::insert('ProductImage', ['id' => new_id(), 'productId' => $r['id'], 'mediaId' => $i['mediaId'], 'kind' => $i['kind'], 'sort' => (int) $i['sort'], 'alt' => $i['alt'], 'lang' => $i['lang'], 'source' => null]);
        }
        self::done();
        return ['ok' => true, 'id' => $r['id']];
    }
}

/** app/admin/(panel)/produits/list-actions.ts: one field of one product (quick edit), or one change on many. */
final class ListActions
{
    public const MAX_BULK = 500;
    private const ID = '/^[a-z0-9]{20,40}$/';

    private static function categoryOk(?string $cid): bool
    {
        return !$cid || (int) Db::value('SELECT count(*) FROM "Category" WHERE id = ?', [$cid]) > 0;
    }

    /** One field changed from the list. What needs the whole form is sent to the product's page. */
    public static function quickUpdate(mixed $productId, mixed $raw): array
    {
        $patch = Z::obj([
            'price' => Z::int()->min(1)->max(100_000_000)->optional(),
            'stockQty' => Z::int()->min(0)->max(1_000_000)->optional(),
            'available' => Z::bool()->optional(),
            'active' => Z::bool()->optional(),
            'featured' => Z::bool()->optional(),
            'categoryId' => Z::str()->min(1)->max(40)->nullable()->optional(),
        ], true)->check(static fn (array $p) => count($p) > 0);
        $parsed = $patch->safeParse($raw);
        if (!is_string($productId) || !preg_match(self::ID, $productId) || !$parsed['ok']) {
            return ['ok' => false, 'error' => 'Valeur invalide.'];
        }
        $d = $parsed['data'];
        $p = Db::one('SELECT "stockMode", "compareAt", (SELECT count(*) FROM "ProductOffer" o WHERE o."productId" = p.id) AS offers, (SELECT count(*) FROM "ProductVariant" v WHERE v."productId" = p.id) AS variants FROM "Product" p WHERE id = ?', [$productId]);
        $catOk = self::categoryOk($d['categoryId'] ?? null);
        if (!$p) {
            return ['ok' => false, 'error' => 'Produit introuvable : il a peut-être été supprimé.'];
        }
        if (isset($d['price'])) {
            if ((int) $p['offers'] > 0) {
                return ['ok' => false, 'error' => 'Ce produit a des offres : changez les prix dans la fiche.'];
            }
            if ($p['compareAt'] !== null && (int) $p['compareAt'] <= $d['price']) {
                return ['ok' => false, 'error' => "L'ancien prix (" . Money::format((int) $p['compareAt']) . ') doit rester plus élevé : changez-le dans la fiche.'];
            }
        }
        if (isset($d['stockQty']) && ($p['stockMode'] !== 'TRACKED' || (int) $p['variants'] > 0)) {
            return ['ok' => false, 'error' => 'Le stock de ce produit se change dans la fiche.'];
        }
        if (isset($d['available']) && $p['stockMode'] !== 'SUPPLIER') {
            return ['ok' => false, 'error' => 'Ce produit compte son stock : changez la quantité.'];
        }
        if (!$catOk) {
            return ['ok' => false, 'error' => 'Catégorie introuvable : rechargez la page.'];
        }
        Db::update('Product', $d + ['updatedAt' => Dates::now()], 'id = ?', [$productId]);
        Refresh::catalog([$productId]);
        return ['ok' => true];
    }

    /** @return ?list<string> */
    private static function ids(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > self::MAX_BULK) {
            return null;
        }
        foreach ($raw as $i) {
            if (!is_string($i) || !preg_match(self::ID, $i)) {
                return null;
            }
        }
        return array_values(array_unique($raw));
    }

    private static function done(array $productIds, array $slugs = []): void
    {
        Refresh::catalog($productIds, $slugs);
        Refresh::path();
    }

    /** One change on the products ticked in the list, in one statement. */
    public static function bulkUpdate(mixed $rawIds, mixed $raw): array
    {
        $list = self::ids($rawIds);
        $parsed = Z::union([
            Z::obj(['active' => Z::bool()], true),
            Z::obj(['featured' => Z::bool()], true),
            Z::obj(['available' => Z::bool()], true),
            Z::obj(['categoryId' => Z::str()->min(1)->max(40)->nullable()], true),
        ])->safeParse($raw);
        if ($list === null) {
            return ['ok' => false, 'error' => 'Sélection invalide (' . self::MAX_BULK . ' produits au plus).'];
        }
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => 'Changement invalide : rechargez la page.'];
        }
        $patch = $parsed['data'];
        $in = 'id IN (' . Db::marks($list) . ')';
        $now = Dates::now();
        if (array_key_exists('categoryId', $patch) && $patch['categoryId']) {
            $name = Db::value('SELECT name FROM "Category" WHERE id = ?', [$patch['categoryId']]);
            if ($name === null) {
                return ['ok' => false, 'error' => 'Catégorie introuvable : rechargez la page.'];
            }
            $n = Db::update('Product', $patch + ['updatedAt' => $now], $in, $list);
            self::done($list);
            return ['ok' => true, 'message' => self::bulkMessage($patch, $n, ['categoryName' => $name])];
        }
        if (array_key_exists('available', $patch)) {
            // Availability is the supplier's word; a product whose stock is counted sells what is counted.
            [$n, $skipped] = Db::tx(static function () use ($patch, $now, $in, $list) {
                $n = Db::update('Product', $patch + ['updatedAt' => $now], "$in AND \"stockMode\" = 'SUPPLIER'", $list);
                $skipped = (int) Db::value("SELECT count(*) FROM \"Product\" WHERE $in AND \"stockMode\" = 'TRACKED'", $list);
                return [$n, $skipped];
            });
            self::done($list);
            return ['ok' => true, 'message' => self::bulkMessage($patch, $n, ['skipped' => $skipped])];
        }
        $n = Db::update('Product', $patch + ['updatedAt' => $now], $in, $list);
        self::done($list);
        return ['ok' => true, 'message' => self::bulkMessage($patch, $n)];
    }

    /** Deleting keeps past orders intact (their lines are snapshots); pictures no other product uses go too. */
    public static function bulkDelete(mixed $rawIds): array
    {
        $list = self::ids($rawIds);
        if ($list === null) {
            return ['ok' => false, 'error' => 'Sélection invalide (' . self::MAX_BULK . ' produits au plus).'];
        }
        $in = Db::marks($list);
        $media = array_column(Db::all("SELECT \"mediaId\" FROM \"ProductImage\" WHERE \"productId\" IN ($in)", $list), 'mediaId');
        $gone = Db::all("SELECT slug, \"oldSlugs\" FROM \"Product\" WHERE id IN ($in)", $list);
        $n = Db::run("DELETE FROM \"Product\" WHERE id IN ($in)", $list)->rowCount();
        ProductImages::dropUnused($media);
        $slugs = [];
        foreach ($gone as $p) {
            $slugs = [...$slugs, $p['slug'], ...Db::parseArray($p['oldSlugs'])];
        }
        self::done([], $slugs);
        return ['ok' => true, 'message' => self::bulkMessage('delete', $n)];
    }

    private static function pl(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n > 1 ? $many : $one);
    }

    /** list-params.ts bulkMessage: "12 produits mis en ligne". */
    public static function bulkMessage(array|string $patch, int $n, array $extra = []): string
    {
        if ($patch === 'delete') {
            $text = self::pl($n, 'produit supprimé', 'produits supprimés') . '.';
        } elseif (array_key_exists('active', $patch)) {
            $text = self::pl($n, 'produit', 'produits') . ' ' . ($patch['active'] ? 'mis en ligne' : 'mis hors ligne') . '.';
        } elseif (array_key_exists('featured', $patch)) {
            $text = self::pl($n, 'produit', 'produits') . ' ' . ($patch['featured'] ? ($n > 1 ? 'ajoutés' : 'ajouté') . ' à la sélection' : ($n > 1 ? 'retirés' : 'retiré') . ' de la sélection') . '.';
        } elseif (array_key_exists('available', $patch)) {
            $text = $patch['available'] ? self::pl($n, 'produit marqué disponible', 'produits marqués disponibles') . '.' : self::pl($n, 'produit marqué indisponible', 'produits marqués indisponibles') . '.';
        } else {
            $text = $patch['categoryId'] ? self::pl($n, 'produit rangé', 'produits rangés') . ' dans « ' . ($extra['categoryName'] ?? 'la catégorie') . ' ».' : self::pl($n, 'produit', 'produits') . ' sans catégorie.';
        }
        if (!empty($extra['skipped'])) {
            $text .= ' ' . self::pl($extra['skipped'], 'autre compte son stock : inchangé', 'autres comptent leur stock : inchangés') . '.';
        }
        return $text;
    }
}
