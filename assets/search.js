/* PromoFun – zoeksuggesties onder elk zoekveld: categorieën (met filter), producten met foto en prijs. */
(function () {
  'use strict';
  var C = window.MDO_SEARCH;
  if (!C || !window.fetch) return;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function mark(name, q) {
    var words = q.toLowerCase().split(/\s+/).filter(function (w) { return w.length > 1; });
    var h = esc(name);
    words.forEach(function (w) { h = h.replace(new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<b>$1</b>'); });
    return h;
  }
  var cache = {};

  function attach(inp) {
    if (inp.getAttribute('data-mdo-suggest')) return;
    inp.setAttribute('data-mdo-suggest', '1');
    inp.setAttribute('autocomplete', 'off');
    var form = inp.form, box = document.createElement('div'), tmr = 0, last = '', items = [], sel = -1;
    box.className = 'mdo-suggest';
    box.setAttribute('role', 'listbox');
    box.hidden = true;
    var host = inp.closest('.sm_search_form, .search-form, form') || inp.parentNode;
    if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
    host.appendChild(box);
    var theirs = form && form.querySelector('.search-content'); if (theirs) theirs.style.display = 'none';

    function close() { box.hidden = true; sel = -1; inp.setAttribute('aria-expanded', 'false'); }
    function draw(d, q) {
      var h = '';
      if (d.cats && d.cats.length) {
        h += '<p class="mdo-sg-h">Categorieën</p>' + d.cats.map(function (c) {
          return '<a class="mdo-sg-cat" role="option" href="' + esc(c.url) + '"><span class="mdo-sg-ic" aria-hidden="true">⌕</span><span><span class="mdo-sg-n">' + mark(c.name, q) + (c.filter ? ' <em>· ' + esc(c.filter) + '</em>' : '') + '</span>' + (c.parent ? '<span class="mdo-sg-sub">in ' + esc(c.parent) + '</span>' : '') + '</span>' + (c.count ? '<span class="mdo-sg-cnt">' + c.count + '</span>' : '') + '</a>';
        }).join('');
      }
      if (d.products && d.products.length) {
        h += '<p class="mdo-sg-h">Producten</p>' + d.products.map(function (p) {
          return '<a class="mdo-sg-prod" role="option" href="' + esc(p.url) + '"><span class="mdo-sg-img">' + (p.img ? '<img src="' + esc(p.img) + '" alt="" loading="lazy">' : '') + '</span><span><span class="mdo-sg-n">' + mark(p.name, q) + '</span><span class="mdo-sg-sub">' + esc(p.price) + '</span></span></a>';
        }).join('');
      }
      if (!h) h = '<p class="mdo-sg-none">Geen suggesties voor “' + esc(q) + '”.</p>';
      h += '<a class="mdo-sg-all" role="option" href="' + esc(d.all) + '">Alle resultaten voor “' + esc(q) + '” ›</a>';
      box.innerHTML = h;
      items = [].slice.call(box.querySelectorAll('a'));
      sel = -1;
      box.hidden = false;
      inp.setAttribute('aria-expanded', 'true');
    }
    function run() {
      var q = inp.value.trim();
      if (q.length < 2) { close(); return; }
      if (q === last && !box.hidden) return;
      last = q;
      if (cache[q]) { draw(cache[q], q); return; }
      fetch(C.url + (C.url.indexOf('?') >= 0 ? '&' : '?') + 'mdo_suggest=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { cache[q] = d; if (inp.value.trim() === q) draw(d, q); })
        .catch(function () {});
    }
    inp.addEventListener('input', function () { clearTimeout(tmr); tmr = setTimeout(run, 180); });
    inp.addEventListener('focus', function () { if (inp.value.trim().length >= 2) { last = ''; run(); } });
    inp.addEventListener('keydown', function (e) {
      if (box.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach(function (a, i) { a.classList.toggle('is-sel', i === sel); });
        items[sel].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && sel >= 0) { e.preventDefault(); location.href = items[sel].href; }
      else if (e.key === 'Escape') close();
    });
    document.addEventListener('click', function (e) { if (!host.contains(e.target)) close(); });
  }
  function scan() { [].forEach.call(document.querySelectorAll('input[name="s"]'), attach); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
})();
