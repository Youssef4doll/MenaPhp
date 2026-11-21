<?php
declare(strict_types=1);

/** A picture refused (a format, a size, an address), with the message the owner reads. */
final class ImageRefused extends Exception
{
}

/**
 * A product's pictures: lib/product-images.ts and the picture half of
 * lib/media.ts. Every picture is fetched once and stored in the database
 * ("Media"), re-encoded, so the shop never depends on a supplier's server.
 */
final class ProductImages
{
    public const MAX_UPLOAD = 8 * 1024 * 1024;
    /** A fetched picture may be larger than an upload: it is made smaller here, never stored as it came. */
    private const MAX_FETCH = 20 * 1024 * 1024;
    private const MAX_W = 1600;
    private const MAX_H = 16_000;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'];
    /** Picture services that resize another address: the original is in their "url". */
    private const RESIZERS = ['/_next/image', '/_vercel/image'];
    /** A picture of this shop ("…/api/img/<id>/<width>"), as an export lists them: linked again, never downloaded twice. */
    private const OWN = '#/api/img/([a-z0-9]{20,40})/\d+/?$#';

    /** supplier-page.ts originalImageUrl: "…/_next/image?url=…&w=384" → the picture it resizes. */
    public static function originalImageUrl(string $raw, ?string $base = null): ?string
    {
        $s = (string) preg_replace_callback('/&(amp|quot|apos|lt|gt|#39|#x27|#47|#x2F);/i', static function ($m) {
            $map = ['amp' => '&', 'quot' => '"', 'apos' => "'", 'lt' => '<', 'gt' => '>', '#39' => "'", '#x27' => "'", '#47' => '/', '#x2f' => '/'];
            return $map[strtolower($m[1])] ?? $m[0];
        }, Text::trim($raw));
        if ($s === '' || preg_match('/^(data|blob|javascript|about):/i', $s)) {
            return null;
        }
        $u = WebUrl::parse(str_starts_with($s, '//') ? "https:$s" : $s, $base);
        if (!$u) {
            return null;
        }
        $inner = in_array($u->path, self::RESIZERS, true) ? $u->param('url') : null;
        if ($inner) {
            $u = WebUrl::parse(str_starts_with($inner, '//') ? "https:$inner" : $inner, $u->origin());
            if (!$u) {
                return null;
            }
        }
        return $u->scheme === 'https' || $u->scheme === 'http' ? (string) $u : null;
    }

    /** media.ts directImageUrl: Google Drive and Dropbox share links, and resized copies, as the picture itself. */
    public static function directImageUrl(string $raw): string
    {
        $url = self::originalImageUrl($raw) ?? Text::trim($raw);
        $u = WebUrl::parse($url);
        if (!$u || $u->host === '') {
            return $url;
        }
        if ($u->host === 'drive.google.com' || $u->host === 'docs.google.com') {
            $id = preg_match('#/d/([\w-]{10,})#', $u->path, $m) ? $m[1] : $u->param('id');
            if ($id) {
                return "https://drive.google.com/uc?export=download&id=$id";
            }
        }
        if (str_ends_with($u->host, 'dropbox.com') && $u->hasParam('dl')) {
            $u->setParam('dl', '1');
            return (string) $u;
        }
        return $url;
    }

    /** Private, loopback and reserved addresses: a link to them would make the server read its own network. */
    private static function privateAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$a, $b] = array_map('intval', explode('.', $ip));
            return $a === 0 || $a === 10 || $a === 127 || ($a === 100 && $b >= 64 && $b <= 127) || ($a === 169 && $b === 254) || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168) || ($a === 198 && ($b === 18 || $b === 19)) || $a >= 224;
        }
        $v = strtolower($ip);
        if (str_starts_with($v, '::ffff:')) {
            return self::privateAddress(substr($v, 7));
        }
        return $v === '::' || $v === '::1' || (bool) preg_match('/^(fc|fd|fe8|fe9|fea|feb|ff)/', $v);
    }

    /** The tests serve their pictures from this machine. Never in production, whatever the setting. */
    private static function localOk(): bool
    {
        return config('env', 'production') !== 'production' && (string) config('media_allow_local', '') === '1';
    }

    /**
     * media.ts checkHost: refuses an address of the server's own network.
     * @return list<string> the addresses the name gives, to connect to those and no other
     */
    public static function checkHost(WebUrl $u): array
    {
        if ($u->scheme !== 'https' && $u->scheme !== 'http') {
            throw new ImageRefused('Adresse invalide.');
        }
        $host = trim($u->host, '[]');
        if (self::localOk()) {
            return [];
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new ImageRefused('Adresse refusée.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
                $ips[] = $r['ip'] ?? $r['ipv6'] ?? '';
            }
            $ips = array_values(array_filter($ips));
            if (!$ips) {
                $v4 = @gethostbynamel($host);
                $ips = $v4 ?: [];
            }
            if (!$ips) {
                throw new ImageRefused("Site introuvable : vérifiez l'adresse de l'image.");
            }
        }
        foreach ($ips as $ip) {
            if (self::privateAddress($ip)) {
                throw new ImageRefused('Adresse refusée.');
            }
        }
        return $ips;
    }

    /**
     * One GET, redirects followed one by one, each address checked like the first.
     * @return array{status: int, type: string, body: string, url: string}
     */
    public static function fetch(string $raw, string $accept, string $agent, int $timeout, int $max, string $tooBig): array
    {
        $u = WebUrl::parse($raw);
        if (!$u) {
            throw new ImageRefused('Adresse invalide.');
        }
        for ($hop = 0; $hop < 5; $hop++) {
            $ips = self::checkHost($u);
            $ch = curl_init((string) $u);
            $body = '';
            $headers = [];
            $opts = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ["user-agent: $agent", "accept: $accept", 'accept-language: fr,en;q=0.8'],
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers) {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $headers[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $max) {
                    $body .= $chunk;
                    return strlen($body) > $max ? 0 : strlen($chunk);
                },
            ];
            // The checked addresses are the ones connected to: a name cannot point elsewhere between the check and the request.
            if ($ips) {
                $port = $u->port ?? ($u->scheme === 'https' ? 443 : 80);
                $opts[CURLOPT_RESOLVE] = [trim($u->host, '[]') . ":$port:" . implode(',', array_map(static fn ($ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $ips))];
            }
            curl_setopt_array($ch, $opts);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            if (strlen($body) > $max) {
                throw new ImageRefused($tooBig);
            }
            if ($ok === false && $errno !== 0) {
                throw new ImageRefused($errno === CURLE_OPERATION_TIMEDOUT ? 'Le site ne répond pas.' : 'Téléchargement impossible.');
            }
            $next = $status >= 300 && $status < 400 ? ($headers['location'] ?? null) : null;
            if (!$next) {
                return ['status' => $status, 'type' => strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])), 'body' => $body, 'url' => (string) $u, 'length' => (int) ($headers['content-length'] ?? 0)];
            }
            $u = WebUrl::parse($next, (string) $u);
            if (!$u) {
                throw new ImageRefused('Adresse invalide.');
            }
        }
        throw new ImageRefused('Trop de redirections.');
    }

    /** Supplier pictures: fetched once and stored. Returns the new media id. */
    public static function importFromUrl(string $raw): string
    {
        $r = self::fetch(self::directImageUrl($raw), 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8', 'Mozilla/5.0 (compatible; MenaShop/1.0; +product-import)', 20, self::MAX_FETCH, 'Image trop lourde (20 Mo maximum).');
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new ImageRefused("Téléchargement impossible (erreur {$r['status']}).");
        }
        $mime = in_array($r['type'], self::ALLOWED, true) ? $r['type'] : self::sniff($r['body']);
        if (!$mime) {
            throw new ImageRefused(str_starts_with($r['type'], 'text/') ? "Ce lien mène à une page, pas à une image : ouvrez l'image seule et copiez son adresse." : "Ce fichier n'est pas une image reconnue (JPEG, PNG, WebP, AVIF).");
        }
        return self::store($r['body'], $mime, self::MAX_FETCH);
    }

    /** The format the bytes say (a server that answers "octet-stream" for a picture). */
    private static function sniff(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? null;
        return $mime && in_array($mime, self::ALLOWED, true) ? $mime : null;
    }

    /**
     * media.ts storeImage: any accepted picture re-encoded to WebP (which also drops EXIF, GPS
     * included), at most 1600 wide and 16 000 tall, with a 16-px blurred preview.
     */
    public static function store(string $bytes, string $mime, int $max = self::MAX_UPLOAD): string
    {
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new ImageRefused('Format non accepté (JPEG, PNG, WebP, AVIF).');
        }
        if (strlen($bytes) > $max) {
            throw new ImageRefused('Image trop lourde (' . (int) round($max / 1024 / 1024) . ' Mo maximum).');
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            throw new ImageRefused($mime === 'image/avif' ? "Ce serveur ne lit pas les images AVIF : envoyez-la en JPEG ou PNG." : 'Image illisible.');
        }
        $img = self::upright($img, $bytes, $mime);
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_W / $w, self::MAX_H / $h);
        if ($scale < 1) {
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }
        $webp = function_exists('imagewebp');
        $out = self::encode($img, $webp, 82);
        $tinyImg = imagescale($img, 16);
        $blur = $tinyImg ? 'data:image/' . ($webp ? 'webp' : 'jpeg') . ';base64,' . base64_encode(self::encode($tinyImg, $webp, 40)) : null;
        $width = imagesx($img);
        $height = imagesy($img);
        imagedestroy($img);
        $id = new_id();
        Db::run('INSERT INTO "Media" (id, mime, width, height, bytes, blur, "createdAt") VALUES (?, ?, ?, ?, decode(?, \'base64\'), ?, ?)', [
            $id, $webp ? 'image/webp' : 'image/jpeg', $width, $height, base64_encode($out), $blur, Dates::now(),
        ]);
        return $id;
    }

    private static function encode(GdImage $img, bool $webp, int $quality): string
    {
        ob_start();
        if ($webp) {
            imagewebp($img, null, $quality);
        } else {
            $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagejpeg($flat, null, $quality);
            imagedestroy($flat);
        }
        return (string) ob_get_clean();
    }

    /** sharp's rotate(): a phone photo turned the way it was taken, from its EXIF orientation. */
    private static function upright(GdImage $img, string $bytes, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode(substr($bytes, 0, 256 * 1024)));
        $o = (int) ($exif['Orientation'] ?? 1);
        $turned = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($turned, IMG_FLIP_HORIZONTAL);
            if ($o === 4) {
                $turned = imagerotate($turned, 180, 0);
            } elseif ($o === 5) {
                $turned = imagerotate($turned, 90, 0);
            } elseif ($o === 7) {
                $turned = imagerotate($turned, -90, 0);
            }
        }
        return $turned ?: $img;
    }

    /**
     * product-images.ts addProductImage: a picture for a product, from an address or a file.
     * `source` is kept with it, so the same CSV imported again adds nothing.
     *
     * @param array{url: string}|array{bytes: string, mime: string, source: ?string, max?: int} $from
     * @return array{ok: true, id: string, skipped: bool}|array{ok: false, error: string}
     */
    public static function add(string $productId, string $kind, array $from, ?int $sort = null): array
    {
        $source = isset($from['url']) ? Text::slice(self::directImageUrl($from['url']), 0, 1000) : (($from['source'] ?? null) ? Text::slice($from['source'], 0, 1000) : null);
        if (!Db::value('SELECT 1 FROM "Product" WHERE id = ?', [$productId])) {
            return ['ok' => false, 'error' => 'Produit introuvable.'];
        }
        if ($source !== null && $source !== '') {
            $already = Db::value('SELECT id FROM "ProductImage" WHERE "productId" = ? AND kind = ? AND source = ? LIMIT 1', [$productId, $kind, $source]);
            if ($already) {
                return ['ok' => true, 'id' => $already, 'skipped' => true];
            }
        } else {
            $source = null;
        }
        $place = $sort ?? ((int) (Db::value('SELECT max(sort) FROM "ProductImage" WHERE "productId" = ? AND kind = ?', [$productId, $kind]) ?? -1) + 1);
        try {
            $known = null;
            if (isset($from['url']) && preg_match(self::OWN, $from['url'], $m)) {
                $known = Db::value('SELECT id FROM "Media" WHERE id = ?', [$m[1]]);
            }
            if ($known) {
                $linked = Db::value('SELECT id FROM "ProductImage" WHERE "productId" = ? AND kind = ? AND "mediaId" = ? LIMIT 1', [$productId, $kind, $known]);
                if ($linked) {
                    return ['ok' => true, 'id' => $linked, 'skipped' => true];
                }
                $mediaId = $known;
            } elseif (isset($from['url'])) {
                $mediaId = self::importFromUrl($from['url']);
            } else {
                $mediaId = self::store($from['bytes'], $from['mime'], $from['max'] ?? self::MAX_UPLOAD);
            }
            $id = new_id();
            try {
                Db::insert('ProductImage', ['id' => $id, 'productId' => $productId, 'kind' => $kind, 'mediaId' => $mediaId, 'sort' => $place, 'source' => $source]);
                return ['ok' => true, 'id' => $id, 'skipped' => false];
            } catch (PDOException $e) {
                // The same picture sent at the same moment from another device: that one is kept, this copy is dropped.
                if ((string) $e->getCode() !== '23505' || !$source) {
                    throw $e;
                }
                $kept = Db::value('SELECT id FROM "ProductImage" WHERE "productId" = ? AND kind = ? AND source = ? LIMIT 1', [$productId, $kind, $source]);
                if (!$known) {
                    Db::run('DELETE FROM "Media" WHERE id = ?', [$mediaId]);
                }
                return ['ok' => true, 'id' => (string) ($kept ?? ''), 'skipped' => true];
            }
        } catch (ImageRefused $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Pictures no product uses any more, deleted from the storage too. */
    public static function dropUnused(array $mediaIds): void
    {
        $mediaIds = array_values(array_unique(array_filter($mediaIds)));
        if (!$mediaIds) {
            return;
        }
        Db::run('DELETE FROM "Media" m WHERE m.id IN (' . Db::marks($mediaIds) . ') AND NOT EXISTS (SELECT 1 FROM "ProductImage" i WHERE i."mediaId" = m.id)', $mediaIds);
        foreach ($mediaIds as $id) {
            if (preg_match('/^[a-z0-9]{20,40}$/', $id) && !Db::value('SELECT 1 FROM "Media" WHERE id = ?', [$id])) {
                foreach (glob(ROOT . "/public/media/$id/*") ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir(ROOT . "/public/media/$id");
            }
        }
    }
}
