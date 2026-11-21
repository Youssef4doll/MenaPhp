<?php
declare(strict_types=1);

/** A FormData sent to an action (React sends it as the form's parts): get() and getAll() as in JavaScript. */
final class FormDataIn
{
    /** @param list<array{0: string, 1: string|array}> $entries values, or uploaded files ($_FILES entries) */
    public function __construct(private readonly array $entries)
    {
    }

    public function get(string $name): string|array|null
    {
        foreach ($this->entries as [$k, $v]) {
            if ($k === $name) {
                return $v;
            }
        }
        return null;
    }

    /** @return list<string|array> */
    public function getAll(string $name): array
    {
        return array_values(array_map(static fn ($e) => $e[1], array_filter($this->entries, static fn ($e) => $e[0] === $name)));
    }
}

/**
 * The Next.js back office's Server Actions, run by PHP. The browser calls
 * /admin/api/action/<file>/<function> with the arguments (tools/islands/admin/actions.ts);
 * the answer is the action's result and, when the action changed something,
 * the page drawn again, as Next.js sends it.
 */
final class Actions
{
    /** "<file of the Next.js back office>" → the PHP class with its functions, of the same names. */
    private const MODULES = [
        'connexion/actions' => LoginActions::class,
        'produits/actions' => ProductActions::class,
        'produits/list-actions' => ListActions::class,
        'produits/import/actions' => ImportActions::class,
        'produits/fournisseur/actions' => SupplierActions::class,
        'produits/completer/actions' => CompleteActions::class,
        'produits/traduction/actions' => TranslateActions::class,
    ];

    public static function run(string $module, string $name): void
    {
        $class = self::MODULES[$module] ?? null;
        if (!$class || !method_exists($class, $name) || !(new ReflectionMethod($class, $name))->isPublic()) {
            AdminApp::json(['error' => 'Action inconnue.'], 404);
            return;
        }
        // Writes come from the back office's own pages only (the browser always sends Origin on a POST).
        if (!same_origin_request()) {
            AdminApp::json(['error' => 'Requête refusée.'], 403);
            return;
        }
        if ($class !== LoginActions::class && !Auth::current()) {
            AdminApp::json(['redirect' => '/admin/connexion'], 401);
            return;
        }
        [$page, $args] = self::arguments();
        @set_time_limit(300);
        try {
            $result = $class::$name(...$args);
        } catch (ActionRedirect $r) {
            AdminApp::json(['redirect' => $r->to]);
            return;
        } catch (Throwable $e) {
            error_log("[action] $module#$name: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            AdminApp::json(['error' => 'An error occurred in the Server Components render.'], 500);
            return;
        }
        $model = null;
        if (AdminApp::$revalidated && is_string($page) && str_starts_with($page, '/admin')) {
            $path = (string) parse_url($page, PHP_URL_PATH);
            parse_str((string) parse_url($page, PHP_URL_QUERY), $query);
            try {
                $model = AdminApp::model($path, $query);
            } catch (Throwable $e) {
                error_log("[action] page $page: " . $e->getMessage());
            }
        }
        AdminApp::json(['result' => $result, 'page' => $model]);
    }

    /** @return array{0: ?string, 1: list<mixed>} the page the action came from, and its arguments */
    private static function arguments(): array
    {
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_starts_with($type, 'multipart/form-data')) {
            $payload = json_decode((string) ($_POST['__payload'] ?? ''), true);
            $forms = [];
            foreach ((array) ($payload['forms'] ?? []) as $i => $keys) {
                $entries = [];
                foreach ((array) $keys as [$name, $part]) {
                    $entries[] = [(string) $name, isset($_FILES[$part]) ? $_FILES[$part] : (string) ($_POST[$part] ?? '')];
                }
                $forms[$i] = new FormDataIn($entries);
            }
            $args = self::revive($payload['args'] ?? [], $forms);
            return [$payload['page'] ?? null, is_array($args) ? array_values($args) : []];
        }
        $body = json_decode((string) file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024), true);
        return [$body['page'] ?? null, is_array($body['args'] ?? null) ? array_values($body['args']) : []];
    }

    private static function revive(mixed $v, array $forms): mixed
    {
        if (is_array($v)) {
            if (count($v) === 1 && array_key_exists('$fd', $v) && isset($forms[$v['$fd']])) {
                return $forms[$v['$fd']];
            }
            return array_map(static fn ($x) => self::revive($x, $forms), $v);
        }
        return $v;
    }
}

/** redirect() in a Server Action: the browser goes there. */
final class ActionRedirect extends Exception
{
    public function __construct(public readonly string $to)
    {
        parent::__construct($to);
    }
}

/** lib/catalog-refresh.ts and revalidatePath: the shop shows the change, the page is drawn again. */
final class Refresh
{
    private const EXPIRE_AT_MOST = 50;

    /** refreshCatalog(productIds, slugs): the products' pages expire at once (updateTag), the rest is marked stale. */
    public static function catalog(array $productIds = [], array $slugs = []): void
    {
        Cache::flush();
        if ($productIds || $slugs) {
            if (count($productIds) + count($slugs) <= self::EXPIRE_AT_MOST) {
                // updateTag: the page that asked is drawn again with the change.
                AdminApp::$revalidated = true;
            }
        }
    }

    /** revalidatePath(): the page that asked is drawn again. */
    public static function path(): void
    {
        AdminApp::$revalidated = true;
    }

    /** revalidateTag(CATALOG_TAG, "max"): the shop rebuilds in the background; this page is not drawn again. */
    public static function tag(): void
    {
        Cache::flush();
    }
}

final class LoginActions
{
    public static function login(mixed $_prev, FormDataIn $form): array
    {
        // Said in words: without it the session cannot be made, and the page would only show an error code.
        if (!Tokens::hasSecret()) {
            return ['error' => "Le serveur n'a pas sa clé app_secret (32 caractères ou plus) dans config.php. Ajoutez-la sur l'hébergement, puis rechargez la page."];
        }
        $email = (string) $form->get('email');
        $password = (string) $form->get('password');
        if (!RateLimit::hit('login', client_ip())) {
            return ['error' => 'Trop de tentatives. Réessayez dans ' . (int) ceil(RateLimit::$retryAfter / 60) . ' min.'];
        }
        $user = Auth::verify(mb_substr($email, 0, 190), mb_substr($password, 0, 200));
        if (!$user) {
            return ['error' => 'E-mail ou mot de passe incorrect.'];
        }
        Auth::login($user['id']);
        throw new ActionRedirect('/admin');
    }
}
