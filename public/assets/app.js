/* Глобальный скрипт портала: сортировка таблиц по клику + карточки на телефоне + переключатель «телефонный вид».
   Подключается один раз в layout.php (defer) и работает на всех страницах. */
(function () {
  'use strict';

  // ---------- cookie ----------
  function getCookie(n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : ''; }
  function setCookie(n, v) { document.cookie = n + '=' + encodeURIComponent(v) + ';path=/;max-age=' + (3600 * 24 * 365); }

  // ---------- мобильный режим: '' (авто) | 'on' (всегда карточки) | 'off' (всегда таблицы) ----------
  function applyMobile(state) {
    document.body.classList.remove('mobile-mode', 'force-desktop');
    if (state === 'on') document.body.classList.add('mobile-mode');
    else if (state === 'off') document.body.classList.add('force-desktop');
  }
  function mobLabel(state) { return state === 'on' ? '📱 Телефон: вкл' : (state === 'off' ? '🖥 Полные таблицы' : '📱 Телефон: авто'); }
  function updMobBtn(state) { var b = document.getElementById('mobToggle'); if (b) b.textContent = mobLabel(state); }
  window.toggleMobile = function () {
    var s = getCookie('mobmode');
    var next = s === 'on' ? 'off' : (s === 'off' ? '' : 'on'); // авто → вкл → выкл → авто
    setCookie('mobmode', next); applyMobile(next); updMobBtn(next);
  };

  // ---------- карточки: проставить data-label из заголовков ----------
  function labelTable(t) {
    if (!t.tHead || !t.tHead.rows.length) return;
    var labels = [].map.call(t.tHead.rows[0].cells, function (th) { return th.textContent.trim(); });
    [].forEach.call(t.tBodies, function (tb) {
      [].forEach.call(tb.rows, function (tr) {
        [].forEach.call(tr.cells, function (td, i) { if (labels[i]) td.setAttribute('data-label', labels[i]); });
      });
    });
  }

  // ---------- сортировка ----------
  function num(s) {
    var d = s.match(/^(\d{2})\.(\d{2})\.(\d{2,4})$/);              // дата дд.мм.гггг → число гггг-мм-дд
    if (d) { var y = d[3].length === 2 ? '20' + d[3] : d[3]; return +(y + d[2] + d[1]); }
    var x = s.replace(/[^\d.,-]/g, '').replace(/\s/g, '').replace(',', '.');
    var f = parseFloat(x);
    return (x === '' || isNaN(f)) ? NaN : f;
  }
  function cmp(a, b) { var na = num(a), nb = num(b); if (!isNaN(na) && !isNaN(nb)) return na - nb; return a.localeCompare(b, 'ru'); }
  function cellText(tr, i) { var c = tr.cells[i]; return c ? (c.textContent || '').trim() : ''; }

  function sortTable(t, col, asc) {
    var tb = t.tBodies[0]; if (!tb) return;
    var all = [].slice.call(tb.rows);
    var totals = all.filter(function (r) { return r.classList.contains('total'); });
    var rows = all.filter(function (r) { return !r.classList.contains('total'); });
    rows.sort(function (r1, r2) { var d = cmp(cellText(r1, col), cellText(r2, col)); return asc ? d : -d; });
    rows.forEach(function (r) { tb.appendChild(r); });   // переносим целые <tr> — формы/textarea сохраняются
    totals.forEach(function (r) { tb.appendChild(r); }); // итоговые строки всегда вниз
  }
  function makeSortable(t) {
    if (!t.tHead || !t.tHead.rows.length) return;
    var head = t.tHead.rows[0];
    [].forEach.call(head.cells, function (th, i) {
      if (th.classList.contains('nosort') || th.textContent.trim() === '') return; // пропускаем «действие»/пустые
      th.classList.add('sortable'); th.title = 'Нажмите, чтобы упорядочить';
      th.addEventListener('click', function () {
        var asc = th.getAttribute('data-sort') !== 'asc';
        [].forEach.call(head.cells, function (c) { c.removeAttribute('data-sort'); c.classList.remove('sort-asc', 'sort-desc'); });
        th.setAttribute('data-sort', asc ? 'asc' : 'desc');
        th.classList.add(asc ? 'sort-asc' : 'sort-desc');
        sortTable(t, i, asc);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var state = getCookie('mobmode');
    applyMobile(state); updMobBtn(state);
    [].forEach.call(document.querySelectorAll('table.table'), function (t) { labelTable(t); makeSortable(t); });
    [].forEach.call(document.querySelectorAll('table.vgrid'), function (t) { makeSortable(t); });
  });
})();
