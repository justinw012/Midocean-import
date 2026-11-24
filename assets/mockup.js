/* PromoFun – Mockup maken (beheer). Alles draait in de browser; het logo wordt niet geüpload. */
(function () {
  'use strict';
  var C = window.MDO_MOCK || {};
  var $ = function (id) { return document.getElementById(id); };
  var cv = $('mdo-mock-canvas'); if (!cv) return;
  var ctx = cv.getContext('2d');

  var FONTS = {
    poppins: ['Poppins', '"MDO Poppins", Arial, sans-serif'],
    montserrat: ['Montserrat', '"MDO Montserrat", Arial, sans-serif'],
    opensans: ['Open Sans', '"MDO Open Sans", Arial, sans-serif'],
    oswald: ['Oswald', '"MDO Oswald", "Arial Narrow", sans-serif'],
    anton: ['Anton', '"MDO Anton", Impact, sans-serif'],
    bebasneue: ['Bebas Neue', '"MDO Bebas Neue", Impact, sans-serif'],
    robotoslab: ['Roboto Slab', '"MDO Roboto Slab", Georgia, serif'],
    playfair: ['Playfair Display', '"MDO Playfair Display", Georgia, serif'],
    courierprime: ['Courier Prime', '"MDO Courier Prime", "Courier New", monospace'],
    pacifico: ['Pacifico', '"MDO Pacifico", cursive'],
    lobster: ['Lobster', '"MDO Lobster", cursive'],
    dancing: ['Dancing Script', '"MDO Dancing Script", cursive'],
    marker: ['Permanent Marker', '"MDO Permanent Marker", cursive']
  };

  var st = {
    prod: null, color: 0, photo: null, photoImg: null,
    mode: 'logo', logo: null, text: 'Jouw tekst', font: 'poppins', ink: '#1e1e1e',
    size: 35, rot: 0, curve: 0, arc: 3, opacity: 95, blend: true, effect: false, showBox: true,
    cx: 0.5, cy: 0.5
  };

  function curveFor(id) {
    id = String(id || '').toUpperCase();
    if (/PEN|PENCIL/.test(id)) return 0;
    if (/360|WRAP|AROUND/.test(id)) return 78;
    if (/ROUND ?SCREEN|ROUNDPART/.test(id)) return 62;
    return 0;
  }
  function get(action, params) {
    var q = Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(C.ajax + '?action=' + action + '&nonce=' + encodeURIComponent(C.nonce) + '&' + q, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  function msg(t) { $('mdo-mock-msg').textContent = t || ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  // ---------- product zoeken ----------
  var timer;
  $('mdo-mock-q').addEventListener('input', function () {
    clearTimeout(timer);
    var q = this.value.trim();
    timer = setTimeout(function () {
      if (q.length < 2) { $('mdo-mock-results').innerHTML = ''; return; }
      get('mdo_mock_search', { q: q }).then(function (r) {
        var list = (r && r.success && r.data) || [];
        $('mdo-mock-results').innerHTML = list.length ? list.map(function (p) {
          return '<button type="button" class="mdo-mock-hit" data-id="' + p.id + '">' + (p.thumb ? '<img src="' + esc(p.thumb) + '" alt="">' : '') + '<span>' + esc(p.name) + '<small>' + esc(p.code) + '</small></span></button>';
        }).join('') : '<p class="description">Geen producten gevonden.</p>';
      });
    }, 300);
  });
  $('mdo-mock-results').addEventListener('click', function (e) {
    var b = e.target.closest('[data-id]'); if (!b) return;
    msg('Product laden…');
    get('mdo_mock_product', { id: b.getAttribute('data-id') }).then(function (r) {
      if (!r || !r.success) { msg('Dit product kon niet geladen worden.'); return; }
      st.prod = r.data; st.color = 0;
      $('mdo-mock-results').innerHTML = '';
      $('mdo-mock-form').hidden = false;
      $('mdo-mock-name').textContent = st.prod.name;
      $('mdo-mock-dia').value = st.prod.diameter > 0 ? Math.round(st.prod.diameter) : '';
      $('mdo-mock-color').innerHTML = st.prod.colors.map(function (c, i) { return '<option value="' + i + '">' + esc(c.name) + '</option>'; }).join('');
      photos();
    });
  });
  $('mdo-mock-color').addEventListener('change', function () { st.color = +this.value; photos(); });

  // Foto's: eerst de drukposities (met drukvlak), dan de gewone productfoto's.
  function photoList() {
    var c = st.prod.colors[st.color], out = [];
    st.prod.positions.forEach(function (p) {
      var u = p.images[c.code];
      if (!u) Object.keys(p.images).forEach(function (k) { if (!u && k && new RegExp('-' + k + '(-|$)', 'i').test(c.sku)) u = p.images[k]; });
      if (u) out.push({ url: u, label: p.label, box: p.box ? p.box.slice() : null, curve: curveFor(p.id), w: p.w || 0 });
    });
    c.photos.forEach(function (u, i) { out.push({ url: u, label: 'Foto ' + (i + 1), box: null, curve: 0 }); });
    return out;
  }
  function photos() {
    var list = photoList();
    st.list = list;
    $('mdo-mock-photos').innerHTML = list.map(function (p, i) {
      return '<button type="button" class="mdo-mock-ph" data-i="' + i + '" title="' + esc(p.label) + '"><img src="' + esc(p.url) + '" alt=""><span>' + esc(p.label) + '</span></button>';
    }).join('') || '<p class="description">Geen foto\'s voor deze kleur.</p>';
    if (list.length) pick(0);
  }
  $('mdo-mock-photos').addEventListener('click', function (e) { var b = e.target.closest('[data-i]'); if (b) pick(+b.getAttribute('data-i')); });

  function pick(i) {
    var p = st.list[i];
    [].forEach.call(document.querySelectorAll('.mdo-mock-ph'), function (b, j) { b.classList.toggle('is-on', j === i); });
    msg('Foto laden…');
    var im = new Image();
    im.onload = function () {
      st.photo = p; st.photoImg = im;
      cv.width = im.naturalWidth; cv.height = im.naturalHeight;
      if (!p.box) { var W = im.naturalWidth, H = im.naturalHeight; p.box = [W * 0.3, H * 0.35, W * 0.7, H * 0.6]; }
      st.curve = autoCurve(p); $('mdo-mock-curve').value = st.curve;
      st.cx = 0.5; st.cy = 0.5;
      msg('');
      draw();
    };
    im.onerror = function () { msg('Deze foto kon niet geladen worden.'); };
    im.src = p.url;
  }

  // Kromming uit de diameter: een drukvlak van w mm op een cilinder met diameter D beslaat
  // aan weerszijden w/D radialen. Zonder bekende drukbreedte (gewone foto): zet het vlak van rand
  // tot rand over het product, dan is het precies een halve omtrek (90°).
  function autoCurve(p) {
    var D = parseFloat($('mdo-mock-dia').value) || 0;
    var hint = $('mdo-mock-curve-hint');
    if (D > 0 && (p.curve > 0 || !p.w)) {
      var deg = p.w ? Math.min(90, p.w / D * 180 / Math.PI) : 90;
      hint.textContent = p.w ? 'Berekend: drukvlak ' + p.w + ' mm op Ø ' + D + ' mm = ' + Math.round(deg) + '° naar elke kant.' : 'Berekend voor Ø ' + D + ' mm: zet het drukvlak van rand tot rand over het product.';
      return Math.round(deg);
    }
    hint.textContent = '0 = plat. Vul de diameter in, dan wordt de kromming exact berekend.';
    return p.curve;
  }
  $('mdo-mock-dia').addEventListener('input', function () { if (st.photo) { st.curve = autoCurve(st.photo); $('mdo-mock-curve').value = st.curve; draw(); } });

  // ---------- invoer ----------
  [].forEach.call(document.querySelectorAll('.mdo-mock-tabs [data-mode]'), function (b) {
    b.addEventListener('click', function () {
      st.mode = b.getAttribute('data-mode');
      [].forEach.call(document.querySelectorAll('.mdo-mock-tabs [data-mode]'), function (x) { x.classList.toggle('is-on', x === b); });
      [].forEach.call(document.querySelectorAll('[data-show]'), function (x) { x.hidden = x.getAttribute('data-show') !== st.mode; });
      draw();
    });
  });
  $('mdo-mock-font').innerHTML = Object.keys(FONTS).map(function (k) { return '<option value="' + k + '" style="font-family:' + esc(FONTS[k][1]) + '">' + esc(FONTS[k][0]) + '</option>'; }).join('');
  $('mdo-mock-file').addEventListener('change', function () {
    var f = this.files && this.files[0]; if (!f) return;
    var im = new Image();
    im.onload = function () { st.logo = im; draw(); };
    im.onerror = function () { msg('Dit bestand kan niet getoond worden. Gebruik PNG, JPG of SVG.'); };
    im.src = URL.createObjectURL(f);
  });
  function bind(id, key, num) {
    $(id).addEventListener('input', function () { st[key] = this.type === 'checkbox' ? this.checked : (num ? +this.value : this.value); draw(); });
  }
  bind('mdo-mock-text', 'text'); bind('mdo-mock-ink', 'ink'); bind('mdo-mock-size', 'size', 1); bind('mdo-mock-curve', 'curve', 1);
  bind('mdo-mock-arc', 'arc', 1); bind('mdo-mock-opacity', 'opacity', 1);
  bind('mdo-mock-blend', 'blend'); bind('mdo-mock-effect', 'effect'); bind('mdo-mock-box', 'showBox');
  $('mdo-mock-font').addEventListener('change', function () {
    st.font = this.value;
    if (document.fonts && document.fonts.load) document.fonts.load('700 100px ' + FONTS[st.font][1]).then(draw, draw); else draw();
  });
  [].forEach.call(document.querySelectorAll('.mdo-mock-rot [data-rot]'), function (b) {
    b.addEventListener('click', function () {
      st.rot = +b.getAttribute('data-rot');
      [].forEach.call(document.querySelectorAll('.mdo-mock-rot [data-rot]'), function (x) { x.classList.toggle('is-on', x === b); });
      draw();
    });
  });
  $('mdo-mock-reset').addEventListener('click', function () { st.cx = 0.5; st.cy = 0.5; draw(); });

  // ---------- tekenen ----------
  // Ontwerp zonder vervorming, inclusief draaien. Geeft een canvas of null.
  function artCanvas(boxW) {
    var src, aw, ah, pad = 0;
    if (st.mode === 'text') {
      var t = st.text || ' ', m = document.createElement('canvas').getContext('2d');
      m.font = '700 200px ' + FONTS[st.font][1];
      aw = Math.ceil(m.measureText(t).width) + 20; ah = 260;
      src = document.createElement('canvas'); src.width = aw; src.height = ah;
      var c = src.getContext('2d');
      c.font = m.font; c.fillStyle = st.ink; c.textAlign = 'center'; c.textBaseline = 'middle';
      c.fillText(t, aw / 2, ah / 2 + 8);
    } else {
      if (!st.logo) return null;
      src = st.logo; aw = st.logo.naturalWidth || 400; ah = st.logo.naturalHeight || 200;
    }
    var vertical = st.rot === 90 || st.rot === 270;
    var outW = boxW * st.size / 100, scale = outW / (vertical ? ah : aw), outH = (vertical ? aw : ah) * scale;
    var o = document.createElement('canvas');
    o.width = Math.max(1, Math.round(outW)); o.height = Math.max(1, Math.round(outH));
    var oc = o.getContext('2d');
    oc.translate(o.width / 2, o.height / 2);
    oc.rotate(st.rot * Math.PI / 180);
    oc.drawImage(src, -aw * scale / 2, -ah * scale / 2, aw * scale, ah * scale);
    return o;
  }
  function regionDark(b) {
    try {
      var d = ctx.getImageData(Math.round(b[0]), Math.round(b[1]), Math.max(1, Math.round(b[2] - b[0])), Math.max(1, Math.round(b[3] - b[1]))).data, sum = 0, n = 0;
      for (var i = 0; i < d.length; i += 16) { sum += 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]; n++; }
      return n ? sum / n < 110 : false;
    } catch (e) { return false; }
  }
  function render(withBox) {
    if (!st.photoImg) return;
    var W = cv.width, H = cv.height, b = st.photo.box, bw = b[2] - b[0], bh = b[3] - b[1];
    ctx.globalAlpha = 1; ctx.globalCompositeOperation = 'source-over'; ctx.filter = 'none';
    ctx.clearRect(0, 0, W, H);
    ctx.drawImage(st.photoImg, 0, 0, W, H);
    var dark = regionDark(b);
    var art = artCanvas(bw);
    if (art) {
      var layer = document.createElement('canvas'); layer.width = W; layer.height = H;
      var lc = layer.getContext('2d');
      var T = st.curve * Math.PI / 180, ah = art.height, top = b[1] + st.cy * bh - ah / 2;
      if (T < 0.02) {
        lc.drawImage(art, b[0] + st.cx * bw - art.width / 2, top);
      } else {
        // Kolom voor kolom om een cilinder die het drukvlak beslaat.
        var sT = Math.sin(T), aw = art.width / bw, a0 = st.cx - aw / 2, sag = st.arc / 100 * bh;
        for (var x = Math.floor(b[0]); x < Math.ceil(b[2]); x++) {
          var s = ((x + 0.5 - b[0]) / bw - 0.5) * 2 * sT;
          if (Math.abs(s) >= 1) continue;
          var ang = Math.asin(s), a = 0.5 + ang / (2 * T), sx = (a - a0) / aw * art.width;
          if (sx < 0 || sx >= art.width) continue;
          var cs = Math.cos(ang), srcw = Math.max(0.5, art.width * sT / (aw * bw * T * cs));
          lc.globalAlpha = 0.45 + 0.55 * cs;
          lc.drawImage(art, sx, 0, Math.min(srcw, art.width - sx), ah, x, top + sag * (1 - cs), 1, ah);
        }
        lc.globalAlpha = 1;
      }
      if (st.effect) {
        // Gravering: kleur verdwijnt, het materiaal wordt lichter (donker product) of donkerder (licht product).
        var e = document.createElement('canvas'); e.width = W; e.height = H;
        var ec = e.getContext('2d');
        ec.filter = dark ? 'grayscale(1) brightness(0) invert(1) brightness(.78)' : 'grayscale(1) brightness(0) invert(.35)';
        ec.drawImage(layer, 0, 0);
        layer = e;
      }
      ctx.globalAlpha = st.opacity / 100;
      ctx.globalCompositeOperation = st.blend && !dark ? 'multiply' : 'source-over';
      ctx.drawImage(layer, 0, 0);
      ctx.globalAlpha = 1; ctx.globalCompositeOperation = 'source-over';
    }
    if (withBox && st.showBox) {
      var px = W / cv.clientWidth || 1;
      ctx.save();
      ctx.setLineDash([8 * px, 6 * px]); ctx.lineWidth = 2 * px; ctx.strokeStyle = '#DF3550';
      ctx.strokeRect(b[0], b[1], bw, bh);
      ctx.setLineDash([]); ctx.fillStyle = '#DF3550';
      [[b[0], b[1]], [b[2], b[1]], [b[0], b[3]], [b[2], b[3]]].forEach(function (p) { ctx.fillRect(p[0] - 6 * px, p[1] - 6 * px, 12 * px, 12 * px); });
      ctx.restore();
    }
  }
  var raf = 0;
  function draw() { if (raf) return; raf = requestAnimationFrame(function () { raf = 0; render(true); }); }

  // ---------- slepen ----------
  var drag = null;
  function pt(e) { var r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; }
  cv.addEventListener('pointerdown', function (e) {
    if (!st.photo) return;
    var p = pt(e), b = st.photo.box, tol = 16 * cv.width / cv.getBoundingClientRect().width, corner = -1;
    [[0, 1], [2, 1], [0, 3], [2, 3]].forEach(function (c, i) { if (Math.abs(p[0] - b[c[0]]) < tol && Math.abs(p[1] - b[c[1]]) < tol) corner = i; });
    drag = { p: p, corner: corner, cx: st.cx, cy: st.cy, box: b.slice() };
    cv.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  cv.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var p = pt(e), dx = p[0] - drag.p[0], dy = p[1] - drag.p[1], b = st.photo.box, o = drag.box;
    if (drag.corner >= 0) {
      var c = [[0, 1], [2, 1], [0, 3], [2, 3]][drag.corner];
      b[c[0]] = o[c[0]] + dx; b[c[1]] = o[c[1]] + dy;
      if (b[2] - b[0] < 10) b[c[0]] = c[0] === 0 ? b[2] - 10 : b[0] + 10;
      if (b[3] - b[1] < 10) b[c[1]] = c[1] === 1 ? b[3] - 10 : b[1] + 10;
    } else {
      st.cx = drag.cx + dx / (b[2] - b[0]); st.cy = drag.cy + dy / (b[3] - b[1]);
    }
    draw();
  });
  ['pointerup', 'pointercancel'].forEach(function (t) { cv.addEventListener(t, function () { drag = null; }); });

  // ---------- downloaden ----------
  $('mdo-mock-dl').addEventListener('click', function () {
    if (!st.photo) return;
    render(false);
    try {
      cv.toBlob(function (blob) {
        render(true);
        if (!blob) { msg('Opslaan lukte niet.'); return; }
        var a = document.createElement('a');
        var c = st.prod.colors[st.color];
        a.href = URL.createObjectURL(blob);
        a.download = 'mockup-' + String(c.sku || 'product').replace(/[^A-Za-z0-9-]+/g, '') + '.png';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
      }, 'image/png');
    } catch (e) {
      render(true);
      msg('Deze foto mag de browser niet opslaan (komt van een ander domein).');
    }
  });

  if (document.fonts && document.fonts.load) document.fonts.load('700 100px ' + FONTS.poppins[1]).then(draw, draw);
})();
