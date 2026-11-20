<?php
declare(strict_types=1);

/**
 * Product pictures. The originals live in the database ("Media".bytes, shared
 * with the Next.js shop). Each width is made once, on its first request, and
 * written to public/media/<id>/<width>.webp: from then on the web server
 * sends the file itself, without PHP or the database.
 */
final class Media
{
    /** The widths the Next.js shop's loader asks for (image-loader.ts IMG_WIDTHS). */
    public const WIDTHS = [64, 96, 128, 200, 256, 360, 480, 640, 828, 1080, 1280, 1600];

    public static function ext(): string
    {
        return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false) ? 'webp' : 'jpg';
    }

    /** The address of a picture at a width (the nearest made one above it). */
    public static function url(?string $id, int $width = 640): string
    {
        if (!$id) {
            return '';
        }
        $w = self::WIDTHS[count(self::WIDTHS) - 1];
        foreach (self::WIDTHS as $x) {
            if ($x >= $width) {
                $w = $x;
                break;
            }
        }
        return "/media/$id/$w." . self::ext();
    }

    public static function srcset(?string $id): string
    {
        if (!$id) {
            return '';
        }
        return implode(', ', array_map(static fn ($w) => self::url($id, $w) . " {$w}w", self::WIDTHS));
    }

    /** Makes (or finds) one width of a picture and sends it. Answers 404 for anything unknown. */
    public static function serve(string $id, int $width, string $ext): never
    {
        if (!preg_match('/^[a-z0-9]{20,40}$/', $id) || !in_array($width, self::WIDTHS, true) || $ext !== self::ext()) {
            http_response_code(404);
            exit;
        }
        $dir = ROOT . "/public/media/$id";
        $file = "$dir/$width.$ext";
        if (!is_file($file)) {
            $m = Db::one('SELECT bytes, width FROM "Media" WHERE id = ?', [$id]);
            if (!$m) {
                http_response_code(404);
                exit;
            }
            $bytes = is_resource($m['bytes']) ? stream_get_contents($m['bytes']) : (string) $m['bytes'];
            // With emulated prepares PDO may hand back PostgreSQL's hex text ("\x89504e…").
            if (str_starts_with($bytes, '\\x')) {
                $bytes = (string) hex2bin(substr($bytes, 2));
            }
            $out = self::resize($bytes, $width, $ext);
            if ($out === null) {
                http_response_code(404);
                exit;
            }
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents("$file.tmp", $out) && @rename("$file.tmp", $file);
            self::send($out, $ext);
        }
        self::send((string) file_get_contents($file), $ext);
    }

    private static function send(string $bytes, string $ext): never
    {
        header('Content-Type: ' . ($ext === 'webp' ? 'image/webp' : 'image/jpeg'));
        // The id changes when the picture does.
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    /** A picture at a width (never larger than it is), re-encoded: what is sent is always a clean image. */
    public static function resize(string $bytes, int $width, string $ext): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > $width) {
            $nh = (int) round($h * $width / $w);
            $dst = imagecreatetruecolor($width, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $width, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }
        ob_start();
        if ($ext === 'webp') {
            imagewebp($img, null, 78);
        } else {
            // JPEG has no transparency: white behind it.
            $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagejpeg($flat, null, 82);
            imagedestroy($flat);
        }
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /**
     * Stores an uploaded picture in the database, as the Next.js shop does:
     * re-encoded (WebP when possible, at most 1600 px wide), so what is kept is
     * always a clean image whatever was uploaded. Returns the new media id.
     */
    public static function store(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return null;
        }
        $ext = self::ext();
        $clean = self::resize($bytes, 1600, $ext);
        if ($clean === null) {
            return null;
        }
        $size = getimagesizefromstring($clean);
        $id = new_id();
        Db::run('INSERT INTO "Media" (id, mime, width, height, bytes, "createdAt") VALUES (?, ?, ?, ?, decode(?, \'base64\'), ?)', [
            $id, $ext === 'webp' ? 'image/webp' : 'image/jpeg', (int) $size[0], (int) $size[1], base64_encode($clean), Db::now(),
        ]);
        return $id;
    }
}
