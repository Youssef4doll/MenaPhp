<?php
declare(strict_types=1);

/**
 * The shop's search, a port of the Next.js shop's src/lib/search.ts (same
 * rules, same scores: tests/fixtures/next-search.json holds its answers).
 *
 * - Everything is compared in a plain form: lower case, no accents, Arabic
 *   letters unified (أ إ آ → ا, ة → ه, ى → ي, no tashkeel), Arabic digits as 0-9.
 * - A word matches exactly, as the start of a longer word (the customer is
 *   still typing), inside a word, or with a typing mistake: one wrong, missing,
 *   extra or swapped letter in a word of 4 or 5 letters, two from 6 letters.
 *
 * Strings are handled as lists of characters (mb_*), as JavaScript does for
 * the letters the shop uses.
 */
final class Search
{
    /** The plain form of a text, the one the search compares. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(['œ', 'æ'], ['oe', 'ae'], $s);
        if (class_exists('Normalizer')) {
            $s = (string) Normalizer::normalize($s, Normalizer::FORM_D);
        }
        // Latin accents and Arabic short vowels are combining marks.
        $s = (string) preg_replace('/[\x{0300}-\x{036F}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $s);
        $s = (string) preg_replace('/[أإآٱ]/u', 'ا', $s);
        $s = str_replace(['ة', 'ى', 'ؤ', 'ئ', 'ـ'], ['ه', 'ي', 'و', 'ي', ''], $s);
        $s = (string) preg_replace_callback('/[\x{0660}-\x{0669}]/u', static fn ($m) => (string) (mb_ord($m[0]) - 0x0660), $s);
        $s = (string) preg_replace_callback('/[\x{06F0}-\x{06F9}]/u', static fn ($m) => (string) (mb_ord($m[0]) - 0x06F0), $s);
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim($s);
    }

    /** Words of two letters or more; an Arabic word with "ال" also counts without it. */
    public static function tokenize(string $s): array
    {
        $out = [];
        $n = self::normalize($s);
        if ($n === '') {
            return [];
        }
        foreach (explode(' ', $n) as $w) {
            if (mb_strlen($w) < 2 && !preg_match('/\d/', $w)) {
                continue;
            }
            $out[] = $w;
            if (str_starts_with($w, 'ال') && mb_strlen($w) > 4) {
                $out[] = mb_substr($w, 2);
            }
        }
        return $out;
    }

    /** Edits between two words (a swap of two neighbours counts as one), stopping early past $max. */
    public static function distance(string $a, string $b, int $max = 2): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $la = count($a);
        $lb = count($b);
        if (abs($la - $lb) > $max) {
            return $max + 1;
        }
        $prev2 = array_fill(0, $lb + 1, 0);
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            $rowMin = $i;
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $v = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $v = min($v, $prev2[$j - 2] + 1);
                }
                $cur[] = $v;
                $rowMin = min($rowMin, $v);
            }
            if ($rowMin > $max) {
                return $max + 1;
            }
            $prev2 = $prev;
            $prev = $cur;
        }
        return $prev[$lb];
    }

    private static function arabic(string $w): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $w);
    }

    /** An Arabic word without its long "ا" after the first letter: "ماكياج" and "مكياج" are the same word. */
    private static function skeleton(string $w): string
    {
        if (!self::arabic($w) || $w === '') {
            return $w;
        }
        return mb_substr($w, 0, 1) . str_replace('ا', '', mb_substr($w, 1));
    }

    /** Words that name the same thing in French, Arabic and Tunisian written in Latin letters (plain forms). */
    private const SYNONYMS = [
        ['maquillage', 'makeup', 'مكياج'],
        ['cuisine', 'كوجينه', 'مطبخ', 'koujina'],
        ['lampe', 'لمبه', 'lamba'],
        ['chaise', 'كرسي', 'kursi', 'korsi'],
        ['table', 'طاوله', 'tawla'],
        ['sac', 'صاك', 'شنطه', 'sak'],
        ['montre', 'منقاله', 'mongela'],
        ['tapis', 'زربيه', 'zarbia'],
        ['miroir', 'مرايه', 'mraya'],
        ['parfum', 'عطر', 'riha'],
        ['verre', 'كاس', 'kess'],
        ['brosse', 'فرشه', 'فرشاه'],
    ];
    private static ?array $synonymOf = null;

    /** A field's words with the other names of the same things. */
    public static function withSynonyms(array $tokens): array
    {
        if (self::$synonymOf === null) {
            self::$synonymOf = [];
            foreach (self::SYNONYMS as $g) {
                foreach ($g as $w) {
                    self::$synonymOf[self::skeleton($w)] = $g;
                }
            }
        }
        $out = [];
        foreach ($tokens as $t) {
            $out[$t] = true;
        }
        foreach ($tokens as $t) {
            foreach (self::$synonymOf[self::skeleton($t)] ?? [] as $w) {
                $out[$w] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /** Typing mistakes forgiven for a word of this length. */
    private static function allowed(int $n): int
    {
        return $n <= 3 ? 0 : ($n <= 5 ? 1 : 2);
    }

    /** How well one typed word matches one word of the catalogue (0 = not at all). $last: the word being typed. */
    public static function matchWord(string $typed, string $word, bool $last): float
    {
        if ($typed === $word) {
            return 1.0;
        }
        $lt = mb_strlen($typed);
        $lw = mb_strlen($word);
        if (self::arabic($typed) && $lt >= 3 && self::skeleton($typed) === self::skeleton($word)) {
            return 0.97;
        }
        if ($lt >= 2 && str_starts_with($word, $typed)) {
            return $last ? 0.92 : 0.75;
        }
        if ($lt >= 3 && str_contains($word, $typed)) {
            return 0.6;
        }
        $max = $lt <= 3 ? 0 : self::allowed(max(4, min($lt, $lw)));
        if ($max === 0) {
            return 0.0;
        }
        if (self::arabic($typed) && min($lt, $lw) <= 3) {
            return 0.0;
        }
        $d = self::distance($typed, $word, $max);
        if ($d <= $max) {
            return 0.8 - 0.15 * $d;
        }
        if ($last && $lt >= 5 && $lw > $lt) {
            $dp = $max + 1;
            for ($n = $lt - 1; $n <= $lt + 1; $n++) {
                if ($n >= 2 && $n <= $lw) {
                    $dp = min($dp, self::distance($typed, mb_substr($word, 0, $n), $max));
                }
            }
            if ($dp <= $max) {
                return 0.7 - 0.15 * $dp;
            }
        }
        return 0.0;
    }

    /** "noire" and "noir", "verres" and "verre": the same word for a "did you mean". */
    private static function stem(string $w): string
    {
        return (string) preg_replace('/(es|e|s|x)$/u', '', $w);
    }

    /** @param array $doc ['id' => string, 'fields' => [['tokens' => [...], 'weight' => float], ...]] */
    private static function bestIn(string $typed, array $doc, bool $last): array
    {
        $best = ['score' => 0.0, 'word' => '', 'exact' => false];
        foreach ($doc['fields'] as $f) {
            foreach ($f['tokens'] as $w) {
                $w = (string) $w;
                $m = self::matchWord($typed, $w, $last) * $f['weight'];
                if ($m > $best['score']) {
                    $best = ['score' => $m, 'word' => $w, 'exact' => $typed === $w || str_starts_with($w, $typed) || self::stem($typed) === self::stem($w)];
                }
            }
        }
        return $best;
    }

    /** The unique words of a query, in order. */
    public static function words(string $q): array
    {
        return array_values(array_unique(self::tokenize($q)));
    }

    /**
     * The documents that match a query, best first: [['id', 'score', 'corrected' => [typed => found]], ...].
     * Every typed word must be found (three words or more may miss one, at a cost).
     */
    public static function rank(string $query, array $docs, bool $prefix = false, int $limit = 60): array
    {
        $words = self::words($query);
        if (!$words) {
            return [];
        }
        $out = [];
        $last = count($words) - 1;
        foreach ($docs as $order => $doc) {
            $total = 0.0;
            $missed = 0;
            $corrected = [];
            foreach ($words as $i => $w) {
                $m = self::bestIn($w, $doc, $prefix && $i === $last);
                if ($m['score'] == 0) {
                    $missed++;
                } else {
                    $total += $m['score'];
                    if (!$m['exact']) {
                        $corrected[$w] = $m['word'];
                    }
                }
            }
            if ($missed > (count($words) >= 3 ? 1 : 0)) {
                continue;
            }
            $out[] = ['id' => $doc['id'], 'score' => $total / count($words) - $missed * 0.3, 'corrected' => $corrected, 'order' => $order];
        }
        // JavaScript's sort is stable: equal scores keep the documents' order.
        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['order'] <=> $b['order']);
        return array_slice(array_map(static function ($r) {
            unset($r['order']);
            return $r;
        }, $out), 0, $limit);
    }

    /** Google's completions: phrases that start with what is typed, or match it, the typed part first. */
    public static function complete(string $query, array $phrases, int $limit = 4): array
    {
        $q = self::normalize($query);
        $words = self::tokenize($query);
        if (mb_strlen($q) < 2 || !$words) {
            return [];
        }
        $scored = [];
        $seen = [];
        foreach ($phrases as $order => $p) {
            $n = self::normalize((string) $p);
            if ($n === '' || isset($seen[$n]) || $n === $q) {
                continue;
            }
            $seen[$n] = true;
            $s = 0.0;
            if (str_starts_with($n, $q)) {
                $s = 2 + 1 / mb_strlen($n);
            } else {
                $r = self::rank($query, [['id' => $p, 'fields' => [['tokens' => self::tokenize((string) $p), 'weight' => 1]]]], true)[0] ?? null;
                if ($r) {
                    $s = $r['score'];
                }
            }
            if ($s >= 0.5) {
                $scored[] = ['p' => $p, 's' => $s, 'order' => $order];
            }
        }
        usort($scored, static fn ($a, $b) => $b['s'] <=> $a['s'] ?: $a['order'] <=> $b['order']);
        return array_map(static fn ($x) => $x['p'], array_slice($scored, 0, $limit));
    }
}
