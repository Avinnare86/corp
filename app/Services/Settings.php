<?php
namespace App\Services;

use App\Core\Database;

class Settings
{
    private static array $cache = [];

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $val = Database::scalar('SELECT sval FROM settings WHERE skey = ?', [$key]);
        if ($val === false) {
            $val = $default;
        }
        self::$cache[$key] = $val;
        return $val;
    }

    public static function set(string $key, $value): void
    {
        $exists = Database::scalar('SELECT skey FROM settings WHERE skey = ?', [$key]);
        if ($exists !== false) {
            Database::run('UPDATE settings SET sval = ? WHERE skey = ?', [(string) $value, $key]);
        } else {
            Database::run('INSERT INTO settings (skey, sval) VALUES (?,?)', [$key, (string) $value]);
        }
        self::$cache[$key] = (string) $value;
    }

    public static function inspectionPercent(): float
    {
        return (float) self::get('inspection_percent', 8);
    }

    /** Шаг ступенчатой эскалации штрафа за повтор (надбавка к множителю за каждое повторение). */
    public static function penaltyStep(): float
    {
        return (float) self::get('penalty_step', 0.5);
    }

    /** Потолок множителя штрафа (плато, чтобы эскалация не росла бесконечно). */
    public static function penaltyMaxMultiplier(): float
    {
        return (float) self::get('penalty_max_multiplier', 2.0);
    }

    /** Целевая дневная норма анкет (ориентир для целеполагания, на выплату не влияет). */
    public static function dailyNorm(): float
    {
        return (float) self::get('daily_norm', 60);
    }

    /** Доплата за ночные часы, % к часовой ставке (ТК ст.154, минимум 20%). */
    public static function nightPct(): float
    {
        return (float) self::get('night_pct', 20);
    }

    /** Множитель оплаты праздничного часа (ТК ст.153, минимум ×2). */
    public static function holidayMult(): float
    {
        return (float) self::get('holiday_mult', 2);
    }

    /** Множитель оплаты сверхурочного часа (ТК ст.152). */
    public static function overtimeMult(): float
    {
        return (float) self::get('overtime_mult', 1.5);
    }
}
