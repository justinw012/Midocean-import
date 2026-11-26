/* PromoFun – filters op shop-, categorie- en zoekpagina's.
 * Eerst het aantal kiezen; daarna toont elk product de prijs per stuk bij dat aantal (incl. bedrukking
 * in 1 kleur), of er voorraad is en wanneer het ongeveer klaar is. Alles filtert direct, zonder herladen. */
(function () {
  'use strict';
  var C = window.MDO_FILTER, root = document.getElementById('mdo-filter');
  if (!C || !root || !window.fetch) return;
  root.classList.add('mdo-filter');

  var tiers = C.tiers || [50, 100, 250, 500, 1000, 2500];
  var GROUPS = C.groups || {};
  var TECH = [['fc', 'Full colour (foto, verloop)'], ['eng', 'Gravure (laser)'], ['scr', 'Zeefdruk / tampondruk'], ['emb', 'Borduren'], ['wrap', 'Rondom bedrukken (360°)']];
  var READY = [5, 10, 15, 20];
  var MONTHS = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'], DAYS = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
  var PAGE = 24;
  var rows = [], loading = true, total = 0;

  // ---------- staat (ook in de adresbalk, zodat een gefilterde pagina te delen is) ----------
  var st = { q: 250, g: {}, t: {}, max: null, ready: 0, date: '', stock: false, mat: {}, rec: false, sz: {}, gen: {}, nw: false, last: false, text: '', sort: 'rec', shown: PAGE };
  (function readUrl() {
    var p = new URLSearchParams(location.search), q;
    try { q = parseInt(localStorage.getItem('mdo_qty'), 10); } catch (e) { q = 0; }
    if (parseInt(p.get('aantal'), 10)) q = parseInt(p.get('aantal'), 10);
    if (tiers.indexOf(q) >= 0) st.q = q;
    function set(key, obj) { (p.get(key) || '').split(',').forEach(function (v) { if (v) obj[v] = 1; }); }
    set('kleur', st.g); set('druk', st.t); set('materiaal', st.mat); set('maat', st.sz); set('pasvorm', st.gen);
    st.max = parseFloat(p.get('max')) || null;
    st.ready = parseInt(p.get('klaar'), 10) || 0;
    st.date = /^\d{4}-\d{2}-\d{2}$/.test(p.get('datum') || '') ? p.get('datum') : '';
    st.stock = p.get('voorraad') === '1';
    st.rec = p.get('gerecycled') === '1';
    st.nw = p.get('nieuw') === '1';
    st.last = p.get('op') === '1';
    st.text = p.get('zoek') || '';
    st.sort = p.get('sorteer') || 'rec';
  })();
  function writeUrl() {
    var p = new URLSearchParams(location.search);
    ['aantal', 'kleur', 'druk', 'materiaal', 'maat', 'pasvorm', 'max', 'klaar', 'datum', 'voorraad', 'gerecycled', 'nieuw', 'op', 'zoek', 'sorteer', 'paged'].forEach(function (k) { p.delete(k); });
    function put(k, v) { if (v) p.set(k, v); }
    put('aantal', st.q !== 250 ? st.q : '');
    put('kleur', Object.keys(st.g).join(',')); put('druk', Object.keys(st.t).join(','));
    put('materiaal', Object.keys(st.mat).join(',')); put('maat', Object.keys(st.sz).join(',')); put('pasvorm', Object.keys(st.gen).join(','));
    put('max', st.max ? st.max.toFixed(2) : ''); put('klaar', st.ready || ''); put('datum', st.date);
    put('voorraad', st.stock ? '1' : ''); put('gerecycled', st.rec ? '1' : ''); put('nieuw', st.nw ? '1' : ''); put('op', st.last ? '1' : '');
    put('zoek', st.text); put('sorteer', st.sort !== 'rec' ? st.sort : '');
    var qs = p.toString();
    try { history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash); } catch (e) {}
    try { localStorage.setItem('mdo_qty', String(st.q)); } catch (e) {}
  }

  // ---------- hulpjes ----------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function eur(n) { return C.currency + ' ' + n.toFixed(2).replace('.', C.dec || ','); }
  function today() { var d = new Date(); d.setHours(0, 0, 0, 0); return d; }
  function parseDate(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function fmtDate(d) { return DAYS[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()]; }
  function addWorkdays(d, n) {
    d = new Date(d.getTime());
    while (d.getDay() === 0 || d.getDay() === 6) d.setDate(d.getDate() + 1);
    while (n > 0) { d.setDate(d.getDate() + 1); if (d.getDay() !== 0 && d.getDay() !== 6) n--; }
    return d;
  }
  function workdaysUntil(d) { var n = 0, x = today(); while (x < d) { x.setDate(x.getDate() + 1); if (x.getDay() !== 0 && x.getDay() !== 6) n++; } return n; }
  function readyFrom(stock, arrivals, need) {
    if (stock === null || stock === undefined || need <= stock) return null;
    var have = Math.max(0, stock);
    for (var i = 0; i < (arrivals || []).length; i++) { have += arrivals[i][1]; if (have >= need) return parseDate(arrivals[i][0]); }
    return false;
  }
  function count(o) { return Object.keys(o).length; }
  function ti() { var i = tiers.indexOf(st.q); return i < 0 ? 0 : i; }
  function isNew(r) {
    if (!C.newDays || !r.rel) return false;
    var d = parseDate(r.rel), now = today();
    return d > new Date(now.getTime() - C.newDays * 864e5) && d <= new Date(now.getTime() + 30 * 864e5);
  }
  function matLabel(m) { m = String(m || '').trim(); return m ? m.charAt(0).toUpperCase() + m.slice(1).toLowerCase() : ''; }

  // ---------- per product, bij het gekozen aantal ----------
  var memo = {};
  function colours(r) { var c = r.c || []; return count(st.g) ? c.filter(function (x) { return st.g[x.g]; }) : c; }
  function calc(r) {
    var key = r.id + '|' + st.q + '|' + Object.keys(st.g).join(',');
    if (memo[key]) return memo[key];
    var out = { price: null, date: null, wait: null, now: false, col: null };
    if (r.x || !r.c) return (memo[key] = out);
    var i = ti(), base = r.pr[i], best = null, now0 = today();
    colours(r).forEach(function (c) {
      var p = (base + (c.p ? c.p[i] : 0)) * (r.tx || 1);
      if (out.price === null || p < out.price) out.price = p;
      var rf = readyFrom(c.s, c.a, st.q);
      if (rf === false) return;
      var d = addWorkdays(rf && rf > now0 ? rf : now0, r.ld[i]);
      if (!best || d < best.d) best = { d: d, rf: rf, c: c };
      if (rf === null) out.now = true;
    });
    if (best) { out.date = best.d; out.wait = best.rf; out.col = best.c; }
    return (memo[key] = out);
  }

  // ---------- filters: elke groep is een test; aantallen per optie tellen alle andere filters mee ----------
  var tests = {
    text: function (r) { if (!st.text) return true; var t = st.text.toLowerCase(); return (r.n + ' ' + (r.sku || '')).toLowerCase().indexOf(t) >= 0; },
    g: function (r) { return !count(st.g) || (r.c || []).some(function (c) { return st.g[c.g]; }); },
    t: function (r) { return !count(st.t) || Object.keys(st.t).some(function (k) { return r.t && r.t[k]; }); },
    max: function (r) { if (!st.max) return true; var p = calc(r).price; return p !== null && p <= st.max + 0.004; },
    ready: function (r) {
      if (!st.ready && !st.date) return true;
      var d = calc(r).date; if (!d) return false;
      if (st.ready && workdaysUntil(d) > st.ready) return false;
      if (st.date && d > parseDate(st.date)) return false;
      return true;
    },
    stock: function (r) { return !st.stock || calc(r).now; },
    mat: function (r) { return !count(st.mat) || !!st.mat[matLabel(r.mat)]; },
    rec: function (r) { return !st.rec || !!r.rec; },
    sz: function (r) { return !count(st.sz) || (r.sz || []).some(function (s) { return st.sz[s]; }); },
    gen: function (r) { return !count(st.gen) || !!st.gen[r.gen]; },
    nw: function (r) { return !st.nw || isNew(r); },
    last: function (r) { return !st.last || !!r.last; }
  };
  function pass(r, skip) { for (var k in tests) if (k !== skip && !tests[k](r)) return false; return true; }
  function subset(skip) { return rows.filter(function (r) { return pass(r, skip); }); }

  function sorted(list) {
    var l = list.slice();
    if (st.sort === 'price') l.sort(function (a, b) { var x = calc(a).price, y = calc(b).price; return (x === null) - (y === null) || x - y; });
    else if (st.sort === 'fast') l.sort(function (a, b) { var x = calc(a).date, y = calc(b).date; return (!x) - (!y) || (x && y ? x - y : 0); });
    else if (st.sort === 'new') l.sort(function (a, b) { return String(b.rel || '').localeCompare(String(a.rel || '')); });
    else if (st.sort === 'name') l.sort(function (a, b) { return a.n.localeCompare(b.n, 'nl'); });
    return l;
  }

  // ---------- opbouw ----------
  function chip(act, val, label, on, n, extra) {
    return '<button type="button" class="mdo-chip' + (on ? ' is-on' : '') + '" data-act="' + act + '" data-v="' + esc(val) + '" aria-pressed="' + (on ? 'true' : 'false') + '"' + (n === 0 && !on ? ' disabled' : '') + (extra || '') + '>' + label + (n !== undefined ? ' <span class="mdo-n">' + n + '</span>' : '') + '</button>';
  }
  function check(act, val, label, on, n) {
    return '<label class="mdo-check' + (n === 0 && !on ? ' is-off' : '') + '"><input type="checkbox" data-act="' + act + '" data-v="' + esc(val) + '"' + (on ? ' checked' : '') + (n === 0 && !on ? ' disabled' : '') + '> <span>' + label + '</span>' + (n !== undefined ? ' <span class="mdo-n">' + n + '</span>' : '') + '</label>';
  }
  function grp(title, body, open) { return body ? '<details class="mdo-fg"' + (open === false ? '' : ' open') + '><summary>' + title + '</summary><div class="mdo-fg-b">' + body + '</div></details>' : ''; }

  function sideHtml() {
    var h = '', list, i, sum;
    // Klaar vóór
    list = subset('ready');
    h += grp('Klaar binnen', '<div class="mdo-chips">' + READY.map(function (n) {
      var c = list.filter(function (r) { var d = calc(r).date; return d && workdaysUntil(d) <= n; }).length;
      return chip('ready', n, n + ' werkdagen', st.ready === n, c);
    }).join('') + '</div><label class="mdo-date">of klaar vóór <input type="date" data-act="date" value="' + esc(st.date) + '" min="' + new Date().toISOString().slice(0, 10) + '"></label><p class="mdo-hint">Indicatie, gerekend vanaf goedkeuring van de drukproef.</p>');
    // Voorraad
    list = subset('stock');
    h += grp('Voorraad', check('stock', 1, 'Direct op voorraad voor ' + st.q.toLocaleString('nl-NL') + ' stuks', st.stock, list.filter(function (r) { return calc(r).now; }).length));
    // Budget
    list = subset('max');
    var ps = list.map(function (r) { return calc(r).price; }).filter(function (p) { return p !== null; });
    if (ps.length > 1) {
      var lo = Math.ceil(Math.min.apply(null, ps) * 20) / 20, hi = Math.ceil(Math.max.apply(null, ps) * 20) / 20, cur = st.max ? Math.min(st.max, hi) : hi;
      h += grp('Budget per stuk', '<input type="range" class="mdo-range" data-act="max" min="' + lo + '" max="' + hi + '" step="0.05" value="' + cur + '" aria-label="Maximale prijs per stuk"><div class="mdo-range-l"><span>' + eur(lo) + '</span><span>tot <b data-out="max">' + eur(cur) + '</b></span></div><p class="mdo-hint">Per stuk bij ' + st.q.toLocaleString('nl-NL') + ' stuks, incl. bedrukking' + (C.taxLabel ? ', ' + esc(C.taxLabel) : '') + '.</p>');
    }
    // Kleur
    list = subset('g');
    var gc = {};
    list.forEach(function (r) { var seen = {}; (r.c || []).forEach(function (c) { if (!seen[c.g]) { seen[c.g] = 1; gc[c.g] = (gc[c.g] || 0) + 1; } }); });
    sum = Object.keys(GROUPS).filter(function (k) { return gc[k] || st.g[k]; }).map(function (k) {
      var g = GROUPS[k], cls = k === 'transparant' ? ' is-clear' : (k === 'multi' ? ' is-multi' : '');
      return '<button type="button" class="mdo-sw' + cls + (st.g[k] ? ' is-on' : '') + '" data-act="g" data-v="' + k + '" aria-pressed="' + (st.g[k] ? 'true' : 'false') + '" title="' + esc(g[0]) + ' (' + (gc[k] || 0) + ')"' + (g[1] ? ' style="--sw:' + g[1] + '"' : '') + '><span class="mdo-sw-dot"></span><span class="mdo-sw-l">' + esc(g[0]) + '</span></button>';
    }).join('');
    h += grp('Kleur product', sum ? '<div class="mdo-sws">' + sum + '</div>' : '');
    // Bedrukking
    list = subset('t');
    h += grp('Soort bedrukking', TECH.map(function (t) {
      var c = list.filter(function (r) { return r.t && r.t[t[0]]; }).length;
      return (c || st.t[t[0]]) ? check('t', t[0], t[1], !!st.t[t[0]], c) : '';
    }).join(''));
    // Kleding
    list = subset('sz');
    var sizes = [];
    list.forEach(function (r) { (r.sz || []).forEach(function (s) { if (sizes.indexOf(s) < 0) sizes.push(s); }); });
    if (sizes.length || count(st.sz)) {
      var ord = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', '3XL', '4XL', '5XL'];
      sizes.sort(function (a, b) { var x = ord.indexOf(a), y = ord.indexOf(b); if (x >= 0 && y >= 0) return x - y; if (x >= 0) return -1; if (y >= 0) return 1; return (parseInt(a, 10) || 0) - (parseInt(b, 10) || 0) || a.localeCompare(b); });
      h += grp('Maat', '<div class="mdo-chips">' + sizes.map(function (s) { return chip('sz', s, esc(s), !!st.sz[s], list.filter(function (r) { return (r.sz || []).indexOf(s) >= 0; }).length); }).join('') + '</div>');
    }
    list = subset('gen');
    var gens = {};
    list.forEach(function (r) { if (r.gen) gens[r.gen] = (gens[r.gen] || 0) + 1; });
    if (Object.keys(gens).length > 1 || count(st.gen)) {
      var gl = { MEN: 'Heren', WOMEN: 'Dames', LADIES: 'Dames', UNISEX: 'Unisex', KIDS: 'Kinderen', CHILDREN: 'Kinderen', BABY: "Baby's" };
      h += grp('Pasvorm', Object.keys(gens).sort().map(function (g) { return check('gen', g, esc(gl[String(g).toUpperCase()] || g), !!st.gen[g], gens[g]); }).join(''));
    }
    // Materiaal
    list = subset('mat');
    var mats = {};
    list.forEach(function (r) { var m = matLabel(r.mat); if (m) mats[m] = (mats[m] || 0) + 1; });
    var top = Object.keys(mats).sort(function (a, b) { return mats[b] - mats[a]; }).slice(0, 10);
    Object.keys(st.mat).forEach(function (m) { if (top.indexOf(m) < 0) top.push(m); });
    if (top.length > 1) h += grp('Materiaal', top.map(function (m) { return check('mat', m, esc(m), !!st.mat[m], mats[m] || 0); }).join(''), false);
    // Overig
    var o = '';
    list = subset('rec'); i = list.filter(function (r) { return r.rec; }).length;
    if (i || st.rec) o += check('rec', 1, 'Gerecycled materiaal', st.rec, i);
    list = subset('nw'); i = list.filter(isNew).length;
    if (i || st.nw) o += check('nw', 1, 'Nieuw', st.nw, i);
    list = subset('last'); i = list.filter(function (r) { return r.last; }).length;
    if (i || st.last) o += check('last', 1, 'Op = op', st.last, i);
    h += grp('Overig', o);
    return h;
  }

  function activeChips() {
    var a = [];
    Object.keys(st.g).forEach(function (k) { a.push(['g', k, (GROUPS[k] || [k])[0]]); });
    Object.keys(st.t).forEach(function (k) { TECH.forEach(function (t) { if (t[0] === k) a.push(['t', k, t[1].replace(/ \(.*\)/, '')]); }); });
    if (st.ready) a.push(['ready', st.ready, 'Binnen ' + st.ready + ' werkdagen']);
    if (st.date) a.push(['date', '', 'Klaar vóór ' + fmtDate(parseDate(st.date))]);
    if (st.max) a.push(['max', '', 'Tot ' + eur(st.max)]);
    if (st.stock) a.push(['stock', 1, 'Op voorraad']);
    Object.keys(st.sz).forEach(function (k) { a.push(['sz', k, 'Maat ' + k]); });
    Object.keys(st.gen).forEach(function (k) { a.push(['gen', k, k]); });
    Object.keys(st.mat).forEach(function (k) { a.push(['mat', k, k]); });
    if (st.rec) a.push(['rec', 1, 'Gerecycled']);
    if (st.nw) a.push(['nw', 1, 'Nieuw']);
    if (st.last) a.push(['last', 1, 'Op = op']);
    if (st.text) a.push(['text', '', '“' + st.text + '”']);
    return a;
  }

  function card(r) {
    var k = calc(r), p = '', d = '', img = r.img, code = '', hov = r.hov;
    if (count(st.g) && r.c) {
      var m = k.col || colours(r)[0];
      if (m && m.i) { img = m.i; hov = null; }
      if (m) code = m.k;
    } else if (k.col) code = k.col.k;
    if (r.x) p = '<span class="mdo-card-p">' + esc(r.ph) + '</span>';
    else if (k.price !== null) p = '<span class="mdo-card-p"><b>' + eur(k.price) + '</b> <span>p/st bij ' + st.q.toLocaleString('nl-NL') + ' st.' + (r.pp ? '' : ' (zonder bedrukking)') + '</span></span>';
    if (!r.x) {
      if (!k.date) d = '<span class="mdo-card-d is-unk">Levertijd op aanvraag</span>';
      else if (k.wait) d = '<span class="mdo-card-d is-wait">Nieuwe voorraad · klaar rond ' + fmtDate(k.date) + '</span>';
      else d = '<span class="mdo-card-d is-ok">Op voorraad · klaar rond ' + fmtDate(k.date) + '</span>';
    }
    var dots = '', seen = {}, n = 0;
    (r.c || []).forEach(function (c) {
      if (seen[c.g]) return; seen[c.g] = 1; n++;
      if (n > 8) return;
      var g = GROUPS[c.g] || ['', ''];
      dots += '<span class="mdo-dot' + (c.g === 'transparant' ? ' is-clear' : c.g === 'multi' ? ' is-multi' : '') + '" title="' + esc(g[0]) + '"' + (g[1] ? ' style="background:' + g[1] + '"' : '') + '></span>';
    });
    if (n > 8) dots += '<span class="mdo-dot-more">+' + (n - 8) + '</span>';
    var nc = (r.c || []).length;
    var badges = (isNew(r) ? '<span class="mdo-badge mdo-badge-new">Nieuw</span>' : '') + (r.last ? '<span class="mdo-badge mdo-badge-last">Op = op</span>' : '');
    var url = r.u + (r.u.indexOf('?') >= 0 ? '&' : '?') + 'aantal=' + st.q + (code ? '&kleur=' + encodeURIComponent(code) : '');
    return '<li class="product mdo-card"><a class="mdo-card-a" href="' + esc(url) + '">' +
      '<span class="mdo-card-img"><img src="' + esc(img) + '" alt="' + esc(r.n) + '" loading="lazy" decoding="async">' + (badges ? '<span class="mdo-badges">' + badges + '</span>' : '') + '</span>' +
      '<span class="mdo-card-n">' + esc(r.n) + '</span>' +
      (dots ? '<span class="mdo-dots" aria-label="' + nc + ' kleuren">' + dots + '</span>' : '') + p + d + '</a>' +
      (hov ? '<span class="mdo-hov" hidden data-mdo-hov="' + esc(JSON.stringify(hov)) + '"></span>' : '') + '</li>';
  }

  function mainHtml() {
    var list = sorted(subset(null)), act = activeChips(), nf = act.length;
    var h = '<div class="mdo-f-bar">' +
      '<button type="button" class="mdo-f-open" data-act="open">Filters' + (nf ? ' (' + nf + ')' : '') + '</button>' +
      '<b class="mdo-f-count">' + list.length + (list.length === 1 ? ' product' : ' producten') + '</b>' +
      '<label class="mdo-f-search"><span class="screen-reader-text">Zoek in deze producten</span><input type="search" data-act="text" value="' + esc(st.text) + '" placeholder="Zoek in deze producten"></label>' +
      '<label class="mdo-f-sort">Sorteer <select data-act="sort">' +
      [['rec', 'Aanbevolen'], ['price', 'Laagste prijs bij ' + st.q.toLocaleString('nl-NL') + ' st.'], ['fast', 'Snelst klaar'], ['new', 'Nieuwste'], ['name', 'Naam']].map(function (o) { return '<option value="' + o[0] + '"' + (st.sort === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') +
      '</select></label></div>';
    if (nf) h += '<div class="mdo-f-active">' + act.map(function (a) { return '<button type="button" class="mdo-tag" data-act="un-' + a[0] + '" data-v="' + esc(a[1]) + '">' + esc(a[2]) + ' <span aria-hidden="true">×</span><span class="screen-reader-text"> verwijderen</span></button>'; }).join('') + '<button type="button" class="mdo-linkbtn" data-act="clear">Wis alles</button></div>';
    if (loading && !rows.length) return h + '<p class="mdo-f-loading">Producten laden…</p>';
    if (!list.length) return h + emptyHtml();
    h += '<ul class="products mdo-grid">' + list.slice(0, st.shown).map(card).join('') + '</ul>';
    if (list.length > st.shown) h += '<p class="mdo-more"><button type="button" class="mdo-btn" data-act="more">Toon meer (' + (list.length - st.shown) + ')</button></p>';
    if (loading) h += '<p class="mdo-f-loading">Nog meer producten laden…</p>';
    return h;
  }

  // Niets gevonden: laat zien welk filter het verschil maakt.
  function emptyHtml() {
    var tips = [];
    activeChips().forEach(function (a) {
      var key = a[0], save = JSON.stringify(st);
      unset(key, String(a[1]));
      memo = {};
      var n = subset(null).length;
      var back = JSON.parse(save); Object.keys(back).forEach(function (k) { st[k] = back[k]; });
      memo = {};
      if (n) tips.push('<button type="button" class="mdo-tag" data-act="un-' + key + '" data-v="' + esc(a[1]) + '">Zonder “' + esc(a[2]) + '”: ' + n + ' producten</button>');
    });
    return '<div class="mdo-empty"><p><b>Geen producten met deze keuzes.</b></p>' + (tips.length ? '<div class="mdo-f-active">' + tips.join('') + '</div>' : '') + '</div>';
  }

  function qtyHtml() {
    return '<div class="mdo-f-qty"><b id="mdo-q-l">Hoeveel stuks heb je nodig?</b><div class="mdo-chips" role="group" aria-labelledby="mdo-q-l">' +
      tiers.map(function (q) { return chip('q', q, q.toLocaleString('nl-NL'), st.q === q); }).join('') +
      '</div><span class="mdo-f-qnote">Prijzen, voorraad en levertijd gelden voor ' + st.q.toLocaleString('nl-NL') + ' stuks, incl. bedrukking in 1 kleur' + (C.taxLabel ? ', ' + esc(C.taxLabel) : '') + '.</span></div>';
  }
  function subsHtml() {
    if (!C.subs || !C.subs.length) return '';
    return '<nav class="mdo-f-subs" aria-label="Subcategorieën">' + C.subs.map(function (s) {
      var n = rows.filter(function (r) { return (r.cats || []).indexOf(s.id) >= 0 && pass(r, null); }).length;
      return '<a class="mdo-chip" href="' + esc(s.url) + '">' + esc(s.name) + (rows.length ? ' <span class="mdo-n">' + n + '</span>' : '') + '</a>';
    }).join('') + '</nav>';
  }

  var built = false;
  function render(part) {
    memo = part === 'main' ? memo : {};
    if (!built) {
      root.innerHTML = '<div class="mdo-f">' + '<div data-slot="qty"></div><div data-slot="subs"></div>' +
        '<div class="mdo-f-body"><aside class="mdo-f-side" aria-label="Filters"><div class="mdo-f-head"><b>Filters</b><button type="button" class="mdo-linkbtn" data-act="clear">Wis alles</button><button type="button" class="mdo-f-x" data-act="close" aria-label="Filters sluiten">×</button></div><div data-slot="side"></div><div class="mdo-f-foot"><button type="button" class="mdo-btn" data-act="close" data-slot="show"></button></div></aside>' +
        '<div class="mdo-f-main" data-slot="main"></div></div></div>';
      built = true;
    }
    var q = function (s) { return root.querySelector('[data-slot="' + s + '"]'); };
    if (part !== 'main') {
      q('qty').innerHTML = qtyHtml();
      q('subs').innerHTML = subsHtml();
      q('side').innerHTML = sideHtml();
    }
    q('main').innerHTML = mainHtml();
    var n = subset(null).length;
    q('show').textContent = 'Toon ' + n + (n === 1 ? ' product' : ' producten');
    writeUrl();
  }
  function update() { st.shown = PAGE; render(); }

  function unset(k, v) {
    if (k === 'g' || k === 't' || k === 'sz' || k === 'gen' || k === 'mat') delete st[k][v];
    else if (k === 'ready') st.ready = 0;
    else if (k === 'date') st.date = '';
    else if (k === 'max') st.max = null;
    else if (k === 'text') st.text = '';
    else st[k] = false;
  }
  function toggle(obj, v) { if (obj[v]) delete obj[v]; else obj[v] = 1; }

  // ---------- bediening ----------
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b || b.tagName === 'INPUT' || b.tagName === 'SELECT') return;
    var a = b.getAttribute('data-act'), v = b.getAttribute('data-v');
    if (a === 'q') { st.q = parseInt(v, 10); st.max = null; update(); return; }
    if (a === 'g' || a === 't' || a === 'sz') { toggle(st[a], v); update(); return; }
    if (a === 'ready') { st.ready = st.ready === +v ? 0 : +v; st.date = ''; update(); return; }
    if (a === 'more') { st.shown += PAGE * 2; render('main'); return; }
    if (a === 'clear') { st.g = {}; st.t = {}; st.sz = {}; st.gen = {}; st.mat = {}; st.max = null; st.ready = 0; st.date = ''; st.stock = st.rec = st.nw = st.last = false; st.text = ''; update(); return; }
    if (a.indexOf('un-') === 0) { unset(a.slice(3), v); update(); return; }
    if (a === 'open') { root.classList.add('is-open'); document.documentElement.classList.add('mdo-f-lock'); var x = root.querySelector('.mdo-f-x'); if (x) x.focus(); return; }
    if (a === 'close') { root.classList.remove('is-open'); document.documentElement.classList.remove('mdo-f-lock'); var o = root.querySelector('.mdo-f-open'); if (o && window.matchMedia('(max-width: 900px)').matches) o.focus(); return; }
  });
  root.addEventListener('change', function (e) {
    var t = e.target, a = t.getAttribute('data-act'), v = t.getAttribute('data-v');
    if (!a) return;
    if (a === 't' || a === 'mat' || a === 'gen') { toggle(st[a], v); update(); }
    else if (a === 'stock' || a === 'rec' || a === 'nw' || a === 'last') { st[a] = t.checked; update(); }
    else if (a === 'date') { st.date = t.value; if (st.date) st.ready = 0; update(); }
    else if (a === 'sort') { st.sort = t.value; render('main'); }
    else if (a === 'max') { st.max = parseFloat(t.value); update(); }
  });
  var tmr = 0;
  root.addEventListener('input', function (e) {
    var t = e.target, a = t.getAttribute('data-act');
    if (a === 'max') {
      st.max = parseFloat(t.value);
      var o = root.querySelector('[data-out="max"]'); if (o) o.textContent = eur(st.max);
      clearTimeout(tmr); tmr = setTimeout(function () { st.shown = PAGE; render('main'); }, 60);
    } else if (a === 'text') {
      st.text = t.value.trim();
      clearTimeout(tmr);
      tmr = setTimeout(function () {
        st.shown = PAGE;
        var pos = t.selectionStart;
        render();
        var n = root.querySelector('input[data-act="text"]'); if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (x) {} }
      }, 200);
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && root.classList.contains('is-open')) { root.classList.remove('is-open'); document.documentElement.classList.remove('mdo-f-lock'); } });

  // ---------- gegevens ophalen (grote categorieën in delen, tot alles binnen is) ----------
  function load(tries) {
    var u = C.ajax + '?action=mdo_filter&cat=' + (C.cat || 0) + '&tag=' + (C.tag || 0) + '&s=' + encodeURIComponent(C.s || '');
    fetch(u, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }).then(function (d) {
      rows = d.rows || []; total = d.total || rows.length; loading = !!d.more;
      document.body.classList.add('mdo-filter-on');
      render();
      if (d.more && tries < 40) setTimeout(function () { load(tries + 1); }, 50);
    }).catch(function () {
      // Lukt het niet, dan blijft de gewone productlijst van het thema staan.
      root.innerHTML = '';
      document.body.classList.remove('mdo-filter-on');
    });
  }
  render();
  load(0);
})();
