<?php
/** @var string $heading @var array $cards @var int $total @var string $sort @var int $pageNo @var int $pages @var string $path */
?>
<div class="wrap">
  <div class="listing-head">
    <div>
      <h1><?= e($heading) ?></h1>
      <p class="muted"><?= e(t('category.count', $total)) ?></p>
    </div>
    <form class="sort" method="get" action="<?= e(url($path)) ?>">
      <label><span class="muted"><?= e(t('category.sort')) ?></span>
        <select name="tri" data-autosubmit>
          <?php foreach (['new', 'price_asc', 'price_desc'] as $k) : ?>
            <option value="<?= $k ?>"<?= $k === $sort ? ' selected' : '' ?>><?= e(t('category.sorts.' . $k)) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <noscript><button class="btn">OK</button></noscript>
    </form>
  </div>
  <?php if (!$cards) : ?>
    <p class="muted"><?= e(t('category.empty')) ?></p>
  <?php else : ?>
    <div class="grid"><?php foreach ($cards as $c) { require ROOT . '/views/shop/_card.php'; } ?></div>
  <?php endif ?>
  <?php if ($pages > 1) : ?>
    <nav class="pager" aria-label="Pages">
      <?php for ($i = 1; $i <= $pages; $i++) : ?>
        <a class="btn<?= $i === $pageNo ? ' dark' : '' ?>" href="<?= e(url($path) . '?tri=' . $sort . '&page=' . $i) ?>"<?= $i === $pageNo ? ' aria-current="page"' : '' ?>><?= $i ?></a>
      <?php endfor ?>
    </nav>
  <?php endif ?>
</div>
