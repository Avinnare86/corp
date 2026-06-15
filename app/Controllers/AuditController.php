<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Xlsx;

class AuditController extends Controller
{
    /** Условие фильтра из запроса. */
    private function filter(): array
    {
        $q = trim((string) $this->input('q'));
        $who = $this->input('user');
        $where = '1=1'; $params = [];
        if ($q !== '') {
            $where .= ' AND (label LIKE ? OR action LIKE ? OR details LIKE ? OR user_name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($who) { $where .= ' AND user_id = ?'; $params[] = (int) $who; }
        return [$where, $params, $q, $who];
    }

    public function export(): void
    {
        Auth::requireRole('admin');
        [$where, $params] = $this->filter();
        $rows = Database::all("SELECT * FROM audit_log WHERE $where ORDER BY id DESC LIMIT 5000", $params);
        $roleLabels = ['employee'=>'специалист','controller'=>'контролёр','manager'=>'менеджер','admin'=>'админ'];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                substr((string)$r['created_at'],0,19), $r['user_name'] ?: '—',
                $roleLabels[$r['role']] ?? $r['role'], $r['label'],
                (string)$r['details'], $r['ip'],
            ];
        }
        Xlsx::download('journal-' . date('Y-m-d') . '.xlsx', [[
            'name' => 'Журнал',
            'headers' => ['Время','Пользователь','Роль','Действие','Детали','IP'],
            'rows' => $out,
        ]]);
    }

    public function index(): void
    {
        Auth::requireRole('admin');
        [$where, $params, $q, $who] = $this->filter();

        $rows = Database::all("SELECT * FROM audit_log WHERE $where ORDER BY id DESC LIMIT 300", $params);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE $where", $params);
        $users = Database::all("SELECT id, full_name FROM users ORDER BY full_name");

        $this->view('audit/index', [
            'title' => 'Журнал действий',
            'rows'  => $rows,
            'total' => $total,
            'users' => $users,
            'q'     => $q,
            'who'   => $who,
        ]);
    }
}
