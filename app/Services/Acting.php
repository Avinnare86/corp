<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Auth;

/**
 * Исполняющие обязанности (И.о./ВРИО) на период — единый механизм замещения для подписи
 * стимула, документов СЭД и поручений. Источник — таблица acting_assignments.
 * И.о. получает полные права замещаемого (через Auth::effectiveRoles при переключении),
 * но подписывает своей ЭП (физический подписант фиксируется отдельно).
 */
class Acting
{
    /** Кого $uid замещает СЕГОДНЯ: список id замещаемых (активные, дата в диапазоне). */
    public static function actingFor(int $uid): array
    {
        $today = date('Y-m-d');
        return array_map(fn($r) => (int) $r['absent_id'], Database::all(
            "SELECT DISTINCT absent_id FROM acting_assignments
              WHERE acting_id = ? AND status = 'active' AND date_from <= ? AND date_to >= ?",
            [$uid, $today, $today]));
    }

    /** Кто СЕЙЧАС замещает $absentId (строки с именами замещающих). */
    public static function coveredBy(int $absentId): array
    {
        $today = date('Y-m-d');
        return Database::all(
            "SELECT a.*, u.full_name AS acting_name FROM acting_assignments a
               JOIN users u ON u.id = a.acting_id
              WHERE a.absent_id = ? AND a.status = 'active' AND a.date_from <= ? AND a.date_to >= ?
              ORDER BY a.date_to", [$absentId, $today, $today]);
    }

    /** Активное назначение, по которому $actingId замещает $absentId сегодня (или null). */
    public static function activeFor(int $actingId, int $absentId): ?array
    {
        $today = date('Y-m-d');
        return Database::one(
            "SELECT * FROM acting_assignments
              WHERE acting_id = ? AND absent_id = ? AND status = 'active' AND date_from <= ? AND date_to >= ?
              ORDER BY date_to LIMIT 1", [$actingId, $absentId, $today, $today]) ?: null;
    }

    /** Есть ли активное замещение $absentId, перекрывающее период [$from,$to] (для контроля отпуска). */
    public static function coversRange(int $absentId, string $from, string $to): bool
    {
        return (bool) Database::scalar(
            "SELECT 1 FROM acting_assignments
              WHERE absent_id = ? AND status = 'active' AND date_from <= ? AND date_to >= ? LIMIT 1",
            [$absentId, $to, $from]);
    }

    /** Может ли $actor назначить И.о. для $absent: сам замещаемый, его начальник, вышестоящий или админ. */
    public static function canAssign(int $actor, int $absent): bool
    {
        if ($actor === $absent || Auth::isAdmin()) { return true; }
        if (Org::canOverseeUser($actor, $absent)) { return true; }
        return in_array($actor, Org::superiorUserIds($absent), true);
    }

    /** OR Org::canOverseeUser по множеству [actor + кого он замещает] — «вышестоящий как И.о.». */
    public static function oversees(int $actor, int $target): bool
    {
        foreach (array_merge([$actor], self::actingFor($actor)) as $id) {
            if (Org::canOverseeUser($id, $target)) { return true; }
        }
        return false;
    }

    /** Есть ли у пользователя полномочия подписи/утверждения (нужен ли И.о. на отпуск). */
    public static function hasSigningAuthority(int $uid): bool
    {
        if (Org::headedDeptIds($uid)) { return true; }
        if (Database::scalar('SELECT 1 FROM departments WHERE curator_id = ? LIMIT 1', [$uid])) { return true; }
        if (Org::directorUserId() === $uid) { return true; }
        $roles = Auth::roles($uid);
        foreach (['dept_head', 'deputy_director', 'director', 'accountant', 'docs_manager', 'doc_controller'] as $r) {
            if (!empty($roles[$r])) { return true; }
        }
        return false;
    }

    /**
     * Маркер для штампа: был ли $actingId исполняющим обязанности за $absentId на дату $date.
     * Возвращает «И.о. <должность>» / «ВРИО <должность>» или null (если подписал в своём качестве).
     */
    public static function markerOn(int $actingId, int $absentId, ?string $date): ?string
    {
        if (!$actingId || !$absentId || $actingId === $absentId || !$date) { return null; }
        $d = substr($date, 0, 10);
        $a = Database::one(
            "SELECT kind FROM acting_assignments WHERE acting_id = ? AND absent_id = ? AND date_from <= ? AND date_to >= ?
              ORDER BY id DESC LIMIT 1", [$actingId, $absentId, $d, $d]);
        if (!$a) { return null; }
        $pos = (string) Database::scalar('SELECT position FROM users WHERE id = ?', [$absentId]);
        return trim(($a['kind'] === 'vrio' ? 'ВРИО' : 'И.о.') . ' ' . ($pos ?: ''));
    }

    /** Активные назначения, где $uid — исполняющий (для переключателя «работаю как И.о.» в шапке). */
    public static function myActingOptions(int $uid): array
    {
        $today = date('Y-m-d');
        return Database::all(
            "SELECT a.absent_id, a.kind, u.full_name AS absent_name, u.position AS absent_pos
               FROM acting_assignments a JOIN users u ON u.id = a.absent_id
              WHERE a.acting_id = ? AND a.status = 'active' AND a.date_from <= ? AND a.date_to >= ?
              ORDER BY u.full_name", [$uid, $today, $today]);
    }

    /** Список активных назначений для UI (с именами замещаемого/замещающего/автора). */
    public static function activeList(): array
    {
        return Database::all(
            "SELECT a.*, ab.full_name AS absent_name, ab.position AS absent_pos,
                    ac.full_name AS acting_name, cb.full_name AS creator_name
               FROM acting_assignments a
               JOIN users ab ON ab.id = a.absent_id
               JOIN users ac ON ac.id = a.acting_id
               LEFT JOIN users cb ON cb.id = a.created_by
              WHERE a.status = 'active'
              ORDER BY a.date_from DESC");
    }
}
