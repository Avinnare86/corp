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

  // ---------- навигация: drawer (узкое/мобайл) + аккордеоны + overflow «Ещё» (десктоп) ----------
  var NAV_BREAK = '(max-width:1099.98px)';
  function isDrawer() { return window.matchMedia(NAV_BREAK).matches; }
  function closeAllGroups() {
    [].forEach.call(document.querySelectorAll('.nav-group.open'), function (g) {
      g.classList.remove('open');
      var b = g.querySelector('.nav-gbtn'); if (b) b.setAttribute('aria-expanded', 'false');
    });
  }
  window.navToggle = function (btn) {
    var g = btn.closest('.nav-group'), was = g.classList.contains('open');
    var nested = !!(g.parentElement && g.parentElement.closest('.nav-drop-more'));
    if (isDrawer() || nested) {                       // аккордеон: переключаем только себя
      g.classList.toggle('open', !was);
      btn.setAttribute('aria-expanded', !was ? 'true' : 'false');
      return;
    }
    closeAllGroups();                                 // десктоп: один раскрыт за раз
    if (!was) {
      g.classList.add('open'); btn.setAttribute('aria-expanded', 'true');
      var d = g.querySelector('.nav-drop');
      if (d) {                                        // фикс-позиция под кнопкой (дропдаун вне overflow:hidden у .nav)
        var r = btn.getBoundingClientRect();
        d.style.top = Math.round(r.bottom + 2) + 'px';
        if (r.right < 230) { d.style.left = Math.round(r.left) + 'px'; d.style.right = 'auto'; }
        else { d.style.left = 'auto'; d.style.right = Math.round(window.innerWidth - r.right) + 'px'; }
      }
    }
  };
  window.navBurger = function () {
    var open = document.body.classList.toggle('menu-open');
    var b = document.querySelector('.nav-burger'); if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
    var scrim = document.querySelector('.nav-scrim'); if (scrim) scrim.hidden = !open;
    if (open) { var c = document.querySelector('#mainNav .nav-close'); if (c) c.focus(); }
    else closeAllGroups();
  };
  function navOverflow() {
    var nav = document.getElementById('mainNav'); if (!nav) return;
    var more = nav.querySelector('.nav-more'), bucket = nav.querySelector('.nav-drop-more');
    if (!more || !bucket) return;
    while (bucket.firstChild) { nav.insertBefore(bucket.firstChild, more); }   // вернуть всё на место
    more.hidden = true;
    if (isDrawer()) return;                            // в drawer всё вертикально — overflow не нужен
    var guard = 0;
    while (nav.scrollWidth > nav.clientWidth + 1 && guard++ < 40) {
      var groups = nav.querySelectorAll(':scope > .nav-group[data-group]');
      var movable = null;                              // активный раздел оставляем видимым (двигаем последний НЕ активный)
      for (var i = groups.length - 1; i >= 0; i--) { if (!groups[i].classList.contains('is-active')) { movable = groups[i]; break; } }
      if (!movable) break;
      bucket.insertBefore(movable, bucket.firstChild); // prepend — сохраняем исходный порядок
      more.hidden = false;
    }
    var sum = 0;
    [].forEach.call(bucket.querySelectorAll('.nav-gbtn .badge'), function (b) { sum += parseInt(b.textContent, 10) || 0; });
    var mBtn = more.querySelector('.nav-gbtn'), old = mBtn.querySelector('.badge');
    if (old) old.remove();
    if (sum > 0) { var s = document.createElement('span'); s.className = 'badge'; s.textContent = sum; mBtn.insertBefore(s, mBtn.querySelector('.caret')); }
    more.classList.toggle('is-active', !!bucket.querySelector('.nav-group.is-active'));  // подсветить «Ещё», если активный раздел внутри
    if (!bucket.children.length) more.hidden = true;
  }
  var _navT;
  function navOverflowDebounced() { clearTimeout(_navT); _navT = setTimeout(navOverflow, 120); }
  window.addEventListener('resize', navOverflowDebounced);
  function closeUserPop() {
    var p = document.querySelector('.user-pop.open');
    if (p) { p.classList.remove('open'); var b = p.querySelector('.user-btn'); if (b) b.setAttribute('aria-expanded', 'false'); }
  }
  window.userMenu = function (btn) {
    var p = btn.closest('.user-pop'), open = p.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.nav-group')) closeAllGroups();
    if (!e.target.closest('.user-pop')) closeUserPop();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeAllGroups(); closeUserPop(); if (document.body.classList.contains('menu-open')) navBurger(); }
  });
  document.addEventListener('DOMContentLoaded', function () {
    navOverflow();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(navOverflow);  // Unbounded меняет ширины
    if (isDrawer()) {   // в drawer сразу раскрыть раздел с активным пунктом
      [].forEach.call(document.querySelectorAll('.nav-group.is-active'), function (g) {
        g.classList.add('open'); var b = g.querySelector('.nav-gbtn'); if (b) b.setAttribute('aria-expanded', 'true');
      });
    }
    var nav = document.getElementById('mainNav');
    if (nav) nav.addEventListener('click', function (e) {   // выбор пункта в drawer закрывает меню
      if (e.target.closest('a[href]') && isDrawer() && document.body.classList.contains('menu-open')) navBurger();
    });
  });

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
    if (!t.tHead || !t.tHead.rows.length) return;
    var labels = [].map.call(t.tHead.rows[0].cells, function (th) { return th.textContent.trim(); });
    [].forEach.call(t.tBodies, function (tb) {
      [].forEach.call(tb.rows, function (tr) {
        [].forEach.call(tr.cells, function (td, i) {
          if (td.hasAttribute('data-label')) return;          // автор задал подпись вручную — не трогаем
          if (labels[i]) td.setAttribute('data-label', labels[i]);
        });
      });
    });
  }
  // Классифицировать таблицы внутри root (вызывается и для AJAX-вставок). По умолчанию — весь документ.
  function enhanceTables(root) {
    [].forEach.call((root || document).querySelectorAll('table.table'), function (t) {
      if (t.dataset.enhanced) return; t.dataset.enhanced = '1';
      if (t.classList.contains('tbl-wide')) { wrapScroll(t); }          // автор: широкая → прокрутка
      else if (t.classList.contains('tbl-cards')) { labelCards(t); }    // автор: карточки → проставить подписи
      else if (isWide(t)) { t.classList.add('tbl-wide'); wrapScroll(t); }
      else { t.classList.add('tbl-cards'); labelCards(t); }
      makeSortable(t);
    });
    [].forEach.call((root || document).querySelectorAll('table.vgrid'), function (t) {
      if (t.dataset.enhanced) return; t.dataset.enhanced = '1'; makeSortable(t);
    });
  }
  window.enhanceTables = enhanceTables;

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

    enhanceTables(document);
  });
})();
