<?php
/** @var array $categories @var array $featured @var array $newest */
$l = lang();
?>
<div class="wrap">
  <section class="hero">
    <h1><?= e($l === 'ar' ? Settings::get('hero.titleAr') : Settings::get('hero.title')) ?></h1>
    <p><?= e($l === 'ar' ? Settings::get('shop.taglineAr') : Settings::get('shop.tagline')) ?></p>
  </section>

  <?php if ($categories) : ?>
  <!-- The categories first, as the owner asked. -->
  <section class="section" aria-labelledby="h-cats">
    <div class="section-head"><h2 id="h-cats"><?= e(t('home.categories')) ?></h2></div>
    <div class="cats">
      <?php foreach ($categories as $c) : $cn = Catalog::name($c); $head = Catalog::name($c, 'headline'); ?>
        <a class="cat" href="<?= e(url('/categorie/' . rawurlencode($c['slug']))) ?>">
          <?php if ($c['imageId']) : ?><img src="<?= e(Media::url($c['imageId'], 360)) ?>" alt="" loading="lazy" width="360" height="480"><?php endif ?>
          <span><?= e($head !== '' ? $head : $cn) ?></span>
        </a>
      <?php endforeach ?>
    </div>
  </section>
  <?php endif ?>

  <?php if (!$featured && !$newest) : ?>
    <p class="section muted"><?= e(t('home.empty')) ?></p>
  <?php endif ?>

  <?php if ($featured) : ?>
  <section class="section" aria-labelledby="h-feat">
    <div class="section-head"><h2 id="h-feat"><?= e(t('home.featured')) ?></h2></div>
    <div class="grid"><?php foreach ($featured as $c) { require ROOT . '/views/shop/_card.php'; } ?></div>
  </section>
  <?php endif ?>

  <?php if ($newest) : ?>
  <section class="section" aria-labelledby="h-new">
    <div class="section-head"><h2 id="h-new"><?= e(t('home.newest')) ?></h2><a href="<?= e(url('/boutique')) ?>"><?= e(t('home.seeMore')) ?></a></div>
    <div class="grid"><?php foreach ($newest as $c) { require ROOT . '/views/shop/_card.php'; } ?></div>
  </section>
  <?php endif ?>

  <section class="section" aria-labelledby="h-how">
    <div class="section-head"><h2 id="h-how"><?= e(t('home.howTitle')) ?></h2></div>
    <ol class="how"><?php foreach (Lang::list('home.how') as $step) : ?><li><?= e($step) ?></li><?php endforeach ?></ol>
  </section>
</div>
