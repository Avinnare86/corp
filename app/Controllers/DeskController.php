<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

/**
 * «Рабочий стол» объединён в главную страницу-дашборд (DashboardController + DashboardService).
 * Маршрут /desk сохранён для совместимости старых ссылок и ведёт на главную.
 */
class DeskController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $this->redirect('/');
    }
}
