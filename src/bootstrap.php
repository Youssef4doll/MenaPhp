<?php
declare(strict_types=1);

/**
 * Loaded first by every request (public/index.php) and by the tests: reads
 * config.php, sets the error policy, and loads the shop's code.
 */

define('ROOT', dirname(__DIR__));
date_default_timezone_set('Africa/Tunis');
mb_internal_encoding('UTF-8');

$configFile = getenv('MENA_CONFIG') ?: ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "config.php is missing. Copy config.example.php to config.php and fill it in (see README.md).\n";
    exit;
}
$GLOBALS['MENA_CONFIG'] = require $configFile;

/** A setting from config.php. */
function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['MENA_CONFIG'][$key] ?? $default;
}

$production = config('env', 'production') === 'production';
error_reporting(E_ALL);
ini_set('display_errors', $production ? '0' : '1');
ini_set('log_errors', '1');
if (!is_dir(ROOT . '/storage')) {
    @mkdir(ROOT . '/storage', 0775, true);
}
ini_set('error_log', ROOT . '/storage/php-errors.log');

foreach (['helpers', 'Db', 'Lang', 'Money', 'Phone', 'Gov', 'Tokens', 'Cache', 'Settings', 'OfferLabel', 'Search', 'Rules', 'ui/Icons', 'ui/Ui', 'ui/ProductUi', 'Seo', 'Api', 'Events', 'Notify', 'Catalog', 'Pricing', 'RateLimit', 'Csrf', 'Cart', 'Orders', 'Auth', 'Media', 'Router', 'View', 'Shop', 'Admin'] as $file) {
    require __DIR__ . "/$file.php";
}
