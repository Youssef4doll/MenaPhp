<?php
/** @var array $s @var bool $freeOffers @var string $heading */
$ar = lang() === 'ar';
$sep = $ar ? '، ' : ', ';
$zones = $s['delivery']['zones'];
$on = array_filter(array_keys(Gov::LIST), static fn ($g) => $zones[$g]['enabled']);
$off = array_filter(array_keys(Gov::LIST), static fn ($g) => !$zones[$g]['enabled']);
// The delivery times, by group of governorates with the same range: a few lines instead of 24.
$groups = [];
foreach ($on as $g) {
    $min = $zones[$g]['minDays'];
    $max = max($min, $zones[$g]['maxDays']);
    $groups["$min-$max"] ??= ['min' => $min, 'max' => $max, 'ids' => []];
    $groups["$min-$max"]['ids'][] = $g;
}
$largest = $groups ? max(array_map(static fn ($r) => count($r['ids']), $groups)) : 0;
usort($groups, static fn ($a, $b) => (count($a['ids']) === $largest) <=> (count($b['ids']) === $largest) ?: $a['min'] <=> $b['min']);
$who = static function (array $r) use ($groups, $off, $largest, $ar, $sep): string {
    if (count($groups) === 1 && !$off) {
        return $ar ? 'كل الولايات' : 'Tous les gouvernorats';
    }
    if (count($r['ids']) === $largest && count($r['ids']) > 6) {
        return $ar ? 'باقي الولايات' : 'Autres gouvernorats';
    }
    return implode($sep, array_map(static fn ($id) => Gov::label($id), $r['ids']));
};
$fee = (int) $s['delivery']['fee'];
$d = (int) $s['returns']['days'];
?>
<div class="wrap narrow">
  <h1 class="page-title"><?= e($heading) ?></h1>
  <div class="prose">
    <p class="muted"><?= e($ar ? 'معلوم التوصيل' : 'Frais de livraison') ?></p>
    <p style="font-size:30px;font-weight:800;margin:0" class="price"><?= e($fee === 0 ? t('product.free') : Money::format($fee)) ?></p>
    <p><?= e(Gov::everywhere($zones) ? ($ar ? 'نفس السوم لكل الولايات' : 'Le même prix dans tous les gouvernorats') : ($ar ? 'نفس السوم لكل الولايات اللي نوصلولها' : 'Le même prix dans tous les gouvernorats desservis')) ?></p>
    <?php if ($fee > 0 && $freeOffers) : ?><p><strong><?= e($ar ? 'ومجاني في العروض اللي مكتوب عليها «التوصيل مجاني»' : 'Offerte sur les offres marquées « Livraison offerte »') ?></strong></p><?php endif ?>
    <?php if ($s['delivery']['freeThreshold'] !== null) : ?><p><strong><?= e(t('product.freeFrom', Money::format((int) $s['delivery']['freeThreshold']))) ?></strong></p><?php endif ?>
    <ul>
      <li><?= e($ar ? 'تخلّص كاش كي تستلم' : 'Paiement en espèces à la livraison') ?></li>
      <li><?= e($ar ? 'نكلموك نأكدو قبل ما نبعثو' : "Nous vous appelons pour confirmer avant l'envoi") ?></li>
      <?php if ($s['delivery']['openBeforePaying']) : ?><li><?= e(t('trust.openBeforePaying')) ?></li><?php endif ?>
    </ul>

    <h2><?= e($ar ? 'مدة التوصيل' : 'Délais de livraison') ?></h2>
    <table class="specs">
      <?php foreach ($groups as $r) : ?><tr><th scope="row"><?= e($who($r)) ?></th><td><?= e(Settings::rangeLabel($r['min'], $r['max'])) ?></td></tr><?php endforeach ?>
    </table>
    <?php if ($off) : ?><p class="muted"><?= e(($ar ? 'ما نوصلوش توّا لـ: ' : 'Pas encore livrés : ') . implode($sep, array_map(static fn ($g) => Gov::label($g), $off))) ?></p><?php endif ?>
    <p class="muted small"><?= e($ar ? 'تتحسب من نهار تأكيد الطلبية.' : 'À compter de la confirmation de la commande.') ?></p>

    <h2><?= e($ar ? 'الإرجاع' : 'Retours') ?></h2>
    <p><?= e($ar ? "ترجّع المنتوج خلال $d " . ($d > 10 ? 'يوم' : 'أيام') . ' من الاستلام، في حالتو وفي الكرتونة متاعو.' : "Retour possible sous $d jours après réception, produit intact et dans son emballage.") ?></p>
  </div>
</div>
