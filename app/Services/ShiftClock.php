<?php
namespace App\Services;

/**
 * Разбивка рабочей смены на дневные и ночные часы по ночному окну (по умолчанию 22:00–06:00, ТК РФ).
 * Чистый класс без БД. Смена через полночь (конец ≤ начало) трактуется как +24ч и целиком относится
 * к календарному дню СТАРТА (work_date). Ночное окно тоже может пересекать полночь.
 */
class ShiftClock
{
    /** 'HH:MM' → минуты от полуночи, либо null если формат не распознан. */
    private static function toMin(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $x)) { return null; }
        $h = (int) $x[1]; $m = (int) $x[2];
        if ($h > 24 || $m > 59) { return null; }
        return $h * 60 + $m;
    }

    /**
     * @return array{hours:float, day:float, night:float} — часы всего / дневные / ночные.
     * Пустые/некорректные start|end → нули (день без смены).
     */
    public static function split(string $start, string $end, string $nightStart = '22:00', string $nightEnd = '06:00'): array
    {
        $zero = ['hours' => 0.0, 'day' => 0.0, 'night' => 0.0];
        $s = self::toMin($start); $e = self::toMin($end);
        if ($s === null || $e === null) { return $zero; }
        if ($e <= $s) { $e += 24 * 60; }          // смена через полночь
        $total = $e - $s;
        if ($total <= 0) { return $zero; }

        $ns = self::toMin($nightStart); $ne = self::toMin($nightEnd);
        if ($ns === null || $ne === null) { $ns = 22 * 60; $ne = 6 * 60; }

        // Ночные интервалы покрываем на оси [0..48ч), чтобы накрыть и стартовый, и следующий день.
        $intervals = [];
        $push = function (int $a, int $b) use (&$intervals) { if ($b > $a) { $intervals[] = [$a, $b]; } };
        if ($ne <= $ns) {                         // окно через полночь (напр. 22:00→06:00)
            $push(0, $ne);                        // «хвост» ночи предыдущих суток до nightEnd
            $push($ns, 1440 + $ne);               // непрерывно nightStart текущих → nightEnd следующих
            $push($ns + 1440, 2 * 1440 + $ne);    // ночь следующих суток (для смен, заканчивающихся поздно)
        } else {                                  // окно внутри суток
            $push($ns, $ne);
            $push($ns + 1440, $ne + 1440);
        }
        $night = 0;
        foreach ($intervals as [$a, $b]) {
            $night += max(0, min($e, $b) - max($s, $a));   // пересечение смены с ночным интервалом
        }
        $night = min($night, $total);
        $day = $total - $night;
        return ['hours' => round($total / 60, 2), 'day' => round($day / 60, 2), 'night' => round($night / 60, 2)];
    }
}
