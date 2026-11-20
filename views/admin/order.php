<?php
/** @var array $o @var array $events @var array $calls @var ?array $customer @var array $history */
$flags = $o['flags'];
$next = Orders::TRANSITIONS[$o['status']] ?? [];
$wa = 'https://wa.me/216' . $o['phone'];
$outcomes = ['CONFIRMED' => 'Confirmée', 'NO_ANSWER' => 'Pas de réponse', 'CALL_BACK' => 'Rappeler', 'WRONG_NUMBER' => 'Mauvais numéro', 'CANCELLED_BY_CUSTOMER' => 'Annulée par le client'];
?>
<h1>Commande <?= e($o['ref']) ?> <span class="pill<?= $o['status'] === 'PENDING' ? ' warn' : '' ?>"><?= e(Orders::STATUS_LABELS[$o['status']] ?? $o['status']) ?></span></h1>
<div class="grid2">
  <div>
    <section class="cardx">
      <h2>Client</h2>
      <p><strong><?= e($o['name']) ?></strong><br>
        <a href="tel:+216<?= e($o['phone']) ?>"><?= e(Phone::format($o['phone'])) ?></a> · <a href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><br>
        <?= e($o['governorate']) ?> — <?= e($o['address']) ?><br>
        <span class="muted small">Passée le <?= e(View::time($o['createdAt'], 'd/m/Y H:i')) ?> · langue <?= e($o['locale']) ?> · source <?= e($o['source'] ?? 'direct') ?><?= $o['campaign'] ? ' / ' . e($o['campaign']) : '' ?></span></p>
      <p><?php require ROOT . '/views/admin/_flags.php'; ?></p>
      <?php if ($customer) : ?>
        <form method="post" class="actions"><?= Csrf::field() ?><input type="hidden" name="do" value="blacklist"><input type="hidden" name="on" value="<?= Db::bool($customer['blacklisted']) ? '0' : '1' ?>">
          <button class="btn small<?= Db::bool($customer['blacklisted']) ? '' : ' danger' ?>"><?= Db::bool($customer['blacklisted']) ? 'Retirer de la liste noire' : 'Mettre ce numéro en liste noire' ?></button></form>
      <?php endif ?>
    </section>
    <section class="cardx">
      <h2>Articles</h2>
      <div class="tablewrap"><table>
        <tr><th>Produit</th><th>Qté</th><th class="num">Prix</th></tr>
        <?php foreach ($o['items'] as $i) : ?>
          <tr><td><?php if ($i['productId']) : ?><a href="/admin/produits/<?= e($i['productId']) ?>"><?= e($i['name']) ?></a><?php else : ?><?= e($i['name']) ?><?php endif ?><br><span class="muted small"><?= e(implode(' · ', array_filter([$i['variantName'], $i['offerLabel']]))) ?></span></td><td><?= (int) $i['qty'] ?></td><td class="num"><?= e(Money::format((int) $i['lineTotal'], 'fr')) ?></td></tr>
        <?php endforeach ?>
        <tr><td colspan="2">Sous-total</td><td class="num"><?= e(Money::format((int) $o['subtotal'], 'fr')) ?></td></tr>
        <?php if ((int) $o['discount'] > 0) : ?><tr><td colspan="2">Réduction<?= $o['promoCodeText'] ? ' (' . e($o['promoCodeText']) . ')' : '' ?></td><td class="num">−<?= e(Money::format((int) $o['discount'], 'fr')) ?></td></tr><?php endif ?>
        <tr><td colspan="2">Livraison</td><td class="num"><?= e(Money::format((int) $o['deliveryFee'], 'fr')) ?></td></tr>
        <tr><td colspan="2"><strong>Total à encaisser</strong></td><td class="num"><strong><?= e(Money::format((int) $o['total'], 'fr')) ?></strong></td></tr>
        <?php if ($o['costTotal'] !== null) : ?><tr><td colspan="2" class="muted">Coût fournisseur</td><td class="num muted"><?= e(Money::format((int) $o['costTotal'], 'fr')) ?></td></tr><?php endif ?>
      </table></div>
    </section>
  </div>
  <div>
    <?php if ($next) : ?>
    <section class="cardx">
      <h2>Changer le statut</h2>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="do" value="status">
        <?php if (in_array('SHIPPED', $next, true)) : ?>
          <div class="row"><div><label for="carrier">Transporteur</label><input id="carrier" name="carrier" value="<?= e($o['carrier'] ?? '') ?>"></div><div><label for="tn">N° de suivi</label><input id="tn" name="trackingNumber" value="<?= e($o['trackingNumber'] ?? '') ?>"></div></div>
        <?php endif ?>
        <label for="note">Note (facultatif)</label><input id="note" name="note" maxlength="500">
        <div class="actions"><?php foreach ($next as $to) : ?><button class="btn<?= in_array($to, ['CANCELLED', 'REFUSED'], true) ? ' danger' : ' dark' ?>" name="to" value="<?= e($to) ?>"><?= e(Orders::STATUS_LABELS[$to]) ?></button><?php endforeach ?></div>
      </form>
    </section>
    <?php endif ?>
    <?php if ($o['status'] === 'PENDING') : ?>
    <section class="cardx">
      <h2>Appel de confirmation <span class="muted small">(<?= (int) $o['confirmAttempts'] ?> déjà)</span></h2>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="do" value="call">
        <div class="actions">
          <button class="btn small" name="outcome" value="NO_ANSWER">Pas de réponse</button>
          <button class="btn small" name="outcome" value="CALL_BACK">Rappeler plus tard</button>
          <button class="btn small danger" name="outcome" value="WRONG_NUMBER">Mauvais numéro</button>
        </div>
      </form>
    </section>
    <?php endif ?>
    <?php if (in_array($o['status'], ['SHIPPED', 'DELIVERED'], true)) : ?>
    <section class="cardx">
      <h2>Livraison</h2>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="do" value="shipping">
        <div class="row"><div><label for="c2">Transporteur</label><input id="c2" name="carrier" value="<?= e($o['carrier'] ?? '') ?>"></div><div><label for="t2">N° de suivi</label><input id="t2" name="trackingNumber" value="<?= e($o['trackingNumber'] ?? '') ?>"></div></div>
        <div class="actions"><button class="btn">Enregistrer</button></div>
      </form>
    </section>
    <?php endif ?>
    <section class="cardx">
      <h2>Historique</h2>
      <?php foreach ($events as $ev) : ?><p class="small"><?= e(View::time($ev['createdAt'], 'd/m H:i')) ?> · <?= e(Orders::STATUS_LABELS[$ev['to']] ?? $ev['to']) ?> · <span class="muted"><?= e($ev['by']) ?></span><?= $ev['note'] ? '<br>' . e($ev['note']) : '' ?></p><?php endforeach ?>
      <?php foreach ($calls as $c) : ?><p class="small muted"><?= e(View::time($c['createdAt'], 'd/m H:i')) ?> · appel : <?= e($outcomes[$c['outcome']] ?? $c['outcome']) ?></p><?php endforeach ?>
    </section>
    <?php if ($history) : ?>
    <section class="cardx">
      <h2>Autres commandes de ce numéro</h2>
      <?php foreach ($history as $h) : ?><p class="small"><a href="/admin/commandes/<?= e($h['id']) ?>"><?= e($h['ref']) ?></a> · <?= e(View::time($h['createdAt'], 'd/m/Y')) ?> · <?= e(Orders::STATUS_LABELS[$h['status']] ?? $h['status']) ?> · <?= e(Money::format((int) $h['total'], 'fr')) ?></p><?php endforeach ?>
    </section>
    <?php endif ?>
  </div>
</div>
