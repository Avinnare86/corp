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

    // ===== Дневной повышающий коэффициент к тарифу проверки анкет =====

    public const COEFF_MIN = 1.0;
    public const COEFF_MAX = 2.0;

    /** @var array<string,float>|null статический кэш коэффициентов по датам (YYYY-MM-DD => coeff) */
    private static ?array $coeffCache = null;

    private static function loadCoeffs(): array
    {
        if (self::$coeffCache === null) {
            self::$coeffCache = [];
            foreach (Database::all('SELECT work_date, coefficient FROM tariff_day_coeff') as $r) {
                self::$coeffCache[substr((string) $r['work_date'], 0, 10)] = (float) $r['coefficient'];
            }
        }
        return self::$coeffCache;
    }

    /** Коэффициент тарифа за день (YYYY-MM-DD или с временем). По умолчанию 1.0. */
    public static function dayCoeff(string $date): float
    {
        $c = self::loadCoeffs()[substr($date, 0, 10)] ?? 1.0;
        return $c > 0 ? $c : 1.0;
    }

    public static function clampCoeff(float $c): float
    {
        return max(self::COEFF_MIN, min(self::COEFF_MAX, $c));
    }

    /** Установить/обновить коэффициент дня (значение приводится к диапазону 1.0–2.0). */
    public static function setDayCoeff(string $date, float $coeff, int $byUserId): void
    {
        $d = substr($date, 0, 10);
        $coeff = self::clampCoeff($coeff);
        $exists = Database::scalar('SELECT 1 FROM tariff_day_coeff WHERE work_date = ?', [$d]);
        if ($exists) {
            Database::run('UPDATE tariff_day_coeff SET coefficient=?, set_by=?, set_at=? WHERE work_date=?',
                [$coeff, $byUserId, date('Y-m-d H:i:s'), $d]);
        } else {
            Database::insert('INSERT INTO tariff_day_coeff (work_date, coefficient, set_by, set_at) VALUES (?,?,?,?)',
                [$d, $coeff, $byUserId, date('Y-m-d H:i:s')]);
        }
        self::$coeffCache = null; // сброс кэша
    }

    /** Предыдущий рабочий день перед датой (по производственному календарю; иначе Пн–Пт). */
    public static function prevWorkingDay(string $date): string
    {
        $t = strtotime(substr($date, 0, 10));
        for ($i = 0; $i < 14; $i++) {
            $t -= 86400;
            $d = date('Y-m-d', $t);
            $w = \App\Services\ProductionCalendar::isWorkingDay($d);
            if ($w === null) { $w = (int) date('N', $t) <= 5; }
            if ($w) { return $d; }
        }
        return date('Y-m-d', strtotime(substr($date, 0, 10) . ' -1 day'));
    }

    /**
     * Может ли менеджер анкет редактировать коэффициент за дату: день в день (сегодня) либо за
     * прошлый рабочий день на следующий рабочий день (сегодня — следующий рабочий после даты).
     * Админ редактирует любой день, минуя эту проверку.
     */
    public static function managerCanEdit(string $date): bool
    {
        $d = substr($date, 0, 10);
        $today = date('Y-m-d');
        if ($d === $today) { return true; }
        return $d === self::prevWorkingDay($today);
    }
}
