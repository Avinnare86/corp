<?php

namespace App\Services;

use App\Core\Database;

/**
 * Уведомления об отпуске (извещение по ст.123 ТК РФ) по утверждённому директором графику Т-7.
 *
 * Поток: после подписи сводного графика директором формируются черновики уведомлений на каждый
 * период отпуска каждого сотрудника ({@see generateForSchedule}). Затем начальник отдела кадров
 * (настройка vacation_hr_head) подписывает весь пакет своей ЭП ({@see signBatch}) — и ТОЛЬКО после
 * подписи каждое уведомление автоматически направляется сотруднику в личный кабинет и на почту
 * ({@see NotificationService::create} → {@see Mailer}). Шапка печатной формы — только ФИО.
 */
class VacationNoticeService
{
    /** id начальника отдела кадров (подписант уведомлений) из настройки vacation_hr_head. */
    public static function hrHeadId(): int
    {
        return (int) (Settings::get('vacation_hr_head', 0));
    }

    /** Начальник отдела кадров (строка users) или null, если настройка не задана/не активен. */
    public static function hrHead(): ?array
    {
        $uid = self::hrHeadId();
        if (!$uid) { return null; }
        return Database::one('SELECT id, full_name, position FROM users WHERE id=? AND is_active=1', [$uid]) ?: null;
    }

    /**
     * Сформировать черновики уведомлений по подписанному графику (по каждому согласованному периоду).
     * Не дублирует уже созданные (по schedule/emp/датам).
     *
     * Для КОРРЕКТИРОВОЧНОЙ ревизии (revision > 0) уведомляются ТОЛЬКО изменившиеся сотрудники:
     * период, в точности совпадающий с предыдущей подписанной ревизией того же охвата, повторно
     * не уведомляется (сотрудник уже был извещён о нём ранее). Возвращает число созданных.
     */
    public static function generateForSchedule(int $scheduleId): int
    {
        $s = Database::one('SELECT * FROM vacation_schedules WHERE id=?', [$scheduleId]);
        if (!$s || $s['status'] !== VacationScheduleService::ST_SIGNED) { return 0; }
        $year = (int) $s['year'];
        $rev  = (int) $s['revision'];
        $priorKeys = self::priorPeriodKeys($s);   // emp|start|end периоды предыдущей подписанной ревизии
        $n = 0;
        foreach (Database::all(
            "SELECT employee_id, start_date, end_date, days FROM vacation_schedule_rows WHERE schedule_id=? AND status=?",
            [$scheduleId, VacationScheduleService::ROW_APPROVED]) as $r) {
            $key = (int) $r['employee_id'] . '|' . $r['start_date'] . '|' . $r['end_date'];
            if ($rev > 0 && isset($priorKeys[$key])) { continue; }   // период не изменился — не уведомляем повторно
            $exists = Database::scalar('SELECT 1 FROM vacation_notices WHERE schedule_id=? AND employee_id=? AND start_date=? AND end_date=?',
                [$scheduleId, (int) $r['employee_id'], $r['start_date'], $r['end_date']]);
            if ($exists) { continue; }
            Database::insert(
                'INSERT INTO vacation_notices (year, employee_id, schedule_id, start_date, end_date, days, status, created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$year, (int) $r['employee_id'], $scheduleId, $r['start_date'], $r['end_date'], (int) $r['days'], 'draft', date('Y-m-d H:i:s')]);
            $n++;
        }
        if ($n) { Audit::log('vacation_notice.generate', 'Сформированы уведомления об отпуске: ' . $n . ($rev > 0 ? ' (корректировка №' . $rev . ', график #' . $scheduleId . ')' : ' (график #' . $scheduleId . ')')); }
        return $n;
    }

    /** Периоды предыдущей подписанной ревизии того же охвата: 'emp|start|end' => true (пусто для ревизии 0). */
    private static function priorPeriodKeys(array $s): array
    {
        if ((int) $s['revision'] <= 0) { return []; }
        $isOrg = $s['department_id'] === null;
        $prev = Database::one(
            'SELECT id FROM vacation_schedules WHERE year=? AND '
            . ($isOrg ? 'department_id IS NULL' : 'department_id=?')
            . ' AND status=? AND revision < ? ORDER BY revision DESC, id DESC LIMIT 1',
            $isOrg ? [(int) $s['year'], VacationScheduleService::ST_SIGNED, (int) $s['revision']]
                   : [(int) $s['year'], (int) $s['department_id'], VacationScheduleService::ST_SIGNED, (int) $s['revision']]);
        if (!$prev) { return []; }
        $keys = [];
        foreach (Database::all('SELECT employee_id, start_date, end_date FROM vacation_schedule_rows WHERE schedule_id=? AND status=?',
            [(int) $prev['id'], VacationScheduleService::ROW_APPROVED]) as $pr) {
            $keys[(int) $pr['employee_id'] . '|' . $pr['start_date'] . '|' . $pr['end_date']] = true;
        }
        return $keys;
    }

    /**
     * Подписать все черновые уведомления года ЭП начальника отдела кадров и РАЗОСЛАТЬ (кабинет + почта).
     * Прерывается при ошибке подписи (неверный пароль/нет сертификата) — уже подписанные остаются.
     * @return array{ok:bool, error?:string, signed:int, sent:int}
     */
    public static function signBatch(int $year, int $hrHeadId, string $type, string $password): array
    {
        $drafts = Database::all("SELECT * FROM vacation_notices WHERE year=? AND status='draft' ORDER BY id", [$year]);
        if (!$drafts) { return ['ok' => false, 'error' => 'Нет уведомлений к подписи — сформируйте и утвердите график Т-7 директором.', 'signed' => 0, 'sent' => 0]; }
        $signed = 0; $sent = 0;
        foreach ($drafts as $nrow) {
            $nid = (int) $nrow['id'];
            $payload = json_encode(['type' => 'vacation_notice', 'emp' => (int) $nrow['employee_id'],
                'from' => $nrow['start_date'], 'to' => $nrow['end_date'], 'days' => (int) $nrow['days']], JSON_UNESCAPED_UNICODE);
            $res = SignService::signDocument('vacation_notice', $nid, $hrHeadId, $type, $password, $payload);
            if (empty($res['ok'])) { return ['ok' => false, 'error' => $res['error'] ?? 'Не удалось подписать.', 'signed' => $signed, 'sent' => $sent]; }
            Database::run('UPDATE vacation_notices SET status=?, signed_by=?, signed_at=?, sign_type=?, sign_hash=?, cert_serial=? WHERE id=?',
                ['signed', $hrHeadId, $res['signed_at'], $res['sign_type'], $res['sign_hash'], $res['serial'], $nid]);
            $signed++;
            // Доставка — только после подписи: кабинет + почта (best-effort через NotificationService).
            $body = 'Уведомляем Вас, что согласно графику отпусков на ' . $year . ' год Вам предоставляется ежегодный '
                  . 'оплачиваемый отпуск продолжительностью ' . (int) $nrow['days'] . ' календарных дней с '
                  . date('d.m.Y', strtotime($nrow['start_date'])) . ' по ' . date('d.m.Y', strtotime($nrow['end_date']))
                  . '. Уведомление подписано электронной подписью начальника отдела кадров (ст. 123 ТК РФ).';
            NotificationService::create((int) $nrow['employee_id'], 'Уведомление об отпуске (' . $year . ')', $body);
            Database::run('UPDATE vacation_notices SET status=?, notified_at=? WHERE id=?', ['sent', date('Y-m-d H:i:s'), $nid]);
            $sent++;
        }
        Audit::log('vacation_notice.sign_batch', 'Подписаны и разосланы уведомления об отпуске: ' . $sent . ' (год ' . $year . ')');
        return ['ok' => true, 'signed' => $signed, 'sent' => $sent];
    }
}
