<?php
/** @var string $q @var array $cards */
?>
<div class="wrap">
  <h1 class="page-title"><?= e($q !== '' ? ($cards ? t('search.results', count($cards), $q) : t('search.none', $q)) : t('search.title')) ?></h1>
  <?php if ($q !== '' && !$cards) : ?>
    <p class="muted"><?= e(t('search.noneHelp')) ?></p>
    <p><a class="btn" href="<?= e(url('/boutique')) ?>"><?= e(t('home.allShop')) ?></a></p>
  <?php endif ?>
  <?php if ($cards) : ?><div class="grid"><?php foreach ($cards as $c) { require ROOT . '/views/shop/_card.php'; } ?></div><?php endif ?>
</div>
