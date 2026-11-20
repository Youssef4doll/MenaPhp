<?php
/** @var array $quote @var array $notes @var array $form */
$lines = $quote['lines'];
$cartLines = Cart::lines();
?>
<div class="wrap narrow">
  <h1 class="page-title"><?= e(t('cart.title')) ?></h1>
  <?php if ($notes) : ?><ul class="notes"><?php foreach ($notes as $n) : ?><li><?= e($n) ?></li><?php endforeach ?></ul><?php endif ?>
  <?php if (!$lines) : ?>
    <p class="muted"><?= e(t('cart.empty')) ?></p>
    <p><a class="btn dark" href="<?= e(url('/boutique')) ?>"><?= e(t('cart.keepShopping')) ?></a></p>
  <?php else : ?>
    <ul class="lines">
      <?php foreach ($cartLines as $i => $cl) :
          // The priced line for this basket line (same product, option and offer).
          $l = null;
          foreach ($lines as $x) {
              if ($x['productId'] === $cl['productId'] && $x['variantId'] === ($cl['variantId'] ?? null) && $x['offerId'] === ($cl['offerId'] ?? null)) {
                  $l = $x;
              }
          }
          if (!$l) {
              continue;
          }
          $ln = lang() === 'ar' && $l['nameAr'] ? $l['nameAr'] : $l['name'];
          $vn = lang() === 'ar' && $l['variantNameAr'] ? $l['variantNameAr'] : $l['variantName'];
          $offer = lang() === 'ar' ? $l['offerLabelAr'] : $l['offerLabel'];
          ?>
        <li class="line">
          <?php if ($l['imageId']) : ?><img src="<?= e(Media::url($l['imageId'], 360)) ?>" alt="" width="76" height="76" loading="lazy"><?php endif ?>
          <div class="l-main">
            <a class="l-name" href="<?= e(url('/produit/' . rawurlencode($l['slug']))) ?>"><?= e($ln) ?></a>
            <div class="l-meta"><?= e(implode(' · ', array_filter([$vn, $offer]))) ?></div>
            <div><strong class="price"><?= e(Money::format($l['lineTotal'])) ?></strong><?php if (!$l['offerId'] && $l['qty'] > 1) : ?> <span class="l-meta"><?= e(t('cart.unit', Money::format($l['unitPrice']))) ?></span><?php endif ?></div>
            <?php if (!$l['offerId']) : ?>
              <form method="post" action="<?= e(url('/panier')) ?>">
                <?= Csrf::field() ?><input type="hidden" name="do" value="qty"><input type="hidden" name="i" value="<?= $i ?>">
                <select name="qty" aria-label="<?= e(t('buy.quantity')) ?>" data-autosubmit>
                  <?php for ($q = 1; $q <= Pricing::MAX_QTY_PER_LINE; $q++) : ?><option value="<?= $q ?>"<?= $q === $l['qty'] ? ' selected' : '' ?>><?= $q ?></option><?php endfor ?>
                </select>
                <noscript><button class="link-btn"><?= e(t('cart.update')) ?></button></noscript>
              </form>
            <?php endif ?>
            <form method="post" action="<?= e(url('/panier')) ?>">
              <?= Csrf::field() ?><input type="hidden" name="do" value="remove"><input type="hidden" name="i" value="<?= $i ?>">
              <button class="link-btn"><?= e(t('cart.remove')) ?></button>
            </form>
          </div>
        </li>
      <?php endforeach ?>
    </ul>

    <form method="post" action="<?= e(url('/commander')) ?>" novalidate data-cart-form>
      <input type="hidden" name="origin" value="cart">
      <?php
      $priceLabel = t('cart.items', array_sum(array_column($lines, 'qty')));
      $afterSubmit = '<a class="btn" href="' . e(url('/boutique')) . '">' . e(t('cart.keepShopping')) . '</a>';
      require ROOT . '/views/shop/_orderform.php';
      ?>
    </form>
  <?php endif ?>
</div>
