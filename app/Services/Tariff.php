<?php
namespace App\Services;

use App\Core\Database;

class Tariff
{
    /**
     * Извлечь код страны из рег. номера досье.
     * Формат: FRA-00005/26 → код = буквы/цифры до первого дефиса.
     */
    public static function extractCountryCode(string $regNumber): ?string
    {
        $regNumber = strtoupper(trim($regNumber));
        if (preg_match('/^([A-Z0-9]+)-/', $regNumber, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Цена за досье по коду страны. Неизвестная страна → тариф «остальные» (группа 2, 70 ₽). */
    public static function priceForCountry(string $code): float
    {
        $row = Database::one(
            'SELECT pg.price AS price
               FROM countries c
               JOIN price_groups pg ON pg.group_no = c.group_no
              WHERE c.code = ?',
            [strtoupper($code)]
        );
        if ($row) {
            return (float) $row['price'];
        }
        // По умолчанию — тариф «остальные» (группа 2).
        $def = Database::scalar('SELECT price FROM price_groups WHERE group_no = 2');
        return $def !== false ? (float) $def : 70.0;
    }

    /** Цена по умолчанию (тариф «остальные»). */
    public static function defaultPrice(): float
    {
        $def = Database::scalar('SELECT price FROM price_groups WHERE group_no = 2');
        return $def !== false ? (float) $def : 70.0;
    }

    /** Группа страны или null. */
    public static function groupForCountry(string $code): ?int
    {
        $g = Database::scalar('SELECT group_no FROM countries WHERE code = ?', [strtoupper($code)]);
        return $g === false ? null : (int) $g;
    }

    public static function countryExists(string $code): bool
    {
        return (bool) Database::scalar('SELECT 1 FROM countries WHERE code = ?', [strtoupper($code)]);
    }
}
