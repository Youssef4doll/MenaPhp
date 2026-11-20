<?php
declare(strict_types=1);

/** Money is carried as millimes (integers): 1 DT = 1 000 millimes. */
final class Money
{
    /**
     * "89,900 DT" (fr) or "89,900 د.ت" (ar). Latin digits in both, as Tunisian receipts print.
     * No thousands separator: with three decimals a spaced thousand reads as two prices.
     */
    public static function format(int $millimes, ?string $locale = null): string
    {
        $locale ??= lang();
        $sign = $millimes < 0 ? "\u{2212}" : '';
        $abs = abs($millimes);
        $n = $sign . intdiv($abs, 1000) . ',' . str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT);
        return $locale === 'ar' ? "$n د.ت" : "$n DT";
    }

    /** Back-office input: "89,9", "89.900", "1 200" → millimes. Null when unreadable. */
    public static function parse(string $input): ?int
    {
        $clean = str_replace(',', '.', (string) preg_replace('/\s|DT|TND|د\.ت/iu', '', $input));
        if (!preg_match('/^\d+(\.\d{0,3})?$/', $clean)) {
            return null;
        }
        $parts = explode('.', $clean);
        return (int) $parts[0] * 1000 + (int) str_pad($parts[1] ?? '', 3, '0');
    }

    /** Millimes → "89.900" for an input field. */
    public static function input(?int $millimes): string
    {
        return $millimes === null ? '' : number_format($millimes / 1000, 3, '.', '');
    }
}
