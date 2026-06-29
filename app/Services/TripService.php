<?php

namespace App\Services;

use App\Core\Database;

/**
 * Командировки: расчёт сметы (суточные по сегментам пребывания + проживание + проезд +
 * доп.расходы), факт и валидация заявки к подаче.
 *
 * Суточные = Σ по сегментам (календарные дни сегмента × ставка(источник, РФ/зарубеж)).
 * Ставки задаёт менеджер финансов ({@see per_diem_rates}). До факта в бюджет идёт план,
 * после внесения факта бухгалтером — факт ({@see TripBudgetService}).
 */
class TripService
{
    public const LOC = ['rf' => 'РФ', 'abroad' => 'Зарубеж'];

    public const STATUS = [
        'draft'       => 'Черновик',
        'on_approval' => 'На согласовании',
        'approved'    => 'Утверждён',
        'rejected'    => 'Отклонён',
        'revision'    => 'На доработке',
    ];

    public const ATT_KIND = ['accommodation' => 'Проживание', 'travel' => 'Проезд', 'other' => 'Иное'];

    /** Ставка суточных для источника и места пребывания (0, если не задана). */
    public static function perDiemRate(int $sourceId, string $location): float
    {
        $v = Database::scalar('SELECT amount FROM per_diem_rates WHERE source_id = ? AND location = ?', [$sourceId, $location]);
        return $v === false ? 0.0 : (float) $v;
    }

    /** Календарных дней в периоде включительно. */
    public static function calDays(string $start, string $end): int
    {
        $a = strtotime($start); $b = strtotime($end);
        if ($a === false || $b === false || $b < $a) { return 0; }
        return (int) floor(($b - $a) / 86400) + 1;
    }

    public static function segments(int $tripId): array
    {
        return Database::all('SELECT * FROM trip_segments WHERE trip_id = ? ORDER BY start_date, id', [$tripId]);
    }

    public static function extras(int $tripId): array
    {
        return Database::all(
            'SELECT e.*, k.name AS kind_name FROM trip_extra_expenses e
               JOIN trip_expense_kinds k ON k.id = e.kind_id
              WHERE e.trip_id = ? ORDER BY e.id', [$tripId]);
    }

    public static function attachments(int $tripId): array
    {
        return Database::all('SELECT * FROM trip_attachments WHERE trip_id = ? ORDER BY id', [$tripId]);
    }

    /**
     * Плановая смета заявки.
     * @return array{per_diem:float, days_rf:int, days_abroad:int, lodging:float, travel:float, extras:float, total:float}
     */
    public static function estimate(array $trip): array
    {
        $tripId = (int) $trip['id'];
        $src    = (int) $trip['source_id'];
        $pd = 0.0; $daysRf = 0; $daysAb = 0;
        foreach (self::segments($tripId) as $s) {
            $d = self::calDays($s['start_date'], $s['end_date']);
            $pd += $d * self::perDiemRate($src, $s['location']);
            if ($s['location'] === 'abroad') { $daysAb += $d; } else { $daysRf += $d; }
        }
        $lodging = (float) $trip['lodging_sum'];
        $travel  = (float) $trip['travel_sum'];
        $extras  = (float) Database::scalar('SELECT COALESCE(SUM(amount),0) FROM trip_extra_expenses WHERE trip_id = ?', [$tripId]);
        return [
            'per_diem' => round($pd, 2), 'days_rf' => $daysRf, 'days_abroad' => $daysAb,
            'lodging'  => round($lodging, 2), 'travel' => round($travel, 2), 'extras' => round($extras, 2),
            'total'    => round($pd + $lodging + $travel + $extras, 2),
        ];
    }

    /** Сумма факта (если внесён бухгалтером), иначе null. */
    public static function factTotal(array $trip): ?float
    {
        if (empty($trip['fact_at'])) { return null; }
        return round((float) $trip['fact_per_diem'] + (float) $trip['fact_lodging']
            + (float) $trip['fact_travel'] + (float) $trip['fact_other'], 2);
    }

    /** Сумма, списываемая из бюджета: факт (если внесён) либо плановый снимок. */
    public static function effectiveTotal(array $trip): float
    {
        $f = self::factTotal($trip);
        return $f !== null ? $f : (float) $trip['plan_total'];
    }

    /** Год, к которому относится командировка в бюджете (по дате начала). */
    public static function budgetYear(array $trip): int
    {
        return (int) substr((string) $trip['date_from'], 0, 4);
    }

    /**
     * Проверка заявки к подаче на согласование: сегменты покрывают период без пропусков,
     * приложены подтверждения стоимости проживания и проезда, смета не пустая.
     * @return string[] список проблем (пусто = можно подавать)
     */
    public static function validateForSubmit(array $trip): array
    {
        $issues = [];
        $tripId = (int) $trip['id'];
        $segs = self::segments($tripId);
        if (!$segs) {
            $issues[] = 'не заданы сегменты пребывания (РФ/зарубеж)';
        } else {
            // сегменты должны идти подряд и покрывать весь период командировки без пропусков
            $cover = $segs[0]['start_date'] === $trip['date_from']
                  && $segs[count($segs) - 1]['end_date'] === $trip['date_to'];
            for ($i = 1; $i < count($segs) && $cover; $i++) {
                $expected = date('Y-m-d', strtotime($segs[$i - 1]['end_date'] . ' +1 day'));
                if ($segs[$i]['start_date'] !== $expected) { $cover = false; }
            }
            if (!$cover) {
                $issues[] = 'сегменты пребывания должны идти подряд и покрывать весь период командировки (' .
                    date('d.m.Y', strtotime($trip['date_from'])) . ' — ' . date('d.m.Y', strtotime($trip['date_to'])) . ') без пропусков';
            }
        }
        $att = self::attachments($tripId);
        $has = fn(string $kind) => (bool) array_filter($att, fn($a) => $a['kind'] === $kind);
        if ((float) $trip['lodging_sum'] > 0 && !$has('accommodation')) {
            $issues[] = 'не приложен документ, подтверждающий стоимость проживания';
        }
        if ((float) $trip['travel_sum'] > 0 && !$has('travel')) {
            $issues[] = 'не приложен документ, подтверждающий стоимость проезда';
        }
        if (self::estimate($trip)['total'] <= 0) {
            $issues[] = 'смета пустая — укажите суточные/проживание/проезд/доп.расходы';
        }
        return $issues;
    }
}
