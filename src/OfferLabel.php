<?php
declare(strict_types=1);

/**
 * An offer's title is its quantity, written by the shop itself ("زوز حبات",
 * "3 unités"): always true. The name the owner typed ("Achetez 2, la 3e
 * offerte") is shown under it only when it says the same number of units and
 * says something more. Port of src/lib/offer-label.ts: a name that promises
 * what the parcel does not hold gets a cash-on-delivery parcel refused.
 */
final class OfferLabel
{
    private const AR_WORDS = [
        ['/^(?:وحدة|واحدة|واحد)$/u', 1],
        ['/^(?:زوز|زوزي|ثنين|اثنين|إثنين)$/u', 2],
        ['/^(?:ثاني|ثانية|الثاني|الثانية)$/u', 2],
        ['/^(?:ثلاثة|ثلاث|تلاثة|تلاث)$/u', 3],
        ['/^(?:ثالث|ثالثة|الثالث|الثالثة)$/u', 3],
        ['/^(?:أربعة|اربعة|ربعة|أربع|اربع)$/u', 4],
        ['/^(?:رابع|رابعة|الرابع|الرابعة)$/u', 4],
        ['/^(?:خمسة|خمس)$/u', 5],
        ['/^(?:خامس|خامسة|الخامس|الخامسة)$/u', 5],
    ];
    private const FR_WORDS = [
        'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6,
        'deuxieme' => 2, 'second' => 2, 'seconde' => 2, 'troisieme' => 3, 'quatrieme' => 4, 'cinquieme' => 5,
    ];
    /** Words that only name a unit or a pack: "1 unité", "Lot de 2", "حبة وحدة" say nothing more than the title. */
    private const UNIT_WORDS = '/^(?:unites?|pieces?|pcs?|articles?|produits?|x|lot|pack|de|du|des|d|le|la|les|l|par|en|حبة|حبات|حبّة|حبّات|قطعة|قطع|حاجة|باكو)$/u';

    /** Western digits for Arabic-Indic ones, accents off, lower case. */
    private static function plain(string $s): string
    {
        $s = (string) preg_replace_callback('/[\x{0660}-\x{0669}]/u', static fn ($m) => (string) (mb_ord($m[0]) - 0x0660), $s);
        $s = (string) preg_replace_callback('/[\x{06F0}-\x{06F9}]/u', static fn ($m) => (string) (mb_ord($m[0]) - 0x06F0), $s);
        if (class_exists('Normalizer')) {
            $s = (string) Normalizer::normalize($s, Normalizer::FORM_D);
            $s = (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $s);
        } else {
            $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'É' => 'e']);
        }
        return mb_strtolower($s);
    }

    /** An Arabic word without "و", "ب", "ف" in front: "والثالثة" → "الثالثة". */
    private static function arabicStem(string $w): string
    {
        $w = (string) preg_replace('/^(?:و|ب|ف)(?=ال)/u', '', $w);
        return (string) preg_replace('/^(?:و)(?=[^ا])/u', '', $w);
    }

    private static function wordValue(string $w): ?int
    {
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $w)) {
            foreach ([$w, self::arabicStem($w)] as $cand) {
                foreach (self::AR_WORDS as [$re, $n]) {
                    if (preg_match($re, $cand)) {
                        return $n;
                    }
                }
            }
            return null;
        }
        return self::FR_WORDS[$w] ?? null;
    }

    private static function words(string $s): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $s) ?: [], static fn ($w) => $w !== ''));
    }

    /** How many units a name speaks of, or null when it names none. Prices, percentages and measures are not counts. */
    public static function unitsIn(string $label): ?int
    {
        $s = self::plain($label);
        $found = [];
        preg_match_all('/(\d+)\s*\+\s*(\d+)/u', $s, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $found[] = (int) $x[1] + (int) $x[2];
        }
        preg_match_all('/(?<![\d.,])(\d+)(?!\d)(?![.,]\d)\s*(%|dt\b|tnd\b|د\.?ت|دينار|dinars?\b|millimes?\b|ml\b|cl\b|l\b|kg\b|g\b|cm\b|mm\b|m\b|w\b|v\b|°|h\b|mois\b|ans?\b|jours?\b|min\b|ثواني|دقايق|ساعة|سوايع|شهر|عام|سنين|بالمية)?/u', $s, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $n = (int) $x[1];
            if (($x[2] ?? '') !== '' || $n < 1 || $n > 50) {
                continue;
            }
            $found[] = $n;
        }
        foreach (self::words($s) as $w) {
            if (preg_match('/^(\d+)(?:e|eme|er|re|ere)$/u', $w, $o)) {
                $found[] = (int) $o[1];
            }
            $v = self::wordValue($w);
            if ($v !== null) {
                $found[] = $v;
            }
        }
        return $found ? max($found) : null;
    }

    /** True when the name says nothing about the number of units, or says the offer's own. */
    public static function fits(?string $label, int $quantity): bool
    {
        if ($label === null || trim($label) === '') {
            return true;
        }
        $n = self::unitsIn($label);
        return $n === null || $n === $quantity;
    }

    /** True when the name says more than a number of units. */
    public static function adds(?string $label): bool
    {
        if ($label === null || trim($label) === '') {
            return false;
        }
        foreach (self::words(self::plain($label)) as $w) {
            if (!preg_match('/^\d+(?:e|eme|er|re|ere)?$/u', $w) && self::wordValue($w) === null && !preg_match(self::UNIT_WORDS, $w) && preg_match('/\p{L}/u', $w)) {
                return true;
            }
        }
        return false;
    }

    /** The offer's name in a language, kept only when it fits and adds something; otherwise null. */
    public static function note(array $o, ?string $locale = null): ?string
    {
        $own = ($locale ?? lang()) === 'ar' ? ($o['labelAr'] ?? null) : ($o['label'] ?? null);
        if (!$own || !self::fits($own, (int) $o['quantity']) || !self::adds($own)) {
            return null;
        }
        return trim($own);
    }

    /** The title of an offer: its quantity, in the shop's words. */
    public static function title(int $quantity, ?string $locale = null): string
    {
        $locale ??= lang();
        if ($locale === 'ar') {
            return $quantity === 1 ? 'حبة وحدة' : ($quantity === 2 ? 'زوز حبات' : ($quantity <= 10 ? "$quantity حبات" : "$quantity حبة"));
        }
        return $quantity . ' unité' . ($quantity > 1 ? 's' : '');
    }

    /** Title and note on one line, for the cart, the order and the messages. */
    public static function line(array $o, ?string $locale = null): string
    {
        $note = self::note($o, $locale);
        $title = self::title((int) $o['quantity'], $locale);
        return $note ? "$title · $note" : $title;
    }

    /** The first name of an offer that speaks of another quantity, for the editor's refusal. */
    public static function problem(array $o): ?string
    {
        foreach ([$o['label'] ?? null, $o['labelAr'] ?? null] as $l) {
            if ($l && !self::fits($l, (int) $o['quantity'])) {
                return sprintf('le nom « %s » annonce %d unités, mais la quantité est %d', trim($l), self::unitsIn($l), (int) $o['quantity']);
            }
        }
        return null;
    }
}
