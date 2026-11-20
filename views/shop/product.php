<?php
/**
 * @var array $p @var array $offers @var ?string $variantId @var ?string $offerId @var int $qty
 * @var array $quote @var array $related @var array $form @var bool $sellable
 */
$l = lang();
$name = Catalog::name($p);
$summary = Catalog::name($p, 'summary');
$description = Catalog::name($p, 'description');
$specs = spec_rows(Catalog::name($p, 'specs'));
$gallery = array_values(array_filter($p['images'], static fn ($i) => $i['kind'] === 'GALLERY'));
$details = array_values(array_filter($p['images'], static fn ($i) => $i['kind'] === 'DETAIL' && ($i['lang'] === null || $i['lang'] === $l)));
$s = Settings::all();
$span = Settings::span();
$fee = (int) $s['delivery']['fee'];
$feeText = $fee === 0 ? t('product.free') : t('product.deliveryFee', Money::format($fee));
$priceNow = $p['price'];
foreach ($p['variants'] as $v) {
    if ($v['id'] === $variantId && $v['price'] !== null) {
        $priceNow = $v['price'];
    }
}
$low = $p['stockMode'] === 'TRACKED' && !$p['variants'] && $p['stockQty'] > 0 && $p['stockQty'] <= 5 ? $p['stockQty'] : null;
$maxQty = $p['stockMode'] === 'TRACKED' && !$p['variants'] ? max(1, min(Pricing::MAX_QTY_PER_LINE, $p['stockQty'])) : Pricing::MAX_QTY_PER_LINE;
$productUrl = absolute_url(url('/produit/' . $p['slug']));
$waText = t('product.whatsappText', $name, null, Money::format($priceNow), $productUrl);
// What the page's script needs to show the totals while choosing (display only: the server prices the order).
$js = [
    'price' => $p['price'],
    'variants' => array_map(static fn ($v) => ['id' => $v['id'], 'price' => $v['price']], $p['variants']),
    'offers' => array_map(static fn ($o) => ['id' => $o['id'], 'quantity' => $o['quantity'], 'price' => $o['price'], 'freeDelivery' => $o['freeDelivery']], $offers),
    'fee' => $fee,
    'threshold' => $s['delivery']['freeThreshold'],
    'zones' => array_map(static fn ($z) => [$z['minDays'], max($z['minDays'], $z['maxDays']), $z['enabled']], $s['delivery']['zones']),
    'govNames' => array_combine(array_keys(Gov::LIST), array_map(static fn ($g) => Gov::label($g), array_keys(Gov::LIST))),
    'lang' => $l,
    'words' => ['free' => t('product.free'), 'deliveryTo' => t('buy.deliveryTo', '%g', '%r')],
];
$ld = [
    '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $name, 'sku' => $p['id'],
    'image' => array_map(static fn ($i) => absolute_url(Media::url($i['mediaId'], 1080)), array_slice($gallery, 0, 4)),
    'description' => mb_substr($summary !== '' ? $summary : $description, 0, 300),
    'offers' => ['@type' => 'Offer', 'priceCurrency' => 'TND', 'price' => number_format($priceNow / 1000, 3, '.', ''), 'availability' => $sellable ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'url' => $productUrl],
];
?>
<div class="wrap">
  <nav class="crumbs" aria-label="Fil d'Ariane">
    <a href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a>
    <?php if ($p['category']) : ?> › <a href="<?= e(url('/categorie/' . rawurlencode($p['category']['slug']))) ?>"><?= e(Catalog::name($p['category'])) ?></a><?php endif ?>
  </nav>

  <div class="product">
    <div class="gallery" data-gallery>
      <div class="main">
        <?php if ($gallery) : ?>
          <img src="<?= e(Media::url($gallery[0]['mediaId'], 1080)) ?>" srcset="<?= e(Media::srcset($gallery[0]['mediaId'])) ?>" sizes="(min-width: 900px) 600px, 100vw" alt="<?= e($gallery[0]['alt'] ?: $name) ?>" width="1080" height="1080" data-main fetchpriority="high">
        <?php endif ?>
      </div>
      <?php if (count($gallery) > 1) : ?>
        <div class="thumbs">
          <?php foreach ($gallery as $i => $img) : ?>
            <button type="button" data-thumb="<?= e($img['mediaId']) ?>" data-src="<?= e(Media::url($img['mediaId'], 1080)) ?>" data-srcset="<?= e(Media::srcset($img['mediaId'])) ?>" aria-label="Photo <?= $i + 1 ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>>
              <img src="<?= e(Media::url($img['mediaId'], 360)) ?>" alt="" loading="lazy" width="64" height="64">
            </button>
          <?php endforeach ?>
        </div>
      <?php endif ?>
    </div>

    <div class="buybox">
      <h1><?= e($name) ?></h1>
      <?php if ($summary !== '') : ?><p class="summary"><?= e($summary) ?></p><?php endif ?>
      <p class="price-line">
        <span class="now price" data-unit-price><?= e(Money::format($priceNow)) ?></span>
        <?php if ($p['compareAt'] !== null && $p['compareAt'] > $p['price']) : ?><span class="strike price"><?= e(Money::format($p['compareAt'])) ?></span><?php endif ?>
      </p>
      <?php if ($low !== null) : ?><p class="low"><?= e(t('product.low', $low)) ?></p><?php endif ?>

      <?php if (!$sellable) : ?>
        <p class="alert"><?= e(t('product.out')) ?> — <?= e(t('product.outSub')) ?></p>
        <?php if ($p['category']) : ?><p><a class="btn" href="<?= e(url('/categorie/' . rawurlencode($p['category']['slug']))) ?>"><?= e(t('product.outOthers')) ?></a></p><?php endif ?>
      <?php else : ?>
      <form method="post" action="<?= e(url('/commander')) ?>" data-product-form novalidate>
        <input type="hidden" name="origin" value="product">
        <input type="hidden" name="productId" value="<?= e($p['id']) ?>">

        <?php if ($p['variants']) : ?>
          <fieldset>
            <legend><?= e(t('product.choose')) ?></legend>
            <div class="chips">
              <?php foreach ($p['variants'] as $v) : $ok = Pricing::sellable($p, $v) > 0; ?>
                <label class="chip">
                  <input type="radio" name="variantId" value="<?= e($v['id']) ?>"<?= $v['id'] === $variantId ? ' checked' : '' ?><?= $ok ? '' : ' disabled' ?> data-variant>
                  <?php if ($v['colorHex']) : ?><span class="dot" style="background:<?= e(preg_match('/^#[0-9a-fA-F]{3,8}$/', $v['colorHex']) ? $v['colorHex'] : '#ccc') ?>"></span><?php endif ?>
                  <?= e(Catalog::name($v)) ?>
                </label>
              <?php endforeach ?>
            </div>
          </fieldset>
        <?php endif ?>

        <?php if ($offers) : ?>
          <fieldset>
            <legend><?= e(t('buy.step1')) ?></legend>
            <div class="offers">
              <?php foreach ($offers as $o) : $full = $p['price'] * $o['quantity']; $note = OfferLabel::note($o); ?>
                <label class="offer">
                  <input type="radio" name="offerId" value="<?= e($o['id']) ?>"<?= $o['id'] === $offerId ? ' checked' : '' ?> data-offer>
                  <span class="o-main">
                    <span class="o-title"><?= e(OfferLabel::title($o['quantity'])) ?></span>
                    <?php if ($note) : ?><br><span class="o-note"><?= e($note) ?></span><?php endif ?>
                    <?php if ($o['price'] < $full) : ?><br><span class="o-meta"><?= e(t('buy.save', Money::format($full - $o['price']))) ?></span><?php endif ?>
                    <?php if ($o['freeDelivery']) : ?><br><span class="o-free"><?= e(t('buy.freeDelivery')) ?></span><?php endif ?>
                    <?php if ($o['endsAt']) : ?><br><span class="o-meta"><?= e(t('buy.until', View::time($o['endsAt'], 'd/m/Y'))) ?></span><?php endif ?>
                  </span>
                  <span class="o-price">
                    <span class="price"><?= e(Money::format($o['price'])) ?></span>
                    <?php if ($o['price'] < $full) : ?><br><span class="strike price"><?= e(Money::format($full)) ?></span><?php endif ?>
                  </span>
                </label>
              <?php endforeach ?>
            </div>
          </fieldset>
        <?php else : ?>
          <fieldset class="qty">
            <legend><?= e(t('buy.quantity')) ?></legend>
            <select name="qty" data-qty aria-label="<?= e(t('buy.quantity')) ?>">
              <?php for ($i = 1; $i <= $maxQty; $i++) : ?><option value="<?= $i ?>"<?= $i === $qty ? ' selected' : '' ?>><?= $i ?></option><?php endfor ?>
            </select>
          </fieldset>
        <?php endif ?>

        <?php
        $priceLabel = t('buy.price');
        $afterSubmit = '<button type="submit" class="btn" formaction="' . e(url('/panier')) . '" formnovalidate name="do" value="add">' . e(t('product.addToCart')) . '</button>';
        require ROOT . '/views/shop/_orderform.php';
        ?>
      </form>
      <script type="application/json" id="product-data"><?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
      <?php endif ?>
    </div>
  </div>

  <div class="desc">
    <?php if ($p['videoUrl'] && preg_match('#(?:youtube\.com/(?:watch\?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,20})#', $p['videoUrl'], $yt)) : ?>
      <section class="box"><h2><?= e(t('product.video')) ?></h2><div class="video"><iframe src="https://www.youtube-nocookie.com/embed/<?= e($yt[1]) ?>" title="<?= e($name) ?>" loading="lazy" allowfullscreen></iframe></div></section>
    <?php elseif ($p['videoUrl'] && preg_match('#^https://[^\s]+\.mp4(\?.*)?$#i', $p['videoUrl'])) : ?>
      <section class="box"><h2><?= e(t('product.video')) ?></h2><div class="video"><video src="<?= e($p['videoUrl']) ?>" controls preload="metadata" playsinline></video></div></section>
    <?php endif ?>
    <?php if (trim($description) !== '') : ?>
      <section class="box"><h2><?= e(t('product.description')) ?></h2><p><?= e($description) ?></p></section>
    <?php endif ?>
    <?php if ($specs) : ?>
      <section class="box"><h2><?= e(t('product.specs')) ?></h2>
        <table class="specs"><?php foreach ($specs as [$k, $val]) : ?><tr><th scope="row"><?= e($k) ?></th><td><?= e($val) ?></td></tr><?php endforeach ?></table>
      </section>
    <?php endif ?>
    <?php if ($details) : ?>
      <section class="box details"><h2><?= e(t('product.detailsTitle')) ?></h2>
        <?php foreach ($details as $d) : ?><img src="<?= e(Media::url($d['mediaId'], 1080)) ?>" srcset="<?= e(Media::srcset($d['mediaId'])) ?>" sizes="(min-width: 800px) 760px, 100vw" alt="<?= e($d['alt'] ?: $name) ?>" loading="lazy"><?php endforeach ?>
      </section>
    <?php endif ?>
    <section class="box faq">
      <h2><?= e(t('product.faqTitle')) ?></h2>
      <?php if ($span) : ?>
        <details open><summary><?= e(t('product.whenQ')) ?></summary><p><?= e(t('product.whenA', Settings::rangeLabel($span['minDays'], $span['maxDays']), $feeText, $s['delivery']['freeThreshold'] !== null ? Money::format($s['delivery']['freeThreshold']) : null)) ?></p></details>
      <?php endif ?>
      <details><summary><?= e(t('product.payQ')) ?></summary><p><?= e(t('product.payA')) ?><?= $s['delivery']['openBeforePaying'] ? ' ' . e(t('product.openA')) : '' ?></p></details>
      <details><summary><?= e(t('product.callQ')) ?></summary><p><?= e(t('product.callA')) ?></p></details>
      <details><summary><?= e(t('product.returnQ')) ?></summary><p><?= e(t('product.returnA', (int) $s['returns']['days'])) ?></p></details>
    </section>
  </div>

  <?php if ($related) : ?>
  <section class="section" aria-labelledby="h-rel">
    <div class="section-head"><h2 id="h-rel"><?= e(t('product.related')) ?></h2></div>
    <div class="grid"><?php foreach ($related as $c) { require ROOT . '/views/shop/_card.php'; } ?></div>
  </section>
  <?php endif ?>
</div>
<script type="application/ld+json"><?= json_encode($ld, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
