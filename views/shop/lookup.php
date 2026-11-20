<?php /** @var ?string $error */ ?>
<div class="wrap narrow">
  <h1 class="page-title"><?= e(t('track.title')) ?></h1>
  <form method="post" action="<?= e(url('/suivi')) ?>" class="card-box lookup">
    <?= Csrf::field() ?>
    <div class="field"><label for="t-ref"><?= e(t('track.ref')) ?></label><input id="t-ref" name="ref" placeholder="MN-XXXXXX" autocapitalize="characters" required maxlength="12" dir="ltr" value="<?= e(post_str('ref', 12)) ?>"></div>
    <div class="field"><label for="t-phone"><?= e(t('track.phone')) ?></label><div class="tel"><span>+216</span><input id="t-phone" name="phone" type="tel" inputmode="tel" required maxlength="16" value="<?= e(post_str('phone', 16)) ?>"></div></div>
    <?php if ($error) : ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif ?>
    <button class="btn dark"><?= e(t('track.submit')) ?></button>
  </form>
</div>
