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

    /** Headers sent with every page. */
    public static function securityHeaders(bool $admin = false): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; frame-src https://www.youtube-nocookie.com https://www.youtube.com; media-src 'self' https:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
        if (is_https()) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
        }
        if ($admin) {
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: no-store');
        }
    }
}
