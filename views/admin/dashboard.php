<?php /** @var array $today @var array $byStatus @var array $toConfirm @var array $lowStock */ ?>
<h1>Tableau de bord</h1>
<div class="stats">
  <div class="stat">Commandes aujourd'hui<b><?= (int) $today['n'] ?></b><span class="muted"><?= e(Money::format((int) $today['total'], 'fr')) ?></span></div>
  <div class="stat">À confirmer<b><?= (int) ($byStatus['PENDING'] ?? 0) ?></b></div>
  <div class="stat">À préparer / commander<b><?= (int) ($byStatus['CONFIRMED'] ?? 0) + (int) ($byStatus['SUPPLIER_ORDERED'] ?? 0) ?></b></div>
  <div class="stat">En livraison<b><?= (int) ($byStatus['SHIPPED'] ?? 0) ?></b></div>
</div>
<div class="grid2">
  <section class="cardx">
    <h2>À appeler en premier <a class="small" href="/admin/commandes?statut=PENDING">Tout voir</a></h2>
    <?php if (!$toConfirm) : ?><p class="muted">Aucune commande en attente.</p><?php endif ?>
    <?php foreach ($toConfirm as $o) : $flags = Db::parseArray($o['flags']); ?>
      <p><a href="/admin/commandes/<?= e($o['id']) ?>"><strong><?= e($o['name']) ?></strong> · <?= e(Phone::format($o['phone'])) ?></a><br>
      <span class="muted small"><?= e($o['ref']) ?> · <?= e($o['governorate']) ?> · <?= e(View::time($o['createdAt'])) ?><?= (int) $o['confirmAttempts'] > 0 ? ' · ' . (int) $o['confirmAttempts'] . ' appel(s)' : '' ?></span> <strong class="num"><?= e(Money::format((int) $o['total'], 'fr')) ?></strong><br>
      <?php require ROOT . '/views/admin/_flags.php'; ?></p>
    <?php endforeach ?>
  </section>
  <section class="cardx">
    <h2>Stock à surveiller</h2>
    <?php if (!$lowStock) : ?><p class="muted">Rien à signaler.</p><?php endif ?>
    <?php foreach ($lowStock as $p) : ?>
      <p><a href="/admin/produits/<?= e($p['id']) ?>"><?= e($p['name']) ?></a> <?= $p['stockMode'] === 'SUPPLIER' ? '<span class="pill danger">Indispo fournisseur</span>' : '<span class="pill ' . ((int) $p['stockQty'] === 0 ? 'danger' : 'warn') . '">' . (int) $p['stockQty'] . ' en stock</span>' ?></p>
    <?php endforeach ?>
  </section>
</div>
