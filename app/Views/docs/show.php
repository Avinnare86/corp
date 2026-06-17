<?php
use App\Controllers\DocumentController;
$statusClass = ['draft'=>'','on_approval'=>'st-wait','revision'=>'st-rev','approved'=>'st-ok'];
$canEdit = $isAuthor && in_array($doc['status'], ['draft','revision'], true);
// группировка маршрута по этапам
$stages = [];
foreach ($route as $r) { $stages[(int)$r['step_no']][] = $r; }
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/docs">← Документы</a>
    <h1 style="margin:0;font-size:1.2rem"><?= e($doc['title']) ?></h1>
    <span class="st <?= $statusClass[$doc['status']] ?? '' ?>"><?= e(DocumentController::STATUS_LABEL[$doc['status']] ?? $doc['status']) ?></span>
    <?php if ($doc['grif'] === 'ДСП'): ?><span class="st st-rev">ДСП</span><?php endif; ?>
</div>

<div class="doc-grid">
<div>
    <section class="panel">
        <h2>Реквизиты</h2>
        <table class="table">
            <tr><td class="muted" style="width:160px">Рег. номер</td><td><strong class="mono"><?= e($doc['reg_number'] ?: '— (после подписания)') ?></strong></td></tr>
            <tr><td class="muted">Тип</td><td><?= e($doc['type_name']) ?></td></tr>
            <?php $dirLabel=['incoming'=>'Входящий','outgoing'=>'Исходящий','internal'=>'Внутренний']; if (($doc['direction']??'internal')!=='internal'): ?>
                <tr><td class="muted">Направление</td><td><span class="tag"><?= e($dirLabel[$doc['direction']] ?? $doc['direction']) ?></span></td></tr>
                <?php if (!empty($doc['correspondent_name'])): ?>
                    <tr><td class="muted"><?= $doc['direction']==='incoming'?'От кого':'Кому' ?></td><td><?= e($doc['correspondent_name']) ?></td></tr>
                <?php endif; ?>
                <?php if ($doc['direction']==='incoming' && (!empty($doc['incoming_number']) || !empty($doc['incoming_date']))): ?>
                    <tr><td class="muted">Исх. корреспондента</td><td><?= e($doc['incoming_number']) ?><?= $doc['incoming_date']?' от '.e($doc['incoming_date']):'' ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($doc['delivery'])): ?><tr><td class="muted">Доставка</td><td><?= e($doc['delivery']) ?></td></tr><?php endif; ?>
            <?php endif; ?>
            <tr><td class="muted">Автор</td><td><?= e($doc['author_name']) ?> <span class="muted"><?= e($doc['author_position']) ?></span></td></tr>
            <tr><td class="muted">Подразделение</td><td><?= e($doc['dept_name'] ?? '—') ?></td></tr>
            <?php if ($doc['reply_to_id']): ?>
                <tr><td class="muted">Ответ на</td><td><a href="/docs/<?= (int)$doc['reply_to_id'] ?>"><?= e(($doc['reply_reg'] ? '№' . $doc['reply_reg'] . ' ' : '') . $doc['reply_title']) ?></a></td></tr>
            <?php endif; ?>
            <?php if ($replies): ?>
                <tr><td class="muted">Ответы</td><td><?php foreach ($replies as $rp): ?><a href="/docs/<?= (int)$rp['id'] ?>"><?= e(($rp['reg_number'] ? '№' . $rp['reg_number'] : '#' . $rp['id'])) ?></a> <?php endforeach; ?></td></tr>
            <?php endif; ?>
            <tr><td class="muted">Создан</td><td><?= e(substr((string)$doc['created_at'],0,16)) ?></td></tr>
            <?php if ($files): ?>
                <tr><td class="muted">Вложение</td><td>
                    <?php foreach ($files as $i => $f): ?>
                        <div><a href="/docs/<?= (int)$doc['id'] ?>/file?v=<?= (int)$f['version'] ?>">📎 <?= e($f['orig_name']) ?></a>
                            <span class="tag <?= $i===0?'ok':'' ?>">v<?= (int)$f['version'] ?><?= $i===0?' — текущая':'' ?></span>
                            <span class="muted" style="font-size:.76rem"><?= e(substr((string)$f['uploaded_at'],0,16)) ?></span></div>
                    <?php endforeach; ?>
                </td></tr>
            <?php endif; ?>
        </table>
        <?php if (trim((string)$doc['body']) !== ''): ?>
            <h3 class="sub">Текст документа</h3>
            <div class="doc-body"><?= nl2br(e($doc['body'])) ?></div>
        <?php endif; ?>

        <?php if ($doc['grif']==='ДСП' && ($canManageReaders || $readers)): ?>
            <h3 class="sub">Читатели (доступ к ДСП)</h3>
            <?php foreach ($readers as $rd): ?>
                <span class="tag"><?= e($rd['full_name']) ?>
                <?php if ($canManageReaders): ?>
                    <form method="post" action="/docs/<?= (int)$doc['id'] ?>/readers" class="inline"><?= csrf_field() ?>
                        <input type="hidden" name="remove" value="<?= (int)$rd['user_id'] ?>">
                        <button style="border:none;background:none;cursor:pointer;color:inherit">×</button></form>
                <?php endif; ?></span>
            <?php endforeach; ?>
            <?php if (!$readers): ?><span class="muted">нет</span><?php endif; ?>
            <?php if ($canManageReaders): ?>
            <form method="post" action="/docs/<?= (int)$doc['id'] ?>/readers" class="form-inline" style="margin-top:8px">
                <?= csrf_field() ?>
                <label>Добавить читателя
                    <select name="user_id"><?php foreach ($allUsers as $u3): ?><option value="<?= (int)$u3['id'] ?>"><?= e($u3['full_name']) ?></option><?php endforeach; ?></select>
                </label>
                <button class="btn btn-mini">+ Дать доступ</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>

        <div class="form-inline" style="margin-top:14px">
            <?php if ($canEdit): ?>
                <a class="btn btn-primary" href="/docs/<?= (int)$doc['id'] ?>/edit">✎ Редактировать<?= $doc['status']==='revision' ? ' и отправить заново' : '' ?></a>
                <?php if ($doc['status']==='draft'): ?>
                <form method="post" action="/docs/<?= (int)$doc['id'] ?>/delete" onsubmit="return confirm('Удалить черновик?')" class="inline">
                    <?= csrf_field() ?><button class="btn btn-danger">Удалить</button></form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($isAuthor && $doc['status']==='on_approval'): ?>
                <form method="post" action="/docs/<?= (int)$doc['id'] ?>/recall" onsubmit="return confirm('Отозвать документ с маршрута на доработку?')" class="inline">
                    <?= csrf_field() ?><button class="btn">↩ Отозвать с маршрута</button></form>
            <?php endif; ?>
            <a class="btn" href="/docs/<?= (int)$doc['id'] ?>/sheet">🖨 Лист согласования</a>
            <a class="btn" href="/docs/<?= (int)$doc['id'] ?>/card" target="_blank">🪪 Рег. карточка (штрихкод)</a>
        </div>

        <?php $canControl = $isBoss || $isAuthor; if ($canControl || (int)($doc['on_control']??0)): ?>
        <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--line)">
            <?php if ((int)($doc['on_control']??0)): $overC = !empty($doc['control_due']) && $doc['control_due'] < date('Y-m-d'); ?>
                <span class="tag" style="background:#fff3d6;color:#8a5a00">🔍 На контроле</span>
                <?php if (!empty($doc['control_due'])): ?><span class="<?= $overC?'minus':'muted' ?>">срок: <?= e($doc['control_due']) ?><?= $overC?' ⚠ просрочено':'' ?></span><?php endif; ?>
                <?php if ($canControl): ?>
                    <form method="post" action="/docs/<?= (int)$doc['id'] ?>/control" class="inline" style="margin-left:8px"><?= csrf_field() ?>
                        <input type="hidden" name="off" value="1"><button class="btn btn-mini">Снять с контроля</button></form>
                <?php endif; ?>
            <?php elseif ($canControl): ?>
                <form method="post" action="/docs/<?= (int)$doc['id'] ?>/control" class="row-form inline"><?= csrf_field() ?>
                    Контрольный срок <input type="date" name="control_due">
                    <button class="btn btn-mini">🔍 Поставить на контроль</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($canFile) || !empty($caseInfo)): ?>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
            <?php if (!empty($caseInfo)): ?>
                <span class="tag">📁 в деле <?= e($caseInfo['index_code']) ?> «<?= e($caseInfo['title']) ?>»</span>
                <?php if (!empty($canFile)): ?>
                    <form method="post" action="/docs/<?= (int)$doc['id'] ?>/file-case" class="inline" style="margin-left:8px"><?= csrf_field() ?>
                        <input type="hidden" name="unfile" value="1"><button class="btn btn-mini">Изъять из дела</button></form>
                <?php endif; ?>
            <?php elseif (!empty($canFile)): ?>
                <form method="post" action="/docs/<?= (int)$doc['id'] ?>/file-case" class="row-form inline"><?= csrf_field() ?>
                    Списать в дело
                    <select name="case_id">
                        <option value="">— дело —</option>
                        <?php foreach ($cases as $cs): ?><option value="<?= (int)$cs['id'] ?>"><?= e($cs['index_code'].' '.$cs['title']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-mini">📁 В дело</button>
                    <?php if (!$cases): ?><span class="muted">— сначала заведите дела в <a href="/nomenclature">номенклатуре</a></span><?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($isBoss || $docOrders): ?>
    <section class="panel">
        <h2>Поручения по документу</h2>
        <?php foreach ($docOrders as $o): ?>
            <div class="hist-row">
                <span class="st <?= in_array($o['status'],['done'],true)?'st-ok':($o['status']==='canceled'?'':'st-wait') ?>"><?= e(\App\Controllers\OrderController::STATUS[$o['status']] ?? $o['status']) ?></span>
                <span><strong><?= e($o['title']) ?></strong> → <?= e($o['assignee_name']) ?><?= $o['due_date'] ? ', срок ' . e($o['due_date']) : '' ?>
                    <span class="muted">(от <?= e($o['author_name']) ?>)</span></span>
            </div>
        <?php endforeach; ?>
        <?php if ($isBoss): ?>
        <form method="post" action="/docs/<?= (int)$doc['id'] ?>/order" style="margin-top:10px">
            <?= csrf_field() ?>
            <p class="muted" style="margin:0 0 6px">Резолюция может содержать несколько пунктов — каждый станет отдельным поручением.<?= !empty($doc['on_control']) ? ' Документ на контроле — поручения наследуют контроль.' : '' ?></p>
            <table class="table" id="resLines">
                <thead><tr><th>Пункт резолюции</th><th>Исполнитель</th><th>Срок</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
            <div class="form-inline" style="margin-top:8px">
                <button type="button" class="btn btn-mini" onclick="resAdd()">+ Пункт</button>
                <button class="btn btn-primary">Дать резолюцию</button>
            </div>
        </form>
        <script>
        var RES_USERS = <?= json_encode(array_map(fn($u)=>['id'=>(int)$u['id'],'name'=>$u['full_name']], $allUsers), JSON_UNESCAPED_UNICODE) ?>;
        function resOpt(){ return RES_USERS.map(function(u){ return '<option value="'+u.id+'">'+u.name+'</option>'; }).join(''); }
        function resAdd(){
          var tb=document.querySelector('#resLines tbody'), i=tb.children.length;
          var tr=document.createElement('tr');
          tr.innerHTML='<td><input type="text" name="res['+i+'][title]" placeholder="Подготовить ответ…" style="width:100%"></td>'
            +'<td><select name="res['+i+'][assignee]"><option value="">—</option>'+resOpt()+'</select></td>'
            +'<td><input type="date" name="res['+i+'][due]"></td>'
            +'<td><button type="button" class="btn btn-mini btn-danger" onclick="this.closest(\'tr\').remove()">×</button></td>';
          tb.appendChild(tr);
        }
        resAdd();
        </script>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (!empty($canRegister)): ?>
    <section class="panel">
        <h2>Регистрация</h2>
        <p class="muted" style="margin-top:0">
            <?= $doc['reg_number'] ? 'Зарегистрирован: <strong>' . e($doc['reg_number']) . '</strong>' . (!empty($doc['registered_at']) ? ' от ' . e(substr((string)$doc['registered_at'],0,10)) : '') : 'Не зарегистрирован' ?>
        </p>
        <form method="post" action="/docs/<?= (int)$doc['id'] ?>/assign-reg" class="form-inline" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
            <?= csrf_field() ?>
            <label>Журнал
                <select name="journal_id"><option value="">— по типу —</option>
                    <?php foreach ($journals as $j): ?><option value="<?= (int)$j['id'] ?>" <?= (int)($doc['journal_id'] ?? 0)===(int)$j['id']?'selected':'' ?>><?= e($j['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Дата регистрации<input type="date" name="reg_date" value="<?= e($doc['registered_at'] ? substr((string)$doc['registered_at'],0,10) : date('Y-m-d')) ?>"></label>
            <label>Ручной рег.№<input type="text" name="manual_no" placeholder="пусто = авто" value="<?= e($doc['reg_number']) ?>"></label>
            <button class="btn btn-primary"><?= $doc['reg_number'] ? 'Изменить регистрацию' : 'Зарегистрировать' ?></button>
        </form>
    </section>
    <?php endif; ?>

    <?php if (!empty($isAdminUser)): ?>
    <section class="panel" style="border-left:4px solid #c0392b">
        <h2 style="margin-top:0">Администрирование (откат действий)</h2>
        <div class="form-inline" style="gap:8px;flex-wrap:wrap">
            <?php if ($doc['reg_number']): ?>
            <form method="post" action="/docs/<?= (int)$doc['id'] ?>/unregister" class="inline" onsubmit="return confirm('Снять регистрационный номер?')"><?= csrf_field() ?><button class="btn btn-mini">Снять рег.№</button></form>
            <?php endif; ?>
            <form method="post" action="/docs/<?= (int)$doc['id'] ?>/unvisa" class="inline" onsubmit="return confirm('Отменить последнюю визу и вернуть документ на маршрут?')"><?= csrf_field() ?><button class="btn btn-mini">Отменить последнюю визу</button></form>
            <form method="post" action="/admin/data/document/<?= (int)$doc['id'] ?>/revert" class="inline" onsubmit="return confirm('Откатить статус документа на шаг назад?')"><?= csrf_field() ?><button class="btn btn-mini">↩ Откатить статус</button></form>
            <form method="post" action="/admin/data/document/<?= (int)$doc['id'] ?>/delete" class="inline" onsubmit="return confirm('УДАЛИТЬ документ со всеми связями (маршрут, файлы-метаданные, история, поручения)?')"><?= csrf_field() ?><button class="btn btn-mini btn-danger">✕ Удалить документ</button></form>
        </div>
        <p class="muted" style="margin:6px 0 0">Действия фиксируются в журнале. «Откатить статус»/«Удалить» используют раздел «Управление данными».</p>
    </section>
    <?php endif; ?>

    <?php if ($history): ?>
    <section class="panel">
        <h2>История</h2>
        <?php foreach ($history as $h): ?>
            <div class="hist-row">
                <span class="muted mono"><?= e(substr((string)$h['created_at'],0,16)) ?></span>
                <span><?= e($h['event']) ?><?= $h['comment'] ? ' — <i>' . e($h['comment']) . '</i>' : '' ?></span>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</div>

<div>
    <section class="panel">
        <h2>Маршрут</h2>
        <?php if (!$stages): ?><p class="muted">Маршрут не задан.</p><?php endif; ?>
        <?php foreach ($stages as $sn => $members):
            $stype = $members[0]['stage_type']; $par = (int)$members[0]['parallel'];
            $isCurrent = $doc['status']==='on_approval' && (int)$doc['current_step']===$sn;
        ?>
        <div class="stage-block<?= $isCurrent ? ' current' : '' ?>">
            <div class="stage-title">
                Этап <?= $sn ?>: <?= e(DocumentController::STAGE_LABEL[$stype] ?? $stype) ?>
                <span class="muted"><?= $par ? 'параллельно' : 'последовательно' ?></span>
                <?= $isCurrent ? '<span class="tag">текущий</span>' : '' ?>
            </div>
            <div class="stepper">
            <?php
            $seqTurnGiven = false;
            foreach ($members as $r):
                $cls='wait'; $icon='○';
                if ($r['status']==='approved') { $cls='ok'; $icon='✓'; }
                elseif ($r['status']==='acked') { $cls='ok'; $icon='👁'; }
                elseif ($r['status']==='skipped') { $cls='wait'; $icon='⊘'; }
                elseif ($r['status']==='rejected') { $cls='bad'; $icon='✕'; }
                elseif ($isCurrent && $r['status']==='pending' && ($par || !$seqTurnGiven)) { $cls='cur'; $icon='●'; if(!$par){$seqTurnGiven=true;} }
            ?>
                <div class="step <?= $cls ?>">
                    <span class="step-ic"><?= $icon ?></span>
                    <div class="step-body">
                        <strong><?= e($r['full_name']) ?></strong>
                        <span class="muted"><?= e($r['position']) ?></span>
                        <?php if ($r['behalf_name']): ?><span class="muted" style="font-size:.76rem">визировал заместитель: <?= e($r['behalf_name']) ?></span><?php endif; ?>
                        <?php if ($r['decided_at']): ?><span class="muted" style="font-size:.76rem"><?= e(substr((string)$r['decided_at'],0,16)) ?><?= $r['file_version'] ? ' · виза к версии v' . (int)$r['file_version'] : '' ?></span><?php endif; ?>
                        <?php if ($r['comment']): ?><div class="step-visa">«<?= e($r['comment']) ?>»</div><?php endif; ?>
                        <?php if (($r['stage_type'] ?? '') === 'sign' && !empty($r['sign_hash'])): ?>
                            <?= ep_stamp('Подписант' . ($r['behalf_name'] ? ' (за ' . $r['full_name'] . ')' : ''), $r['behalf_name'] ?: $r['full_name'], $r['decided_at'], $r['sign_type'] ?? 'PEP', $r['sign_hash']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($myTurn): ?>
        <h3 class="sub">Ваше решение — <?= e(DocumentController::STAGE_LABEL[$myTurn['stage_type']] ?? '') ?></h3>
        <form method="post" action="/docs/<?= (int)$doc['id'] ?>/decide">
            <?= csrf_field() ?>
            <?php if ($myTurn['stage_type'] === 'ack'): ?>
                <div class="form-inline">
                    <button class="btn btn-primary" name="verdict" value="ack">👁 Ознакомлен</button>
                </div>
            <?php else: ?>
                <label>Комментарий (виза)
                    <textarea name="comment" rows="2" placeholder="обязателен при отклонении"></textarea>
                </label>
                <?php if ($myTurn['stage_type'] === 'sign'): ?>
                <label style="max-width:320px">Пароль (подтверждение ЭП)
                    <input type="password" name="password" autocomplete="off" placeholder="ваш пароль входа">
                </label>
                <?php endif; ?>
                <div class="form-inline">
                    <button class="btn btn-primary" name="verdict" value="approve"
                        <?= $myTurn['stage_type']==='sign' ? 'onclick="return this.form.password.value!==\'\' || (alert(\'Введите пароль для подписи\'),false)"' : '' ?>><?= $myTurn['stage_type']==='sign' ? '🖋 Подписать' : '✓ Согласовать' ?></button>
                    <?php if ($myTurn['stage_type']==='approve'): ?>
                    <button class="btn" name="verdict" value="approve_rem"
                            onclick="return this.form.comment.value.trim()!=='' || (alert('Укажите замечания в комментарии'),false)">✓ С замечаниями</button>
                    <?php endif; ?>
                    <button class="btn btn-danger" name="verdict" value="reject"
                            onclick="return this.form.comment.value.trim()!=='' || (alert('При отклонении укажите причину'),false)">✕ Отклонить</button>
                    <?php if ($myTurn['stage_type']==='approve'): ?>
                    <button class="btn btn-mini" name="verdict" value="incompetent"
                            onclick="return confirm('Отметить «не в моей компетенции»? Этап продолжится без вашей визы.')">⊘ Не в моей компетенции</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <?php if ($myTurn): ?>
        <details style="margin-top:12px">
            <summary class="muted" style="cursor:pointer">Переадресовать задачу другому сотруднику…</summary>
            <form method="post" action="/docs/<?= (int)$doc['id'] ?>/redirect" class="form-inline" style="margin-top:8px">
                <?= csrf_field() ?>
                <label>Кому
                    <select name="to_user_id">
                        <?php foreach ($allUsers as $u2): if ((int)$u2['id'] === (int)$myTurn['user_id']) continue; ?>
                            <option value="<?= (int)$u2['id'] ?>"><?= e($u2['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-mini" onclick="return confirm('Передать задачу выбранному сотруднику?')">→ Переадресовать</button>
            </form>
        </details>
        <?php endif; ?>
    </section>
</div>
</div>

<?php if ($files): $cur = $files[0]; ?>
<section class="panel">
    <h2>Образ документа
        <span class="muted" style="font-size:.8rem;font-weight:400">
            <?= e($cur['orig_name']) ?> · v<?= (int)$cur['version'] ?> — текущая
            <?php foreach (array_slice($files, 1) as $fv): ?>
                · <a href="/docs/<?= (int)$doc['id'] ?>/preview?v=<?= (int)$fv['version'] ?>" target="_blank">v<?= (int)$fv['version'] ?></a>
            <?php endforeach; ?>
        </span>
        <a class="btn btn-mini" style="float:right" href="/docs/<?= (int)$doc['id'] ?>/preview" target="_blank">⤢ Во весь экран</a>
    </h2>
    <iframe class="doc-preview" src="/docs/<?= (int)$doc['id'] ?>/preview" title="Образ документа"></iframe>
</section>
<?php endif; ?>
