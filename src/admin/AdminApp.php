<?php
declare(strict_types=1);

/**
 * The Next.js back office, in PHP. Each page is the Next.js page's own React
 * component (tools/islands/admin/pages, built into public/assets/islands/admin.js),
 * drawn in the browser from the data PHP reads here (AdminPages). Its Server
 * Actions are run by Actions.php, and its route handlers (pictures, import,
 * export, video) are below. Pages not ported yet are still served by Admin.php.
 */
final class AdminApp
{
    /** Set by an action that changed something (Next.js's revalidatePath / updateTag): the page is drawn again. */
    public static bool $revalidated = false;

    /** The pages: address → AdminPages method. The first that matches wins. */
    private const PAGES = [
        '#^/admin/connexion$#' => 'login',
        '#^/admin/produits$#' => 'products',
        '#^/admin/produits/import$#' => 'import',
        '#^/admin/produits/fournisseur$#' => 'supplier',
        '#^/admin/produits/completer$#' => 'complete',
        '#^/admin/produits/traduction$#' => 'translate',
        '#^/admin/produits/qualite$#' => 'quality',
        '#^/admin/produits/([^/]+)$#' => 'product',
    ];

    /** Answers the request when it is one of the ported pages or routes; false lets Admin.php answer. */
    public static function handle(string $method, string $path): bool
    {
        if ($method === 'POST' && preg_match('#^/admin/api/action/(.+)/([A-Za-z0-9_]+)$#', $path, $m)) {
            Actions::run($m[1], $m[2]);
            return true;
        }
        if ($method === 'POST' && $path === '/admin/deconnexion' && isset($_SERVER['HTTP_X_MENA_ACTION'])) {
            Auth::logout();
            self::json(['ok' => true]);
            return true;
        }
        if (preg_match('#^/admin/produits/(import/check|import/prepare|import/rows|export|video)$#', $path, $m) || preg_match('#^/admin/produits/([a-z0-9]{20,40})/images$#', $path, $m2)) {
            AdminRoutes::handle($method, $path);
            return true;
        }
        if ($method !== 'GET') {
            return false;
        }
        $model = self::model($path, $_GET);
        if ($model === null) {
            return false;
        }
        if (isset($_SERVER['HTTP_X_MENA_PAGE'])) {
            self::json($model);
        } else {
            self::render($model, $path);
        }
        return true;
    }

    /**
     * The page at an address, as data for its React component, or null when it is not ported.
     * Redirects and the sign-in check happen here, as in the Next.js page.
     *
     * @return array{component: string, props: array, title: string, nav: ?array, admin: string}|null
     */
    public static function model(string $path, array $query): ?array
    {
        foreach (self::PAGES as $re => $method) {
            if (!preg_match($re, $path, $m)) {
                continue;
            }
            if ($method !== 'login') {
                $admin = Auth::current();
                if (!$admin) {
                    self::redirectTo('/admin/connexion');
                }
            }
            try {
                $page = AdminPages::$method($query, ...array_slice($m, 1));
            } catch (AdminNotFound) {
                $page = ['component' => 'NotFoundPage', 'props' => (object) [], 'title' => '404: This page could not be found.', 'bare' => true];
            }
            $bare = !empty($page['bare']);
            return [
                'component' => $page['component'],
                'props' => $page['props'],
                'title' => $page['fullTitle'] ?? (($page['title'] ?? '') !== '' ? $page['title'] . ' · Mena admin' : 'Back-office'),
                'nav' => $bare ? null : self::nav(),
                'admin' => $bare ? '' : (string) (Auth::current()['email'] ?? ''),
            ];
        }
        return null;
    }

    /** The menu of app/admin/(panel)/layout.tsx, with its three counts. */
    public static function nav(): array
    {
        $c = Db::one("SELECT (SELECT count(*) FROM \"Order\" WHERE status = 'PENDING') AS pending, (SELECT count(*) FROM \"Review\" WHERE status = 'PENDING') AS reviews, (SELECT count(*) FROM \"ReturnRequest\" WHERE status = 'REQUESTED') AS returns");
        return [
            ['href' => '/admin', 'label' => 'Tableau de bord'],
            ['href' => '/admin/commandes', 'label' => 'Commandes', 'badge' => (int) $c['pending']],
            ['href' => '/admin/analytics', 'label' => 'Analytics'],
            ['href' => '/admin/publicites', 'label' => 'Publicités'],
            ['href' => '/admin/depenses', 'label' => 'Dépenses pub'],
            ['href' => '/admin/paniers', 'label' => 'Non terminées'],
            ['href' => '/admin/produits', 'label' => 'Produits'],
            ['href' => '/admin/produits/qualite', 'label' => 'Qualité des fiches'],
            ['href' => '/admin/categories', 'label' => 'Catégories'],
            ['href' => '/admin/fournisseurs', 'label' => 'Fournisseurs'],
            ['href' => '/admin/clients', 'label' => 'Clients'],
            ['href' => '/admin/codes-promo', 'label' => 'Codes promo'],
            ['href' => '/admin/retours', 'label' => 'Retours', 'badge' => (int) $c['returns']],
            ['href' => '/admin/avis', 'label' => 'Avis', 'badge' => (int) $c['reviews']],
            ['href' => '/admin/parametres', 'label' => 'Paramètres'],
        ];
    }

    public static function redirectTo(string $to): never
    {
        if (isset($_SERVER['HTTP_X_MENA_PAGE']) || isset($_SERVER['HTTP_X_MENA_ACTION'])) {
            self::json(['redirect' => $to], str_starts_with($to, '/admin/connexion') ? 401 : 200);
            exit;
        }
        redirect($to);
    }

    public static function json(mixed $data, int $status = 200): void
    {
        while (ob_get_level() > 1) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** The HTML of app/admin/layout.tsx, with the menu and the loading outline shown until the page's code runs. */
    private static function render(array $model, string $path): void
    {
        $skeletons = self::skeletons();
        $editor = (bool) preg_match('#^/admin/produits/(?!import$|traduction$)[^/]+$#', $path);
        $loading = $editor ? $skeletons['editor'] : $skeletons['panel'];
        $static = $model['nav'] === null ? '' : self::shell($model['nav'], $model['admin'], $path, $loading);
        header('Content-Type: text/html; charset=utf-8');
        require ROOT . '/views/admin/next.php';
    }

    /** @return array{panel: string, editor: string} */
    private static function skeletons(): array
    {
        $file = ROOT . '/public/assets/islands/skeletons.json';
        $s = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($s) ? $s + ['panel' => '', 'editor' => ''] : ['panel' => '', 'editor' => ''];
    }

    /** components/admin/Shell.tsx as plain HTML: what shows while the page's code arrives. */
    private static function shell(array $nav, string $admin, string $path, string $main): string
    {
        $active = static fn (string $href) => $href === '/admin' ? $path === '/admin' : str_starts_with($path, $href);
        $links = '<nav class="flex flex-col gap-0.5">';
        foreach ($nav as $n) {
            $on = $active($n['href']);
            $links .= '<a class="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm ' . ($on ? 'bg-ink text-white' : 'text-ink-2 hover:bg-sand') . '" href="' . e($n['href']) . '">' . e($n['label']);
            if (!empty($n['badge'])) {
                $links .= '<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ' . ($on ? 'bg-white/20' : 'bg-clay text-white') . '">' . (int) $n['badge'] . '</span>';
            }
            $links .= '</a>';
        }
        $links .= '</nav>';
        $logo = Ui::logo();
        return '<div class="min-h-dvh lg:grid lg:grid-cols-[232px_1fr]">'
            . '<aside class="sticky top-0 hidden h-dvh flex-col gap-6 border-e border-line bg-cream p-4 lg:flex"><a class="px-2 pt-1" href="/admin">' . $logo . '</a>' . $links
            . '<div class="mt-auto flex flex-col gap-2 px-2 text-xs text-muted"><a target="_blank" class="underline" href="/">Voir la boutique</a><span>' . e($admin) . '</span><form><button class="underline">Se déconnecter</button></form></div></aside>'
            . '<div class="min-w-0"><header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-line bg-cream/95 px-4 backdrop-blur lg:hidden"><button type="button" class="-ms-2 rounded-full p-2" aria-label="Menu"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round"></path></svg></button>' . $logo . '<span class="ms-auto text-xs text-muted">admin</span></header>'
            . '<main class="mx-auto max-w-[1400px] px-4 py-5 lg:px-8 lg:py-8">' . $main . '</main></div></div>';
    }
}

/** notFound() of a Next.js page. */
final class AdminNotFound extends Exception
{
}
