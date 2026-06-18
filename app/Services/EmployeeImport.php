<?php
namespace App\Services;

use App\Core\Database;

/**
 * Загрузка сотрудников из xlsx-выгрузки штатного расписания (формат «Список сотрудников по штатке»).
 * Строки-секции (только колонка «Подразделение») задают отдел; строки сотрудников: № / Сотрудник / Должность / Оклад (тариф).
 * Объём ставки берётся из суффикса «(… 0,25 ставки)» в ФИО, иначе 1.0. Дубли ловятся по ФИО.
 */
class EmployeeImport
{
    /** Разобрать xlsx → список ['fio','position','dept','rate','oklad']. */
    public static function parse(string $path): array
    {
        $rows = Xlsx::readRows($path);
        $people = [];
        $dept = '';
        $started = false;
        foreach ($rows as $c) {
            $a = trim((string) ($c['A'] ?? ''));
            $b = trim((string) ($c['B'] ?? ''));
            $f = trim((string) ($c['F'] ?? ''));
            $p = (string) ($c['P'] ?? '');
            if (!$started) {
                // начинаем после строки-заголовка (B = «Сотрудник»)
                if (mb_strtolower($b, 'UTF-8') === 'сотрудник') { $started = true; }
                continue;
            }
            // строка-секция = отдел (есть текст в A, нет сотрудника и не число)
            if ($b === '' && $a !== '' && !is_numeric($a)) { $dept = $a; continue; }
            if ($b === '') { continue; }
            $fio = trim(preg_replace('/\s*\(.*\)\s*$/u', '', $b));            // убрать «(осн.)», «(совм., 0,25 ставки)»
            if ($fio === '') { continue; }
            $rate = 1.0;
            if (preg_match('/(\d+[.,]\d+)\s*ставк/ui', $b, $m)) { $rate = (float) str_replace(',', '.', $m[1]); }
            elseif (preg_match('/\bполставк/ui', $b)) { $rate = 0.5; }
            $position = trim(preg_replace('/\s*\d+[.,]\d+\s*ставк\S*$/ui', '', $f));  // «Советник 0,25 ставки» → «Советник»
            $oklad = (float) preg_replace('/[^\d.]/', '', str_replace([',', ' '], ['.', ''], $p));
            $people[] = ['fio' => $fio, 'position' => $position, 'dept' => $dept, 'rate' => $rate, 'oklad' => $oklad];
        }
        return $people;
    }

    /**
     * Импортировать список (с дедупликацией по ФИО). Создаёт недостающие отделы/должности.
     * @return array{created:array,existing:array,creds:array,depts:int,positions:int}
     */
    public static function import(array $people, ?int $byUserId = null): array
    {
        $created = []; $existing = []; $creds = []; $newDepts = 0; $newPos = 0;
        foreach ($people as $r) {
            $fio = trim((string) $r['fio']);
            if ($fio === '') { continue; }
            // дубль по ФИО
            if (Database::scalar('SELECT 1 FROM users WHERE full_name = ?', [$fio])) { $existing[] = $fio; continue; }

            // отдел
            $deptId = null;
            $deptName = trim((string) ($r['dept'] ?? ''));
            if ($deptName !== '') {
                $deptId = Database::scalar('SELECT id FROM departments WHERE name = ?', [$deptName]);
                if ($deptId === false) { $deptId = Database::insert('INSERT INTO departments (name) VALUES (?)', [$deptName]); $newDepts++; }
                $deptId = (int) $deptId;
            }
            // должность (oklad из штатки при создании)
            $posId = null; $posTitle = trim((string) ($r['position'] ?? ''));
            if ($posTitle !== '') {
                $posId = Database::scalar('SELECT id FROM positions WHERE title = ?', [$posTitle]);
                if ($posId === false) { $posId = Database::insert('INSERT INTO positions (title, oklad, is_active) VALUES (?,?,1)', [$posTitle, round((float) $r['oklad'], 2)]); $newPos++; }
                $posId = (int) $posId;
            }
            $oklad = $posId ? (float) Database::scalar('SELECT oklad FROM positions WHERE id = ?', [$posId]) : round((float) $r['oklad'], 2);

            $login = self::uniqueLogin($fio);
            $pass = self::tempPassword();
            Database::insert(
                'INSERT INTO users (full_name, login, password_hash, role, position, position_id, oklad, rate_volume, schedule_type, does_anketas, does_operations, is_active, department_id, must_change_password)
                 VALUES (?,?,?,?,?,?,?,?,?,1,0,1,?,1)',
                [$fio, $login, password_hash($pass, PASSWORD_DEFAULT), 'employee', $posTitle, $posId, $oklad, round((float) $r['rate'], 2), '5_2', $deptId]
            );
            $created[] = $fio;
            $creds[] = ['fio' => $fio, 'login' => $login, 'password' => $pass];
        }
        return ['created' => $created, 'existing' => $existing, 'creds' => $creds, 'depts' => $newDepts, 'positions' => $newPos];
    }

    /** Уникальный логин из ФИО (транслит): фамилия + инициалы, при коллизии — с номером. */
    private static function uniqueLogin(string $fio): string
    {
        $parts = preg_split('/\s+/u', trim($fio));
        $base = self::translit((string) ($parts[0] ?? 'user'));
        foreach (array_slice($parts, 1) as $w) { $base .= mb_substr(self::translit($w), 0, 1, 'UTF-8'); }
        $base = preg_replace('/[^a-z0-9]/', '', mb_strtolower($base, 'UTF-8'));
        if ($base === '') { $base = 'user'; }
        $login = $base; $i = 1;
        while (Database::scalar('SELECT 1 FROM users WHERE login = ?', [$login])) { $login = $base . (++$i); }
        return $login;
    }

    private static function translit(string $s): string
    {
        $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h',
            'ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
        $s = mb_strtolower($s, 'UTF-8');
        return strtr($s, $map);
    }

    /** Временный пароль (соответствует политике сложности; показывается админу один раз). */
    private static function tempPassword(): string
    {
        $L = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; $l = 'abcdefghijkmnpqrstuvwxyz'; $d = '23456789'; $sp = '!$%&*';
        return $L[random_int(0, strlen($L) - 1)]
            . substr(str_shuffle(str_repeat($l, 2)), 0, 4)
            . $d[random_int(0, strlen($d) - 1)] . $d[random_int(0, strlen($d) - 1)]
            . $sp[random_int(0, strlen($sp) - 1)];
    }
}
