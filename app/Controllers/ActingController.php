<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Acting;
use App\Services\Org;
use App\Services\NotificationService;

/**
 * Назначение исполняющих обязанности (И.о./ВРИО) на период и переключение контекста
 * «работаю как И.о.» (self ↔ замещение). Назначать может сам замещаемый, его начальник,
 * любой вышестоящий по структуре или админ (Acting::canAssign).
 */
class ActingController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        // Для кого текущий может назначить И.о.: сам + подчинённые (директор/админ — все активные).
        if (Auth::isAdmin() || Org::tier($uid) === 'director') {
            $absentChoices = Database::all('SELECT id, full_name, position FROM users WHERE is_active = 1 ORDER BY full_name');
        } else {
            $ids = array_values(array_unique(array_merge([$uid], Org::subordinateUserIds($uid))));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $absentChoices = Database::all("SELECT id, full_name, position FROM users WHERE id IN ($ph) AND is_active = 1 ORDER BY full_name", $ids);
        }
        $this->view('acting/index', [
            'title' => 'Замещение и И.о./ВРИО',
            'assignments' => Acting::activeList(),
            'absentChoices' => $absentChoices,
            'allUsers' => Database::all('SELECT id, full_name, position FROM users WHERE is_active = 1 ORDER BY full_name'),
            'uid' => $uid,
            'csrf' => Auth::csrf(),
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $absent = (int) $this->input('absent_id');
        $acting = (int) $this->input('acting_id');
        $kind   = $this->input('kind') === 'vrio' ? 'vrio' : 'io';
        $from   = (string) $this->input('date_from');
        $to     = (string) $this->input('date_to');
        $reason = trim((string) $this->input('reason'));
        $vacId  = $this->input('vacation_id') ? (int) $this->input('vacation_id') : null;

        if (!$absent || !$acting) { flash('Укажите замещаемого и исполняющего обязанности.', 'error'); $this->redirect('/acting'); }
        if ($absent === $acting) { flash('Нельзя назначить сотрудника И.о. самого себя.', 'error'); $this->redirect('/acting'); }
        if (!Acting::canAssign($uid, $absent)) { flash('Вы не можете назначать И.о. для этого сотрудника.', 'error'); $this->redirect('/acting'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            flash('Укажите корректный период (с / по).', 'error'); $this->redirect('/acting');
        }
        if (!Database::scalar('SELECT 1 FROM users WHERE id = ? AND is_active = 1', [$acting])) {
            flash('Исполняющий обязанности не найден или неактивен.', 'error'); $this->redirect('/acting');
        }
        Database::insert(
            'INSERT INTO acting_assignments (absent_id, acting_id, kind, date_from, date_to, reason, vacation_id, created_by, status) VALUES (?,?,?,?,?,?,?,?,?)',
            [$absent, $acting, $kind, $from, $to, $reason ?: null, $vacId, $uid, 'active']);
        $aName = (string) Database::scalar('SELECT full_name FROM users WHERE id = ?', [$absent]);
        NotificationService::create($acting, 'Назначены исполняющие обязанности',
            "Вы назначены " . ($kind === 'vrio' ? 'ВРИО' : 'И.о.') . " за «{$aName}» на период {$from} — {$to}. Переключиться можно в шапке портала.");
        flash('Назначение И.о./ВРИО сохранено.');
        $this->redirect('/acting');
    }

    public function cancel(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $a = Database::one('SELECT * FROM acting_assignments WHERE id = ?', [$id]);
        if (!$a) { $this->redirect('/acting'); }
        if (!Acting::canAssign($uid, (int) $a['absent_id'])) { flash('Нет прав отменить это назначение.', 'error'); $this->redirect('/acting'); }
        Database::run("UPDATE acting_assignments SET status = 'canceled' WHERE id = ?", [$id]);
        // Если кто-то сейчас работает в этом контексте — сбросим у себя (у других сбросится по ревалидации).
        if ((int) ($_SESSION['acting_as'] ?? 0) === (int) $a['absent_id'] && $uid === (int) $a['acting_id']) {
            unset($_SESSION['acting_as']);
        }
        flash('Назначение отменено.');
        $this->redirect('/acting');
    }

    /** Переключение контекста: set/clear $_SESSION['acting_as'] (валидно только при активном назначении). */
    public function switchCtx(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $to = (int) $this->input('to');
        if ($to && Acting::activeFor($uid, $to) !== null) {
            $_SESSION['acting_as'] = $to;
        } else {
            unset($_SESSION['acting_as']);   // 0/пусто/невалидно → работаю как сам
        }
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = $ref !== '' ? (parse_url($ref, PHP_URL_PATH) ?: '/') : '/';
        $this->redirect(str_starts_with($path, '/') ? $path : '/');
    }
}
