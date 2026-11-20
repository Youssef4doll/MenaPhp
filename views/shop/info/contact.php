<?php
/** @var array $s @var string $heading */
$ar = lang() === 'ar';
$wa = preg_replace('/\D/', '', (string) $s['contact']['whatsapp']);
$tel = Phone::normalize((string) $s['shop']['phone']);
$shop = $s['shop'];
?>
<div class="wrap narrow">
  <h1 class="page-title"><?= e($heading) ?></h1>
  <div class="prose">
    <div class="actions">
      <?php if ($wa !== '') : ?><a class="btn dark" href="https://wa.me/216<?= e($wa) ?>" target="_blank" rel="noopener"><?= e($ar ? 'ابعثلنا على واتساب' : 'Écrivez-nous sur WhatsApp') ?></a><?php endif ?>
      <?php if ($tel) : ?><a class="btn" href="tel:+216<?= e($tel) ?>"><span class="price"><?= e(Phone::format($tel)) ?></span></a><?php endif ?>
    </div>
    <?php if ($shop['legalName'] || $shop['legalId']) : ?>
      <h2><?= e($ar ? 'البائع' : 'Vendeur') ?></h2>
      <p><?= e($shop['legalName'] ?: $shop['name']) ?><?php if ($shop['legalId']) : ?><br><?= e($ar ? 'المعرّف الجبائي' : 'Matricule fiscal') ?> <span class="price"><?= e($shop['legalId']) ?></span><?php endif ?></p>
    <?php endif ?>
    <?php if ($shop['email']) : ?><h2><?= e($ar ? 'الإيميل' : 'E-mail') ?></h2><p><a href="mailto:<?= e($shop['email']) ?>" dir="ltr"><?= e($shop['email']) ?></a></p><?php endif ?>
    <?php if ($shop['address']) : ?><h2><?= e($ar ? 'العنوان' : 'Adresse') ?></h2><p><?= e($shop['address']) ?></p><?php endif ?>
    <p><a href="<?= e(url('/suivi')) ?>"><?= e(t('nav.track')) ?></a></p>
  </div>
</div>
