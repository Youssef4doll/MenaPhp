<?php
/** The lines and totals of a placed order. @var array $o */
$l = lang();
?>
<ul class="lines">
  <?php foreach ($o['items'] as $i) : $offer = $l === 'ar' ? ($i['offerLabelAr'] ?: $i['offerLabel']) : $i['offerLabel']; ?>
    <li class="line">
      <div class="l-main">
        <span class="l-name"><?= e($i['name']) ?></span>
        <div class="l-meta"><?= e(implode(' · ', array_filter([$i['variantName'], $offer, $offer ? null : '× ' . $i['qty']]))) ?></div>
      </div>
      <strong class="price"><?= e(Money::format((int) $i['lineTotal'])) ?></strong>
    </li>
  <?php endforeach ?>
</ul>
<dl class="totals">
  <div><dt><?= e(t('buy.price')) ?></dt><dd class="price"><?= e(Money::format((int) $o['subtotal'])) ?></dd></div>
  <?php if ((int) $o['discount'] > 0) : ?><div><dt><?= e(t('buy.discount')) ?></dt><dd class="price">−<?= e(Money::format((int) $o['discount'])) ?></dd></div><?php endif ?>
  <div><dt><?= e(t('buy.delivery')) ?></dt><dd class="price"><?= (int) $o['deliveryFee'] === 0 ? '<span class="free">' . e(t('product.free')) . '</span>' : e(Money::format((int) $o['deliveryFee'])) ?></dd></div>
  <div class="sum"><dt><?= e(t('track.toPay')) ?></dt><dd class="price"><?= e(Money::format((int) $o['total'])) ?></dd></div>
</dl>
