<div class="chat-head">
    <a href="/chat" class="btn btn-mini">← Беседы</a>
    <h1><?= e($title) ?></h1>
    <span class="muted"><?= count($members) ?> участн.</span>
</div>

<section class="panel chat-panel">
    <div id="messages" class="messages">
        <?php foreach ($msgs as $m): ?>
            <div class="msg <?= (int) $m['sender_id'] === (int) $uid ? 'mine' : '' ?>">
                <div class="msg-meta"><strong><?= e($m['sender']) ?></strong> <span class="muted"><?= e($m['created_at']) ?></span></div>
                <?php if ($m['body'] !== '' && $m['body'] !== null): ?><div class="msg-body"><?= nl2br(e($m['body'])) ?></div><?php endif; ?>
                <?php foreach ($m['attachments'] as $a): ?>
                    <a class="msg-file" href="/chat/file/<?= (int) $a['id'] ?>">📎 <?= e($a['orig_name']) ?> <span class="muted">(<?= round($a['size_bytes']/1024) ?> КБ)</span></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="post" action="/chat/<?= (int) $conv['id'] ?>/send" enctype="multipart/form-data" class="chat-send">
        <?= csrf_field() ?>
        <textarea name="body" rows="1" placeholder="Сообщение…"></textarea>
        <label class="file-btn" title="Прикрепить файл">📎<input type="file" name="file"></label>
        <button class="btn btn-primary">Отправить</button>
    </form>
</section>

<script>
(function(){
  var cid = <?= (int) $conv['id'] ?>;
  var lastId = <?= (int) $lastId ?>;
  var me = <?= (int) $uid ?>;
  var box = document.getElementById('messages');
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
  function scrollBottom(){ box.scrollTop = box.scrollHeight; }
  scrollBottom();
  function render(m){
    var div=document.createElement('div');
    div.className='msg'+(m.sender_id==me?' mine':'');
    var html='<div class="msg-meta"><strong>'+esc(m.sender)+'</strong> <span class="muted">'+esc(m.created_at)+'</span></div>';
    if(m.body) html+='<div class="msg-body">'+esc(m.body).replace(/\n/g,'<br>')+'</div>';
    (m.attachments||[]).forEach(function(a){
      html+='<a class="msg-file" href="/chat/file/'+a.id+'">📎 '+esc(a.orig_name)+' <span class="muted">('+Math.round(a.size_bytes/1024)+' КБ)</span></a>';
    });
    div.innerHTML=html; box.appendChild(div);
  }
  function poll(){
    fetch('/chat/'+cid+'/messages?after='+lastId,{headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.messages && d.messages.length){
          d.messages.forEach(function(m){ render(m); lastId=Math.max(lastId, m.id); });
          scrollBottom();
        }
      }).catch(function(){});
  }
  setInterval(poll, 7000);
  // авто-рост textarea
  var ta=document.querySelector('.chat-send textarea');
  ta.addEventListener('input',function(){ this.style.height='auto'; this.style.height=Math.min(120,this.scrollHeight)+'px'; });
})();
</script>
