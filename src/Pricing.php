<?php
declare(strict_types=1);

/**
 * The one place a basket becomes a price: a port of priceCart in the Next.js
 * shop (src/lib/pricing.ts), without promo codes. It is handed the catalog
 * rows the server just read, never anything the browser said about prices.
 * The browser sends ids and quantities; unit prices, stock, the delivery fee
 * and what an offer contains and costs are decided here, every time.
 */
final class Pricing
{
    public const MAX_QTY_PER_LINE = 10;
    public const MAX_LINES = 30;
    public const UNLIMITED = PHP_INT_MAX;

    /** How many of this product/option can be sold right now. UNLIMITED for supplier stock that is available. */
    public static function sellable(array $p, ?array $v): int
    {
        if (!$p['active']) {
            return 0;
        }
        if ($p['stockMode'] === 'SUPPLIER') {
            if (!$p['available'] || ($v !== null && !$v['available'])) {
                return 0;
            }
            return self::UNLIMITED;
        }
        return max(0, $v !== null ? (int) $v['stockQty'] : (int) $p['stockQty']);
    }

    /** Whether a card's product can be bought in at least one option. */
    public static function anySellable(array $p, array $variants): bool
    {
        if (!$variants) {
            return self::sellable($p, null) > 0;
        }
        foreach ($variants as $v) {
            if (self::sellable($p, $v) > 0) {
                return true;
            }
        }
        return false;
    }

    /** An offer can be bought while it is on and its end date (if any, UTC) has not passed. */
    public static function offerLive(array $o, string $nowUtc): bool
    {
        return $o['active'] && ($o['endsAt'] === null || (string) $o['endsAt'] > $nowUtc);
    }

    /**
     * Same product + option twice counts once, quantities added. An offer line
     * stands on its own and is taken once.
     */
    public static function mergeLines(array $lines): array
    {
        $map = [];
        foreach ($lines as $l) {
            $pid = $l['productId'] ?? null;
            $qty = $l['qty'] ?? null;
            if (!is_string($pid) || $pid === '' || !is_int($qty) || $qty <= 0) {
                continue;
            }
            $vid = is_string($l['variantId'] ?? null) && $l['variantId'] !== '' ? $l['variantId'] : null;
            $oid = is_string($l['offerId'] ?? null) && $l['offerId'] !== '' ? $l['offerId'] : null;
            $key = $pid . '|' . ($vid ?? '') . '|' . ($oid ?? '');
            if ($oid !== null) {
                $map[$key] ??= ['productId' => $pid, 'variantId' => $vid, 'offerId' => $oid, 'qty' => 1];
                continue;
            }
            $map[$key] = ['productId' => $pid, 'variantId' => $vid, 'offerId' => null, 'qty' => ($map[$key]['qty'] ?? 0) + $qty];
        }
        return array_slice(array_values($map), 0, self::MAX_LINES);
    }

    /**
     * @param array $lines    [['productId', 'variantId', 'offerId', 'qty'], ...]
     * @param array $catalog  Catalog::forPricing(...)
     * @param array $delivery Settings 'delivery' section
     */
    public static function quote(array $lines, array $catalog, ?string $governorate, array $delivery, ?string $nowUtc = null, ?array $promoRow = null, bool $phoneAlreadyUsedPromo = false): array
    {
        $nowUtc ??= Db::now();
        $problems = [];
        $priced = [];
        $costKnown = true;
        $costTotal = 0;
        // Units already taken by earlier lines of the same product and option: two lines never sell the same stock twice.
        $used = [];

        foreach (self::mergeLines($lines) as $l) {
            $p = $catalog[$l['productId']] ?? null;
            if (!$p || !$p['active']) {
                $problems[] = ['productId' => $l['productId'], 'variantId' => $l['variantId'], 'kind' => 'not_found'];
                continue;
            }
            $v = null;
            if ($p['variants']) {
                foreach ($p['variants'] as $x) {
                    if ($x['id'] === $l['variantId']) {
                        $v = $x;
                    }
                }
                if ($v === null) {
                    $problems[] = ['productId' => $p['id'], 'variantId' => $l['variantId'], 'kind' => 'variant_required'];
                    continue;
                }
            }
            $key = $p['id'] . '|' . ($v['id'] ?? '');
            $taken = $used[$key] ?? 0;
            $left = self::sellable($p, $v) === self::UNLIMITED ? self::UNLIMITED : self::sellable($p, $v) - $taken;

            if ($l['offerId'] !== null) {
                $offer = null;
                foreach ($p['offers'] as $o) {
                    if ($o['id'] === $l['offerId']) {
                        $offer = $o;
                    }
                }
                // Offers are set at the product's price: an option with its own price is not sold through them.
                $ownPrice = $v !== null && $v['price'] !== null && $v['price'] !== $p['price'];
                if (!$offer || !self::offerLive($offer, $nowUtc) || $offer['quantity'] < 1 || $ownPrice) {
                    $problems[] = ['productId' => $p['id'], 'variantId' => $v['id'] ?? null, 'offerId' => $l['offerId'], 'kind' => 'offer_unavailable'];
                    continue;
                }
                // An offer is all or nothing: never a smaller quantity at the offer's price.
                if ($left < $offer['quantity']) {
                    $problems[] = ['productId' => $p['id'], 'variantId' => $v['id'] ?? null, 'offerId' => $offer['id'], 'kind' => 'unavailable', 'available' => max(0, $left)];
                    continue;
                }
                $used[$key] = $taken + $offer['quantity'];
                if ($p['cost'] === null) {
                    $costKnown = false;
                } else {
                    $costTotal += $p['cost'] * $offer['quantity'];
                }
                $priced[] = self::line($p, $v, [
                    'unitPrice' => (int) round($offer['price'] / $offer['quantity']),
                    'qty' => $offer['quantity'],
                    'lineTotal' => $offer['price'],
                    'offerId' => $offer['id'],
                    'offerLabel' => OfferLabel::line($offer, 'fr'),
                    'offerLabelAr' => OfferLabel::line($offer, 'ar'),
                    'freeDelivery' => $offer['freeDelivery'],
                ]);
                continue;
            }

            $max = min(self::MAX_QTY_PER_LINE, $left);
            if ($max <= 0) {
                $problems[] = ['productId' => $p['id'], 'variantId' => $v['id'] ?? null, 'offerId' => null, 'kind' => 'unavailable'] + (array_key_exists($key, $used) ? ['available' => 0] : []);
                continue;
            }
            $qty = $l['qty'];
            if ($qty > $max) {
                $qty = $max;
                $problems[] = ['productId' => $p['id'], 'variantId' => $v['id'] ?? null, 'offerId' => null, 'kind' => 'qty_reduced', 'available' => $max];
            }
            $used[$key] = $taken + $qty;
            $unit = $v['price'] ?? $p['price'];
            if ($p['cost'] === null) {
                $costKnown = false;
            } else {
                $costTotal += $p['cost'] * $qty;
            }
            $priced[] = self::line($p, $v, ['unitPrice' => $unit, 'qty' => $qty, 'lineTotal' => $unit * $qty, 'offerId' => null, 'offerLabel' => null, 'offerLabelAr' => null, 'freeDelivery' => false]);
        }

        $subtotal = array_sum(array_column($priced, 'lineTotal'));
        $discount = 0;
        $promo = null;
        $promoFree = false;
        if ($promoRow) {
            $result = self::evaluatePromo($promoRow, $subtotal, $nowUtc, $phoneAlreadyUsedPromo);
            $promo = ['code' => $promoRow['code'], 'result' => $result];
            if ($result['ok']) {
                $discount = $result['discount'];
                $promoFree = $result['freeDelivery'];
            }
        }
        $afterDiscount = $subtotal - $discount;
        $zones = $delivery['zones'] ?? [];
        $zone = $governorate !== null && $governorate !== '' ? ($zones[$governorate] ?? null) : null;
        $deliveryUnavailable = $governorate !== null && $governorate !== '' && (!$zone || !$zone['enabled']);
        $threshold = $delivery['freeThreshold'] ?? null;
        $thresholdMet = $threshold !== null && $afterDiscount >= $threshold;
        $free = $promoFree || $thresholdMet || (bool) array_filter($priced, static fn ($x) => $x['freeDelivery']);

        // One fee everywhere: it is known before the governorate, so the total is the real one from the start.
        $fee = 0;
        if ($priced && !$deliveryUnavailable) {
            $fee = $free ? 0 : (int) ($delivery['fee'] ?? 0);
        }
        $deliveryInfo = null;
        if ($zone && $zone['enabled'] && $priced) {
            $deliveryInfo = ['minDays' => $zone['minDays'], 'maxDays' => max($zone['minDays'], $zone['maxDays']), 'fee' => $fee, 'free' => $free];
        }

        return [
            'lines' => $priced,
            'problems' => $problems,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'deliveryFee' => $fee,
            'total' => $afterDiscount + $fee,
            'delivery' => $deliveryInfo,
            'deliveryUnavailable' => $deliveryUnavailable,
            'freeThreshold' => $threshold,
            'freeDeliveryRemaining' => $threshold !== null && !$free && $priced ? max(0, $threshold - $afterDiscount) : null,
            'promo' => $promo,
            'costTotal' => $costKnown && $priced ? $costTotal : null,
        ];
    }

    /** promo.ts evaluatePromo: percent discounts rounded down to 100 millimes. $now: UTC "Y-m-d H:i:s". */
    public static function evaluatePromo(array $p, int $subtotal, string $now, bool $phoneAlreadyUsed): array
    {
        if (!Db::bool($p['active'])) {
            return ['ok' => false, 'reason' => 'inactive'];
        }
        if ($p['startsAt'] !== null && $now < substr((string) $p['startsAt'], 0, 19)) {
            return ['ok' => false, 'reason' => 'not_started'];
        }
        if ($p['endsAt'] !== null && $now > substr((string) $p['endsAt'], 0, 19)) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        if ($p['maxUses'] !== null && (int) $p['usedCount'] >= (int) $p['maxUses']) {
            return ['ok' => false, 'reason' => 'used_up'];
        }
        if ($subtotal < (int) $p['minSubtotal']) {
            return ['ok' => false, 'reason' => 'min_subtotal'];
        }
        if (Db::bool($p['oncePerPhone']) && $phoneAlreadyUsed) {
            return ['ok' => false, 'reason' => 'already_used'];
        }
        $value = (int) $p['value'];
        return match ($p['kind']) {
            'PERCENT' => ['ok' => true, 'discount' => intdiv(intdiv($subtotal * min(90, max(0, $value)), 100), 100) * 100, 'freeDelivery' => false],
            'FIXED' => ['ok' => true, 'discount' => min(max(0, $value), $subtotal), 'freeDelivery' => false],
            default => ['ok' => true, 'discount' => 0, 'freeDelivery' => true],
        };
    }

    public static function normalizeCode(string $code): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtoupper(trim($code)));
    }

    /**
     * quoteCart: a basket priced from the database, read fresh: the catalog rows, the delivery settings, the
     * code looked up by its text. The same function prices the page, the basket and the order itself.
     */
    public static function quoteCart(array $lines, ?string $governorate, ?string $promoCode, ?string $phone): array
    {
        $settings = Settings::all();
        $catalog = Catalog::forPricing(array_column($lines, 'productId'));
        $gov = $governorate !== null && Gov::valid($governorate) ? $governorate : null;
        $promo = null;
        $usedByPhone = false;
        $code = $promoCode ? self::normalizeCode($promoCode) : '';
        if ($code !== '') {
            $promo = Db::one('SELECT id, code, kind, value, "minSubtotal", "maxUses", "usedCount", "oncePerPhone", to_char("startsAt", \'YYYY-MM-DD HH24:MI:SS\') AS "startsAt", to_char("endsAt", \'YYYY-MM-DD HH24:MI:SS\') AS "endsAt", active FROM "PromoCode" WHERE code = ?', [$code]);
            if ($promo && Db::bool($promo['oncePerPhone']) && $phone) {
                $usedByPhone = (int) Db::value('SELECT count(*) FROM "Order" WHERE "promoCodeId" = ? AND phone = ? AND status <> \'CANCELLED\'', [$promo['id'], $phone]) > 0;
            }
        }
        $q = self::quote($lines, $catalog, $gov, $settings['delivery'], null, $promo, $usedByPhone);
        // An unknown code is reported like any refused one.
        if ($code !== '' && !$promo) {
            $q['promo'] = ['code' => $code, 'result' => ['ok' => false, 'reason' => 'inactive']];
        }
        return $q;
    }

    /** publicQuote: what the browser may see of a quote, no supplier costs. */
    public static function publicQuote(array $q): array
    {
        unset($q['costTotal']);
        $q['lines'] = array_map(static function ($l) {
            unset($l['unitCost']);
            return $l;
        }, $q['lines']);
        return $q;
    }

    private static function line(array $p, ?array $v, array $rest): array
    {
        return [
            'productId' => $p['id'],
            'variantId' => $v['id'] ?? null,
            'slug' => $p['slug'],
            'name' => $p['name'],
            'nameAr' => $p['nameAr'],
            'variantName' => $v['name'] ?? null,
            'variantNameAr' => $v['nameAr'] ?? null,
            'imageId' => $p['imageId'],
            'unitCost' => $p['cost'],
        ] + $rest;
    }
}
