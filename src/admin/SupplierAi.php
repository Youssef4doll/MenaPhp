<?php
declare(strict_types=1);

/**
 * A product page written by Claude from a supplier's page, in French and in
 * Tunisian Arabic: lib/supplier-ai.ts and supplier-prompt.ts. Nothing is
 * saved here: the back office shows the result first.
 */
final class SupplierAi
{
    public const MAX_PAGE_TEXT = 20_000;

    /** Draft */
    public static function draft(): Z
    {
        $variant = Z::obj(['name' => Z::str(), 'nameAr' => Z::str(), 'colorHex' => Z::str()]);
        return Z::obj([
            'name' => Z::str(), 'nameAr' => Z::str(), 'summary' => Z::str(), 'summaryAr' => Z::str(), 'description' => Z::str(), 'descriptionAr' => Z::str(),
            'specs' => Z::str(), 'specsAr' => Z::str(), 'keywords' => Z::str(),
            'seoTitle' => Z::str()->default(''), 'seoTitleAr' => Z::str()->default(''), 'seoDescription' => Z::str()->default(''), 'seoDescriptionAr' => Z::str()->default(''),
            'category' => Z::str(), 'supplierPrice' => Z::str(), 'supplierRef' => Z::str(),
            'variants' => Z::arr($variant),
            'imageIdeas' => Z::arr(ImageIdeas::idea())->default([]),
        ]);
    }

    /** draftTask() */
    public static function task(string $text, array $categories): string
    {
        $t = AiPrompts::T;
        return str_replace([Text::json(["{$t}cat"]), "{$t}text"], [Text::json(array_values($categories)), $text], AiPrompts::get('supplier', 'task'));
    }

    private static function line(string $s, int $max): string
    {
        return Text::slice(Text::trim((string) preg_replace('/' . Text::WS . '*[\r\n]+' . Text::WS . '*/u', ' ', $s)), 0, $max);
    }

    private static function block(string $s, int $max): string
    {
        $s = (string) preg_replace('/\r\n?/', "\n", $s);
        $s = (string) preg_replace('/\n{3,}/', "\n\n", $s);
        return Text::slice(Text::trim($s), 0, $max);
    }

    /** cleanDraft(): names on one line, lengths cut, colours checked, empty options dropped. */
    public static function clean(array $d): array
    {
        $seen = [];
        $variants = [];
        foreach ($d['variants'] as $v) {
            $hex = strtolower(Text::trim($v['colorHex']));
            $v = ['name' => self::line($v['name'], 60), 'nameAr' => self::line($v['nameAr'], 60), 'colorHex' => preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : ''];
            $k = mb_strtolower($v['name']);
            if ($v['name'] === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $variants[] = $v;
        }
        return [
            'name' => self::line($d['name'], 140), 'nameAr' => self::line($d['nameAr'], 140), 'summary' => self::line($d['summary'], 300), 'summaryAr' => self::line($d['summaryAr'], 300),
            'description' => self::block($d['description'], 8000), 'descriptionAr' => self::block($d['descriptionAr'], 8000), 'specs' => self::block($d['specs'], 4000), 'specsAr' => self::block($d['specsAr'], 4000),
            'keywords' => self::line($d['keywords'], 1000), 'seoTitle' => self::line($d['seoTitle'], 120), 'seoTitleAr' => self::line($d['seoTitleAr'], 120),
            'seoDescription' => self::line($d['seoDescription'], 320), 'seoDescriptionAr' => self::line($d['seoDescriptionAr'], 320), 'category' => self::line($d['category'], 60),
            'supplierPrice' => self::line($d['supplierPrice'], 40), 'supplierRef' => self::line($d['supplierRef'], 120),
            'variants' => array_slice($variants, 0, 60),
            'imageIdeas' => ImageIdeas::cleanIdeas($d['imageIdeas']),
        ];
    }

    /** draftFromSupplierPage() */
    public static function write(string $text, array $categories): array
    {
        $r = Ai::json(AiPrompts::get('supplier', 'system'), self::task($text, $categories), AiPrompts::get('supplier', 'schema'), 16000, 'medium', true);
        if ($r['stop'] === 'refusal') {
            throw new AiError("L'IA a refusé d'écrire cette fiche. Écrivez-la vous-même.");
        }
        if ($r['stop'] === 'max_tokens') {
            throw new AiError('Page trop longue : copiez seulement la partie du produit.');
        }
        if ($r['text'] === null) {
            throw new AiError('Réponse vide.');
        }
        $parsed = self::draft()->safeParse(Ai::parse($r['text']));
        if (!$parsed['ok']) {
            throw new AiError('Réponse illisible.');
        }
        return self::clean($parsed['data']);
    }
}
