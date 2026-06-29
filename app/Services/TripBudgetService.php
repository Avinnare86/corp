<?php

namespace App\Services;

use App\Core\Database;

/**
 * Бюджет командировок: отдел × источник финансирования × год (отдельный пул от ФОТ).
 * Резерв (committed) = Σ утверждённых заявок отдела по источнику за год: факт, если внесён
 * бухгалтером, иначе плановый снимок сметы. Доступно = бюджет − резерв.
 *
 * Гейт срабатывает только если бюджет по источнику задан (>0) — как в стимуле
 * ({@see StimulusBudgetService}): незаданный бюджет не блокирует согласование.
 */
class TripBudgetService
{
    public static function budgetAmount(int $deptId, int $sourceId, int $year): float
    {
        $v = Database::scalar('SELECT amount FROM trip_budgets WHERE department_id = ? AND year = ? AND source_id = ?',
            [$deptId, $year, $sourceId]);
        return $v === false ? 0.0 : (float) $v;
    }

    /** Сумма утверждённых заявок (факт либо план) по отделу/источнику/году. */
    public static function committed(int $deptId, int $sourceId, int $year, ?int $excludeTripId = null): float
    {
        $sql = "SELECT * FROM trip_requests
                 WHERE department_id = ? AND source_id = ? AND status = 'approved'
                   AND archived_at IS NULL AND substr(date_from,1,4) = ?";
        $params = [$deptId, $sourceId, (string) $year];
        if ($excludeTripId) { $sql .= ' AND id <> ?'; $params[] = $excludeTripId; }
        $sum = 0.0;
        foreach (Database::all($sql, $params) as $t) { $sum += TripService::effectiveTotal($t); }
        return round($sum, 2);
    }

    public static function available(int $deptId, int $sourceId, int $year, ?int $excludeTripId = null): float
    {
        return round(self::budgetAmount($deptId, $sourceId, $year) - self::committed($deptId, $sourceId, $year, $excludeTripId), 2);
    }

    /** Разбивка по источникам для отдела/года (для панели и страницы бюджета). */
    public static function breakdown(int $deptId, int $year): array
    {
        $rows = [];
        foreach (Database::all('SELECT * FROM pay_sources ORDER BY id') as $s) {
            $b = self::budgetAmount($deptId, (int) $s['id'], $year);
            $c = self::committed($deptId, (int) $s['id'], $year);
            $rows[] = ['id' => (int) $s['id'], 'name' => $s['name'], 'kind' => $s['kind'],
                       'budget' => $b, 'committed' => $c, 'available' => round($b - $c, 2)];
        }
        return $rows;
    }

    /**
     * Гейт на утверждение: хватает ли остатка по источнику отдела на плановую сумму.
     * @return array{ok:bool, available:float, budget:float, has_budget:bool}
     */
    public static function guard(int $deptId, int $sourceId, int $year, float $planTotal, ?int $excludeTripId = null): array
    {
        $budget = self::budgetAmount($deptId, $sourceId, $year);
        $avail  = self::available($deptId, $sourceId, $year, $excludeTripId);
        if ($budget <= 0) {
            return ['ok' => true, 'available' => $avail, 'budget' => $budget, 'has_budget' => false];
        }
        return ['ok' => $planTotal <= $avail + 0.001, 'available' => $avail, 'budget' => $budget, 'has_budget' => true];
    }
}
