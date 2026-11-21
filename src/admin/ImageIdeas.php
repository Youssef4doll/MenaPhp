<?php
declare(strict_types=1);

/** The tall pictures of a product, planned by Claude from its texts: lib/image-ideas.ts and image-ideas-ai.ts. */
final class ImageIdeas
{
    public const KINDS = ['hero', 'features', 'before_after', 'in_use', 'size', 'in_box', 'comparison'];

    /** Idea: an unknown kind (from a chat assistant) reads as the main benefit. */
    public static function idea(): Z
    {
        return Z::obj([
            'kind' => Z::unknown()->default(null)->transform(static fn ($k) => is_string($k) && in_array($k, self::KINDS, true) ? $k : 'hero'),
            'headlineAr' => Z::str(),
            'headlineFr' => Z::str(),
            'points' => Z::arr(Z::obj(['ar' => Z::str(), 'fr' => Z::str()]))->default([]),
            'scene' => Z::str(),
            'sceneBefore' => Z::str()->default(''),
        ]);
    }

    private static function clean(string $s, int $max): string
    {
        return Text::oneLine($s, $max);
    }

    /** cleanIdeas(): lengths cut, empty ideas dropped, six at most. */
    public static function cleanIdeas(array $ideas): array
    {
        $out = [];
        foreach ($ideas as $i) {
            $points = [];
            foreach ($i['points'] as $p) {
                $p = ['ar' => self::clean($p['ar'], 60), 'fr' => self::clean($p['fr'], 60)];
                if ($p['ar'] !== '' || $p['fr'] !== '') {
                    $points[] = $p;
                }
            }
            $idea = [
                'kind' => $i['kind'],
                'headlineAr' => self::clean($i['headlineAr'], 80),
                'headlineFr' => self::clean($i['headlineFr'], 80),
                'points' => array_slice($points, 0, 3),
                'scene' => self::clean($i['scene'], 2500),
                'sceneBefore' => self::clean($i['sceneBefore'], 2500),
            ];
            if ($idea['scene'] !== '' && ($idea['headlineAr'] !== '' || $idea['headlineFr'] !== '')) {
                $out[] = $idea;
            }
        }
        return array_slice($out, 0, 6);
    }

    /** ideasTask(): section 8 of the brief, with the product's texts. */
    public static function task(array $t): string
    {
        $texts = ['name' => $t['name'], 'summary' => $t['summary'], 'description' => $t['description'], 'specs' => $t['specs'], 'nameAr' => $t['nameAr'], 'descriptionAr' => $t['descriptionAr']];
        return str_replace(AiPrompts::get('ideas', 'texts'), Text::json($texts, 1), AiPrompts::get('ideas', 'task'));
    }

    /** ideasForProduct() */
    public static function forProduct(array $texts): array
    {
        $r = Ai::json(AiPrompts::get('ideas', 'system'), self::task($texts), AiPrompts::get('ideas', 'schema'), 8000, 'medium', true);
        if ($r['stop'] === 'refusal') {
            throw new AiError("L'IA a refusé cette demande.");
        }
        if ($r['text'] === null) {
            throw new AiError('Réponse vide.');
        }
        $parsed = Z::obj(['imageIdeas' => Z::arr(self::idea())])->safeParse(Ai::parse($r['text']));
        if (!$parsed['ok']) {
            throw new AiError('Réponse illisible.');
        }
        return self::cleanIdeas($parsed['data']['imageIdeas']);
    }
}
