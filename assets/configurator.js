/* Midocean configurator – prijsregels gelijk aan includes/class-mdo-pricing.php */
(function () {
  'use strict';

  var root = document.getElementById('mdo-configurator');
  if (!root) return;
  var cfg = JSON.parse(root.getAttribute('data-config'));
  var form = root.closest('form');

  var FONTS = {
    poppins: ['Poppins (strak)', '"MDO Poppins", Arial, sans-serif'],
    montserrat: ['Montserrat (modern)', '"MDO Montserrat", Arial, sans-serif'],
    opensans: ['Open Sans (neutraal)', '"MDO Open Sans", Arial, sans-serif'],
    oswald: ['Oswald (smal)', '"MDO Oswald", "Arial Narrow", sans-serif'],
    anton: ['Anton (dik)', '"MDO Anton", Impact, sans-serif'],
    bebasneue: ['Bebas Neue (hoofdletters)', '"MDO Bebas Neue", Impact, sans-serif'],
    robotoslab: ['Roboto Slab (schreef)', '"MDO Roboto Slab", Georgia, serif'],
    playfair: ['Playfair Display (klassiek)', '"MDO Playfair Display", Georgia, serif'],
    courierprime: ['Courier Prime (typemachine)', '"MDO Courier Prime", "Courier New", monospace'],
    pacifico: ['Pacifico (speels)', '"MDO Pacifico", cursive'],
    lobster: ['Lobster (retro)', '"MDO Lobster", cursive'],
    dancing: ['Dancing Script (handschrift)', '"MDO Dancing Script", cursive'],
    marker: ['Permanent Marker (stift)', '"MDO Permanent Marker", cursive']
  };
  var INKS = ['#1E1E1E', '#FFFFFF', '#C9A227', '#C8312F', '#2443A8'];
  var COLOUR_OPTS = ['1', '2', '3', '4', 'fc'];

  /** Is de gekozen productkleur donker? Dan is witte tekst standaard beter leesbaar. */
  function isDark() {
    var name = String(cfg.colors[st.color].name).toLowerCase().split(/\s*[,/]\s*/)[0].trim();
    var hex = (typeof NAMED !== 'undefined' && NAMED[name]) || '';
    if (/^#[0-9a-f]{6}$/i.test(hex)) {
      var r = parseInt(hex.substr(1, 2), 16), g = parseInt(hex.substr(3, 2), 16), b = parseInt(hex.substr(5, 2), 16);
      return (0.299 * r + 0.587 * g + 0.114 * b) < 140;
    }
    return /zwart|black|navy|marine|donker|dark|bordeaux|petrol|royal|bruin|paars/.test(name);
  }
  function isDarkHex(hex) {
    if (!/^#[0-9a-f]{6}$/i.test(hex || '')) return true;
    var r = parseInt(hex.substr(1, 2), 16), g = parseInt(hex.substr(3, 2), 16), b = parseInt(hex.substr(5, 2), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) < 160;
  }
  function inkOf(fs) { return fs.ink || (isDark() ? '#FFFFFF' : '#1E1E1E'); }
  /** Laser en blinddruk hebben geen inktkleur: toon een gravure-effect. */
  function effectOf(techId) {
    var n = String((cfg.techniques[techId] || {}).name || '').toLowerCase();
    if (/laser|graveer|gravure|engrav/.test(n)) return 'engrave';
    if (/blind|deboss|emboss|reli/.test(n)) return 'deboss';
    return '';
  }
  var imgSize = {}; // url -> [w, h]

  // ---------- prijs ----------
  function scaleAt(scales, q) { var r = scales[0] || [1, 0, 0]; for (var i = 0; i < scales.length; i++) if (q >= scales[i][0]) r = scales[i]; return r; }
  function rangeFor(ranges, a) {
    for (var i = 0; i < ranges.length; i++) { var to = ranges[i][1] === null ? Infinity : ranges[i][1]; if (a >= ranges[i][0] && a < to) return ranges[i][2]; }
    return ranges.length ? ranges[ranges.length - 1][2] : [];
  }
  function printCost(tech, colours, area, q) {
    var c = Math.max(1, colours | 0), t = tech.type;
    var isArea = t === 'AreaRange' || t === 'ColourAreaRange';
    var s = scaleAt(isArea ? rangeFor(tech.ranges, area) : (tech.ranges[0] ? tech.ranges[0][2] : []), q);
    var unit = s[1] || 0, next = s[2] || 0, setup = tech.setup;
    if (t === 'Area') unit *= area;
    if (t === 'NumberOfColours' || t === 'ColourAreaRange') {
      unit = tech.next ? unit + next * (c - 1) : unit * c;
      if (cfg.setupPerColour) setup *= c;
    }
    return [unit, setup];
  }
  // Kleding: totaal aantal over alle maten.
  function sizeTotal() { var n = 0; Object.keys(st.sizes).forEach(function (k) { n += st.sizes[k] | 0; }); return n; }
  // Productprijs: bij kleding het gewogen gemiddelde van de maten (bijv. XXL duurder), staffel op het totaal.
  function productPrice(color, q) {
    if (color.sizes && sizeTotal() > 0) {
      var sum = 0, n = 0;
      color.sizes.forEach(function (sz) { var k = st.sizes[sz.size] | 0; if (k > 0) { sum += k * scaleAt(sz.scales, q)[1]; n += k; } });
      if (n) return sum / n;
    }
    return scaleAt(color.scales, q)[1];
  }
  function quote(color, q, sels) {
    q = Math.max(1, q | 0);
    var unit = productPrice(color, q), setup = 0;
    sels.forEach(function (s) { var r = printCost(cfg.techniques[s.tech], s.colours, s.w * s.h / 100, q); unit += r[0]; setup += r[1]; });
    if (sels.length) unit += cfg.manip;
    var total = unit * q + setup;
    return { unit: unit, setup: setup, total: total, perPc: total / q };
  }
  function eur(n) { return cfg.currency + ' ' + n.toFixed(2).replace('.', cfg.decimalSep); }

  // ---------- staat ----------
  var st = {
    color: 0, qty: 100, face: cfg.faces.length ? cfg.faces[0].key : null, view: 'design', note: '', sizes: {},
    faces: {}
  };
  cfg.faces.forEach(function (f, i) {
    st.faces[f.key] = { on: i === 0, mode: 'logo', colours: '1', pick: null, size: null, text: '', font: 'poppins', ink: null, pms: '', logo: null, al: 'c', va: 'm' };
  });

  function nColours(fs) { return fs.mode === 'text' ? 1 : (fs.colours === 'fc' ? 1 : parseInt(fs.colours, 10)); }
  function allowed(t, fs) {
    if (!cfg.techniques[t.id]) return false;
    var k = fs.mode === 'text' ? '1' : fs.colours;
    if (k === 'fc') return t.max === 0;
    return t.max === 0 || t.max >= parseInt(k, 10);
  }
  function faceByKey(k) { for (var i = 0; i < cfg.faces.length; i++) if (cfg.faces[i].key === k) return cfg.faces[i]; return null; }

  /** Mogelijke (positie, techniek)-combinaties voor een vlak, voordeligste eerst. */
  /**
   * Afmetingen van het ontwerp op een positie, rekening houdend met draaien.
   * len = lengte van het ontwerp zelf (schuifje), thick = hoogte van het ontwerp;
   * w × h = ruimte op het product (wat Midocean drukt en waar de prijs op rekent).
   */
  function dims(p, fs) {
    var a = aspect(fs), vertical = ((fs.rot || 0) % 180) !== 0;
    var maxLen = Math.max(5, Math.floor(vertical ? Math.min(p.h, p.w / a) : Math.min(p.w, p.h / a)));
    var len = Math.max(5, Math.min(maxLen, fs.size || Math.round(maxLen * 0.85)));
    var thick = Math.max(2, Math.round(len * a));
    return { len: len, thick: thick, max: maxLen, vertical: vertical, w: vertical ? thick : len, h: vertical ? len : thick };
  }
  function options(face, fs) {
    var out = [];
    face.positions.forEach(function (p) {
      p.techs.forEach(function (t) {
        if (!allowed(t, fs)) return;
        var d = dims(p, fs);
        var c = quote(cfg.colors[st.color], st.qty, [{ tech: t.id, colours: nColours(fs), w: d.w, h: d.h }]);
        out.push({ key: p.id + '|' + t.id, pos: p, tech: t, cost: c.perPc, isDefault: !!t.default });
      });
    });
    out.sort(function (a, b) { return (a.cost - b.cost) || (b.isDefault - a.isDefault); });
    return out;
  }
  // Breedte van de tekst meten, zodat tekst net als een logo het hele vlak kan vullen.
  var measureCanvas = null, textCache = {};
  function textBox(fs) {
    var txt = fs.text || 'Jouw tekst', key = fs.font + '|' + txt;
    if (textCache[key]) return textCache[key];
    var w = 0;
    try {
      measureCanvas = measureCanvas || document.createElement('canvas');
      var ctx = measureCanvas.getContext && measureCanvas.getContext('2d');
      if (ctx) { ctx.font = '700 84px ' + FONTS[fs.font][1]; w = ctx.measureText(txt).width; }
    } catch (e) { w = 0; }
    if (!w) w = txt.length * 50;
    var vbw = Math.ceil(w + 12);
    return (textCache[key] = { txt: txt, vbw: vbw, aspect: 100 / vbw });
  }
  var loadedFonts = {};
  function loadFont(key) {
    if (loadedFonts[key] || !document.fonts || !document.fonts.load) return;
    loadedFonts[key] = 1;
    document.fonts.load('700 84px ' + FONTS[key][1]).then(function () { textCache = {}; soft(); }).catch(function () {});
  }
  function aspect(fs) {
    if (fs.mode === 'text') return textBox(fs).aspect;
    if (fs.logo && fs.logo.preview && imgSize[fs.logo.preview]) { var d = imgSize[fs.logo.preview]; return d[1] / d[0]; }
    if (!fs.logo && demoSrc && imgSize[demoSrc]) { var e = imgSize[demoSrc]; return e[1] / e[0]; }
    return 0.5;
  }
  function chosen(face, fs) {
    var opts = options(face, fs);
    if (!opts.length) return null;
    for (var i = 0; i < opts.length; i++) if (opts[i].key === fs.pick) return { opt: opts[i], opts: opts };
    return { opt: opts[0], opts: opts };
  }
  function selection(face, fs) {
    var ch = chosen(face, fs);
    if (!ch) return null;
    var p = ch.opt.pos;
    var d = dims(p, fs);
    var ar = alignRules(p);
    var canH = ar.h && p.w - d.w >= 3, canV = ar.v && p.h >= 25 && p.h - d.h >= 8;
    return { face: face, fs: fs, opt: ch.opt, opts: ch.opts, w: d.w, h: d.h, d: d, rot: fs.rot || 0, colours: nColours(fs),
      canH: canH, canV: canV, al: canH ? (fs.al || 'c') : 'c', va: canV ? (fs.va || 'm') : 'm' };
  }
  function selections() {
    var out = [];
    cfg.faces.forEach(function (f) { var fs = st.faces[f.key]; if (fs.on) { var s = selection(f, fs); if (s) out.push(s); } });
    return out;
  }
  // Waar heeft uitlijnen geen zin?
  // - Rondom / 360°: links en rechts bestaan niet op een rond product (behalve bij pennen: daar loopt het vlak langs de pen).
  // - Rond of onregelmatig drukvlak (Ellipse/Polygon, bijv. een deksel of doming): altijd in het midden.
  function alignRules(p) {
    var id = String(p.id || '').toUpperCase(), shape = String(p.shape || '').toLowerCase();
    if (shape === 'ellipse' || shape === 'polygon') return { h: false, v: false };
    var round = /360|ROUND ?SCREEN|ROUNDPART|WRAP|AROUND|SUBLIMATION 360/.test(id) && !/PEN|PENCIL/.test(id);
    return { h: !round, v: true };
  }
  // Hoe rond is het drukvlak op de foto? Geeft de halve hoek (radialen) die het vlak beslaat, of 0 voor plat.
  function curveOf(p) {
    var id = String(p.id || '').toUpperCase(), shape = String(p.shape || '').toLowerCase();
    if (shape === 'ellipse' || shape === 'polygon' || /PEN|PENCIL/.test(id)) return 0;
    if (/360|WRAP|AROUND/.test(id)) return 78 * Math.PI / 180;
    if (/ROUND ?SCREEN|ROUNDPART/.test(id)) return 62 * Math.PI / 180;
    return 0;
  }
  // ---------- rondom: logo/tekst om de cilinder (canvas) ----------
  var wrapJob = null, wrapImgs = {};
  function wrapImg(src) {
    if (wrapImgs[src]) return wrapImgs[src].complete && wrapImgs[src].naturalWidth ? wrapImgs[src] : null;
    var im = new Image();
    im.onload = function () {
      if (!imgSize[src] && im.naturalWidth) { imgSize[src] = [im.naturalWidth, im.naturalHeight]; soft(); } else drawWrap();
    };
    im.src = src;
    wrapImgs[src] = im;
    return null;
  }
  // Ontwerp onvervormd tekenen (incl. draaien), daarna kolom voor kolom om de cilinder leggen.
  function drawWrap() {
    var cv = root.querySelector('canvas.mdo-wrap') || (gal && gal.querySelector('canvas.mdo-wrap')), j = wrapJob;
    if (!cv || !j || !cv.getContext) return;
    var dpr = Math.min(3, window.devicePixelRatio || 1), Zw = cv.clientWidth, Zh = cv.clientHeight;
    if (!Zw || !Zh) return;
    cv.width = Math.round(Zw * dpr); cv.height = Math.round(Zh * dpr);
    var out = cv.getContext('2d'); if (!out) return;
    out.clearRect(0, 0, cv.width, cv.height);
    var Aw = j.aw * (j.views ? (j.b1 - j.b0) : 1) * Zw * dpr, Ah = j.ah * Zh * dpr;
    var src = document.createElement('canvas');
    src.width = Math.max(1, Math.round(Aw * 1.5)); src.height = Math.max(1, Math.round(Ah * 1.5));
    var c = src.getContext('2d'), S = 1.5;
    c.translate(src.width / 2, src.height / 2);
    c.rotate(j.rot * Math.PI / 180);
    var iw = (j.vertical ? Ah : Aw) * S, ih = (j.vertical ? Aw : Ah) * S;
    if (j.kind === 'text') {
      var s = Math.min(iw / j.vbw, ih / 100);
      c.scale(s, s);
      c.font = '700 84px ' + j.family;
      c.textAlign = 'center';
      c.fillStyle = j.ink;
      c.fillText(j.txt, 0, 74 - 50);
    } else {
      var im = wrapImg(j.src);
      if (!im) return; // tekent opnieuw zodra het plaatje geladen is
      var r = Math.min(iw / im.naturalWidth, ih / im.naturalHeight), w = im.naturalWidth * r, h = im.naturalHeight * r;
      if (j.filter) c.filter = j.filter;
      c.drawImage(im, -w / 2, -h / 2, w, h);
    }
    var W = cv.width, H = cv.height, sT = Math.sin(j.T), al = (1 - j.aw) / 2, sag = 0.035;
    if (j.views) {
      // Drie aanzichten: elk derde van de foto is hetzelfde product, 120° verder gedraaid.
      var third = W / 3, sil = 0.9, span = j.b1 - j.b0;
      for (var xv = 0; xv < W; xv++) {
        var k = Math.min(2, Math.floor(xv / third)), xl = ((xv + 0.5 - k * third) / third - 0.5) * 2 / sil;
        if (Math.abs(xl) >= 1) continue;
        var angv = Math.asin(xl), cosv = Math.cos(angv);
        var cpos = (k + 0.5) / 3 + angv / (2 * Math.PI);
        var av = (cpos - j.b0) / span;
        if (av < 0 || av > 1) continue; // buiten het drukvlak
        var sxv = (av - al) / j.aw * src.width;
        if (sxv < 0 || sxv >= src.width) continue;
        var dsrc = (2 / sil) / third / (cosv * 2 * Math.PI) / span / j.aw * src.width;
        out.globalAlpha = 0.45 + 0.55 * cosv;
        out.drawImage(src, sxv, 0, Math.max(1, Math.min(dsrc, src.width - sxv)), src.height, xv, (j.at + sag * (1 - cosv)) * H, 1, j.ah * H);
      }
      out.globalAlpha = 1;
      return;
    }
    for (var x = 0; x < W; x++) {
      var sv = ((x + 0.5) / W - 0.5) * 2 * sT;
      if (Math.abs(sv) >= 1) continue;
      var ang = Math.asin(sv), a = 0.5 + ang / (2 * j.T), sx = (a - al) / j.aw * src.width;
      if (sx < 0 || sx >= src.width) continue;
      var cs = Math.cos(ang);
      out.globalAlpha = 0.5 + 0.5 * cs; // licht draait weg naar de zijkanten
      out.drawImage(src, sx, 0, Math.max(1, src.width / (j.aw * W) * (1 / (j.T / sT * cs))), src.height, x, (j.at + sag * (1 - cs)) * H, 1, j.ah * H);
    }
    out.globalAlpha = 1;
  }
  var AL = { l: 'links', c: 'midden', r: 'rechts' }, VA = { t: 'boven', m: 'midden', b: 'onder' };
  function alignText(s) {
    var parts = [];
    if (s.al !== 'c') parts.push(AL[s.al]);
    if (s.va !== 'm') parts.push(VA[s.va]);
    return parts.length ? 'uitgelijnd ' + parts.join(' ') : '';
  }
  function priceSels(sels) { return sels.map(function (s) { return { tech: s.opt.tech.id, colours: s.colours, w: s.w, h: s.h }; }); }

  // ---------- weergave ----------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function effectInk(effect) {
    if (effect === 'engrave') return isDark() ? '#C9CDD2' : '#5A5E64';
    if (effect === 'deboss') return isDark() ? 'rgba(255,255,255,0.18)' : 'rgba(0,0,0,0.28)';
    return null;
  }
  // Voorbeeldlogo: ons eigen logo (zonder slogan) in plaats van "Jouw logo".
  var demoSrc = null;
  function loadDemo() {
    if (!cfg.demoLogo) return;
    var im = new Image();
    im.onload = function () {
      try {
        var c = document.createElement('canvas'); c.width = im.naturalWidth; c.height = im.naturalHeight;
        var x = c.getContext('2d'); x.drawImage(im, 0, 0);
        var d = x.getImageData(0, 0, c.width, c.height).data, rows = [], y, i, s = -1, blocks = [];
        for (y = 0; y < c.height; y++) { var ink = 0; for (i = 0; i < c.width; i += 2) if (d[(y * c.width + i) * 4 + 3] > 24) { ink = 1; break; } rows.push(ink); }
        for (y = 0; y <= rows.length; y++) { if (rows[y] && s < 0) s = y; if (!rows[y] && s >= 0) { blocks.push([s, y]); s = -1; } }
        var merged = [];
        blocks.forEach(function (b) { var l = merged[merged.length - 1]; if (l && b[0] - l[1] < c.height * 0.03) l[1] = b[1]; else merged.push(b.slice()); });
        merged.sort(function (a, b) { return (b[1] - b[0]) - (a[1] - a[0]); });
        var top = merged[0] || [0, c.height], h = top[1] - top[0], l = c.width, r = 0;
        for (y = top[0]; y < top[1]; y += 2) for (i = 0; i < c.width; i++) if (d[(y * c.width + i) * 4 + 3] > 24) { if (i < l) l = i; if (i > r) r = i; }
        if (r <= l) { l = 0; r = c.width - 1; }
        var t = document.createElement('canvas'); t.width = r - l + 1; t.height = h;
        t.getContext('2d').drawImage(c, l, top[0], t.width, h, 0, 0, t.width, h);
        demoSrc = t.toDataURL('image/png');
        imgSize[demoSrc] = [t.width, t.height];
      } catch (e) { demoSrc = cfg.demoLogo; imgSize[demoSrc] = [im.naturalWidth, im.naturalHeight]; }
      render();
    };
    im.src = cfg.demoLogo;
  }
  // Kan de gekozen techniek/het aantal kleuren geen kleur? Dan het voorbeeldlogo in één kleur.
  function demoMono(fs) { return fs.colours !== 'fc'; }
  function demoClass(fs, effect) {
    if (effect) return 'mdo-fx-' + effect + (isDark() ? ' on-dark' : '');
    return demoMono(fs) ? (isDark() ? 'mdo-mono-w' : 'mdo-mono-b') : '';
  }
  function demoFilter(fs, effect) {
    if (effect === 'engrave') return isDark() ? 'grayscale(1) contrast(.6) brightness(1.6)' : 'grayscale(1) contrast(.6) brightness(.55)';
    if (effect === 'deboss') return isDark() ? 'grayscale(1) brightness(2) opacity(.18)' : 'grayscale(1) brightness(0) opacity(.25)';
    return demoMono(fs) ? (isDark() ? 'brightness(0) invert(1)' : 'brightness(0)') : '';
  }
  function demoLogo(dark, effect) {
    var ink = effectInk(effect) || (dark ? '#FFFFFF' : '#1D1A2E');
    var accent = effect ? ink : '#DF3550';
    return '<svg viewBox="0 0 220 110" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Voorbeeldlogo"><circle cx="48" cy="55" r="40" fill="' + ink + '"/><path d="M30 70 L48 32 L66 70 Z" fill="' + (effect ? (isDark() ? '#1E1E1E' : '#FFFFFF') : accent) + '"/><text x="100" y="52" font-family="Helvetica, Arial, sans-serif" font-size="30" font-weight="800" fill="' + ink + '">Jouw</text><text x="100" y="84" font-family="Helvetica, Arial, sans-serif" font-size="30" font-weight="800" fill="' + ink + '">Logo</text></svg>';
  }

  function posImg(pos, color) {
    var url = pos.images[color.code];
    if (!url) { // kleurcode niet gevonden: zoek de code in het artikelnummer
      Object.keys(pos.images).forEach(function (k) { if (!url && k && new RegExp('-' + k + '(-|$)', 'i').test(color.sku)) url = pos.images[k]; });
    }
    return url || pos.images[Object.keys(pos.images)[0]] || color.image;
  }
  function previewHtml() {
    var face = faceByKey(st.face); if (!face) return '';
    var fs = st.faces[face.key], color = cfg.colors[st.color];
    var sel = fs.on ? selection(face, fs) : null;
    var pos = sel ? sel.opt.pos : face.positions[0];
    var url = posImg(pos, color);
    var dims = imgSize[url];
    var zone = '';
    if (sel && dims && pos.box) {
      var b = pos.box, W = dims[0], H = dims[1];
      var zl = b[0] / W * 100, zt = b[1] / H * 100, zw = (b[2] - b[0]) / W * 100, zh = (b[3] - b[1]) / H * 100;
      var artW = Math.min(100, sel.w / pos.w * 100), artH = Math.min(100, sel.h / pos.h * 100);
      var art;
      var effect = effectOf(sel.opt.tech.id);
      if (fs.mode === 'text') {
        var tb = textBox(fs), txt = tb.txt, vbw = tb.vbw;
        art = '<svg viewBox="0 0 ' + vbw + ' 100" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' + esc(txt) + '"><text x="' + (vbw / 2) + '" y="74" text-anchor="middle" font-size="84" font-weight="700" style="font-family:' + esc(FONTS[fs.font][1]) + '" fill="' + (effectInk(effect) || inkOf(fs)) + '">' + esc(txt) + '</text></svg>';
      } else if (fs.logo && fs.logo.preview) {
        art = '<img src="' + esc(fs.logo.preview) + '" data-measure="' + esc(fs.logo.preview) + '" alt="Jouw logo" class="' + (effect ? 'mdo-fx-' + effect + (isDark() ? ' on-dark' : '') : '') + '">';
      } else if (fs.logo) {
        art = '<span class="mdo-art-file">' + esc(fs.logo.name) + '</span>';
      } else if (demoSrc) {
        art = '<img src="' + esc(demoSrc) + '" alt="Voorbeeldlogo" class="' + demoClass(fs, effect) + '">';
      } else {
        art = demoLogo(isDark(), effect);
      }
      // Binnenste vlak heeft de onverdraaide maat van het ontwerp en wordt rond het midden gedraaid.
      var d = sel.d, innerW = d.vertical ? (d.len / d.thick * 100) : 100, innerH = d.vertical ? (d.thick / d.len * 100) : 100;
      var jc = { l: 'flex-start', c: 'center', r: 'flex-end' }[sel.al], ai = { t: 'flex-start', m: 'center', b: 'flex-end' }[sel.va];
      var inner = '<div class="mdo-art-in" style="width:' + innerW + '%;height:' + innerH + '%;transform:translate(-50%,-50%) rotate(' + sel.rot + 'deg)">' + art + '</div>';
      // Mengen met de foto (glans en structuur blijven zichtbaar) alleen op lichte producten met donkere inkt.
      var lightInk = fs.mode === 'text' && !effect && !isDarkHex(inkOf(fs));
      var blend = isDark() || lightInk ? '' : ' mdo-blend';
      var T = curveOf(pos);
      wrapJob = null;
      if (T && (fs.mode === 'text' || !fs.logo || fs.logo.preview)) {
        var aw = artW / 100, ah = artH / 100;
        // Met bekende diameter precies: het drukvlak (breedte w mm) beslaat een hoek van w/D rad om de as.
        if (cfg.diameter > 0 && pos.w > 0) {
          T = Math.min(Math.PI / 2, pos.w / cfg.diameter);
          var arc = T >= Math.PI / 2 - 1e-6 ? Math.PI * cfg.diameter / 2 : pos.w;
          aw = sel.w / arc;
        }
        wrapJob = { T: T, aw: aw, ah: ah, at: sel.va === 't' ? 0 : sel.va === 'b' ? 1 - ah : (1 - ah) / 2, rot: sel.rot, vertical: !!d.vertical };
        if (fs.mode === 'text') {
          var tb2 = textBox(fs);
          wrapJob.kind = 'text'; wrapJob.txt = tb2.txt; wrapJob.vbw = tb2.vbw; wrapJob.family = FONTS[fs.font][1]; wrapJob.ink = effectInk(effect) || inkOf(fs);
        } else if (fs.logo) {
          wrapJob.kind = 'img'; wrapJob.src = fs.logo.preview;
          wrapJob.filter = effect === 'engrave' ? (isDark() ? 'grayscale(1) contrast(.6) brightness(1.6)' : 'grayscale(1) contrast(.6) brightness(.55)') : effect === 'deboss' ? (isDark() ? 'grayscale(1) brightness(2) opacity(.18)' : 'grayscale(1) brightness(0) opacity(.25)') : '';
        } else if (demoSrc) {
          wrapJob.kind = 'img'; wrapJob.src = demoSrc; wrapJob.filter = demoFilter(fs, effect);
        } else {
          wrapJob.kind = 'img'; wrapJob.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(demoLogo(isDark(), effect).replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" width="440" height="220" '));
        }
        // Midocean toont rondom-posities vaak als drie aanzichten van hetzelfde product naast elkaar,
        // met het drukvlak als uitgerolde band eroverheen. Dan elk aanzicht apart om de cilinder tekenen.
        if (zw > 50) {
          wrapJob.views = 3; wrapJob.b0 = zl / 100; wrapJob.b1 = (zl + zw) / 100; wrapJob.aw = sel.w / (pos.w || sel.w);
          zone = '<div class="mdo-zone mdo-zone-line" style="left:' + zl + '%;top:' + zt + '%;width:' + zw + '%;height:' + zh + '%"></div>' +
            '<div class="mdo-zone mdo-curved mdo-wrapfull' + blend + '" style="left:0;top:' + zt + '%;width:100%;height:' + zh + '%"><canvas class="mdo-wrap" aria-label="Voorbeeld van je opdruk rondom"></canvas></div>';
        } else {
          zone = '<div class="mdo-zone mdo-curved' + blend + '" style="left:' + zl + '%;top:' + zt + '%;width:' + zw + '%;height:' + zh + '%"><canvas class="mdo-wrap" aria-label="Voorbeeld van je opdruk rondom"></canvas></div>';
        }
      } else {
        zone = '<div class="mdo-zone' + blend + '" style="left:' + zl + '%;top:' + zt + '%;width:' + zw + '%;height:' + zh + '%;justify-content:' + jc + ';align-items:' + ai + '"><div class="mdo-art" style="width:' + artW + '%;height:' + artH + '%">' + inner + '</div></div>';
      }
    }
    return '<div class="mdo-preview"><div class="mdo-photo"><img src="' + esc(url) + '" alt="' + esc(face.label) + ', ' + esc(color.name) + '" data-measure="' + esc(url) + '">' + zone + '</div>' +
      (dims ? '' : '<p class="mdo-muted">Foto laden…</p>') + '</div>';
  }

  function priceHtml() {
    var color = cfg.colors[st.color], sels = selections(), n = dispQty();
    var q = quote(color, n, priceSels(sels));
    var next = cfg.tiers.filter(function (t) { return t > n; })[0];
    var tiers = cfg.tiers.map(function (t) {
      var x = quote(color, t, priceSels(sels));
      return '<div class="mdo-tier' + (t === n ? ' is-on' : '') + '"><span>' + t + ' st.</span><span>' + eur(x.perPc) + '</span></div>';
    }).join('');
    var short, block, stockMsg;
    if (color.sizes) {
      var shortSz = color.sizes.filter(function (sz) { return sz.stock !== null && (st.sizes[sz.size] | 0) > sz.stock; });
      short = shortSz.length > 0;
      block = shortSz.some(function (sz) { return !sz.backorders; }) || sizeTotal() === 0;
      stockMsg = (sizeTotal() === 0 ? '<p class="mdo-warn">Vul bij minstens één maat een aantal in.</p>' : '') +
        (!short ? '' : '<p class="mdo-warn">Niet genoeg voorraad in ' + shortSz.map(function (sz) { return esc(sz.size) + ' (nog ' + Math.max(0, sz.stock) + ')'; }).join(', ') + '.' + (block ? '' : ' Je kunt wel bestellen; de levertijd is dan langer.') + '</p>');
    } else {
      short = color.stock !== null && st.qty > color.stock; block = short && !color.backorders;
      stockMsg = !short ? '' : '<p class="mdo-warn">' + (color.stock > 0 ? 'Nog ' + color.stock + ' stuks op voorraad in ' + esc(color.name) + '.' : 'Op dit moment niet op voorraad in ' + esc(color.name) + '.') + (block ? '' : ' Je kunt wel bestellen; de levertijd is dan langer.') + '</p>';
    }
    return '<div class="mdo-total"><span class="mdo-total-num">' + eur(q.total) + '</span><span class="mdo-muted">' + (n !== st.qty ? 'Voorbeeld bij ' + n + ' stuks: ' : '') + eur(q.perPc) + ' per stuk, alles inbegrepen' + (q.setup ? ' (incl. ' + eur(q.setup) + ' instelkosten)' : '') + '</span></div>' +
      (next ? '<p class="mdo-hint">Vanaf ' + next + ' stuks: ' + eur(quote(color, next, priceSels(sels)).perPc) + ' per stuk</p>' : '') +
      '<div class="mdo-tiers">' + tiers + '</div>' + stockMsg +
      '<button type="button" class="mdo-buy button alt"' + (block ? ' disabled' : '') + '>In winkelwagen</button>';
  }

  function faceHtml(face) {
    var fs = st.faces[face.key];
    var h = '<label class="mdo-check"><input type="checkbox" data-act="toggle" ' + (fs.on ? 'checked' : '') + '> ' + esc(face.label) + ' bedrukken</label>';
    if (!fs.on) return h;
    var sel = selection(face, fs);
    h += '<div class="mdo-seg">' + [['logo', 'Logo'], ['text', 'Eigen tekst']].map(function (m) {
      return '<button type="button" data-act="mode" data-v="' + m[0] + '" class="' + (fs.mode === m[0] ? 'is-on' : '') + '">' + m[1] + '</button>';
    }).join('') + '</div>';
    if (fs.mode === 'logo') {
      h += '<label class="mdo-upload"><input type="file" data-act="logo" accept=".pdf,.eps,.ai,.svg,.png,.jpg,.jpeg"><span>' +
        (fs.logo ? esc(fs.logo.name) : 'Upload je logo (PDF, EPS, AI, SVG, PNG, JPG) – of later aanleveren') + '</span></label>';
      h += '<div class="mdo-field"><span class="mdo-lbl">Kleuren in je logo</span><div class="mdo-grid mdo-grid-col">' + COLOUR_OPTS.map(function (k) {
        return '<button type="button" data-act="colours" data-v="' + k + '" class="mdo-pill' + (fs.colours === k ? ' is-on' : '') + '">' + (k === 'fc' ? 'Full colour' : k) + '</button>';
      }).join('') + '</div></div>';
      if (fs.colours !== 'fc') {
        h += '<input type="text" class="mdo-input" data-act="pms" maxlength="60" placeholder="Pantone-kleuren van je logo (optioneel), bijv. 485 C, Black" value="' + esc(fs.pms) + '" aria-label="Pantone-kleuren van je logo">';
      }
    } else {
      h += '<input type="text" class="mdo-input" data-act="text" maxlength="60" placeholder="Jouw tekst, bijv. je bedrijfsnaam" value="' + esc(fs.text) + '">';
      // Eigen keuzelijst: een gewone <select> toont op telefoons alle opties in het systeemlettertype.
      h += '<div class="mdo-lbl" id="mdo-font-lbl">Lettertype</div><div class="mdo-fontpick">' +
        '<button type="button" data-act="fontopen" class="mdo-input mdo-fontbtn" aria-haspopup="listbox" aria-expanded="' + (st.fontOpen ? 'true' : 'false') + '" aria-labelledby="mdo-font-lbl" style="font-family:' + esc(FONTS[fs.font][1]) + '"><span>' + esc(fs.text || FONTS[fs.font][0]) + '</span><span class="mdo-fontname">' + esc(FONTS[fs.font][0]) + ' ▾</span></button>' +
        (st.fontOpen ? '<div class="mdo-fontlist" role="listbox" aria-labelledby="mdo-font-lbl">' + Object.keys(FONTS).map(function (k) {
          return '<button type="button" role="option" aria-selected="' + (fs.font === k ? 'true' : 'false') + '" data-act="font" data-v="' + k + '" class="mdo-fontopt' + (fs.font === k ? ' is-on' : '') + '"><span style="font-family:' + esc(FONTS[k][1]) + '">' + esc(fs.text || 'PromoFun') + '</span><span class="mdo-fontname">' + esc(FONTS[k][0]) + '</span></button>';
        }).join('') + '</div>' : '') + '</div>';
      if (!(sel && effectOf(sel.opt.tech.id))) {
        var cur = inkOf(fs);
        h += '<div class="mdo-lbl">Tekstkleur</div><div class="mdo-row">' + INKS.map(function (c) {
          return '<button type="button" data-act="ink" data-v="' + c + '" class="mdo-ink' + (cur.toLowerCase() === c.toLowerCase() ? ' is-on' : '') + '" style="background:' + c + '" aria-label="Tekstkleur ' + c + '"></button>';
        }).join('') +
          '<label class="mdo-picker' + (INKS.map(function (c) { return c.toLowerCase(); }).indexOf(cur.toLowerCase()) < 0 ? ' is-on' : '') + '" title="Eigen kleur kiezen"><input type="color" data-act="inkpick" value="' + esc(/^#[0-9a-f]{6}$/i.test(cur) ? cur : '#1e1e1e') + '" aria-label="Eigen kleur kiezen"><span>Eigen kleur</span></label>' +
          '<input type="text" class="mdo-input mdo-pms" data-act="pms" maxlength="20" placeholder="PMS-code (optioneel)" value="' + esc(fs.pms) + '" aria-label="Pantone-code (optioneel)"></div>' +
          '<p class="mdo-muted">Kleuren op een scherm wijken af van drukinkt. Weet je de Pantone-code (PMS) van je huisstijl, vul die dan in voor een exacte kleur.</p>';
      }
      if (sel && effectOf(sel.opt.tech.id)) {
        h += '<p class="mdo-muted">' + (effectOf(sel.opt.tech.id) === 'engrave' ? 'Bij lasergravering wordt je tekst in het materiaal gegraveerd; de kleur hangt af van het materiaal.' : 'Bij blinddruk wordt je tekst in het materiaal geperst, zonder inkt.') + '</p>';
        var inkOpt = sel.opts.filter(function (o) { return !effectOf(o.tech.id); })[0];
        if (inkOpt) h += '<button type="button" class="mdo-linkbtn" data-act="techinfo">Liever in kleur? Kies ' + esc(cfg.techniques[inkOpt.tech.id].name.toLowerCase()) + ' of een andere techniek</button>';
      }
    }
    if (!sel) return h + '<p class="mdo-warn">Voor deze keuze is op ' + esc(face.label.toLowerCase()) + ' geen techniek beschikbaar.</p>';
    var p = sel.opt.pos;
    h += '<label class="mdo-size"><span class="mdo-row-between"><span class="mdo-lbl">Formaat</span><span class="mdo-muted" data-out="size">' + sel.w + ' × ' + sel.h + ' mm · max. ' + p.w + ' × ' + p.h + '</span></span>' +
      '<input type="range" data-act="size" min="5" max="' + sel.d.max + '" step="1" value="' + sel.d.len + '"></label>';
    h += '<div class="mdo-field"><span class="mdo-lbl">Draaien</span><div class="mdo-grid mdo-grid-rot">' + [0, 90, 180, 270].map(function (r) {
      return '<button type="button" data-act="rot" data-v="' + r + '" class="mdo-pill mdo-rot' + (sel.rot === r ? ' is-on' : '') + '" aria-label="Draai ' + r + ' graden"><span class="mdo-rot-ico" style="transform:rotate(' + r + 'deg)">A</span>' + r + '°</button>';
    }).join('') + '</div></div>';
    if (sel.canH) {
      h += '<div class="mdo-field"><span class="mdo-lbl">Uitlijnen</span><div class="mdo-grid mdo-grid-3">' + [['l', 'Links'], ['c', 'Midden'], ['r', 'Rechts']].map(function (a) {
        return '<button type="button" data-act="al" data-v="' + a[0] + '" class="mdo-pill' + (sel.al === a[0] ? ' is-on' : '') + '">' + a[1] + '</button>';
      }).join('') + '</div>';
      if (sel.canV) h += '<div class="mdo-grid mdo-grid-3">' + [['t', 'Boven'], ['m', 'Midden'], ['b', 'Onder']].map(function (a) {
        return '<button type="button" data-act="va" data-v="' + a[0] + '" class="mdo-pill' + (sel.va === a[0] ? ' is-on' : '') + '">' + a[1] + '</button>';
      }).join('') + '</div>';
      h += '</div>';
    } else if (sel.canV) {
      h += '<div class="mdo-field"><span class="mdo-lbl">Uitlijnen</span><div class="mdo-grid mdo-grid-3">' + [['t', 'Boven'], ['m', 'Midden'], ['b', 'Onder']].map(function (a) {
        return '<button type="button" data-act="va" data-v="' + a[0] + '" class="mdo-pill' + (sel.va === a[0] ? ' is-on' : '') + '">' + a[1] + '</button>';
      }).join('') + '</div></div>';
    }
    var cur = sel.opt, more = sel.opts.length - 1;
    h += '<div class="mdo-field"><span class="mdo-lbl">Techniek</span>' +
      '<button type="button" class="mdo-input mdo-techbtn" data-act="techinfo" aria-haspopup="dialog">' +
      '<span class="mdo-techbtn-name">' + esc(cfg.techniques[cur.tech.id].name) + (cur.pos.id !== face.positions[0].id ? ' (' + cur.pos.w + ' × ' + cur.pos.h + ' mm)' : '') + (cur.key === sel.opts[0].key && more ? ' – voordeligst' : '') + '</span>' +
      '<span class="mdo-techbtn-more">' + (more ? more + (more === 1 ? ' andere optie' : ' andere opties') : '') + '</span></button></div>';
    return h;
  }

  // Kleurnaam -> kleur voor het bolletje als er nog geen productfoto is.
  var NAMED = { zwart: '#1E1E1E', wit: '#FFFFFF', 'gebroken wit': '#F1ECE0', rood: '#C8312F', blauw: '#2F6DB5', 'royal blauw': '#2443A8', marineblauw: '#1F2A44', navy: '#1F2A44', 'licht blauw': '#8EC5EA', lichtblauw: '#8EC5EA', babyblauw: '#A9CFEA', turquoise: '#2BB3C0', petrol: '#1F5F6B', groen: '#2E8B57', 'donker groen': '#1F4D2E', limoen: '#B5D334', mintgroen: '#A8DCC7', oranje: '#F07C22', geel: '#F2C230', roze: '#E86AA6', babyroze: '#F4C6D0', babyrose: '#F4C6D0', fuchsia: '#C2185B', paars: '#6A3D9A', bruin: '#7B4A2D', beige: '#D9C7A7', naturel: '#D9C7A7', grijs: '#8A8A86', steengrijs: '#8A8A86', 'donker grijs': '#4A4A48', zilver: '#C0C4C8', 'mat zilver': '#B9BDC2', goud: '#C9A227', transparant: 'transparent', bordeaux: '#6D1A2A', 'burgundy': '#6D1A2A' };
  function dot(name) {
    var parts = String(name).toLowerCase().split(/\s*[,/]\s*/).map(function (n) { return NAMED[n.trim()] || null; }).filter(Boolean);
    if (!parts.length) return '';
    var bg = parts.length > 1 ? 'linear-gradient(135deg,' + parts[0] + ' 50%,' + parts[1] + ' 50%)' : parts[0];
    return '<span class="mdo-dot" style="background:' + bg + '"></span>';
  }

  var mobile = window.matchMedia ? window.matchMedia('(max-width: 767px)') : { matches: false };
  st.step = 0;
  var STEPS = ['Kleur & aantal', 'Bedrukking', 'Overzicht'];

  function swatchesHtml() {
    var color = cfg.colors[st.color];
    return '<section class="mdo-sec"><div class="mdo-lbl">Kleur: <strong>' + esc(color.name) + '</strong></div><div class="mdo-swatches">' +
      cfg.colors.map(function (c, i) {
        var on = i === st.color ? ' is-on' : '';
        if (c.thumb) return '<button type="button" data-act="color" data-v="' + i + '" class="mdo-swatch' + on + '" aria-label="' + esc(c.name) + '" title="' + esc(c.name) + '"><img src="' + esc(c.thumb) + '" alt=""></button>';
        return '<button type="button" data-act="color" data-v="' + i + '" class="mdo-chip' + on + '">' + dot(c.name) + esc(c.name) + '</button>';
      }).join('') + '</div></section>';
  }
  function qtyHtml() {
    var color = cfg.colors[st.color];
    if (color.sizes) {
      return '<section class="mdo-sec"><div class="mdo-row-between"><div class="mdo-lbl">Aantal per maat</div>' + (cfg.sizeChart ? '<a class="mdo-sizechart mdo-pdf" data-title="Maattabel" href="' + esc(cfg.sizeChart) + '" target="_blank" rel="noopener">Maattabel</a>' : '') + '</div><div class="mdo-sizes">' + color.sizes.map(function (sz) {
        var q = st.sizes[sz.size] || '', out = sz.stock !== null && (q | 0) > sz.stock;
        return '<label class="mdo-size' + (out ? ' is-short' : '') + '"><span>' + esc(sz.size) + '</span><input type="number" min="0" inputmode="numeric" data-act="sizeqty" data-v="' + esc(sz.size) + '" class="mdo-input" value="' + q + '" placeholder="0"></label>';
      }).join('') + '</div><p class="mdo-muted mdo-size-total">Totaal: <strong>' + sizeTotal() + '</strong> stuks. De staffelprijs geldt voor het totaal.</p></section>';
    }
    return '<section class="mdo-sec"><div class="mdo-lbl">Aantal</div><div class="mdo-row">' + cfg.tiers.map(function (t) {
      return '<button type="button" data-act="qty" data-v="' + t + '" class="mdo-pill' + (t === st.qty ? ' is-on' : '') + '">' + t + '</button>';
    }).join('') + '<input type="number" min="1" data-act="qtyin" class="mdo-input mdo-qty" value="' + st.qty + '" aria-label="Aantal"></div></section>';
  }
  function printHtml() {
    if (!cfg.faces.length) return '';
    return '<section class="mdo-sec mdo-print"><div class="mdo-lbl">Bedrukking</div><div class="mdo-tabs">' +
      cfg.faces.map(function (f) {
        return '<button type="button" data-act="face" data-v="' + esc(f.key) + '" class="' + (f.key === st.face ? 'is-on' : '') + '">' + esc(f.label) + (st.faces[f.key].on ? ' ✓' : '') + '</button>';
      }).join('') + '</div>' + (gal ? '' : '<div data-out="preview">' + previewHtml() + '</div>') + '<div data-face="' + esc(st.face) + '">' + faceHtml(faceByKey(st.face)) + '</div></section>';
  }
  function noteHtml() {
    return '<section class="mdo-sec"><div class="mdo-row-between"><label class="mdo-lbl" for="mdo-note">Opmerking (optioneel)</label><button type="button" class="mdo-info" data-act="noteinfo" aria-label="Wat kun je bij opmerking zetten?">i</button></div><textarea id="mdo-note" data-act="note" class="mdo-input" rows="2" maxlength="500">' + esc(st.note) + '</textarea></section>';
  }
  function summaryHtml() {
    var color = cfg.colors[st.color], sels = selections();
    var rows = [['Kleur', color.name], ['Aantal', st.qty + ' stuks']];
    if (color.sizes) rows.push(['Maten', color.sizes.filter(function (sz) { return (st.sizes[sz.size] | 0) > 0; }).map(function (sz) { return st.sizes[sz.size] + '× ' + sz.size; }).join(', ') || '–']);
    if (!sels.length) rows.push(['Bedrukking', 'Blanco']);
    sels.forEach(function (s) {
      var what = s.fs.mode === 'text' ? '“' + (s.fs.text || 'Jouw tekst') + '”' : (s.fs.logo ? s.fs.logo.name : 'logo (later aanleveren)');
      rows.push([s.face.label, cfg.techniques[s.opt.tech.id].name + ', ' + s.w + '×' + s.h + ' mm' + (alignText(s) ? ', ' + alignText(s) : '') + ', ' + what]);
    });
    return '<section class="mdo-sec mdo-summary">' + rows.map(function (r) { return '<div class="mdo-row-between"><span class="mdo-muted">' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>'; }).join('') + '</section>';
  }

  // Eén "vanaf"-prijs bovenaan, inclusief de gekozen bedrukking (WooCommerce-prijs is verborgen).
  function fromHtml() {
    var color = cfg.colors[st.color], t = cfg.tiers[0] || 50, q = quote(color, t, priceSels(selections()));
    var big = cfg.tiers[cfg.tiers.length - 1], qb = quote(color, big, priceSels(selections()));
    return '<p class="mdo-from">Vanaf <strong>' + eur(qb.perPc) + '</strong> per stuk <span class="mdo-muted">incl. bedrukking · bij ' + t + ' stuks ' + eur(q.perPc) + '</span></p>';
  }
  function render() {
    var h = '<div data-out="from">' + fromHtml() + '</div>';
    if (mobile.matches) {
      // Stappen als tikbare tabs, zodat "Bedrukking" direct zichtbaar en bereikbaar is.
      h += '<div class="mdo-steptabs" role="tablist">' + STEPS.map(function (x, i) {
        return '<button type="button" role="tab" aria-selected="' + (i === st.step ? 'true' : 'false') + '" data-act="goto" data-v="' + i + '" class="' + (i === st.step ? 'is-on' : '') + (i < st.step ? ' is-done' : '') + '"><span class="mdo-stepnr">' + (i + 1) + '</span>' + esc(x) + '</button>';
      }).join('') + '</div>';
      if (st.step === 0) h += swatchesHtml() + qtyHtml();
      if (st.step === 1) h += printHtml();
      if (st.step === 2) h += summaryHtml() + noteHtml() + '<section class="mdo-sec mdo-price" data-out="price">' + priceHtml() + '</section>';
      h += '<div class="mdo-bar"><div class="mdo-bar-price" data-out="bar">' + barHtml() + '</div><div class="mdo-bar-btns">' +
        (st.step > 0 ? '<button type="button" data-act="prev" class="mdo-pill">Terug</button>' : '') +
        (st.step < 2 ? '<button type="button" data-act="next" class="button alt mdo-next">' + (st.step === 0 ? 'Bedrukking kiezen' : 'Naar overzicht') + '</button>' : '') + '</div></div>';
    } else {
      h += swatchesHtml() + qtyHtml() + printHtml() + noteHtml() + '<section class="mdo-sec mdo-price" data-out="price">' + priceHtml() + '</section>';
    }
    root.innerHTML = h;
    drawWrap();
    root.classList.toggle('is-mobile', !!mobile.matches);
    measure();
    syncGallery();
  }

  // Kleding zonder ingevulde maten: prijs bij het eerste staffelaantal tonen, niet bij 1 stuk.
  function dispQty() { var c = cfg.colors[st.color]; return c.sizes && !sizeTotal() ? (cfg.tiers[0] || 50) : st.qty; }
  function barHtml() {
    var n = dispQty(), q = quote(cfg.colors[st.color], n, priceSels(selections()));
    return '<strong>' + eur(q.total) + '</strong><span class="mdo-muted">' + eur(q.perPc) + ' per stuk' + (n !== st.qty ? ' bij ' + n + ' st.' : '') + '</span>';
  }

  // Hoofdfoto van het thema laten meewisselen met de gekozen kleur.

  // ---------- uitleg technieken (i-knop) ----------
  var INFO = [
    [/zeefdruk|screen/i, 'Inkt wordt door een fijn gaas op het product gedrukt.', ['Felle, egale kleuren', 'Voordelig bij grotere aantallen'], ['Elke extra kleur kost extra', 'Geen kleurverlopen of foto\'s']],
    [/tampon|pad/i, 'Een zachte stempel brengt de inkt over, ook op gebogen vlakken.', ['Geschikt voor kleine en gebogen oppervlakken', 'Scherpe details'], ['Klein drukvlak', 'Beperkt aantal kleuren']],
    [/laser|graveer|gravure|engrav/i, 'Het ontwerp wordt met een laser in het materiaal gegraveerd.', ['Permanent, slijt niet', 'Strak en chique'], ['Eén tint; de kleur hangt af van het materiaal']],
    [/blind|deboss/i, 'Het ontwerp wordt in het materiaal geperst, zonder inkt.', ['Subtiel en luxe'], ['Geen kleur, minder opvallend']],
    [/borduur|embroid/i, 'Het ontwerp wordt met garen in de stof geborduurd.', ['Luxe en duurzaam'], ['Geen fijne details of kleurverlopen']],
    [/doming/i, 'Een full colour sticker met een glanzende, bolle laag erover.', ['Full colour met 3D-effect'], ['Alleen op kleine, vlakke oppervlakken']],
    [/label|sticker/i, 'Een full colour label dat op het product wordt aangebracht.', ['Full colour, ook foto\'s'], ['Opgeplakt, niet direct op het materiaal gedrukt']],
    [/sublim/i, 'De inkt trekt in het materiaal.', ['Full colour', 'Slijtvast'], ['Alleen op geschikte, lichte materialen']],
    [/transfer/i, 'Het ontwerp wordt eerst op folie gedrukt en daarna met warmte overgebracht.', ['Veel kleuren mogelijk', 'Ook op textiel'], ['Duurder bij grote oppervlakken']],
    [/digita|full colour|uv/i, 'Het ontwerp wordt in full colour direct op het product geprint.', ['Full colour, ook foto\'s en kleurverlopen', 'Geen extra kosten per kleur'], ['Per stuk relatief duur bij grote aantallen']]
  ];
  function infoFor(techId) {
    var t = cfg.techniques[techId] || {};
    if (t.info) {
      var plus = [], min = [], text = [];
      String(t.info).split(/\r?\n/).forEach(function (l) {
        l = l.trim(); if (!l) return;
        if (/^\+/.test(l)) plus.push(l.replace(/^\+\s*/, '')); else if (/^[-−]/.test(l)) min.push(l.replace(/^[-−]\s*/, '')); else text.push(l);
      });
      return [text.join(' '), plus, min];
    }
    for (var i = 0; i < INFO.length; i++) if (INFO[i][0].test(t.name || '')) return [INFO[i][1], INFO[i][2], INFO[i][3]];
    return ['', [], []];
  }
  function techRows(face, fs, sel) {
    var color = cfg.colors[st.color];
    return sel.opts.map(function (o, i) {
      var inf = infoFor(o.tech.id), d = dims(o.pos, fs), on = o.key === sel.opt.key;
      var q = quote(color, st.qty, [{ tech: o.tech.id, colours: nColours(fs), w: d.w, h: d.h }]);
      var printPc = q.perPc - productPrice(color, st.qty) - cfg.manip;
      var size = o.pos.id !== face.positions[0].id ? ' <span class="mdo-muted">(' + o.pos.w + ' × ' + o.pos.h + ' mm)</span>' : '';
      return '<div class="mdo-ti' + (on ? ' is-on' : '') + '" data-act="techpick" data-v="' + esc(o.key) + '" role="radio" tabindex="0" aria-checked="' + on + '">' +
        '<div class="mdo-row-between"><strong><span class="mdo-ti-dot"></span>' + esc(cfg.techniques[o.tech.id].name) + size + '</strong><span class="mdo-ti-price">+ ' + eur(printPc) + ' p.st.</span></div>' +
        (i === 0 && sel.opts.length > 1 ? '<span class="mdo-ti-tag">Voordeligst</span>' : '') +
        (inf[0] ? '<p>' + esc(inf[0]) + '</p>' : '') +
        (inf[1].length || inf[2].length ? '<ul>' + inf[1].map(function (x) { return '<li class="plus">' + esc(x) + '</li>'; }).join('') + inf[2].map(function (x) { return '<li class="min">' + esc(x) + '</li>'; }).join('') + '</ul>' : '') +
        '</div>';
    }).join('') + '<p class="mdo-muted mdo-ti-note">Drukkosten per stuk bij ' + st.qty + ' stuks, instelkosten inbegrepen.</p>';
  }
  function openNoteInfo() {
    var ov = document.createElement('div');
    ov.className = 'mdo-sheet';
    ov.innerHTML = '<div class="mdo-sheet-in" role="dialog" aria-modal="true" aria-label="Uitleg opmerking"><div class="mdo-row-between"><strong style="font-size:17px">Opmerking bij je bestelling</strong><button type="button" class="mdo-sheet-x" aria-label="Sluiten">×</button></div>' +
      '<p>Met de knoppen Formaat, Draaien en Uitlijnen bepaal je de plek van je logo of tekst. Wil je het preciezer, zet het dan hier, bijvoorbeeld:</p>' +
      '<ul class="mdo-note-ex"><li>Logo 1 cm boven de onderrand</li><li>Tekst precies tegenover het handvat</li><li>Logo even groot als op de vorige bestelling</li><li>Bij een mok: logo rechts van het oor</li></ul>' +
      '<p class="mdo-muted">We nemen je wensen mee bij het opmaken van de drukproef. Je krijgt die eerst ter goedkeuring.</p></div>';
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.mdo-sheet-x')) ov.remove(); });
    ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') ov.remove(); });
    document.body.appendChild(ov);
  }
  function openTechInfo() {
    var face = faceByKey(st.face), fs = st.faces[face.key], sel = selection(face, fs);
    if (!sel) return;
    var color = cfg.colors[st.color];
    var rows = sel.opts.map(function (o) {
      var inf = infoFor(o.tech.id), d = dims(o.pos, fs);
      var q = quote(color, st.qty, [{ tech: o.tech.id, colours: nColours(fs), w: d.w, h: d.h }]);
      var printPc = q.perPc - productPrice(color, st.qty) - cfg.manip;
      var on = o.key === sel.opt.key;
      return '<div class="mdo-ti' + (on ? ' is-on' : '') + '" data-pick="' + esc(o.key) + '" role="button" tabindex="0" aria-pressed="' + on + '"><div class="mdo-row-between"><strong>' + esc(cfg.techniques[o.tech.id].name) + '</strong><span class="mdo-muted">+ ' + eur(printPc) + ' per stuk bij ' + st.qty + ' st.</span></div>' +
        (inf[0] ? '<p>' + esc(inf[0]) + '</p>' : '') +
        '<ul>' + inf[1].map(function (x) { return '<li class="plus">' + esc(x) + '</li>'; }).join('') + inf[2].map(function (x) { return '<li class="min">' + esc(x) + '</li>'; }).join('') + '</ul>' +
        (on ? '<span class="mdo-ti-cur">✓ Gekozen</span>' : '<span class="mdo-ti-pick">Tik om te kiezen</span>') + '</div>';
    }).join('');
    var ov = document.createElement('div');
    ov.className = 'mdo-sheet';
    ov.innerHTML = '<div class="mdo-sheet-in" role="dialog" aria-modal="true" aria-label="Uitleg technieken"><div class="mdo-row-between"><strong style="font-size:17px">Technieken voor ' + esc(face.label.toLowerCase()) + '</strong><button type="button" class="mdo-sheet-x" aria-label="Sluiten">×</button></div>' +
      '<p class="mdo-muted">Prijzen zijn de drukkosten per stuk bij het gekozen aantal, met de instelkosten verdeeld over de stuks.</p>' + rows + '</div>';
    ov.addEventListener('click', function (e) {
      var p = e.target.closest('[data-pick]');
      if (p) { fs.pick = p.getAttribute('data-pick'); fs.size = null; ov.remove(); render(); return; }
      if (e.target === ov || e.target.closest('.mdo-sheet-x')) ov.remove();
    });
    ov.addEventListener('keydown', function (e) {
      var p = e.target.closest('[data-pick]');
      if (p && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); p.click(); }
      else if (e.key === 'Escape') ov.remove();
    });
    document.body.appendChild(ov);
  }

  // Podium (links op desktop, bovenaan op mobiel): groot bedrukt voorbeeld + strook met
  // de drukposities en de gewone productfoto's van de gekozen kleur.
  var gal = document.getElementById('mdo-gallery'), galView = 'design';
  function stageHtml() {
    var c = cfg.colors[st.color], g = c.gallery || [], design = galView === 'design' || !g[galView], face = faceByKey(st.face);
    var tabs = '<div class="mdo-stage-tabs" role="tablist">' +
      '<button type="button" role="tab" data-gview="design" class="' + (design ? 'is-on' : '') + '">Jouw ontwerp</button>' +
      (g.length ? '<button type="button" role="tab" data-gview="photos" class="' + (!design ? 'is-on' : '') + '">Productfoto\'s (' + g.length + ')</button>' : '') + '</div>';
    if (design) {
      var fs = face && st.faces[face.key];
      var cap = !face ? '' : (fs && fs.on ? 'Voorbeeld op: <strong>' + esc(face.label) + '</strong>' : '<strong>' + esc(face.label) + '</strong> – niet bedrukt');
      return tabs + '<div class="mdo-stage-main"><div class="mdo-stage-design" data-out="preview">' + previewHtml() + '</div></div>' +
        (cap ? '<p class="mdo-stage-cap">' + cap + '<span class="mdo-muted"> · indicatie, je krijgt eerst een drukproef</span></p>' : '');
    }
    var cur = g[galView];
    return tabs + '<div class="mdo-stage-main"><a class="mdo-gallery-main" href="' + esc(cur.full) + '" data-act="zoom"><img src="' + esc(cur.large) + '" alt="' + esc(cur.alt || c.name) + '"></a></div>' +
      '<div class="mdo-gallery-thumbs mdo-stage-strip">' + g.map(function (x, i) {
        return '<button type="button" data-gi="' + i + '" class="' + (galView === i ? 'is-on' : '') + '" aria-label="Foto ' + (i + 1) + '"><img src="' + esc(x.thumb) + '" alt=""></button>';
      }).join('') + '</div>';
  }
  function syncGallery() {
    if (!gal) return;
    gal.classList.toggle('is-compact', !!(mobile.matches && st.step === 1));
    gal.innerHTML = stageHtml();
    drawWrap();
  }
  if (gal) {
    gal.classList.add('mdo-stage');
    gal.addEventListener('click', function (e) {
      var v = e.target.closest('[data-gview]');
      if (v) { galView = v.getAttribute('data-gview') === 'design' ? 'design' : 0; syncGallery(); return; }
      var b = e.target.closest('[data-gi]');
      if (b) { galView = parseInt(b.getAttribute('data-gi'), 10); syncGallery(); return; }
      var z = e.target.closest('[data-act="zoom"]');
      if (z) {
        e.preventDefault();
        var ov = document.createElement('div');
        ov.className = 'mdo-lightbox';
        ov.innerHTML = '<img src="' + esc(z.getAttribute('href')) + '" alt=""><button type="button" aria-label="Sluiten">&times;</button>';
        ov.addEventListener('click', function () { ov.remove(); });
        document.body.appendChild(ov);
      }
    });
  }
  if (mobile.addEventListener) mobile.addEventListener('change', render);

  function q$(sel) { return root.querySelector(sel) || (gal && gal.querySelector(sel)); }
  function soft() {
    var pv = q$('[data-out="preview"]'); if (pv) { pv.innerHTML = previewHtml(); drawWrap(); }
    var fr = root.querySelector('[data-out="from"]'); if (fr) fr.innerHTML = fromHtml();
    var pr = root.querySelector('[data-out="price"]'); if (pr) pr.innerHTML = priceHtml();
    var br = root.querySelector('[data-out="bar"]'); if (br) br.innerHTML = barHtml();
    var face = faceByKey(st.face), fs = face && st.faces[face.key];
    var so = root.querySelector('[data-out="size"]');
    if (so && fs && fs.on) { var s = selection(face, fs); if (s) so.textContent = s.w + ' × ' + s.h + ' mm · max. ' + s.opt.pos.w + ' × ' + s.opt.pos.h; }
    measure();
  }

  function measure() {
    [].slice.call(root.querySelectorAll('img[data-measure]')).concat(gal ? [].slice.call(gal.querySelectorAll('img[data-measure]')) : []).forEach(function (img) {
      var u = img.getAttribute('data-measure');
      if (imgSize[u]) return;
      var done = function () { if (img.naturalWidth) { imgSize[u] = [img.naturalWidth, img.naturalHeight]; soft(); } };
      if (img.complete) done(); else img.addEventListener('load', done, { once: true });
    });
  }

  // ---------- interactie ----------
  function curFace() { return st.faces[st.face]; }

  root.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act],[data-act="techpick"]'); if (!b) return;
    galView = 'design';
    var a = b.getAttribute('data-act'), v = b.getAttribute('data-v'), fs = curFace();
    if (a === 'color') { st.color = parseInt(v, 10); galView = 'design'; if (cfg.colors[st.color].sizes) st.qty = Math.max(1, sizeTotal()); }
    else if (a === 'qty') st.qty = parseInt(v, 10);
    else if (a === 'face') st.face = v;
    else if (a === 'mode') { fs.mode = v; fs.pick = null; }
    else if (a === 'colours') { fs.colours = v; fs.pick = null; }
    else if (a === 'font') { fs.font = v; st.fontOpen = false; loadFont(v); }
    else if (a === 'fontopen') { st.fontOpen = !st.fontOpen; if (st.fontOpen) Object.keys(FONTS).forEach(loadFont); }
    else if (a === 'ink') fs.ink = v;
    else if (a === 'techinfo') { openTechInfo(); return; }
    else if (a === 'techpick') { if (fs.pick === v) return; fs.pick = v; fs.size = null; }
    else if (a === 'al') fs.al = v;
    else if (a === 'va') fs.va = v;
    else if (a === 'noteinfo') { openNoteInfo(); return; }
    else if (a === 'rot') { fs.rot = parseInt(v, 10); fs.size = null; }
    else if (a === 'goto') { st.step = parseInt(v, 10); render(); return; }
    else if (a === 'next') { st.step = Math.min(2, st.step + 1); render(); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    else if (a === 'prev') { st.step = Math.max(0, st.step - 1); render(); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    else return;
    render();
  });

  root.addEventListener('keydown', function (e) {
    var t = e.target.closest && e.target.closest('[data-act="techpick"]');
    if (t && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); t.click(); }
  });

  root.addEventListener('change', function (e) {
    var t = e.target, a = t.getAttribute('data-act'), fs = curFace();
    if (a === 'toggle') { fs.on = t.checked; render(); }
    else if (a === 'tech') { fs.pick = t.value; fs.size = null; render(); }
    else if (a === 'fontsel') { fs.font = t.value; render(); loadFont(fs.font); }
    else if (a === 'inkpick') { fs.ink = t.value; render(); }
    else if (a === 'qtyin') { var q = parseInt(t.value, 10); if (q > 0) { st.qty = q; render(); } }
    else if (a === 'sizeqty') {
      var n = Math.max(0, parseInt(t.value, 10) || 0), k = t.getAttribute('data-v');
      if (n) st.sizes[k] = n; else delete st.sizes[k];
      st.qty = Math.max(1, sizeTotal());
      render();
    }
    else if (a === 'logo' && t.files && t.files[0]) upload(t.files[0], fs);
  });

  root.addEventListener('input', function (e) {
    var t = e.target, a = t.getAttribute('data-act'), fs = curFace();
    if (a === 'size') { fs.size = parseInt(t.value, 10); soft(); }
    else if (a === 'text') { fs.text = t.value; soft(); }
    else if (a === 'inkpick') { fs.ink = t.value; soft(); }
    else if (a === 'pms') { fs.pms = t.value; }
    else if (a === 'note') st.note = t.value;
  });

  function upload(file, fs) {
    var fd = new FormData();
    fd.append('action', 'mdo_logo'); fd.append('nonce', cfg.nonce); fd.append('file', file);
    // Direct een voorbeeld tonen van het eigen bestand; het uploaden loopt op de achtergrond.
    var local = /^image\/(png|jpe?g|gif|webp|svg\+xml)$/.test(file.type) && window.URL ? URL.createObjectURL(file) : '';
    fs.logo = { name: file.name + ' (uploaden…)', token: '', preview: local };
    render();
    fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
      if (r && r.success) { fs.logo = { name: r.data.name, token: r.data.token, preview: local || r.data.preview }; }
      else { fs.logo = null; alert((r && r.data) || 'Upload mislukt.'); }
      render();
    }).catch(function () { fs.logo = null; alert('Upload mislukt.'); render(); });
  }

  // ---------- bestellen ----------
  root.addEventListener('click', function (e) {
    if (!e.target.closest('.mdo-buy')) return;
    var pending = selections().some(function (s) { return s.fs.logo && !s.fs.logo.token; });
    if (pending) { alert('Je logo wordt nog geüpload. Wacht een paar seconden en probeer het dan opnieuw.'); return; }
    var color = cfg.colors[st.color];
    var prints = selections().map(function (s) {
      return {
        position: s.opt.pos.id, tech: s.opt.tech.id, colours: s.colours, mode: s.fs.mode, w: s.w, h: s.h,
        logo: s.fs.logo ? s.fs.logo.token : '', logoname: s.fs.logo ? s.fs.logo.name : '',
        text: s.fs.text, font: s.fs.font, ink: inkOf(s.fs), pms: s.fs.pms, rot: s.rot, al: s.al, va: s.va
      };
    });
    form.querySelector('[name="variation_id"]').value = color.id;
    var cfgOut = { prints: prints, note: st.note };
    if (color.sizes) {
      if (!sizeTotal()) return;
      cfgOut.sizes = {};
      color.sizes.forEach(function (sz) { if ((st.sizes[sz.size] | 0) > 0) cfgOut.sizes[sz.size] = st.sizes[sz.size] | 0; });
      st.qty = sizeTotal();
    }
    form.querySelector('[name="mdo_config"]').value = JSON.stringify(cfgOut);
    form.querySelector('[name="quantity"]').value = st.qty;
    form.querySelectorAll('.mdo-attr').forEach(function (n) { n.remove(); });
    Object.keys(color.attrs).forEach(function (k) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = color.attrs[k]; i.className = 'mdo-attr'; form.appendChild(i);
    });
    form.submit();
  });

  if (cfg.colors[st.color] && cfg.colors[st.color].sizes) st.qty = 1;
  render();
  loadFont('poppins');
  loadDemo();
})();
