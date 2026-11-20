<?php
/** One product card. @var array $c (Catalog::cards row) */
$name = Catalog::name($c);
?>
<a class="card" href="<?= e(url('/produit/' . rawurlencode($c['slug']))) ?>">
  <div class="ph">
    <?php if ($c['imageId']) : ?>
      <img src="<?= e(Media::url($c['imageId'], 640)) ?>" srcset="<?= e(Media::srcset($c['imageId'])) ?>" sizes="(min-width: 1000px) 280px, 50vw" alt="<?= e($name) ?>" loading="lazy" width="640" height="640">
    <?php endif ?>
    <?php if (!$c['sellable']) : ?>
      <span class="tag out"><?= e(t('product.out')) ?></span>
    <?php elseif ($c['freeDelivery']) : ?>
      <span class="tag free"><?= e(t('buy.freeDelivery')) ?></span>
    <?php endif ?>
  </div>
  <h3><?= e($name) ?></h3>
  <div class="row">
    <span class="now price"><?php if ($c['priceFrom'] !== null) : ?><?= e(t('product.from')) ?> <?= e(Money::format($c['priceFrom'])) ?><?php else : ?><?= e(Money::format($c['price'])) ?><?php endif ?></span>
    <?php if ($c['priceFrom'] === null && $c['compareAt'] !== null && $c['compareAt'] > $c['price']) : ?>
      <span class="strike price"><?= e(Money::format($c['compareAt'])) ?></span>
    <?php endif ?>
  </div>
  <?php if ($c['deal']) : ?>
    <span class="deal"><?= e(OfferLabel::title($c['deal']['quantity'])) ?> : <span class="price"><?= e(Money::format($c['deal']['price'])) ?></span></span>
  <?php endif ?>
</a>
