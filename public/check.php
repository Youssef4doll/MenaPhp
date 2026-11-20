<?php
/**
 * Can this host run Mena's PHP shop on Neon? Upload this one file to the
 * host (with config.php beside the project, if you have it already), open
 * it in the browser, read the list. It changes nothing and shows no
 * password, no key and no address of the database.
 *
 * Without config.php it can still test whether Neon is reachable: type the
 * HOST part of your Neon address (e.g. ep-cool-name-123456-pooler.eu-central-1.aws.neon.tech).
 *
 * Delete it from the host once the shop runs.
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$rows = [];
$add = static function (string $what, ?bool $ok, string $detail = '') use (&$rows): void {
    $rows[] = [$what, $ok, $detail];
};

// 1. PHP itself.
$add('PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), 'This host: PHP ' . PHP_VERSION);
foreach (['pdo_pgsql' => 'PostgreSQL driver (pdo_pgsql) — needed for Neon', 'mbstring' => 'mbstring (Arabic text)', 'gd' => 'GD (resizing product photos)', 'intl' => 'intl (product addresses from names) — optional', 'openssl' => 'openssl (secure connection to Neon)', 'curl' => 'curl — optional'] as $ext => $label) {
    $add($label, extension_loaded($ext) ? true : (str_contains($label, 'optional') ? null : false));
}
if (extension_loaded('gd')) {
    $gd = gd_info();
    $add('GD can write WebP photos', !empty($gd['WebP Support']) ? true : null, empty($gd['WebP Support']) ? 'No: photos will be served as JPEG (fine, a little heavier).' : '');
}

// 2. Folders the shop writes to.
$root = dirname(__DIR__);
foreach (['storage' => "$root/storage", 'public/media' => __DIR__ . '/media'] as $label => $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $add("Folder $label can be written", is_dir($dir) && is_writable($dir));
}
$bigUploads = (int) ini_get('upload_max_filesize') >= 8 || stripos((string) ini_get('upload_max_filesize'), 'G') !== false;
$add('Uploads up to 8 MB (product photos)', $bigUploads ? true : null, 'upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size') . ($bigUploads ? '' : ': larger photos are refused. public/.user.ini asks for 16 MB; if the host ignores it, raise it in its PHP settings or send smaller photos.'));

// 3. Neon: the host from config.php, or typed below.
$config = is_file("$root/config.php") ? (static fn () => require "$root/config.php")() : null;
$url = is_array($config) ? (string) ($config['database_url'] ?? '') : '';
$host = '';
if ($url !== '') {
    $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
} elseif (isset($_GET['host']) && preg_match('/^[a-z0-9.-]{4,200}$/i', (string) $_GET['host'])) {
    $host = strtolower((string) $_GET['host']);
}
$isNeon = $host !== '' && str_ends_with($host, '.neon.tech');

if ($host === '') {
    $add('Neon reachable from this host', null, 'Not tested: no config.php yet. Type your Neon host below.');
} else {
    $errno = 0;
    $errstr = '';
    $t = microtime(true);
    $sock = @fsockopen($host, 5432, $errno, $errstr, 8);
    $ms = (int) ((microtime(true) - $t) * 1000);
    if ($sock) {
        fclose($sock);
    }
    $add('Neon reachable from this host (port 5432)', (bool) $sock, $sock ? "Answered in $ms ms." : 'Blocked or unreachable: this host does not let sites connect to outside databases.');
    if ($isNeon && !str_contains($host, '-pooler.')) {
        $add('Pooled Neon address', null, 'Use the address with "-pooler" in the host name (Neon → Connect → Connection pooling).');
    }
}

// 4. A real connection, when config.php has the address.
if ($url !== '' && extension_loaded('pdo_pgsql')) {
    $u = parse_url($url) ?: [];
    parse_str($u['query'] ?? '', $q);
    $base = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $u['host'] ?? '', (int) ($u['port'] ?? 5432), ltrim($u['path'] ?? '/neondb', '/'), $q['sslmode'] ?? 'require');
    $user = rawurldecode($u['user'] ?? '');
    $pass = rawurldecode($u['pass'] ?? '');
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10, PDO::ATTR_EMULATE_PREPARES => true];
    $pdo = null;
    $how = '';
    try {
        $pdo = new PDO($base, $user, $pass, $opts);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'endpoint') !== false && preg_match('/^(ep-[a-z0-9-]+?)(-pooler)?\./', (string) ($u['host'] ?? ''), $m)) {
            try {
                $pdo = new PDO($base . ";options='endpoint={$m[1]}'", $user, $pass, $opts);
                $how = ' (this host\'s PostgreSQL library is old: the shop adds the Neon endpoint itself, nothing to do)';
            } catch (PDOException $e2) {
                $msg = $e2->getMessage();
            }
        }
        if (!$pdo) {
            // The message can name the host, never the password.
            $add('Connected to the Neon database', false, preg_replace('/password[^,;]*/i', 'password …', $msg));
        }
    }
    if ($pdo) {
        $client = (string) $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);
        $add('Connected to the Neon database', true, "PostgreSQL client library $client$how.");
        try {
            $found = (int) $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name IN ('Product','Order','Setting','Media','AdminUser')")->fetchColumn();
            $add("The shop's tables are there", $found === 5, $found === 5 ? 'Products, orders, settings, photos, accounts.' : "Only $found of 5 found: is it the shop's database?");
        } catch (Throwable $e) {
            $add("The shop's tables are there", false, 'Could not read them.');
        }
    }
    $secret = is_array($config) ? (string) ($config['app_secret'] ?? '') : '';
    $add('app_secret is set (32+ characters)', strlen($secret) >= 32, strlen($secret) >= 32 ? '' : 'Put the same APP_SECRET as on Netlify in config.php.');
}

$ready = !in_array(false, array_column($rows, 1), true);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mena: host check</title>
<style>
  body { font: 16px/1.5 system-ui, sans-serif; color: #1c2633; background: #f5f7fa; margin: 0; padding: 16px; }
  main { max-width: 760px; margin: 0 auto; }
  h1 { font-size: 22px; margin: 8px 0 4px; }
  .verdict { padding: 12px 16px; border-radius: 14px; font-weight: 700; margin: 12px 0 16px; }
  .ok { background: #e3f1e8; color: #2c6b45; } .no { background: #fdecef; color: #b42318; }
  table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dfe5eb; border-radius: 14px; overflow: hidden; }
  td { padding: 10px 12px; border-top: 1px solid #dfe5eb; vertical-align: top; }
  tr:first-child td { border-top: 0; }
  .s { width: 64px; font-weight: 700; } .y { color: #2c6b45; } .n { color: #b42318; } .m { color: #566373; }
  small { color: #566373; }
  form { margin-top: 16px; display: flex; gap: 8px; flex-wrap: wrap; }
  input { flex: 1; min-width: 220px; height: 44px; border: 1px solid #848f9c; border-radius: 12px; padding: 0 12px; font-size: 16px; }
  button { height: 44px; border: 0; border-radius: 22px; padding: 0 18px; background: #1c2633; color: #fff; font-size: 16px; }
</style>
</head>
<body>
<main>
  <h1>Can this host run Mena (PHP + Neon)?</h1>
  <p class="verdict <?= $ready ? 'ok' : 'no' ?>"><?= $ready ? 'Yes: nothing blocking found.' : 'Not yet: see the lines marked NO.' ?></p>
  <table>
    <?php foreach ($rows as [$what, $ok, $detail]) : ?>
      <tr>
        <td class="s <?= $ok === true ? 'y' : ($ok === false ? 'n' : 'm') ?>"><?= $ok === true ? 'YES' : ($ok === false ? 'NO' : '—') ?></td>
        <td><?= htmlspecialchars($what) ?><?php if ($detail !== '') : ?><br><small><?= htmlspecialchars($detail) ?></small><?php endif ?></td>
      </tr>
    <?php endforeach ?>
  </table>
  <?php if ($url === '') : ?>
    <form method="get">
      <input name="host" placeholder="ep-…-pooler.eu-central-1.aws.neon.tech" value="<?= htmlspecialchars($host) ?>" aria-label="Neon host">
      <button>Test Neon</button>
    </form>
    <p><small>Only the host part of the address: never the user name or the password.</small></p>
  <?php endif ?>
  <p><small>Delete check.php from the host once the shop runs.</small></p>
</main>
</body>
</html>
