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
        $period = $this->input('period', date('Y-m'));
        $ranking = RatingService::ranking($period);

        $this->view('rating/index', [
            'title'   => 'Рейтинг специалистов',
            'ranking' => $ranking,
            'period'  => $period,
            'meId'    => Auth::id(),
        ]);
    }
}
