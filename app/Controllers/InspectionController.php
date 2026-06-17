<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\SamplingService;
use App\Services\PenaltyService;
use App\Services\NotificationService;
use App\Services\Settings;

class InspectionController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('controller', 'admin', 'manager');

        $batches = Database::all(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM inspections i WHERE i.batch_id = b.id) AS total,
                    (SELECT COUNT(*) FROM inspections i WHERE i.batch_id = b.id AND i.is_correct IS NOT NULL) AS done
               FROM sample_batches b
              ORDER BY b.work_date DESC"
        );

        $this->view('inspect/index', [
            'title'     => 'Меню контролёра',
            'batches'   => $batches,
            'yesterday' => SamplingService::yesterday(),
            'percent'   => Settings::inspectionPercent(),
        ]);
    }

    public function generate(): void
    {
        Auth::requireRole('controller', 'admin', 'manager');
        Auth::verifyCsrf();

        $date = $this->input('work_date', SamplingService::yesterday());
        $batchId = SamplingService::generateForDate($date, (int) Auth::id());

        $total = (int) Database::scalar('SELECT COUNT(*) FROM inspections WHERE batch_id = ?', [$batchId]);
        if ($total === 0) {
            flash("За {$date} нет досье для выборки.", 'error');
            $this->redirect('/inspect');
        }
        flash("Выборка за {$date} сформирована: анкет на проверку — {$total}.");
        $this->redirect('/inspect/queue?date=' . urlencode($date));
    }

    public function queue(): void
    {
        Auth::requireRole('controller', 'admin', 'manager');
        $date = $this->input('date', SamplingService::yesterday());

        $batch = Database::one('SELECT * FROM sample_batches WHERE work_date = ?', [$date]);
        if (!$batch) {
            flash('Выборка за эту дату не сформирована.', 'error');
            $this->redirect('/inspect');
        }

        $items = Database::all(
            "SELECT i.*, d.reg_number, d.country_code, u.full_name AS employee_name,
                    et.name AS error_name, d.comment_text AS dorabotka
               FROM inspections i
               JOIN assignment_items d ON d.id = i.dossier_id
               JOIN users u    ON u.id = i.employee_id
               LEFT JOIN error_types et ON et.id = i.error_type_id
              WHERE i.batch_id = ?
              ORDER BY u.full_name, i.id",
            [$batch['id']]
        );

        $errorTypes = Database::all('SELECT * FROM error_types WHERE is_active = 1 ORDER BY name');

        $this->view('inspect/queue', [
            'title'      => "Проверка анкет за {$date}",
            'batch'      => $batch,
            'items'      => $items,
            'errorTypes' => $errorTypes,
            'date'       => $date,
        ]);
    }

    public function review(string $id): void
    {
        Auth::requireRole('controller', 'admin', 'manager');
        Auth::verifyCsrf();

        $inspection = Database::one('SELECT * FROM inspections WHERE id = ?', [$id]);
        if (!$inspection) {
            $this->redirect('/inspect');
        }

        $verdict = $this->input('verdict'); // 'correct' | 'error'
        $errorTypeId = $this->input('error_type_id');
        $comment = trim((string) $this->input('comment', ''));
        $isCorrect = $verdict === 'correct';
        $errorTypeId = ($isCorrect || !$errorTypeId) ? null : (int) $errorTypeId;

        if (!$isCorrect && !$errorTypeId) {
            flash('Для некорректной анкеты укажите тип ошибки.', 'error');
            $this->redirect('/inspect/queue?date=' . urlencode($this->workDateOf($inspection)));
        }

        // Что было до сохранения — чтобы не слать повторное уведомление при идентичной правке.
        $wasError    = $inspection['is_correct'] !== null && (int) $inspection['is_correct'] === 0;
        $oldType     = $inspection['error_type_id'] !== null ? (int) $inspection['error_type_id'] : null;
        $oldComment  = (string) ($inspection['controller_comment'] ?? '');

        PenaltyService::applyReview($inspection, $isCorrect, $errorTypeId, (int) Auth::id(), $comment);

        // Уведомить проверявшего сразу при фиксации ошибки (новой или уточнённой).
        if (!$isCorrect) {
            $changed = !$wasError || $oldType !== $errorTypeId || $oldComment !== $comment;
            if ($changed) {
                NotificationService::notifyInspectionError((int) $inspection['id'], $this->workDateOf($inspection));
            }
        }

        $this->redirect('/inspect/queue?date=' . urlencode($this->workDateOf($inspection)));
    }

    public function finish(): void
    {
        Auth::requireRole('controller', 'admin', 'manager');
        Auth::verifyCsrf();

        $date = $this->input('date');
        $batch = Database::one('SELECT * FROM sample_batches WHERE work_date = ?', [$date]);
        if (!$batch) {
            $this->redirect('/inspect');
        }

        $pending = (int) Database::scalar(
            'SELECT COUNT(*) FROM inspections WHERE batch_id = ? AND is_correct IS NULL',
            [$batch['id']]
        );
        if ($pending > 0) {
            flash("Остались непроверенные анкеты: {$pending}. Завершить нельзя.", 'error');
            $this->redirect('/inspect/queue?date=' . urlencode($date));
        }

        Database::run(
            'UPDATE sample_batches SET finished_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $batch['id']]
        );
        NotificationService::notifyBatch((int) $batch['id']);

        flash('Проверка завершена, уведомления разосланы сотрудникам.');
        $this->redirect('/inspect');
    }

    private function workDateOf(array $inspection): string
    {
        return (string) Database::scalar("SELECT substr(checked_at,1,10) FROM assignment_items WHERE id = ?", [$inspection['dossier_id']]);
    }
}
