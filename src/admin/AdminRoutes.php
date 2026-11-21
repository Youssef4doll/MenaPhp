<?php
declare(strict_types=1);

/**
 * The back office's route handlers of the Next.js shop: the import's check,
 * prepare and rows, a product's pictures, the CSV export, and the video
 * upload (sent to this server instead of Vercel Blob).
 */
final class AdminRoutes
{
    public const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];
    public const VIDEO_MAX_MB = 60;
    private const VIDEO_PATH = '#^produits/([a-z0-9-]{1,60})\.(mp4|webm|mov)$#';

    public static function handle(string $method, string $path): void
    {
        @set_time_limit(180);
        $refused = self::refuseUnlessAdmin($method);
        if ($refused) {
            return;
        }
        if ($method === 'GET' && $path === '/admin/produits/export') {
            self::export();
            return;
        }
        if ($method !== 'POST') {
            AdminApp::json(['ok' => false, 'error' => 'Méthode refusée.'], 405);
            return;
        }
        match (true) {
            $path === '/admin/produits/import/check' => self::check(),
            $path === '/admin/produits/import/prepare' => self::prepare(),
            $path === '/admin/produits/import/rows' => self::rows(),
            $path === '/admin/produits/video' => self::video(),
            (bool) preg_match('#^/admin/produits/([a-z0-9]{20,40})/images$#', $path, $m) => self::images($m[1]),
            default => AdminApp::json(['ok' => false, 'error' => 'Introuvable.'], 404),
        };
    }

    /** lib/admin-api.ts refuseUnlessAdmin(): writes from the shop's own pages, by a signed-in admin. */
    private static function refuseUnlessAdmin(string $method): bool
    {
        if ($method !== 'GET' && $method !== 'HEAD' && !same_origin_request()) {
            AdminApp::json(['ok' => false, 'error' => 'Requête refusée.'], 403);
            return true;
        }
        if (!Auth::current()) {
            AdminApp::json(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
            return true;
        }
        return false;
    }

    /** lib/http.ts readJson(): a JSON body of at most `max` bytes, or null. */
    private static function readJson(int $max): mixed
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) {
            return null;
        }
        return json_decode($raw, true);
    }

    private static function check(): void
    {
        $body = Z::obj([
            'keys' => Z::arr(Z::str()->max(100))->max(2000),
            'categories' => Z::arr(Z::str()->max(60))->max(2000),
            'suppliers' => Z::arr(Z::str()->max(80))->max(2000),
        ])->safeParse(self::readJson(512_000));
        if (!$body['ok']) {
            AdminApp::json(['ok' => false, 'error' => 'Liste invalide.'], 400);
            return;
        }
        $d = $body['data'];
        AdminApp::json(['ok' => true] + ProductImport::check($d['keys'], $d['categories'], $d['suppliers']));
    }

    private static function prepare(): void
    {
        $body = Z::obj([
            'categories' => Z::arr(Z::obj(['name' => Z::str()->min(1)->max(60), 'nameAr' => Z::str()->max(60)->optional()]))->max(500),
            'suppliers' => Z::arr(Z::str()->min(1)->max(80))->max(500),
        ])->safeParse(self::readJson(256_000));
        if (!$body['ok']) {
            AdminApp::json(['ok' => false, 'error' => 'Liste invalide.'], 400);
            return;
        }
        AdminApp::json(ProductImport::prepare($body['data']['categories'], $body['data']['suppliers']));
    }

    /** One CSV row, created or updated. The browser sends a few at a time and shows the progress. */
    private static function rows(): void
    {
        $parsed = Z::obj(['row' => ProductImport::rowSchema(), 'activateNew' => Z::bool()->default(false), 'translate' => Z::bool()->default(false)])->safeParse(self::readJson(256_000));
        if (!$parsed['ok']) {
            // The column as the file names it ("prix", "photos"), not the field's internal name.
            $key = null;
            foreach ($parsed['path'] as $p) {
                if (is_string($p) && $p !== 'row') {
                    $key = $p;
                    break;
                }
            }
            $column = $key === null ? null : (ProductCsv::header($key) ?? ($key === 'stockQty' ? ProductCsv::header('stock') : null));
            AdminApp::json(['ok' => false, 'error' => $column ? "Ligne refusée : la colonne « $column » a une valeur trop longue ou invalide." : 'Ligne refusée : une valeur est trop longue ou invalide.'], 400);
            return;
        }
        $d = $parsed['data'];
        $r = ProductImport::row($d['row'], $d['activateNew'], $d['translate']);
        if (!$r['ok']) {
            AdminApp::json($r, 422);
            return;
        }
        Refresh::tag();
        AdminApp::json($r);
    }

    /** One picture for a product: a file (multipart: file, kind, source, sort) or a web address (JSON: url, kind, sort). */
    private static function images(string $id): void
    {
        if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
            $file = $_FILES['file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($file['size'] ?? 0) === 0) {
                if (is_array($file) && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    AdminApp::json(['ok' => false, 'error' => ($file['name'] ?? 'Image') . ' : 8 Mo maximum.'], 413);
                    return;
                }
                AdminApp::json(['ok' => false, 'error' => 'Aucune image.'], 400);
                return;
            }
            if ($file['size'] > ProductImages::MAX_UPLOAD) {
                AdminApp::json(['ok' => false, 'error' => "{$file['name']} : 8 Mo maximum."], 413);
                return;
            }
            $kind = ($_POST['kind'] ?? '') === 'DETAIL' ? 'DETAIL' : 'GALLERY';
            $sort = isset($_POST['sort']) && preg_match('/^\d{1,6}$/', (string) $_POST['sort']) && (int) $_POST['sort'] <= 100_000 ? (int) $_POST['sort'] : null;
            $source = Text::slice((string) ($_POST['source'] ?? ''), 0, 300) ?: null;
            $mime = (string) ($file['type'] ?? '');
            $result = ProductImages::add($id, $kind, ['bytes' => (string) file_get_contents($file['tmp_name']), 'mime' => $mime, 'source' => $source], $sort);
        } else {
            $body = Z::obj([
                'url' => Z::url()->max(2000),
                'kind' => Z::enum(['GALLERY', 'DETAIL']),
                'sort' => Z::int()->min(0)->max(100_000)->optional(),
            ])->safeParse(self::readJson(8_192));
            if (!$body['ok']) {
                AdminApp::json(['ok' => false, 'error' => "Adresse d'image invalide."], 400);
                return;
            }
            $result = ProductImages::add($id, $body['data']['kind'], ['url' => $body['data']['url']], $body['data']['sort'] ?? null);
        }
        if (!$result['ok']) {
            AdminApp::json($result, 422);
            return;
        }
        Refresh::tag();
        AdminApp::json($result);
    }

    /**
     * The catalog as a CSV file in the import format. ?modele=vide gives the empty template,
     * ?modele=exemple the template with two example lines; ?arabe=1 adds the Arabic columns.
     */
    private static function export(): void
    {
        $model = $_GET['modele'] ?? null;
        $arabic = ($_GET['arabe'] ?? '') === '1';
        if (is_string($model) && $model !== '') {
            self::csv($model === 'exemple' ? 'exemple-import-produits.csv' : 'modele-import-produits.csv', ProductCsv::templateCsv($model === 'exemple', ';', $arabic));
            return;
        }
        // Pictures are listed by their address on this shop; importing the file again links them, it never copies them.
        $site = rtrim((string) config('site_url', ''), '/');
        $base = $site === '' || str_starts_with($site, 'http://localhost') ? (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') : $site;
        $ids = array_column(Db::all('SELECT id FROM "Product" ORDER BY active DESC, "createdAt" DESC LIMIT 5000'), 'id');
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $loaded = ProductWrite::load($chunk);
            $cats = [];
            foreach (Db::all('SELECT p.id, c.name, c."nameAr", s.name AS supplier FROM "Product" p LEFT JOIN "Category" c ON c.id = p."categoryId" LEFT JOIN "Supplier" s ON s.id = p."supplierId" WHERE p.id IN (' . Db::marks($chunk) . ')', $chunk) as $r) {
                $cats[$r['id']] = $r;
            }
            $images = [];
            foreach (Db::all('SELECT "productId", "mediaId", kind FROM "ProductImage" WHERE "productId" IN (' . Db::marks($chunk) . ') ORDER BY sort ASC, id ASC', $chunk) as $i) {
                $images[$i['productId']][$i['kind']][] = "$base/api/img/{$i['mediaId']}/1600";
            }
            foreach ($chunk as $id) {
                $p = $loaded[$id] ?? null;
                if (!$p) {
                    continue;
                }
                $c = $cats[$id] ?? [];
                $rows[] = ProductWrite::toInput($p) + [
                    'category' => ($c['name'] ?? null) !== null ? ['name' => $c['name'], 'nameAr' => $c['nameAr']] : null,
                    'supplier' => $c['supplier'] ?? null,
                    'photos' => $images[$id]['GALLERY'] ?? [],
                    'detailPhotos' => $images[$id]['DETAIL'] ?? [],
                ];
            }
        }
        $day = gmdate('Y-m-d');
        self::csv("produits-mena-$day" . ($arabic ? '-avec-arabe' : '') . '.csv', ProductCsv::productsToCsv($rows, ';', $arabic));
    }

    private static function csv(string $name, string $body): void
    {
        while (ob_get_level() > 1) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        echo $body;
    }

    /** Videos are kept here, on the host: public/uploads/videos. */
    public static function videoDir(): string
    {
        return ROOT . '/public/uploads/videos';
    }

    /** Whether a video can be sent from the computer (the folder can be written). */
    public static function videoReady(): bool
    {
        $dir = self::videoDir();
        return (is_dir($dir) || @mkdir($dir, 0775, true)) && is_writable($dir);
    }

    /**
     * A product video, in pieces (shims-vercel-blob.ts): ?path=produits/<name>.<ext>&type&size&at&token.
     * The first piece starts a file; the last one moves it into public/uploads/videos.
     */
    private static function video(): void
    {
        $path = (string) ($_GET['path'] ?? '');
        $type = (string) ($_GET['type'] ?? '');
        $size = (int) ($_GET['size'] ?? -1);
        $at = (int) ($_GET['at'] ?? -1);
        $token = (string) ($_GET['token'] ?? '');
        if (!preg_match(self::VIDEO_PATH, $path, $m) || !in_array($type, self::VIDEO_TYPES, true)) {
            AdminApp::json(['error' => 'Nom de fichier refusé.'], 400);
            return;
        }
        if ($size <= 0 || $size > self::VIDEO_MAX_MB * 1024 * 1024 || $at < 0 || $at >= $size) {
            AdminApp::json(['error' => 'Envoi refusé.'], 400);
            return;
        }
        if (!self::videoReady()) {
            AdminApp::json(['error' => "Le dossier des vidéos ne peut pas être écrit sur l'hébergement (public/uploads/videos)."], 503);
            return;
        }
        $work = ROOT . '/storage/uploads';
        if (!is_dir($work)) {
            @mkdir($work, 0775, true);
        }
        if ($at === 0) {
            $token = bin2hex(random_bytes(16));
            file_put_contents("$work/$token.json", json_encode(['path' => $path, 'type' => $type, 'size' => $size, 'by' => Auth::current()['id'] ?? '']));
            file_put_contents("$work/$token.part", '');
        } elseif (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file("$work/$token.json")) {
            AdminApp::json(['error' => 'Envoi interrompu : recommencez.'], 400);
            return;
        }
        $meta = json_decode((string) file_get_contents("$work/$token.json"), true);
        if ($meta['path'] !== $path || $meta['size'] !== $size || clearstatcache() || filesize("$work/$token.part") !== $at) {
            AdminApp::json(['error' => 'Envoi interrompu : recommencez.'], 400);
            return;
        }
        $piece = (string) file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024 + 1);
        if ($piece === '' || strlen($piece) > 4 * 1024 * 1024 || $at + strlen($piece) > $size) {
            AdminApp::json(['error' => 'Envoi refusé.'], 400);
            return;
        }
        file_put_contents("$work/$token.part", $piece, FILE_APPEND);
        if ($at + strlen($piece) < $size) {
            AdminApp::json(['token' => $token]);
            return;
        }
        // The whole file: kept under its name with a random suffix, as Vercel Blob does.
        $name = $m[1] . '-' . substr(bin2hex(random_bytes(8)), 0, 12) . '.' . $m[2];
        rename("$work/$token.part", self::videoDir() . "/$name");
        @unlink("$work/$token.json");
        $site = rtrim((string) config('site_url', ''), '/');
        AdminApp::json(['token' => $token, 'url' => "$site/uploads/videos/$name"]);
    }
}
