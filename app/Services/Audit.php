<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Auth;

class Audit
{
    /** Карта маршрутов → понятное название действия. */
    private static array $labels = [
        '#^/logout$#'                       => 'Выход',
        '#^/manager/upload$#'               => 'Загрузка списка',
        '#^/manager/move$#'                 => 'Перераспределение досье',
        '#^/manager/distribute$#'           => 'Распределение досье',
        '#^/manager/recall$#'               => 'Возврат досье в пул',
        '#^/dossiers/bulk$#'                => 'Массовая отметка досье',
        '#^/dossiers/\d+/check$#'           => 'Отметка досье проверенным',
        '#^/dossiers/\d+/recomment$#'       => 'Изменение причин доработки',
        '#^/dossiers/\d+/uncheck$#'         => 'Возврат досье в работу',
        '#^/piecework$#'                    => 'Ввод операций (визы и пр.)',
        '#^/piecework/\d+/delete$#'         => 'Удаление операции',
        '#^/day/open$#'                     => 'Приступил к работе',
        '#^/day/close$#'                    => 'Завершил работу',
        '#^/inspect/generate$#'             => 'Формирование выборки на проверку',
        '#^/inspect/\d+/review$#'           => 'Проверка анкеты контролёром',
        '#^/inspect/finish$#'               => 'Завершение проверки',
        '#^/admin/employees$#'              => 'Добавление сотрудника',
        '#^/admin/employees/\d+/update$#'   => 'Изменение сотрудника',
        '#^/admin/employees/\d+/delete$#'   => 'Удаление сотрудника',
        '#^/admin/positions$#'              => 'Должность: сохранение',
        '#^/admin/positions/\d+/delete$#'   => 'Удаление должности',
        '#^/admin/countries$#'              => 'Страна: сохранение',
        '#^/admin/countries/[^/]+/delete$#' => 'Удаление страны',
        '#^/admin/pricing$#'                => 'Сохранение тарифов',
        '#^/admin/operations$#'             => 'Операция: сохранение',
        '#^/admin/operations/\d+/delete$#'  => 'Удаление операции',
        '#^/admin/extras$#'                 => 'Доплата: добавление',
        '#^/admin/extras/\d+/delete$#'      => 'Удаление доплаты',
        '#^/admin/timesheet$#'              => 'Сохранение табеля',
        '#^/admin/settings$#'               => 'Сохранение настроек',
        '#^/orders$#'                       => 'Поручение: создано',
        '#^/orders/\d+/action$#'            => 'Поручение: действие',
        '#^/vacations$#'                    => 'Отпуск: заявка',
        '#^/vacations/\d+/decide$#'         => 'Отпуск: решение',
        '#^/vacations/\d+/send-notice$#'    => 'Отпуск: эл. уведомление',
        '#^/vacations/blackout$#'           => 'Отпуска: запретная зона',
        '#^/vacations/blackout/\d+/delete$#'=> 'Отпуска: удаление зоны',
        '#^/budget/save$#'                  => 'Бюджет ФОТ: сохранение',
        '#^/budget/source#'                 => 'Бюджет: источник выплат',
        '#^/timesheet2/create$#'            => 'Эл. табель: формирование',
        '#^/timesheet2/\d+/save$#'          => 'Эл. табель: сохранение отметок',
        '#^/timesheet2/\d+/sign$#'          => 'Эл. табель: ПОДПИСАНИЕ ЭП',
        '#^/timesheet2/\d+/delete$#'        => 'Эл. табель: удаление черновика',
        '#^/admin/org/access$#'             => 'Доступы: проекты/роли сотрудника',
        '#^/admin/org/cert#'                => 'Сертификат ЭП',
        '#^/docs$#'                         => 'СЭД: создание документа',
        '#^/docs/\d+/update$#'              => 'СЭД: изменение документа',
        '#^/docs/\d+/send$#'                => 'СЭД: отправка на согласование',
        '#^/docs/\d+/decide$#'              => 'СЭД: виза (согласовано/отклонено)',
        '#^/docs/\d+/delete$#'              => 'СЭД: удаление черновика',
        '#^/docs/\d+/recall$#'              => 'СЭД: отзыв с маршрута',
        '#^/docs/\d+/order$#'               => 'СЭД: резолюция по документу',
        '#^/docs/templates#'                => 'СЭД: шаблон маршрута',
        '#^/docs/\d+/redirect$#'            => 'СЭД: переадресация задачи',
        '#^/docs/\d+/readers$#'             => 'СЭД: читатели ДСП',
        '#^/docs/register$#'                => 'СЭД: ручная регистрация документа',
        '#^/docs/\d+/assign-reg$#'          => 'СЭД: регистрация / правка рег.№',
        '#^/docs/journals$#'                => 'СЭД: журнал регистрации',
        '#^/docs/journals/\d+/reserve$#'    => 'СЭД: бронь рег.№',
        '#^/docs/\d+/unregister$#'          => 'СЭД: снятие рег.№ (админ)',
        '#^/docs/\d+/unvisa$#'              => 'СЭД: отмена визы (админ)',
        '#^/visas/upload$#'                 => 'Визы: загрузка ходатайств',
        '#^/visas/ai-batch$#'               => 'Визы: ИИ-подстановка адресов',
        '#^/visas/move$#'                   => 'Визы: распределение строк',
        '#^/visas/save$#'                   => 'Визы: проверка пачки анкет',
        '#^/visas/batch/\d+/params$#'       => 'Визы: параметры бланка партии',
        '#^/visas/row/\d+/save$#'           => 'Визы: правка анкеты',
        '#^/visas/row/\d+/rework$#'         => 'Визы: возврат анкеты на доработку',
        '#^/visas/export$#'                 => 'Визы: выгрузка ходатайств (выборочно)',
        '#^/visas/opis/create$#'            => 'Визы: формирование описи',
        '#^/visas/opis/\d+/instruction$#'   => 'Визы: внесение визового указания',
        '#^/visas/opis/\d+/instruction-edit$#' => 'Визы: правка визового указания',
        '#^/visas/opis/\d+/refuse-row$#'    => 'Визы: удаление из указания → доработка',
        '#^/visas/opis/\d+/remove$#'        => 'Визы: удаление строки из описи',
        '#^/visas/opis/\d+/delete$#'        => 'Визы: удаление описи',
        '#^/visas/rework/move$#'            => 'Визы: распределение на доработке (МИД)',
        '#^/admin/data/[^/]+/\d+/delete$#'  => 'Админ: удаление записи',
        '#^/admin/data/[^/]+/\d+/revert$#'  => 'Админ: откат статуса записи',
        '#^/appeals$#'                      => 'Обращение: регистрация',
        '#^/appeals/\d+/action$#'           => 'Обращение: действие',
        '#^/admin/org/dept$#'               => 'Оргструктура: подразделение',
        '#^/admin/org/dept/\d+/delete$#'    => 'Оргструктура: удаление подразделения',
        '#^/admin/org/assign$#'             => 'Оргструктура: перемещение сотрудника',
        '#^/admin/org/type$#'               => 'СЭД: тип документа',
        '#^/admin/org/type/\d+/delete$#'    => 'СЭД: удаление типа документа',
        '#^/chat/direct$#'                  => 'Открытие личного чата',
        '#^/chat/group$#'                   => 'Создание группового чата',
        '#^/chat/\d+/send$#'                => 'Сообщение в чат',
        '#^/notifications/\d+/read$#'       => 'Прочтение уведомления',
    ];

    /** Записать произвольное действие. */
    public static function log(string $action, string $label, $details = null): void
    {
        try {
            $u = $_SESSION['user_id'] ?? null;
            Database::run(
                'INSERT INTO audit_log (user_id, user_name, role, action, label, details, ip) VALUES (?,?,?,?,?,?,?)',
                [
                    $u,
                    $_SESSION['name'] ?? '',
                    $_SESSION['role'] ?? '',
                    mb_substr($action, 0, 120),
                    mb_substr($label, 0, 200),
                    is_string($details) ? $details : ($details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null),
                    $_SERVER['REMOTE_ADDR'] ?? '',
                ]
            );
        } catch (\Throwable $e) {
            // журнал не должен ломать основную работу
        }
    }

    /** Автолог текущего POST-запроса (мутации). /login логируется отдельно. */
    public static function logRequest(string $path): void
    {
        $label = '';
        foreach (self::$labels as $rx => $name) {
            if (preg_match($rx, $path)) { $label = $name; break; }
        }
        if ($label === '') { $label = 'Действие: ' . $path; }

        // Параметры без чувствительных полей (пароли и API-ключи в журнал не пишутся).
        $params = $_POST;
        unset($params['_csrf'], $params['password'], $params['openrouter_key'], $params['smtp_pass']);
        if (!empty($_FILES['file']['name'])) { $params['_file'] = $_FILES['file']['name']; }
        // обрезать длинные значения
        array_walk_recursive($params, function (&$v) {
            if (is_string($v) && mb_strlen($v) > 200) { $v = mb_substr($v, 0, 200) . '…'; }
        });

        self::log('POST ' . $path, $label, $params ?: null);
    }
}
