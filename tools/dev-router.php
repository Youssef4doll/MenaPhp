<?php
/**
 * For trying the shop on a computer, without Apache:
 *   php -S localhost:8080 -t public tools/dev-router.php
 * Files that exist are sent as they are; everything else goes to index.php (as public/.htaccess does).
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . '/../public' . $path)) {
    return false;
}
require __DIR__ . '/../public/index.php';
