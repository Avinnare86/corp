<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Tariff;

/**
 * Дневной повышающий коэффициент к тарифу проверки анкет (1.0–2.0).
 * Менеджер проекта «Анкеты» задаёт его день в день или за прошлый рабочий день
 * (на следующий рабочий); администратор — за любой день. Эффективная цена анкеты =
 * базовый тариф страны × коэффициент дня проверки (применяется во всех расчётах ЗП/бюджета).
 */
class TariffCoeffController extends Controller
{
    private function gate(): array
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!Auth::effectiveHas('admin', 'anketa_manager')) {
            flash('Раздел доступен менеджеру проекта «Анкеты» и администратору.', 'error');
            $this->redirect('/');
        }
        return $me;
    }

    public function index(): void
    {
        $me = $this->gate();
        $today = date('Y-m-d');
        $prevWd = Tariff::prevWorkingDay($today);
        $this->view('tariff/coeff', [
            'title'      => 'Коэффициент тарифа проверки анкет',
            'rows'       => Database::all(
                "SELECT t.work_date, t.coefficient, t.set_at, u.full_name AS by_name
                   FROM tariff_day_coeff t LEFT JOIN users u ON u.id = t.set_by
                  ORDER BY t.work_date DESC LIMIT 60"),
            'today'      => $today,
            'prevWd'     => $prevWd,
            'todayCoeff' => Tariff::dayCoeff($today),
            'prevCoeff'  => Tariff::dayCoeff($prevWd),
            'isAdmin'    => Auth::effectiveHas('admin'),
            'min'        => Tariff::COEFF_MIN,
            'max'        => Tariff::COEFF_MAX,
            'csrf'       => Auth::csrf(),
        ]);
    }

    public function save(): void
    {
        $me = $this->gate();
        Auth::verifyCsrf();
        $date = (string) $this->input('work_date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash('Некорректная дата.', 'error'); $this->redirect('/tariff-coeff');
        }
        $coeff = (float) str_replace(',', '.', (string) $this->input('coefficient', 1));
        if (!Auth::effectiveHas('admin') && !Tariff::managerCanEdit($date)) {
            flash('Менеджер задаёт коэффициент только день в день или за прошлый рабочий день (на следующий рабочий). За другие даты — к администратору.', 'error');
            $this->redirect('/tariff-coeff');
        }
        Tariff::setDayCoeff($date, $coeff, (int) $me['id']);
        flash('Коэффициент за ' . date('d.m.Y', strtotime($date)) . ' установлен: '
            . number_format(Tariff::clampCoeff($coeff), 2, ',', ' ') . '. Суммы за этот день пересчитываются.');
        $this->redirect('/tariff-coeff');
    }
}
