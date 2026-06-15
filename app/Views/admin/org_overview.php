<h1>Оргструктура</h1>
<?php include __DIR__ . '/../partials/org_nav.php'; ?>

<?php
// строим дерево по parent_id (NULL = в подчинении директора)
$byParent = [];
foreach ($departments as $d) { $byParent[(int)$d['parent_id']][] = $d; }
$kindIcon = ['дирекция'=>'🏛','заместитель'=>'🧑‍💼','советник'=>'🎓','управление'=>'🏢','центр'=>'◉','отдел'=>'▸'];
$kindLabel = ['дирекция'=>'дирекция','заместитель'=>'зам. директора','советник'=>'советник','управление'=>'управление','центр'=>'центр','отдел'=>'отдел'];
$personKinds = \App\Controllers\OrgController::PERSON_KINDS;
function renderNode($d, $byParent, $kindIcon, $kindLabel, $personKinds, $depth) {
    $icon = $kindIcon[$d['kind']] ?? '▸';
    $isPerson = in_array($d['kind'] ?? '', $personKinds, true);
    echo '<div class="org-node" style="margin-left:' . ($depth * 26) . 'px">';
    echo '<span class="org-ic">' . $icon . '</span> ';
    if ($isPerson) {
        // должностной узел: показываем лицо как заголовок
        echo '<strong>' . e($d['head_name'] ?: $d['name']) . '</strong> <span class="muted org-kind">' . e($kindLabel[$d['kind']] ?? $d['kind']) . '</span>';
        if ($d['name'] && $d['head_name'] && $d['name'] !== $d['head_name']) { echo ' <span class="muted">(' . e($d['name']) . ')</span>'; }
    } else {
        echo '<strong>' . e($d['name']) . '</strong> <span class="muted org-kind">' . e($kindLabel[$d['kind']] ?? $d['kind']) . '</span>';
        if ($d['head_name']) { echo ' · рук.: ' . e($d['head_name']); }
        if ($d['curator_name']) { echo ' · куратор: <span class="muted">' . e($d['curator_name']) . '</span>'; }
        echo ' <span class="muted">(' . (int)$d['members'] . ')</span>';
    }
    echo '</div>';
    foreach ($byParent[(int)$d['id']] ?? [] as $child) { renderNode($child, $byParent, $kindIcon, $kindLabel, $personKinds, $depth + 1); }
}
?>
<section class="panel">
    <h2>Дерево подчинённости</h2>
    <p class="muted">Узлы верхнего уровня — в подчинении директора. В структуру входят и должностные лица:
        🧑‍💼 заместители директора и 🎓 советники — им можно подчинять управления/центры/отделы.
        Настроить связи: <a href="/admin/org/departments">Подразделения</a>.</p>
    <div class="org-tree">
        <div class="org-node org-root"><span class="org-ic">👤</span> <strong>Директор</strong></div>
        <?php foreach ($byParent[0] ?? [] as $top): renderNode($top, $byParent, $kindIcon, $kindLabel, $personKinds, 1); endforeach; ?>
        <?php if (empty($departments)): ?><p class="muted">Подразделений пока нет.</p><?php endif; ?>
    </div>
</section>

<section class="panel">
    <h2>Разделы</h2>
    <div class="org-cards">
        <a class="org-card" href="/admin/org/departments"><strong>Подразделения</strong><span class="muted">создание, вид узла, подчинённость (родитель), начальник, куратор-зам</span></a>
        <a class="org-card" href="/admin/org/staff"><strong>Сотрудники по отделам</strong><span class="muted">простое распределение сотрудников по подразделениям</span></a>
        <a class="org-card" href="/admin/org/roles"><strong>Роли и доступы</strong><span class="muted">набор ролей, проекты-меню, замещение</span></a>
        <a class="org-card" href="/admin/org/certs"><strong>Сертификаты ЭП</strong><span class="muted">регистрация УНЭП/УКЭП</span></a>
        <a class="org-card" href="/admin/org/types"><strong>Типы документов</strong><span class="muted">справочник типов СЭД и нумераторы</span></a>
    </div>
</section>
