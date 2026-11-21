<?php
declare(strict_types=1);

/**
 * Category names in Tunisian Arabic: lib/category-ar.ts and category-ai.ts.
 * The common aisles have their name here (free, at once, also when an import
 * creates the category); the others go to Claude with the key.
 */
final class CategoryAr
{
    private const AR = [
        'maison' => 'الدار',
        'cuisine' => 'الكوجينة',
        'beaute' => 'التجميل',
        'accessoires' => 'الإكسسوارات',
        'high-tech' => 'التكنولوجيا',
        'hightech' => 'التكنولوجيا',
        'meubles' => 'الموبيليا',
        'decoration' => 'الديكور',
        'sport' => 'الرياضة',
        'fitness' => 'الرياضة',
        'electronique' => 'الإلكترونيك',
        'auto' => 'الكرهبة',
        'voiture' => 'الكرهبة',
        'sante' => 'الصحة',
        'bebe' => 'البيبي',
        'enfants' => 'الصغار',
        'jouets' => 'اللعب',
        'mode' => 'الموضة',
        'vetements' => 'الحوايج',
        'chaussures' => 'الصبابط',
        'sacs' => 'الصاكوات',
        'bijoux' => 'المجوهرات',
        'montres' => 'المنقالات',
        'telephonie' => 'التليفونات',
        'telephones' => 'التليفونات',
        'informatique' => 'الإعلامية',
        'jeux video' => 'ألعاب الفيديو',
        'bricolage' => 'البريكولاج',
        'outils' => 'العدّة',
        'jardin' => 'الجنينة',
        'animaux' => 'الحيوانات',
        'bureau' => 'المكتب',
        'eclairage' => 'الضو',
        'salle de bain' => 'بيت البانو',
        'chambre' => 'بيت النوم',
        'plein air' => 'الخرجات',
        'voyage' => 'السفر',
        'menage' => 'التنظيف',
        'nettoyage' => 'التنظيف',
        'electromenager' => 'الكهرومنزلي',
        'cheveux' => 'الشعر',
        'maquillage' => 'الماكياج',
        'soins' => 'العناية',
        'parfums' => 'العطورات',
        'homme' => 'الرجال',
        'femme' => 'النساء',
        'gadgets' => 'الأدوات',
        'linge de maison' => 'الفرش',
        'rangement' => 'الترتيب',
        'hygiene' => 'النظافة',
        'bien-etre' => 'الراحة',
        'camping' => 'التخييم',
        'cadeaux' => 'الهدايا',
        'cuisine et maison' => 'الكوجينة والدار',
    ];

    private static function key(string $s): string
    {
        if (class_exists('Normalizer')) {
            $s = (string) Normalizer::normalize($s, Normalizer::FORM_D);
        }
        $s = mb_strtolower((string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $s), 'UTF-8');
        $s = (string) preg_replace("/[\x{2019}']/u", ' ', $s);
        return Text::trim((string) preg_replace('/' . Text::WS . '+/u', ' ', $s));
    }

    /** arabicCategory(): the Tunisian Arabic name of a common category, or "" when it is not one. */
    public static function arabic(string $name): string
    {
        return self::AR[self::key($name)] ?? '';
    }

    /** categoriesTask() */
    public static function task(array $items): string
    {
        $list = array_map(static fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'headline' => $c['headline']], $items);
        return 'The categories (JSON):' . "\n" . Text::json($list, 1);
    }

    /** readCategoriesAnswer(): names on one line and within the field's length; null when nothing reads. */
    public static function readAnswer(string $reply): ?array
    {
        $from = strpos($reply, '{');
        $to = strrpos($reply, '}');
        if ($from === false || $to === false || $to <= $from) {
            return null;
        }
        $data = json_decode(substr($reply, $from, $to - $from + 1), true);
        $parsed = Z::obj(['categories' => Z::arr(Z::obj(['id' => Z::str(), 'nameAr' => Z::str(), 'headlineAr' => Z::str()->default('')]))])->safeParse($data);
        if ($data === null || !$parsed['ok']) {
            return null;
        }
        $out = [];
        foreach ($parsed['data']['categories'] as $c) {
            $c = ['id' => $c['id'], 'nameAr' => Text::oneLine($c['nameAr'], 60), 'headlineAr' => Text::oneLine($c['headlineAr'], 60)];
            if ($c['nameAr'] !== '' || $c['headlineAr'] !== '') {
                $out[] = $c;
            }
        }
        return $out ?: null;
    }

    /** translateCategoryTexts(): category names and titles in Tunisian Arabic, by Claude. */
    public static function translate(array $items): array
    {
        $r = Ai::json(AiPrompts::get('categories', 'system'), self::task($items), AiPrompts::get('categories', 'schema'), 8000, 'low', true);
        if ($r['stop'] === 'refusal' || $r['text'] === null) {
            throw new AiError('Traduction impossible.');
        }
        return self::readAnswer($r['text']) ?? [];
    }
}
