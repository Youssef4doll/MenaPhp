<?php
declare(strict_types=1);

/**
 * The product page as the Next.js shop's server writes it (ProductView,
 * Gallery, BuyBox, OrderForm, VariantSelect: src/components/shop/*.tsx), in
 * its first state: nothing chosen yet, an empty form. The same components,
 * compiled into public/assets/islands, then take the page over in the
 * browser (they hydrate it: the markup must be theirs, text separators
 * included, or they draw it again).
 */
final class ProductUi
{
    /** React's separator between two pieces of text side by side. */
    private const SEP = '<!-- -->';

    private static function j(string ...$parts): string
    {
        return implode(self::SEP, array_map('e', $parts));
    }

    private const PHOTO_SIZES = '(min-width: 1024px) 560px, (min-width: 768px) 50vw, 100vw';
    private const DETAIL_SIZES = '(min-width: 1024px) 500px, (min-width: 768px) 540px, 100vw';

    // --------------------------------------------------------------- the choice

    /** useBuyChoice's first state (no address parameters read yet: the browser does that). */
    public static function choice(array $p): array
    {
        $only = count($p['variants']) === 1 && !$p['variants'][0]['soldOut'] ? $p['variants'][0] : null;
        $variant = $only;
        $known = $variant ? $variant['maxUnits'] : $p['maxUnits'];
        $cap = $known;
        $fits = static fn ($o) => $cap === null || $o['quantity'] <= $cap;
        $usable = array_values(array_filter($p['offers'], $fits));
        $maxQty = max(1, min(10, $cap ?? 10));
        return ['variant' => $variant, 'offer' => $usable[0] ?? null, 'hasOffers' => (bool) $usable, 'fits' => $fits, 'qty' => min(1, $maxQty), 'maxQty' => $maxQty];
    }

    /** shownPrice: one unit (or the smallest pack) when there are offers, else the option, else the lowest "from". */
    private static function shownPrice(array $p, array $c): array
    {
        $lead = Rules::leadOffer(array_values(array_filter($p['offers'], $c['fits'])));
        if ($lead) {
            return ['price' => $lead['price'], 'compareAt' => $lead['compareAt'], 'note' => $lead['quantity'] > 1 ? t('product.pack', $lead['quantity']) : null];
        }
        if ($c['variant']) {
            return ['price' => $c['variant']['price'], 'compareAt' => $c['variant']['price'] === $p['price'] ? $p['compareAt'] : null, 'note' => null];
        }
        $prices = array_column($p['variants'], 'price');
        $min = $prices ? min($prices) : $p['price'];
        $from = false;
        foreach ($prices as $x) {
            $from = $from || $x !== $min;
        }
        return ['price' => $min, 'compareAt' => $min === $p['price'] ? $p['compareAt'] : null, 'note' => $from ? t('product.from') : null];
    }

    /** payNow: what the current choice costs before delivery. */
    private static function payNow(array $p, array $c): array
    {
        if ($c['offer']) {
            return ['price' => $c['offer']['price'], 'from' => false];
        }
        if ($c['variant']) {
            return ['price' => $c['variant']['price'] * $c['qty'], 'from' => false];
        }
        $prices = array_column($p['variants'], 'price');
        $min = $prices ? min($prices) : $p['price'];
        $from = false;
        foreach ($prices as $x) {
            $from = $from || $x !== $min;
        }
        return ['price' => $min * $c['qty'], 'from' => $from];
    }

    // -------------------------------------------------------------- ProductView

    public static function view(array $p, array $settings, string $url): string
    {
        $l = lang();
        $name = Ui::pick($p['name'], $p['nameAr']);
        $summary = Ui::pick($p['summary'], $p['summaryAr']);
        $out = $p['stock']['kind'] === 'out';
        $choice = self::choice($p);
        $shown = self::shownPrice($p, $choice);
        $pct = $out ? 0 : Rules::discountPct($shown['price'], $shown['compareAt']);
        $struck = !$out && $shown['compareAt'] !== null && $shown['compareAt'] > $shown['price'] ? $shown['compareAt'] : null;
        $pay = self::payNow($p, $choice);
        $details = $p['detailImages'];
        $hasDetails = (bool) $details;
        $description = Ui::pick($p['description'], $p['descriptionAr']);
        $specs = $l === 'ar' && $p['specsAr'] ? $p['specsAr'] : $p['specs'];
        $free = (bool) ($choice['offer']['freeDelivery'] ?? false);

        $h = '<div class="mx-auto max-w-6xl px-4 pb-28 md:pb-16">';
        $h .= '<div class="' . ($hasDetails ? 'max-md:flex max-md:flex-col max-md:gap-4 max-md:pt-1' : 'contents') . '">';
        $h .= '<div class="grid gap-4 pt-1 md:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] md:grid-rows-[auto_1fr] md:gap-x-10 md:gap-y-5 md:pt-4 ' . ($hasDetails ? 'max-md:contents' : '') . '">';
        $h .= '<div class="min-w-0 max-md:order-1 md:sticky md:top-36 md:col-start-1 md:row-span-2 md:row-start-1 md:self-start">' . self::gallery($p, $name, $hasDetails ? count($details) : 0) . '</div>';

        $h .= '<div class="flex min-w-0 flex-col gap-2 max-md:order-2 md:col-start-2 md:row-start-1">';
        $h .= '<h1 class="text-[24px] font-bold leading-snug text-ink md:text-[30px] md:leading-tight">' . Ui::bidi($name) . '</h1>';
        if ($summary !== '') {
            $h .= '<p class="text-[16px] leading-relaxed text-ink-2" dir="auto" data-testid="summary">' . Ui::bidi($summary) . '</p>';
        }
        if ($p['rating'] || $p['sold']) {
            $h .= '<div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[15px]">';
            if ($p['rating']) {
                $h .= '<a href="#avis" class="-my-1.5 flex min-h-11 items-center gap-1.5 text-sea"><span class="font-bold text-ink ltr-nums">' . number_format($p['rating']['avg'], 1, '.', '') . '</span>' . Ui::stars($p['rating']['avg'], 17) . '<span class="underline-offset-2 hover:underline ltr-nums">' . self::j('(', (string) $p['rating']['count'], ')') . '</span></a>';
            }
            if ($p['sold']) {
                $h .= '<span class="text-muted"><span class="font-bold text-ink-2 ltr-nums">' . self::j('+', (string) $p['sold']) . '</span> ' . e(t('product.soldSuffix')) . '</span>';
            }
            $h .= '</div>';
        }
        $h .= '<p class="flex flex-wrap items-center gap-x-2.5 gap-y-1 pt-1" data-testid="price">';
        if ($shown['note']) {
            $h .= '<span class="text-[15px] text-muted">' . e($shown['note']) . '</span>';
        }
        $h .= '<span class="whitespace-nowrap text-[28px] font-bold leading-none text-ink price-nums ltr-nums">' . e(Ui::money($shown['price'])) . '</span>';
        if ($struck !== null) {
            $h .= '<span class="sr-only">' . e(t('buy.instead', Ui::money($struck))) . '</span><s aria-hidden="true" class="whitespace-nowrap text-[17px] text-muted price-nums ltr-nums">' . e(Ui::money($struck)) . '</s>';
        }
        if ($pct > 0) {
            $h .= '<span class="rounded-full bg-deal px-2.5 text-[14px] font-bold leading-7 text-white"><span class="ltr-nums">' . self::j('−', (string) $pct, '%') . '</span></span>';
        }
        $h .= '</p>' . self::stockLine($p, $choice['variant']) . '</div>';

        $h .= '<div class="min-w-0 pt-1 max-md:order-4 md:col-start-2 md:row-start-2">' . self::buyBox($p, $settings, $url, $choice, 'bb', 'product') . '</div>';
        $h .= '</div>';

        if ($description !== '' || $hasDetails) {
            $h .= '<section id="details" class="mx-auto mt-10 flex max-w-5xl flex-col gap-4 border-t border-line pt-8 ' . ($hasDetails ? 'max-md:contents' : '') . '" aria-labelledby="desc-title">';
            $titleCls = $hasDetails ? ($description !== '' ? 'max-md:order-5 max-md:mt-6 max-md:border-t max-md:border-line max-md:pt-8' : 'max-md:hidden') : '';
            $h .= '<h2 id="desc-title" tabindex="-1" class="scroll-mt-4 text-[20px] font-bold text-ink ' . $titleCls . '">'
                . ($hasDetails ? '<span class="md:hidden">' . e(t('product.description')) . '</span><span class="max-md:hidden">' . e(t('product.detailsTitle')) . '</span>' : e(t('product.description'))) . '</h2>';
            $h .= '<div class="' . ($hasDetails ? 'contents lg:grid lg:grid-cols-[500px_minmax(0,1fr)] lg:gap-12' : 'contents') . '">';
            if ($hasDetails) {
                $h .= '<div class="relative -mx-4 flex flex-col overflow-hidden max-md:order-3 md:mx-auto md:w-full md:max-w-[540px] md:rounded-2xl lg:mx-0 lg:max-w-none lg:self-start" data-testid="long-pictures">';
                foreach ($details as $img) {
                    $h .= Ui::nextImage($img, (string) ($img['alt'] ?? ''), self::DETAIL_SIZES, false, 'block h-auto w-full', false);
                }
                $h .= '<span aria-hidden="true" class="pointer-events-none absolute inset-x-0 bottom-0 h-px"></span></div>';
            }
            if ($description !== '' || ($hasDetails && !$out)) {
                $h .= '<div class="flex min-w-0 flex-col gap-6 max-lg:contents">';
                if ($description !== '') {
                    $h .= '<div class="flex max-w-3xl flex-col gap-3 text-[17px] leading-relaxed text-ink-2 ' . ($hasDetails ? 'max-md:order-5 md:mx-auto md:w-full md:max-w-[540px] lg:mx-0 lg:max-w-none' : '') . '">';
                    foreach (Rules::descriptionBlocks($description) as $b) {
                        if ($b['kind'] === 'text') {
                            $h .= '<p class="whitespace-pre-line" dir="auto">' . Ui::bidi($b['text']) . '</p>';
                        } else {
                            $h .= '<ul class="flex flex-col gap-2.5">';
                            foreach ($b['items'] as $item) {
                                $h .= '<li class="flex items-start gap-2.5" dir="auto">' . icon('Check', 'mt-1 h-5 w-5 shrink-0 text-olive') . '<span class="min-w-0">' . Ui::bidi($item) . '</span></li>';
                            }
                            $h .= '</ul>';
                        }
                    }
                    $h .= '</div>';
                }
                if ($hasDetails && !$out) {
                    $h .= self::sideOrder($settings, $name, $pay['price'], $pay['from'], $free);
                }
                $h .= '</div>';
            }
            $h .= '</div></section>';
        }
        $h .= '</div>';

        if ($p['reviews']) {
            $h .= '<section id="avis" class="mx-auto mt-10 max-w-3xl scroll-mt-4 border-t border-line pt-8" aria-labelledby="reviews-title"><h2 id="reviews-title" class="scroll-mt-4 text-[20px] font-bold text-ink ">' . e(t('product.reviews')) . '</h2>';
            if ($p['rating']) {
                $h .= '<p class="mt-3 flex items-center gap-2 text-[17px]">' . Ui::stars($p['rating']['avg'], 22) . '<span class="font-bold ltr-nums">' . e(t('rating', number_format($p['rating']['avg'], 1, '.', ''), $p['rating']['count'])) . '</span></p>';
            }
            $h .= '<ul class="flex flex-col divide-y divide-line">';
            foreach ($p['reviews'] as $r) {
                $h .= '<li class="py-4"><div class="flex items-center justify-between gap-2">' . Ui::stars((float) $r['rating'], 18) . '<span class="text-[14px] text-muted">' . e(self::monthYear($r['createdAt'])) . '</span></div>';
                if ($r['text']) {
                    $h .= '<p class="mt-2 text-[17px] leading-relaxed text-ink-2" dir="auto">' . e($r['text']) . '</p>';
                }
                $h .= '<p class="mt-2 flex flex-wrap items-center gap-1.5 text-[14px] text-muted"><span class="font-bold text-ink">' . e($r['authorName']) . '</span>';
                if ($r['governorate']) {
                    $h .= '<span>' . self::j('· ', Gov::label($r['governorate'])) . '</span>';
                }
                $h .= '<span class="inline-flex items-center gap-1 text-olive">· ' . icon('Check', 'h-3.5 w-3.5') . ' ' . e(t('product.verified')) . '</span></p></li>';
            }
            $h .= '</ul></section>';
        }

        $h .= self::questions($settings, $p['offers']);
        if (!$out) {
            $h .= self::endOrder($settings, $pay['price'], $pay['from'], $free);
        }
        if ($specs) {
            $h .= '<section class="mx-auto mt-10 max-w-3xl border-t border-line pt-8" aria-labelledby="specs-title"><h2 id="specs-title" class="scroll-mt-4 text-[20px] font-bold text-ink ">' . e(t('product.specs')) . '</h2><dl class="mt-1 divide-y divide-line text-[17px]">';
            foreach ($specs as $sp) {
                if ($sp['label'] !== '') {
                    $h .= '<div class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3 py-3"><dt class="text-muted" dir="auto">' . Ui::bidi($sp['label']) . '</dt><dd class="text-ink" dir="auto">' . Ui::bidi($sp['value']) . '</dd></div>';
                } else {
                    $h .= '<div class="flex items-start gap-2 py-3"><dt class="sr-only">·</dt><dd class="flex items-start gap-2 text-ink" dir="auto">' . icon('Check', 'mt-1 h-4 w-4 shrink-0 text-olive') . Ui::bidi($sp['value']) . '</dd></div>';
                }
            }
            $h .= '</dl></section>';
        }
        // The bar at the bottom of a phone: shown by the page's code once the form is out of sight.
        $h .= '<div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white/95 px-4 pb-[max(.625rem,env(safe-area-inset-bottom))] pt-2.5 shadow-[0_-6px_20px_-12px_rgb(0_0_0/.25)] backdrop-blur transition-transform duration-300 md:hidden translate-y-full" aria-hidden="true" data-testid="sticky-bar">'
            . '<div class="flex items-center gap-3"><p class="flex shrink-0 flex-col leading-tight"><span class="text-[14px] text-muted">' . e($pay['from'] ? t('product.from') : t('buy.price')) . '</span><span class="whitespace-nowrap text-[19px] font-bold text-ink price-nums ltr-nums">' . e(Ui::money($pay['price'])) . '</span></p>'
            . '<button type="button" tabindex="-1" class="flex h-[54px] min-w-0 flex-1 items-center justify-center gap-2 rounded-full bg-buy px-4 text-[18px] font-bold text-night shadow-[inset_0_-3px_0_var(--color-buy-2)]">' . icon('Truck', 'h-6 w-6 shrink-0') . e(t('buy.sticky')) . '</button></div></div>';
        return $h . '</div>';
    }

    /** "octobre 2026" / "أكتوبر 2026", as toLocaleDateString writes it (fr-FR, ar-TN). */
    private static function monthYear(string $iso): string
    {
        $d = new DateTimeImmutable($iso);
        $m = (int) $d->format('n');
        $fr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $ar = ['جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان', 'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        return lang() === 'ar' ? $ar[$m - 1] . ' ' . $d->format('Y') : $fr[$m - 1] . ' ' . $d->format('Y');
    }

    /** StockLine: said only when it matters, a few left or none. */
    private static function stockLine(array $p, ?array $variant): string
    {
        $stock = $variant ? ($variant['soldOut'] ? ['kind' => 'out'] : ($variant['lowQty'] ? ['kind' => 'low', 'qty' => $variant['lowQty']] : ['kind' => 'in_stock'])) : $p['stock'];
        if ($stock['kind'] === 'out') {
            return '<p class="flex items-center gap-2 text-[16px] text-muted"><span class="h-2.5 w-2.5 rounded-full bg-muted"></span>' . e(t('product.out')) . '</p>';
        }
        if ($stock['kind'] === 'low') {
            return '<p class="flex items-center gap-2 text-[16px] font-bold text-clay"><span class="h-2.5 w-2.5 rounded-full bg-clay"></span>' . e(t('product.low', $stock['qty'])) . '</p>';
        }
        return '';
    }

    private static function deliveryLine(array $settings, bool $free, int $price): string
    {
        if (!array_filter($settings['delivery']['zones'], static fn ($z) => $z['enabled'])) {
            return '';
        }
        $fee = (int) $settings['delivery']['fee'];
        $th = $settings['delivery']['freeThreshold'];
        $isFree = $free || $fee === 0 || ($th !== null && $price >= $th);
        return '<p class="flex items-start gap-2 text-[16px] text-ink-2" data-testid="delivery-line">' . icon('Truck', 'mt-0.5 h-5 w-5 shrink-0 text-sea') . '<span>'
            . ($isFree ? '<strong class="text-olive">' . e(t('buy.freeDelivery')) . '</strong>' : e(t('buy.delivery')) . self::SEP . ' <span class="price-nums ltr-nums">' . e(Ui::money($fee)) . '</span>') . '</span></p>';
    }

    private static function endOrder(array $settings, int $price, bool $from, bool $free): string
    {
        return '<div class="mx-auto mt-10 flex max-w-3xl flex-col gap-3 rounded-[24px] bg-sand/70 p-4 md:p-5" data-testid="end-order"><p class="flex items-baseline justify-between gap-3"><span class="text-[16px] text-ink-2">' . e($from ? t('product.from') : t('buy.price')) . '</span><span class="whitespace-nowrap text-[24px] font-bold leading-none text-ink price-nums ltr-nums">' . e(Ui::money($price)) . '</span></p>'
            . self::deliveryLine($settings, $free, $price)
            . '<button type="button" aria-haspopup="dialog" class="flex min-h-[58px] w-full items-center justify-center gap-2.5 rounded-full bg-buy px-5 py-2 text-[19px] font-bold leading-tight text-night shadow-[inset_0_-3px_0_var(--color-buy-2)] transition [--focus-ring:var(--color-night)] hover:bg-buy-2 active:scale-[.99]">' . icon('Truck', 'h-6 w-6 shrink-0') . e(t('product.orderNow')) . '</button></div>';
    }

    private static function sideOrder(array $settings, string $name, int $price, bool $from, bool $free): string
    {
        return '<div class="sticky top-40 flex flex-col gap-3 rounded-[24px] border border-line bg-white p-5 shadow-[0_10px_30px_-20px_rgb(0_0_0/.4)] max-lg:hidden" data-testid="side-order"><p class="line-clamp-2 text-[17px] font-bold leading-snug text-ink">' . e($name) . '</p><p class="flex items-baseline justify-between gap-3"><span class="text-[16px] text-ink-2">' . e($from ? t('product.from') : t('buy.price')) . '</span><span class="whitespace-nowrap text-[26px] font-bold leading-none text-ink price-nums ltr-nums">' . e(Ui::money($price)) . '</span></p>'
            . self::deliveryLine($settings, $free, $price)
            . '<p class="flex items-start gap-2 text-[16px] text-ink-2">' . icon('Cash', 'mt-0.5 h-5 w-5 shrink-0 text-sea') . e(t('trust.cod')) . '</p>'
            . '<button type="button" aria-haspopup="dialog" class="mt-1 flex min-h-[54px] w-full items-center justify-center gap-2.5 rounded-full bg-buy px-5 py-2 text-[18px] font-bold leading-tight text-night shadow-[inset_0_-3px_0_var(--color-buy-2)] transition [--focus-ring:var(--color-night)] hover:bg-buy-2 active:scale-[.99]">' . icon('Truck', 'h-6 w-6 shrink-0') . e(t('product.orderNow')) . '</button></div>';
    }

    /** Questions: answered from the shop's own settings only. Closed until tapped. */
    private static function questions(array $settings, array $offers): string
    {
        $d = $settings['delivery'];
        $on = array_filter($d['zones'], static fn ($z) => $z['enabled']);
        $span = $on ? ['minDays' => min(array_column($on, 'minDays')), 'maxDays' => max(array_map(static fn ($z) => max($z['minDays'], $z['maxDays']), $on))] : null;
        $fee = (int) $d['fee'];
        $freeOffers = $offers && !array_filter($offers, static fn ($o) => !$o['freeDelivery']) ? 'all' : (array_filter($offers, static fn ($o) => $o['freeDelivery']) ? 'some' : 'none');
        $allFree = $fee === 0 || $freeOffers === 'all';
        $items = [];
        if ($span) {
            $items[] = [t('product.faq.whenQ'), e(t('product.faq.whenA', Settings::rangeLabel($span['minDays'], $span['maxDays'], lang()), $allFree ? t('buy.freeDelivery') : t('buy.delivery') . ' ' . Ui::money($fee), $d['freeThreshold'] !== null && !$allFree ? Ui::money((int) $d['freeThreshold']) : null, !$allFree && $freeOffers === 'some'))];
        }
        $items[] = [t('product.faq.payQ'), e($d['openBeforePaying'] ? t('product.faq.payA') . ' ' . t('product.faq.openA') : t('product.faq.payA'))];
        $items[] = [t('product.faq.callQ'), e(t('product.faq.callA'))];
        $items[] = [t('product.faq.returnQ'), e(t('product.faq.returnA', $settings['returns']['days'])) . self::SEP . ' <a class="text-sea underline underline-offset-2" href="' . e(url('/livraison-retours')) . '">' . e(t('product.faq.more')) . '</a>'];
        $h = '<section class="mx-auto mt-10 max-w-3xl border-t border-line pt-8" aria-labelledby="faq-title"><h2 id="faq-title" class="scroll-mt-4 text-[20px] font-bold text-ink ">' . e(t('product.faq.title')) . '</h2><div class="mt-2 divide-y divide-line">';
        foreach ($items as [$q, $a]) {
            $h .= '<details class="group"><summary class="flex min-h-[56px] cursor-pointer list-none items-center justify-between gap-3 py-3 text-[17px] font-bold text-ink [&amp;::-webkit-details-marker]:hidden">' . e($q) . icon('ChevronDown', 'h-5 w-5 shrink-0 text-ink-2 transition group-open:rotate-180') . '</summary><p class="pb-4 text-[17px] leading-relaxed text-ink-2">' . $a . '</p></details>';
        }
        return $h . '</div></section>';
    }

    // ------------------------------------------------------------------ Gallery

    private static function gallery(array $p, string $name, int $detailCount): string
    {
        $slides = array_map(static fn ($i) => ['kind' => 'image', 'image' => $i], $p['images']);
        if ($p['video']) {
            array_splice($slides, min(1, count($slides)), 0, [['kind' => 'video', 'video' => $p['video'], 'poster' => $p['images'][0] ?? null]]);
        }
        $link = $detailCount > 0
            ? '<button type="button" class="absolute bottom-3 start-3 flex min-h-11 max-w-[calc(100%-4.75rem)] items-center gap-1 rounded-[22px] border border-line bg-white/95 py-1 ps-3 pe-2.5 text-start text-[16px] font-bold leading-tight text-ink shadow-[0_2px_10px_-4px_rgb(0_0_0/.3)] " data-testid="details-link"><span>' . e(t('product.detailsLink')) . self::SEP . "\u{00A0}" . '<span class="ltr-nums">' . self::j('(', (string) $detailCount, ')') . '</span></span>' . icon('ArrowDown', 'h-4 w-4 shrink-0 text-sea') . '</button>'
            : '';
        if (!$slides) {
            return '<div class="relative aspect-square overflow-hidden rounded-2xl border border-line">' . Ui::placeholder() . $link . '</div>';
        }
        $photos = array_values(array_filter($slides, static fn ($s) => $s['kind'] === 'image'));
        $nPhotos = count($photos);
        $photoNo = 0;
        $h = '<div class="flex flex-col gap-2.5 md:flex-row md:items-start md:gap-3"><div class="relative min-w-0 md:flex-1">';
        $h .= '<div class="flex aspect-square snap-x snap-mandatory overflow-x-auto overscroll-x-contain rounded-2xl border border-line bg-white no-scrollbar" role="group" aria-roledescription="carousel" aria-label="' . e($name) . '">';
        $thumbs = '';
        foreach ($slides as $n => $s) {
            if ($s['kind'] === 'image') {
                $photoNo++;
                $label = t('product.photo', $photoNo, $nPhotos);
                $h .= '<div class="relative h-full w-full shrink-0 snap-center snap-always" aria-label="' . e($label) . '" role="group" aria-roledescription="slide"><button type="button" class="block h-full w-full cursor-zoom-in [--focus-offset:-3px]" aria-label="' . e(t('product.zoom')) . '">'
                    . Ui::nextImage($s['image'], $s['image']['alt'] ?? ($n === 0 ? $name : ''), self::PHOTO_SIZES, $n === 0, 'object-contain', true, !empty($s['image']['blur'])) . '</button></div>';
                $thumbs .= '<button type="button" class="relative h-14 w-14 shrink-0 overflow-hidden rounded-xl border bg-white transition md:h-[72px] md:w-[72px] ' . ($n === 0 ? 'border-sea ring-1 ring-sea' : 'border-line hover:border-sea/50') . '" aria-label="' . e($label) . '" aria-current="' . ($n === 0 ? 'true' : 'false') . '">' . Ui::nextImage($s['image'], '', '72px', false, 'object-cover', true, false) . '</button>';
            } else {
                $h .= '<div class="relative h-full w-full shrink-0 snap-center snap-always" aria-label="' . e(t('product.video')) . '" role="group" aria-roledescription="slide"><button type="button" class="group relative block h-full w-full overflow-hidden bg-night [--focus-offset:-3px]" aria-label="' . e(t('product.playVideo') . ' — ' . $name) . '">'
                    . ($s['poster'] ? Ui::nextImage($s['poster'], '', '(min-width: 768px) 50vw, 100vw', false, 'scale-105 object-cover opacity-55 blur-[2px]', true, false) : '')
                    . '<span class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-white"><span class="flex h-20 w-20 items-center justify-center rounded-full bg-white/95 text-night shadow-lift transition group-active:scale-95">' . icon('Play', 'ms-1 h-10 w-10') . '</span><span class="rounded-full bg-night/70 px-4 py-1.5 text-[17px] font-bold">' . e(t('product.playVideo')) . '</span></span></button></div>';
                $thumbs .= '<button type="button" class="relative h-14 w-14 shrink-0 overflow-hidden rounded-xl border bg-white transition md:h-[72px] md:w-[72px] ' . ($n === 0 ? 'border-sea ring-1 ring-sea' : 'border-line hover:border-sea/50') . '" aria-label="' . e(t('product.playVideo')) . '" aria-current="' . ($n === 0 ? 'true' : 'false') . '">'
                    . ($s['poster'] ? Ui::nextImage($s['poster'], '', '72px', false, 'object-cover', true, false) : '')
                    . '<span class="absolute inset-0 flex items-center justify-center text-white ' . ($s['poster'] ? 'bg-night/40' : 'bg-night') . '">' . icon('Play', 'h-6 w-6') . '</span></button>';
            }
        }
        $h .= '</div>';
        if ($slides[0]['kind'] === 'image') {
            $h .= '<button type="button" class="absolute bottom-3 end-3 flex h-11 w-11 items-center justify-center rounded-full border border-line bg-white/95 text-ink-2" aria-label="' . e(t('product.zoom')) . '">' . icon('Expand', 'h-5 w-5') . '</button>';
        }
        $h .= $link . '</div>';
        if (count($slides) > 1) {
            $h .= '<div class="overflow-x-auto no-scrollbar md:order-first md:max-h-[560px] md:overflow-y-auto md:overflow-x-visible"><div class="mx-auto flex w-max gap-2 p-0.5 md:mx-0 md:w-auto md:flex-col">' . $thumbs . '</div></div>';
        }
        return $h . '</div>';
    }

    // ------------------------------------------------------------------- BuyBox

    public static function buyBox(array $p, array $settings, string $url, array $choice, string $idp, string $origin): string
    {
        $id = static fn (string $k) => "$idp-$k";
        $variant = $choice['variant'];
        $offer = $choice['offer'];
        $qty = $choice['qty'];
        $unit = $variant['price'] ?? $p['price'];
        $out = $p['stock']['kind'] === 'out' || ($variant['soldOut'] ?? false);
        $needsVariant = $p['variants'] && !$variant;
        $pageTotal = $offer ? $offer['price'] : $unit * $qty;
        $priceVaries = false;
        if ($needsVariant) {
            foreach ($p['variants'] as $v) {
                $priceVaries = $priceVaries || $v['price'] !== $p['variants'][0]['price'];
            }
        }
        $imageId = $p['images'][0]['id'] ?? null;

        $h = '<div id="' . ($idp === 'bb' ? 'commander' : $id('box')) . '" class="flex scroll-mt-4 flex-col gap-4">';
        if ($p['offers'] && !$out) {
            $h .= '<fieldset class="flex min-w-0 flex-col gap-2.5"><legend class="sr-only">' . e(t('buy.step1')) . '</legend>';
            foreach ($p['offers'] as $o) {
                $h .= self::offerCard($o, "$idp-offer", $imageId, ($offer['id'] ?? null) === $o['id'], !($choice['fits'])($o));
            }
            $h .= '</fieldset>';
        }
        if ($p['variants']) {
            $h .= '<div id="' . $id('variants') . '" class="flex scroll-mt-24 flex-col gap-2" role="group" aria-labelledby="' . $id('variants-label') . '"><p id="' . $id('variants-label') . '" class="ps-1 text-[16px] text-ink-2" aria-live="polite">' . e(t('product.choose')) . '</p>';
            if ($offer && $offer['quantity'] > 1) {
                $h .= '<p class="-mt-1 ps-1 text-[15px] text-muted">' . e(t('buy.sameOption', $offer['quantity'])) . '</p>';
            }
            $h .= self::variantSelect($id('variant'), $p, $variant, $choice['hasOffers']) . '</div>';
        }
        if (!$choice['hasOffers'] && !$out) {
            $h .= '<div class="flex items-center justify-between gap-3"><span class="ps-1 text-[16px] text-ink-2" id="' . $id('qty-label') . '">' . e(t('buy.quantity')) . '</span><div class="inline-flex h-12 items-center rounded-full border border-field bg-white px-1" role="group" aria-labelledby="' . $id('qty-label') . '">'
                . '<button type="button" class="flex h-12 w-12 items-center justify-center disabled:opacity-30"' . ($qty <= 1 ? ' disabled=""' : '') . ' aria-label="' . e(t('buy.qtyLess')) . '">' . icon('Minus', 'h-5 w-5') . '</button>'
                . '<span class="w-8 text-center text-[18px] font-bold ltr-nums" aria-live="polite">' . $qty . '</span>'
                . '<button type="button" class="flex h-12 w-12 items-center justify-center disabled:opacity-30"' . ($qty >= $choice['maxQty'] ? ' disabled=""' : '') . ' aria-label="' . e(t('buy.qtyMore')) . '">' . icon('Plus', 'h-5 w-5') . '</button></div></div>';
        }
        if ($out) {
            $wa = (string) $settings['contact']['whatsapp'];
            $h .= '<div class="flex flex-col gap-3 rounded-2xl bg-sand px-4 py-4"><p class="text-[16px] text-ink-2">' . e(t('product.outSub')) . '</p><div class="flex flex-wrap gap-2">';
            if ($wa !== '') {
                $h .= '<a href="https://wa.me/216' . e($wa) . '?text=' . rawurlencode(t('product.outAsk') . ': ' . Ui::pick($p['name'], $p['nameAr']) . ' ' . $url) . '" target="_blank" rel="noopener" class="inline-flex min-h-12 items-center gap-2 rounded-full bg-wa px-5 text-[16px] font-bold text-white">' . icon('WhatsApp', 'h-5 w-5') . e(t('product.outAsk')) . '</a>';
            }
            $h .= '<a href="#related-title" class="inline-flex min-h-12 items-center rounded-full border border-ink/60 bg-white px-5 text-[16px] font-bold text-ink hover:bg-sand">' . e(t('product.outOthers')) . '</a></div></div>';
        } else {
            $optionLabel = implode(' — ', array_filter([$variant ? Ui::pick($variant['name'], $variant['nameAr']) : null, $offer ? Ui::pick($offer['label'], $offer['labelAr']) : null])) ?: null;
            $h .= self::orderForm($settings, [
                'idp' => $idp,
                'formId' => $idp === 'bb' ? 'commander-form' : $id('form'),
                'pagePrice' => $pageTotal,
                'priceLabel' => t('buy.price'),
                'priceVaries' => $priceVaries,
                'freeOffer' => (bool) ($offer['freeDelivery'] ?? false),
                'origin' => $origin,
                'waText' => t('product.whatsappText', Ui::pick($p['name'], $p['nameAr']), $optionLabel, Ui::money($pageTotal), $url),
                'addToCart' => true,
                'submitTitle' => t('product.buyNow'),
            ]);
        }
        return $h . '</div>';
    }

    private static function offerCard(array $o, string $name, ?string $imageId, bool $checked, bool $disabled): string
    {
        $pct = $o['quantity'] > 1 ? Rules::discountPct($o['price'], $o['compareAt']) : 0;
        $struck = $o['compareAt'] !== null && $o['compareAt'] > $o['price'] ? $o['compareAt'] : null;
        $note = OfferLabel::note($o, lang());
        $cls = $disabled ? 'cursor-not-allowed border-dashed border-line bg-sand/50 opacity-70' : ($checked ? 'cursor-pointer border-sea bg-sea-soft/60 ring-1 ring-sea' : 'cursor-pointer border-sand-2 bg-white hover:border-sea/60');
        $h = '<label class="relative flex items-center gap-3 rounded-2xl border p-2.5 transition ' . $cls . '"><span class="relative h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-sand">';
        if ($imageId) {
            $h .= '<img src="/api/img/' . e($imageId) . '/200" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async">';
        }
        $h .= '<span aria-hidden="true" class="absolute bottom-1 end-1 rounded-full bg-sea px-1.5 text-[13px] font-bold leading-5 text-white"><span class="ltr-nums">' . self::j('×', (string) $o['quantity']) . '</span></span></span>';
        $h .= '<span class="flex min-w-0 flex-1 flex-col gap-0.5"><span class="flex flex-wrap items-center gap-x-2 gap-y-1"><span class="text-[16px] leading-snug text-ink">' . e(OfferLabel::title($o['quantity'], lang())) . '</span>';
        if ($pct > 0 && !$disabled) {
            $h .= '<span class="rounded-full bg-deal px-2 text-[13px] font-bold leading-6 text-white"><span class="ltr-nums">' . self::j('−', (string) $pct, '%') . '</span></span>';
        }
        if ($o['mostChosen'] && !$disabled) {
            $h .= '<span class="rounded-full border border-sea bg-white px-2 text-[14px] font-bold leading-6 text-sea-2">' . e(t('buy.mostChosen')) . '</span>';
        }
        $h .= '</span>';
        if ($note) {
            $h .= '<span class="text-[14px] leading-snug text-ink-2" dir="auto">' . Ui::bidi($note) . '</span>';
        }
        $h .= '<span class="flex flex-wrap items-baseline gap-x-2"><span class="whitespace-nowrap text-[18px] font-bold text-ink price-nums ltr-nums">' . e(Ui::money($o['price'])) . '</span>';
        if ($struck !== null && !$disabled) {
            $h .= '<span class="sr-only">' . e(t('buy.instead', Ui::money($struck))) . '</span><span aria-hidden="true" class="whitespace-nowrap text-[14px] text-muted line-through price-nums ltr-nums">' . e(Ui::money($struck)) . '</span>';
        }
        $h .= '</span>';
        if ($disabled) {
            $h .= '<span class="text-[14px] text-muted">' . e(t('buy.packTooBig')) . '</span>';
        } elseif ($o['freeDelivery'] || $o['lastDay']) {
            $h .= '<span class="flex flex-wrap items-center gap-x-2 text-[14px]">';
            if ($o['freeDelivery']) {
                $h .= '<span class="inline-flex items-center gap-1 font-bold text-olive">' . icon('Truck', 'h-4 w-4') . e(t('buy.freeDelivery')) . '</span>';
            }
            if ($o['lastDay']) {
                $h .= '<span class="text-muted">' . e(t('buy.until', Rules::formatDay($o['lastDay'], lang() === 'ar' ? 'ar-TN' : 'fr-FR'))) . '</span>';
            }
            $h .= '</span>';
        }
        return $h . '</span><input type="radio" class="h-[22px] w-[22px] shrink-0 accent-[var(--color-sea)]" data-testid="offer-' . $o['quantity'] . '" name="' . e($name) . '"' . ($checked ? ' checked=""' : '') . ($disabled ? ' disabled=""' : '') . '></label>';
    }

    /** VariantSelect, closed: the button that shows the choice. */
    private static function variantSelect(string $id, array $p, ?array $chosen, bool $hasOffers): string
    {
        $label = $chosen ? Ui::pick($chosen['name'], $chosen['nameAr']) : null;
        $price = $chosen && !$hasOffers && $chosen['price'] !== $p['price'] ? $chosen['price'] : null;
        $h = '<div class="relative"><button id="' . $id . '" type="button" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="' . substr($id, 0, -strlen('variant')) . 'variants-label ' . $id . '" class="flex min-h-[52px] w-full items-center gap-3 rounded-2xl border bg-white px-4 text-start text-[16px] transition border-field hover:border-sea/60" data-testid="variant-select">';
        if ($chosen && $chosen['colorHex']) {
            $h .= self::dot($chosen['colorHex']);
        }
        $h .= '<span class="min-w-0 flex-1 truncate ' . ($chosen ? 'font-bold text-ink' : 'text-muted') . '">' . e($label ?? t('product.chooseFirst')) . '</span>';
        if ($price !== null) {
            $h .= '<span class="shrink-0 text-[15px] text-ink-2 price-nums ltr-nums">' . e(Ui::money($price)) . '</span>';
        }
        return $h . icon('ChevronDown', 'h-5 w-5 shrink-0 text-ink-2 transition ') . '</button></div>';
    }

    private static function dot(string $hex, bool $faded = false): string
    {
        return '<span aria-hidden="true" class="h-6 w-6 shrink-0 rounded-full ring-1 ring-inset ' . (Rules::isPale($hex) ? 'ring-ink/30' : 'ring-black/10') . ' ' . ($faded ? 'opacity-40' : '') . '" style="background:' . e($hex) . '"></span>';
    }

    // ---------------------------------------------------------------- OrderForm

    private const CARD = 'rounded-[24px] border border-line bg-white p-4 shadow-[0_10px_30px_-22px_rgb(15_23_42/.45)]';
    private const WIDE = '@2xs:col-span-2 @lg:col-span-1';
    private const INPUT = 'h-12 w-full min-w-0 rounded-2xl border ps-3.5 text-[16px] text-ink outline-none transition placeholder:text-muted focus:bg-white focus:ring-2 border-field bg-white  focus:border-sea focus:ring-sea/20';

    private static function field(string $id, string $label, string $class, string $inner): string
    {
        return '<div class="flex min-w-0 flex-col gap-1 ' . $class . '"><label for="' . $id . '" class="ps-1 text-[14px] font-semibold text-ink">' . e($label) . '<span class="ms-1 text-danger" aria-hidden="true">*</span><span class="sr-only"> ' . self::j('(', t('buy.required'), ')') . '</span></label>' . $inner . '</div>';
    }

    /** OrderForm, as the server draws it: empty fields, the page's own price, no answer from the server yet. */
    public static function orderForm(array $settings, array $o): string
    {
        $idp = $o['idp'];
        $id = static fn (string $k) => "$idp-$k";
        $d = $settings['delivery'];
        $price = (int) $o['pagePrice'];
        $fee = (int) $d['fee'];
        $th = $d['freeThreshold'];
        $freeKnown = $o['freeOffer'] || ($th !== null && $price >= $th) || $fee === 0;
        $deliveryShown = $freeKnown ? 0 : $fee;
        $total = $price + $deliveryShown;
        $origin = $o['origin'];
        $sectionCls = $origin === 'quick' || $origin === 'sheet' ? 'border-t border-line pt-5' : ($origin === 'cart' ? self::CARD . ' md:rounded-none md:border-0 md:p-0 md:shadow-none' : self::CARD . ' @md:p-5');

        $h = '<form id="' . e($o['formId']) . '" novalidate="" class="@container flex flex-col gap-4">';
        $h .= '<input type="text" tabindex="-1" autocomplete="off" class="absolute -start-[9999px] h-0 w-0 opacity-0" aria-hidden="true" name="website">';
        $h .= '<section class="' . $sectionCls . '" aria-labelledby="' . $id('info-title') . '"><h3 id="' . $id('info-title') . '" class="text-[17px] font-bold leading-tight text-ink">' . e(t('buy.deliveryInfo')) . '</h3>';
        $h .= '<fieldset class="mt-3 grid min-w-0 items-start gap-x-2.5 gap-y-3 @2xs:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]"><legend class="sr-only">' . e(t('buy.step2')) . '</legend>';
        $h .= self::field($id('name'), t('buy.name'), self::WIDE, '<div class="relative"><input id="' . $id('name') . '" enterkeyhint="next" autocomplete="name" autocapitalize="words" autocorrect="off" spellcheck="false" class="' . self::INPUT . ' pe-3.5" aria-required="true" aria-invalid="false" data-testid="name" value=""></div>');
        $h .= self::field($id('phone'), t('buy.phone'), '', '<div class="h-12 w-full min-w-0 rounded-2xl border flex items-stretch overflow-hidden transition focus-within:bg-white focus-within:ring-2 border-field bg-white  focus-within:border-sea focus-within:ring-sea/20" dir="ltr"><span class="flex items-center border-e border-line ps-2.5 pe-2 text-[14px] text-muted">+216</span><input id="' . $id('phone') . '" type="tel" enterkeyhint="next" inputmode="tel" autocomplete="tel-national" placeholder="22 123 456" maxlength="16" class="min-w-0 flex-1 bg-transparent ps-2 pe-1 text-[16px] outline-none placeholder:text-muted" aria-required="true" aria-invalid="false" data-testid="phone" value=""></div>');
        $opts = '<option value="" selected="">' . e(t('buy.chooseGov')) . '</option>';
        foreach (array_keys(Gov::LIST) as $g) {
            $opts .= '<option value="' . e($g) . '"' . (($d['zones'][$g]['enabled'] ?? true) === false ? ' disabled=""' : '') . ' class="text-ink">' . e(Gov::label($g)) . '</option>';
        }
        $h .= self::field($id('governorate'), t('buy.governorate'), '', '<div class="relative"><select id="' . $id('governorate') . '" autocomplete="address-level1" class="' . self::INPUT . ' appearance-none pe-9 text-muted" aria-required="true" aria-invalid="false" data-testid="governorate">' . $opts . '</select>' . icon('ChevronDown', 'pointer-events-none absolute end-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-2') . '</div>');
        $h .= self::field($id('address'), t('buy.address'), self::WIDE, '<div class="relative"><textarea id="' . $id('address') . '" enterkeyhint="done" autocomplete="street-address" spellcheck="false" rows="1" maxlength="300" placeholder="' . e(t('buy.addressPh')) . '" class="' . self::INPUT . ' pe-3.5 block h-auto max-h-40 min-h-[48px] resize-none py-[11px] leading-snug [field-sizing:content]" aria-required="true" aria-invalid="false" data-testid="address"></textarea></div>');
        $h .= '</fieldset>';
        $h .= '<dl class="mt-4 flex flex-col gap-2 rounded-2xl bg-sand/70 p-4 text-[15px]" data-testid="summary">';
        $h .= '<div class="flex justify-between gap-3 text-ink-2"><dt>' . e($o['priceLabel']) . '</dt><dd class="shrink-0 whitespace-nowrap price-nums ltr-nums">' . e(Ui::money($price)) . '</dd></div>';
        $h .= '<div class="flex justify-between gap-3 text-ink-2"><dt>' . e(t('buy.delivery')) . '</dt><dd class="shrink-0 whitespace-nowrap price-nums ltr-nums">' . ($freeKnown ? '<span class="font-bold text-olive">' . e(t('product.free')) . '</span>' : e(Ui::money($deliveryShown))) . '</dd></div>';
        $h .= '<div class="flex items-baseline justify-between gap-3 border-t border-line pt-3"><dt class="text-[17px] font-bold text-ink">' . e(t('buy.total')) . '</dt><dd class="shrink-0 whitespace-nowrap text-end text-[21px] font-extrabold text-sea" data-testid="total">'
            . ($o['priceVaries'] ? '<span class="text-[15px] font-normal text-muted">' . e(t('product.from')) . self::SEP . ' </span>' : '') . '<span class="price-nums ltr-nums">' . e(Ui::money($total)) . '</span></dd></div>';
        if (!$freeKnown && $th !== null) {
            $h .= '<p class="text-[15px] text-muted">' . e(t('product.freeFrom', Ui::money((int) $th))) . '</p>';
        }
        $h .= '</dl></section>';
        $h .= '<button type="button" class="-my-1 inline-flex min-h-11 items-center self-start text-[15px] text-ink-2 underline decoration-field underline-offset-4">' . e(t('buy.promo')) . '</button>';
        if ($d['openBeforePaying']) {
            $h .= '<p class="flex items-center justify-center gap-1.5 text-[15px] text-ink-2">' . icon('Shield', 'h-5 w-5 shrink-0 text-sea') . e(t('trust.openBeforePaying')) . '</p>';
        }
        $h .= '<button id="' . $id('submit') . '" type="submit" class="flex min-h-[58px] w-full flex-wrap items-center justify-center gap-x-3 gap-y-1 rounded-full bg-buy px-5 py-2 text-[19px] font-bold leading-tight text-night shadow-[inset_0_-3px_0_var(--color-buy-2)] transition [--focus-ring:var(--color-night)] hover:bg-buy-2 active:scale-[.99] disabled:opacity-80" data-testid="place-order"><span class="inline-flex items-center gap-2.5">' . icon('Truck', 'h-6 w-6 shrink-0') . e($o['submitTitle']) . '</span>'
            . (!$o['priceVaries'] ? '<span class="whitespace-nowrap rounded-full bg-white/45 px-3 py-0.5 text-[18px] price-nums ltr-nums">' . e(Ui::money($total)) . '</span>' : '') . '</button>';
        if ($o['addToCart']) {
            $h .= '<button type="button" class="-mt-1 flex min-h-[52px] w-full items-center justify-center gap-2.5 rounded-full border border-ink/60 bg-white px-5 text-[17px] font-bold text-ink transition hover:bg-sand active:scale-[.99]" data-testid="add-to-cart">' . icon('CartPlus', 'h-6 w-6 shrink-0') . e(t('product.addToCart')) . '</button>';
        }
        $shopPhone = Phone::normalize((string) $settings['shop']['phone']);
        $wa = (string) $settings['contact']['whatsapp'];
        if ($shopPhone || $wa !== '') {
            $h .= '<div class="flex flex-wrap justify-center gap-2">';
            if ($wa !== '') {
                $h .= '<a href="https://wa.me/216' . e($wa) . '?text=' . rawurlencode($o['waText']) . '" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-2 rounded-full border border-line bg-white px-4 text-[15px] font-bold text-ink hover:bg-sand">' . icon('WhatsApp', 'h-5 w-5 text-wa') . e(t('buy.whatsapp')) . '</a>';
            }
            if ($shopPhone) {
                $h .= '<a href="tel:+216' . e($shopPhone) . '" class="inline-flex min-h-11 items-center gap-2 rounded-full border border-line bg-white px-4 text-[15px] text-ink hover:bg-sand" aria-label="' . e(t('buy.callUs') . ' : ' . Phone::format($shopPhone)) . '">' . icon('Phone', 'h-[18px] w-[18px] text-ink-2') . '<strong class="ltr-nums">' . e(Phone::format($shopPhone)) . '</strong></a>';
            }
            $h .= '</div>';
        }
        return $h . '</form>';
    }
}
