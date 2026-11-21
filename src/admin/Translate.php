<?php
declare(strict_types=1);

/**
 * Product texts translated by Claude, French ↔ Tunisian Arabic, with the
 * search keywords and the title and description for Google: lib/translate.ts,
 * translate-merge.ts and translate-batch.ts. What a translation may change is
 * decided here, not by the model: only the fields that were asked for.
 */
final class Translate
{
    private const PAIRS = [['name', 'nameAr'], ['summary', 'summaryAr'], ['description', 'descriptionAr'], ['specs', 'specsAr']];
    private const SEO = [['seoTitle', 'seoTitleAr', 120], ['seoDescription', 'seoDescriptionAr', 320]];
    private const FRENCH = ['name', 'summary', 'description', 'specs', 'keywords', 'seoTitle', 'seoDescription'];
    private const ARABIC = ['nameAr', 'summaryAr', 'descriptionAr', 'specsAr', 'seoTitleAr', 'seoDescriptionAr'];

    /** translate.ts SCHEMA: the answer's form. */
    public static function schema(): array
    {
        $pair = ['type' => 'string'];
        $keys = ['name', 'nameAr', 'summary', 'summaryAr', 'description', 'descriptionAr', 'specs', 'specsAr', 'keywords', 'seoTitle', 'seoTitleAr', 'seoDescription', 'seoDescriptionAr'];
        $props = array_fill_keys($keys, $pair);
        $props['variants'] = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => $pair, 'nameAr' => $pair], 'required' => ['name', 'nameAr'], 'additionalProperties' => false]];
        $props['offers'] = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => $pair, 'labelAr' => $pair], 'required' => ['label', 'labelAr'], 'additionalProperties' => false]];
        return ['type' => 'object', 'properties' => $props, 'required' => [...$keys, 'variants', 'offers'], 'additionalProperties' => false];
    }

    /** translate-prompt.ts Answer: the texts of an answer; the Google texts may be left out. */
    public static function answer(): Z
    {
        return Z::obj([
            'name' => Z::str(), 'nameAr' => Z::str(), 'summary' => Z::str(), 'summaryAr' => Z::str(), 'description' => Z::str(), 'descriptionAr' => Z::str(),
            'specs' => Z::str(), 'specsAr' => Z::str(), 'keywords' => Z::str(),
            'seoTitle' => Z::str()->default(''), 'seoTitleAr' => Z::str()->default(''), 'seoDescription' => Z::str()->default(''), 'seoDescriptionAr' => Z::str()->default(''),
            'variants' => Z::arr(Z::obj(['name' => Z::str(), 'nameAr' => Z::str()])),
            'offers' => Z::arr(Z::obj(['label' => Z::str(), 'labelAr' => Z::str()])),
        ]);
    }

    /** translateProduct(): fills the missing language of each text (or rewrites the Arabic from the French). */
    public static function product(array $texts, string $mode): array
    {
        $task = AiPrompts::get('translate', $mode === 'to-ar' ? 'toAr' : 'fill') . "\n\nProduct texts (JSON):\n" . Text::json($texts);
        $r = Ai::json(AiPrompts::get('translate', 'system'), $task, self::schema(), 16000, 'medium', false);
        if ($r['stop'] === 'refusal') {
            throw new AiError('La traduction a été refusée. Écrivez ce texte vous-même.');
        }
        if ($r['stop'] === 'max_tokens') {
            throw new AiError('Textes trop longs pour être traduits en une fois.');
        }
        if ($r['text'] === null) {
            throw new AiError('Réponse vide.');
        }
        $parsed = self::answer()->safeParse(Ai::parse($r['text']));
        if (!$parsed['ok']) {
            throw new AiError('Réponse illisible.');
        }
        return self::merge($texts, $parsed['data'], $mode);
    }

    /** One French/Arabic pair: the value to keep for each side. */
    private static function pick(string $mode, string $fr, string $ar, ?string $newFr, ?string $newAr): array
    {
        if ($mode === 'to-ar') {
            return [$fr, Text::filled($fr) && Text::filled($newAr) ? Text::trim($newAr) : $ar];
        }
        if (!Text::filled($fr) && Text::filled($ar) && Text::filled($newFr)) {
            return [Text::trim($newFr), $ar];
        }
        if (Text::filled($fr) && !Text::filled($ar) && Text::filled($newAr)) {
            return [$fr, Text::trim($newAr)];
        }
        return [$fr, $ar];
    }

    /** mergeTranslation() */
    public static function merge(array $before, array $answer, string $mode): array
    {
        $out = $before;
        foreach (self::PAIRS as [$fr, $ar]) {
            [$out[$fr], $out[$ar]] = self::pick($mode, $before[$fr], $before[$ar], $answer[$fr] ?? null, $answer[$ar] ?? null);
        }
        if (!Text::filled($before['keywords']) && Text::filled($answer['keywords'] ?? null)) {
            $out['keywords'] = Text::slice(Text::trim($answer['keywords']), 0, 1000);
        }
        foreach (self::SEO as [$fr, $ar, $max]) {
            [$a, $b] = !Text::filled($before[$fr]) && !Text::filled($before[$ar]) ? [$answer[$fr] ?? null, $answer[$ar] ?? null] : self::pick($mode, $before[$fr], $before[$ar], $answer[$fr] ?? null, $answer[$ar] ?? null);
            [$out[$fr], $out[$ar]] = [Text::oneLine($a, $max), Text::oneLine($b, $max)];
        }
        // Options and offers are matched by position; a list of another length is ignored.
        if (count($answer['variants']) === count($before['variants'])) {
            $out['variants'] = array_map(static function ($v, $i) use ($mode, $answer) {
                [$name, $nameAr] = self::pick($mode, $v['name'], $v['nameAr'], $answer['variants'][$i]['name'], $answer['variants'][$i]['nameAr']);
                return ['name' => Text::slice($name, 0, 60), 'nameAr' => Text::slice($nameAr, 0, 60)];
            }, $before['variants'], array_keys($before['variants']));
        }
        if (count($answer['offers']) === count($before['offers'])) {
            $out['offers'] = array_map(static function ($o, $i) use ($mode, $answer) {
                [$label, $labelAr] = self::pick($mode, $o['label'], $o['labelAr'], $answer['offers'][$i]['label'], $answer['offers'][$i]['labelAr']);
                return ['label' => Text::slice($label, 0, 80), 'labelAr' => Text::slice($labelAr, 0, 80)];
            }, $before['offers'], array_keys($before['offers']));
        }
        // The short description stays within what the editor accepts.
        $out['summary'] = Text::slice($out['summary'], 0, 300);
        $out['summaryAr'] = Text::slice($out['summaryAr'], 0, 300);
        return $out;
    }

    /** textsOfProduct(): the texts of a product form. */
    public static function textsOf(array $p): array
    {
        return [
            'name' => $p['name'], 'nameAr' => $p['nameAr'], 'summary' => $p['summary'], 'summaryAr' => $p['summaryAr'], 'description' => $p['description'], 'descriptionAr' => $p['descriptionAr'],
            'specs' => $p['specs'], 'specsAr' => $p['specsAr'], 'keywords' => $p['keywords'],
            'seoTitle' => $p['seoTitle'], 'seoTitleAr' => $p['seoTitleAr'], 'seoDescription' => $p['seoDescription'], 'seoDescriptionAr' => $p['seoDescriptionAr'],
            'variants' => array_map(static fn ($v) => ['name' => $v['name'], 'nameAr' => $v['nameAr'] ?? ''], $p['variants']),
            'offers' => array_map(static fn ($o) => ['label' => $o['label'], 'labelAr' => $o['labelAr']], $p['offers']),
        ];
    }

    /** withProductTexts(): the form with translated texts put back (options and offers by position). */
    public static function withTexts(array $p, array $t): array
    {
        $variants = array_map(static fn ($v, $i) => array_merge($v, ['name' => $t['variants'][$i]['name'] ?? $v['name'], 'nameAr' => $t['variants'][$i]['nameAr'] ?? ($v['nameAr'] ?? null)]), $p['variants'], array_keys($p['variants']));
        $offers = array_map(static fn ($o, $i) => array_merge($o, ['label' => $t['offers'][$i]['label'] ?? $o['label'], 'labelAr' => $t['offers'][$i]['labelAr'] ?? $o['labelAr']]), $p['offers'], array_keys($p['offers']));
        return array_merge($p, $t, ['variants' => $variants, 'offers' => $offers]);
    }

    /** needsTranslation(): a text in one language only, or no keywords or Google texts yet. */
    public static function needed(array $t): bool
    {
        $one = static fn (?string $a, ?string $b) => Text::filled($a) !== Text::filled($b);
        $named = Text::filled($t['name']) || Text::filled($t['nameAr']);
        $none = static fn (?string $a, ?string $b) => $named && !Text::filled($a) && !Text::filled($b);
        if ($one($t['name'], $t['nameAr']) || $one($t['summary'], $t['summaryAr']) || $one($t['description'], $t['descriptionAr']) || $one($t['specs'], $t['specsAr'])
            || $one($t['seoTitle'], $t['seoTitleAr']) || $one($t['seoDescription'], $t['seoDescriptionAr']) || $none($t['seoTitle'], $t['seoTitleAr']) || $none($t['seoDescription'], $t['seoDescriptionAr'])
            || (!Text::filled($t['keywords']) && $named)) {
            return true;
        }
        foreach ($t['variants'] as $v) {
            if ($one($v['name'], $v['nameAr'])) {
                return true;
            }
        }
        foreach ($t['offers'] as $o) {
            if ($one($o['label'], $o['labelAr'])) {
                return true;
            }
        }
        return false;
    }

    /** The columns a translation reads, as stored (textsSelect, without the options and offers). */
    public const COLUMNS = 'name, "nameAr", summary, "summaryAr", description, "descriptionAr", specs, "specsAr", keywords, "seoTitle", "seoTitleAr", "seoDescription", "seoDescriptionAr"';

    /**
     * Stored products' texts, with their options and offers in the order the shop shows them.
     * @param list<string> $ids
     * @return array<string, array> by id, each with its 'variants' and 'offers' rows (with their ids)
     */
    public static function stored(array $ids, string $order = ''): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT id, ' . self::COLUMNS . ' FROM "Product" WHERE id IN (' . Db::marks($ids) . ')' . $order, $ids) as $r) {
            $out[$r['id']] = $r + ['variants' => [], 'offers' => []];
        }
        $found = array_keys($out);
        if ($found) {
            foreach (Db::all('SELECT id, "productId", name, "nameAr" FROM "ProductVariant" WHERE "productId" IN (' . Db::marks($found) . ') ORDER BY sort ASC, id ASC', $found) as $v) {
                $out[$v['productId']]['variants'][] = $v;
            }
            foreach (Db::all('SELECT id, "productId", label, "labelAr" FROM "ProductOffer" WHERE "productId" IN (' . Db::marks($found) . ') ORDER BY sort ASC, id ASC', $found) as $o) {
                $out[$o['productId']]['offers'][] = $o;
            }
        }
        return $out;
    }

    /** storedTexts(): a stored product's texts, empty Arabic columns read as "". */
    public static function storedTexts(array $p): array
    {
        return [
            'name' => $p['name'], 'nameAr' => $p['nameAr'] ?? '', 'summary' => $p['summary'], 'summaryAr' => $p['summaryAr'] ?? '',
            'description' => $p['description'], 'descriptionAr' => $p['descriptionAr'] ?? '', 'specs' => $p['specs'], 'specsAr' => $p['specsAr'] ?? '',
            'keywords' => $p['keywords'], 'seoTitle' => $p['seoTitle'], 'seoTitleAr' => $p['seoTitleAr'] ?? '',
            'seoDescription' => $p['seoDescription'], 'seoDescriptionAr' => $p['seoDescriptionAr'] ?? '',
            'variants' => array_map(static fn ($v) => ['name' => $v['name'], 'nameAr' => $v['nameAr'] ?? ''], $p['variants']),
            'offers' => array_map(static fn ($o) => ['label' => $o['label'], 'labelAr' => $o['labelAr'] ?? ''], $p['offers']),
        ];
    }

    /** translationSchema: what the browser may send back for one product, names and labels on one line. */
    public static function translationSchema(): Z
    {
        $line = static fn (int $max) => Z::str()->max($max)->transform(static fn (string $s) => Text::trim((string) preg_replace('/' . Text::WS . '*[\r\n]+' . Text::WS . '*/u', ' ', $s)));
        return Z::obj([
            'name' => $line(140), 'nameAr' => $line(140),
            'summary' => Z::str()->max(300), 'summaryAr' => Z::str()->max(300),
            'description' => Z::str()->max(8000), 'descriptionAr' => Z::str()->max(8000),
            'specs' => Z::str()->max(4000), 'specsAr' => Z::str()->max(4000),
            'keywords' => Z::str()->max(1000),
            'seoTitle' => $line(120)->default(''), 'seoTitleAr' => $line(120)->default(''),
            'seoDescription' => $line(320)->default(''), 'seoDescriptionAr' => $line(320)->default(''),
            'variants' => Z::arr(Z::obj(['name' => $line(60), 'nameAr' => $line(60)]))->max(60),
            'offers' => Z::arr(Z::obj(['label' => $line(80), 'labelAr' => $line(80)]))->max(8),
        ]);
    }

    /** textWrites(): the columns a merged translation changes, and only those; null when nothing changes. */
    public static function writes(array $before, array $after): ?array
    {
        $product = [];
        foreach (self::FRENCH as $c) {
            if ($after[$c] !== $before[$c]) {
                $product[$c] = $after[$c];
            }
        }
        foreach (self::ARABIC as $c) {
            if ($after[$c] !== $before[$c]) {
                $product[$c] = $after[$c] !== '' ? $after[$c] : null;
            }
        }
        $variants = [];
        foreach ($after['variants'] as $i => $v) {
            $was = $before['variants'][$i] ?? null;
            if ($was && ($v['name'] !== $was['name'] || $v['nameAr'] !== $was['nameAr'])) {
                $variants[] = ['index' => $i, 'name' => $v['name'], 'nameAr' => $v['nameAr'] !== '' ? $v['nameAr'] : null];
            }
        }
        $offers = [];
        foreach ($after['offers'] as $i => $o) {
            $was = $before['offers'][$i] ?? null;
            if ($was && ($o['label'] !== $was['label'] || $o['labelAr'] !== $was['labelAr'])) {
                $offers[] = ['index' => $i, 'label' => $o['label'], 'labelAr' => $o['labelAr'] !== '' ? $o['labelAr'] : null];
            }
        }
        return $product || $variants || $offers ? ['product' => $product, 'variants' => $variants, 'offers' => $offers] : null;
    }

    /** idsParam(): "?ids=a,b,c" → the valid ids, without repeats; absent → null (every product). */
    public static function idsParam(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $raw = array_map(static fn ($s) => Text::trim($s), explode(',', is_array($v) ? implode(',', $v) : (string) $v));
        return array_slice(array_values(array_unique(array_filter($raw, static fn ($s) => (bool) preg_match('/^[a-z0-9]{20,40}$/', $s)))), 0, 2000);
    }
}
