<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Auth;

class Audit
{
    /** Карта маршрутов → понятное название действия. */
    private static array $labels = [
        '#^/logout$#'                       => 'Выход',
        '#^/password/change$#'              => 'Смена пароля',
        '#^/admin/login-as/\d+$#'           => 'Вход как сотрудник',
        '#^/admin/return$#'                 => 'Возврат к админу',
        '#^/acting/save$#'                  => 'И.о./ВРИО: назначение',
        '#^/acting/switch$#'                => 'И.о.: переключение режима',
        '#^/acting/\d+/cancel$#'            => 'И.о./ВРИО: отмена',
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
        '#^/admin/employees/import$#'       => 'Кадры: импорт сотрудников из штатки',
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
        '#^/timesheet2/\d+/regenerate$#'    => 'Сменный табель 0504421: пересформирование из графика',
        '#^/timesheet2/\d+/delete$#'        => 'Эл. табель: удаление черновика',
        '#^/shifts/save$#'                  => 'Сменный график (2/2): сохранение план/факт',
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
        '#^/visas/row/\d+/passport-rework$#'=> 'Визы: доработка (срок действия паспорта)',
        '#^/visas/export$#'                 => 'Визы: выгрузка ходатайств (выборочно)',
        '#^/visas/opis/create$#'            => 'Визы: формирование описи',
        '#^/visas/opis/\d+/instruction$#'   => 'Визы: внесение визового указания',
        '#^/visas/opis/\d+/instruction-edit$#' => 'Визы: правка визового указания',
        '#^/visas/opis/\d+/refuse-row$#'    => 'Визы: удаление из указания → доработка',
        '#^/visas/opis/\d+/remove$#'        => 'Визы: удаление строки из описи',
        '#^/visas/opis/\d+/delete$#'        => 'Визы: удаление описи',
        '#^/visas/rework/move$#'            => 'Визы: распределение на доработке (МИД)',
        '#^/visas/timesheet/save$#'         => 'Визы: акцепт работы специалиста (этапы 1/3)',
        '#^/visas/timesheet/revoke$#'       => 'Визы: снятие акцепта работы',
        '#^/admin/data/[^/]+/\d+/delete$#'  => 'Админ: удаление записи',
        '#^/admin/data/[^/]+/\d+/revert$#'  => 'Админ: откат статуса записи',
        '#^/admin/employees/\d+/allowance-grant$#' => 'Стимул: надбавка на период (служебки-проекты)',
        '#^/admin/allowance-grants/\d+/cancel$#'   => 'Стимул: отмена назначения надбавки',
        '#^/memos/reasons$#'                       => 'Стимул: справочник оснований (сохранение)',
        '#^/memos/reasons/\d+/delete$#'            => 'Стимул: справочник оснований (отключение)',
        '#^/memos/batch/\d+/sign$#'                => 'Стимул: пакетная подпись инициатором',
        '#^/memos/\d+/stamps$#'                    => 'Стимул: проставление штампов ЭП (админ, задним числом)',
        '#^/memos/\d+/stamps/clear$#'              => 'Стимул: очистка гибких штампов (админ)',
        '#^/memos/carry$#'                         => 'Стимул: перенос выплат с прошлого месяца',
        '#^/manager/arrival/line$#'                => 'Квота: справочник ЛП (сохранение)',
        '#^/manager/arrival/line/merge$#'          => 'Квота: объединение ЛП',
        '#^/manager/arrival/line/\d+/delete$#'     => 'Квота: ЛП — отключение',
        '#^/manager/arrival/detail$#'              => 'Квота: справочник ДЛП (сохранение)',
        '#^/manager/arrival/detail/merge$#'        => 'Квота: объединение ДЛП',
        '#^/manager/arrival/detail/\d+/delete$#'   => 'Квота: ДЛП — отключение',
        '#^/manager/arrival/assign$#'              => 'Квота: проставление линии прибытия',
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
        unset($params['_csrf'], $params['password'], $params['password_confirm'], $params['openrouter_key'], $params['smtp_pass']);
        if (!empty($_FILES['file']['name'])) { $params['_file'] = $_FILES['file']['name']; }
        // обрезать длинные значения
        array_walk_recursive($params, function (&$v) {
            if (is_string($v) && mb_strlen($v) > 200) { $v = mb_substr($v, 0, 200) . '…'; }
        });

        self::log('POST ' . $path, $label, $params ?: null);
    }
}
