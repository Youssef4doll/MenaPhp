<?php /** @var array $categories */ ?>
<h1>Nouveau produit</h1>
<form class="cardx" method="post" action="/admin/produits/nouveau">
  <?= Csrf::field() ?>
  <div class="row">
    <div><label for="n">Nom (français)</label><input id="n" name="name" required maxlength="200"></div>
    <div><label for="na">Nom (arabe)</label><input id="na" name="nameAr" dir="rtl" maxlength="200"></div>
    <div><label for="pr">Prix (DT)</label><input id="pr" name="price" inputmode="decimal" placeholder="49,900" required></div>
    <div><label for="c">Catégorie</label><select id="c" name="categoryId"><option value="">—</option><?php foreach ($categories as $c) : ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach ?></select></div>
  </div>
  <p class="muted small">Le produit est créé caché. Vous ajoutez ensuite les photos, la description et les offres, puis vous le mettez en ligne.</p>
  <div class="actions"><button class="btn dark">Créer</button></div>
</form>
