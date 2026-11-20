<?php
declare(strict_types=1);

/**
 * Random secrets and keyed hashes, made exactly as the Next.js shop makes
 * them (src/lib/tokens.ts) with the same APP_SECRET: a tracking link or a
 * back-office session from one version works in the other.
 */
final class Tokens
{
    private static function secret(): string
    {
        $s = (string) config('app_secret', '');
        if (strlen($s) < 32) {
            throw new RuntimeException('app_secret in config.php must be at least 32 characters.');
        }
        return $s;
    }

    public static function hasSecret(): bool
    {
        return strlen((string) config('app_secret', '')) >= 32;
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** A URL-safe random secret (newToken). */
    public static function random(int $bytes = 24): string
    {
        return self::b64url(random_bytes($bytes));
    }

    /** Keyed hash, hex: what is stored in place of a token (hashToken). */
    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, self::secret());
    }

    /** An IP, hashed with the secret and the day (UTC): groups abuse without keeping addresses (hashIp). */
    public static function hashIp(string $ip): string
    {
        return substr(hash_hmac('sha256', gmdate('Y-m-d') . '|' . $ip, self::secret()), 0, 32);
    }

    /** "MN-7K3Q9P": no 0/O or 1/I, easy to read over the phone (newOrderRef). */
    public static function orderRef(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $s = '';
        foreach (str_split(random_bytes(6)) as $b) {
            $s .= $alphabet[ord($b) % strlen($alphabet)];
        }
        return "MN-$s";
    }

    /** The order's tracking-link token, made again from its salt whenever needed (accessTokenFromSalt). */
    public static function orderToken(string $salt): string
    {
        return substr(self::b64url(hash_hmac('sha256', 'order-access:' . $salt, self::secret(), true)), 0, 32);
    }

    public static function looksLikeToken(string $t): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{16,64}$/', $t);
    }
}
