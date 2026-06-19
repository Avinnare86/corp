<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Controllers\AttendanceController;

/**
 * Очередь проверки досье: назначенные досье, отметка «проверено».
 * Можно отметить «Без замечаний» (статус) либо указать несколько причин-доработок.
 */
class DossierController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('employee', 'admin');
        $uid = Auth::id();

        $pending = Database::all(
            "SELECT ai.*, c.name AS country_name, al.code AS arrival_code, ad.text AS arrival_detail
               FROM assignment_items ai
               LEFT JOIN countries c ON c.code = ai.country_code
               LEFT JOIN arrival_lines al ON al.id = ai.arrival_line_id
               LEFT JOIN arrival_details ad ON ad.id = ai.arrival_detail_id
              WHERE ai.assigned_to = ? AND ai.checked_at IS NULL
              ORDER BY ai.country_code, ai.id LIMIT 500",
            [$uid]
        );
        $pendingTotal = (int) Database::scalar('SELECT COUNT(*) FROM assignment_items WHERE assigned_to=? AND checked_at IS NULL', [$uid]);
        $doneToday = (int) Database::scalar("SELECT COUNT(*) FROM assignment_items WHERE assigned_to=? AND substr(checked_at,1,10)=?", [$uid, date('Y-m-d')]);
        $checkedTotal = (int) Database::scalar('SELECT COUNT(*) FROM assignment_items WHERE assigned_to=? AND checked_at IS NOT NULL', [$uid]);

        $this->view('dossiers/index', array_merge([
            'title'        => 'Проверка досье',
            'pending'      => $pending,
            'pendingTotal' => $pendingTotal,
            'doneToday'    => $doneToday,
            'checkedTotal' => $checkedTotal,
            'working'      => AttendanceController::isWorking((int) $uid),
        ], self::pickerData()));
    }

    /** Вкладка «Проверенные». */
    public function checked(): void
    {
        Auth::requireRole('employee', 'admin');
        $uid = Auth::id();
        $items = Database::all(
            "SELECT ai.*, c.name AS country_name, al.code AS arrival_code, ad.text AS arrival_detail
               FROM assignment_items ai
               LEFT JOIN countries c ON c.code = ai.country_code
               LEFT JOIN arrival_lines al ON al.id = ai.arrival_line_id
               LEFT JOIN arrival_details ad ON ad.id = ai.arrival_detail_id
              WHERE ai.assigned_to = ? AND ai.checked_at IS NOT NULL
              ORDER BY ai.checked_at DESC, ai.id DESC LIMIT 500",
            [$uid]
        );
        // Текущие причины по каждому досье (для предвыбора при редактировании).
        $selected = [];
        foreach ($items as $it) {
            $rows = Database::all('SELECT comment_id FROM item_comments WHERE item_id = ?', [$it['id']]);
            $selected[$it['id']] = array_map(fn($r) => (int) $r['comment_id'], $rows);
        }
        $checkedTotal = (int) Database::scalar('SELECT COUNT(*) FROM assignment_items WHERE assigned_to=? AND checked_at IS NOT NULL', [$uid]);
        $pendingTotal = (int) Database::scalar('SELECT COUNT(*) FROM assignment_items WHERE assigned_to=? AND checked_at IS NULL', [$uid]);

        $this->view('dossiers/checked', array_merge([
            'title' => 'Проверенные досье',
            'items' => $items,
            'selected' => $selected,
            'checkedTotal' => $checkedTotal,
            'pendingTotal' => $pendingTotal,
        ], self::pickerData()));
    }

    /** Гард: работать можно только при открытом дне. */
    private function requireWorkingDay(): void
    {
        if (AttendanceController::isWorking((int) Auth::id())) {
            return;
        }
        $msg = 'Рабочий день не открыт. Нажмите «Приступить к работе» в кабинете.';
        if ($this->isXhr()) {
            $this->json(['ok' => false, 'message' => $msg]);
        }
        flash($msg, 'error');
        $this->redirect('/dashboard');
    }

    /** Отметить одно досье (несколько причин или без замечаний). */
    public function check(string $id): void
    {
        Auth::requireRole('employee', 'admin');
        Auth::verifyCsrf();
        $this->requireWorkingDay();
        $uid = Auth::id();
        $xhr = $this->isXhr();
        $item = Database::one('SELECT * FROM assignment_items WHERE id=? AND assigned_to=?', [$id, $uid]);
        if (!$item || $item['checked_at']) {
            if ($xhr) { $this->json(['ok' => false, 'message' => 'Досье недоступно']); }
            $this->redirect('/dossiers');
        }
        $cids = $this->commentIds();
        $reasons = $this->saveReasons((int) $id, $cids);
        Database::run('UPDATE assignment_items SET checked_at=? WHERE id=?', [date('Y-m-d H:i:s'), $id]);
        if ($xhr) { $this->json(['ok' => true, 'id' => (int) $id, 'reg' => $item['reg_number'], 'status' => $reasons === '' ? 'ok' : 'fix', 'reasons' => $reasons]); }
        $this->redirect('/dossiers');
    }

    /** Массовая отметка выбранных. */
    public function bulk(): void
    {
        Auth::requireRole('employee', 'admin');
        Auth::verifyCsrf();
        $this->requireWorkingDay();
        $uid = (int) Auth::id();
        $xhr = $this->isXhr();
        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        if (!$ids) { if ($xhr) { $this->json(['ok' => false, 'count' => 0]); } $this->redirect('/dossiers'); }
        $cids = $this->commentIds();
        $now = date('Y-m-d H:i:s');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $ok = 0; $reasons = '';
        foreach ($ids as $id) {
            $own = Database::scalar('SELECT 1 FROM assignment_items WHERE id=? AND assigned_to=? AND checked_at IS NULL', [$id, $uid]);
            if (!$own) { continue; }
            $reasons = $this->saveReasons($id, $cids);
            Database::run('UPDATE assignment_items SET checked_at=? WHERE id=?', [$now, $id]);
            $ok++;
        }
        $pdo->commit();
        if ($xhr) { $this->json(['ok' => true, 'count' => $ok, 'ids' => $ids, 'status' => $reasons === '' ? 'ok' : 'fix', 'reasons' => $reasons]); }
        flash("Отмечено: {$ok}.");
        $this->redirect('/dossiers');
    }

    /** Изменить причины у проверенного досье. */
    public function recomment(string $id): void
    {
        Auth::requireRole('employee', 'admin');
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $own = Database::scalar('SELECT 1 FROM assignment_items WHERE id=? AND assigned_to=? AND checked_at IS NOT NULL', [$id, $uid]);
        if ($own) { $reasons = $this->saveReasons((int) $id, $this->commentIds()); }
        else { $reasons = ''; }
        if ($this->isXhr()) { $this->json(['ok' => (bool) $own, 'id' => (int) $id, 'status' => $reasons === '' ? 'ok' : 'fix', 'reasons' => $reasons]); }
        $this->redirect('/dossiers/checked');
    }

    /** Вернуть досье в работу. */
    public function uncheck(string $id): void
    {
        Auth::requireRole('employee', 'admin');
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        Database::run('DELETE FROM item_comments WHERE item_id=?', [$id]);
        Database::run('UPDATE assignment_items SET checked_at=NULL, comment_id=NULL, comment_text=NULL WHERE id=? AND assigned_to=? AND checked_at IS NOT NULL', [$id, $uid]);
        flash('Досье возвращено в работу.');
        $this->redirect('/dossiers/checked');
    }

    // ---------- helpers ----------
    private function isXhr(): bool { return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== ''; }

    private function commentIds(): array
    {
        $raw = $_POST['comment_id'] ?? [];
        if (!is_array($raw)) { $raw = $raw === '' ? [] : [$raw]; }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    /** Заменить набор причин у досье; вернуть склейку текстов ('' = без замечаний). */
    private function saveReasons(int $itemId, array $commentIds): string
    {
        Database::run('DELETE FROM item_comments WHERE item_id = ?', [$itemId]);
        $texts = [];
        foreach ($commentIds as $cid) {
            $text = Database::scalar('SELECT text FROM dorabotka_comments WHERE id = ? AND is_active = 1', [$cid]);
            if ($text === false) { continue; }
            Database::run('INSERT INTO item_comments (item_id, comment_id) VALUES (?,?)', [$itemId, $cid]);
            $texts[] = $text;
        }
        $joined = implode('; ', $texts);
        Database::run('UPDATE assignment_items SET comment_id=?, comment_text=? WHERE id=?',
            [$commentIds[0] ?? null, $joined !== '' ? $joined : null, $itemId]);
        return $joined;
    }

    /** Данные для двухколоночного выбора причин: список, категории, ТОП. */
    private static function pickerData(): array
    {
        $comments = Database::all('SELECT id, text, category FROM dorabotka_comments WHERE is_active=1 ORDER BY category, text');
        $categories = [];
        foreach ($comments as $c) { if (!in_array($c['category'], $categories, true)) { $categories[] = $c['category']; } }
        sort($categories, SORT_STRING);
        $top = Database::all(
            "SELECT dc.id, dc.text FROM dorabotka_comments dc
               JOIN item_comments ic ON ic.comment_id = dc.id
              WHERE dc.is_active=1 GROUP BY dc.id, dc.text
              ORDER BY COUNT(ic.id) DESC, dc.text LIMIT 12"
        );
        return ['pickComments' => $comments, 'pickCategories' => $categories, 'pickTop' => $top];
    }
}
