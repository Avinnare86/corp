<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

class AttendanceController extends Controller
{
    public function open(): void
    {
        Auth::requireRole('employee', 'admin');
        Auth::verifyCsrf();
        $uid = Auth::id();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $row = Database::one('SELECT * FROM work_days WHERE employee_id = ? AND work_date = ?', [$uid, $today]);
        if (!$row) {
            Database::insert('INSERT INTO work_days (employee_id, work_date, opened_at) VALUES (?,?,?)', [$uid, $today, $now]);
            flash('Вы приступили к работе. Хорошего дня!');
        } elseif (empty($row['opened_at'])) {
            Database::run('UPDATE work_days SET opened_at = ? WHERE id = ?', [$now, $row['id']]);
            flash('Вы приступили к работе.');
        } elseif (!empty($row['closed_at'])) {
            // Возобновление после случайного завершения.
            Database::run('UPDATE work_days SET closed_at = NULL WHERE id = ?', [$row['id']]);
            flash('Работа возобновлена.');
        } else {
            flash('Вы уже работаете.', 'info');
        }
        $this->redirect('/dashboard');
    }

    public function close(): void
    {
        Auth::requireRole('employee', 'admin');
        Auth::verifyCsrf();
        $uid = Auth::id();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $row = Database::one('SELECT * FROM work_days WHERE employee_id = ? AND work_date = ?', [$uid, $today]);
        if (!$row || empty($row['opened_at'])) {
            flash('Сначала приступите к работе.', 'error');
        } else {
            Database::run('UPDATE work_days SET closed_at = ? WHERE id = ?', [$now, $row['id']]);
            flash('Работа завершена. Явка засчитана.');
        }
        $this->redirect('/dashboard');
    }

    /** Сегодняшняя запись явки сотрудника (или null). */
    public static function today(int $uid): ?array
    {
        return Database::one('SELECT * FROM work_days WHERE employee_id = ? AND work_date = ?', [$uid, date('Y-m-d')]);
    }

    /** Работает ли сотрудник сейчас (день открыт и не завершён). */
    public static function isWorking(int $uid): bool
    {
        $row = self::today($uid);
        return $row && !empty($row['opened_at']) && empty($row['closed_at']);
    }
}
