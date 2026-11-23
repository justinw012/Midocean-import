/* Midocean configurator – prijsregels gelijk aan includes/class-mdo-pricing.php */
(function () {
  'use strict';

  var root = document.getElementById('mdo-configurator');
  if (!root) return;
  var cfg = JSON.parse(root.getAttribute('data-config'));
  var form = root.closest('form');

  var FONTS = { sans: ['Strak', 'Helvetica, Arial, sans-serif'], serif: ['Klassiek', 'Georgia, "Times New Roman", serif'], script: ['Speels', '"Brush Script MT", "Segoe Script", cursive'] };
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
  function quote(color, q, sels) {
    q = Math.max(1, q | 0);
    var unit = scaleAt(color.scales, q)[1], setup = 0;
    sels.forEach(function (s) { var r = printCost(cfg.techniques[s.tech], s.colours, s.w * s.h / 100, q); unit += r[0]; setup += r[1]; });
    if (sels.length) unit += cfg.manip;
    var total = unit * q + setup;
    return { unit: unit, setup: setup, total: total, perPc: total / q };
  }
  function eur(n) { return cfg.currency + ' ' + n.toFixed(2).replace('.', cfg.decimalSep); }

  // ---------- staat ----------
  var st = {
    color: 0, qty: 100, face: cfg.faces.length ? cfg.faces[0].key : null, view: 'design', note: '',
    faces: {}
  };
  cfg.faces.forEach(function (f, i) {
    st.faces[f.key] = { on: i === 0, mode: 'logo', colours: '1', pick: null, size: null, text: '', font: 'sans', ink: null, logo: null };
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
  function options(face, fs) {
    var out = [];
    face.positions.forEach(function (p) {
      p.techs.forEach(function (t) {
        if (!allowed(t, fs)) return;
        var w = Math.min(p.w, fs.size || p.w * 0.6), h = Math.min(p.h, w * aspect(fs));
        var c = quote(cfg.colors[st.color], st.qty, [{ tech: t.id, colours: nColours(fs), w: w, h: h }]);
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
  function aspect(fs) {
    if (fs.mode === 'text') return textBox(fs).aspect;
    if (fs.logo && fs.logo.preview && imgSize[fs.logo.preview]) { var d = imgSize[fs.logo.preview]; return d[1] / d[0]; }
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
    var w = Math.max(5, Math.min(p.w, fs.size || Math.round(p.w * 0.6)));
    var h = Math.max(3, Math.min(p.h, Math.round(w * aspect(fs))));
    return { face: face, fs: fs, opt: ch.opt, opts: ch.opts, w: w, h: h, colours: nColours(fs) };
  }
  function selections() {
    var out = [];
    cfg.faces.forEach(function (f) { var fs = st.faces[f.key]; if (fs.on) { var s = selection(f, fs); if (s) out.push(s); } });
    return out;
  }
  function priceSels(sels) { return sels.map(function (s) { return { tech: s.opt.tech.id, colours: s.colours, w: s.w, h: s.h }; }); }

  // ---------- weergave ----------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function effectInk(effect) {
    if (effect === 'engrave') return isDark() ? '#C9CDD2' : '#5A5E64';
    if (effect === 'deboss') return isDark() ? 'rgba(255,255,255,0.18)' : 'rgba(0,0,0,0.28)';
    return null;
  }
  function demoLogo(dark, effect) {
    var ink = effectInk(effect) || (dark ? '#FFFFFF' : '#1D1A2E');
    var accent = effect ? ink : '#DF3550';
    return '<svg viewBox="0 0 220 110" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Voorbeeldlogo"><circle cx="48" cy="55" r="40" fill="' + ink + '"/><path d="M30 70 L48 32 L66 70 Z" fill="' + (effect ? (isDark() ? '#1E1E1E' : '#FFFFFF') : accent) + '"/><text x="100" y="52" font-family="Helvetica, Arial, sans-serif" font-size="30" font-weight="800" fill="' + ink + '">Jouw</text><text x="100" y="84" font-family="Helvetica, Arial, sans-serif" font-size="30" font-weight="800" fill="' + ink + '">Logo</text></svg>';
  }

  function previewHtml() {
    var face = faceByKey(st.face); if (!face) return '';
    var fs = st.faces[face.key], color = cfg.colors[st.color];
    var sel = fs.on ? selection(face, fs) : null;
    var pos = sel ? sel.opt.pos : face.positions[0];
    var url = pos.images[color.code] || pos.images[Object.keys(pos.images)[0]] || color.image;
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
        art = '<img src="' + esc(fs.logo.preview) + '" alt="Jouw logo" class="' + (effect ? 'mdo-fx-' + effect + (isDark() ? ' on-dark' : '') : '') + '">';
      } else if (fs.logo) {
        art = '<span class="mdo-art-file">' + esc(fs.logo.name) + '</span>';
      } else {
        art = demoLogo(isDark(), effect);
      }
      zone = '<div class="mdo-zone" style="left:' + zl + '%;top:' + zt + '%;width:' + zw + '%;height:' + zh + '%"><div class="mdo-art" style="width:' + artW + '%;height:' + artH + '%">' + art + '</div></div>';
    }
    return '<div class="mdo-preview"><div class="mdo-photo"><img src="' + esc(url) + '" alt="' + esc(face.label) + ', ' + esc(color.name) + '" data-measure="' + esc(url) + '">' + zone + '</div>' +
      (dims ? '' : '<p class="mdo-muted">Foto laden…</p>') + '</div>';
  }

  function priceHtml() {
    var color = cfg.colors[st.color], sels = selections();
    var q = quote(color, st.qty, priceSels(sels));
    var next = cfg.tiers.filter(function (t) { return t > st.qty; })[0];
    var tiers = cfg.tiers.map(function (t) {
      var x = quote(color, t, priceSels(sels));
      return '<div class="mdo-tier' + (t === st.qty ? ' is-on' : '') + '"><span>' + t + ' st.</span><span>' + eur(x.perPc) + '</span></div>';
    }).join('');
    var short = color.stock !== null && st.qty > color.stock, block = short && !color.backorders;
    var stockMsg = !short ? '' : '<p class="mdo-warn">' + (color.stock > 0 ? 'Nog ' + color.stock + ' stuks op voorraad in ' + esc(color.name) + '.' : 'Op dit moment niet op voorraad in ' + esc(color.name) + '.') + (block ? '' : ' Je kunt wel bestellen; de levertijd is dan langer.') + '</p>';
    return '<div class="mdo-total"><span class="mdo-total-num">' + eur(q.total) + '</span><span class="mdo-muted">' + eur(q.perPc) + ' per stuk, alles inbegrepen' + (q.setup ? ' (incl. ' + eur(q.setup) + ' instelkosten)' : '') + '</span></div>' +
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
      h += '<div class="mdo-row"><span class="mdo-lbl">Kleuren in je logo</span>' + COLOUR_OPTS.map(function (k) {
        return '<button type="button" data-act="colours" data-v="' + k + '" class="mdo-pill' + (fs.colours === k ? ' is-on' : '') + '">' + (k === 'fc' ? 'Full colour' : k) + '</button>';
      }).join('') + '</div>';
    } else {
      h += '<input type="text" class="mdo-input" data-act="text" maxlength="60" placeholder="Jouw tekst, bijv. je bedrijfsnaam" value="' + esc(fs.text) + '">';
      h += '<div class="mdo-row">' + Object.keys(FONTS).map(function (k) {
        return '<button type="button" data-act="font" data-v="' + k + '" class="mdo-pill' + (fs.font === k ? ' is-on' : '') + '" style="font-family:' + FONTS[k][1] + '">' + FONTS[k][0] + '</button>';
      }).join('') + (sel && effectOf(sel.opt.tech.id) ? '' : INKS.map(function (c) {
        return '<button type="button" data-act="ink" data-v="' + c + '" class="mdo-ink' + (inkOf(fs) === c ? ' is-on' : '') + '" style="background:' + c + '" aria-label="Tekstkleur ' + c + '"></button>';
      }).join('')) + '</div>';
      if (sel && effectOf(sel.opt.tech.id)) {
        h += '<p class="mdo-muted">' + (effectOf(sel.opt.tech.id) === 'engrave' ? 'Bij lasergravering wordt je tekst in het materiaal gegraveerd; de kleur hangt af van het materiaal.' : 'Bij blinddruk wordt je tekst in het materiaal geperst, zonder inkt.') + '</p>';
      }
    }
    if (!sel) return h + '<p class="mdo-warn">Voor deze keuze is op ' + esc(face.label.toLowerCase()) + ' geen techniek beschikbaar.</p>';
    var p = sel.opt.pos;
    h += '<label class="mdo-size"><span class="mdo-row-between"><span class="mdo-lbl">Formaat</span><span class="mdo-muted" data-out="size">' + sel.w + ' × ' + sel.h + ' mm · max. ' + p.w + ' × ' + p.h + '</span></span>' +
      '<input type="range" data-act="size" min="5" max="' + p.w + '" step="1" value="' + sel.w + '"></label>';
    h += '<label class="mdo-lbl" for="mdo-tech-' + face.key + '">Techniek</label><select id="mdo-tech-' + face.key + '" data-act="tech" class="mdo-input">' + sel.opts.map(function (o, i) {
      return '<option value="' + esc(o.key) + '"' + (o.key === sel.opt.key ? ' selected' : '') + '>' + esc(cfg.techniques[o.tech.id].name) + (o.pos.id !== face.positions[0].id ? ' (' + o.pos.w + '×' + o.pos.h + ' mm)' : '') + (i === 0 ? ' – voordeligst' : '') + '</option>';
    }).join('') + '</select>';
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
    return '<section class="mdo-sec"><div class="mdo-lbl">Aantal</div><div class="mdo-row">' + cfg.tiers.map(function (t) {
      return '<button type="button" data-act="qty" data-v="' + t + '" class="mdo-pill' + (t === st.qty ? ' is-on' : '') + '">' + t + '</button>';
    }).join('') + '<input type="number" min="1" data-act="qtyin" class="mdo-input mdo-qty" value="' + st.qty + '" aria-label="Aantal"></div></section>';
  }
  function printHtml() {
    if (!cfg.faces.length) return '';
    return '<section class="mdo-sec mdo-print"><div class="mdo-lbl">Bedrukking</div><div class="mdo-tabs">' +
      cfg.faces.map(function (f) {
        return '<button type="button" data-act="face" data-v="' + esc(f.key) + '" class="' + (f.key === st.face ? 'is-on' : '') + '">' + esc(f.label) + (st.faces[f.key].on ? ' ✓' : '') + '</button>';
      }).join('') + '</div><div data-out="preview">' + previewHtml() + '</div><div data-face="' + esc(st.face) + '">' + faceHtml(faceByKey(st.face)) + '</div></section>';
  }
  function noteHtml() {
    return '<section class="mdo-sec"><label class="mdo-lbl" for="mdo-note">Opmerking (optioneel)</label><textarea id="mdo-note" data-act="note" class="mdo-input" rows="2" maxlength="500">' + esc(st.note) + '</textarea></section>';
  }
  function summaryHtml() {
    var color = cfg.colors[st.color], sels = selections();
    var rows = [['Kleur', color.name], ['Aantal', st.qty + ' stuks']];
    if (!sels.length) rows.push(['Bedrukking', 'Blanco']);
    sels.forEach(function (s) {
      var what = s.fs.mode === 'text' ? '“' + (s.fs.text || 'Jouw tekst') + '”' : (s.fs.logo ? s.fs.logo.name : 'logo (later aanleveren)');
      rows.push([s.face.label, cfg.techniques[s.opt.tech.id].name + ', ' + s.w + '×' + s.h + ' mm, ' + what]);
    });
    return '<section class="mdo-sec mdo-summary">' + rows.map(function (r) { return '<div class="mdo-row-between"><span class="mdo-muted">' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>'; }).join('') + '</section>';
  }

  function render() {
    var h = '';
    if (mobile.matches) {
      h += '<div class="mdo-steps"><span class="mdo-muted">Stap ' + (st.step + 1) + ' van 3 · ' + STEPS[st.step] + '</span><div class="mdo-progress">' +
        STEPS.map(function (x, i) { return '<span class="' + (i <= st.step ? 'is-on' : '') + '"></span>'; }).join('') + '</div></div>';
      if (st.step === 0) h += swatchesHtml() + qtyHtml();
      if (st.step === 1) h += printHtml();
      if (st.step === 2) h += summaryHtml() + noteHtml() + '<section class="mdo-sec mdo-price" data-out="price">' + priceHtml() + '</section>';
      h += '<div class="mdo-bar"><div class="mdo-bar-price" data-out="bar">' + barHtml() + '</div><div class="mdo-bar-btns">' +
        (st.step > 0 ? '<button type="button" data-act="prev" class="mdo-pill">Terug</button>' : '') +
        (st.step < 2 ? '<button type="button" data-act="next" class="button alt mdo-next">Volgende</button>' : '') + '</div></div>';
    } else {
      h += swatchesHtml() + qtyHtml() + printHtml() + noteHtml() + '<section class="mdo-sec mdo-price" data-out="price">' + priceHtml() + '</section>';
    }
    root.innerHTML = h;
    root.classList.toggle('is-mobile', !!mobile.matches);
    measure();
    syncGallery();
  }

  function barHtml() {
    var q = quote(cfg.colors[st.color], st.qty, priceSels(selections()));
    return '<strong>' + eur(q.total) + '</strong><span class="mdo-muted">' + eur(q.perPc) + ' per stuk</span>';
  }

  // Hoofdfoto van het thema laten meewisselen met de gekozen kleur.
  var lastImg = null;
  function syncGallery() {
    var c = cfg.colors[st.color];
    if (!c.image || c.image === lastImg) return;
    lastImg = c.image;
    var img = document.querySelector('.woocommerce-product-gallery__image img, .woocommerce-product-gallery img.wp-post-image');
    if (!img) return;
    img.removeAttribute('srcset'); img.removeAttribute('sizes');
    img.src = c.image;
    if (img.hasAttribute('data-src')) img.setAttribute('data-src', c.image);
    if (img.hasAttribute('data-large_image')) img.setAttribute('data-large_image', c.image);
    var a = img.closest('a'); if (a) a.href = c.image;
  }
  if (mobile.addEventListener) mobile.addEventListener('change', render);

  function soft() {
    var pv = root.querySelector('[data-out="preview"]'); if (pv) pv.innerHTML = previewHtml();
    var pr = root.querySelector('[data-out="price"]'); if (pr) pr.innerHTML = priceHtml();
    var br = root.querySelector('[data-out="bar"]'); if (br) br.innerHTML = barHtml();
    var face = faceByKey(st.face), fs = face && st.faces[face.key];
    var so = root.querySelector('[data-out="size"]');
    if (so && fs && fs.on) { var s = selection(face, fs); if (s) so.textContent = s.w + ' × ' + s.h + ' mm · max. ' + s.opt.pos.w + ' × ' + s.opt.pos.h; }
    measure();
  }

  function measure() {
    root.querySelectorAll('img[data-measure]').forEach(function (img) {
      var u = img.getAttribute('data-measure');
      if (imgSize[u]) return;
      var done = function () { if (img.naturalWidth) { imgSize[u] = [img.naturalWidth, img.naturalHeight]; soft(); } };
      if (img.complete) done(); else img.addEventListener('load', done, { once: true });
    });
  }

  // ---------- interactie ----------
  function curFace() { return st.faces[st.face]; }

  root.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]'); if (!b) return;
    var a = b.getAttribute('data-act'), v = b.getAttribute('data-v'), fs = curFace();
    if (a === 'color') st.color = parseInt(v, 10);
    else if (a === 'qty') st.qty = parseInt(v, 10);
    else if (a === 'face') st.face = v;
    else if (a === 'mode') { fs.mode = v; fs.pick = null; }
    else if (a === 'colours') { fs.colours = v; fs.pick = null; }
    else if (a === 'font') fs.font = v;
    else if (a === 'ink') fs.ink = v;
    else if (a === 'next') { st.step = Math.min(2, st.step + 1); render(); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    else if (a === 'prev') { st.step = Math.max(0, st.step - 1); render(); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    else return;
    render();
  });

  root.addEventListener('change', function (e) {
    var t = e.target, a = t.getAttribute('data-act'), fs = curFace();
    if (a === 'toggle') { fs.on = t.checked; render(); }
    else if (a === 'tech') { fs.pick = t.value; fs.size = null; render(); }
    else if (a === 'qtyin') { var q = parseInt(t.value, 10); if (q > 0) { st.qty = q; render(); } }
    else if (a === 'logo' && t.files && t.files[0]) upload(t.files[0], fs);
  });

  root.addEventListener('input', function (e) {
    var t = e.target, a = t.getAttribute('data-act'), fs = curFace();
    if (a === 'size') { fs.size = parseInt(t.value, 10); soft(); }
    else if (a === 'text') { fs.text = t.value; soft(); }
    else if (a === 'note') st.note = t.value;
  });

  function upload(file, fs) {
    var fd = new FormData();
    fd.append('action', 'mdo_logo'); fd.append('nonce', cfg.nonce); fd.append('file', file);
    fs.logo = { name: file.name + ' (uploaden…)', token: '', preview: '' };
    render();
    fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
      if (r && r.success) { fs.logo = { name: r.data.name, token: r.data.token, preview: r.data.preview }; }
      else { fs.logo = null; alert((r && r.data) || 'Upload mislukt.'); }
      render();
    }).catch(function () { fs.logo = null; alert('Upload mislukt.'); render(); });
  }

  // ---------- bestellen ----------
  root.addEventListener('click', function (e) {
    if (!e.target.closest('.mdo-buy')) return;
    var color = cfg.colors[st.color];
    var prints = selections().map(function (s) {
      return {
        position: s.opt.pos.id, tech: s.opt.tech.id, colours: s.colours, mode: s.fs.mode, w: s.w, h: s.h,
        logo: s.fs.logo ? s.fs.logo.token : '', logoname: s.fs.logo ? s.fs.logo.name : '',
        text: s.fs.text, font: s.fs.font, ink: inkOf(s.fs)
      };
    });
    form.querySelector('[name="variation_id"]').value = color.id;
    form.querySelector('[name="quantity"]').value = st.qty;
    form.querySelector('[name="mdo_config"]').value = JSON.stringify({ prints: prints, note: st.note });
    form.querySelectorAll('.mdo-attr').forEach(function (n) { n.remove(); });
    Object.keys(color.attrs).forEach(function (k) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = color.attrs[k]; i.className = 'mdo-attr'; form.appendChild(i);
    });
    form.submit();
  });

  render();
})();
