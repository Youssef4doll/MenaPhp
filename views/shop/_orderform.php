<?php
/**
 * The delivery fields and the totals in one card, then the button. Printed
 * inside a <form> by the product page and the basket.
 * @var array $quote (Pricing::quote) @var array $form (what was typed, errors) @var string $priceLabel
 */
$bad = $form['bad'] ?? [];
$err = $form['error'] ?? null;
$v = static fn (string $k) => (string) ($form[$k] ?? '');
$s = Settings::all();
$fee = $quote['deliveryFee'];
$free = $quote['lines'] && $fee === 0 && !$quote['deliveryUnavailable'];
$gov = $v('governorate');
$messages = [
    'name' => t('buy.nameError'),
    'phone' => trim($v('phone')) === '' ? t('buy.phoneRequired') : t('buy.phoneInvalid'),
    'governorate' => t('buy.govError'),
    'address' => trim($v('address')) === '' ? t('buy.addressError') : t('buy.addressShort'),
];
$field = static function (string $k) use ($bad, $messages): string {
    return in_array($k, $bad, true) ? '<p class="err" id="err-' . $k . '">' . e($messages[$k]) . '</p>' : '';
};
$shopPhone = Phone::normalize((string) $s['shop']['phone']);
$wa = preg_replace('/\D/', '', (string) $s['contact']['whatsapp']);
?>
<input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
<input type="hidden" name="submitKey" value="<?= e(Tokens::random(18)) ?>">
<input type="hidden" name="expectedTotal" value="<?= (int) ($form['shownTotal'] ?? $quote['total']) ?>" data-expected>
<?= Csrf::field() ?>

<section class="card-box" aria-labelledby="h-info">
  <h3 id="h-info"><?= e(t('buy.deliveryInfo')) ?></h3>
  <div class="fields">
    <div class="field wide<?= in_array('name', $bad, true) ? ' bad' : '' ?>">
      <label for="f-name"><?= e(t('buy.name')) ?><span class="star" aria-hidden="true">*</span></label>
      <input id="f-name" name="name" value="<?= e($v('name')) ?>" autocomplete="name" required maxlength="120"<?= in_array('name', $bad, true) ? ' aria-invalid="true" aria-describedby="err-name"' : '' ?>>
      <?= $field('name') ?>
    </div>
    <div class="field<?= in_array('phone', $bad, true) ? ' bad' : '' ?>">
      <label for="f-phone"><?= e(t('buy.phone')) ?><span class="star" aria-hidden="true">*</span></label>
      <div class="tel"><span>+216</span><input id="f-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" placeholder="22 123 456" value="<?= e($v('phone')) ?>" required maxlength="16"<?= in_array('phone', $bad, true) ? ' aria-invalid="true" aria-describedby="err-phone"' : '' ?>></div>
      <?= $field('phone') ?>
    </div>
    <div class="field<?= in_array('governorate', $bad, true) ? ' bad' : '' ?>">
      <label for="f-gov"><?= e(t('buy.governorate')) ?><span class="star" aria-hidden="true">*</span></label>
      <select id="f-gov" name="governorate" required data-gov<?= in_array('governorate', $bad, true) ? ' aria-invalid="true" aria-describedby="err-governorate"' : '' ?>>
        <option value=""><?= e(t('buy.chooseGov')) ?></option>
        <?php foreach (array_keys(Gov::LIST) as $g) : ?>
          <option value="<?= e($g) ?>"<?= $g === $gov ? ' selected' : '' ?><?= $s['delivery']['zones'][$g]['enabled'] ? '' : ' disabled' ?>><?= e(Gov::label($g)) ?></option>
        <?php endforeach ?>
      </select>
      <?= $field('governorate') ?>
    </div>
    <div class="field wide<?= in_array('address', $bad, true) ? ' bad' : '' ?>">
      <label for="f-address"><?= e(t('buy.address')) ?><span class="star" aria-hidden="true">*</span></label>
      <textarea id="f-address" name="address" rows="1" autocomplete="street-address" placeholder="<?= e(t('buy.addressPh')) ?>" required maxlength="500"<?= in_array('address', $bad, true) ? ' aria-invalid="true" aria-describedby="err-address"' : '' ?>><?= e($v('address')) ?></textarea>
      <?= $field('address') ?>
    </div>
  </div>

  <!-- The totals in the same card as the fields. -->
  <dl class="totals" data-totals>
    <div><dt><?= e($priceLabel) ?></dt><dd class="price" data-subtotal><?= e(Money::format($quote['subtotal'])) ?></dd></div>
    <div><dt><?= e(t('buy.delivery')) ?></dt><dd class="price" data-fee><?= $free ? '<span class="free">' . e(t('product.free')) . '</span>' : e(Money::format($fee)) ?></dd></div>
    <div class="sum"><dt><?= e(t('buy.total')) ?></dt><dd class="price" data-total><?= e(Money::format($quote['total'])) ?></dd></div>
    <p data-delay><?= $quote['delivery'] && $gov !== '' ? e(t('buy.deliveryTo', Gov::label($gov), Settings::rangeLabel($quote['delivery']['minDays'], $quote['delivery']['maxDays']))) : '' ?></p>
    <?php if ($quote['deliveryUnavailable']) : ?><p class="err"><?= e(t('errors.delivery_unavailable')) ?></p><?php endif ?>
    <?php if (!$free && $s['delivery']['freeThreshold'] !== null) : ?><p><?= e(t('product.freeFrom', Money::format($s['delivery']['freeThreshold']))) ?></p><?php endif ?>
  </dl>
</section>

<?php if ($err) : ?><p class="alert" role="alert"><?= e(t('errors.' . $err)) ?></p><?php endif ?>
<?php if ($s['delivery']['openBeforePaying']) : ?><p class="promise"><?= e(t('trust.openBeforePaying')) ?></p><?php endif ?>

<div class="actions">
  <button type="submit" class="btn buy" data-submit>
    <span><?= e(t('buy.submit')) ?></span>
    <span class="pill price" data-total><?= e(Money::format($quote['total'])) ?></span>
  </button>
  <?= $afterSubmit ?? '' ?>
</div>
<p class="terms"><?= e(t('buy.consentTerms')) ?> <a href="<?= e(url('/conditions')) ?>"><?= e(t('buy.termsLink')) ?></a>.</p>
<?php if ($wa !== '' || $shopPhone) : ?>
  <div class="contact-row">
    <?php if ($wa !== '') : ?><a class="btn wa" href="https://wa.me/216<?= e($wa) ?>?text=<?= rawurlencode($waText ?? '') ?>" target="_blank" rel="noopener"><?= e(t('buy.whatsapp')) ?></a><?php endif ?>
    <?php if ($shopPhone) : ?><a class="btn wa" href="tel:+216<?= e($shopPhone) ?>"><?= e(t('buy.callUs')) ?> · <span class="price"><?= e(Phone::format($shopPhone)) ?></span></a><?php endif ?>
  </div>
<?php endif ?>
