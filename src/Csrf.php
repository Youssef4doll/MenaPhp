<?php
declare(strict_types=1);

/** Every form carries a token tied to the visitor's session: a page on another site cannot send it. */
final class Csrf
{
    public static function token(): string
    {
        Cart::session();
        return $_SESSION['csrf'] ??= Tokens::random(24);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valid(): bool
    {
        Cart::session();
        $sent = $_POST['_csrf'] ?? '';
        return is_string($sent) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
    }
}
