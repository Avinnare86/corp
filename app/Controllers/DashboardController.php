<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\PayrollService;
use App\Services\NotificationService;
use App\Services\RatingService;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    /** Главная страница портала — дашборд (для всех ролей). */
    public function index(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $this->view('dashboard/home', [
            'title' => 'Главная',
            'user'  => $user,
            'dash'  => DashboardService::forUser((int) $user['id']),
        ]);
    }

    /** Расчётный листок (доступен некрупной ссылкой с дашборда). Период переключается через ?period=YYYY-MM. */
    public function payroll(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $period = (string) $this->input('period', PayrollService::currentPeriod());
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $period = PayrollService::currentPeriod();
        }
        $payroll = PayrollService::calculate((int) $user['id'], $period);

        // Позиция в рейтинге.
        $ranking = RatingService::ranking($period);
        $myRank = null;
        foreach ($ranking as $r) {
            if ((int) $r['id'] === (int) $user['id']) { $myRank = $r; break; }
        }

        $this->view('dashboard/payslip', [
            'title'    => 'Расчётный листок',
            'user'     => $user,
            'period'   => $period,
            'payroll'  => $payroll,
            'penaltyRows' => !empty($payroll['penalty_details_flag']) ? PayrollService::penaltyDetails((int) $user['id'], $period) : [],
            'myRank'   => $myRank,
            'totalEmployees' => count($ranking),
            'unread'   => NotificationService::unreadCount((int) $user['id']),
            'today'    => AttendanceController::today((int) $user['id']),
        ]);
    }
}
