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

    /** Начало ночного времени 'HH:MM' (ТК ст.96, по умолчанию 22:00). */
    public static function nightStart(): string
    {
        $v = (string) self::get('night_start', '22:00');
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : '22:00';
    }

    /** Конец ночного времени 'HH:MM' (по умолчанию 06:00). */
    public static function nightEnd(): string
    {
        $v = (string) self::get('night_end', '06:00');
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : '06:00';
    }

    /** Стандартное время смен графика 2/2 'HH:MM' (задаётся на странице графика). */
    private static function hhmm(string $key, string $def): string
    {
        $v = (string) self::get($key, $def);
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : $def;
    }
    public static function shiftDayStart(): string   { return self::hhmm('shift_day_start', '08:00'); }
    public static function shiftDayEnd(): string     { return self::hhmm('shift_day_end', '20:00'); }
    public static function shiftNightStart(): string { return self::hhmm('shift_night_start', '20:00'); }
    public static function shiftNightEnd(): string   { return self::hhmm('shift_night_end', '08:00'); }
}
