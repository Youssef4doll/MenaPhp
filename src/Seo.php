<?php
declare(strict_types=1);

/**
 * What Google reads, as the Next.js shop's src/lib/seo.tsx writes it: titles
 * and descriptions cut at a word, and the structured data (store, search box,
 * product, list, breadcrumb). Only real facts: ratings only from approved reviews.
 */
final class Seo
{
    public const TITLE_MAX = 60;
    public const DESCRIPTION_MAX = 155;

    /** Cut at a word, never in the middle of one. */
    public static function clip(string $text, int $max): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($t) <= $max) {
            return $t;
        }
        $cut = mb_substr($t, 0, $max - 1);
        $at = mb_strrpos($cut, ' ');
        $cut = $at !== false && $at > $max * 0.6 ? mb_substr($cut, 0, $at) : $cut;
        return preg_replace('/[\s,;:.\-–—]+$/u', '', $cut) . '…';
    }

    private static function base(): string
    {
        return rtrim((string) config('site_url', ''), '/');
    }

    private static function everywhereFacts(bool $everywhere): string
    {
        return lang() === 'ar'
            ? 'الدفع عند الاستلام، ' . ($everywhere ? 'توصيل لكل الولايات' : 'توصيل حتى لدارك') . '.'
            : 'Paiement à la livraison, ' . ($everywhere ? 'livraison dans toute la Tunisie' : 'livraison à domicile') . '.';
    }

    /** productTitle: the product's own words, then its category; the shop's own title wins. */
    public static function productTitle(array $p): string
    {
        $own = Ui::pick($p['seoTitle'] ?? '', $p['seoTitleAr'] ?? null);
        if (trim($own) !== '') {
            return self::clip($own, self::TITLE_MAX);
        }
        $name = Ui::pick($p['name'], $p['nameAr']);
        $cat = $p['category'] ? Ui::pick($p['category']['name'], $p['category']['nameAr']) : '';
        return self::clip($cat !== '' && mb_strlen($name) + mb_strlen($cat) + 3 <= self::TITLE_MAX - 7 ? "$name — $cat" : $name, self::TITLE_MAX);
    }

    /** productDescription: the name, the short description, the real price, how it is paid and delivered. */
    public static function productDescription(array $p, bool $everywhere): string
    {
        $own = Ui::pick($p['seoDescription'] ?? '', $p['seoDescriptionAr'] ?? null);
        if (trim($own) !== '') {
            return self::clip($own, self::DESCRIPTION_MAX + 5);
        }
        $name = Ui::pick($p['name'], $p['nameAr']);
        $first = explode("\n", Ui::pick($p['description'], $p['descriptionAr']))[0] ?? '';
        $about = Ui::pick($p['summary'], $p['summaryAr']) ?: $first;
        $lead = Rules::leadOffer($p['offers']);
        $price = Ui::money($lead ? (int) $lead['price'] : min($p['price'], ...array_map(static fn ($v) => (int) $v['price'], $p['variants'] ?: [['price' => $p['price']]])));
        $facts = "$price. " . self::everywhereFacts($everywhere);
        $head = $about !== '' && !str_starts_with(mb_strtolower($about), mb_strtolower($name)) ? $name . (lang() === 'ar' ? ': ' : ' : ') . $about : ($about !== '' ? $about : $name);
        $room = self::DESCRIPTION_MAX - mb_strlen($facts) - 1;
        return self::clip($head, max(40, $room)) . ' ' . $facts;
    }

    /** listDescription: what is in a list (its first product names) and how it is sold. */
    public static function listDescription(string $title, array $products, array $s): string
    {
        $facts = self::everywhereFacts(Gov::everywhere($s['delivery']['zones']));
        $names = implode(lang() === 'ar' ? '، ' : ', ', array_map(static fn ($p) => Ui::pick($p['name'], $p['nameAr']), array_slice($products, 0, 4)));
        $head = $names !== '' ? (lang() === 'ar' ? "$title: $names" : "$title : $names") : $title;
        return str_replace('….', '…', self::clip($head, self::DESCRIPTION_MAX - mb_strlen($facts) - 2) . '. ' . $facts);
    }

    public static function website(array $s): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $s['shop']['name'],
            'url' => self::base() . url('/'),
            'inLanguage' => lang() === 'ar' ? 'ar-TN' : 'fr-TN',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => self::base() . url('/recherche') . '?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    public static function itemList(array $products): array
    {
        $items = [];
        foreach (array_slice($products, 0, 30) as $i => $p) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => self::base() . url('/produit/' . $p['slug']), 'name' => Ui::pick($p['name'], $p['nameAr'])];
        }
        return ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items];
    }

    public static function organization(array $s): array
    {
        $shop = $s['shop'];
        $ld = ['@context' => 'https://schema.org', '@type' => 'OnlineStore', 'name' => $shop['name'], 'url' => self::base(), 'logo' => self::base() . '/icon.svg'];
        if ($shop['phone']) {
            $ld['telephone'] = $shop['phone'];
        }
        if ($shop['email']) {
            $ld['email'] = $shop['email'];
        }
        $ld['sameAs'] = array_values(array_filter(array_map(static fn ($k) => profile_url($k, (string) ($shop[$k] ?? '')), ['instagram', 'facebook', 'tiktok'])));
        if ($shop['legalName']) {
            $ld['legalName'] = $shop['legalName'];
        }
        if ($shop['legalId']) {
            $ld['taxID'] = $shop['legalId'];
        }
        if ($shop['address']) {
            $ld['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $shop['address'], 'addressCountry' => 'TN'];
        }
        return $ld + ['areaServed' => 'TN', 'currenciesAccepted' => 'TND', 'paymentAccepted' => 'Cash'];
    }

    /** productLd: in the page's language; ratings only from approved reviews; delivery as the shop sets it. */
    public static function product(array $p, array $s, string $pageUrl): array
    {
        $zones = array_filter($s['delivery']['zones'], static fn ($z) => $z['enabled']);
        $minDays = $zones ? min(array_column($zones, 'minDays')) : null;
        $maxDays = $zones ? max(array_map(static fn ($z) => max($z['minDays'], $z['maxDays']), $zones)) : null;
        $lead = Rules::leadOffer($p['offers']);
        $price = $lead ? (int) $lead['price'] : min($p['price'], ...array_map(static fn ($v) => (int) $v['price'], $p['variants'] ?: [['price' => $p['price']]]));
        $d = $s['delivery'];
        $minFee = $zones ? (!empty($lead['freeDelivery']) || $d['fee'] === 0 || ($d['freeThreshold'] !== null && $price >= $d['freeThreshold']) ? 0 : $d['fee']) : null;
        $description = implode("\n\n", array_filter([Ui::pick($p['summary'], $p['summaryAr']), Ui::pick($p['description'], $p['descriptionAr'])]));
        $offer = ['@type' => 'Offer', 'url' => $pageUrl, 'priceCurrency' => 'TND', 'price' => number_format($price / 1000, 3, '.', '')];
        if ($lead && $lead['quantity'] > 1) {
            $offer['eligibleQuantity'] = ['@type' => 'QuantitativeValue', 'value' => (int) $lead['quantity']];
        }
        $offer['availability'] = $p['stock']['kind'] !== 'out' ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        $offer['itemCondition'] = 'https://schema.org/NewCondition';
        if ($minFee !== null) {
            $ship = ['@type' => 'OfferShippingDetails', 'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => number_format($minFee / 1000, 3, '.', ''), 'currency' => 'TND'], 'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'TN']];
            if ($minDays !== null && $maxDays !== null) {
                $ship['deliveryTime'] = ['@type' => 'ShippingDeliveryTime', 'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY'], 'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => $minDays, 'maxValue' => $maxDays, 'unitCode' => 'DAY']];
            }
            $offer['shippingDetails'] = $ship;
        }
        $offer['hasMerchantReturnPolicy'] = ['@type' => 'MerchantReturnPolicy', 'applicableCountry' => 'TN', 'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow', 'merchantReturnDays' => $s['returns']['days']];
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => Ui::pick($p['name'], $p['nameAr']),
            'description' => mb_substr($description, 0, 1000),
            'image' => array_map(static fn ($i) => self::base() . '/api/img/' . $i['id'] . '/1080', $p['images']),
            'sku' => $p['id'],
            'url' => $pageUrl,
        ];
        if ($p['category']) {
            $ld['category'] = Ui::pick($p['category']['name'], $p['category']['nameAr']);
        }
        $ld['offers'] = $offer;
        if ($p['rating']) {
            $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => number_format($p['rating']['avg'], 1, '.', ''), 'reviewCount' => $p['rating']['count']];
            $ld['review'] = array_map(static fn ($r) => ['@type' => 'Review', 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $r['rating']], 'author' => ['@type' => 'Person', 'name' => $r['authorName']]] + ($r['text'] ? ['reviewBody' => $r['text']] : []) + ['datePublished' => substr((string) $r['createdAt'], 0, 10)], array_slice($p['reviews'], 0, 5));
        }
        return $ld;
    }

    public static function breadcrumb(array $items): array
    {
        $list = [];
        foreach ($items as $i => $it) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $it['name'], 'item' => $it['url']];
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }
}
