<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Auth;

/**
 * Иерархия подчинённости: кто кому начальник по дереву подразделений.
 *
 * Дерево — departments(parent_id), руководство — departments.head_id (начальник отдела)
 * и departments.curator_id (курирующий зам). Тиры ролей: director > deputy_director > dept_head.
 * FK в схеме нет, дерево может иметь циклы — все обходы ограничены по глубине.
 */
class Org
{
    /** Тир актёра для прав «вышестоящего»: director | deputy | head | null. admin приравнен к директору. */
    public static function tier(int $uid): ?string
    {
        $r = Auth::roles($uid);
        if (!empty($r['admin']) || !empty($r['director'])) { return 'director'; }
        if (!empty($r['deputy_director'])) { return 'deputy'; }
        if (!empty($r['dept_head'])) { return 'head'; }
        return null;
    }

    /** Является ли $uid руководителем подразделения $deptId (глава/куратор отдела или выше по цепочке parent_id). */
    public static function isSuperiorOfDept(int $uid, ?int $deptId): bool
    {
        $guard = 0;
        while ($deptId && $guard++ < 20) {
            $d = Database::one('SELECT head_id, curator_id, parent_id FROM departments WHERE id=?', [$deptId]);
            if (!$d) { break; }
            if ((int) $d['head_id'] === $uid || (int) $d['curator_id'] === $uid) { return true; }
            $deptId = $d['parent_id'] ? (int) $d['parent_id'] : null;
        }
        return false;
    }

    /** Может ли $uid распоряжаться стимулом сотрудника $targetUserId (он его подчинённый). */
    public static function canOverseeUser(int $uid, int $targetUserId): bool
    {
        if ($uid === $targetUserId) { return false; }
        if (self::tier($uid) === 'director') { return true; } // директор/админ — все
        $dept = Database::scalar('SELECT department_id FROM users WHERE id=?', [$targetUserId]);
        return $dept ? self::isSuperiorOfDept($uid, (int) $dept) : false;
    }

    /** Начальники сотрудника вверх по иерархии: head_id/curator_id его отдела и всех вышестоящих (для эскалации). */
    public static function superiorUserIds(int $uid): array
    {
        $deptId = (int) Database::scalar('SELECT department_id FROM users WHERE id=?', [$uid]);
        $out = []; $guard = 0;
        while ($deptId && $guard++ < 20) {
            $d = Database::one('SELECT head_id, curator_id, parent_id FROM departments WHERE id=?', [$deptId]);
            if (!$d) { break; }
            foreach (['head_id', 'curator_id'] as $k) {
                $boss = (int) ($d[$k] ?? 0);
                if ($boss && $boss !== $uid) { $out[$boss] = true; }
            }
            $deptId = $d['parent_id'] ? (int) $d['parent_id'] : null;
        }
        return array_keys($out);
    }

    /** Отделы, где $uid — начальник (head_id). */
    public static function headedDeptIds(int $uid): array
    {
        return array_map('intval', array_column(
            Database::all('SELECT id FROM departments WHERE head_id=?', [$uid]), 'id'));
    }

    /** Отделы, которые курирует $uid (curator_id), включая все подотделы по parent_id. */
    public static function curatedDeptIds(int $uid): array
    {
        $out = [];
        foreach (Database::all('SELECT id FROM departments WHERE curator_id=?', [$uid]) as $r) {
            foreach (self::withDescendants((int) $r['id']) as $d) { $out[$d] = true; }
        }
        return array_keys($out);
    }

    /**
     * Вся ветка подчинённости вниз: отделы, где $uid начальник (с подотделами),
     * плюс кураторские отделы (curatedDeptIds уже с подотделами). Директор/админ → все.
     * Используется для создания служебок о стимуле по всей ветке (а не только своему отделу).
     */
    public static function branchDeptIds(int $uid): array
    {
        if (self::tier($uid) === 'director') {
            return array_map('intval', array_column(Database::all('SELECT id FROM departments'), 'id'));
        }
        $out = [];
        // headedDeptIds НЕ раскрывает подотделы — раскрываем здесь.
        foreach (self::headedDeptIds($uid) as $hid) {
            foreach (self::withDescendants($hid) as $d) { $out[$d] = true; }
        }
        // curatedDeptIds уже включает подотделы.
        foreach (self::curatedDeptIds($uid) as $d) { $out[$d] = true; }
        return array_keys($out);
    }

    /** Директор по структуре: глава корневого подразделения (дирекция). null — если не задан. */
    public static function directorUserId(): ?int
    {
        $id = Database::scalar("SELECT head_id FROM departments WHERE parent_id IS NULL AND kind = 'дирекция' AND head_id IS NOT NULL ORDER BY id LIMIT 1");
        if (!$id) { $id = Database::scalar('SELECT head_id FROM departments WHERE parent_id IS NULL AND head_id IS NOT NULL ORDER BY id LIMIT 1'); }
        return $id ? (int) $id : null;
    }

    /** Подразделение + все его подотделы (обход вниз по parent_id, с защитой от циклов). */
    public static function withDescendants(int $rootId): array
    {
        $out = []; $stack = [$rootId]; $guard = 0;
        while ($stack && $guard++ < 1000) {
            $id = (int) array_pop($stack);
            if (isset($out[$id])) { continue; }
            $out[$id] = true;
            foreach (Database::all('SELECT id FROM departments WHERE parent_id=?', [$id]) as $c) {
                $stack[] = (int) $c['id'];
            }
        }
        return array_keys($out);
    }

    /** Id всех подчинённых $uid: директор → все активные; зам → его отделы+подотделы; начальник → свои отделы. */
    public static function subordinateUserIds(int $uid): array
    {
        $tier = self::tier($uid);
        if ($tier === 'director') {
            return array_map('intval', array_column(
                Database::all('SELECT id FROM users WHERE is_active=1'), 'id'));
        }
        $deptIds = $tier === 'deputy' ? self::curatedDeptIds($uid)
                 : ($tier === 'head' ? self::headedDeptIds($uid) : []);
        if (!$deptIds) { return []; }
        $ph = implode(',', array_fill(0, count($deptIds), '?'));
        return array_map('intval', array_column(
            Database::all("SELECT id FROM users WHERE is_active=1 AND department_id IN ($ph)", $deptIds), 'id'));
    }
}
