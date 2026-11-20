<?php
/** @var string $content @var string $title @var ?array $admin */
Cart::session();
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH);
$pending = (int) Db::value('SELECT count(*) FROM "Order" WHERE status = \'PENDING\'');
$nav = ['/admin' => 'Tableau de bord', '/admin/commandes' => 'Commandes', '/admin/produits' => 'Produits', '/admin/categories' => 'Catégories', '/admin/parametres' => 'Paramètres'];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Mena admin</title>
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/assets/admin.css?v=1">
<script src="/assets/app.js?v=1" defer></script>
</head>
<body>
<header class="bar">
  <a class="brand" href="/admin">Mena · admin</a>
  <nav>
    <?php foreach ($nav as $href => $label) : $on = $href === '/admin' ? $path === '/admin' : str_starts_with($path, $href); ?>
      <a href="<?= $href ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($href === '/admin/commandes' && $pending > 0) : ?> <span class="pill warn"><?= $pending ?></span><?php endif ?></a>
    <?php endforeach ?>
    <a href="/" target="_blank" rel="noopener">Voir la boutique</a>
  </nav>
  <form method="post" action="/admin/deconnexion"><?= Csrf::field() ?><button class="link"><?= e($admin['email'] ?? '') ?> · Déconnexion</button></form>
</header>
<main class="page">
  <?php if ($flash) : ?><p class="flash <?= $flash[1] ? 'ok' : 'bad' ?>" role="status"><?= e($flash[0]) ?></p><?php endif ?>
  <?= $content ?>
</main>
</body>
</html>
