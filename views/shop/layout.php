<?php
/**
 * The shop's frame, as the Next.js shop's src/app/[lang]/layout.tsx: the same
 * <html> classes (the compiled stylesheet keys on them), the skip link, the
 * header, the page, the footer and the WhatsApp button.
 *
 * @var string $content @var string $title @var ?string $description @var ?string $canonical
 * @var bool $fullTitle (the title is used as it is, without "· Mena") @var array $jsonLd @var ?array $og
 */
$l = lang();
$s = Settings::all();
$shopName = (string) $s['shop']['name'];
$tagline = Ui::pick($s['shop']['tagline'], $s['shop']['taglineAr']);
$pageTitle = !empty($fullTitle) || $title === '' ? ($title !== '' ? $title : "$shopName — $tagline") : "$title · $shopName";
$desc = $description ?? $tagline;
$here = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$bare = (string) preg_replace('#^/(ar|fr)(?=/|$)#', '', $here) ?: '/';
$base = rtrim((string) config('site_url', ''), '/');
$header = Ui::header(Catalog::navCategories());
$footer = Ui::footer();
$wa = Ui::waFloat();
?>
<!doctype html>
<html lang="<?= $l === 'ar' ? 'ar-TN' : 'fr-TN' ?>" dir="<?= $l === 'ar' ? 'rtl' : 'ltr' ?>" class="shop-root cairo_dbaca838-module___q3zXW__variable">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<link rel="preload" href="/assets/media/<?= $l === 'ar' ? '9ff27b8a0a8f3dc0-s.p.40_3w74kn95bo' : 'd41831e24743a3c1-s.p.08tn9snzkmifr' ?>.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('/assets/shop.css')) ?>">
<?= implode("\n", Ui::$preload) ?>

<meta name="theme-color" content="#ffffff">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e(mb_substr((string) $desc, 0, 300)) ?>">
<?php if (!empty($canonical)) : ?>
<link rel="canonical" href="<?= e($base . $canonical) ?>">
<link rel="alternate" hreflang="fr" href="<?= e($base . url($bare, 'fr')) ?>">
<link rel="alternate" hreflang="ar" href="<?= e($base . url($bare, 'ar')) ?>">
<link rel="alternate" hreflang="x-default" href="<?= e($base . url($bare, 'fr')) ?>">
<?php endif ?>
<meta property="og:title" content="<?= e($og['title'] ?? $pageTitle) ?>">
<meta property="og:description" content="<?= e(mb_substr((string) ($og['description'] ?? $desc), 0, 300)) ?>">
<meta property="og:site_name" content="<?= e($shopName) ?>">
<meta property="og:locale" content="<?= $l === 'ar' ? 'ar_TN' : 'fr_TN' ?>">
<?php foreach ($og['images'] ?? [] as $img) : ?>
<meta property="og:image" content="<?= e($base . $img) ?>">
<?php endforeach ?>
<meta property="og:type" content="<?= e($og['type'] ?? 'website') ?>">
<?php foreach ($meta ?? [] as $k => $v) : ?>
<meta property="<?= e($k) ?>" content="<?= e($v) ?>">
<?php endforeach ?>
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/assets/icon.svg" sizes="any" type="image/svg+xml">
<meta name="facebook-domain-verification" content="ea2z4wt9uo28w8hpxgzduab5r3s4el">
<?php foreach ($jsonLd ?? [] as $ld) : ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach ?>
<?php if (str_contains($content, 'data-island=')) : ?>
<link rel="modulepreload" href="<?= e(asset('/assets/islands/islands.js')) ?>">
<?php endif ?>
<script src="<?= e(asset('/assets/shop.js')) ?>" defer></script>
</head>
<body class="min-h-dvh" data-lang="<?= $l ?>" data-base="<?= $l === 'ar' ? '/ar' : '' ?>" data-site="<?= e($base) ?>">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-full focus:bg-ink focus:px-5 focus:py-3 focus:text-[16px] focus:font-bold focus:text-white"><?= e(t('nav.skip')) ?></a>
<?= $header ?>
<main id="main" tabindex="-1" class="outline-none"><?= $content ?></main>
<?= $footer ?>
<?= $wa ?>
<script type="application/json" id="mena-texts" data-islands="<?= e(asset('/assets/islands/islands.js')) ?>"><?= json_encode(Ui::clientTexts(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</body>
</html>
