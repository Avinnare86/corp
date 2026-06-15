<?php
namespace App\Services;

use App\Core\Database;

class NotificationService
{
    public static function create(int $employeeId, string $title, string $body): void
    {
        Database::insert(
            'INSERT INTO notifications (employee_id, title, body) VALUES (?,?,?)',
            [$employeeId, $title, $body]
        );
        // дублирование на email (если включён SMTP и у пользователя задан адрес)
        Mailer::toUser($employeeId, $title, $body);
    }

    /**
     * После завершения проверки разослать уведомления всем проверенным сотрудникам
     * со списком косяков (повторы помечаются и стоят дороже).
     */
    public static function notifyBatch(int $batchId): void
    {
        $batch = Database::one('SELECT * FROM sample_batches WHERE id = ?', [$batchId]);
        if (!$batch) {
            return;
        }
        $workDate = $batch['work_date'];

        $employees = Database::all(
            'SELECT DISTINCT employee_id FROM inspections WHERE batch_id = ?',
            [$batchId]
        );

        foreach ($employees as $row) {
            $empId = (int) $row['employee_id'];
            $items = Database::all(
                "SELECT i.*, d.reg_number, et.name AS error_name
                   FROM inspections i
                   JOIN assignment_items d ON d.id = i.dossier_id
                   LEFT JOIN error_types et ON et.id = i.error_type_id
                  WHERE i.batch_id = ? AND i.employee_id = ?",
                [$batchId, $empId]
            );

            $errorItems = array_filter($items, fn($i) => (int) $i['is_correct'] === 0);
            $checkedCnt = count($items);

            if (empty($errorItems)) {
                $title = "Проверка за {$workDate}: ошибок не найдено";
                $body = "Проверено анкет: {$checkedCnt}. Ошибок не выявлено. Отличная работа!";
            } else {
                $totalPenalty = 0.0;
                $lines = [];
                foreach ($errorItems as $it) {
                    $totalPenalty += (float) $it['penalty_amount'];
                    $rep = (int) $it['occurrence'] > 1
                        ? " — ПОВТОР №{$it['occurrence']} (дороже)"
                        : '';
                    $lines[] = sprintf(
                        '• %s — %s: −%s ₽%s',
                        $it['reg_number'],
                        $it['error_name'] ?? 'ошибка',
                        number_format((float) $it['penalty_amount'], 2, ',', ' '),
                        $rep
                    );
                }
                $title = sprintf('Проверка за %s: найдено ошибок — %d', $workDate, count($errorItems));
                $body = "Проверено анкет: {$checkedCnt}.\n"
                    . "Выявленные косяки:\n" . implode("\n", $lines) . "\n\n"
                    . 'Итого снижение: −' . number_format($totalPenalty, 2, ',', ' ') . ' ₽.';
            }

            self::create($empId, $title, $body);
        }
    }

    public static function unreadCount(int $employeeId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE employee_id = ? AND is_read = 0',
            [$employeeId]
        );
    }
}
