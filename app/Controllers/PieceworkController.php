<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Controllers\AttendanceController;

class PieceworkController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('employee', 'admin');
        $uid = Auth::id();
        $period = $this->input('period', date('Y-m'));

        $operations = Database::all('SELECT * FROM operations WHERE is_active = 1 ORDER BY name');
        $entries = Database::all(
            "SELECT pw.*, o.name AS op_name, o.unit_price
               FROM piecework pw
               JOIN operations o ON o.id = pw.operation_id
              WHERE pw.employee_id = ? AND substr(pw.work_date,1,7) = ?
              ORDER BY pw.work_date DESC, pw.id DESC",
            [$uid, $period]
        );
        $this->view('piecework/index', [
            'title'      => 'Визы и операции',
            'operations' => $operations,
            'entries'    => $entries,
            'period'     => $period,
            'working'    => AttendanceController::isWorking((int) $uid),
        ]);
    }

    public function store(): void
    {
        Auth::requireRole('employee', 'admin');
        Auth::verifyCsrf();
        $uid = Auth::id();
        if (!AttendanceController::isWorking((int) $uid)) {
            flash('Рабочий день не открыт. Нажмите «Приступить к работе» в кабинете.', 'error');
            $this->redirect('/dashboard');
        }

        $opId = (int) $this->input('operation_id');
        $date = $this->input('work_date', date('Y-m-d'));
        $qty  = (int) $this->input('quantity', 0);

        $op = Database::one('SELECT * FROM operations WHERE id = ? AND is_active = 1', [$opId]);
        if (!$op || $qty <= 0) {
            flash('Выберите операцию и укажите количество больше нуля.', 'error');
            $this->redirect('/piecework');
        }
        Database::insert(
            'INSERT INTO piecework (employee_id, operation_id, work_date, quantity) VALUES (?,?,?,?)',
            [$uid, $opId, $date, $qty]
        );
        flash("Добавлено: {$op['name']} — {$qty} шт за {$date}.");
        $this->redirect('/piecework?period=' . substr($date, 0, 7));
    }

    public function destroy(string $id): void
    {
        Auth::requireRole('employee', 'admin');
        Auth::verifyCsrf();
        Database::run('DELETE FROM piecework WHERE id = ? AND employee_id = ?', [$id, Auth::id()]);
        flash('Запись удалена.');
        $this->redirect('/piecework');
    }
}
