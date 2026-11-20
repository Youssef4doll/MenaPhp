<?php /** @var array $rows @var string $q @var int $pageNo @var int $pages @var int $total */ ?>
<h1>Produits <span class="muted small">(<?= $total ?>)</span></h1>
<form class="toolbar" method="get" action="/admin/produits">
  <input name="q" value="<?= e($q) ?>" placeholder="Nom ou adresse">
  <button class="btn">Chercher</button>
  <a class="btn dark" href="/admin/produits/nouveau">Nouveau produit</a>
</form>
<div class="tablewrap">
<table>
  <tr><th></th><th>Produit</th><th>Catégorie</th><th>Stock</th><th>État</th><th class="num">Prix</th></tr>
  <?php foreach ($rows as $p) : ?>
    <tr>
      <td><?php if ($p['imageId']) : ?><img src="<?= e(Media::url($p['imageId'], 360)) ?>" alt="" width="48" height="48" style="border-radius:8px;object-fit:cover"><?php endif ?></td>
      <td><a href="/admin/produits/<?= e($p['id']) ?>"><?= e($p['name']) ?></a></td>
      <td class="muted"><?= e($p['category'] ?? '—') ?></td>
      <td><?= $p['stockMode'] === 'TRACKED' ? (int) $p['stockQty'] . ' en stock' : (Db::bool($p['available']) ? 'Fournisseur' : '<span class="pill danger">Indispo</span>') ?></td>
      <td><?= Db::bool($p['active']) ? '<span class="pill ok">En ligne</span>' : '<span class="pill">Caché</span>' ?><?= Db::bool($p['featured']) ? ' <span class="pill">Sélection</span>' : '' ?></td>
      <td class="num"><?= e(Money::format((int) $p['price'], 'fr')) ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows) : ?><tr><td colspan="6" class="muted">Aucun produit.</td></tr><?php endif ?>
</table>
</div>
<?php if ($pages > 1) : ?><nav class="pager"><?php for ($i = 1; $i <= $pages; $i++) : ?><a class="btn small<?= $i === $pageNo ? ' dark' : '' ?>" href="/admin/produits?<?= e(http_build_query(['q' => $q, 'page' => $i])) ?>"><?= $i ?></a><?php endfor ?></nav><?php endif ?>
