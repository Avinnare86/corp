<?php

namespace App\Services;

use App\Core\Database;

/**
 * График отпусков — документ-сущность (как табель/график сменности):
 *   - охват: отдел (department_id) либо организация в целом (NULL);
 *   - ревизии: основной (revision=0), далее корректировочные;
 *   - после подписи неизменяем; статусы документа draft | signed.
 *
 * Строка-период (vacation_schedule_rows) — одна часть отпуска сотрудника.
 * Статусы строки: proposal (Предложение) → approved (Согласован). Когда график
 * подписан (signed) — согласованные строки показываются сотруднику как «В графике».
 *
 * Правила (подтверждены заказчиком):
 *   - нельзя согласовать/подписать, пока у сотрудника запланировано меньше остатка
 *     (остаток вводит кадровик, {@see vacation_balances});
 *   - хотя бы одна часть отпуска должна быть ≥ 10 рабочих дней (праздники исключаются
 *     по производственному календарю РФ).
 */
class VacationScheduleService
{
    public const ST_DRAFT  = 'draft';
    public const ST_SIGNED = 'signed';

    public const ROW_PROPOSAL = 'proposal';   // Предложение
    public const ROW_APPROVED = 'approved';    // Согласован

    public const MIN_LONG_PART_WD = 10;        // минимум рабочих дней хотя бы в одной части
    public const DEFAULT_BALANCE  = 28;        // дней по умолчанию, если кадровик не ввёл остаток

    public const ROW_STATUS_LABEL = [
        self::ROW_PROPOSAL => 'Предложение',
        self::ROW_APPROVED => 'Согласован',
    ];

    /** Название охвата для отображения. */
    public static function scopeLabel(?int $deptId): string
    {
        if ($deptId === null) { return 'Организация в целом'; }
        $n = Database::scalar('SELECT name FROM departments WHERE id = ?', [$deptId]);
        return $n !== false ? (string) $n : ('Отдел #' . $deptId);
    }

    /** Активные сотрудники охвата (отдел или вся организация). */
    public static function scopeEmployees(?int $deptId): array
    {
        if ($deptId === null) {
            return Database::all('SELECT id, full_name, position, department_id FROM users WHERE is_active = 1 ORDER BY full_name');
        }
        return Database::all('SELECT id, full_name, position, department_id FROM users WHERE is_active = 1 AND department_id = ? ORDER BY full_name', [$deptId]);
    }

    /** Остатки отпуска по году для набора сотрудников (год + перенос с прошлого года): id => дни. */
    public static function balances(int $year, array $empIds): array
    {
        $map = [];
        foreach ($empIds as $id) { $map[(int) $id] = self::DEFAULT_BALANCE; }
        if (!$empIds) { return $map; }
        $in = implode(',', array_fill(0, count($empIds), '?'));
        $rows = Database::all("SELECT employee_id, days FROM vacation_balances WHERE year = ? AND employee_id IN ($in)",
            array_merge([$year], array_map('intval', $empIds)));
        foreach ($rows as $r) { $map[(int) $r['employee_id']] = (int) $r['days']; }
        $carry = Database::all("SELECT employee_id, carried_out FROM vacation_balances WHERE year = ? AND employee_id IN ($in) AND carried_out > 0",
            array_merge([$year - 1], array_map('intval', $empIds)));
        foreach ($carry as $r) { $map[(int) $r['employee_id']] += (int) $r['carried_out']; }
        return $map;
    }

    /**
     * Остаток конкретного сотрудника на год: свежий лимит года Y + дни, явно перенесённые
     * с года Y-1 (vacation_balances.carried_out — переносимые дни НЕ входят в лимит нового
     * года, а прибавляются к нему как отдельный, уже «оплаченный» пул).
     */
    public static function balanceOf(int $employeeId, int $year): int
    {
        return self::balanceBreakdown($employeeId, $year)['total'];
    }

    /** Разбивка остатка: свежий лимит года / перенесённые с прошлого года / итого. */
    public static function balanceBreakdown(int $employeeId, int $year): array
    {
        $d = Database::scalar('SELECT days FROM vacation_balances WHERE employee_id = ? AND year = ?', [$employeeId, $year]);
        $fresh = $d === false ? self::DEFAULT_BALANCE : (int) $d;
        $carriedIn = (int) (Database::scalar('SELECT carried_out FROM vacation_balances WHERE employee_id = ? AND year = ?', [$employeeId, $year - 1]) ?: 0);
        return ['fresh' => $fresh, 'carried_in' => $carriedIn, 'total' => $fresh + $carriedIn];
    }

    public static function setBalance(int $employeeId, int $year, int $days, string $note, int $uid): void
    {
        $exists = Database::scalar('SELECT id FROM vacation_balances WHERE employee_id = ? AND year = ?', [$employeeId, $year]);
        if ($exists) {
            Database::run('UPDATE vacation_balances SET days = ?, note = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$days, $note, $uid, date('Y-m-d H:i:s'), (int) $exists]);
        } else {
            Database::insert('INSERT INTO vacation_balances (employee_id, year, days, note, updated_by, updated_at) VALUES (?,?,?,?,?,?)',
                [$employeeId, $year, $days, $note, $uid, date('Y-m-d H:i:s')]);
        }
    }

    /** Сколько дней года Y уже явно перенесено на год Y+1 (declared carry-out). */
    public static function carriedOut(int $employeeId, int $year): int
    {
        return (int) (Database::scalar('SELECT carried_out FROM vacation_balances WHERE employee_id = ? AND year = ?', [$employeeId, $year]) ?: 0);
    }

    /** Увеличить перенос года Y на $delta дней (может быть отрицательным — при откате заявки). Создаёт строку остатка, если её ещё нет. */
    public static function addCarriedOut(int $employeeId, int $year, int $delta): void
    {
        $row = Database::one('SELECT id, carried_out FROM vacation_balances WHERE employee_id = ? AND year = ?', [$employeeId, $year]);
        if ($row) {
            $new = max(0, (int) $row['carried_out'] + $delta);
            Database::run('UPDATE vacation_balances SET carried_out = ? WHERE id = ?', [$new, (int) $row['id']]);
        } else {
            Database::insert('INSERT INTO vacation_balances (employee_id, year, days, carried_out) VALUES (?,?,?,?)',
                [$employeeId, $year, self::DEFAULT_BALANCE, max(0, $delta)]);
        }
    }

    /**
     * Отразить одобренную правку (vacation_change_requests) в актуальной (неархивной) ревизии
     * графика отдела, если она уже сформирована — без пересчёта подписи/hash документа (правки
     * применяются к его текущим строкам; исторический snapshot на момент подписи не трогаем).
     * $mode: 'add' — вставить строку; 'remove' — удалить строку по сотруднику/датам.
     * Возвращает id новой строки (при 'add') или null.
     */
    public static function syncRowForPick(int $year, ?int $deptId, int $empId, string $start, string $end, int $days, string $mode): ?int
    {
        if (!$deptId) { return null; }
        $sched = self::current($year, $deptId);
        if (!$sched) { return null; }
        if ($mode === 'add') {
            return (int) Database::insert('INSERT INTO vacation_schedule_rows (schedule_id, employee_id, start_date, end_date, days, status) VALUES (?,?,?,?,?,?)',
                [(int) $sched['id'], $empId, $start, $end, $days, self::ROW_APPROVED]);
        }
        Database::run('DELETE FROM vacation_schedule_rows WHERE schedule_id=? AND employee_id=? AND start_date=? AND end_date=?',
            [(int) $sched['id'], $empId, $start, $end]);
        return null;
    }

    /**
     * Сформировать СВОДНЫЙ график по организации (форма Т-7) из самозаписей кампании
     * (vacation_picks) за год — все активные сотрудники, сгруппированные по подразделениям.
     * Строки создаются сразу «согласованными» (отделы/замы уже согласовали служебки).
     * Возвращает id нового графика (черновик, ждёт подписи директора).
     */
    public static function formFromCampaign(int $year, int $by): int
    {
        $rev = self::nextRevision($year, null);
        $sid = (int) Database::insert(
            'INSERT INTO vacation_schedules (year, department_id, revision, status, created_by, created_at) VALUES (?,NULL,?,?,?,?)',
            [$year, $rev, self::ST_DRAFT, $by, date('Y-m-d H:i:s')]);
        foreach (Database::all(
            'SELECT vp.employee_id, vp.start_date, vp.end_date, vp.days FROM vacation_picks vp
               JOIN users u ON u.id = vp.employee_id
              WHERE vp.year = ? AND u.is_active = 1 ORDER BY u.department_id, u.full_name, vp.start_date', [$year]) as $p) {
            Database::insert('INSERT INTO vacation_schedule_rows (schedule_id, employee_id, start_date, end_date, days, status) VALUES (?,?,?,?,?,?)',
                [$sid, (int) $p['employee_id'], $p['start_date'], $p['end_date'],
                 (int) $p['days'] ?: self::calDays($p['start_date'], $p['end_date']), self::ROW_APPROVED]);
        }
        return $sid;
    }

    /** Табельный номер сотрудника (если ведётся) — для формы Т-7. */
    public static function tabNumber(int $employeeId): string
    {
        foreach (['tab_number', 'personnel_number', 'staff_number'] as $col) {
            try { $v = Database::scalar("SELECT $col FROM users WHERE id=?", [$employeeId]); if ($v !== false && $v !== null && $v !== '') { return (string) $v; } } catch (\Throwable $e) {}
        }
        return (string) $employeeId;
    }

    /** Строки графика (с ФИО), упорядоченные по сотруднику и дате. */
    public static function rows(int $scheduleId): array
    {
        return Database::all(
            'SELECT r.*, u.full_name, u.department_id FROM vacation_schedule_rows r
               JOIN users u ON u.id = r.employee_id
              WHERE r.schedule_id = ? ORDER BY u.full_name, r.start_date', [$scheduleId]);
    }

    /** Сумма запланированных календарных дней по сотрудникам: empId => дни. */
    public static function plannedByEmp(int $scheduleId): array
    {
        $out = [];
        foreach (Database::all('SELECT employee_id, SUM(days) AS d FROM vacation_schedule_rows WHERE schedule_id = ? GROUP BY employee_id', [$scheduleId]) as $r) {
            $out[(int) $r['employee_id']] = (int) $r['d'];
        }
        return $out;
    }

    /** Календарных дней в периоде включительно. */
    public static function calDays(string $start, string $end): int
    {
        $a = strtotime($start); $b = strtotime($end);
        if ($a === false || $b === false || $b < $a) { return 0; }
        return (int) floor(($b - $a) / 86400) + 1;
    }

    /**
     * Рабочих дней в периоде включительно (по производственному календарю РФ;
     * при отсутствии данных календаря — Пн–Пт как рабочие).
     */
    public static function workingDaysBetween(string $start, string $end): int
    {
        $a = strtotime($start); $b = strtotime($end);
        if ($a === false || $b === false || $b < $a) { return 0; }
        $n = 0;
        for ($t = $a; $t <= $b; $t += 86400) {
            $d = date('Y-m-d', $t);
            $w = ProductionCalendar::isWorkingDay($d);
            if ($w === null) { $w = (int) date('N', $t) <= 5; }   // нет календаря → Пн–Пт
            if ($w) { $n++; }
        }
        return $n;
    }

    /** Максимум рабочих дней среди частей отпуска сотрудника в графике. */
    public static function longestPartWorkingDays(array $empRows): int
    {
        $max = 0;
        foreach ($empRows as $r) {
            $wd = self::workingDaysBetween($r['start_date'], $r['end_date']);
            if ($wd > $max) { $max = $wd; }
        }
        return $max;
    }

    /**
     * Проверка графика к согласованию/подписи. Возвращает по каждому сотруднику охвата
     * список проблем (пусто = в порядке) и общий флаг ok.
     *
     * @return array{ok:bool, byEmp:array<int,array{name:string,planned:int,balance:int,longestWd:int,issues:string[]}>}
     */
    public static function validate(array $schedule): array
    {
        $year   = (int) $schedule['year'];
        $emps   = self::scopeEmployees($schedule['department_id'] !== null ? (int) $schedule['department_id'] : null);
        $empIds = array_map(fn($e) => (int) $e['id'], $emps);
        $bal    = self::balances($year, $empIds);
        $planned = self::plannedByEmp((int) $schedule['id']);
        $rows   = self::rows((int) $schedule['id']);
        $byEmpRows = [];
        foreach ($rows as $r) { $byEmpRows[(int) $r['employee_id']][] = $r; }

        $byEmp = []; $ok = true;
        foreach ($emps as $e) {
            $id = (int) $e['id'];
            $balance = (int) ($bal[$id] ?? self::DEFAULT_BALANCE);
            $p = (int) ($planned[$id] ?? 0);
            $longest = self::longestPartWorkingDays($byEmpRows[$id] ?? []);
            $issues = [];
            if ($balance > 0 && $p < $balance) {
                $issues[] = "запланировано {$p} из {$balance} дн. — отпуск не распределён полностью";
            }
            if ($balance > 0 && $longest < self::MIN_LONG_PART_WD) {
                $issues[] = 'нет части ≥ ' . self::MIN_LONG_PART_WD . ' рабочих дней (наибольшая ' . $longest . ')';
            }
            if ($issues) { $ok = false; }
            $byEmp[$id] = ['name' => $e['full_name'], 'planned' => $p, 'balance' => $balance, 'longestWd' => $longest, 'issues' => $issues];
        }
        return ['ok' => $ok, 'byEmp' => $byEmp];
    }

    /** Актуальная (последняя по revision, не архивная) ревизия графика для (год, охват). */
    public static function current(int $year, ?int $deptId): ?array
    {
        $sql = 'SELECT * FROM vacation_schedules WHERE year = ? AND archived_at IS NULL AND '
             . ($deptId === null ? 'department_id IS NULL' : 'department_id = ?')
             . ' ORDER BY revision DESC, id DESC LIMIT 1';
        $params = $deptId === null ? [$year] : [$year, $deptId];
        return Database::one($sql, $params) ?: null;
    }

    /** Номер следующей ревизии (на основе подписанных/существующих) для (год, охват). */
    public static function nextRevision(int $year, ?int $deptId): int
    {
        $sql = 'SELECT MAX(revision) FROM vacation_schedules WHERE year = ? AND '
             . ($deptId === null ? 'department_id IS NULL' : 'department_id = ?');
        $params = $deptId === null ? [$year] : [$year, $deptId];
        $m = Database::scalar($sql, $params);
        return $m === false || $m === null ? 0 : ((int) $m + 1);
    }

    /** Есть ли незавершённый черновик для (год, охват)? */
    public static function openDraft(int $year, ?int $deptId): ?array
    {
        $sql = 'SELECT * FROM vacation_schedules WHERE year = ? AND status = ? AND archived_at IS NULL AND '
             . ($deptId === null ? 'department_id IS NULL' : 'department_id = ?')
             . ' ORDER BY id DESC LIMIT 1';
        $params = $deptId === null ? [$year, self::ST_DRAFT] : [$year, self::ST_DRAFT, $deptId];
        return Database::one($sql, $params) ?: null;
    }
}
