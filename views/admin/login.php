<?php /** @var ?string $error */ ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion · Mena admin</title>
<link rel="stylesheet" href="/assets/admin.css?v=1">
</head>
<body>
<form class="login" method="post" action="/admin/connexion">
  <h1>Back-office Mena</h1>
  <?= Csrf::field() ?>
  <label for="email">E-mail</label>
  <input id="email" name="email" type="email" autocomplete="username" required value="<?= e(post_str('email', 190)) ?>">
  <label for="password">Mot de passe</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <?php if ($error) : ?><p class="err" role="alert"><?= e($error) ?></p><?php endif ?>
  <div class="actions"><button class="btn dark">Se connecter</button></div>
  <p class="muted small">Le même compte que le back-office actuel.</p>
</form>
</body>
</html>
