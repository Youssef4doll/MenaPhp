<?php
declare(strict_types=1);

/**
 * Fixed windows against floods and scripted orders, kept in small files
 * (storage/rate): shared hosting has no shared memory, and the database
 * belongs to the Next.js shop's schema. A floor, not the real protection
 * (phone confirmation is).
 */
final class RateLimit
{
    public const LIMITS = [
        'order' => [20, 600],   // Loose: Tunisian carriers put many customers behind one address.
        'login' => [8, 900],
        'lookup' => [10, 600],
        'cart' => [60, 60],
        // The Next.js shop's LIMITS (rate-limit.ts).
        'suggest' => [120, 60],
        'events' => [120, 60],
        'quote' => [300, 60],
        'promo' => [30, 600],
        'review' => [10, 3600],
    ];

    /** True when this hit is allowed. */
    public static function hit(string $kind, string $who): bool
    {
        [$limit, $window] = self::LIMITS[$kind];
        $dir = ROOT . '/storage/rate';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return true;
        }
        $file = $dir . '/' . hash('sha256', $kind . '|' . $who) . '.json';
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return true;
        }
        try {
            flock($fh, LOCK_EX);
            $now = time();
            $raw = stream_get_contents($fh);
            $w = $raw ? json_decode($raw, true) : null;
            if (!is_array($w) || ($w['reset'] ?? 0) <= $now) {
                $w = ['count' => 0, 'reset' => $now + $window];
            }
            $w['count']++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($w));
            return $w['count'] <= $limit;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
