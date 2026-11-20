<?php
/** @var array $o */
$steps = ['PENDING', 'CONFIRMED', 'SUPPLIER_ORDERED', 'SHIPPED', 'DELIVERED'];
$at = array_search($o['status'], $steps, true);
$names = Lang::list('track.timeline');
$wa = preg_replace('/\D/', '', (string) Settings::get('contact.whatsapp', ''));
?>
<div class="wrap narrow">
  <h1 class="page-title"><?= e(t('track.title')) ?> <span class="ref"><?= e($o['ref']) ?></span></h1>
  <p><strong><?= e(Lang::dict(lang())['track']['status'][$o['status']] ?? $o['status']) ?></strong></p>
  <?php if ($at !== false) : ?>
    <ol class="timeline"><?php foreach ($steps as $i => $st) : ?><li class="<?= $i <= $at ? 'done' : '' ?>"><?= e($names[$i] ?? $st) ?></li><?php endforeach ?></ol>
  <?php endif ?>
  <?php if ($o['carrier'] || $o['trackingNumber']) : ?>
    <p class="muted"><?php if ($o['carrier']) : ?><?= e(t('track.carrier')) ?> : <?= e($o['carrier']) ?><?php endif ?><?php if ($o['trackingNumber']) : ?> · <?= e(t('track.tracking')) ?> : <span class="price"><?= e($o['trackingNumber']) ?></span><?php endif ?></p>
  <?php endif ?>
  <p class="muted"><?= e(t('track.deliveryTo')) ?> : <?= e($o['name']) ?>, <?= e(Gov::label($o['governorate'])) ?> — <?= e($o['address']) ?></p>
  <?php require ROOT . '/views/shop/_items.php'; ?>
  <?php if ($wa !== '') : ?><p><a class="btn wa" href="https://wa.me/216<?= e($wa) ?>?text=<?= rawurlencode($o['ref']) ?>" target="_blank" rel="noopener"><?= e(t('track.help')) ?></a></p><?php endif ?>
</div>
