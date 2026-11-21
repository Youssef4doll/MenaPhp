<?php
/**
 * The home page, as the Next.js shop's src/app/[lang]/page.tsx: one line of
 * welcome, the categories as tall pictures, the banners and the cards, the
 * shelves, the whole shop ranked, the reviews, how ordering works.
 *
 * @var array $hero @var array $shown @var array $banners @var array $quads @var array $home @var array $best
 * @var array $deals @var array $catShelves @var array $small @var array $fresh @var array $forClient @var array $settings
 */
$seeMore = t('home.seeMore');
?>
<section class="mx-auto max-w-6xl px-4 pb-4 pt-5 md:pb-5 md:pt-7"><h1 class="text-[22px] font-bold leading-snug text-ink sm:text-[28px]"><?= e(Ui::pick($hero['title'], $hero['titleAr'])) ?></h1></section>

<?php if ($shown) : ?>
<section class="mx-auto max-w-6xl px-4 pb-5 md:pb-7" aria-label="<?= e(t('home.categories')) ?>"><ul class="-mx-4 flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pb-2 no-scrollbar md:mx-0 md:scroll-px-0 md:px-0">
<?php foreach ($shown as $i => $c) :
    $image = $c['imageId'] ?? $c['firstImage'] ?? null;
    $title = Ui::pick(($c['headline'] ?? '') ?: $c['name'], ($c['headlineAr'] ?? '') ?: ($c['nameAr'] ?? '') ?: (($c['headline'] ?? '') ?: $c['name']));
    ?>
<li class="w-[44%] shrink-0 snap-start sm:w-[30%] md:w-[calc((100%-3rem)/5)]"><a href="<?= e(url('/categorie/' . $c['slug'])) ?>" class="group relative block aspect-[3/4.6] overflow-hidden rounded-2xl bg-sand"><?= $image ? Ui::img(['id' => $image, 'blur' => null, 'width' => 600, 'height' => 600], '', '(min-width: 768px) 20vw, 45vw', $i < 3, 'object-cover transition duration-500 group-hover:scale-[1.03]') : Ui::warmBlur() ?><span class="absolute inset-x-0 top-0 bg-white/55 px-3.5 pb-10 pt-3.5 backdrop-blur-md" style="mask-image:linear-gradient(to bottom, #000 62%, transparent);-webkit-mask-image:linear-gradient(to bottom, #000 62%, transparent)"><span class="line-clamp-3 block text-[20px] font-extrabold leading-[1.15] text-ink md:text-[23px]"><?= e($title) ?></span></span></a></li>
<?php endforeach ?>
</ul></section>
<?php endif ?>

<?php if ($banners || $quads) : ?>
<div class="bg-cream pb-6 md:pb-8">
<?= Ui::heroBanners($banners, true) ?>
<?php if ($quads) : ?>
<div class="relative mx-auto max-w-6xl pt-4 md:px-4 md:pt-5"><div class="flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pb-2 no-scrollbar md:grid md:grid-cols-3 md:gap-4 md:overflow-visible md:px-0 md:pb-0 lg:grid-cols-4">
<?php foreach ($quads as $i => $q) : ?><?= Ui::quadCard($q, $i === 0, 'home_quad_' . $q['key']) ?><?php endforeach ?>
</div></div>
<?php endif ?>
</div>
<?php endif ?>

<?php if (!$home['newest']) : ?><p class="mx-auto max-w-6xl px-4 py-16 text-center text-[18px] text-muted"><?= e(t('home.empty')) ?></p><?php endif ?>

<?php if ($home['featured']) : ?><?= Ui::shelf(t('home.featured'), Ui::productRow($home['featured'], false, 'home_featured'), ['href' => url('/boutique'), 'label' => $seeMore]) ?><?php endif ?>
<?php /* HomeForYou: filled by shop.js for a visitor who came before (what they opened, more of their categories). */ ?>
<div class="contents" data-for-you></div>
<?php if ($best) : ?><?= Ui::shelf(t('home.bestsellers'), Ui::productRow($best, false, 'home_best'), ['href' => url('/boutique?tri=popular'), 'label' => $seeMore]) ?><?php endif ?>
<?php if (count($deals) >= 4) : ?><?= Ui::shelf(t('home.deals'), Ui::productRow($deals, false, 'home_deals'), ['href' => url('/boutique'), 'label' => $seeMore]) ?><?php endif ?>
<?php foreach ($catShelves as $i => $cs) : ?>
<div class="contents"><?= Ui::shelf(t('home.bestOf', Ui::pick($cs['cat']['name'], $cs['cat']['nameAr'])), Ui::productRow($cs['products'], false, 'home_cat_' . $cs['cat']['slug']), ['href' => url('/categorie/' . $cs['cat']['slug']), 'label' => $seeMore]) ?>
<?php if ($i === 0 && count($small) >= 4) : ?><?= Ui::shelf(t('home.small', Ui::money(30000)), Ui::productRow($small, false, 'home_small'), ['href' => url('/boutique?tri=price_asc'), 'label' => $seeMore]) ?><?php endif ?>
</div>
<?php endforeach ?>
<?php if (count($fresh) >= 4) : ?><?= Ui::shelf(t('home.newest'), Ui::productRow($fresh, false, 'home_new'), ['href' => url('/boutique'), 'label' => $seeMore]) ?><?php endif ?>

<?php if ($forClient) : ?>
<?php /* HomeMore: the whole shop ranked, ten more each time the reader nears the end (shop.js). The other cards wait in a template. */ ?>
<section class="mx-auto mt-12 max-w-6xl px-4 md:mt-16" data-home-more>
<h2 class="mb-4 text-[22px] font-bold leading-tight text-ink md:text-[26px]"><?= e(t('home.more')) ?></h2>
<?= Ui::productGrid(array_slice(array_column($forClient, 'card'), 0, 10), 0, 'home_feed') ?>
<div class="mt-8 flex justify-center" data-more-end>
<?php if (count($forClient) > 10) : ?>
<button type="button" data-more class="min-h-12 rounded-full border border-ink/60 bg-white px-6 text-[16px] font-bold text-ink hover:bg-sand"><?= e(t('home.loadMore')) ?></button>
<?php endif ?>
<a href="<?= e(url('/boutique')) ?>" data-all-shop class="flex min-h-12 items-center gap-1 rounded-full bg-ink px-6 text-[16px] font-bold text-white"<?= count($forClient) > 10 ? ' hidden' : '' ?>><?= e(t('home.allShop')) ?><?= icon('Chevron', 'h-5 w-5') ?></a>
</div>
<template data-feed-cards><?php foreach ($forClient as $i => $f) : ?><?= str_replace('<div class="group ', '<div data-cat="' . e((string) $f['category']) . '" class="group ', Ui::productCard($f['card'], false, '', false, $i)) ?><?php endforeach ?></template>
</section>
<?php endif ?>

<?php if ($home['reviews']) : ?>
<?php ob_start() ?>
<div class="-mx-4 flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pb-2 no-scrollbar md:mx-0 md:grid md:scroll-px-0 md:grid-cols-3 md:overflow-visible md:px-0">
<?php foreach ($home['reviews'] as $r) : ?>
<figure class="w-[82%] shrink-0 snap-start rounded-2xl border border-line bg-white p-5 sm:w-80 md:w-auto"><?= Ui::stars((float) $r['rating'], 18) ?><blockquote class="mt-3 line-clamp-5 text-[17px] leading-relaxed text-ink-2"><?= e($r['text']) ?></blockquote><figcaption class="mt-4 text-[15px] text-muted"><span class="font-bold text-ink"><?= e($r['authorName']) ?></span><?= $r['governorate'] ? ' · ' . e(Gov::label($r['governorate'])) : '' ?> · <a href="<?= e(url('/produit/' . $r['productSlug'])) ?>" class="underline underline-offset-2"><?= e(Ui::pick($r['productName'], $r['productNameAr'])) ?></a></figcaption></figure>
<?php endforeach ?>
</div>
<?= Ui::shelf(t('home.reviews'), (string) ob_get_clean()) ?>
<?php endif ?>

<section class="mx-auto mt-14 max-w-6xl px-4">
<h2 class="text-[22px] font-bold leading-tight text-ink md:text-[26px]"><?= e(t('home.howTitle')) ?></h2>
<ol class="mt-5 grid gap-4 sm:grid-cols-3">
<?php foreach (Lang::list('home.how') as $i => $step) : ?>
<li class="flex items-center gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-field text-[17px] font-bold text-ink ltr-nums"><?= $i + 1 ?></span><span class="text-[17px] leading-snug text-ink"><?= e($step) ?></span></li>
<?php endforeach ?>
</ol>
<ul class="mt-6 flex flex-wrap gap-2">
<?php if ($settings['delivery']['openBeforePaying']) : ?><li class="inline-flex min-h-10 items-center gap-2 rounded-full bg-sand px-4 text-[15px] text-ink-2"><?= icon('Shield', 'h-5 w-5 text-sea') ?><?= e(t('trust.openBeforePaying')) ?></li><?php endif ?>
<li class="inline-flex min-h-10 items-center gap-2 rounded-full bg-sand px-4 text-[15px] text-ink-2"><?= icon('Return', 'h-5 w-5 text-sea') ?><?= e(t('trust.returns', $settings['returns']['days'])) ?></li>
</ul>
</section>
<script type="application/json" data-feed><?= json_encode(array_map(static fn ($f) => [$f['card']['id'], $f['category']], $forClient), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
