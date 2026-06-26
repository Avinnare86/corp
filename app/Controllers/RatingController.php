<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\RatingService;

class RatingController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $period = (string) $this->input('period', date('Y-m'));
        $from = (string) $this->input('from', '');
        $to = (string) $this->input('to', '');
        $ranking = RatingService::ranking($period, $from, $to);

        $this->view('rating/index', [
            'title'   => 'Рейтинг специалистов',
            'ranking' => $ranking,
            'period'  => $period,
            'from'    => $from,
            'to'      => $to,
            'meId'    => Auth::id(),
        ]);
    }
}
