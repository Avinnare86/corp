<?php /* Всплывающий чат-виджет с уведомлениями (правый нижний угол, десктоп). */ ?>
<button class="cw-btn" id="cwBtn" title="Чат и уведомления">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-3.8-.8L3 20l1-4.1a8.3 8.3 0 0 1-1-4.4 8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 9 8.4Z"/></svg>
    <span class="cw-badge" id="cwBadge" style="display:none">0</span>
</button>

<div class="cw-panel" id="cwPanel">
    <div class="cw-tabs">
        <button class="cw-tab active" id="cwTabChats" onclick="cwTab('chats')">Чаты <span class="cw-badge sm" id="cwChatBadge" style="display:none">0</span></button>
        <button class="cw-tab" id="cwTabNotifs" onclick="cwTab('notifs')">Уведомления <span class="cw-badge sm" id="cwNotifBadge" style="display:none">0</span></button>
        <a class="cw-expand" href="/chat" title="Открыть на весь экран">⤢</a>
        <button class="cw-close" onclick="cwToggle(false)">✕</button>
    </div>
    <div class="cw-body">
        <div id="cwChatsWrap">
            <button class="cw-newchat" id="cwNewBtn" onclick="cwOpenNew()">✚ Новый чат</button>
            <div id="cwChats" class="cw-list"></div>
        </div>
        <div id="cwNotifs" class="cw-list" style="display:none"></div>
        <div id="cwNew" style="display:none">
            <div class="cw-conv-head">
                <button class="btn btn-mini" onclick="cwCloseNew()">←</button>
                <strong>Новый чат — кому</strong>
            </div>
            <input type="text" id="cwNewSearch" placeholder="Поиск по ФИО…" autocomplete="off"
                   style="margin:8px 10px;width:calc(100% - 20px);padding:7px 10px;border:1px solid var(--line);border-radius:8px;box-sizing:border-box">
            <div id="cwNewList" class="cw-list" style="max-height:340px;overflow:auto"></div>
        </div>
        <div id="cwConv" style="display:none">
            <div class="cw-conv-head">
                <button class="btn btn-mini" onclick="cwBackToList()">←</button>
                <strong id="cwConvTitle"></strong>
            </div>
            <div class="cw-msgs" id="cwMsgs"></div>
            <div class="cw-filechip" id="cwFileChip" style="display:none">
                <span id="cwFileName"></span>
                <button type="button" class="cw-filex" id="cwFileClear" title="Убрать">✕</button>
            </div>
            <div class="cw-emoji" id="cwEmoji" style="display:none"></div>
            <form class="cw-send" id="cwSendForm">
                <button type="button" class="cw-ico" id="cwEmojiBtn" title="Эмодзи">😊</button>
                <label class="cw-ico" id="cwFileBtn" title="Прикрепить файл (до 10 МБ)">📎
                    <input type="file" id="cwFile" style="display:none">
                </label>
                <textarea id="cwText" rows="1" placeholder="Сообщение…"></textarea>
                <button type="submit" class="btn btn-mini btn-primary" title="Отправить">➤</button>
            </form>
        </div>
    </div>
</div>
<div class="cw-toast" id="cwToast"></div>

<script>
(function(){
  var CSRF=<?= json_encode(\App\Core\Auth::csrf()) ?>;
  var ME=<?= (int) \App\Core\Auth::id() ?>;
  var open=false, tab='chats', convId=null, convLastId=0, convTimer=null;
  var prevChat=-1, prevNotif=-1, state=null;

  function $(id){ return document.getElementById(id); }
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
  function toast(msg){ var t=$('cwToast'); t.textContent=msg; t.classList.add('show'); setTimeout(function(){ t.classList.remove('show'); },3200); }

  window.cwToggle=function(v){
    open = (v===undefined) ? !open : v;
    $('cwPanel').classList.toggle('open', open);
    if(open){ refresh(); }
    else { cwBackToList(); }
  };
  $('cwBtn').addEventListener('click', function(){ cwToggle(); });

  window.cwTab=function(t){
    tab=t; cwBackToList(true);
    $('cwTabChats').classList.toggle('active', t==='chats');
    $('cwTabNotifs').classList.toggle('active', t==='notifs');
    $('cwChatsWrap').style.display = t==='chats' ? '' : 'none';
    $('cwNotifs').style.display = t==='notifs' ? '' : 'none';
    $('cwNew').style.display='none';
  };

  // ---- новый чат: выбор собеседника ----
  var newTimer=null;
  window.cwOpenNew=function(){
    $('cwChatsWrap').style.display='none'; $('cwNotifs').style.display='none'; $('cwConv').style.display='none';
    $('cwNew').style.display='block';
    $('cwNewSearch').value=''; loadContacts('');
    $('cwNewSearch').focus();
  };
  window.cwCloseNew=function(){ $('cwNew').style.display='none'; cwBackToList(); };
  function loadContacts(q){
    var box=$('cwNewList'); box.innerHTML='<div class="cw-empty">Загрузка…</div>';
    fetch('/chat/contacts?q='+encodeURIComponent(q),{headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();}).then(function(d){
        box.innerHTML='';
        if(!d.users||!d.users.length){ box.innerHTML='<div class="cw-empty">Не найдено.</div>'; return; }
        d.users.forEach(function(u){
          var row=document.createElement('div'); row.className='cw-row';
          row.innerHTML='<span class="cw-ava">👤</span><span class="cw-main"><span class="cw-name">'+esc(u.full_name)+'</span><span class="cw-last">'+esc(u.dept||'')+'</span></span>';
          row.onclick=function(){ startDirect(u.id, u.full_name); };
          box.appendChild(row);
        });
      }).catch(function(){ box.innerHTML='<div class="cw-empty">Ошибка сети.</div>'; });
  }
  function startDirect(id, name){
    fetch('/chat/direct',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
      body:'_csrf='+encodeURIComponent(CSRF)+'&user_id='+id})
      .then(function(r){return r.json();}).then(function(d){
        if(d&&d.ok){ $('cwNew').style.display='none'; openConv(d.id, d.display||name); refresh(); }
      });
  }

  function renderChats(){
    var box=$('cwChats'); box.innerHTML='';
    if(!state || !state.conversations.length){ box.innerHTML='<div class="cw-empty">Бесед нет. <a href="/chat">Начать чат</a></div>'; return; }
    state.conversations.forEach(function(c){
      var d=document.createElement('div'); d.className='cw-row'+(c.unread?' unread':'');
      d.innerHTML='<span class="cw-ava">'+(c.type==='group'?'👥':'👤')+'</span><span class="cw-main"><span class="cw-name">'+esc(c.display)+'</span><span class="cw-last">'+esc(c.last||'')+'</span></span>'+(c.unread?'<span class="cw-dot"></span>':'');
      d.onclick=function(){ openConv(c.id, c.display); };
      box.appendChild(d);
    });
  }
  function renderNotifs(){
    var box=$('cwNotifs'); box.innerHTML='';
    if(!state || !state.notifications.length){ box.innerHTML='<div class="cw-empty">Уведомлений нет.</div>'; return; }
    state.notifications.forEach(function(n){
      var unread = !parseInt(n.is_read);
      var d=document.createElement('div'); d.className='cw-note'+(unread?' unread':'');
      d.innerHTML='<div class="cw-note-t">'+esc(n.title)+'</div><div class="cw-note-b">'+esc((n.body||'').slice(0,180))+'</div><div class="cw-note-m">'+esc((n.created_at||'').slice(0,16))+(unread?' · <a href="#" data-id="'+n.id+'" class="cw-read">прочитано</a>':'')+'</div>';
      box.appendChild(d);
    });
    box.querySelectorAll('.cw-read').forEach(function(a){
      a.addEventListener('click', function(e){
        e.preventDefault();
        fetch('/notifications/'+this.dataset.id+'/read',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},body:'_csrf='+encodeURIComponent(CSRF)})
          .then(function(){ refresh(); });
      });
    });
  }
  function updateBadges(){
    if(!state) return;
    var total=state.chatUnread+state.notifUnread;
    $('cwBadge').style.display = total>0?'':'none'; $('cwBadge').textContent=total;
    $('cwChatBadge').style.display = state.chatUnread>0?'':'none'; $('cwChatBadge').textContent=state.chatUnread;
    $('cwNotifBadge').style.display = state.notifUnread>0?'':'none'; $('cwNotifBadge').textContent=state.notifUnread;
  }

  function refresh(){
    fetch('/widget/state',{headers:{'X-Requested-With':'fetch'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        state=d;
        // всплывашки о новом (после первого опроса)
        if(prevChat>=0 && d.chatUnread>prevChat){ toast('💬 Новое сообщение в чате'); }
        if(prevNotif>=0 && d.notifUnread>prevNotif){ toast('🔔 Новое уведомление'); }
        prevChat=d.chatUnread; prevNotif=d.notifUnread;
        updateBadges();
        if(open && !convId){ renderChats(); renderNotifs(); }
      }).catch(function(){});
  }

  // ---- беседа внутри виджета ----
  function renderMsg(m){
    var div=document.createElement('div');
    div.className='msg'+(m.sender_id==ME?' mine':'');
    var html='<div class="msg-meta"><strong>'+esc(m.sender)+'</strong></div>';
    if(m.body) html+='<div class="msg-body">'+esc(m.body).replace(/\n/g,'<br>')+'</div>';
    (m.attachments||[]).forEach(function(a){ html+='<a class="msg-file" href="/chat/file/'+a.id+'">📎 '+esc(a.orig_name)+'</a>'; });
    div.innerHTML=html;
    return div;
  }
  function pollConv(){
    if(!convId) return;
    fetch('/chat/'+convId+'/messages?after='+convLastId,{headers:{'X-Requested-With':'fetch'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        var box=$('cwMsgs');
        (d.messages||[]).forEach(function(m){ box.appendChild(renderMsg(m)); convLastId=Math.max(convLastId,m.id); });
        if((d.messages||[]).length){ box.scrollTop=box.scrollHeight; }
      }).catch(function(){});
  }
  function openConv(id, title){
    convId=id; convLastId=0;
    $('cwConvTitle').textContent=title;
    $('cwMsgs').innerHTML='';
    $('cwChatsWrap').style.display='none'; $('cwNotifs').style.display='none'; $('cwNew').style.display='none'; $('cwConv').style.display='flex';
    pollConv();
    convTimer=setInterval(pollConv, 5000);
  }
  window.cwBackToList=function(keepTab){
    if(convTimer){ clearInterval(convTimer); convTimer=null; }
    convId=null;
    $('cwConv').style.display='none'; $('cwNew').style.display='none';
    if(!keepTab){ $('cwChatsWrap').style.display = tab==='chats'?'':'none'; $('cwNotifs').style.display = tab==='notifs'?'':'none'; }
    renderChats(); renderNotifs();
  };
  $('cwNewSearch').addEventListener('input', function(){
    if(newTimer) clearTimeout(newTimer);
    var q=this.value; newTimer=setTimeout(function(){ loadContacts(q); }, 250);
  });
  // ---- прикрепление файла ----
  function clearFile(){ $('cwFile').value=''; $('cwFileChip').style.display='none'; $('cwFileName').textContent=''; }
  $('cwFile').addEventListener('change', function(){
    var f=this.files&&this.files[0];
    if(!f){ clearFile(); return; }
    if(f.size>10485760){ toast('Файл слишком большой (макс. 10 МБ).'); clearFile(); return; }
    $('cwFileName').textContent='📎 '+f.name;
    $('cwFileChip').style.display='flex';
  });
  $('cwFileClear').addEventListener('click', clearFile);

  // ---- эмодзи ----
  var EMOJI='😀 😁 😂 🤣 😊 😍 😘 😉 😎 🤔 🙂 😐 😴 😇 🥳 😅 😢 😭 😡 🤬 😱 😳 🤝 🙏 👍 👎 👌 👏 🙌 💪 ✍️ 👀 ✅ ❌ ❗ ❓ ⚠️ 🔥 ⭐ 🎉 💯 📌 📎 📄 📅 ⏰ 💰 ✉️ 📞 🚀 ❤️ 💔 💬'.split(' ');
  (function(){ var box=$('cwEmoji'); EMOJI.forEach(function(em){ var b=document.createElement('button'); b.type='button'; b.className='cw-em'; b.textContent=em;
    b.onclick=function(){ insertText($('cwText'), em); $('cwText').focus(); }; box.appendChild(b); }); })();
  function insertText(ta, t){
    var s=ta.selectionStart, e=ta.selectionEnd, v=ta.value;
    if(s==null){ ta.value=v+t; } else { ta.value=v.slice(0,s)+t+v.slice(e); ta.selectionStart=ta.selectionEnd=s+t.length; }
  }
  $('cwEmojiBtn').addEventListener('click', function(e){ e.stopPropagation();
    $('cwEmoji').style.display = $('cwEmoji').style.display==='none' ? 'flex' : 'none'; });
  document.addEventListener('click', function(e){
    if($('cwEmoji').style.display!=='none' && !$('cwEmoji').contains(e.target) && e.target!==$('cwEmojiBtn')){ $('cwEmoji').style.display='none'; }
  });

  $('cwSendForm').addEventListener('submit', function(e){
    e.preventDefault();
    if(!convId) return;
    var txt=$('cwText').value.trim();
    var file=$('cwFile').files&&$('cwFile').files[0];
    if(!txt && !file) return;
    var fd=new FormData(); fd.append('_csrf',CSRF); fd.append('body',txt);
    if(file){ fd.append('file', file); }
    var btn=this.querySelector('button[type=submit]'); btn.disabled=true;
    $('cwEmoji').style.display='none';
    fetch('/chat/'+convId+'/send',{method:'POST',headers:{'X-Requested-With':'fetch'},body:fd})
      .then(function(){ $('cwText').value=''; clearFile(); btn.disabled=false; pollConv(); })
      .catch(function(){ btn.disabled=false; toast('Не удалось отправить.'); });
  });
  $('cwText').addEventListener('keydown', function(e){
    if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); $('cwSendForm').dispatchEvent(new Event('submit')); }
  });

  refresh();
  setInterval(refresh, 12000);
})();
</script>
