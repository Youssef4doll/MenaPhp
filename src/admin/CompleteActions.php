<?php
declare(strict_types=1);

/** app/admin/(panel)/produits/completer/actions.ts: a product's page completed from the web. */
final class CompleteActions
{
    private const ID = '/^[a-z0-9]{20,40}$/';
    private const MAX_PAGES = 6;
    private const MAX_CANDIDATES = 30;

    private static function host(string $url): string
    {
        $u = WebUrl::parse($url);
        return $u ? (string) preg_replace('/^www\./', '', $u->host) : '';
    }

    /** @return ?list<string> */
    private static function links(mixed $raw): ?array
    {
        $r = Z::arr(Z::str()->max(2000))->max(10)->safeParse($raw);
        return $r['ok'] ? $r['data'] : null;
    }

    /**
     * The pages (the AI's and the owner's links) read by the server for their large pictures and
     * videos; the owner's direct photo addresses and the AI's come first.
     */
    private static function gather(array $report, array $extra): array
    {
        $direct = [...array_values(array_filter($extra, [WebProduct::class, 'isImageUrl'])), ...array_values(array_filter($report['images'], static fn ($u) => (bool) preg_match('#^https://#i', $u)))];
        $pageUrls = array_values(array_unique([...array_values(array_filter($extra, static fn ($u) => !WebProduct::isImageUrl($u))), ...array_column($report['pages'], 'url')]));
        $pageUrls = array_slice(array_values(array_filter($pageUrls, static fn ($u) => (bool) preg_match('#^https?://#i', $u))), 0, self::MAX_PAGES);
        $reads = array_map([WebProduct::class, 'read'], $pageUrls);
        $images = [];
        $seen = [];
        $add = static function (string $url, string $from) use (&$images, &$seen) {
            $big = WebProduct::bigImageUrl($url);
            $key = (string) preg_replace('/\?.*$/s', '', $big);
            if (isset($seen[$key]) || count($images) >= self::MAX_CANDIDATES) {
                return;
            }
            $seen[$key] = true;
            $images[] = ['url' => $big, 'from' => $from];
        };
        foreach ($direct as $u) {
            $add($u, self::host($u));
        }
        foreach ($reads as $r) {
            if ($r['ok']) {
                foreach ($r['finds']['images'] as $u) {
                    $add($u, self::host($r['url']));
                }
            }
        }
        $videos = [];
        foreach ([$report['video'], ...array_merge([], ...array_map(static fn ($r) => $r['ok'] ? $r['finds']['videos'] : [], $reads))] as $v) {
            $a = $v ? WebProduct::videoAddress($v) : null;
            if ($a !== null && !in_array($a, $videos, true)) {
                $videos[] = $a;
            }
        }
        $first = null;
        foreach ($reads as $r) {
            if ($r['ok'] && ($r['finds']['bullets'] || $r['finds']['description'])) {
                $first = $r;
                break;
            }
        }
        $found = $first ? implode("\n\n", array_filter([$first['finds']['description'], implode("\n", array_map(static fn ($b) => "- $b", $first['finds']['bullets']))])) : '';
        $titles = [];
        foreach ($reads as $r) {
            if ($r['ok']) {
                $titles[$r['url']] = $r['finds']['title'];
            }
        }
        $pages = array_map(static function ($url) use ($report, $titles, $extra) {
            $fromAi = null;
            foreach ($report['pages'] as $p) {
                if ($p['url'] === $url) {
                    $fromAi = $p;
                    break;
                }
            }
            $title = ($fromAi['title'] ?? '') ?: (($titles[$url] ?? '') ?: self::host($url));
            return ['url' => $url, 'title' => $title, 'sure' => $fromAi['sure'] ?? in_array($url, $extra, true)];
        }, $pageUrls);
        $notes = [];
        foreach ($reads as $r) {
            if (!$r['ok']) {
                $notes[] = self::host($r['url']) . ' : ' . $r['error'];
            }
        }
        $texts = WebComplete::cleanTexts($report['texts']);
        return ['pages' => $pages, 'images' => $images, 'videos' => array_slice($videos, 0, 6), 'texts' => $texts ?: (object) [], 'found' => Text::slice($found, 0, 4000), 'notes' => $notes];
    }

    private static function failure(Throwable $e): array
    {
        $stop = $e instanceof Anthropic\Core\Exceptions\APIException;
        return ['ok' => false, 'error' => Ai::failure($e, "Trop de recherches d'un coup : réessayez dans une minute.", "L'IA ne répond pas pour le moment. Réessayez plus tard.", 'Recherche impossible.'), 'stop' => $stop];
    }

    /** One product searched on the web by the AI (with the key), plus the owner's own links. */
    public static function searchProduct(mixed $id, mixed $rawLinks): array
    {
        if (!is_string($id) || !preg_match(self::ID, $id)) {
            return ['ok' => false, 'error' => 'Produit inconnu.', 'stop' => false];
        }
        $extra = self::links($rawLinks);
        if ($extra === null) {
            return ['ok' => false, 'error' => 'Dix liens au plus.', 'stop' => false];
        }
        $p = Db::one('SELECT name, "nameAr", summary, description, specs FROM "Product" WHERE id = ?', [$id]);
        if (!$p) {
            return ['ok' => false, 'error' => 'Produit introuvable.', 'stop' => false];
        }
        $report = ['pages' => [], 'images' => [], 'video' => '', 'texts' => []];
        if (Ai::ready()) {
            try {
                $report = WebComplete::research(['ref' => 'P1', 'name' => $p['name'], 'nameAr' => $p['nameAr'] ?? '', 'summary' => $p['summary'], 'description' => $p['description'], 'specs' => $p['specs']]);
            } catch (Throwable $e) {
                // The owner's links are still worth reading when the AI could not help.
                if (!$extra) {
                    return self::failure($e);
                }
            }
        } elseif (!$extra) {
            return ['ok' => false, 'error' => "Collez au moins un lien (une page du produit ou l'adresse d'une photo).", 'stop' => false];
        }
        return ['ok' => true, 'candidates' => self::gather($report, $extra)];
    }

    /** An answer pasted from claude.ai (no API key): its pages are read the same way. */
    public static function readReport(mixed $id, mixed $raw, mixed $rawLinks): array
    {
        if (!is_string($id) || !preg_match(self::ID, $id)) {
            return ['ok' => false, 'error' => 'Produit inconnu.', 'stop' => false];
        }
        $report = WebComplete::report()->safeParse($raw);
        $extra = self::links($rawLinks);
        if (!$report['ok'] || $extra === null) {
            return ['ok' => false, 'error' => 'Réponse illisible.', 'stop' => false];
        }
        return ['ok' => true, 'candidates' => self::gather($report['data'], $extra)];
    }

    /** The texts and the video the owner kept, written into the product. Only the fields sent are changed. */
    public static function applyToProduct(mixed $id, mixed $raw): array
    {
        if (!is_string($id) || !preg_match(self::ID, $id)) {
            return ['ok' => false, 'error' => 'Produit inconnu.'];
        }
        $body = Z::obj(['texts' => WebComplete::texts(true), 'video' => Z::str()->max(500)->nullable()])->safeParse($raw);
        if (!$body['ok']) {
            return ['ok' => false, 'error' => 'Envoi refusé.'];
        }
        $texts = WebComplete::cleanTexts($body['data']['texts']);
        $video = Text::trim((string) ($body['data']['video'] ?? '')) ?: null;
        if ($video && !WebProduct::videoAddress($video)) {
            return ['ok' => false, 'error' => "Cette vidéo n'est pas acceptée (YouTube, Vimeo, TikTok, Instagram ou Facebook)."];
        }
        if (!$texts && !$video) {
            return ['ok' => true];
        }
        if (!Db::value('SELECT 1 FROM "Product" WHERE id = ?', [$id])) {
            return ['ok' => false, 'error' => 'Produit introuvable.'];
        }
        Db::update('Product', $texts + ($video ? ['videoUrl' => $video] : []) + ['updatedAt' => Dates::now()], 'id = ?', [$id]);
        return ['ok' => true];
    }

    /** Once products were completed: their pages and the lists show it at once. */
    public static function completionDone(mixed $ids): array
    {
        $valid = is_array($ids) ? array_slice(array_values(array_filter($ids, static fn ($i) => is_string($i) && preg_match(self::ID, $i))), 0, 200) : [];
        Refresh::catalog($valid);
        Refresh::path();
        return ['ok' => true];
    }
}

/** app/admin/(panel)/produits/fournisseur/actions.ts */
final class SupplierActions
{
    /** The product page written by Claude from the supplier's page the owner pasted. Nothing is saved. */
    public static function writeDraft(mixed $text): array
    {
        if (!Ai::ready()) {
            return ['ok' => false, 'error' => "IA non configurée : ajoutez la clé anthropic_api_key dans config.php, sur l'hébergement, puis rechargez la page."];
        }
        $page = Z::str()->trim()->min(20)->max(SupplierAi::MAX_PAGE_TEXT)->safeParse($text);
        if (!$page['ok']) {
            return ['ok' => false, 'error' => 'La page collée est vide ou trop longue.'];
        }
        $categories = array_column(Db::all('SELECT name FROM "Category" ORDER BY sort ASC, name ASC'), 'name');
        try {
            return ['ok' => true, 'draft' => SupplierAi::write($page['data'], $categories)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => Ai::failure($e, "Trop de demandes d'un coup : réessayez dans une minute.", "L'IA ne répond pas pour le moment. Réessayez plus tard.", 'Écriture impossible.')];
        }
    }

    /** For each pasted product, the shop's product it already is. Writes nothing. */
    public static function matchProducts(mixed $raw): array
    {
        $list = Z::arr(Z::obj(['ref' => Z::str()->max(120), 'slug' => Z::str()->max(100)]))->max(2000)->parse($raw);
        $code = static fn (?string $s) => mb_strtolower(Text::trim((string) $s));
        $refs = array_values(array_unique(array_filter(array_map(static fn ($i) => Text::trim($i['ref']), $list))));
        $slugs = array_values(array_unique(array_filter(array_column($list, 'slug'))));
        if (!$refs && !$slugs) {
            return array_map(static fn () => ['match' => null, 'slugTaken' => false], $list);
        }
        $or = [];
        $params = [];
        if ($refs) {
            $or[] = 'lower("supplierRef") IN (' . Db::marks($refs) . ')';
            $params = [...$params, ...array_map('mb_strtolower', $refs)];
        }
        if ($slugs) {
            $or[] = 'slug IN (' . Db::marks($slugs) . ')';
            $or[] = '"oldSlugs" && ?::text[]';
            $params = [...$params, ...$slugs, Db::pgArray($slugs)];
        }
        $found = Db::all('SELECT p.id, p.slug, p."oldSlugs", p.name, p.active, p.price, p.cost, p."supplierRef", p."supplierUrl", p.summary, p.description, p.specs, p."seoTitle", p."seoDescription",
            c.name AS category, (SELECT count(*) FROM "ProductVariant" v WHERE v."productId" = p.id) AS variants,
            (SELECT count(*) FROM "ProductImage" i WHERE i."productId" = p.id AND i.kind = \'GALLERY\') AS photos
            FROM "Product" p LEFT JOIN "Category" c ON c.id = p."categoryId" WHERE ' . implode(' OR ', $or) . ' ORDER BY p."createdAt" ASC', $params);
        foreach ($found as &$f) {
            $f['oldSlugs'] = Db::parseArray($f['oldSlugs']);
        }
        unset($f);
        return array_map(static function ($i) use ($found, $code) {
            $atSlug = null;
            if ($i['slug'] !== '') {
                foreach ($found as $f) {
                    if ($f['slug'] === $i['slug']) {
                        $atSlug = $f;
                        break;
                    }
                }
                if (!$atSlug) {
                    foreach ($found as $f) {
                        if (in_array($i['slug'], $f['oldSlugs'], true)) {
                            $atSlug = $f;
                            break;
                        }
                    }
                }
            }
            $other = $atSlug && $code($i['ref']) !== '' && $code($atSlug['supplierRef']) !== '' && $code($atSlug['supplierRef']) !== $code($i['ref']);
            $p = null;
            if ($code($i['ref']) !== '') {
                foreach ($found as $f) {
                    if ($code($f['supplierRef']) === $code($i['ref'])) {
                        $p = $f;
                        break;
                    }
                }
            }
            $p ??= $other ? null : $atSlug;
            if (!$p) {
                return ['match' => null, 'slugTaken' => (bool) $atSlug];
            }
            return ['slugTaken' => false, 'match' => [
                'id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'active' => Db::bool($p['active']), 'price' => (int) $p['price'], 'cost' => $p['cost'] === null ? null : (int) $p['cost'],
                'category' => $p['category'], 'supplierUrl' => $p['supplierUrl'] ?? '',
                'filled' => ['summary' => Text::filled($p['summary']), 'description' => Text::filled($p['description']), 'specs' => Text::filled($p['specs']), 'variants' => (int) $p['variants'] > 0, 'seo' => Text::filled($p['seoTitle']) || Text::filled($p['seoDescription'])],
                'photos' => (int) $p['photos'],
            ]];
        }, $list);
    }
}

/** app/admin/(panel)/produits/traduction/actions.ts: many products translated at once. */
final class TranslateActions
{
    private const ID = '/^[a-z0-9]{20,40}$/';

    /** Writes translated texts into the products, merged with the texts read from the database now. */
    private static function saveTexts(array $items): array
    {
        if (!$items) {
            return [];
        }
        $rows = Translate::stored(array_column($items, 'id'));
        $writes = [];
        $out = [];
        foreach ($items as ['id' => $id, 'answer' => $answer]) {
            $row = $rows[$id] ?? null;
            if (!$row) {
                $out[] = ['id' => $id, 'status' => 'missing', 'left' => false];
                continue;
            }
            $before = Translate::storedTexts($row);
            $after = Translate::merge($before, $answer, 'fill');
            $left = Translate::needed($after);
            $w = Translate::writes($before, $after);
            if (!$w) {
                $out[] = ['id' => $id, 'status' => 'nothing', 'left' => $left] + ($left ? ['texts' => $after] : []);
                continue;
            }
            $writes[] = [$id, $row, $w];
            $out[] = ['id' => $id, 'status' => 'translated', 'left' => $left] + ($left ? ['texts' => $after] : []);
        }
        if ($writes) {
            Db::tx(static function () use ($writes) {
                foreach ($writes as [$id, $row, $w]) {
                    // updatedAt moves even when only an option changed: an open editor then shows the saved texts.
                    Db::update('Product', $w['product'] + ['updatedAt' => Dates::now()], 'id = ?', [$id]);
                    foreach ($w['variants'] as $v) {
                        Db::update('ProductVariant', ['name' => $v['name'], 'nameAr' => $v['nameAr']], 'id = ?', [$row['variants'][$v['index']]['id']]);
                    }
                    foreach ($w['offers'] as $o) {
                        Db::update('ProductOffer', ['label' => $o['label'], 'labelAr' => $o['labelAr']], 'id = ?', [$row['offers'][$o['index']]['id']]);
                    }
                }
            });
        }
        return $out;
    }

    /** The translations of one batch, read from a reply pasted in the browser. */
    public static function applyTranslations(mixed $raw): array
    {
        $parsed = Z::arr(Z::obj(['id' => Z::str()->regex(self::ID), 'texts' => Z::unknown()->default(null)]))->min(1)->max(20)->safeParse($raw);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => 'Envoi refusé : 20 produits au plus par envoi.'];
        }
        $valid = [];
        $invalid = [];
        $seen = [];
        foreach ($parsed['data'] as $it) {
            if (isset($seen[$it['id']])) {
                continue;
            }
            $seen[$it['id']] = true;
            $t = Translate::translationSchema()->safeParse($it['texts']);
            if ($t['ok']) {
                $valid[] = ['id' => $it['id'], 'answer' => $t['data']];
            } else {
                $invalid[] = ['id' => $it['id'], 'status' => 'invalid', 'left' => true];
            }
        }
        $results = [...self::saveTexts($valid), ...$invalid];
        if (array_filter($results, static fn ($r) => $r['status'] === 'translated')) {
            Refresh::tag();
        }
        return ['ok' => true, 'results' => $results];
    }

    /** One product translated with the API key and saved at once. */
    public static function translateStored(mixed $id): array
    {
        if (!Ai::ready()) {
            return ['ok' => false, 'error' => Ai::NOT_READY_TRANSLATE, 'stop' => true];
        }
        if (!is_string($id) || !preg_match(self::ID, $id)) {
            return ['ok' => false, 'error' => 'Produit inconnu.', 'stop' => false];
        }
        $row = Translate::stored([$id])[$id] ?? null;
        if (!$row) {
            return ['ok' => true, 'result' => ['id' => $id, 'status' => 'missing', 'left' => false]];
        }
        $texts = Translate::storedTexts($row);
        if (!Translate::needed($texts)) {
            return ['ok' => true, 'result' => ['id' => $id, 'status' => 'nothing', 'left' => false]];
        }
        try {
            $answer = Translate::translationSchema()->safeParse(Translate::product($texts, 'fill'));
            if (!$answer['ok']) {
                return ['ok' => true, 'result' => ['id' => $id, 'status' => 'invalid', 'left' => true]];
            }
            [$result] = self::saveTexts([['id' => $id, 'answer' => $answer['data']]]);
            if ($result['status'] === 'translated') {
                Refresh::tag();
            }
            return ['ok' => true, 'result' => ['id' => $id, 'status' => $result['status'], 'left' => $result['left']]];
        } catch (Throwable $e) {
            $stop = $e instanceof Anthropic\Core\Exceptions\APIException;
            return ['ok' => false, 'error' => Ai::failure($e, "Trop de traductions d'un coup : réessayez dans une minute.", 'Le service de traduction ne répond pas pour le moment. Réessayez plus tard.', 'Traduction impossible.'), 'stop' => $stop];
        }
    }

    /** Once a run is over: the product list and this page show the new texts at once. */
    public static function translationsDone(): array
    {
        Refresh::catalog();
        Refresh::path();
        return ['ok' => true];
    }
}

/** app/admin/(panel)/produits/import/actions.ts */
final class ImportActions
{
    /** Once at the end of an import: the shop rebuilds, the product list shows everything at once. */
    public static function finishImport(): array
    {
        Refresh::catalog();
        Refresh::path();
        return ['ok' => true];
    }

    /** "Mettre en ligne les N produits créés". */
    public static function activateProducts(mixed $raw): array
    {
        $parsed = Z::arr(Z::str()->regex('/^[a-z0-9]{20,40}$/'))->min(1)->max(2000)->safeParse($raw);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => 'Liste de produits invalide.'];
        }
        $ids = $parsed['data'];
        $n = Db::update('Product', ['active' => true, 'updatedAt' => Dates::now()], 'id IN (' . Db::marks($ids) . ')', $ids);
        Refresh::catalog($ids);
        Refresh::path();
        return ['ok' => true, 'count' => $n];
    }
}
