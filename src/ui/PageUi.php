<?php
declare(strict_types=1);

/**
 * The other pages of the storefront as the Next.js shop writes them (Listing,
 * search, basket, thank-you, tracking, information pages), and the way a
 * Next.js component is placed in a page (Ui island: written here, taken over
 * in the browser by the same component, public/assets/islands).
 */
final class PageUi
{
    /** A Next.js component in the page: $ssr is the markup it writes first (hydrated), or none (drawn in the browser). */
    public static function island(string $name, array $props, string $ssr = '', bool $hydrate = true): string
    {
        return '<div class="contents" data-island="' . e($name) . '"' . ($hydrate && $ssr !== '' ? ' data-hydrate' : '') . '>' . $ssr . '</div>'
            . '<script type="application/json" data-props>' . json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }

    private const SORTS = ['new', 'popular', 'price_asc', 'price_desc'];

    public static function sort(): string
    {
        $v = get_str('tri', 20);
        return in_array($v, self::SORTS, true) ? $v : 'new';
    }

    /** Listing.tsx: a title (with a category's picture blurred behind it), the sorts, the grid. */
    public static function listing(string $title, string $path, string $sort, array $products, string $emptyText, ?array $banner = null): string
    {
        $n = count($products);
        $h = '<div class="mx-auto max-w-6xl px-4 pb-6 pt-6 md:pt-8"><div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between md:gap-6">';
        if ($banner) {
            $h .= '<div class="relative -mx-4 overflow-hidden px-4 py-7 md:mx-0 md:flex-1 md:rounded-2xl md:px-7 md:py-9">'
                . ($banner['image'] ? '<img src="/api/img/' . e($banner['image']) . '/640" alt="" aria-hidden="true" class="absolute inset-0 h-full w-full scale-110 object-cover opacity-70 blur-2xl">' : Ui::warmBlur())
                . '<div class="absolute inset-0 bg-white/45" aria-hidden="true"></div><div class="relative"><h1 class="text-[28px] font-extrabold leading-tight md:text-[36px]">' . e($title) . '</h1>';
            if ($banner['line'] && $banner['line'] !== $title) {
                $h .= '<p class="mt-1 text-[17px] text-ink-2">' . e($banner['line']) . '</p>';
            }
            if ($n > 0) {
                $h .= '<p class="mt-1 text-[15px] text-ink-2">' . e(t('category.count', $n)) . '</p>';
            }
            $h .= '</div></div>';
        } else {
            $h .= '<div class="flex items-baseline justify-between gap-3 md:justify-start"><h1 class="text-[26px] font-bold leading-tight md:text-[32px]">' . e($title) . '</h1>' . ($n > 0 ? '<p class="shrink-0 text-[15px] text-muted">' . e(t('category.count', $n)) . '</p>' : '') . '</div>';
        }
        if ($n > 1) {
            $h .= '<nav class="-mx-4 flex scroll-px-4 gap-2 overflow-x-auto px-4 no-scrollbar md:mx-0 md:px-0" aria-label="' . e(t('category.sort')) . '">';
            foreach (self::SORTS as $s) {
                $on = $s === $sort;
                $h .= '<a href="' . e(url($s === 'new' ? $path : "$path?tri=$s")) . '" class="inline-flex min-h-11 shrink-0 items-center rounded-full border px-4 text-[15px] transition ' . ($on ? 'border-sea bg-sea-soft/60 font-bold text-sea-2 ring-1 ring-sea' : 'border-field bg-white text-ink hover:border-sea/60') . '"' . ($on ? ' aria-current="page"' : '') . '>' . e(t('category.sorts.' . $s)) . '</a>';
            }
            $h .= '</nav>';
        }
        $h .= '</div>';
        if ($n === 0) {
            $h .= '<div class="flex flex-col items-center gap-5 py-16 text-center"><p class="max-w-xs text-[17px] text-ink-2">' . e($emptyText) . '</p>'
                . ($path !== '/boutique' ? '<a href="' . e(url('/boutique')) . '" class="inline-flex min-h-12 items-center rounded-full border border-ink/60 bg-white px-6 text-[17px] font-bold text-ink hover:bg-sand">' . e(t('home.shopNow')) . '</a>' : '') . '</div>';
        } else {
            $h .= Ui::productGrid($products, 4, $path === '/boutique' ? 'shop' : 'category');
        }
        return $h . '</div>';
    }

    /** Prose.tsx: an information page's white column. */
    public static function prose(string $title, string $inner): string
    {
        return '<div class="bg-white"><article class="mx-auto max-w-[38rem] px-4 pb-16 pt-8 md:pt-14"><h1 class="text-[28px] leading-tight md:text-[34px]">' . e($title) . '</h1><div class="mt-6 flex flex-col gap-3 text-[17px] leading-[1.7] text-ink-2 [&amp;_h2]:mt-7 [&amp;_h2]:text-[20px] [&amp;_h2]:leading-snug [&amp;&gt;ul]:flex [&amp;&gt;ul]:flex-col [&amp;&gt;ul]:gap-2 [&amp;&gt;ul&gt;li]:ms-5 [&amp;&gt;ul&gt;li]:list-disc [&amp;&gt;ul&gt;li]:ps-1 [&amp;_p&gt;a]:text-sea [&amp;_p&gt;a]:underline [&amp;_p&gt;a]:underline-offset-4">' . $inner . '</div></article></div>';
    }

    /** LookupForm.tsx, as it is first written. */
    public static function lookupForm(): string
    {
        $field = 'h-[54px] -ms-1 rounded-full border border-field bg-white px-5 text-[17px] outline-none placeholder:text-placeholder focus:border-sea focus:ring-2 focus:ring-sea/20';
        return '<form class="flex flex-col gap-3"><label class="flex flex-col gap-1.5 ps-1 text-[16px] text-ink-2">' . e(t('track.ref')) . '<input placeholder="MN-XXXXXX" class="' . $field . ' uppercase tracking-wider text-ink" dir="ltr" autocapitalize="characters" required="" data-testid="lookup-ref" value=""></label>'
            . '<label class="flex flex-col gap-1.5 ps-1 text-[16px] text-ink-2">' . e(t('track.phone')) . '<input inputmode="tel" placeholder="22 123 456" class="' . $field . ' text-ink" dir="ltr" required="" data-testid="lookup-phone" value=""></label>'
            . '<button type="submit" class="w-full inline-flex min-h-14 items-center justify-center gap-2 rounded-full bg-ink px-7 text-[17px] font-bold text-white hover:bg-ink-2 disabled:opacity-50">' . e(t('track.submit')) . '</button></form>';
    }

    /** "15 octobre" / "15 أكتوبر" (toLocaleDateString day + month, Tunis time). */
    public static function dayMonth(string $iso): string
    {
        $d = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Africa/Tunis'));
        return Rules::formatDay($d->format('Y-m-d'), lang() === 'ar' ? 'ar-TN' : 'fr-FR');
    }
}
