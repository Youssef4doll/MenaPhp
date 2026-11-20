<?php
declare(strict_types=1);

/** Every address that is not a file (pictures already made, CSS, JS) arrives here. */
require dirname(__DIR__) . '/src/bootstrap.php';
// The whole page is kept until it is complete: it can then be sent with its length, and work done after it
// (Cache::refresh) never makes the visitor wait.
ob_start();

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = $path !== '/' ? rtrim($path, '/') : '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (config('env', 'production') !== 'production') {
    // Outside production: how many database queries each page needed (storage/queries.log).
    register_shutdown_function(static fn () => @file_put_contents(ROOT . '/storage/queries.log', sprintf("%s %s %d\n", $method, $path, Db::$count), FILE_APPEND));
}

try {
    // Pictures: made on their first request, then served as files by the web server.
    if (preg_match('#^/media/([a-z0-9]+)/(\d+)\.(webp|jpg)$#', $path, $m)) {
        Media::serve($m[1], (int) $m[2], $m[3]);
    }
    // The Next.js shop's picture addresses (posts, ads, Canva) keep working.
    if (preg_match('#^/api/img/([a-z0-9]+)/(\d+)$#', $path, $m)) {
        redirect(Media::url($m[1], (int) $m[2]), 301);
    }

    $router = new Router();
    if (str_starts_with($path, '/api/')) {
        $GLOBALS['MENA_LANG'] = 'fr';
        Api::routes($router);
        $router->dispatch($method, $path);
        exit;
    }
    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        View::securityHeaders(true);
        $GLOBALS['MENA_LANG'] = 'fr';
        Admin::routes($router);
        $router->dispatch($method, $path);
        exit;
    }
    if ($path === '/robots.txt' || $path === '/sitemap.xml') {
        $GLOBALS['MENA_LANG'] = 'fr';
        $path === '/robots.txt' ? Shop::robots() : Shop::sitemap();
        exit;
    }

    [$lang, $rest] = Router::language($path);
    $GLOBALS['MENA_LANG'] = $lang;
    View::securityHeaders();
    Shop::routes($router);
    $router->dispatch($method, $rest);
} catch (Throwable $e) {
    error_log('[page] ' . $path . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (config('env', 'production') !== 'production') {
        echo '<pre>' . e((string) $e) . '</pre>';
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Mena</title><p style="font:16px system-ui;padding:24px">Un problème est survenu. Réessayez dans un instant. — صار مشكل، عاود بعد شوية.</p>';
    }
}
