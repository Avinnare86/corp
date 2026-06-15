<?php
/* Навигация по разделам оргструктуры. Ожидает $nav. Вкладки — по доступу роли. */
use App\Core\Auth;
$isHr = Auth::has('hr_manager', 'hr') || Auth::isAdmin();
$tabs = [];
if ($isHr) {
    $tabs['overview']    = ['/admin/org', 'Обзор'];
    $tabs['departments'] = ['/admin/org/departments', 'Подразделения'];
    $tabs['staff']       = ['/admin/org/staff', 'Сотрудники по отделам'];
    $tabs['roles']       = ['/admin/org/roles', 'Роли и доступы'];
}
if (Auth::isAdmin()) { $tabs['certs'] = ['/admin/org/certs', 'Сертификаты ЭП']; }
?>
<div class="org-nav">
    <?php foreach ($tabs as $key => [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= ($nav ?? '') === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
