<?php
/**
 * The product page, as the Next.js shop's src/app/[lang]/produit/[slug]/page.tsx:
 * the trail (computer), the product (ProductView: written here, then taken over
 * in the browser by the same component), and quiet suggestions under it.
 *
 * @var array $p @var array $related @var array $settings @var string $pageUrl
 */
$props = ['p' => $p, 'lang' => lang(), 'settings' => $settings, 'url' => $pageUrl];
?>
<div class="bg-white">
<nav class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-1.5 px-4 py-1 text-[15px] text-muted max-md:hidden md:pt-4" aria-label="breadcrumb"><a class="flex min-h-10 items-center hover:text-ink" href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a><?php if ($p['category']) : ?><span aria-hidden="true">/</span><a class="flex min-h-10 items-center hover:text-ink" href="<?= e(url('/categorie/' . $p['category']['slug'])) ?>"><?= e(Ui::pick($p['category']['name'], $p['category']['nameAr'])) ?></a><?php endif ?></nav>
<div class="contents" data-island="ProductView" data-hydrate><?= ProductUi::view($p, $settings, $pageUrl) ?></div>
<script type="application/json" data-props><?= json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if (count($related) >= 2) : ?>
<section class="mx-auto max-w-6xl border-t border-line px-4 pb-4 pt-8" aria-labelledby="related-title"><h2 id="related-title" class="mb-4 scroll-mt-4 text-[20px] font-bold"><?= e(t('product.related')) ?></h2><?= Ui::productRow(array_slice($related, 0, 8), true, 'related') ?></section>
<?php endif ?>
</div>
