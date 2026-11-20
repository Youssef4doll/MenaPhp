<?php
declare(strict_types=1);

/** The shop's words in French and Arabic (lang/fr.php, lang/ar.php). */
final class Lang
{
    private static array $dicts = [];

    public static function dict(string $l): array
    {
        return self::$dicts[$l] ??= require ROOT . '/lang/' . ($l === 'ar' ? 'ar' : 'fr') . '.php';
    }

    /** t('buy.units', 3): a word from the page's language; closures are called with the arguments. */
    public static function t(string $path, mixed ...$args): string
    {
        $v = self::dict(lang());
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return $path;
            }
            $v = $v[$k];
        }
        if ($v instanceof Closure) {
            return (string) $v(...$args);
        }
        return is_string($v) ? $v : $path;
    }

    /** A list from the dictionary (the "how to order" steps). */
    public static function list(string $path): array
    {
        $v = self::dict(lang());
        foreach (explode('.', $path) as $k) {
            $v = is_array($v) ? ($v[$k] ?? []) : [];
        }
        return is_array($v) ? $v : [];
    }
}

function t(string $path, mixed ...$args): string
{
    return Lang::t($path, ...$args);
}
