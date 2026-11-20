<?php
declare(strict_types=1);

/**
 * Addresses, the same as the Next.js shop's: French at the root, Arabic under
 * /ar, /fr/... sent to the root form, the back office under /admin.
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /** Runs the handler of the first route that matches; 404 otherwise. */
    public function dispatch(string $method, string $path): void
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = false;
        foreach ($this->routes as [$m, $pattern, $handler]) {
            $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#u';
            if (!preg_match($re, $path, $match)) {
                continue;
            }
            if ($m !== $method) {
                $allowed = true;
                continue;
            }
            $args = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler(...array_map('rawurldecode', $args));
            return;
        }
        if ($allowed) {
            http_response_code(405);
            echo 'Method not allowed';
            return;
        }
        Shop::notFound();
    }

    /** Splits the language prefix off a shop address. Returns [lang, path], or a redirect for /fr/... */
    public static function language(string $path): array
    {
        if ($path === '/ar' || str_starts_with($path, '/ar/')) {
            return ['ar', substr($path, 3) ?: '/'];
        }
        if ($path === '/fr' || str_starts_with($path, '/fr/')) {
            $rest = substr($path, 3) ?: '/';
            $qs = $_SERVER['QUERY_STRING'] ?? '';
            redirect($rest . ($qs !== '' ? "?$qs" : ''), 307);
        }
        return ['fr', $path];
    }
}
