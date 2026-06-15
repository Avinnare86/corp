<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

class NotificationController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $items = Database::all(
            'SELECT * FROM notifications WHERE employee_id = ? ORDER BY created_at DESC, id DESC',
            [$uid]
        );
        $this->view('notifications/index', [
            'title' => 'Уведомления',
            'items' => $items,
        ]);
    }

    public function markRead(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        Database::run(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND employee_id = ?',
            [$id, Auth::id()]
        );
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '') {
            $this->json(['ok' => true, 'id' => (int) $id]);
        }
        $this->redirect('/notifications');
    }
}
