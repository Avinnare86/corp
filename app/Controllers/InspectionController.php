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
            'pending'   => SamplingService::unsampledDates(),
        ]);
    }

    /** Сформировать выборку сразу по ВСЕМ непроверенным датам (где есть проверенные анкеты, но выборки нет). */
    public function generateAll(): void
    {
        Auth::requireRole('controller', 'admin', 'manager');
        Auth::verifyCsrf();
        $dates = SamplingService::unsampledDates();
        $created = 0; $picked = 0;
        foreach ($dates as $row) {
            $bid = SamplingService::generateForDate($row['d'], (int) Auth::id());
            $cnt = (int) Database::scalar('SELECT COUNT(*) FROM inspections WHERE batch_id = ?', [$bid]);
            if ($cnt > 0) { $created++; $picked += $cnt; }
        }
        if (!$created) {
            flash('Нет непроверенных дат для формирования выборки.', 'error');
        } else {
            flash("Сформировано выборок по датам: {$created}. Анкет на проверку — {$picked}.");
        }
        $this->redirect('/inspect');
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
        // Навигация по id выборки (даёт работу с ручными выборками); fallback — по дате (старые ссылки).
        $batchId = (int) $this->input('batch', 0);
        if ($batchId) {
            $batch = Database::one('SELECT * FROM sample_batches WHERE id = ?', [$batchId]);
        } else {
            $date = (string) $this->input('date', SamplingService::yesterday());
            $batch = Database::one('SELECT * FROM sample_batches WHERE work_date = ? AND COALESCE(is_manual,0) = 0', [$date]);
        }
        if (!$batch) {
            flash('Выборка не найдена.', 'error');
            $this->redirect('/inspect');
        }

        $items = Database::all(
            "SELECT i.*, d.reg_number, d.country_code, u.full_name AS employee_name,
                    et.name AS error_name, d.comment_text AS dorabotka,
                    al.code AS arrival_code, ad.text AS arrival_detail
               FROM inspections i
               JOIN assignment_items d ON d.id = i.dossier_id
               JOIN users u    ON u.id = i.employee_id
               LEFT JOIN error_types et ON et.id = i.error_type_id
               LEFT JOIN arrival_lines al ON al.id = d.arrival_line_id
               LEFT JOIN arrival_details ad ON ad.id = d.arrival_detail_id
              WHERE i.batch_id = ?
              ORDER BY u.full_name, i.id",
            [$batch['id']]
        );

        $errorTypes = Database::all('SELECT * FROM error_types WHERE is_active = 1 ORDER BY name');

        $this->view('inspect/queue', [
            'title'      => !empty($batch['is_manual'])
                ? ('Контроль: ' . ($batch['title'] ?: 'ручная выборка'))
                : ('Проверка анкет за ' . $batch['work_date']),
            'batch'      => $batch,
            'items'      => $items,
            'errorTypes' => $errorTypes,
            'date'       => (string) $batch['work_date'],
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
            $this->redirect('/inspect/queue?batch=' . (int) $inspection['batch_id']);
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

        $this->redirect('/inspect/queue?batch=' . (int) $inspection['batch_id']);
    }

    public function finish(): void
    {
        Auth::requireRole('controller', 'admin', 'manager');
        Auth::verifyCsrf();

        $batchId = (int) $this->input('batch', 0);
        $batch = $batchId
            ? Database::one('SELECT * FROM sample_batches WHERE id = ?', [$batchId])
            : Database::one('SELECT * FROM sample_batches WHERE work_date = ? AND COALESCE(is_manual,0) = 0', [(string) $this->input('date')]);
        if (!$batch) {
            $this->redirect('/inspect');
        }

        $pending = (int) Database::scalar(
            'SELECT COUNT(*) FROM inspections WHERE batch_id = ? AND is_correct IS NULL',
            [$batch['id']]
        );
        if ($pending > 0) {
            flash("Остались непроверенные анкеты: {$pending}. Завершить нельзя.", 'error');
            $this->redirect('/inspect/queue?batch=' . (int) $batch['id']);
        }

        Database::run(
            'UPDATE sample_batches SET finished_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $batch['id']]
        );
        NotificationService::notifyBatch((int) $batch['id']);

        flash('Проверка завершена, уведомления разосланы сотрудникам.');
        $this->redirect('/inspect');
    }

    /** Экран ручного формирования выборки: фильтр анкет (период/специалист/страна) + выбор галочками. */
    public function manualForm(): void
    {
        Auth::requireRole('controller', 'admin', 'manager');
        $from = (string) $this->input('from', '');
        $to = (string) $this->input('to', '');
        $emp = (int) $this->input('emp', 0);
        $country = (string) $this->input('country', '');
        if ($from === '' && $to === '' && !$emp && $country === '') { $from = date('Y-m-d', strtotime('-14 days')); }
        $this->view('inspect/manual', [
            'title'     => 'Ручная выборка на контроль',
            'cands'     => SamplingService::manualCandidates($from, $to, $emp ?: null, $country),
            'from'      => $from, 'to' => $to, 'emp' => $emp, 'country' => $country,
            'employees' => Database::all("SELECT u.id, u.full_name FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_slug='anketa_worker' WHERE u.is_active=1 ORDER BY u.full_name"),
            'countries' => Database::all("SELECT ai.country_code AS code, c.name FROM assignment_items ai LEFT JOIN countries c ON c.code=ai.country_code WHERE ai.country_code IS NOT NULL AND ai.country_code<>'' GROUP BY ai.country_code, c.name ORDER BY c.name, ai.country_code"),
            'csrf'      => Auth::csrf(),
        ]);
    }

    /** Создать ручную выборку из выбранных анкет и открыть её очередь контроля. */
    public function manualCreate(): void
    {
        Auth::requireRole('controller', 'admin', 'manager');
        Auth::verifyCsrf();
        $ids = array_map('intval', (array) ($_POST['pick'] ?? []));
        [$batchId, $added] = SamplingService::createManualBatch((int) Auth::id(), (string) $this->input('title', ''), $ids);
        if (!$batchId) {
            flash('Не выбрано ни одной анкеты (или все выбранные уже проходили контроль).', 'error');
            $this->redirect('/inspect/manual');
        }
        flash("Ручная выборка сформирована: анкет на проверку — {$added}.");
        $this->redirect('/inspect/queue?batch=' . (int) $batchId);
    }

    private function workDateOf(array $inspection): string
    {
        return (string) Database::scalar("SELECT substr(checked_at,1,10) FROM assignment_items WHERE id = ?", [$inspection['dossier_id']]);
    }
}
