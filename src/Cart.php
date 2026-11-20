<?php
declare(strict_types=1);

/**
 * The basket: ids and quantities only, in the visitor's PHP session. Prices
 * are never kept: every page reads them again from the catalog (Pricing).
 */
final class Cart
{
    /** Starts the session once, with a cookie scripts cannot read. */
    public static function session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];
            return;
        }
        $dir = ROOT . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_name('mena_s');
        session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public static function lines(): array
    {
        self::session();
        return array_values(array_filter($_SESSION['cart'] ?? [], 'is_array'));
    }

    public static function count(): int
    {
        return array_sum(array_map(static fn ($l) => (int) ($l['qty'] ?? 0), self::lines()));
    }

    /** False when the basket is full. An offer replaces another offer of the same product and option. */
    public static function add(string $productId, ?string $variantId, ?string $offerId, int $qty): bool
    {
        self::session();
        $lines = self::lines();
        $qty = max(1, min(Pricing::MAX_QTY_PER_LINE, $qty));
        foreach ($lines as $i => $l) {
            if ($l['productId'] === $productId && ($l['variantId'] ?? null) === $variantId) {
                if ($offerId !== null && ($l['offerId'] ?? null) !== null) {
                    $lines[$i]['offerId'] = $offerId;
                    $lines[$i]['qty'] = 1;
                    $_SESSION['cart'] = $lines;
                    return true;
                }
                if ($offerId === null && ($l['offerId'] ?? null) === null) {
                    $lines[$i]['qty'] = min(Pricing::MAX_QTY_PER_LINE, (int) $l['qty'] + $qty);
                    $_SESSION['cart'] = $lines;
                    return true;
                }
            }
        }
        if (count($lines) >= Pricing::MAX_LINES) {
            return false;
        }
        $lines[] = ['productId' => $productId, 'variantId' => $variantId, 'offerId' => $offerId, 'qty' => $offerId !== null ? 1 : $qty];
        $_SESSION['cart'] = $lines;
        return true;
    }

    public static function setQty(int $index, int $qty): void
    {
        self::session();
        $lines = self::lines();
        if (!isset($lines[$index])) {
            return;
        }
        if ($qty <= 0) {
            array_splice($lines, $index, 1);
        } elseif (($lines[$index]['offerId'] ?? null) === null) {
            $lines[$index]['qty'] = min(Pricing::MAX_QTY_PER_LINE, $qty);
        }
        $_SESSION['cart'] = array_values($lines);
    }

    /** Lines the catalog refused (gone, no longer offered) leave the basket; reduced quantities are kept as reduced. */
    public static function settle(array $quote): void
    {
        self::session();
        $keep = [];
        foreach (self::lines() as $l) {
            $drop = false;
            foreach ($quote['problems'] as $pb) {
                if ($pb['productId'] === $l['productId'] && ($pb['variantId'] ?? null) === ($l['variantId'] ?? null) && ($pb['offerId'] ?? null) === ($l['offerId'] ?? null)) {
                    if (in_array($pb['kind'], ['not_found', 'offer_unavailable', 'variant_required'], true) || ($pb['kind'] === 'unavailable')) {
                        $drop = true;
                    } elseif ($pb['kind'] === 'qty_reduced') {
                        $l['qty'] = (int) $pb['available'];
                    }
                }
            }
            if (!$drop) {
                $keep[] = $l;
            }
        }
        $_SESSION['cart'] = $keep;
    }

    public static function clear(): void
    {
        self::session();
        $_SESSION['cart'] = [];
    }
}
