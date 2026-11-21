<?php
/**
 * app/admin/layout.tsx: the back office's page, drawn by its React components
 * (public/assets/islands/admin.js) from the data below. Until they run, the
 * menu and the page's loading outline are shown as plain HTML.
 *
 * @var array $model the page (AdminApp::model) @var string $static
 */
?>
<!doctype html>
<html lang="fr" class="atkinson_hyperlegible_next_3773b22a-module__GUAgxW__variable">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= e(asset('/assets/admin-next.css')) ?>">
<link rel="modulepreload" href="<?= e(asset('/assets/islands/admin.js')) ?>">
<meta name="theme-color" content="#17181c">
<title><?= e($model['title']) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/assets/icon.svg" sizes="any" type="image/svg+xml">
<script type="module" src="<?= e(asset('/assets/islands/admin.js')) ?>"></script>
</head>
<body class="min-h-dvh bg-cream">
<div id="admin-root" class="contents"><?= $static ?></div>
<script type="application/json" id="mena-admin"><?= json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</body>
</html>
