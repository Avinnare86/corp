/* Глобальный скрипт портала (подключается один раз в layout.php, defer):
   - гамбургер-меню на телефоне;
   - умная адаптация таблиц: данные → карточки (data-label), формы/широкие → горизонтальная прокрутка;
   - переключатель «Карточки / Полные таблицы» (внизу гамбургер-меню);
   - сортировка любых таблиц по клику на заголовок. */
(function () {
  'use strict';

  function getCookie(n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : ''; }
  function setCookie(n, v) { document.cookie = n + '=' + encodeURIComponent(v) + ';path=/;max-age=' + (3600 * 24 * 365); }

  // ---------- переключатель карточки/полные таблицы ----------
  function applyView(v) { document.body.classList.toggle('force-desktop', v === 'full'); }
  function viewLabel(v) { return v === 'full' ? '🗂 Показать карточки' : '📋 Показать полные таблицы'; }
  window.toggleTableView = function () {
    var v = getCookie('mobview') === 'full' ? '' : 'full';
    setCookie('mobview', v); applyView(v);
    var b = document.getElementById('tblViewBtn'); if (b) b.textContent = viewLabel(v);
  };

  // ---------- гамбургер (полноэкранное меню на телефоне) ----------
  window.navBurger = function () {
    var tb = document.querySelector('.topbar'); if (!tb) return;
    var open = tb.classList.toggle('nav-open');
    document.body.classList.toggle('menu-open', open);   // блокировка прокрутки фона
  };

  // ---------- классификация таблиц ----------
  function isWide(t) {
    if (!t.tHead || !t.tHead.rows.length) return true;                 // нет шапки — не карточки
    if (t.tBodies[0] && t.tBodies[0].querySelector('input,select,textarea')) return true; // поля ввода
    // строки со span по колонкам ломают карточки
    var tb = t.tBodies[0];
    if (tb) { for (var i = 0; i < tb.rows.length; i++) { var r = tb.rows[i]; for (var j = 0; j < r.cells.length; j++) { if (r.cells[j].colSpan > 1) return true; } } }
    return false;
  }
  function wrapScroll(t) {
    if (t.parentElement && t.parentElement.classList.contains('table-scroll')) return;
    var w = document.createElement('div'); w.className = 'table-scroll';
    t.parentNode.insertBefore(w, t); w.appendChild(t);
  }
  function labelCards(t) {
    var labels = [].map.call(t.tHead.rows[0].cells, function (th) { return th.textContent.trim(); });
    [].forEach.call(t.tBodies, function (tb) {
      [].forEach.call(tb.rows, function (tr) {
        [].forEach.call(tr.cells, function (td, i) { if (labels[i]) td.setAttribute('data-label', labels[i]); });
      });
    });
  }

  // ---------- сортировка ----------
  function num(s) {
    var d = s.match(/^(\d{2})\.(\d{2})\.(\d{2,4})$/);
    if (d) { var y = d[3].length === 2 ? '20' + d[3] : d[3]; return +(y + d[2] + d[1]); }
    var x = s.replace(/[^\d.,-]/g, '').replace(/\s/g, '').replace(',', '.');
    var f = parseFloat(x); return (x === '' || isNaN(f)) ? NaN : f;
  }
  function cmp(a, b) { var na = num(a), nb = num(b); if (!isNaN(na) && !isNaN(nb)) return na - nb; return a.localeCompare(b, 'ru'); }
  function cellText(tr, i) { var c = tr.cells[i]; return c ? (c.textContent || '').trim() : ''; }
  function sortTable(t, col, asc) {
    var tb = t.tBodies[0]; if (!tb) return;
    var all = [].slice.call(tb.rows);
    var totals = all.filter(function (r) { return r.classList.contains('total'); });
    var rows = all.filter(function (r) { return !r.classList.contains('total'); });
    rows.sort(function (r1, r2) { var d = cmp(cellText(r1, col), cellText(r2, col)); return asc ? d : -d; });
    rows.forEach(function (r) { tb.appendChild(r); });
    totals.forEach(function (r) { tb.appendChild(r); });
  }
  function makeSortable(t) {
    if (!t.tHead || !t.tHead.rows.length) return;
    var head = t.tHead.rows[0];
    [].forEach.call(head.cells, function (th, i) {
      if (th.classList.contains('nosort') || th.textContent.trim() === '') return;
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
    applyView(getCookie('mobview'));
    var b = document.getElementById('tblViewBtn'); if (b) b.textContent = viewLabel(getCookie('mobview'));

    [].forEach.call(document.querySelectorAll('table.table'), function (t) {
      if (isWide(t)) { t.classList.add('tbl-wide'); wrapScroll(t); }
      else { t.classList.add('tbl-cards'); labelCards(t); }
      makeSortable(t);
    });
    // Грид виз и прочие .vgrid — всегда широкие (прокрутка), сортируемые.
    [].forEach.call(document.querySelectorAll('table.vgrid'), function (t) { makeSortable(t); });
  });
})();
