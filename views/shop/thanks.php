<?php
/** @var array $o (with 'token') */
$s = Settings::all();
$wa = preg_replace('/\D/', '', (string) $s['contact']['whatsapp']);
$shopPhone = Phone::normalize((string) $s['shop']['phone']);
$first = trim(explode(' ', trim($o['name']))[0] ?? '');
?>
<div class="wrap narrow">
  <section class="ok-box">
    <h1><?= e($first !== '' ? t('thanks.titleName', $first) : t('thanks.title')) ?></h1>
    <p><?= e(t('thanks.ref')) ?> : <span class="ref"><?= e($o['ref']) ?></span></p>
    <p><?= e(t('thanks.callingYou')) ?> <strong class="price"><?= e(Phone::format($o['phone'])) ?></strong>.<?php if ($shopPhone) : ?> <?= e(t('thanks.callFrom', Phone::format($shopPhone))) ?><?php endif ?></p>
  </section>
  <div class="actions">
    <?php if ($wa !== '') : ?><a class="btn wa" href="https://wa.me/216<?= e($wa) ?>?text=<?= rawurlencode(t('thanks.confirmText', $o['ref'])) ?>" target="_blank" rel="noopener"><?= e(t('thanks.confirmNow')) ?></a><?php endif ?>
    <a class="btn" href="<?= e(url('/suivi/t/' . $o['token'])) ?>"><?= e(t('thanks.track')) ?></a>
  </div>
  <h2 class="page-title"><?= e(t('track.items')) ?></h2>
  <?php require ROOT . '/views/shop/_items.php'; ?>
  <p><a class="btn dark" href="<?= e(url('/')) ?>"><?= e(t('thanks.keepShopping')) ?></a></p>
</div>
