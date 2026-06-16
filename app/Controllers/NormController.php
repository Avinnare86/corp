<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\NormService;
use App\Services\PayrollService;

/**
 * Недельный норматив проверки анкет и отчёт о выработке.
 * Менеджер проекта (квота) задаёт норматив проверяющим и видит их выработку по неделям;
 * сотрудник видит свой норматив и прогресс.
 */
class NormController extends Controller
{
    /** Отчёт менеджера: все проверяющие анкеты, их норматив (редактируемый) и выработка по неделям. */
    public function report(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        $period = (string) $this->input('period', PayrollService::currentPeriod());
        $emps = Database::all(
            "SELECT id, full_name, anketa_norm, schedule_type FROM users
              WHERE is_active = 1 AND does_anketas = 1 ORDER BY full_name");
        $rows = [];
        foreach ($emps as $e) {
            $n = NormService::forEmployee((int) $e['id'], $period);
            $rows[] = ['u' => $e, 'norm' => $n];
        }
        $this->view('norm/report', [
            'title'   => 'Норматив проверки анкет',
            'rows'    => $rows,
            'period'  => $period,
            'canEdit' => Auth::has('anketa_manager', 'admin', 'hr_manager'),
            'csrf'    => Auth::csrf(),
        ]);
    }

    /** Личный норматив и выработка сотрудника. */
    public function mine(): void
    {
        Auth::requireRole('anketa_worker', 'admin');
        $uid = (int) Auth::id();
        $period = (string) $this->input('period', PayrollService::currentPeriod());
        $this->view('norm/mine', [
            'title'  => 'Мой норматив проверки',
            'norm'   => NormService::forEmployee($uid, $period),
            'period' => $period,
            'me'     => Auth::user(),
        ]);
    }

    /** Установить/снять норматив сотруднику (пусто → NULL = классическая модель оплаты). */
    public function set(): void
    {
        Auth::requireRole('anketa_manager', 'admin', 'hr_manager');
        Auth::verifyCsrf();
        $uid = (int) $this->input('user_id');
        $raw = trim((string) $this->input('anketa_norm', ''));
        $val = ($raw === '') ? null : max(0, (int) $raw);
        Database::run('UPDATE users SET anketa_norm = ? WHERE id = ?', [$val, $uid]);
        flash($val === null ? 'Норматив снят (классическая модель оплаты).' : 'Норматив сохранён.');
        $this->redirect('/norm/report' . ($this->input('period') ? '?period=' . urlencode((string) $this->input('period')) : ''));
    }
}
