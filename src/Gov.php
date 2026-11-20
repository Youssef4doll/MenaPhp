<?php
declare(strict_types=1);

/** The 24 governorates, with the Arabic name shown when the site is in Arabic. */
final class Gov
{
    public const LIST = [
        'Tunis' => 'تونس', 'Ariana' => 'أريانة', 'Ben Arous' => 'بن عروس', 'Manouba' => 'منوبة',
        'Nabeul' => 'نابل', 'Zaghouan' => 'زغوان', 'Bizerte' => 'بنزرت', 'Béja' => 'باجة',
        'Jendouba' => 'جندوبة', 'Le Kef' => 'الكاف', 'Siliana' => 'سليانة', 'Sousse' => 'سوسة',
        'Monastir' => 'المنستير', 'Mahdia' => 'المهدية', 'Sfax' => 'صفاقس', 'Kairouan' => 'القيروان',
        'Kasserine' => 'القصرين', 'Sidi Bouzid' => 'سيدي بوزيد', 'Gabès' => 'قابس', 'Médenine' => 'مدنين',
        'Tataouine' => 'تطاوين', 'Gafsa' => 'قفصة', 'Tozeur' => 'توزر', 'Kébili' => 'قبلي',
    ];

    public const GRAND_TUNIS = ['Tunis', 'Ariana', 'Ben Arous', 'Manouba'];

    public static function valid(string $id): bool
    {
        return array_key_exists($id, self::LIST);
    }

    public static function label(string $id, ?string $locale = null): string
    {
        return ($locale ?? lang()) === 'ar' ? (self::LIST[$id] ?? $id) : $id;
    }

    /** "Livraison 24 gouvernorats" is said only while the shop really delivers to all of them. */
    public static function everywhere(array $zones): bool
    {
        foreach (array_keys(self::LIST) as $g) {
            if (($zones[$g]['enabled'] ?? true) === false) {
                return false;
            }
        }
        return true;
    }
}
