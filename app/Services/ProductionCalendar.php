<?php
namespace App\Services;

use App\Core\Database;

/**
 * Производственный календарь РФ. Источник — isdayoff.ru (бесплатно, без ключа):
 *   GET https://isdayoff.ru/api/getdata?year=YYYY → строка по дням года, символ:
 *   0 = рабочий, 1 = нерабочий (выходной/праздник), 2 = сокращённый (предпраздничный, рабочий), 4 = рабочий.
 * Хранится по годам в таблице prod_calendar. Чтение — без сети (для расчёта ЗП); загрузка/обновление —
 * на миграции (засев текущего и следующего года) и кнопкой в админке (когда публикуются переносы, ~2 р/год).
 */
class ProductionCalendar
{
    /** @var array<int,?string> кэш строк года на запрос */
    private static array $cache = [];

    /** Строка календаря за год из БД (или null, если не загружена). Без сети. */
    private static function yearData(int $year): ?string
    {
        if (array_key_exists($year, self::$cache)) { return self::$cache[$year]; }
        $d = Database::scalar('SELECT data FROM prod_calendar WHERE year = ?', [$year]);
        return self::$cache[$year] = ($d !== false && $d !== null && $d !== '') ? (string) $d : null;
    }

    /** Загрузить календарь года с isdayoff.ru и сохранить. Возвращает true при успехе. */
    public static function fetch(int $year): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
        $resp = @file_get_contents("https://isdayoff.ru/api/getdata?year={$year}", false, $ctx);
        if ($resp === false) { return false; }
        $resp = trim($resp);
        // Валидный ответ — строка из 0/1/2/4 длиной год (365/366); коды ошибок ("100","199") короче.
        if (!preg_match('/^[0124]+$/', $resp) || strlen($resp) < 365) { return false; }
        $now = date('Y-m-d H:i:s');
        if (Database::scalar('SELECT 1 FROM prod_calendar WHERE year = ?', [$year])) {
            Database::run('UPDATE prod_calendar SET data = ?, fetched_at = ? WHERE year = ?', [$resp, $now, $year]);
        } else {
            Database::insert('INSERT INTO prod_calendar (year, data, fetched_at) VALUES (?,?,?)', [$year, $resp, $now]);
        }
        self::$cache[$year] = $resp;
        return true;
    }

    /** Загрузить год, если ещё не сохранён (ленивый засев). Сетевые сбои гасит молча. */
    public static function ensure(int $year): void
    {
        if (self::yearData($year) !== null) { return; }
        try { self::fetch($year); } catch (\Throwable $e) { /* офлайн — вызывающий откатится на Пн-Пт */ }
    }

    /**
     * Рабочих дней в месяце по производственному календарю РФ, либо null, если календарь на этот
     * год не загружен (вызывающий откатывается на Пн-Пт). Сокращённый день (2) считается рабочим.
     * Без сети — только из БД.
     */
    public static function workingDaysInMonth(int $year, int $month): ?int
    {
        $s = self::yearData($year);
        if ($s === null) { return null; }
        $start = (int) date('z', mktime(0, 0, 0, $month, 1, $year));   // 0-based день года
        $days  = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        if (strlen($s) < $start + $days) { return null; }              // строка короче ожидаемого — не доверяем
        $cnt = 0;
        for ($i = 0; $i < $days; $i++) {
            if (($s[$start + $i] ?? '1') !== '1') { $cnt++; }          // 0/2/4 — рабочие
        }
        return $cnt;
    }

    /** Рабочий ли день (YYYY-MM-DD) по календарю; null — если год не загружен. Без сети. */
    public static function isWorkingDay(string $date): ?bool
    {
        $ts = strtotime(substr($date, 0, 10));
        if ($ts === false) { return null; }
        $s = self::yearData((int) date('Y', $ts));
        if ($s === null) { return null; }
        $doy = (int) date('z', $ts);
        if (!isset($s[$doy])) { return null; }
        return $s[$doy] !== '1';
    }

    /** Когда последний раз обновляли год (для отображения в админке), либо null. */
    public static function fetchedAt(int $year): ?string
    {
        $v = Database::scalar('SELECT fetched_at FROM prod_calendar WHERE year = ?', [$year]);
        return ($v !== false && $v !== null) ? (string) $v : null;
    }
}
