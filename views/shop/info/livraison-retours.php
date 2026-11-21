<?php
/** Delivery and returns, built from the delivery settings (the fee and times the checkout uses). @var array $s @var bool $freeOffers */
$ar = lang() === 'ar';
$sep = $ar ? '، ' : ', ';
$zones = $s['delivery']['zones'];
$everywhere = Gov::everywhere($zones);
$on = array_values(array_filter(array_keys(Gov::LIST), static fn ($g) => ($zones[$g]['enabled'] ?? true) !== false));
$off = array_values(array_filter(array_keys(Gov::LIST), static fn ($g) => ($zones[$g]['enabled'] ?? true) === false));
$groups = [];
foreach ($on as $g) {
    $min = (int) ($zones[$g]['minDays'] ?? 0);
    $max = max($min, (int) ($zones[$g]['maxDays'] ?? 0));
    $groups["$min-$max"] ??= ['min' => $min, 'max' => $max, 'ids' => []];
    $groups["$min-$max"]['ids'][] = $g;
}
$largest = max(0, ...array_map(static fn ($r) => count($r['ids']), array_values($groups) ?: [['ids' => []]]));
$rows = array_values($groups);
$keyed = array_map(null, $rows, array_keys($rows));
usort($keyed, static fn ($a, $b) => ((int) (count($a[0]['ids']) === $largest) - (int) (count($b[0]['ids']) === $largest)) ?: ($a[0]['min'] - $b[0]['min']) ?: $a[1] <=> $b[1]);
$rows = array_column($keyed, 0);
$who = static fn ($r) => count($rows) === 1 && !$off ? ($ar ? 'كل الولايات' : 'Tous les gouvernorats') : (count($r['ids']) === $largest && count($r['ids']) > 6 ? ($ar ? 'باقي الولايات' : 'Autres gouvernorats') : implode($sep, array_map(static fn ($id) => Gov::label($id), $r['ids'])));
$d = $s['returns']['days'];
$fee = (int) $s['delivery']['fee'];
ob_start();
?>
<section class="rounded-[20px] border border-line"><div class="px-5 py-5"><p class="text-[16px] text-muted"><?= e(t('product.deliveryFee')) ?></p><p class="mt-1 whitespace-nowrap text-[30px] font-bold leading-none text-ink price-nums ltr-nums"><?= e($fee === 0 ? t('product.free') : Ui::money($fee)) ?></p><p class="mt-2 text-[16px] text-ink-2"><?= e($everywhere ? ($ar ? 'نفس السوم لكل الولايات' : 'Le même prix dans tous les gouvernorats') : ($ar ? 'نفس السوم لكل الولايات اللي نوصلولها' : 'Le même prix dans tous les gouvernorats desservis')) ?></p><?php if ($fee > 0 && $freeOffers) : ?><p class="mt-1 text-[16px] font-bold text-olive"><?= e($ar ? 'ومجاني في العروض اللي مكتوب عليها «التوصيل مجاني»' : 'Offerte sur les offres marquées « Livraison offerte »') ?></p><?php endif ?><?php if ($s['delivery']['freeThreshold'] !== null) : ?><p class="mt-1 text-[16px] font-bold text-olive"><?= e(t('product.freeFrom', Ui::money((int) $s['delivery']['freeThreshold']))) ?></p><?php endif ?></div>
<div class="flex flex-col gap-3 border-t border-line px-5 py-4 text-[16px] leading-snug text-ink-2"><p class="flex items-start gap-3"><?= icon('Cash', 'mt-0.5 h-5 w-5 shrink-0 text-sea') ?><?= e($ar ? 'تخلّص كاش كي تستلم' : 'Paiement en espèces à la livraison') ?></p><p class="flex items-start gap-3"><?= icon('Phone', 'mt-0.5 h-5 w-5 shrink-0 text-sea') ?><?= e($ar ? 'نكلموك نأكدو قبل ما نبعثو' : "Nous vous appelons pour confirmer avant l'envoi") ?></p><?php if ($s['delivery']['openBeforePaying']) : ?><p class="flex items-start gap-3"><?= icon('Shield', 'mt-0.5 h-5 w-5 shrink-0 text-sea') ?><?= e(t('trust.openBeforePaying')) ?></p><?php endif ?></div></section>
<h2><?= e($ar ? 'مدة التوصيل' : 'Délais de livraison') ?></h2>
<div class="divide-y divide-line rounded-[20px] border border-line"><?php foreach ($rows as $r) : ?><div class="flex flex-col gap-0.5 px-5 py-4 md:flex-row md:items-baseline md:justify-between md:gap-6"><span class="text-[17px] font-bold leading-snug text-ink"><?= e($who($r)) ?></span><span class="text-[16px] text-ink-2 md:shrink-0"><?= e(Settings::rangeLabel($r['min'], $r['max'])) ?></span></div><?php endforeach ?></div>
<?php if ($off) : ?><p class="text-[16px] text-muted"><?= e($ar ? 'ما نوصلوش توّا لـ: ' : 'Pas encore livrés : ') ?><?= e(implode($sep, array_map(static fn ($g) => Gov::label($g), $off))) ?></p><?php endif ?>
<p class="text-[15px] text-muted"><?= e($ar ? 'تتحسب من نهار تأكيد الطلبية.' : 'À compter de la confirmation de la commande.') ?></p>
<h2><?= e($ar ? 'الإرجاع' : 'Retours') ?></h2>
<p><?= e($ar ? "ترجّع المنتوج خلال $d " . ($d > 10 ? 'يوم' : 'أيام') . ' من الاستلام، في حالتو وفي الكرتونة متاعو.' : "Retour possible sous $d jours après réception, produit intact et dans son emballage.") ?></p>
<a href="<?= e(url('/suivi')) ?>" class="inline-flex min-h-12 w-fit items-center gap-2 rounded-full border border-ink/60 bg-white px-6 text-[17px] font-bold text-ink hover:bg-sand"><?= icon('Return', 'h-5 w-5') ?><?= e(t('track.returnTitle')) ?></a>
<?= PageUi::prose(t('footer.deliveryReturns'), (string) ob_get_clean()) ?>
