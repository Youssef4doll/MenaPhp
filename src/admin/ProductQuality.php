<?php
declare(strict_types=1);

/**
 * How complete a product page is, out of 100, and what it lacks:
 * lib/product-quality.ts. Each point is something a customer sees (or Google
 * reads); a product loses only the points of what is missing.
 */
final class ProductQuality
{
    public const CHECKS = [
        ['key' => 'photos', 'label' => 'Photos', 'max' => 15],
        ['key' => 'details', 'label' => 'Grandes images de description', 'max' => 15],
        ['key' => 'description', 'label' => 'Description', 'max' => 10],
        ['key' => 'arabic', 'label' => 'Arabe', 'max' => 10],
        ['key' => 'checked', 'label' => 'Vérifiée avec le fournisseur', 'max' => 10],
        ['key' => 'summary', 'label' => 'Phrase sous le nom', 'max' => 5],
        ['key' => 'specs', 'label' => 'Caractéristiques', 'max' => 5],
        ['key' => 'category', 'label' => 'Catégorie', 'max' => 5],
        ['key' => 'google', 'label' => 'Titre et description Google', 'max' => 5],
        ['key' => 'keywords', 'label' => 'Mots-clés de recherche', 'max' => 5],
        ['key' => 'cost', 'label' => 'Coût fournisseur', 'max' => 5],
        ['key' => 'video', 'label' => 'Vidéo', 'max' => 5],
        ['key' => 'offers', 'label' => 'Offres cohérentes', 'max' => 5],
    ];

    public static function max(string $key): int
    {
        foreach (self::CHECKS as $c) {
            if ($c['key'] === $key) {
                return $c['max'];
            }
        }
        return 0;
    }

    /**
     * productQuality(). $p: the product's columns, with 'gallery' and 'details' (counts) and
     * 'offers' (quantity, label, labelAr, active).
     * @return array{score: int, missing: list<array{key: string, lost: int, text: string}>}
     */
    public static function score(array $p): array
    {
        $missing = [];
        $lose = static function (string $key, int $got, string $text) use (&$missing) {
            $max = self::max($key);
            if ($got < $max) {
                $missing[] = ['key' => $key, 'lost' => $max - $got, 'text' => $text];
            }
        };
        $f = static fn ($s) => Text::filled($s === null ? null : (string) $s);
        $g = (int) $p['gallery'];
        $dt = (int) $p['details'];
        $lose('photos', $g >= 3 ? 15 : ($g === 2 ? 10 : ($g === 1 ? 5 : 0)), $g === 0 ? 'Aucune photo' : "$g photo" . ($g > 1 ? 's' : '') . ' seulement (3 ou plus conseillées)');
        $lose('details', $dt >= 2 ? 15 : ($dt === 1 ? 10 : 0), $dt === 0 ? 'Pas de grandes images de description' : '1 seule grande image de description');
        $len = Text::len(Text::trim((string) $p['description']));
        $lose('description', $len >= 200 ? 10 : ($len >= 60 ? 6 : 0), $len === 0 ? 'Pas de description' : 'Description courte (200 caractères ou plus conseillés)');
        // Arabic, the shop's main language: the name, and each text that exists in French.
        $ar = ($f($p['nameAr']) ? 4 : 0) + (!$f($p['description']) || $f($p['descriptionAr']) ? 4 : 0) + (!$f($p['summary']) || $f($p['summaryAr']) ? 2 : 0);
        $lacking = array_values(array_filter([!$f($p['nameAr']) ? 'nom' : false, $f($p['description']) && !$f($p['descriptionAr']) ? 'description' : false, $f($p['summary']) && !$f($p['summaryAr']) ? 'phrase' : false]));
        $lose('arabic', $ar, 'Arabe manquant : ' . implode(', ', $lacking));
        $lose('checked', $p['checkedAt'] ? 10 : 0, 'Fiche pas encore vérifiée avec le fournisseur');
        $lose('summary', $f($p['summary']) ? 5 : 0, 'Pas de phrase sous le nom');
        $lose('specs', $f($p['specs']) ? 5 : 0, 'Pas de caractéristiques');
        $lose('category', $p['categoryId'] ? 5 : 0, 'Sans catégorie');
        $lose('google', $f($p['seoTitle']) && $f($p['seoDescription']) ? 5 : ($f($p['seoTitle']) || $f($p['seoDescription']) ? 3 : 0), 'Titre ou description Google à écrire');
        $lose('keywords', $f($p['keywords']) ? 5 : 0, 'Pas de mots-clés de recherche');
        $lose('cost', $p['cost'] !== null ? 5 : 0, 'Coût fournisseur inconnu (marge non calculée)');
        $lose('video', $f($p['videoUrl']) ? 5 : 0, 'Pas de vidéo');
        $bad = count(array_filter($p['offers'], static fn ($o) => Db::bool($o['active']) && ProductWrite::offerLabelProblem($o)));
        $lose('offers', $bad ? 0 : 5, "$bad offre" . ($bad > 1 ? 's' : '') . ' dont le nom contredit la quantité');
        $score = 100 - array_sum(array_column($missing, 'lost'));
        // Array.prototype.sort is stable: equal losses keep the order of the checks.
        usort($missing, static fn ($a, $b) => $b['lost'] <=> $a['lost']);
        return ['score' => $score, 'missing' => $missing];
    }
}
