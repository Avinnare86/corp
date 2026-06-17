<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

/**
 * Справочник оснований стимула по отделам («за что доплата») — отдельно от формальных
 * stimulus_grounds. Начальник отдела ведёт основания своего отдела; директор/админ — любые
 * и общие (department_id IS NULL). Используется как подсказка при назначении стимула.
 */
class ReasonController extends Controller
{
    private const ROLES = ['dept_head', 'deputy_director', 'director', 'admin'];

    private function canAll(): bool
    {
        return Auth::has('director') || Auth::isAdmin();
    }

    /** Отдел текущего пользователя: где он начальник, иначе его подразделение. */
    private function myDeptId(): int
    {
        $uid = (int) Auth::id();
        $head = Database::scalar('SELECT id FROM departments WHERE head_id = ?', [$uid]);
        if ($head) { return (int) $head; }
        return (int) Database::scalar('SELECT department_id FROM users WHERE id = ?', [$uid]);
    }

    public function index(): void
    {
        Auth::requireRole(...self::ROLES);
        $all = $this->canAll();
        if ($all) {
            $reasons = Database::all(
                'SELECT r.*, d.name AS dept_name FROM stimulus_reasons r
                   LEFT JOIN departments d ON d.id = r.department_id
                  ORDER BY (r.department_id IS NULL) DESC, d.name, r.text');
            $depts = Database::all('SELECT id, name FROM departments ORDER BY name');
            $myDept = null;
            $myDeptId = 0;
        } else {
            $myDeptId = $this->myDeptId();
            $reasons = Database::all(
                'SELECT r.*, d.name AS dept_name FROM stimulus_reasons r
                   LEFT JOIN departments d ON d.id = r.department_id
                  WHERE r.department_id = ? OR r.department_id IS NULL
                  ORDER BY (r.department_id IS NULL) DESC, r.text', [$myDeptId]);
            $depts = null;
            $myDept = $myDeptId ? Database::one('SELECT * FROM departments WHERE id = ?', [$myDeptId]) : null;
        }
        $this->view('stimulus/reasons', [
            'title'    => 'Справочник оснований стимула',
            'reasons'  => $reasons,
            'depts'    => $depts,
            'canAll'   => $all,
            'myDept'   => $myDept,
            'myDeptId' => $myDeptId,
            'csrf'     => Auth::csrf(),
        ]);
    }

    public function store(): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();
        $text = trim((string) $this->input('text'));
        $id   = (int) $this->input('id');
        if ($text === '') { flash('Введите текст основания.', 'error'); $this->redirect('/memos/reasons'); }

        if ($this->canAll()) {
            $raw = $this->input('department_id');
            $dep = ($raw === null || $raw === '' || (int) $raw === 0) ? null : (int) $raw;
        } else {
            $dep = $this->myDeptId();
            if ($id && (int) Database::scalar('SELECT department_id FROM stimulus_reasons WHERE id = ?', [$id]) !== $dep) {
                flash('Можно править только основания своего отдела.', 'error');
                $this->redirect('/memos/reasons');
            }
        }
        $active = $this->input('id') ? ((int) $this->input('is_active') ? 1 : 0) : 1;

        if ($id) {
            Database::run('UPDATE stimulus_reasons SET text = ?, department_id = ?, is_active = ? WHERE id = ?', [$text, $dep, $active, $id]);
        } else {
            Database::insert('INSERT INTO stimulus_reasons (department_id, text, is_active) VALUES (?,?,1)', [$dep, $text]);
        }
        flash('Основание сохранено.');
        $this->redirect('/memos/reasons');
    }

    public function delete(string $id): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();
        $id = (int) $id;
        if (!$this->canAll() && (int) Database::scalar('SELECT department_id FROM stimulus_reasons WHERE id = ?', [$id]) !== $this->myDeptId()) {
            flash('Можно отключать только основания своего отдела.', 'error');
            $this->redirect('/memos/reasons');
        }
        Database::run('UPDATE stimulus_reasons SET is_active = 0 WHERE id = ?', [$id]);
        flash('Основание отключено.');
        $this->redirect('/memos/reasons');
    }
}
