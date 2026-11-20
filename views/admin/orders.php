<?php /** @var array $rows @var string $status @var string $q @var int $pageNo @var int $pages @var int $total */ ?>
<h1>Commandes <span class="muted small">(<?= $total ?>)</span></h1>
<form class="toolbar" method="get" action="/admin/commandes">
  <select name="statut" data-autosubmit>
    <option value="">Tous les statuts</option>
    <?php foreach (Orders::STATUS_LABELS as $k => $label) : ?><option value="<?= $k ?>"<?= $k === $status ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
  </select>
  <input name="q" value="<?= e($q) ?>" placeholder="Réf., nom ou téléphone">
  <button class="btn">Chercher</button>
</form>
<div class="tablewrap">
<table>
  <tr><th>Date</th><th>Réf.</th><th>Client</th><th>Gouvernorat</th><th>Statut</th><th class="num">Total</th></tr>
  <?php foreach ($rows as $o) : $flags = Db::parseArray($o['flags']); ?>
    <tr>
      <td class="small"><?= e(View::time($o['createdAt'])) ?></td>
      <td><a href="/admin/commandes/<?= e($o['id']) ?>"><?= e($o['ref']) ?></a></td>
      <td><?= e($o['name']) ?><br><span class="muted small"><?= e(Phone::format($o['phone'])) ?></span> <?php require ROOT . '/views/admin/_flags.php'; ?></td>
      <td><?= e($o['governorate']) ?></td>
      <td><span class="pill<?= $o['status'] === 'PENDING' ? ' warn' : '' ?>"><?= e(Orders::STATUS_LABELS[$o['status']] ?? $o['status']) ?></span></td>
      <td class="num"><?= e(Money::format((int) $o['total'], 'fr')) ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows) : ?><tr><td colspan="6" class="muted">Aucune commande.</td></tr><?php endif ?>
</table>
</div>
<?php if ($pages > 1) : ?><nav class="pager"><?php for ($i = 1; $i <= $pages; $i++) : ?><a class="btn small<?= $i === $pageNo ? ' dark' : '' ?>" href="/admin/commandes?<?= e(http_build_query(['statut' => $status, 'q' => $q, 'page' => $i])) ?>"><?= $i ?></a><?php endfor ?></nav><?php endif ?>
