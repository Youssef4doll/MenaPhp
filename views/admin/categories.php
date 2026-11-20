<?php /** @var array $rows */ ?>
<h1>Catégories</h1>
<section class="cardx">
  <?php foreach ($rows as $c) : ?>
    <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
      <div><label>Nom (fr)</label><input name="name" value="<?= e($c['name']) ?>" required></div>
      <div><label>Nom (ar)</label><input name="nameAr" dir="rtl" value="<?= e($c['nameAr'] ?? '') ?>"></div>
      <div><label>Titre sur l'accueil (fr)</label><input name="headline" value="<?= e($c['headline'] ?? '') ?>"></div>
      <div><label>Titre (ar)</label><input name="headlineAr" dir="rtl" value="<?= e($c['headlineAr'] ?? '') ?>"></div>
      <div><label>Ordre</label><input name="sort" type="number" value="<?= (int) $c['sort'] ?>"></div>
      <label class="check"><input type="checkbox" name="active"<?= Db::bool($c['active']) ? ' checked' : '' ?>> Visible</label>
      <span class="muted small"><?= (int) $c['n'] ?> produit(s) · /categorie/<?= e($c['slug']) ?></span>
      <button class="btn small">Enregistrer</button>
    </form>
  <?php endforeach ?>
</section>
<form class="cardx inline" method="post"><?= Csrf::field() ?><input type="hidden" name="do" value="add">
  <div><label>Nouvelle catégorie (fr)</label><input name="name" required></div>
  <div><label>Nom (ar)</label><input name="nameAr" dir="rtl"></div>
  <div><label>Ordre</label><input name="sort" type="number" value="<?= count($rows) ?>"></div>
  <button class="btn dark">Ajouter</button>
</form>
