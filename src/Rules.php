<?php
declare(strict_types=1);

/**
 * The Next.js shop's display rules, ported one function at a time (same
 * names, same results; tests/fixtures/next-rules.json holds its answers):
 * src/lib/offer-display.ts, src/lib/product-content.ts, src/lib/home-feed.ts
 * and the card rules of src/lib/catalog.ts.
 */
final class Rules
{
    // ------------------------------------------------------------ offer-display.ts

    /** Offers are set at the product's price: a product whose options carry their own prices is sold option by option. */
    public static function offersApply(int $price, array $variants): bool
    {
        foreach ($variants as $v) {
            if ($v['price'] !== null && (int) $v['price'] !== $price) {
                return false;
            }
        }
        return true;
    }

    /** The offer a list shows first: one unit when the shop sells one, otherwise the smallest pack. */
    public static function leadOffer(array $offers): ?array
    {
        foreach ($offers as $o) {
            if ((int) $o['quantity'] === 1) {
                return $o;
            }
        }
        if (!$offers) {
            return null;
        }
        $sorted = $offers;
        usort($sorted, static fn ($a, $b) => $a['quantity'] <=> $b['quantity'] ?: $a['price'] <=> $b['price']);
        return $sorted[0];
    }

    /** "Instead of": for a pack, the same units at the one-unit offer; for one unit, the former price. */
    public static function offerCompareAt(array $offers, array $o, ?int $formerPrice = null): ?int
    {
        if ((int) $o['quantity'] <= 1) {
            return $formerPrice !== null && $formerPrice > (int) $o['price'] ? $formerPrice : null;
        }
        $one = null;
        foreach ($offers as $x) {
            if ((int) $x['quantity'] === 1) {
                $one = $x;
                break;
            }
        }
        if (!$one) {
            return null;
        }
        $separately = (int) $one['price'] * (int) $o['quantity'];
        return $separately > (int) $o['price'] ? $separately : null;
    }

    /** The saving in whole percent, rounded down. */
    public static function discountPct(int $price, ?int $compareAt): int
    {
        return $compareAt !== null && $compareAt > $price ? intdiv(($compareAt - $price) * 100, $compareAt) : 0;
    }

    /** The last day an offer can be ordered, in Tunis ("YYYY-MM-DD"). $endsAt: UTC "Y-m-d H:i:s". */
    public static function offerLastDay(string $endsAt): string
    {
        $t = (new DateTimeImmutable($endsAt, new DateTimeZone('UTC')))->modify('-1 millisecond');
        return $t->setTimezone(new DateTimeZone('Africa/Tunis'))->format('Y-m-d');
    }

    /** "15 octobre" / "15 أكتوبر" from "2026-10-15". */
    public static function formatDay(string $day, string $locale): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $day));
        $fr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        // Tunisian month names, as the browser writes them for ar-TN.
        $ar = ['جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان', 'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        return str_starts_with($locale, 'ar') ? $d . ' ' . $ar[$m - 1] : $d . ' ' . $fr[$m - 1];
    }

    // ---------------------------------------------------------- product-content.ts

    /** "Matière : inox" → ['label' => 'Matière', 'value' => 'inox']; a line without a colon is a plain point. */
    public static function parseSpecs(?string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $text) ?: [] as $line) {
            $line = (string) preg_replace('/^[-•*·]\s*/u', '', trim($line));
            if ($line === '') {
                continue;
            }
            if (count($out) >= 40) {
                break;
            }
            if (!preg_match('/\s*[:：]\s*/u', $line, $m, PREG_OFFSET_CAPTURE) || $m[0][1] <= 0) {
                $out[] = ['label' => '', 'value' => $line];
                continue;
            }
            $i = $m[0][1];
            $label = trim(substr($line, 0, $i));
            $value = trim((string) preg_replace('/^\s*[:：]\s*/u', '', substr($line, $i)));
            $out[] = $value !== '' ? ['label' => $label, 'value' => $value] : ['label' => '', 'value' => $label];
        }
        return $out;
    }

    /** A product video: YouTube, Vimeo, TikTok, Instagram, Facebook, or a direct https file / stream. Anything else: null. */
    public static function parseVideo(?string $url): ?array
    {
        $raw = trim((string) $url);
        if ($raw === '') {
            return null;
        }
        $u = parse_url($raw);
        if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) {
            return null;
        }
        $host = (string) preg_replace('/^(www|m|web)\./', '', strtolower($u['host']));
        $path = $u['path'] ?? '/';
        $parts = array_values(array_filter(explode('/', $path), static fn ($x) => $x !== ''));
        parse_str($u['query'] ?? '', $q);
        $id = static fn ($s) => is_string($s) && preg_match('/^[A-Za-z0-9_-]{11}$/', $s) ? $s : null;
        $digits = static fn ($s) => is_string($s) && preg_match('/^\d{5,25}$/', $s) ? $s : null;
        $after = static function (string $word) use ($parts) {
            $i = array_search($word, $parts, true);
            return $i !== false ? ($parts[$i + 1] ?? null) : null;
        };
        if ($host === 'youtu.be') {
            $v = $id($parts[0] ?? null);
            return $v ? ['kind' => 'youtube', 'id' => $v] : null;
        }
        if ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
            $v = ($parts[0] ?? '') === 'watch' ? $id($q['v'] ?? null) : (in_array($parts[0] ?? '', ['shorts', 'embed', 'live'], true) ? $id($parts[1] ?? null) : null);
            return $v ? ['kind' => 'youtube', 'id' => $v] : null;
        }
        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            $cand = ($parts[0] ?? '') === 'video' ? ($parts[1] ?? null) : null;
            if ($cand === null) {
                foreach ($parts as $x) {
                    if (preg_match('/^\d+$/', $x)) {
                        $cand = $x;
                        break;
                    }
                }
            }
            $v = $digits($cand);
            return $v ? ['kind' => 'embed', 'provider' => 'vimeo', 'src' => "https://player.vimeo.com/video/$v?autoplay=1&playsinline=1&title=0&byline=0", 'vertical' => false] : null;
        }
        if ($host === 'tiktok.com') {
            $v = $digits(($parts[0] ?? '') === 'player' ? ($parts[2] ?? null) : $after('video'));
            return $v ? ['kind' => 'embed', 'provider' => 'tiktok', 'src' => "https://www.tiktok.com/player/v1/$v?autoplay=1&music_info=0&description=0&rel=0", 'vertical' => true] : null;
        }
        if ($host === 'instagram.com') {
            $at = -1;
            foreach ($parts as $i => $x) {
                if (in_array($x, ['reel', 'reels', 'p', 'tv'], true)) {
                    $at = $i;
                    break;
                }
            }
            $code = $at >= 0 && preg_match('/^[A-Za-z0-9_-]{5,40}$/', $parts[$at + 1] ?? '') ? $parts[$at + 1] : null;
            $kind = $at >= 0 && $parts[$at] === 'p' ? 'p' : 'reel';
            return $code ? ['kind' => 'embed', 'provider' => 'instagram', 'src' => "https://www.instagram.com/$kind/$code/embed", 'vertical' => true] : null;
        }
        if ($host === 'facebook.com') {
            $p0 = $parts[0] ?? '';
            $v = $digits($p0 === 'reel' ? ($parts[1] ?? null) : ($p0 === 'watch' ? ($q['v'] ?? null) : $after('videos')));
            if (!$v) {
                return null;
            }
            $page = $p0 === 'reel' ? "https://www.facebook.com/reel/$v" : "https://www.facebook.com/watch/?v=$v";
            return ['kind' => 'embed', 'provider' => 'facebook', 'src' => 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($page) . '&show_text=false&autoplay=true', 'vertical' => $p0 === 'reel'];
        }
        if (preg_match('/\.(mp4|webm|mov)$/i', $path)) {
            return ['kind' => 'file', 'src' => $raw];
        }
        if (preg_match('/\.m3u8$/i', $path)) {
            return ['kind' => 'hls', 'src' => $raw];
        }
        return null;
    }

    private const SOLD_STEPS = [10, 20, 30, 50, 100, 200, 300, 500, 1000, 2000, 5000];

    /** Orders in the last 30 days, rounded down to a step ("+20"); under 10, nothing is said. */
    public static function soldStep(int $orders): ?int
    {
        $step = null;
        foreach (self::SOLD_STEPS as $s) {
            if ($orders >= $s) {
                $step = $s;
            }
        }
        return $step;
    }

    // ------------------------------------------------------------- catalog.ts cards

    /** What the page may say about stock: in_stock, low (with qty) or out. */
    public static function stockState(array $p): array
    {
        $variants = $p['variants'] ?? [];
        if ($p['stockMode'] === 'SUPPLIER') {
            if (!$p['available']) {
                return ['kind' => 'out'];
            }
            if ($variants && !array_filter($variants, static fn ($v) => $v['available'])) {
                return ['kind' => 'out'];
            }
            return ['kind' => 'in_stock'];
        }
        $qty = $variants ? array_sum(array_map(static fn ($v) => max(0, (int) $v['stockQty']), $variants)) : (int) $p['stockQty'];
        if ($qty <= 0) {
            return ['kind' => 'out'];
        }
        if ($qty <= 5) {
            return ['kind' => 'low', 'qty' => $qty];
        }
        return ['kind' => 'in_stock'];
    }

    /** The offers a card may speak of: on, not ended, and not bigger than counted stock. $offers are the active ones. */
    private static function liveOffers(array $p, string $nowUtc): array
    {
        if (!self::offersApply((int) $p['price'], $p['variants'])) {
            return [];
        }
        $cap = PHP_INT_MAX;
        if (($p['stockMode'] ?? 'SUPPLIER') === 'TRACKED') {
            $cap = $p['variants'] ? max(0, ...array_map(static fn ($v) => (int) ($v['stockQty'] ?? 0), $p['variants'])) : (int) ($p['stockQty'] ?? 0);
        }
        return array_values(array_filter($p['offers'], static fn ($o) => ($o['endsAt'] === null || (string) $o['endsAt'] > $nowUtc) && (int) $o['quantity'] <= $cap));
    }

    /** The price a list may show: what the product page will charge (the lead offer, or the lowest option, "from"). */
    public static function cardPrice(array $p, ?string $nowUtc = null): array
    {
        $nowUtc ??= Db::now();
        $live = self::liveOffers($p, $nowUtc);
        $lead = self::leadOffer($live);
        if ($lead) {
            return ['price' => (int) $lead['price'], 'priceFrom' => false, 'packOf' => (int) $lead['quantity'] > 1 ? (int) $lead['quantity'] : null, 'compareAt' => self::offerCompareAt($live, $lead, $p['compareAt'] === null ? null : (int) $p['compareAt'])];
        }
        $prices = array_map(static fn ($v) => $v['price'] ?? (int) $p['price'], $p['variants']);
        $min = $prices ? min($prices) : (int) $p['price'];
        $from = false;
        foreach ($prices as $x) {
            if ($x !== $min) {
                $from = true;
            }
        }
        return ['price' => $min, 'priceFrom' => $from, 'packOf' => null, 'compareAt' => $p['compareAt'] !== null && (int) $p['compareAt'] > $min ? (int) $p['compareAt'] : null];
    }

    /** What a card may add: delivery included in the price shown, and the best pack (lowest price per unit). */
    public static function cardDeal(array $p, ?string $nowUtc = null): array
    {
        $nowUtc ??= Db::now();
        $live = self::liveOffers($p, $nowUtc);
        $lead = self::leadOffer($live);
        if (!$lead) {
            return ['freeDelivery' => false, 'deal' => null];
        }
        $leadUnit = $lead['price'] / $lead['quantity'];
        $cands = array_values(array_filter($live, static fn ($o) => $o['quantity'] > $lead['quantity'] && $o['price'] / $o['quantity'] < $leadUnit));
        usort($cands, static fn ($a, $b) => $a['price'] / $a['quantity'] <=> $b['price'] / $b['quantity']);
        $best = $cands[0] ?? null;
        return [
            'freeDelivery' => (bool) ($lead['freeDelivery'] ?? false),
            'deal' => $best ? ['label' => $best['label'] ?? '', 'labelAr' => $best['labelAr'] ?? null, 'quantity' => (int) $best['quantity'], 'price' => (int) $best['price'], 'freeDelivery' => (bool) ($best['freeDelivery'] ?? false)] : null,
        ];
    }

    /** Buyable first, the others after them, each group in its order. */
    public static function outLast(array $list): array
    {
        $in = array_values(array_filter($list, static fn ($c) => $c['stock']['kind'] !== 'out'));
        $out = array_values(array_filter($list, static fn ($c) => $c['stock']['kind'] === 'out'));
        return [...$in, ...$out];
    }

    // ------------------------------------------------------------------ home-feed.ts

    /** A real saving the card shows: a struck price, or a pack cheaper per unit. */
    public static function saving(array $c): float
    {
        $struck = $c['compareAt'] && $c['compareAt'] > $c['price'] ? ($c['compareAt'] - $c['price']) / $c['compareAt'] : 0;
        $lead = $c['packOf'] ? $c['price'] / $c['packOf'] : $c['price'];
        $pack = $c['deal'] ? 1 - $c['deal']['price'] / $c['deal']['quantity'] / $lead : 0;
        return (float) max(0, $struck, $pack);
    }

    /** $i: card, category, featured, createdAt (ms), orders, views, photos, details. $now in ms. */
    public static function score(array $i, float $now): float
    {
        $age = max(0, ($now - $i['createdAt']) / 86400000);
        return 3 * log1p($i['orders'])
            + 40 * (($i['orders'] + 0.2) / ($i['views'] + 20))
            + 0.6 * log1p($i['views'])
            + 2 * max(0, 1 - $age / 30)
            + ($i['featured'] ? 1.5 : 0)
            + min(1.2, 3 * self::saving($i['card']))
            + ($i['photos'] >= 3 ? 0.5 : 0)
            + ($i['details'] > 0 ? 0.5 : 0);
    }

    /** Best first, but never more than two of one category in a row while another category still has products. */
    public static function diversify(array $ranked): array
    {
        $left = array_values($ranked);
        $out = [];
        while ($left) {
            $n = count($out);
            $a = $n >= 1 ? $out[$n - 1]['category'] : null;
            $b = $n >= 2 ? $out[$n - 2]['category'] : null;
            $blocked = $a !== null && $a === $b ? $a : null;
            $i = 0;
            if ($blocked !== null) {
                $i = -1;
                foreach ($left as $k => $x) {
                    if ($x['category'] !== $blocked) {
                        $i = $k;
                        break;
                    }
                }
            }
            $out[] = array_splice($left, $i < 0 ? 0 : $i, 1)[0];
        }
        return $out;
    }

    /** The whole feed: buyable products with a photo, ranked, spread. */
    public static function rankFeed(array $items, float $now): array
    {
        $buyable = array_values(array_filter($items, static fn ($i) => $i['card']['stock']['kind'] !== 'out' && count($i['card']['images']) > 0));
        $scored = array_map(static fn ($i) => ['i' => $i, 's' => self::score($i, $now)], $buyable);
        usort($scored, static fn ($a, $b) => $b['s'] <=> $a['s'] ?: $b['i']['createdAt'] <=> $a['i']['createdAt']);
        return self::diversify(array_map(static fn ($x) => $x['i'], $scored));
    }
}
