<?php
/**
 * Таблица маршрутов. Подключается из public/index.php, где определён $router.
 * Структура: аутентификация → кабинет специалиста → менеджер → чат/виджет → контролёр → админка.
 */

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DossierController;
use App\Controllers\RatingController;
use App\Controllers\NotificationController;
use App\Controllers\AdminController;
use App\Controllers\InspectionController;
use App\Controllers\PieceworkController;
use App\Controllers\AttendanceController;
use App\Controllers\ChatController;
use App\Controllers\ManagerController;
use App\Controllers\AuditController;
use App\Controllers\DocumentController;
use App\Controllers\OrgController;
use App\Controllers\OrderController;
use App\Controllers\VacationController;
use App\Controllers\BudgetController;
use App\Controllers\TabelController;

// --- Аутентификация ---
$router->get('/login',  [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);
$router->get('/',       [DashboardController::class, 'index']);

// --- Личный кабинет специалиста ---
$router->get('/dashboard',                [DashboardController::class, 'index']);
$router->get('/payroll',                  [DashboardController::class, 'payroll']);
$router->get('/desk',                     [\App\Controllers\DeskController::class, 'index']);
$router->get('/dossiers',                 [DossierController::class, 'index']);
$router->get('/dossiers/checked',         [DossierController::class, 'checked']);
$router->post('/dossiers/bulk',           [DossierController::class, 'bulk']);
$router->post('/dossiers/{id}/check',     [DossierController::class, 'check']);
$router->post('/dossiers/{id}/recomment', [DossierController::class, 'recomment']);
$router->post('/dossiers/{id}/uncheck',   [DossierController::class, 'uncheck']);
$router->post('/day/open',                [AttendanceController::class, 'open']);
$router->post('/day/close',               [AttendanceController::class, 'close']);
$router->get('/piecework',                [PieceworkController::class, 'index']);
$router->post('/piecework',               [PieceworkController::class, 'store']);
$router->post('/piecework/{id}/delete',   [PieceworkController::class, 'destroy']);
$router->get('/rating',                   [RatingController::class, 'index']);
// Норматив проверки анкет + недельная выработка
$router->get('/norm',                     [\App\Controllers\NormController::class, 'mine']);
$router->get('/norm/report',              [\App\Controllers\NormController::class, 'report']);
$router->post('/norm/set',                [\App\Controllers\NormController::class, 'set']);
$router->get('/notifications',            [NotificationController::class, 'index']);
$router->post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

// --- Менеджер проекта ---
$router->get('/manager',                  [ManagerController::class, 'index']);
$router->get('/manager/report',           [ManagerController::class, 'report']);
$router->get('/manager/report/export',    [ManagerController::class, 'reportExport']);
$router->post('/manager/upload',          [ManagerController::class, 'upload']);
$router->post('/manager/manual',           [ManagerController::class, 'manualAdd']);
$router->get('/manager/items',            [ManagerController::class, 'items']);
$router->post('/manager/move',            [ManagerController::class, 'move']);
$router->post('/manager/distribute',      [ManagerController::class, 'distribute']);
$router->post('/manager/recall',          [ManagerController::class, 'recall']);

// --- Журнал действий ---
$router->get('/audit',                    [AuditController::class, 'index']);
$router->get('/audit/export',             [AuditController::class, 'export']);

// --- Чат и виджет ---
$router->get('/chat',                     [ChatController::class, 'index']);
$router->get('/chat/contacts',            [ChatController::class, 'contacts']);
$router->post('/chat/direct',             [ChatController::class, 'direct']);
$router->post('/chat/group',              [ChatController::class, 'createGroup']);
$router->get('/chat/file/{id}',           [ChatController::class, 'file']);
$router->get('/chat/{id}/messages',       [ChatController::class, 'messages']);
$router->post('/chat/{id}/send',          [ChatController::class, 'send']);
$router->get('/chat/{id}',                [ChatController::class, 'show']);
$router->get('/widget/state',             [ChatController::class, 'widgetState']);

// --- СЭД: документы ---
$router->get('/docs',               [DocumentController::class, 'index']);
$router->get('/docs/create',        [DocumentController::class, 'create']);
$router->get('/docs/deputies',      [DocumentController::class, 'deputies']);
$router->post('/docs/deputies',     [DocumentController::class, 'saveDeputies']);
$router->get('/docs/templates',     [DocumentController::class, 'templates']);
$router->post('/docs/templates',    [DocumentController::class, 'storeTemplate']);
$router->post('/docs/templates/{id}/delete', [DocumentController::class, 'deleteTemplate']);
$router->post('/docs',              [DocumentController::class, 'store']);
$router->post('/docs/{id}/recall',  [DocumentController::class, 'recall']);
$router->post('/docs/{id}/order',   [DocumentController::class, 'order']);
$router->post('/docs/{id}/redirect',[DocumentController::class, 'redirectTask']);
$router->post('/docs/{id}/readers', [DocumentController::class, 'readers']);
// Регистрация (МосЭДО): ручная регистрация, журналы, бронь; админ-откат действий
$router->get('/docs/register/new',  [DocumentController::class, 'registerForm']);
$router->post('/docs/register',     [DocumentController::class, 'registerStore']);
$router->get('/docs/journals',      [DocumentController::class, 'journals']);
$router->post('/docs/journals',     [DocumentController::class, 'storeJournal']);
$router->post('/docs/journals/{id}/reserve', [DocumentController::class, 'reserve']);
$router->post('/docs/{id}/assign-reg', [DocumentController::class, 'assignReg']);
$router->post('/docs/{id}/unregister', [DocumentController::class, 'unregister']);
$router->post('/docs/{id}/unvisa',  [DocumentController::class, 'unvisa']);

// --- Визы: ходатайства ---
$router->get('/visas',                  [\App\Controllers\VisaController::class, 'grid']);
$router->post('/visas/save',            [\App\Controllers\VisaController::class, 'saveGrid']);
$router->get('/visas/manage',           [\App\Controllers\VisaController::class, 'manage']);
$router->post('/visas/upload',          [\App\Controllers\VisaController::class, 'upload']);
$router->post('/visas/ai-batch',        [\App\Controllers\VisaController::class, 'aiBatch']);
$router->get('/visas/items',            [\App\Controllers\VisaController::class, 'items']);
$router->post('/visas/move',            [\App\Controllers\VisaController::class, 'move']);
$router->get('/visas/done',             [\App\Controllers\VisaController::class, 'done']);
$router->get('/visas/row/{id}',         [\App\Controllers\VisaController::class, 'row']);
$router->post('/visas/row/{id}/save',   [\App\Controllers\VisaController::class, 'rowSave']);
$router->post('/visas/row/{id}/rework', [\App\Controllers\VisaController::class, 'rowRework']);
$router->post('/visas/export',          [\App\Controllers\VisaController::class, 'export']);
$router->get('/visas/batch/{id}/rows',  [\App\Controllers\VisaController::class, 'batchRows']);
$router->post('/visas/defaults',        [\App\Controllers\VisaController::class, 'saveDefaults']);
$router->post('/visas/batch/{id}/params', [\App\Controllers\VisaController::class, 'saveParams']);
$router->get('/visas/report',           [\App\Controllers\VisaController::class, 'report']);
$router->get('/visas/report/status',     [\App\Controllers\VisaController::class, 'statusReport']);
$router->get('/visas/report/status/export', [\App\Controllers\VisaController::class, 'statusReportExport']);
$router->get('/visas/rating',           [\App\Controllers\VisaController::class, 'rating']);
$router->get('/visas/batch/{id}/zip',   [\App\Controllers\VisaController::class, 'exportZip']);
$router->get('/visas/batch/{id}/pdf',   [\App\Controllers\VisaController::class, 'exportPdf']);
// Описи / гарантийные письма по странам (кросс-партийно, по готовым анкетам)
$router->get('/visas/opis',                  [\App\Controllers\VisaOpisController::class, 'board']);
$router->get('/visas/opis/items',            [\App\Controllers\VisaOpisController::class, 'items']);
$router->post('/visas/opis/create',          [\App\Controllers\VisaOpisController::class, 'create']);
$router->get('/visas/opis/list',             [\App\Controllers\VisaOpisController::class, 'index']);
$router->get('/visas/opis/{id}',             [\App\Controllers\VisaOpisController::class, 'show']);
$router->get('/visas/opis/{id}/docs',        [\App\Controllers\VisaOpisController::class, 'docs']);
$router->post('/visas/opis/{id}/instruction',[\App\Controllers\VisaOpisController::class, 'instruction']);
$router->post('/visas/opis/{id}/instruction-edit',[\App\Controllers\VisaOpisController::class, 'editInstruction']);
$router->post('/visas/opis/{id}/refuse-row', [\App\Controllers\VisaOpisController::class, 'refuseFromInstructed']);
$router->post('/visas/opis/{id}/remove',     [\App\Controllers\VisaOpisController::class, 'removeRow']);
$router->post('/visas/opis/{id}/delete',     [\App\Controllers\VisaOpisController::class, 'destroy']);
// МИД: доработка после отказа
$router->get('/visas/rework',                [\App\Controllers\VisaOpisController::class, 'reworkBoard']);
$router->get('/visas/rework/items',          [\App\Controllers\VisaOpisController::class, 'reworkItems']);
$router->post('/visas/rework/move',          [\App\Controllers\VisaOpisController::class, 'reworkMove']);

// --- Обращения граждан (59-ФЗ) ---
$router->get('/appeals',             [\App\Controllers\AppealController::class, 'index']);
$router->post('/appeals',            [\App\Controllers\AppealController::class, 'store']);
$router->get('/appeals/{id}',        [\App\Controllers\AppealController::class, 'show']);
$router->post('/appeals/{id}/action',[\App\Controllers\AppealController::class, 'action']);
$router->get('/docs/{id}/edit',     [DocumentController::class, 'edit']);
$router->post('/docs/{id}/update',  [DocumentController::class, 'update']);
$router->post('/docs/{id}/send',    [DocumentController::class, 'send']);
$router->post('/docs/{id}/decide',  [DocumentController::class, 'decide']);
$router->post('/docs/{id}/delete',  [DocumentController::class, 'destroy']);
$router->get('/docs/{id}/file',     [DocumentController::class, 'file']);
$router->get('/docs/{id}/preview',  [DocumentController::class, 'preview']);
$router->post('/docs/{id}/control', [DocumentController::class, 'control']);
$router->post('/docs/{id}/file-case', [DocumentController::class, 'fileCase']);
$router->get('/docs/register',       [DocumentController::class, 'register']);
$router->get('/docs/{id}/card',      [DocumentController::class, 'card']);
// Номенклатура дел / архив
$router->get('/nomenclature',            [\App\Controllers\NomenclatureController::class, 'index']);
$router->get('/nomenclature/archive',    [\App\Controllers\NomenclatureController::class, 'archiveList']);
$router->post('/nomenclature',           [\App\Controllers\NomenclatureController::class, 'store']);
$router->get('/nomenclature/{id}',       [\App\Controllers\NomenclatureController::class, 'show']);
$router->post('/nomenclature/{id}/close',[\App\Controllers\NomenclatureController::class, 'close']);
$router->post('/nomenclature/{id}/archive',[\App\Controllers\NomenclatureController::class, 'archive']);
$router->get('/docs/{id}/sheet',    [DocumentController::class, 'sheet']);
$router->get('/docs/{id}',          [DocumentController::class, 'show']);

// --- СЭД: оргструктура и типы документов ---
// Моя ЭП — самообслуживание для всех сотрудников (ПЭП авто, загрузка УНЭП/УКЭП)
$router->get('/certs',                       [OrgController::class, 'myCerts']);
$router->post('/certs',                      [OrgController::class, 'storeMyCert']);
$router->post('/certs/{id}/delete',          [OrgController::class, 'deleteMyCert']);

$router->get('/admin/org',                  [OrgController::class, 'index']);
$router->get('/admin/org/departments',      [OrgController::class, 'departments']);
$router->get('/admin/org/staff',            [OrgController::class, 'staff']);
$router->get('/admin/org/roles',            [OrgController::class, 'rolesPage']);
$router->get('/admin/org/certs',            [OrgController::class, 'certsPage']);
$router->get('/admin/org/types',            [OrgController::class, 'types']);
$router->post('/admin/org/type',            [OrgController::class, 'storeType']);
$router->post('/admin/org/type/{id}/delete',[OrgController::class, 'deleteType']);
$router->post('/admin/org/dept',            [OrgController::class, 'storeDept']);
$router->post('/admin/org/dept/{id}/delete',[OrgController::class, 'deleteDept']);
$router->post('/admin/org/assign',          [OrgController::class, 'assign']);
$router->post('/admin/org/access',          [OrgController::class, 'saveAccess']);
$router->post('/admin/org/curator',         [OrgController::class, 'saveCurator']);
$router->post('/admin/org/transfer',        [OrgController::class, 'transfer']);
$router->post('/admin/org/cert',            [OrgController::class, 'storeCert']);
$router->post('/admin/org/cert/{id}/delete',[OrgController::class, 'deleteCert']);

// --- Служебки о стимуле ---
$router->get('/memos',              [\App\Controllers\StimulusController::class, 'index']);
$router->get('/memos/new',          [\App\Controllers\StimulusController::class, 'create']);
$router->get('/memos/mgmt/new',     [\App\Controllers\StimulusController::class, 'createMgmt']);
$router->get('/memos/direct/new',   [\App\Controllers\StimulusController::class, 'createDirect']);
$router->get('/memos/summary',      [\App\Controllers\StimulusController::class, 'summary']);
$router->get('/memos/summary/export',[\App\Controllers\StimulusController::class, 'summaryExport']);
$router->get('/memos/coverage',     [\App\Controllers\StimulusController::class, 'coverage']);
$router->get('/memos/coverage/export',[\App\Controllers\StimulusController::class, 'coverageExport']);
$router->get('/memos/print-report', [\App\Controllers\StimulusController::class, 'printReport']);
$router->get('/memos/print-batch',  [\App\Controllers\StimulusController::class, 'printBatch']);
$router->post('/memos/line/{id}/override', [\App\Controllers\StimulusController::class, 'override']);
$router->post('/memos',             [\App\Controllers\StimulusController::class, 'store']);
$router->get('/memos/{id}',         [\App\Controllers\StimulusController::class, 'show']);
$router->get('/memos/{id}/edit',    [\App\Controllers\StimulusController::class, 'edit']);
$router->get('/memos/{id}/print',   [\App\Controllers\StimulusController::class, 'printDoc']);
$router->post('/memos/{id}/sign',   [\App\Controllers\StimulusController::class, 'sign']);
$router->post('/memos/{id}/reject', [\App\Controllers\StimulusController::class, 'reject']);
$router->post('/memos/{id}/delete', [\App\Controllers\StimulusController::class, 'delete']);
$router->get('/admin/grounds',      [\App\Controllers\AdminController::class, 'grounds']);
$router->post('/admin/grounds',     [\App\Controllers\AdminController::class, 'storeGround']);
$router->post('/admin/grounds/{id}/delete', [\App\Controllers\AdminController::class, 'deleteGround']);

// --- Поручения ---
$router->get('/orders',              [OrderController::class, 'index']);
$router->get('/orders/report',       [OrderController::class, 'report']);
$router->post('/orders/remind',      [OrderController::class, 'remind']);
$router->post('/orders',             [OrderController::class, 'store']);
$router->post('/orders/{id}/action', [OrderController::class, 'action']);
$router->get('/orders/{id}',         [OrderController::class, 'show']);

// --- График отпусков ---
$router->get('/vacations',                 [VacationController::class, 'index']);
$router->post('/vacations',                [VacationController::class, 'store']);
$router->post('/vacations/{id}/decide',    [VacationController::class, 'decide']);
$router->get('/vacations/{id}/notice',     [VacationController::class, 'notice']);
$router->post('/vacations/{id}/send-notice', [VacationController::class, 'sendNotice']);
$router->post('/vacations/blackout',       [VacationController::class, 'storeBlackout']);
$router->post('/vacations/blackout/{id}/delete', [VacationController::class, 'deleteBlackout']);

// --- Бюджет ФОТ ---
$router->get('/budget',                  [BudgetController::class, 'index']);
$router->post('/budget/save',            [BudgetController::class, 'save']);
$router->post('/budget/source',          [BudgetController::class, 'storeSource']);
$router->post('/budget/source/{id}/delete', [BudgetController::class, 'deleteSource']);

// --- Электронный табель (полумесячный, с ЭП и ревизиями) ---
$router->get('/timesheet2',              [TabelController::class, 'index']);
$router->get('/timesheet2/coverage',     [TabelController::class, 'coverage']);
$router->post('/timesheet2/create',      [TabelController::class, 'create']);
$router->get('/timesheet2/{id}/edit',    [TabelController::class, 'edit']);
$router->post('/timesheet2/{id}/save',   [TabelController::class, 'save']);
$router->post('/timesheet2/{id}/sign',   [TabelController::class, 'sign']);
$router->get('/timesheet2/{id}/view',    [TabelController::class, 'viewSigned']);
$router->get('/timesheet2/{id}/export',  [TabelController::class, 'export']);
$router->post('/timesheet2/{id}/delete', [TabelController::class, 'destroy']);

// --- Меню контролёра ---
$router->get('/inspect',                  [InspectionController::class, 'index']);
$router->get('/inspect/queue',            [InspectionController::class, 'queue']);
$router->post('/inspect/generate',        [InspectionController::class, 'generate']);
$router->post('/inspect/{id}/review',     [InspectionController::class, 'review']);
$router->post('/inspect/finish',          [InspectionController::class, 'finish']);

// --- Админка ---
$router->get('/admin',                    [AdminController::class, 'index']);
$router->get('/admin/employees',          [AdminController::class, 'employees']);
$router->get('/admin/employees/{id}',     [AdminController::class, 'employeeCard']);
$router->post('/admin/employees',         [AdminController::class, 'storeEmployee']);
$router->post('/admin/employees/{id}/update', [AdminController::class, 'updateEmployee']);
$router->post('/admin/employees/{id}/delete', [AdminController::class, 'deleteEmployee']);
$router->post('/admin/employees/{id}/allowance', [AdminController::class, 'setAllowance']);
$router->post('/admin/employees/{id}/allowance-grant', [AdminController::class, 'allowanceGrant']);
$router->post('/admin/allowance-grants/{id}/cancel', [AdminController::class, 'cancelAllowanceGrant']);
$router->get('/admin/comments',           [AdminController::class, 'comments']);
$router->post('/admin/comments',          [AdminController::class, 'storeComment']);
$router->post('/admin/comments/{id}/delete', [AdminController::class, 'deleteComment']);
$router->post('/admin/comments/rename-category', [AdminController::class, 'renameCategory']);
$router->get('/admin/operations',         [AdminController::class, 'operations']);
$router->post('/admin/operations',        [AdminController::class, 'storeOperation']);
$router->post('/admin/operations/{id}/delete', [AdminController::class, 'deleteOperation']);
$router->get('/admin/extras',             [AdminController::class, 'extras']);
$router->post('/admin/extras',            [AdminController::class, 'storeExtra']);
$router->post('/admin/extras/{id}/delete',[AdminController::class, 'deleteExtra']);
$router->get('/admin/positions',          [AdminController::class, 'positions']);
$router->post('/admin/positions',         [AdminController::class, 'storePosition']);
$router->post('/admin/positions/{id}/delete', [AdminController::class, 'deletePosition']);
$router->get('/admin/countries',          [AdminController::class, 'countries']);
$router->post('/admin/countries',         [AdminController::class, 'storeCountry']);
$router->post('/admin/countries/move',    [AdminController::class, 'moveCountries']);
$router->post('/admin/countries/{code}/delete', [AdminController::class, 'deleteCountry']);
$router->get('/admin/pricing',            [AdminController::class, 'pricing']);
$router->post('/admin/pricing',           [AdminController::class, 'savePricing']);
$router->get('/admin/errors',             [AdminController::class, 'errorTypes']);
$router->post('/admin/errors',            [AdminController::class, 'storeErrorType']);
$router->post('/admin/errors/{id}/delete',[AdminController::class, 'deleteErrorType']);
$router->get('/admin/timesheet',          [AdminController::class, 'timesheet']);
$router->post('/admin/timesheet',         [AdminController::class, 'saveTimesheet']);
$router->get('/admin/settings',           [AdminController::class, 'settings']);
$router->post('/admin/settings',          [AdminController::class, 'saveSettings']);
// Управление данными: удаление/откат любых строк (только админ)
$router->get('/admin/data',                       [AdminController::class, 'dataManagement']);
$router->post('/admin/data/{entity}/{id}/delete', [AdminController::class, 'superDelete']);
$router->post('/admin/data/{entity}/{id}/revert', [AdminController::class, 'revertStatus']);
