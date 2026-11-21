<?php
declare(strict_types=1);

/**
 * The settings the owner edits in the back office: the "Setting" row with
 * key "settings" (JSON), shared with the Next.js shop. Stored values are laid
 * over the defaults section by section, as mergeSettings does there, so a
 * setting either version adds never breaks the other.
 */
final class Settings
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        $zones = [];
        foreach (array_keys(Gov::LIST) as $g) {
            $zones[$g] = in_array($g, Gov::GRAND_TUNIS, true) ? ['minDays' => 1, 'maxDays' => 2, 'enabled' => true] : ['minDays' => 2, 'maxDays' => 3, 'enabled' => true];
        }
        return [
            'shop' => [
                'name' => 'Mena',
                'tagline' => 'De belles choses, livrées chez vous. Vous payez à la réception.',
                'taglineAr' => 'حاجات مزيانة توصلك لدارك، وتخلّص كي تستلم.',
                'phone' => '', 'email' => '', 'address' => '', 'legalName' => '', 'legalId' => '',
                'instagram' => '', 'facebook' => '', 'tiktok' => '',
            ],
            'hero' => ['title' => 'Choisi avec soin. Payé à la livraison.', 'titleAr' => 'مختار بعناية. تخلّص كي توصلك.', 'imageId' => null, 'slides' => []],
            'contact' => ['whatsapp' => '', 'messenger' => ''],
            'delivery' => ['carrierName' => 'Livraison à domicile', 'fee' => 8000, 'zones' => $zones, 'freeThreshold' => null, 'openBeforePaying' => true],
            'returns' => ['days' => 10],
            'checkout' => ['askEmail' => false, 'maxOrdersPerPhonePerDay' => 3],
            // The other sections of the Next.js shop's settings, with its defaults (settings-schema.ts).
            'costs' => ['carrierPerParcel' => null, 'returnPerParcel' => null],
            'payments' => ['cod' => true, 'card' => false, 'konnectWalletId' => '', 'konnectSandbox' => true],
            'tracking' => ['metaPixelId' => '', 'ga4Id' => '', 'gtmId' => '', 'tiktokPixelId' => '', 'purchaseAt' => 'placed'],
            'notify' => ['smsUrl' => '', 'smsMethod' => 'GET', 'smsBody' => '', 'smsSender' => 'MENA', 'smsOnPlaced' => true, 'smsOnShipped' => true, 'emailFrom' => '', 'orderAlertTo' => ''],
        ];
    }

    /** All settings, read once per request. */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $stored = Cache::remember('settings', static function () {
            try {
                $v = Db::value('SELECT value FROM "Setting" WHERE key = ?', ['settings']);
                return is_string($v) ? (json_decode($v, true) ?: []) : [];
            } catch (Throwable $e) {
                error_log('[settings] ' . $e->getMessage());
                return [];
            }
        });
        return self::$cache = self::merge($stored);
    }

    /** Stored JSON over the defaults; a section of the wrong shape falls back alone. */
    public static function merge(array $stored): array
    {
        $out = self::defaults();
        foreach ($out as $section => $def) {
            $v = $stored[$section] ?? null;
            if (is_array($v)) {
                $out[$section] = array_replace($def, $v);
            }
        }
        // Every governorate keeps a zone, whatever was stored.
        $out['delivery']['zones'] = array_replace(self::defaults()['delivery']['zones'], is_array($out['delivery']['zones'] ?? null) ? $out['delivery']['zones'] : []);
        $out['delivery']['fee'] = max(0, (int) ($out['delivery']['fee'] ?? 8000));
        $ft = $out['delivery']['freeThreshold'] ?? null;
        $out['delivery']['freeThreshold'] = is_numeric($ft) ? (int) $ft : null;
        $out['returns']['days'] = max(10, (int) ($out['returns']['days'] ?? 10));
        $out['checkout']['maxOrdersPerPhonePerDay'] = max(1, (int) ($out['checkout']['maxOrdersPerPhonePerDay'] ?? 3));
        foreach ($out['delivery']['zones'] as $g => $z) {
            $out['delivery']['zones'][$g] = ['minDays' => (int) ($z['minDays'] ?? 2), 'maxDays' => (int) ($z['maxDays'] ?? 3), 'enabled' => ($z['enabled'] ?? true) !== false];
        }
        return $out;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $v = self::all();
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return $default;
            }
            $v = $v[$k];
        }
        return $v;
    }

    /**
     * Saves changed sections over what is stored now (read again, so a change
     * made meanwhile in the other version of the shop is kept), never touching
     * sections this version does not edit.
     */
    public static function save(array $changes): void
    {
        Db::tx(static function () use ($changes) {
            $row = Db::one('SELECT value FROM "Setting" WHERE key = ? FOR UPDATE', ['settings']);
            $stored = $row ? (json_decode((string) $row['value'], true) ?: []) : [];
            foreach ($changes as $section => $values) {
                $stored[$section] = array_replace(is_array($stored[$section] ?? null) ? $stored[$section] : [], $values);
            }
            $json = json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($row) {
                Db::run('UPDATE "Setting" SET value = ?::jsonb, "updatedAt" = ? WHERE key = ?', [$json, Db::now(), 'settings']);
            } else {
                Db::run('INSERT INTO "Setting" (key, value, "updatedAt") VALUES (?, ?::jsonb, ?)', ['settings', $json, Db::now()]);
            }
        });
        self::$cache = null;
        Cache::flush();
    }

    /** "1 à 2 jours ouvrés" / "خلال 1 إلى 2 أيام عمل" (deliveryRangeLabel). A range, never a promised date. */
    public static function rangeLabel(int $minDays, int $maxDays, ?string $locale = null): string
    {
        $locale ??= lang();
        $max = max($minDays, $maxDays);
        if ($locale === 'ar') {
            if ($max === 0) {
                return 'نفس اليوم';
            }
            if ($minDays === $max) {
                return "خلال $max " . ($max === 1 ? 'يوم عمل' : 'أيام عمل');
            }
            return "خلال $minDays إلى $max أيام عمل";
        }
        if ($max === 0) {
            return 'le jour même';
        }
        if ($minDays === $max) {
            return "$max jour" . ($max > 1 ? 's' : '') . ' ouvré' . ($max > 1 ? 's' : '');
        }
        return "$minDays à $max jours ouvrés";
    }

    /** The shortest and longest delay over the governorates delivered to; null when none (deliverySpan). */
    public static function span(): ?array
    {
        $on = array_filter(self::get('delivery.zones', []), static fn ($z) => $z['enabled']);
        if (!$on) {
            return null;
        }
        return ['minDays' => min(array_column($on, 'minDays')), 'maxDays' => max(array_map(static fn ($z) => max($z['minDays'], $z['maxDays']), $on))];
    }

    /** For the tests. */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
