<?php
/** @var array $p @var array $images @var array $offers @var array $variants @var array $categories @var ?string $supplier */
$b = static fn ($k) => Db::bool($p[$k]);
$gallery = array_values(array_filter($images, static fn ($i) => $i['kind'] === 'GALLERY'));
$details = array_values(array_filter($images, static fn ($i) => $i['kind'] === 'DETAIL'));
$photoRow = static function (array $list) {
    foreach ($list as $img) {
        echo '<div class="thumb"><img src="' . e(Media::url($img['mediaId'], 360)) . '" alt="" loading="lazy">';
        if ($img['lang']) {
            echo '<div class="small muted">page ' . e($img['lang']) . ' seulement</div>';
        }
        foreach (['up' => '◀', 'down' => '▶'] as $dir => $sym) {
            echo '<form method="post">' . Csrf::field() . '<input type="hidden" name="do" value="photoMove"><input type="hidden" name="imageId" value="' . e($img['id']) . '"><input type="hidden" name="dir" value="' . $dir . '"><button class="btn small" aria-label="Déplacer">' . $sym . '</button></form> ';
        }
        echo '<form method="post">' . Csrf::field() . '<input type="hidden" name="do" value="photoDelete"><input type="hidden" name="imageId" value="' . e($img['id']) . '"><button class="btn small danger">Retirer</button></form></div>';
    }
};
?>
<h1><?= e($p['name']) ?> <?= $b('active') ? '<span class="pill ok">En ligne</span>' : '<span class="pill">Caché</span>' ?></h1>
<p class="actions"><?php if ($b('active')) : ?><a class="btn small" href="/produit/<?= e(rawurlencode($p['slug'])) ?>" target="_blank" rel="noopener">Voir la page</a><?php endif ?></p>

<form class="cardx" method="post">
  <?= Csrf::field() ?><input type="hidden" name="do" value="save">
  <h2>Fiche</h2>
  <div class="row">
    <div><label for="name">Nom (français)</label><input id="name" name="name" value="<?= e($p['name']) ?>" required maxlength="200"></div>
    <div><label for="nameAr">Nom (arabe)</label><input id="nameAr" name="nameAr" dir="rtl" value="<?= e($p['nameAr'] ?? '') ?>" maxlength="200"></div>
  </div>
  <div class="row">
    <div><label for="summary">Résumé (français)</label><input id="summary" name="summary" value="<?= e($p['summary']) ?>" maxlength="400"></div>
    <div><label for="summaryAr">Résumé (arabe)</label><input id="summaryAr" name="summaryAr" dir="rtl" value="<?= e($p['summaryAr'] ?? '') ?>" maxlength="400"></div>
  </div>
  <div class="row">
    <div><label for="description">Description (français)</label><textarea id="description" name="description" rows="6"><?= e($p['description']) ?></textarea></div>
    <div><label for="descriptionAr">Description (arabe)</label><textarea id="descriptionAr" name="descriptionAr" dir="rtl" rows="6"><?= e($p['descriptionAr'] ?? '') ?></textarea></div>
  </div>
  <div class="row">
    <div><label for="specs">Caractéristiques (une par ligne : « Matière : inox »)</label><textarea id="specs" name="specs" rows="4"><?= e($p['specs']) ?></textarea></div>
    <div><label for="specsAr">Caractéristiques (arabe)</label><textarea id="specsAr" name="specsAr" dir="rtl" rows="4"><?= e($p['specsAr'] ?? '') ?></textarea></div>
  </div>
  <div class="row">
    <div><label for="price">Prix (DT)</label><input id="price" name="price" inputmode="decimal" value="<?= e(Money::input((int) $p['price'])) ?>" required></div>
    <div><label for="compareAt">Ancien prix réel (barré)</label><input id="compareAt" name="compareAt" inputmode="decimal" value="<?= e(Money::input($p['compareAt'] === null ? null : (int) $p['compareAt'])) ?>"></div>
    <div><label for="cost">Coût fournisseur (jamais affiché)</label><input id="cost" name="cost" inputmode="decimal" value="<?= e(Money::input($p['cost'] === null ? null : (int) $p['cost'])) ?>"></div>
    <div><label for="categoryId">Catégorie</label><select id="categoryId" name="categoryId"><option value="">—</option><?php foreach ($categories as $c) : ?><option value="<?= e($c['id']) ?>"<?= $c['id'] === $p['categoryId'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div>
  </div>
  <div class="row">
    <div><label for="stockMode">Stock</label><select id="stockMode" name="stockMode"><option value="SUPPLIER"<?= $p['stockMode'] === 'SUPPLIER' ? ' selected' : '' ?>>Chez le fournisseur (pas de quantité)</option><option value="TRACKED"<?= $p['stockMode'] === 'TRACKED' ? ' selected' : '' ?>>Compté ici</option></select></div>
    <div><label for="stockQty">Quantité (si compté)</label><input id="stockQty" name="stockQty" type="number" min="0" value="<?= (int) $p['stockQty'] ?>"></div>
    <div><label for="supplierUrl">Page fournisseur<?= $supplier ? ' (' . e($supplier) . ')' : '' ?></label><input id="supplierUrl" name="supplierUrl" value="<?= e($p['supplierUrl'] ?? '') ?>"></div>
    <div><label for="supplierRef">Réf. fournisseur</label><input id="supplierRef" name="supplierRef" value="<?= e($p['supplierRef'] ?? '') ?>"></div>
  </div>
  <div class="row">
    <div><label for="slug">Adresse de la page (/produit/…)</label><input id="slug" name="slug" value="<?= e($p['slug']) ?>"></div>
    <div><label for="videoUrl">Vidéo (lien YouTube ou .mp4)</label><input id="videoUrl" name="videoUrl" value="<?= e($p['videoUrl'] ?? '') ?>"></div>
    <div><label for="keywords">Mots de recherche (jamais affichés)</label><input id="keywords" name="keywords" value="<?= e($p['keywords']) ?>"></div>
  </div>
  <label class="check"><input type="checkbox" name="available"<?= $b('available') ? ' checked' : '' ?>> Disponible chez le fournisseur</label>
  <label class="check"><input type="checkbox" name="featured"<?= $b('featured') ? ' checked' : '' ?>> Dans « Notre sélection »</label>
  <label class="check"><input type="checkbox" name="active"<?= $b('active') ? ' checked' : '' ?>> En ligne</label>
  <div class="actions"><button class="btn dark">Enregistrer</button></div>
</form>

<section class="cardx">
  <h2>Photos du haut (<?= count($gallery) ?>)</h2>
  <div class="thumbs"><?php $photoRow($gallery); ?></div>
  <form method="post" enctype="multipart/form-data" class="inline" style="margin-top:12px"><?= Csrf::field() ?><input type="hidden" name="do" value="photos"><input type="hidden" name="kind" value="GALLERY">
    <div><label for="ph1">Ajouter des photos (JPEG, PNG, WebP)</label><input id="ph1" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></div>
    <button class="btn dark">Envoyer</button></form>
</section>

<section class="cardx">
  <h2>Détails en images, sous la description (<?= count($details) ?>)</h2>
  <div class="thumbs"><?php $photoRow($details); ?></div>
  <form method="post" enctype="multipart/form-data" class="inline" style="margin-top:12px"><?= Csrf::field() ?><input type="hidden" name="do" value="photos"><input type="hidden" name="kind" value="DETAIL">
    <div><label for="ph2">Ajouter des images</label><input id="ph2" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></div>
    <div><label for="lg">Montrée sur</label><select id="lg" name="lang"><option value="">les deux pages</option><option value="fr">la page française</option><option value="ar">la page arabe</option></select></div>
    <button class="btn dark">Envoyer</button></form>
</section>

<section class="cardx">
  <h2>Offres (packs)</h2>
  <p class="muted small">Le titre affiché est la quantité (« 3 unités »). Votre nom s'affiche dessous seulement s'il annonce la même quantité.</p>
  <?php foreach ($offers as $o) : ?>
    <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="offerId" value="<?= e($o['id']) ?>">
      <div><label>Nom (fr)</label><input name="label" value="<?= e($o['label']) ?>"></div>
      <div><label>Nom (ar)</label><input name="labelAr" dir="rtl" value="<?= e($o['labelAr'] ?? '') ?>"></div>
      <div><label>Quantité</label><input name="quantity" type="number" min="1" max="50" value="<?= (int) $o['quantity'] ?>"></div>
      <div><label>Prix total (DT)</label><input name="price" inputmode="decimal" value="<?= e(Money::input((int) $o['price'])) ?>"></div>
      <div><label>Fin (facultatif)</label><input name="endsAt" type="date" value="<?= $o['endsAt'] ? e(View::time($o['endsAt'], 'Y-m-d')) : '' ?>"></div>
      <div><label>Ordre</label><input name="sort" type="number" value="<?= (int) $o['sort'] ?>"></div>
      <label class="check"><input type="checkbox" name="freeDelivery"<?= Db::bool($o['freeDelivery']) ? ' checked' : '' ?>> Livraison offerte</label>
      <label class="check"><input type="checkbox" name="active"<?= Db::bool($o['active']) ? ' checked' : '' ?>> Active</label>
      <button class="btn small" name="do" value="offerSave">Enregistrer</button>
      <button class="btn small danger" name="do" value="offerDelete">Supprimer</button>
    </form>
  <?php endforeach ?>
  <form method="post" class="inline" style="margin-top:12px"><?= Csrf::field() ?><input type="hidden" name="do" value="offerAdd">
    <div><label>Nom (fr)</label><input name="label" placeholder="Achetez 2, la 3e offerte"></div>
    <div><label>Nom (ar)</label><input name="labelAr" dir="rtl"></div>
    <div><label>Quantité</label><input name="quantity" type="number" min="1" max="50" required></div>
    <div><label>Prix total (DT)</label><input name="price" inputmode="decimal" required></div>
    <div><label>Fin (facultatif)</label><input name="endsAt" type="date"></div>
    <div><label>Ordre</label><input name="sort" type="number" value="<?= count($offers) ?>"></div>
    <label class="check"><input type="checkbox" name="freeDelivery"> Livraison offerte</label>
    <button class="btn dark small">Ajouter l'offre</button>
  </form>
</section>

<section class="cardx">
  <h2>Options (taille, couleur)</h2>
  <?php foreach ($variants as $v) : ?>
    <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="variantId" value="<?= e($v['id']) ?>">
      <div><label>Nom (fr)</label><input name="name" value="<?= e($v['name']) ?>"></div>
      <div><label>Nom (ar)</label><input name="nameAr" dir="rtl" value="<?= e($v['nameAr'] ?? '') ?>"></div>
      <div><label>Couleur (#rrggbb, facultatif)</label><input name="colorHex" value="<?= e($v['colorHex'] ?? '') ?>" placeholder="#1f2937"></div>
      <div><label>Prix propre (vide = prix du produit)</label><input name="price" inputmode="decimal" value="<?= e(Money::input($v['price'] === null ? null : (int) $v['price'])) ?>"></div>
      <div><label>Stock (si compté)</label><input name="stockQty" type="number" min="0" value="<?= (int) $v['stockQty'] ?>"></div>
      <div><label>Ordre</label><input name="sort" type="number" value="<?= (int) $v['sort'] ?>"></div>
      <label class="check"><input type="checkbox" name="available"<?= Db::bool($v['available']) ? ' checked' : '' ?>> Disponible</label>
      <button class="btn small" name="do" value="variantSave">Enregistrer</button>
      <button class="btn small danger" name="do" value="variantDelete">Supprimer</button>
    </form>
  <?php endforeach ?>
  <form method="post" class="inline" style="margin-top:12px"><?= Csrf::field() ?><input type="hidden" name="do" value="variantAdd">
    <div><label>Nom (fr)</label><input name="name" placeholder="Noir" required></div>
    <div><label>Nom (ar)</label><input name="nameAr" dir="rtl"></div>
    <div><label>Couleur (facultatif)</label><input name="colorHex" placeholder="#1f2937"></div>
    <div><label>Prix propre</label><input name="price" inputmode="decimal"></div>
    <div><label>Stock</label><input name="stockQty" type="number" min="0" value="0"></div>
    <div><label>Ordre</label><input name="sort" type="number" value="<?= count($variants) ?>"></div>
    <button class="btn dark small">Ajouter l'option</button>
  </form>
</section>

<form class="cardx" method="post" data-confirm="Supprimer ce produit ?"><?= Csrf::field() ?><input type="hidden" name="do" value="delete">
  <h2>Supprimer</h2>
  <p class="muted small">Un produit déjà commandé est caché au lieu d'être supprimé, pour garder l'historique des commandes.</p>
  <button class="btn danger">Supprimer le produit</button>
</form>
