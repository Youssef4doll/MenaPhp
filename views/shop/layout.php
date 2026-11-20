<?php
/** @var string $content @var string $title @var ?string $description @var ?string $canonical */
$l = lang();
$shopName = (string) Settings::get('shop.name', 'Mena');
$here = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$bare = $l === 'ar' ? (substr($here, 3) ?: '/') : $here;
$other = $l === 'ar' ? 'fr' : 'ar';
$qs = $_SERVER['QUERY_STRING'] ?? '';
$switch = url($bare, $other) . ($qs !== '' ? '?' . $qs : '');
$count = Cart::count();
$wa = (string) Settings::get('contact.whatsapp', '');
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="<?= $l ?>" dir="<?= $l === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php if (!empty($description)) : ?><meta name="description" content="<?= e(mb_substr($description, 0, 160)) ?>"><?php endif ?>
<?php if (!empty($canonical)) : ?>
<link rel="canonical" href="<?= e(absolute_url($canonical)) ?>">
<link rel="alternate" hreflang="fr" href="<?= e(absolute_url(url($bare, 'fr'))) ?>">
<link rel="alternate" hreflang="ar" href="<?= e(absolute_url(url($bare, 'ar'))) ?>">
<?php endif ?>
<meta name="theme-color" content="#1f5d8c">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/icon.svg" type="image/svg+xml">
<link rel="preload" href="/assets/fonts/cairo-<?= $l === 'ar' ? 'arabic' : 'latin' ?>-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/app.css?v=1">
<script src="/assets/app.js?v=1" defer></script>
</head>
<body>
<a class="skip" href="#main"><?= e(t('nav.skip')) ?></a>
<header class="top">
  <div class="wrap top-row">
    <a class="logo" href="<?= e(url('/')) ?>" aria-label="<?= e($shopName) ?>"><img src="/assets/logo.png" alt="<?= e($shopName) ?>" width="104" height="37"></a>
    <form class="search" action="<?= e(url('/recherche')) ?>" method="get" role="search">
      <input type="search" name="q" placeholder="<?= e(t('nav.searchPlaceholder')) ?>" aria-label="<?= e(t('nav.search')) ?>" value="<?= e(get_str('q', 80)) ?>">
    </form>
    <a class="lang" href="<?= e($switch) ?>" hreflang="<?= $other ?>"><?= e(t('nav.language')) ?></a>
    <a class="cart-link" href="<?= e(url('/panier')) ?>" aria-label="<?= e(t('nav.cart')) ?> (<?= $count ?>)">
      <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 8H6.2M10 21h.01M17 21h.01"/></svg>
      <?php if ($count > 0) : ?><span class="badge"><?= $count ?></span><?php endif ?>
    </a>
  </div>
  <div class="trust-bar"><div class="wrap"><?= e(t('trust.cod')) ?> · <?= e(Gov::everywhere(Settings::get('delivery.zones', [])) ? t('trust.delivery') : t('trust.deliveryHome')) ?><?php if (Settings::get('delivery.openBeforePaying')) : ?> · <?= e(t('trust.openBeforePaying')) ?><?php endif ?></div></div>
</header>
<?php if ($flash) : ?><div class="wrap"><p class="flash" role="status"><?= e($flash) ?></p></div><?php endif ?>
<main id="main"><?= $content ?></main>
<footer class="foot">
  <div class="wrap foot-grid">
    <div>
      <img src="/assets/logo.png" alt="<?= e($shopName) ?>" width="90" height="32">
      <p class="muted"><?= e($l === 'ar' ? Settings::get('shop.taglineAr') : Settings::get('shop.tagline')) ?></p>
    </div>
    <nav aria-label="<?= e(t('footer.help')) ?>">
      <a href="<?= e(url('/suivi')) ?>"><?= e(t('nav.track')) ?></a>
      <a href="<?= e(url('/livraison-retours')) ?>"><?= e(t('footer.deliveryReturns')) ?></a>
      <a href="<?= e(url('/contact')) ?>"><?= e(t('footer.contact')) ?></a>
      <a href="<?= e(url('/conditions')) ?>"><?= e(t('footer.terms')) ?></a>
      <a href="<?= e(url('/confidentialite')) ?>"><?= e(t('footer.privacy')) ?></a>
    </nav>
  </div>
  <p class="wrap muted small">© <?= date('Y') ?> <?= e(Settings::get('shop.legalName') ?: $shopName) ?>. <?= e(t('footer.rights')) ?></p>
</footer>
<?php if ($wa !== '') : ?>
<a class="wa-bubble" href="https://wa.me/216<?= e(preg_replace('/\D/', '', $wa)) ?>" target="_blank" rel="noopener" aria-label="WhatsApp">
  <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2c-1.5 0-3-.4-4.3-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .1-1.2c0-.1-.2-.2-.5-.3Z"/></svg>
</a>
<?php endif ?>
</body>
</html>
