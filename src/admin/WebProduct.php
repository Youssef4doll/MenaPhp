<?php
declare(strict_types=1);

/**
 * A product's page on another site, read for its large pictures, its video
 * and what it says of the product: lib/web-product.ts, web-pages.ts and the
 * picture reading of supplier-page.ts. Nothing here is shown to customers as
 * such: the owner chooses, and the texts are rewritten, never copied.
 */
final class WebProduct
{
    public const MAX_IMAGES = 24;
    private const MAX_PHOTOS = 20;
    /** A page is read up to this size: a product's pictures and texts are in its first megabytes. */
    private const MAX_PAGE = 3 * 1024 * 1024;
    private const JUNK = '#(^|[/_.-])(logo|icon|icons|avatar|favicon|sprite|placeholder|flag|flags|badge|loader|loading|spinner|payment|visa|mastercard)([/_.-]|$)#i';

    private static function decode(string $s): string
    {
        $map = ['amp' => '&', 'quot' => '"', 'apos' => "'", 'lt' => '<', 'gt' => '>', 'nbsp' => "\u{00A0}", '#39' => "'", '#x27' => "'", '#47' => '/', '#x2f' => '/'];
        $s = (string) preg_replace_callback('/&(amp|quot|apos|lt|gt|nbsp|#39|#x27|#47|#x2F);/i', static fn ($m) => $map[strtolower($m[1])] ?? $m[0], $s);
        return (string) preg_replace_callback('/&#(\d+);/', static fn ($m) => (int) $m[1] <= 0x10FFFF ? mb_chr((int) $m[1], 'UTF-8') : $m[0], $s);
    }

    private static function clean(string $s): string
    {
        return Text::trim((string) preg_replace('/' . Text::WS . '+/u', ' ', self::decode((string) preg_replace('/<[^>]*>/', ' ', $s))));
    }

    /** bigImageUrl(): the largest copy of a picture, from the address of a smaller one. */
    public static function bigImageUrl(string $raw): string
    {
        $u = WebUrl::parse($raw);
        if (!$u || $u->host === '') {
            return $raw;
        }
        if (preg_match('/(^|\.)media-amazon\.com$|(^|\.)ssl-images-amazon\.com$/', $u->host)) {
            $u->path = (string) preg_replace('#/I/([^/.]+)\.[^/]*\.(jpe?g|png|webp|gif)$#i', '/I/$1.$2', $u->path);
            return (string) $u;
        }
        if ($u->host === 'cdn.shopify.com' || str_contains($u->path, '/cdn/shop/')) {
            $u->path = (string) preg_replace('/_(\d+x\d*|\d*x\d+|pico|icon|thumb|small|compact|medium|large|grande)(@\dx)?(\.[a-z]+)$/i', '$3', $u->path);
            $u->query = implode('&', array_filter(explode('&', $u->query), static fn ($p) => $p !== '' && !in_array(urldecode(explode('=', $p, 2)[0]), ['width', 'height', 'crop'], true)));
            return (string) $u;
        }
        $u->path = (string) preg_replace('/-\d{2,4}x\d{2,4}(\.(?:jpe?g|png|webp))$/i', '$1', $u->path);
        return (string) $u;
    }

    /** isImageUrl(): an address that looks like a picture file. */
    public static function isImageUrl(string $url): bool
    {
        return (bool) preg_match('/^https?:\/\/[^\s]+\.(jpe?g|png|webp|avif|gif)(\?[^\s]*)?$/i', Text::trim($url));
    }

    /** videoAddress(): a video's address in one form; null for a file or a stream on another site. */
    public static function videoAddress(string $url): ?string
    {
        $v = Rules::parseVideo($url);
        if (!$v || $v['kind'] === 'file' || $v['kind'] === 'hls') {
            return null;
        }
        return $v['kind'] === 'youtube' ? 'https://www.youtube.com/watch?v=' . $v['id'] : Text::trim($url);
    }

    /** videoLinks(): the video links written anywhere in the page. */
    public static function videoLinks(string $html): array
    {
        $found = [];
        $add = static function (string $url) use (&$found) {
            $key = self::videoAddress($url);
            if ($key && !in_array($key, $found, true)) {
                $found[] = $key;
            }
        };
        $text = str_replace('\\/', '/', self::decode($html));
        preg_match_all('#(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $text, $m);
        foreach ($m[1] as $id) {
            $add("https://www.youtube.com/watch?v=$id");
        }
        preg_match_all('#https://(?:player\.)?vimeo\.com/(?:video/)?\d{5,12}#', $text, $m);
        foreach ($m[0] as $u) {
            $add($u);
        }
        preg_match_all('#https://www\.tiktok\.com/@[\w.-]+/video/\d{10,25}#', $text, $m);
        foreach ($m[0] as $u) {
            $add($u);
        }
        return array_slice($found, 0, 6);
    }

    private static function attr(string $tag, string $name): ?string
    {
        if (!preg_match('/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m)) {
            return null;
        }
        foreach ([1, 2, 3] as $i) {
            if (isset($m[$i]) && ($m[$i] !== '' || $i === count($m) - 1)) {
                return $m[$i];
            }
        }
        return '';
    }

    private static function widest(string $srcset): ?string
    {
        $best = null;
        // Cut after each "640w," or "2x,": the descriptor stays with its address, a comma inside an address does not cut it.
        $bits = preg_split('/(\s\d+(?:\.\d+)?[wx])\s*,\s*/', $srcset, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $parts = [];
        for ($i = 0; $i < count($bits); $i += 2) {
            $parts[] = $bits[$i] . ($bits[$i + 1] ?? '');
        }
        foreach ($parts as $part) {
            $bits = preg_split('/\s+/', Text::trim($part)) ?: [];
            $url = $bits[0] ?? '';
            $size = $bits[1] ?? '1x';
            $w = (float) $size * (str_ends_with($size, 'x') ? 1000 : 1);
            if ($url !== '' && (!$best || $w > $best['w'])) {
                $best = ['url' => $url, 'w' => $w];
            }
        }
        return $best['url'] ?? null;
    }

    private static function isPhoto(string $url): bool
    {
        $u = WebUrl::parse($url);
        if (!$u) {
            return false;
        }
        if (preg_match('/\.(svg|ico)$/i', $u->path)) {
            return false;
        }
        $upload = ($u->host === 'utfs.io' || str_ends_with($u->host, '.ufs.sh')) && str_starts_with($u->path, '/f/');
        return $upload || !preg_match(self::JUNK, $u->path);
    }

    private static function sameFile(string $url): string
    {
        $u = WebUrl::parse($url);
        if ($u && ($u->host === 'utfs.io' || str_ends_with($u->host, '.ufs.sh')) && preg_match('#^/f/([^/]+)$#', $u->path, $m)) {
            return "ut:$m[1]";
        }
        return $u ? $u->origin() . $u->path . ($u->query !== '' ? '?' . $u->query : '') : $url;
    }

    /** supplier-page.ts imagesFromHtml(): the photos of a page, in its order, each once, at full size. */
    public static function imagesFromHtml(string $html): array
    {
        $base = preg_match('/<base\b[^>]*>/i', $html, $bm) ? self::attr($bm[0], 'href') : null;
        $seen = [];
        $out = [];
        preg_match_all('/<(?:img|source)\b[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            $set = self::attr($tag, 'srcset') ?? self::attr($tag, 'data-srcset');
            $candidates = [self::attr($tag, 'src'), self::attr($tag, 'data-src'), self::attr($tag, 'data-original'), $set !== null ? self::widest(self::decode($set)) : null];
            foreach ($candidates as $c) {
                $url = $c ? ProductImages::originalImageUrl($c, $base) : null;
                if (!$url || !self::isPhoto($url)) {
                    continue;
                }
                $k = self::sameFile($url);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $out[] = $url;
            }
            if (count($out) >= self::MAX_PHOTOS) {
                break;
            }
        }
        return $out;
    }

    /** Every object of a JSON-LD tree (lists and "@graph" included). */
    private static function nodes(mixed $v, int $depth, array &$out): void
    {
        if ($depth > 6 || !is_array($v)) {
            return;
        }
        if (array_is_list($v)) {
            foreach ($v as $x) {
                self::nodes($x, $depth + 1, $out);
            }
            return;
        }
        $out[] = $v;
        foreach (['@graph', 'mainEntity', 'itemListElement', 'hasVariant', 'isVariantOf', 'subjectOf', 'video', 'image'] as $k) {
            if (array_key_exists($k, $v)) {
                self::nodes($v[$k], $depth + 1, $out);
            }
        }
    }

    private static function isType(array $o, string $t): bool
    {
        $ty = $o['@type'] ?? null;
        return $ty === $t || (is_array($ty) && in_array($t, $ty, true));
    }

    private static function ldImages(mixed $v): array
    {
        if (is_string($v)) {
            return [$v];
        }
        if (is_array($v) && array_is_list($v)) {
            return array_merge([], ...array_map([self::class, 'ldImages'], $v));
        }
        if (is_array($v)) {
            return array_slice(array_values(array_filter([$v['contentUrl'] ?? null, $v['url'] ?? null], 'is_string')), 0, 1);
        }
        return [];
    }

    private static function meta(string $html, string $key): string
    {
        preg_match_all('/<meta\b[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            $name = preg_match('/\b(?:property|name|itemprop)\s*=\s*["\']([^"\']+)["\']/i', $tag, $m) ? strtolower($m[1]) : null;
            if ($name !== $key) {
                continue;
            }
            if (preg_match('/\bcontent\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $c)) {
                $v = ($c[1] ?? '') !== '' ? $c[1] : ($c[2] ?? '');
                if ($v !== '') {
                    return Text::trim(self::decode($v));
                }
            }
        }
        return '';
    }

    /** extractProductPage(): its title, description and points, large pictures and videos. */
    public static function extract(string $html, string $pageUrl): array
    {
        $images = [];
        $seen = [];
        $add = static function (string $raw) use (&$images, &$seen, $pageUrl) {
            $abs = ProductImages::originalImageUrl($raw, $pageUrl);
            if (!$abs || !preg_match('/^https?:/', $abs) || preg_match('/\.(svg|ico)(\?|$)/i', $abs)) {
                return;
            }
            $big = self::bigImageUrl($abs);
            $key = (string) preg_replace('/\?.*$/s', '', $big);
            if (isset($seen[$key]) || count($images) >= self::MAX_IMAGES) {
                return;
            }
            $seen[$key] = true;
            $images[] = $big;
        };
        $title = '';
        $description = '';
        $ldVideos = [];
        preg_match_all('#<script[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>([\s\S]*?)</script>#i', $html, $blocks);
        foreach ($blocks[1] as $body) {
            $block = json_decode(Text::trim($body), true);
            if ($block === null && Text::trim($body) !== 'null') {
                continue;
            }
            $nodes = [];
            self::nodes($block, 0, $nodes);
            foreach ($nodes as $o) {
                if (self::isType($o, 'Product') || self::isType($o, 'ProductGroup')) {
                    foreach (self::ldImages($o['image'] ?? null) as $i) {
                        $add($i);
                    }
                    if ($title === '' && is_string($o['name'] ?? null)) {
                        $title = self::clean($o['name']);
                    }
                    if ($description === '' && is_string($o['description'] ?? null)) {
                        $description = self::clean($o['description']);
                    }
                }
                if (self::isType($o, 'VideoObject')) {
                    foreach (['embedUrl', 'contentUrl', 'url'] as $k) {
                        if (is_string($o[$k] ?? null)) {
                            $ldVideos[] = $o[$k];
                        }
                    }
                }
            }
        }
        preg_match_all('/"(?:hiRes|large|mainUrl)"\s*:\s*"(https:\/\/[^"]+?\.(?:jpe?g|png))"/', $html, $m);
        foreach ($m[1] as $u) {
            $add($u);
        }
        preg_match_all('/data-old-hires\s*=\s*"(https:\/\/[^"]+)"/', $html, $m);
        foreach ($m[1] as $u) {
            $add($u);
        }
        preg_match_all('/data-large_image\s*=\s*["\']([^"\']+)["\']/i', $html, $m);
        foreach ($m[1] as $u) {
            $add($u);
        }
        foreach (array_filter([self::meta($html, 'og:image:secure_url'), self::meta($html, 'og:image'), self::meta($html, 'twitter:image')]) as $u) {
            $add($u);
        }
        foreach (self::imagesFromHtml(preg_match('/<base\b/i', $html) ? $html : '<base href="' . str_replace('"', '%22', $pageUrl) . '">' . $html) as $u) {
            $add($u);
        }
        if ($title === '') {
            $title = self::meta($html, 'og:title');
        }
        if ($title === '') {
            $title = self::clean(preg_match('/<h1\b[^>]*>([\s\S]*?)<\/h1>/i', $html, $h) ? $h[1] : '');
        }
        if ($title === '') {
            $title = self::clean(preg_match('/<title\b[^>]*>([\s\S]*?)<\/title>/i', $html, $h) ? $h[1] : '');
        }
        if ($description === '') {
            $description = self::meta($html, 'og:description') ?: self::meta($html, 'description');
        }
        $videos = [];
        foreach ($ldVideos as $v) {
            foreach (self::videoLinks($v) as $x) {
                $videos[] = $x;
            }
        }
        foreach (self::videoLinks($html) as $x) {
            $videos[] = $x;
        }
        $bullets = [];
        if (preg_match('/id\s*=\s*["\']feature-bullets["\'][\s\S]*?<\/ul>/i', $html, $box)) {
            preg_match_all('/<span[^>]*class\s*=\s*["\'][^"\']*a-list-item[^"\']*["\'][^>]*>([\s\S]*?)<\/span>/i', $box[0], $sm);
            $bullets = array_slice(array_values(array_filter(array_map([self::class, 'clean'], $sm[1]), static fn ($t) => Text::len($t) > 3)), 0, 10);
        }
        return [
            'title' => Text::slice($title, 0, 200), 'description' => Text::slice($description, 0, 3000), 'bullets' => $bullets,
            'images' => $images, 'videos' => array_slice(array_values(array_unique($videos)), 0, 6),
        ];
    }

    /**
     * web-pages.ts readProductPage(): another site's product page, read by the server with the same
     * address checks as a supplier's picture, a short wait and a size limit.
     * @return array{url: string, ok: true, finds: array}|array{url: string, ok: false, error: string}
     */
    public static function read(string $raw): array
    {
        if (!WebUrl::parse($raw) || !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $raw)) {
            return ['url' => $raw, 'ok' => false, 'error' => 'Adresse invalide.'];
        }
        try {
            $r = ProductImages::fetch($raw, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Mozilla/5.0 (compatible; MenaShop/1.0; +product-pages)', 15, self::MAX_PAGE * 4, 'Page illisible.');
        } catch (ImageRefused $e) {
            return ['url' => $raw, 'ok' => false, 'error' => $e->getMessage()];
        }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['url' => $raw, 'ok' => false, 'error' => in_array($r['status'], [403, 503, 429], true) ? 'Le site refuse la lecture automatique : copiez les adresses des photos à la main.' : "Page illisible (erreur {$r['status']})."];
        }
        if ($r['type'] !== '' && !str_contains($r['type'], 'html') && !str_contains($r['type'], 'xml')) {
            return ['url' => $raw, 'ok' => false, 'error' => 'Ce lien ne mène pas à une page.'];
        }
        $html = substr($r['body'], 0, self::MAX_PAGE);
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        return ['url' => $r['url'], 'ok' => true, 'finds' => self::extract($html, $r['url'])];
    }
}
