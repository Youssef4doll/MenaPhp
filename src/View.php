<?php
declare(strict_types=1);

/** Pages are plain PHP templates in views/, printed inside a layout. Every value is escaped with e(). */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'shop/layout'): void
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            echo $content;
            return;
        }
        echo self::capture($layout, $data + ['content' => $content]);
    }

    public static function capture(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require ROOT . "/views/$template.php";
        return (string) ob_get_clean();
    }

    /** A UTC time from the database, shown in Tunisia's time: "12/11 14:39". */
    public static function time(?string $utc, string $format = 'd/m H:i'): string
    {
        if (!$utc) {
            return '';
        }
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone('Africa/Tunis'))->format($format);
    }

    /** Headers sent with every page: the Next.js shop's (next.config.ts securityHeaders). */
    public static function securityHeaders(bool $admin = false): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://connect.facebook.net https://www.googletagmanager.com https://analytics.tiktok.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' https:; media-src 'self' blob: https:; frame-src https://www.youtube-nocookie.com https://www.youtube.com https://player.vimeo.com https://www.tiktok.com https://www.instagram.com https://www.facebook.com; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'" . (is_https() ? '; upgrade-insecure-requests' : ''));
        if (is_https()) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        }
        if ($admin) {
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: no-store');
        }
    }
}
