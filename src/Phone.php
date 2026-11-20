<?php
declare(strict_types=1);

/**
 * Tunisian phone numbers: 8 digits after +216.
 * Mobile: 2x (Ooredoo), 4x, 5x (Orange), 9x (Tunisie Télécom). Fixed: 3x, 7x.
 */
final class Phone
{
    /** "+216 22 123 456", "0021622123456", "22.123.456", "٢٢١٢٣٤٥٦" → "22123456". Null when it is not a Tunisian number. */
    public static function normalize(string $input): ?string
    {
        // Arabic-keyboard digits as the digits the rest of the shop reads.
        $d = (string) preg_replace_callback('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', static fn ($m) => (string) (mb_ord($m[0]) & 0xF), $input);
        $d = (string) preg_replace('/[^\d+]/', '', $d);
        if (str_starts_with($d, '+216')) {
            $d = substr($d, 4);
        } elseif (str_starts_with($d, '00216')) {
            $d = substr($d, 5);
        } elseif (strlen($d) === 11 && str_starts_with($d, '216')) {
            $d = substr($d, 3);
        }
        $d = (string) preg_replace('/\D/', '', $d);
        // A 0 typed out of habit before the eight digits.
        if (strlen($d) === 9 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        if (strlen($d) !== 8 || !in_array($d[0], ['2', '3', '4', '5', '7', '9'], true)) {
            return null;
        }
        // Eight times the same digit is never a real line; it is how a fake order looks.
        if (preg_match('/^(\d)\1{7}$/', $d)) {
            return null;
        }
        return $d;
    }

    public static function isMobile(string $phone8): bool
    {
        return in_array($phone8[0] ?? '', ['2', '4', '5', '9'], true);
    }

    /** "22 123 456" */
    public static function format(string $phone8): string
    {
        return (string) preg_replace('/^(\d{2})(\d{3})(\d{3})$/', '$1 $2 $3', $phone8);
    }
}
