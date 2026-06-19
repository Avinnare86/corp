<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

/**
 * Справочники линий прибытия для квоты:
 *  - ЛП (arrival_lines): сокращение (напр. ПП = План приема) + полное название;
 *  - ДЛП (arrival_details): детализированная линия (значение из заголовка списка).
 * Анкета хранит ссылки arrival_line_id/arrival_detail_id, отображается как «ЛП/ДЛП».
 * Управляет менеджер проекта квота (anketa_manager). Есть объединение дублей и массовая правка.
 */
class ArrivalController extends Controller
{
    private const ROLES = ['anketa_manager', 'admin'];

    // ---------- get-or-create (используются и в ManagerController при загрузке/ручном вводе) ----------

    private static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }

    /** ЛП по code (создаёт при отсутствии). '' → null. */
    public static function resolveLine(string $code, string $name = ''): ?int
    {
        $code = trim($code);
        if ($code === '') { return null; }
        foreach (Database::all('SELECT id, code FROM arrival_lines') as $r) {
            if (mb_strtolower(trim($r['code'])) === mb_strtolower($code)) { return (int) $r['id']; }
        }
        return (int) Database::insert('INSERT INTO arrival_lines (code, name, is_active) VALUES (?,?,1)', [$code, $name]);
    }

    /** ДЛП по тексту (нормализованное сравнение; создаёт при отсутствии). '' → null. */
    public static function resolveDetail(string $text): ?int
    {
        $text = trim($text);
        if ($text === '') { return null; }
        $norm = self::norm($text);
        foreach (Database::all('SELECT id, text FROM arrival_details') as $r) {
            if (self::norm($r['text']) === $norm) { return (int) $r['id']; }
        }
        return (int) Database::insert('INSERT INTO arrival_details (text, is_active) VALUES (?,1)', [$text]);
    }

    // ---------- страница справочников ----------

    public function index(): void
    {
        Auth::requireRole(...self::ROLES);
        $lines = Database::all(
            "SELECT al.*, (SELECT COUNT(*) FROM assignment_items ai WHERE ai.arrival_line_id = al.id) AS uses
               FROM arrival_lines al ORDER BY al.is_active DESC, al.code");
        $details = Database::all(
            "SELECT ad.*, (SELECT COUNT(*) FROM assignment_items ai WHERE ai.arrival_detail_id = ad.id) AS uses
               FROM arrival_details ad ORDER BY ad.is_active DESC, ad.text");
        $this->view('manager/arrival', [
            'title'   => 'Линии прибытия (справочники)',
            'lines'   => $lines,
            'details' => $details,
            'csrf'    => Auth::csrf(),
        ]);
    }

    // ---------- ЛП ----------

    public function storeLine(): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();
        $id = (int) $this->input('id');
        $code = trim((string) $this->input('code'));
        $name = trim((string) $this->input('name'));
        if ($code === '') { flash('Укажите сокращение линии прибытия (напр. ПП).', 'error'); $this->redirect('/manager/arrival'); }
        $active = $id ? ((int) $this->input('is_active') ? 1 : 0) : 1;
        if ($id) {
            Database::run('UPDATE arrival_lines SET code=?, name=?, is_active=? WHERE id=?', [$code, $name, $active, $id]);
        } else {
            Database::insert('INSERT INTO arrival_lines (code, name, is_active) VALUES (?,?,1)', [$code, $name]);
        }
        flash('Линия прибытия (ЛП) сохранена.');
        $this->redirect('/manager/arrival');
    }

    public function deleteLine(string $id): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();
        Database::run('UPDATE arrival_lines SET is_active=0 WHERE id=?', [(int) $id]);
        flash('ЛП отключена (скрыта из выбора). Уже проставленные анкеты не меняются.');
        $this->redirect('/manager/arrival');
    }

    public function mergeLine(): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();
        $this->merge('arrival_lines', 'arrival_line_id', (int) $this->input('source_id'), (int) $this->input('target_id'), 'ЛП');
    }

    // ---------- ДЛП ----------

    public function storeDetail(): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();
        $id = (int) $this->input('id');
        $text = trim((string) $this->input('text'));
        if ($text === '') { flash('Укажите текст детализированной линии (ДЛП).', 'error'); $this->redirect('/manager/arrival'); }
        $active = $id ? ((int) $this->input('is_active') ? 1 : 0) : 1;
        if ($id) {
            Database::run('UPDATE arrival_details SET text=?, is_active=? WHERE id=?', [$text, $active, $id]);
        } else {
            Database::insert('INSERT INTO arrival_details (text, is_active) VALUES (?,1)', [$text]);
        }
        flash('Детализированная линия (ДЛП) сохранена.');
        $this->redirect('/manager/arrival');
    }

    public function deleteDetail(string $id): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();
        Database::run('UPDATE arrival_details SET is_active=0 WHERE id=?', [(int) $id]);
        flash('ДЛП отключена (скрыта из выбора). Уже проставленные анкеты не меняются.');
        $this->redirect('/manager/arrival');
    }

    public function mergeDetail(): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();
        $this->merge('arrival_details', 'arrival_detail_id', (int) $this->input('source_id'), (int) $this->input('target_id'), 'ДЛП');
    }

    /** Объединение: анкеты источника → цель, затем удалить источник. */
    private function merge(string $table, string $fk, int $source, int $target, string $label): void
    {
        if (!$source || !$target || $source === $target) {
            flash('Выберите две РАЗНЫЕ записи для объединения.', 'error'); $this->redirect('/manager/arrival');
        }
        $okS = Database::scalar("SELECT 1 FROM $table WHERE id=?", [$source]);
        $okT = Database::scalar("SELECT 1 FROM $table WHERE id=?", [$target]);
        if (!$okS || !$okT) { flash('Запись для объединения не найдена.', 'error'); $this->redirect('/manager/arrival'); }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $moved = Database::run("UPDATE assignment_items SET $fk=? WHERE $fk=?", [$target, $source])->rowCount();
            Database::run("DELETE FROM $table WHERE id=?", [$source]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            flash('Ошибка объединения: ' . $e->getMessage(), 'error'); $this->redirect('/manager/arrival');
        }
        flash("$label объединены: перенесено анкет — {$moved}, дубль удалён.");
        $this->redirect('/manager/arrival');
    }

    // ---------- массовая правка линии у уже загруженных анкет ----------

    /** Проставить/изменить линию (ЛП+ДЛП) у выбранных анкет (ids[]) или по фильтру список/страна. */
    public function assign(): void
    {
        Auth::requireRole(...self::ROLES);
        Auth::verifyCsrf();

        // ЛП: выбор существующей или новая.
        $lineId = $this->input('line_id') ? (int) $this->input('line_id') : null;
        $newLine = trim((string) $this->input('line_code'));
        if (!$lineId && $newLine !== '') { $lineId = self::resolveLine($newLine); }
        // ДЛП: выбор существующей или новая.
        $detailId = $this->input('detail_id') ? (int) $this->input('detail_id') : null;
        $newDetail = trim((string) $this->input('detail_text'));
        if (!$detailId && $newDetail !== '') { $detailId = self::resolveDetail($newDetail); }

        if (!$lineId && !$detailId) {
            $this->json(['ok' => false, 'message' => 'Выберите линию прибытия (ЛП и/или ДЛП).']);
        }

        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        if (!$ids) { $this->json(['ok' => false, 'message' => 'Не выбрано ни одной анкеты.']); }

        $place = implode(',', array_fill(0, count($ids), '?'));
        $updated = Database::run(
            "UPDATE assignment_items SET arrival_line_id=?, arrival_detail_id=? WHERE id IN ($place)",
            array_merge([$lineId, $detailId], $ids))->rowCount();
        $this->json(['ok' => true, 'updated' => $updated, 'label' => arrival_label(
            $lineId ? (string) Database::scalar('SELECT code FROM arrival_lines WHERE id=?', [$lineId]) : '',
            $detailId ? (string) Database::scalar('SELECT text FROM arrival_details WHERE id=?', [$detailId]) : '')]);
    }
}
