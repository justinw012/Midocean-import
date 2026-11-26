/* PromoFun – bedrukt voorbeeld in de winkel. Desktop: aanwijzen. Mobiel: foto vasthouden. */
(function () {
  'use strict';
  var C = window.MDO_SHOP || {};
  var logo = null, logoWait = [];

  // Logo laden; desgewenst alleen de bovenste (grootste) regel, zonder slogan.
  function getLogo(cb) {
    if (logo) return cb(logo);
    logoWait.push(cb);
    if (logoWait.length > 1) return;
    var im = new Image();
    im.onload = function () {
      var out = im;
      if (C.trimLine) {
        try {
          var c = document.createElement('canvas'); c.width = im.naturalWidth; c.height = im.naturalHeight;
          var x = c.getContext('2d'); x.drawImage(im, 0, 0);
          var d = x.getImageData(0, 0, c.width, c.height).data, rows = [], y, i;
          for (y = 0; y < c.height; y++) {
            var ink = 0;
            for (i = 0; i < c.width; i += 2) if (d[(y * c.width + i) * 4 + 3] > 24) { ink = 1; break; }
            rows.push(ink);
          }
          var blocks = [], s = -1;
          for (y = 0; y <= rows.length; y++) {
            if (rows[y] && s < 0) s = y;
            if (!rows[y] && s >= 0) { blocks.push([s, y]); s = -1; }
          }
          // Losse regels (minstens 3% hoogte gat ertussen) samenvoegen tot blokken; neem het hoogste blok.
          var merged = [];
          blocks.forEach(function (b) {
            var last = merged[merged.length - 1];
            if (last && b[0] - last[1] < c.height * 0.03) last[1] = b[1]; else merged.push(b.slice());
          });
          if (merged.length > 1) {
            merged.sort(function (a, b) { return (b[1] - b[0]) - (a[1] - a[0]); });
            var top = merged[0], h = top[1] - top[0];
            var t = document.createElement('canvas'); t.width = c.width; t.height = h;
            t.getContext('2d').drawImage(c, 0, top[0], c.width, h, 0, 0, c.width, h);
            out = trimX(t);
          }
        } catch (e) { out = im; }
      }
      logo = out;
      logoWait.forEach(function (f) { f(logo); });
    };
    im.src = C.logo;
  }
  function trimX(cv) {
    var x = cv.getContext('2d'), d = x.getImageData(0, 0, cv.width, cv.height).data, l = cv.width, r = 0;
    for (var y = 0; y < cv.height; y += 2) for (var i = 0; i < cv.width; i++) if (d[(y * cv.width + i) * 4 + 3] > 24) { if (i < l) l = i; if (i > r) r = i; }
    if (r <= l) return cv;
    var o = document.createElement('canvas'); o.width = r - l + 1; o.height = cv.height;
    o.getContext('2d').drawImage(cv, l, 0, o.width, o.height, 0, 0, o.width, o.height);
    return o;
  }

  function curveOf(id, shape) {
    id = String(id || '').toUpperCase(); shape = String(shape || '').toLowerCase();
    if (shape === 'ellipse' || shape === 'polygon' || /PEN|PENCIL/.test(id)) return 0;
    if (/360|WRAP|AROUND/.test(id)) return 78 * Math.PI / 180;
    if (/ROUND ?SCREEN|ROUNDPART/.test(id)) return 62 * Math.PI / 180;
    return 0;
  }

  // Logo in één kleur (zwart, wit of gravure-grijs) als de techniek geen kleuren kan drukken.
  var inkCache = {};
  function tinted(lg, ink) {
    if (!ink) return lg;
    if (inkCache[ink]) return inkCache[ink];
    var c = document.createElement('canvas'); c.width = lg.width || lg.naturalWidth; c.height = lg.height || lg.naturalHeight;
    var x = c.getContext('2d'); x.drawImage(lg, 0, 0);
    x.globalCompositeOperation = 'source-in'; x.fillStyle = ink; x.fillRect(0, 0, c.width, c.height);
    return (inkCache[ink] = c);
  }
  // Foto + logo samenstellen op een canvas (de foto komt van Midocean; tonen mag, opslaan hoeft niet).
  function build(data, done) {
    var photo = new Image();
    photo.onload = function () {
      getLogo(function (lg0) {
        var lg = tinted(lg0, data.ink);
        var W = photo.naturalWidth, H = photo.naturalHeight, b = data.box;
        var cv = document.createElement('canvas'); cv.width = W; cv.height = H; cv.className = 'mdo-hov-img';
        var x = cv.getContext('2d');
        x.drawImage(photo, 0, 0);
        var bw = b[2] - b[0], bh = b[3] - b[1], round = /ellipse/i.test(data.shape);
        var lw = lg.width || lg.naturalWidth, lh = lg.height || lg.naturalHeight;
        var fit = round ? 0.62 : 0.86, s = Math.min(bw * fit / lw, bh * fit / lh), aw = lw * s, ah = lh * s;
        var T = curveOf(data.id, data.shape), top = b[1] + (bh - ah) / 2;
        if (T && data.dia > 0 && data.w > 0) T = Math.min(Math.PI / 2, data.w / data.dia);
        if (!T) {
          x.drawImage(lg, b[0] + (bw - aw) / 2, top, aw, ah);
        } else {
          var sT = Math.sin(T), awN = aw / bw, a0 = 0.5 - awN / 2, sag = 0.03 * bh;
          for (var px = Math.floor(b[0]); px < Math.ceil(b[2]); px++) {
            var sv = ((px + 0.5 - b[0]) / bw - 0.5) * 2 * sT;
            if (Math.abs(sv) >= 1) continue;
            var ang = Math.asin(sv), a = 0.5 + ang / (2 * T), sx = (a - a0) / awN * lw;
            if (sx < 0 || sx >= lw) continue;
            var cs = Math.cos(ang), sw = Math.max(0.5, lw * sT / (awN * bw * T * cs));
            x.globalAlpha = 0.5 + 0.5 * cs;
            x.drawImage(lg, sx, 0, Math.min(sw, lw - sx), lh, px, top + sag * (1 - cs), 1, ah);
          }
          x.globalAlpha = 1;
        }
        done(cv);
      });
    };
    photo.onerror = function () { done(null); };
    photo.src = data.img;
  }

  function setup(mark) {
    var item = mark.closest('li.product, .product, .wc-block-grid__product') || mark.parentNode;
    var img = item && item.querySelector('img');
    if (!img || item.getAttribute('data-mdo-hov-ready')) return;
    item.setAttribute('data-mdo-hov-ready', '1');
    var data; try { data = JSON.parse(mark.getAttribute('data-mdo-hov')); } catch (e) { return; }
    // Eigen omhulsel precies om de foto, zodat titel en prijs vrij blijven.
    var wrap = document.createElement('span');
    wrap.className = 'mdo-hov-wrap';
    img.parentNode.insertBefore(wrap, img);
    wrap.appendChild(img);
    // Mobiel: een klein bedrukt plaatje rechtsonder; tik erop voor het grote voorbeeld.
    var badge = document.createElement('button');
    badge.type = 'button';
    badge.className = 'mdo-hov-badge';
    badge.setAttribute('aria-label', 'Bekijk met logo bedrukt');
    wrap.appendChild(badge);
    var cv = null, busy = false, waiting = [];
    function ready(cb) {
      if (cv) return cb(cv);
      waiting.push(cb);
      if (busy) return; busy = true;
      build(data, function (c) {
        busy = false; if (!c) { badge.remove(); return; }
        cv = c; wrap.appendChild(cv);
        thumb(c);
        waiting.forEach(function (f) { f(cv); }); waiting = [];
      });
    }
    // Uitsnede rond het drukvlak, zodat het logo in het kleine plaatje goed te zien is.
    function thumb(c) {
      var b = data.box, bw = b[2] - b[0], bh = b[3] - b[1], side = Math.min(Math.max(bw, bh) * 1.15, c.width, c.height);
      var cx = (b[0] + b[2]) / 2, cy = (b[1] + b[3]) / 2;
      var sx = Math.max(0, Math.min(c.width - side, cx - side / 2)), sy = Math.max(0, Math.min(c.height - side, cy - side / 2));
      var t = document.createElement('canvas'); t.width = t.height = 120;
      var x = t.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, 120, 120);
      x.drawImage(c, sx, sy, side, side, 0, 0, 120, 120);
      badge.appendChild(t);
      badge.classList.add('has-thumb');
    }
    function show() { ready(function () { requestAnimationFrame(function () { wrap.classList.add('mdo-hov-on'); }); }); }
    // Op telefoons het plaatje alvast maken zodra het product in beeld komt.
    if (window.matchMedia && !window.matchMedia('(hover:hover) and (pointer:fine)').matches) {
      if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (en) { if (en[0].isIntersecting) { io.disconnect(); ready(function () {}); } }, { rootMargin: '200px' });
        io.observe(wrap);
      } else ready(function () {});
    }
    function hide() { wrap.classList.remove('mdo-hov-on'); }
    // Desktop
    item.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') show(); });
    item.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') hide(); });
    // Mobiel: vasthouden toont het voorbeeld; loslaten verbergt het (en opent dan geen product).
    var timer = 0, held = false;
    wrap.addEventListener('touchstart', function () {
      held = false;
      timer = setTimeout(function () { held = true; show(); }, 350);
    }, { passive: true });
    function end() { clearTimeout(timer); if (held) setTimeout(hide, 120); }
    wrap.addEventListener('touchend', end); wrap.addEventListener('touchcancel', end);
    wrap.addEventListener('touchmove', function () { clearTimeout(timer); }, { passive: true });
    wrap.addEventListener('click', function (e) { if (held) { e.preventDefault(); e.stopPropagation(); held = false; } }, true);
    wrap.addEventListener('contextmenu', function (e) { if (held || timer) e.preventDefault(); });
    // Tikken op het rondje: voorbeeld aan/uit.
    badge.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      if (wrap.classList.contains('mdo-hov-on')) hide(); else show();
    });
  }

  // Witte productfoto's op een zachte achtergrondkleur (de foto wordt 'vermenigvuldigd', wit wordt de kleur).
  function bg(item) {
    if (item.getAttribute('data-mdo-bg')) return;
    var img = item.querySelector('img');
    if (!img) return;
    item.setAttribute('data-mdo-bg', '1');
    var p = img.parentNode;
    if (p.classList && (p.classList.contains('mdo-hov-wrap') || p.classList.contains('mdo-imgbg'))) { p.classList.add('mdo-imgbg'); return; }
    var w = document.createElement('span');
    w.className = 'mdo-imgbg';
    p.insertBefore(w, img);
    w.appendChild(img);
  }
  function scan() {
    if (C.logo) [].forEach.call(document.querySelectorAll('[data-mdo-hov]'), setup);
    if (C.bg) [].forEach.call(document.querySelectorAll('li.product, .wc-block-grid__product, .wc-block-product, .mdo-card'), bg);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
  // Producten die later geladen worden (oneindig scrollen, filters).
  if (window.MutationObserver) new MutationObserver(function () { scan(); }).observe(document.body, { childList: true, subtree: true });
})();
