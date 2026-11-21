<?php
declare(strict_types=1);

/**
 * The storefront's building blocks, ported one by one from the Next.js shop's
 * components (src/components/shop/*.tsx) with the same markup and the same
 * classes: the page uses that shop's own stylesheet (public/assets/shop.css),
 * so the same classes give the same page. Each method names the component it
 * copies. Interactivity is in public/assets/shop.js.
 */
final class Ui
{
    /** pick(): the Arabic text on an Arabic page when there is one, the French otherwise. */
    public static function pick(?string $fr, ?string $ar): string
    {
        return lang() === 'ar' && $ar !== null && $ar !== '' ? $ar : (string) $fr;
    }

    public static function money(int $m): string
    {
        return Money::format($m, lang());
    }

    /** Pictures the page shows first, preloaded from <head> as next/image does for priority images. */
    public static array $preload = [];

    // ------------------------------------------------------------------ Img.tsx

    /** The widths next/image lists for a "sizes" (getWidths), with the shop's loader. */
    private static function widths(string $sizes): array
    {
        $all = Media::WIDTHS;
        if (preg_match_all('/(^|\s)(1?\d?\d)vw/', $sizes, $m) && $m[2]) {
            $ratio = min(array_map('intval', $m[2])) * 0.01;
            return array_values(array_filter($all, static fn ($w) => $w >= 360 * $ratio));
        }
        return $all;
    }

    /** next/image's blurred preview (getImageBlurSvg): the same SVG around the stored tiny picture; a sized picture keeps its proportions. */
    private static function blurStyle(string $blur, ?int $w = null, ?int $h = null): string
    {
        $box = $w && $h ? "viewBox='0 0 $w $h'" : '';
        $svg = "%3Csvg xmlns='http://www.w3.org/2000/svg' " . $box . "%3E%3Cfilter id='b' color-interpolation-filters='sRGB'%3E%3CfeGaussianBlur stdDeviation='20'/%3E%3CfeColorMatrix values='1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 0 0 0 100 -1' result='s'/%3E%3CfeFlood x='0' y='0' width='100%25' height='100%25'/%3E%3CfeComposite operator='out' in='s'/%3E%3CfeComposite in2='SourceGraphic'/%3E%3CfeGaussianBlur stdDeviation='20'/%3E%3C/filter%3E%3Cimage width='100%25' height='100%25' x='0' y='0' preserveAspectRatio='none' style='filter: url(%23b);' href='" . $blur . "'/%3E%3C/svg%3E";
        return ';background-size:cover;background-position:50% 50%;background-repeat:no-repeat;background-image:url("data:image/svg+xml;charset=utf-8,' . $svg . '")';
    }

    /** A product picture with its blurred preview while it loads ($image: id, blur, width, height): Img.tsx. */
    public static function img(?array $image, string $alt, string $sizes, bool $priority = false, string $class = '', bool $fill = true): string
    {
        if (!$image || empty($image['id'])) {
            return self::placeholder($class);
        }
        return self::nextImage($image, $alt, $sizes, $priority, 'object-cover ' . $class, $fill, true);
    }

    /** next/image's <img>, with the shop's picture addresses. $blur: show the stored preview while it loads. */
    public static function nextImage(array $image, string $alt, string $sizes, bool $priority, string $class, bool $fill, bool $blur = true): string
    {
        $id = (string) $image['id'];
        $list = self::widths($sizes);
        $srcset = implode(', ', array_map(static fn ($w) => Media::url($id, $w) . " {$w}w", $list));
        $src = Media::url($id, $list[count($list) - 1]);
        $style = $blur && !empty($image['blur']) && str_starts_with((string) $image['blur'], 'data:image/') ? self::blurStyle((string) $image['blur'], $fill ? null : (int) $image['width'], $fill ? null : (int) $image['height']) : '';
        if ($priority) {
            self::$preload[$srcset . '|' . $sizes] = '<link rel="preload" as="image" imagesrcset="' . e($srcset) . '" imagesizes="' . e($sizes) . '">';
        }
        $lazy = $priority ? '' : ' loading="lazy"';
        if ($fill) {
            return '<img alt="' . e($alt) . '"' . $lazy . ' decoding="async" data-nimg="fill" class="' . e($class) . '" style="position:absolute;height:100%;width:100%;left:0;top:0;right:0;bottom:0;color:transparent' . e($style) . '" sizes="' . e($sizes) . '" srcset="' . e($srcset) . '" src="' . e($src) . '">';
        }
        return '<img alt="' . e($alt) . '"' . $lazy . ' width="' . (int) $image['width'] . '" height="' . (int) $image['height'] . '" decoding="async" data-nimg="1" class="' . e($class) . '" style="color:transparent' . e($style) . '" sizes="' . e($sizes) . '" srcset="' . e($srcset) . '" src="' . e($src) . '">';
    }

    public static function placeholder(string $class = ''): string
    {
        return '<div class="absolute inset-0 flex items-center justify-center bg-sand ' . e($class) . '" aria-hidden="true"><svg viewBox="0 0 40 48" class="h-10 w-10 text-sand-2" fill="currentColor"><path d="M20 0C9 0 0 9 0 20v28h40V20C40 9 31 0 20 0Z"></path></svg></div>';
    }

    /** A category without a picture: warm, blurred browns. */
    public static function warmBlur(string $class = ''): string
    {
        return '<div class="absolute inset-0 overflow-hidden bg-[#e9d9c7] ' . e($class) . '" aria-hidden="true"><div class="absolute -start-1/4 top-1/4 h-3/4 w-3/4 rounded-full bg-[#c49a74] opacity-70 blur-3xl"></div><div class="absolute -end-1/4 bottom-0 h-2/3 w-2/3 rounded-full bg-[#9c6b4a] opacity-50 blur-3xl"></div><div class="absolute end-1/4 top-0 h-1/2 w-1/2 rounded-full bg-[#f3e7da] opacity-80 blur-2xl"></div></div>';
    }

    // ----------------------------------------------------------------- Logo.tsx

    public static function logo(string $class = '', bool $light = false): string
    {
        $stroke = $light ? 'var(--color-amber)' : 'var(--color-amber-2)';
        return '<span class="inline-flex flex-col items-stretch leading-none ' . e($class) . '" dir="ltr" aria-label="Mena" role="img">'
            . '<span aria-hidden="true" class="text-[29px] font-extrabold leading-[0.9] tracking-[-0.02em] ' . ($light ? 'text-white' : 'text-ink') . '">mena</span>'
            . '<svg aria-hidden="true" viewBox="0 0 96 16" class="-mt-0.5 h-[9px] w-full overflow-visible" fill="none" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M5 4.5Q46 17.5 88 5" stroke="' . $stroke . '" stroke-width="3.4"></path>'
            . '<path d="M80.4 1.9 89 4.7 83.5 11.8" stroke="' . $stroke . '" stroke-width="3.4"></path></svg></span>';
    }

    // ----------------------------------------------------------------- Bidi.tsx

    /** Latin runs (a brand, "450 °C") kept left to right inside Arabic text. Returns escaped HTML. */
    public static function bidi(string $text): string
    {
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return e($text);
        }
        $out = '';
        $last = 0;
        preg_match_all("/[A-Za-z0-9](?:[A-Za-z0-9 °~.,:\\/×%+\\-'’&]*[A-Za-z0-9°%])?/u", $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$run, $at]) {
            if (preg_match('/^\d+$/', $run)) {
                continue;
            }
            $out .= e(substr($text, $last, $at - $last)) . '<bdi dir="ltr">' . e($run) . '</bdi>';
            $last = $at + strlen($run);
        }
        return $out . e(substr($text, $last));
    }

    // ---------------------------------------------------------------- Stars.tsx

    public static function stars(float $value, int $size = 14): string
    {
        $out = '<span class="inline-flex items-center gap-0.5 text-[#c8902e]" aria-label="' . number_format($value, 1, '.', '') . ' / 5" dir="ltr">';
        for ($i = 1; $i <= 5; $i++) {
            $fill = max(0, min(1, $value - ($i - 1)));
            $id = 's' . $i . '-' . (int) round($fill * 100);
            $pct = rtrim(rtrim(number_format($fill * 100, 4, '.', ''), '0'), '.') . '%';
            $out .= '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 20 20" aria-hidden="true"><defs><linearGradient id="' . $id . '"><stop offset="' . $pct . '" stop-color="currentColor"></stop><stop offset="' . $pct . '" stop-color="#e2d8c6"></stop></linearGradient></defs><path fill="url(#' . $id . ')" d="M10 1.5l2.6 5.6 6 .7-4.5 4.1 1.2 6-5.3-3-5.3 3 1.2-6L1.4 7.8l6-.7L10 1.5z"></path></svg>';
        }
        return $out . '</span>';
    }

    // ---------------------------------------------------------------- Price.tsx

    public static function price(int $value, ?int $compareAt, string $size = 'md', ?string $from = null, bool $steady = false): string
    {
        $cls = $size === 'lg' ? 'text-[28px]' : ($size === 'sm' ? 'text-[16px]' : 'text-[19px]');
        $pct = Rules::discountPct($value, $compareAt);
        $hide = $steady ? 'max-md:hidden' : '';
        $out = '<span class="relative inline-flex flex-wrap items-baseline gap-x-2 gap-y-0.5">';
        if ($from) {
            $out .= '<span class="text-[14px] text-muted">' . e($from) . '</span>';
        }
        $out .= '<span class="whitespace-nowrap font-bold text-ink price-nums ltr-nums ' . $cls . '">' . e(self::money($value)) . '</span>';
        if ($pct > 0 && $compareAt) {
            $out .= '<span class="sr-only">' . e(t('buy.instead', self::money($compareAt))) . '</span>';
            $out .= '<span aria-hidden="true" class="whitespace-nowrap text-muted line-through price-nums ltr-nums ' . ($size === 'lg' ? 'text-[17px]' : 'text-[14px]') . ' ' . $hide . '">' . e(self::money($compareAt)) . '</span>';
            if ($size === 'lg') {
                $out .= '<span class="rounded-full bg-olive-soft px-2 py-0.5 text-[14px] font-bold text-olive ltr-nums ' . $hide . '">−' . $pct . '%</span>';
            }
        }
        return $out . '</span>';
    }

    // ---------------------------------------------------------- ProductCard.tsx

    /** Two to a row on a phone, three on a tablet, four, then five on a laptop. */
    private const CARD_SIZES = '(min-width: 1280px) 220px, (min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw';

    /** The colour line of a card: the best pack, free delivery, or a really low stock. */
    private static function cardTag(array $p): ?array
    {
        if ($p['deal']) {
            $note = OfferLabel::note($p['deal'], lang());
            return ['cls' => 'text-deal', 'icon' => icon('Gift', 'mt-0.5 h-4 w-4 shrink-0'), 'text' => $note ?? t('product.dealFor', OfferLabel::title((int) $p['deal']['quantity'], lang()), self::money((int) $p['deal']['price']))];
        }
        if ($p['freeDelivery']) {
            return ['cls' => 'text-olive', 'icon' => icon('Truck', 'mt-0.5 h-4 w-4 shrink-0'), 'text' => t('buy.freeDelivery')];
        }
        if ($p['stock']['kind'] === 'low') {
            return ['cls' => 'text-clay', 'icon' => '', 'text' => t('product.low', $p['stock']['qty'])];
        }
        return null;
    }

    /** A product in a list: picture, name, price, at most one fact in colour, real stars and orders, the buy button. */
    public static function productCard(array $p, bool $priority = false, string $class = '', bool $compact = false, int $pos = 0): string
    {
        $name = self::pick($p['name'], $p['nameAr']);
        $out = $p['stock']['kind'] === 'out';
        $pct = $out ? 0 : Rules::discountPct($p['price'], $p['compareAt']);
        $struck = !$out && $p['compareAt'] !== null && $p['compareAt'] > $p['price'] ? $p['compareAt'] : null;
        $tag = $out || $compact ? null : self::cardTag($p);
        $sold = $compact ? null : $p['sold'];
        $img0 = $p['images'][0] ?? null;
        $h = '<div class="group row-span-4 grid grid-rows-subgrid content-start gap-y-0 ' . e($class) . '" data-card="' . e($p['id']) . '">';
        $h .= '<a href="' . e(url('/produit/' . $p['slug'])) . '" class="row-span-3 grid grid-rows-subgrid gap-y-0 rounded-2xl" data-slug="' . e($p['slug']) . '" data-pos="' . $pos . '">';
        $h .= '<div class="relative aspect-square overflow-hidden rounded-2xl border border-line bg-white transition-shadow duration-300 group-hover:shadow-[0_10px_28px_-16px_rgb(0_0_0/.45)]">';
        $h .= self::img($img0, $img0 !== null && $img0['alt'] !== null ? (string) $img0['alt'] : $name, self::CARD_SIZES, $priority, 'transition duration-500 group-hover:scale-[1.03] ' . ($out ? 'opacity-60 grayscale' : ''));
        if (isset($p['images'][1])) {
            $h .= '<div class="absolute inset-0 hidden opacity-0 transition-opacity duration-300 pointer-fine:block group-hover:opacity-100" aria-hidden="true">' . self::img($p['images'][1], '', self::CARD_SIZES, false, $out ? 'opacity-60 grayscale' : '') . '</div>';
        }
        // OpeningHint: shown by shop.js while the product page is on its way.
        $h .= '<span aria-hidden="true" class="pointer-events-none absolute inset-0 grid place-items-center bg-white/55 transition-opacity duration-150 opacity-0" data-opening></span>';
        if ($out || $pct > 0) {
            $h .= '<span class="absolute start-2.5 top-2.5 rounded-full px-2.5 text-[14px] font-bold leading-7 text-white ' . ($out ? 'bg-ink/85' : 'bg-deal') . '">' . ($out ? e(t('product.out')) : '<span class="ltr-nums">−' . $pct . '%</span>') . '</span>';
        }
        $h .= '</div>';
        $h .= '<h3 class="mt-2.5 line-clamp-2 text-[16px] font-normal leading-snug text-ink underline-offset-2 group-hover:underline">' . self::bidi($name) . '</h3>';
        $h .= '<div class="mt-1 flex flex-col items-start gap-1"><span class="relative flex flex-wrap items-baseline gap-x-1.5">';
        if ($p['packOf'] || $p['priceFrom']) {
            $h .= '<span class="text-[15px] text-muted">' . e($p['packOf'] ? t('product.pack', $p['packOf']) : t('product.from')) . '</span>';
        }
        $h .= '<span class="whitespace-nowrap text-[20px] font-bold leading-tight text-ink price-nums ltr-nums">' . e(self::money($p['price'])) . '</span>';
        if ($struck !== null) {
            $h .= '<span class="sr-only">' . e(t('buy.instead', self::money($struck))) . '</span><s aria-hidden="true" class="whitespace-nowrap text-[15px] text-muted price-nums ltr-nums">' . e(self::money($struck)) . '</s>';
        }
        $h .= '</span>';
        if ($tag) {
            $h .= '<span data-tag class="inline-flex items-start gap-1 text-[15px] font-bold leading-snug ' . $tag['cls'] . '">' . $tag['icon'] . e($tag['text']) . '</span>';
        }
        if ($p['rating'] || $sold) {
            $h .= '<span data-meta class="flex flex-wrap items-center gap-x-1.5 text-[15px] text-muted">';
            if ($p['rating']) {
                $h .= '<span class="inline-flex items-center gap-1">' . icon('Star', 'h-4 w-4 text-[#b07a1e]') . '<span class="font-bold text-ink-2 ltr-nums">' . number_format((float) $p['rating']['avg'], 1, '.', '') . '</span><span class="sr-only">/5</span><span class="ltr-nums">(' . (int) $p['rating']['count'] . ')</span></span>';
            }
            if ($p['rating'] && $sold) {
                $h .= '<span aria-hidden="true" data-sold>·</span>';
            }
            if ($sold) {
                $h .= '<span data-sold><span class="ltr-nums">+' . (int) $sold . '</span> ' . e(t('product.soldShort')) . '</span>';
            }
            $h .= '</span>';
        }
        $h .= '</div></a>';
        if (!$out && !$compact) {
            // QuickBuy.tsx: opens the order form over the list (shop.js).
            $h .= '<div class="mt-3" data-buy><button type="button" data-quick="' . e($p['slug']) . '" data-product="' . e($p['id']) . '" class="flex min-h-11 w-full items-center justify-center rounded-full bg-buy px-3 text-[15px] font-bold md:min-h-10 whitespace-nowrap text-night transition [--focus-ring:var(--color-night)] hover:bg-buy-2 active:scale-[.98]" aria-haspopup="dialog" aria-label="' . e(t('product.buyNow')) . '">' . e(t('product.buyShort')) . '</button></div>';
        } else {
            $h .= '<span aria-hidden="true"></span>';
        }
        return $h . '</div>';
    }

    /** ListClicks.tsx: which product was opened from which list (shop.js reads data-list). */
    private static function listClicks(string $list, string $inner, ?string $q = null): string
    {
        return '<div class="contents" data-list="' . e($list) . '"' . ($q !== null ? ' data-q="' . e(mb_strtolower(mb_substr($q, 0, 80))) . '"' : '') . '>' . $inner . '</div>';
    }

    public static function productGrid(array $products, int $priorityFirst = 0, string $list = 'grid', ?string $q = null): string
    {
        $cards = '';
        foreach (array_values($products) as $i => $p) {
            $cards .= self::productCard($p, $i < $priorityFirst, '', false, $i);
        }
        return self::listClicks($list, '<div class="grid grid-cols-2 gap-x-3 gap-y-8 sm:grid-cols-3 lg:grid-cols-4 lg:gap-x-5 xl:grid-cols-5">' . $cards . '</div>', $q);
    }

    /** RowScroll.tsx: a shelf that scrolls sideways; arrows on a computer when there is more (shop.js shows them). */
    public static function rowScroll(string $class, string $inner): string
    {
        $arrow = 'absolute top-[28%] z-10 hidden h-11 w-11 items-center justify-center rounded-full border border-line bg-white/95 text-ink shadow-[0_4px_14px_-6px_rgb(0_0_0/.45)] transition hover:bg-white md:flex md:pointer-coarse:hidden';
        return '<div class="relative" data-row><div class="' . e($class) . '">' . $inner . '</div>'
            . '<button type="button" data-row-go="-1" aria-label="' . e(t('home.rowPrev')) . '" class="' . $arrow . ' -start-4" hidden>' . icon('Chevron', 'h-5 w-5 rotate-180') . '</button>'
            . '<button type="button" data-row-go="1" aria-label="' . e(t('home.rowNext')) . '" class="' . $arrow . ' -end-4" hidden>' . icon('Chevron', 'h-5 w-5') . '</button></div>';
    }

    public static function productRow(array $products, bool $compact = false, string $list = 'row'): string
    {
        $cards = '';
        foreach (array_values($products) as $i => $p) {
            $cards .= self::productCard($p, false, 'snap-start', $compact, $i);
        }
        return self::listClicks($list, self::rowScroll('-mx-4 grid auto-cols-[46%] grid-flow-col grid-rows-[repeat(4,auto)] gap-x-3 overflow-x-auto scroll-px-4 px-4 pb-2 snap-x no-scrollbar sm:auto-cols-[30%] md:mx-0 md:auto-cols-[calc((100%-3.75rem)/4)] md:scroll-px-0 md:gap-x-5 md:px-0 lg:auto-cols-[calc((100%-5rem)/5)] xl:auto-cols-[calc((100%-6.25rem)/6)]', $cards));
    }

    // ---------------------------------------------------------- QuadCards.tsx

    private const TILE_SIZES = '(min-width: 1024px) 130px, (min-width: 768px) 15vw, 38vw';

    /** Amazon's home cards: a title, four products in a 2 × 2 grid, a link to see them all. */
    public static function quadCard(array $q, bool $eager, string $list): string
    {
        $h = '<section class="flex w-[80%] shrink-0 snap-start flex-col rounded-2xl border border-line bg-white p-4 shadow-[0_6px_20px_-16px_rgb(0_0_0/.5)] sm:w-[46%] md:w-auto" aria-labelledby="quad-' . e($q['key']) . '">';
        $h .= '<h2 id="quad-' . e($q['key']) . '" class="line-clamp-2 text-[19px] font-bold leading-snug text-ink md:text-[20px]">' . e($q['title']) . '</h2>';
        $h .= '<ul class="mt-3 grid flex-1 grid-cols-2 gap-x-3 gap-y-3">';
        foreach (array_slice($q['products'], 0, 4) as $p) {
            $h .= '<li class="min-w-0"><a href="' . e(url('/produit/' . $p['slug'])) . '" class="group block" data-list="' . e($list) . '">'
                . '<span class="relative block aspect-square overflow-hidden rounded-xl bg-sand">' . self::img($p['images'][0] ?? null, '', self::TILE_SIZES, $eager, 'transition duration-500 group-hover:scale-[1.04]') . '</span>'
                . '<span class="mt-1.5 block truncate text-[14px] leading-tight text-ink-2" dir="auto">' . e(self::pick($p['name'], $p['nameAr'])) . '</span>'
                . '<span class="block text-[15px] font-bold text-ink price-nums ltr-nums">' . e(self::money($p['price'])) . '</span></a></li>';
        }
        $h .= '</ul><a href="' . e($q['link']['href']) . '" class="mt-3 flex min-h-10 items-center text-[15px] font-bold text-sea-2 underline-offset-4 hover:underline">' . e($q['link']['label']) . '</a></section>';
        return $h;
    }

    // -------------------------------------------------------- HeroBanners.tsx

    private const BOX = 'relative block aspect-[16/7] w-full overflow-hidden rounded-2xl md:aspect-[21/6]';
    private const BLEED = 'relative block aspect-[16/8] w-full overflow-hidden md:aspect-[21/7] lg:aspect-[21/6]';

    private static function slide(array $b, bool $first, bool $bleed): string
    {
        $box = $bleed ? self::BLEED : self::BOX;
        $pic = self::img(['id' => $b['imageId'], 'blur' => null, 'width' => 1600, 'height' => 700], $b['alt'], $bleed ? '100vw' : '(min-width: 1152px) 1120px, 100vw', $first);
        if (!$b['href']) {
            return '<div class="' . $box . '">' . $pic . '</div>';
        }
        if ($b['external']) {
            return '<a href="' . e($b['href']) . '" target="_blank" rel="noopener" class="' . $box . '">' . $pic . '</a>';
        }
        return '<a href="' . e($b['href']) . '" class="' . $box . '">' . $pic . '</a>';
    }

    /** The home page's banners: one as it is; several swiped, with dots, moving on by themselves (shop.js). */
    public static function heroBanners(array $banners, bool $bleed = false): string
    {
        if (!$banners) {
            return '';
        }
        if (count($banners) === 1) {
            return '<div class="' . ($bleed ? 'relative' : 'mt-4') . '">' . self::slide($banners[0], true, $bleed) . '</div>';
        }
        $h = '<div class="' . ($bleed ? '' : 'mt-4') . '" data-banners><div class="group relative">';
        $h .= '<ul class="relative flex snap-x snap-mandatory overflow-x-auto no-scrollbar ' . ($bleed ? '' : 'rounded-2xl') . '" aria-roledescription="carousel">';
        foreach ($banners as $i => $b) {
            $h .= '<li data-n="' . $i . '" class="w-full shrink-0 snap-start" aria-label="' . e($b['label']) . '">' . self::slide($b, $i === 0, $bleed) . '</li>';
        }
        $h .= '</ul>';
        foreach (['start' => t('home.bannerPrev'), 'end' => t('home.bannerNext')] as $side => $label) {
            $h .= '<button type="button" data-banner-go="' . ($side === 'start' ? '-1' : '1') . '" aria-label="' . e($label) . '" class="absolute top-1/2 z-10 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-line bg-white/90 text-ink opacity-80 shadow-[0_4px_14px_-6px_rgb(0_0_0/.45)] transition hover:bg-white hover:opacity-100 focus-visible:opacity-100 group-hover:opacity-100 lg:flex lg:pointer-coarse:hidden ' . ($side === 'start' ? 'start-3' : 'end-3') . '">' . icon('Chevron', 'h-6 w-6 ' . ($side === 'start' ? 'rotate-180' : '')) . '</button>';
        }
        $h .= '</div><div class="mt-2 flex justify-center gap-1">';
        foreach ($banners as $i => $b) {
            $h .= '<button type="button" data-banner-dot="' . $i . '" aria-label="' . e($b['label']) . '" aria-current="' . ($i === 0 ? 'true' : 'false') . '" class="grid h-6 w-6 place-items-center"><span class="block h-2 rounded-full transition-all ' . ($i === 0 ? 'w-5 bg-ink' : 'w-2 bg-ink/25') . '"></span></button>';
        }
        return $h . '</div></div>';
    }

    // ------------------------------------------------------------ page shelves

    /** The home page's Shelf: a title, "Tout voir", the content. */
    public static function shelf(string $title, string $inner, ?array $link = null): string
    {
        $h = '<section class="mx-auto mt-12 max-w-6xl px-4 md:mt-16"><div class="mb-4 flex items-center justify-between gap-4"><h2 class="text-[22px] font-bold leading-tight text-ink md:text-[26px]">' . e($title) . '</h2>';
        if ($link) {
            $h .= '<a href="' . e($link['href']) . '" class="-me-2 flex min-h-11 shrink-0 items-center gap-1 rounded-full px-2 text-[16px] font-bold text-sea-2 underline-offset-4 hover:underline">' . e($link['label']) . icon('Chevron', 'h-5 w-5') . '</a>';
        }
        return $h . '</div>' . $inner . '</section>';
    }

    // --------------------------------------------------------------- Header.tsx

    /** France for French, Tunisia for Arabic: drawn, as Windows shows emoji flags as letters. */
    public static function flag(string $of): string
    {
        $inner = $of === 'fr'
            ? '<rect width="10" height="20" fill="#002654"></rect><rect x="10" width="10" height="20" fill="#fff"></rect><rect x="20" width="10" height="20" fill="#ce1126"></rect>'
            : '<rect width="30" height="20" fill="#e70013"></rect><circle cx="15" cy="10" r="5" fill="#fff"></circle><circle cx="15.6" cy="10" r="3.75" fill="#e70013"></circle><circle cx="16.6" cy="10" r="3" fill="#fff"></circle><polygon fill="#e70013" points="15.3,10 18.4,9 16.5,11.7 16.5,8.3 18.4,11"></polygon>';
        return '<svg viewBox="0 0 30 20" class="h-[15px] w-[22px] shrink-0 overflow-hidden rounded-[3px] shadow-[0_0_0_1px_rgb(0_0_0/.15)]" aria-hidden="true">' . $inner . '</svg>';
    }

    /** The same page in the other language, keeping what was searched and the sort. */
    public static function switchHref(): string
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $bare = (string) preg_replace('#^/(ar|fr)(?=/|$)#', '', $path) ?: '/';
        $keep = [];
        foreach (['q', 'tri'] as $k) {
            $v = get_str($k, 200);
            if ($v !== '') {
                $keep[$k] = $v;
            }
        }
        return url($bare, lang() === 'ar' ? 'fr' : 'ar') . ($keep ? '?' . http_build_query($keep, '', '&', PHP_QUERY_RFC3986) : '');
    }

    private static function menuLink(string $href, string $label): string
    {
        return '<a href="' . e($href) . '" class="flex min-h-12 items-center rounded-xl px-3 text-[17px] text-ink hover:bg-sand">' . e($label) . '</a>';
    }

    /** The bar of two facts, the white header with search, language and basket, the category chips, the menu. */
    public static function header(array $categories): string
    {
        $l = lang();
        $s = Settings::all();
        $fee = (int) $s['delivery']['fee'];
        $announcement = [
            t('trust.cod'),
            $fee === 0 ? t('buy.freeDelivery') : (Gov::everywhere($s['delivery']['zones']) ? t('trust.deliveryFee', self::money($fee)) : t('buy.delivery') . ' ' . self::money($fee)),
        ];
        $phone = Phone::normalize((string) $s['shop']['phone']);
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $bare = (string) preg_replace('#^/(ar|fr)(?=/|$)#', '', $path) ?: '/';
        $onProduct = str_contains($path, '/produit/');
        $onHome = $bare === '/';
        $shopOn = str_ends_with($path, '/boutique');
        $compact = $onProduct;
        $q = str_ends_with($path, '/recherche') ? get_str('q', 200) : '';
        $switch = self::switchHref();
        $other = $l === 'fr' ? 'ar' : 'fr';
        $flag = self::flag($l === 'ar' ? 'fr' : 'tn');

        $h = '<div class="border-b border-line bg-sand text-[14px] text-ink-2 min-[375px]:text-[15px]"><p class="mx-auto flex min-h-9 max-w-6xl flex-wrap items-center justify-center gap-x-2 px-2 py-1.5 text-center min-[375px]:px-4">';
        foreach ($announcement as $i => $a) {
            $h .= ($i > 0 ? '<span aria-hidden="true" class="text-muted">·</span>' : '') . '<span>' . e($a) . '</span>';
        }
        $h .= '</p></div>';
        $h .= '<header class="z-30 border-b border-line bg-white text-ink md:sticky md:top-0"><div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-1.5 gap-y-2 px-2 pb-3 pt-2 min-[375px]:gap-x-2 min-[375px]:px-3 sm:gap-x-3 sm:px-4">';
        $h .= '<button type="button" data-menu-open class="-ms-1.5 flex h-11 w-11 items-center justify-center rounded-full text-ink hover:bg-sand" aria-label="' . e(t('nav.menu')) . '">' . icon('Menu', 'h-6 w-6') . '</button>';
        $h .= '<a href="' . e(url('/')) . '" class="flex items-center rounded-lg px-0.5 py-1" aria-label="Mena">' . self::logo() . '</a>';
        $h .= '<div class="order-last w-full md:order-none md:ms-4 md:w-auto md:flex-1 ' . ($compact ? 'max-md:hidden' : '') . '" data-search-wrap>' . self::searchBox($q) . '</div>';
        $h .= '<div class="ms-auto flex items-center gap-0.5 md:ms-0 md:gap-1">';
        if ($compact) {
            $h .= '<button type="button" data-search-open class="flex h-11 w-11 items-center justify-center rounded-full text-ink hover:bg-sand md:hidden" aria-label="' . e(t('nav.search')) . '">' . icon('Search', 'h-6 w-6') . '</button>';
        }
        if ($phone) {
            $h .= '<a href="tel:+216' . e($phone) . '" data-contact="phone" data-where="header" class="hidden flex-col items-end rounded-2xl px-3 py-1 leading-tight hover:bg-sand lg:flex"><span class="text-[14px] text-muted">' . e(t('trust.support')) . '</span><span class="text-[16px] font-bold text-ink ltr-nums">' . e(Phone::format($phone)) . '</span></a>';
        }
        $h .= '<a href="' . e($switch) . '" class="flex h-11 items-center gap-1.5 rounded-full border border-line px-3 text-[16px] font-bold text-ink hover:border-field min-[375px]:gap-2 min-[375px]:px-4" hreflang="' . $other . '">' . $flag . '<span class="max-[359px]:sr-only">' . e(t('nav.language')) . '</span></a>';
        // CartLink: the count comes from this browser's basket (shop.js).
        $h .= '<a href="' . e(url('/panier')) . '" class="relative flex h-11 items-center gap-1 rounded-full px-1.5 text-ink hover:bg-sand md:pe-3" aria-label="' . e(t('cart.open', 0)) . '" data-testid="cart-link" data-cart-link><span class="relative" dir="ltr">' . icon('Cart', 'h-7 w-7') . '<span class="absolute -top-2 left-[8px] flex h-[22px] min-w-[22px] items-center justify-center rounded-full bg-buy px-1 text-[14px] font-bold leading-none text-night" aria-hidden="true" data-testid="cart-count" hidden></span></span><span class="hidden text-[16px] font-bold md:inline">' . e(t('nav.cart')) . '</span></a>';
        $h .= '</div></div>';
        if ($categories) {
            $chip = static fn (bool $on) => 'flex h-11 shrink-0 items-center whitespace-nowrap rounded-full border px-4 text-[16px] ' . ($on ? 'border-ink bg-ink font-bold text-white' : 'border-line text-ink hover:border-field');
            $h .= '<nav class="relative ' . ($onProduct || $onHome ? 'max-md:hidden' : '') . '" aria-label="' . e(t('nav.categories')) . '"><div class="mx-auto flex max-w-6xl gap-2 overflow-x-auto px-3 pb-3 pt-0.5 no-scrollbar sm:px-4">';
            $h .= '<a href="' . e(url('/boutique')) . '" class="' . $chip($shopOn) . '"' . ($shopOn ? ' aria-current="page"' : '') . '>' . e(t('nav.shop')) . '</a>';
            foreach ($categories as $c) {
                $on = str_ends_with($path, '/categorie/' . $c['slug']);
                $h .= '<a href="' . e(url('/categorie/' . $c['slug'])) . '" class="' . $chip($on) . '"' . ($on ? ' aria-current="page"' : '') . '>' . e(self::pick($c['name'], $c['nameAr'])) . '</a>';
            }
            $h .= '</div><span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-0 w-10 bg-gradient-to-l from-white to-transparent rtl:bg-gradient-to-r md:hidden"></span></nav>';
        }
        $h .= '</header>';

        // The menu, shown by shop.js.
        $h .= '<div class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="' . e(t('nav.menu')) . '" data-menu hidden>';
        $h .= '<button type="button" class="absolute inset-0 animate-fade bg-ink/40" data-menu-close aria-label="' . e(t('nav.close')) . '" tabindex="-1"></button>';
        $h .= '<div class="absolute inset-y-0 start-0 flex w-[86%] max-w-sm animate-sheet-side flex-col overflow-y-auto bg-white shadow-lift [--sheet-from:-100%] rtl:[--sheet-from:100%]">';
        $h .= '<div class="flex items-center justify-between border-b border-line px-5 py-4">' . self::logo() . '<button type="button" data-menu-close class="flex h-11 w-11 items-center justify-center rounded-full hover:bg-sand" aria-label="' . e(t('nav.close')) . '">' . icon('Close', 'h-6 w-6') . '</button></div>';
        $h .= '<nav class="flex flex-col px-2 py-3">';
        $h .= self::menuLink(url('/'), t('nav.home')) . self::menuLink(url('/panier'), t('cart.title')) . self::menuLink(url('/boutique'), t('nav.shop'));
        $h .= '<p class="px-3 pb-1 pt-4 text-[15px] font-bold text-muted">' . e(t('nav.categories')) . '</p>';
        foreach ($categories as $c) {
            $h .= self::menuLink(url('/categorie/' . $c['slug']), self::pick($c['name'], $c['nameAr']));
        }
        $h .= '<div class="my-3 border-t border-line"></div>';
        $h .= self::menuLink(url('/suivi'), t('nav.track')) . self::menuLink(url('/livraison-retours'), t('footer.deliveryReturns')) . self::menuLink(url('/contact'), t('footer.contact'));
        $h .= '<a href="' . e($switch) . '" class="flex min-h-12 items-center gap-2.5 rounded-xl px-3 text-[17px] text-ink hover:bg-sand" hreflang="' . $other . '">' . $flag . e(t('nav.language')) . '</a>';
        if ($phone) {
            $h .= '<a href="tel:+216' . e($phone) . '" data-contact="phone" data-where="menu" class="mx-3 mt-4 flex min-h-12 items-center justify-center gap-2 rounded-full border border-field text-[17px] font-bold text-ink">' . icon('Phone', 'h-5 w-5') . '<span class="ltr-nums">' . e(Phone::format($phone)) . '</span></a>';
        }
        $h .= '</nav></div></div>';
        return $h;
    }

    /** One wide search box; suggestions from two letters (shop.js, /api/suggest). Works without JavaScript too. */
    private static function searchBox(string $q): string
    {
        return '<div class="relative" data-search><form role="search" action="' . e(url('/recherche')) . '" method="get" class="flex h-[52px] items-center rounded-full border border-field bg-white pe-1 focus-within:border-sea focus-within:ring-2 focus-within:ring-sea/20">'
            . '<input value="' . e($q) . '" placeholder="' . e(t('nav.searchPlaceholder')) . '" class="h-full min-w-0 flex-1 bg-transparent pe-2 ps-5 text-[17px] text-ink outline-none placeholder:text-placeholder" enterkeyhint="search" name="q" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="search-list" aria-autocomplete="list" aria-label="' . e(t('nav.search')) . '">'
            . '<button type="submit" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-ink text-white hover:bg-ink-2" aria-label="' . e(t('nav.search')) . '">' . icon('Search', 'h-5 w-5') . '</button></form></div>';
    }

    // --------------------------------------------------------------- Footer.tsx

    public static function footer(): string
    {
        $s = Settings::all();
        $shop = $s['shop'];
        $tel = Phone::normalize((string) $shop['phone']);
        $wa = (string) $s['contact']['whatsapp'];
        $h = '<footer class="mt-16 border-t border-line bg-white"><div class="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-10 md:flex-row md:items-start md:justify-between"><div class="flex flex-col items-start gap-4">' . self::logo();
        if ($tel || $wa) {
            $h .= '<div class="flex flex-wrap gap-2">';
            if ($tel) {
                $h .= '<a href="tel:+216' . e($tel) . '" data-contact="phone" data-where="footer" class="inline-flex h-12 items-center gap-2 rounded-full border border-field bg-white px-5 text-[17px] font-bold text-ink hover:bg-sand">' . icon('Phone', 'h-5 w-5') . '<span class="ltr-nums">' . e(Phone::format($tel)) . '</span></a>';
            }
            if ($wa) {
                $h .= '<a href="https://wa.me/216' . e($wa) . '?text=' . rawurlencode(t('trust.waHello')) . '" target="_blank" rel="noopener" data-contact="whatsapp" data-where="footer" class="inline-flex h-12 items-center gap-2 rounded-full bg-wa-soft px-5 text-[17px] font-bold text-wa-ink">' . icon('WhatsApp', 'h-5 w-5') . 'WhatsApp</a>';
            }
            $h .= '</div>';
        }
        $h .= '</div><nav aria-label="' . e(t('footer.help')) . '" class="grid grid-cols-2 gap-x-8 text-[16px] text-ink-2 sm:grid-cols-3 [&amp;&gt;a]:flex [&amp;&gt;a]:min-h-11 [&amp;&gt;a]:items-center [&amp;&gt;a:hover]:text-sea">';
        foreach ([['/suivi', 'nav.track'], ['/livraison-retours', 'footer.deliveryReturns'], ['/contact', 'footer.contact'], ['/conditions', 'footer.terms'], ['/confidentialite', 'footer.privacy']] as [$p, $k]) {
            $h .= '<a href="' . e(url($p)) . '">' . e(t($k)) . '</a>';
        }
        foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'] as $k => $label) {
            $u = profile_url($k, (string) ($shop[$k] ?? ''));
            if ($u !== '') {
                $h .= '<a href="' . e($u) . '" target="_blank" rel="noopener">' . $label . '</a>';
            }
        }
        if ($shop['email']) {
            $h .= '<a href="mailto:' . e($shop['email']) . '" class="col-span-2 break-all sm:col-span-1">' . e($shop['email']) . '</a>';
        }
        $h .= '</nav></div><div class="border-t border-line"><p class="mx-auto max-w-6xl px-4 py-4 text-[15px] text-muted">© ' . gmdate('Y') . ' ' . e($shop['legalName'] ?: $shop['name']);
        if ($shop['legalId']) {
            $h .= '<span class="ltr-nums"> · MF ' . e($shop['legalId']) . '</span>';
        }
        if ($shop['address']) {
            $h .= ' · ' . e($shop['address']);
        }
        return $h . '</p></div></footer>';
    }

    /** What shop.js needs to write: texts, the icons it draws, the pictures' format. */
    public static function clientTexts(): array
    {
        $arrow = 'absolute top-[28%] z-10 hidden h-11 w-11 items-center justify-center rounded-full border border-line bg-white/95 text-ink shadow-[0_4px_14px_-6px_rgb(0_0_0/.45)] transition hover:bg-white md:flex md:pointer-coarse:hidden';
        return [
            'cartOpen' => [t('cart.open', 0), t('cart.open', 1), t('cart.open', 2)],
            'suggestions' => t('search.suggestions'),
            'forYou' => t('home.forYou'),
            'recent' => t('home.recent'),
            'rowArrows' => '<button type="button" data-row-go="-1" aria-label="' . e(t('home.rowPrev')) . '" class="' . $arrow . ' -start-4" hidden>' . icon('Chevron', 'h-5 w-5 rotate-180') . '</button><button type="button" data-row-go="1" aria-label="' . e(t('home.rowNext')) . '" class="' . $arrow . ' -end-4" hidden>' . icon('Chevron', 'h-5 w-5') . '</button>',
            'imgExt' => Media::ext(),
            'tracking' => array_intersect_key(Settings::get('tracking', []), array_flip(['metaPixelId', 'tiktokPixelId', 'gtmId'])),
            'icons' => array_map(static fn ($v) => $v[1], Icons::SVG),
        ];
    }

    // ------------------------------------------------------- WhatsAppButton.tsx

    /** The floating WhatsApp button; not where an order bar or form already sits. The greeting is shown by shop.js on the home page. */
    public static function waFloat(): string
    {
        $phone = (string) Settings::get('contact.whatsapp', '');
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($phone === '' || preg_match('#/(produit|commande|panier)(/|$)#', $path)) {
            return '';
        }
        $link = 'https://wa.me/216' . $phone . '?text=' . rawurlencode(t('trust.waHello'));
        $home = in_array($path, ['/', '/fr', '/ar'], true);
        $h = '';
        if ($home) {
            $h .= '<div class="wa-float fixed bottom-[calc(max(1rem,env(safe-area-inset-bottom))+4.25rem)] end-4 z-30 flex max-w-[15rem] animate-fade items-start gap-1 rounded-2xl rounded-ee-md bg-white py-2.5 ps-3.5 pe-1.5 text-[16px] leading-snug text-ink shadow-[0_8px_24px_-8px_rgb(0_0_0/.35)] md:bottom-[5.5rem] md:end-6" data-wa-hello hidden><a href="' . e($link) . '" target="_blank" rel="noopener" data-contact="whatsapp" data-where="float" class="font-medium">' . e(t('trust.waGreeting')) . '</a><button type="button" data-wa-hello-close class="-my-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted hover:bg-sand" aria-label="' . e(t('nav.close')) . '">×</button></div>';
        }
        return $h . '<a href="' . e($link) . '" target="_blank" rel="noopener" data-contact="whatsapp" data-where="float" class="wa-float fixed bottom-[max(1rem,env(safe-area-inset-bottom))] end-4 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-wa text-white shadow-[0_6px_16px_-6px_rgb(0_0_0/.45)] transition active:scale-95 md:bottom-6 md:end-6" aria-label="' . e(t('trust.waBubble')) . '">' . icon('WhatsApp', 'h-7 w-7') . '</a>';
    }
}
