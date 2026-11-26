/* PromoFun homepage: voorbeeldproducten bovenaan laten rouleren (pauzeert bij aanwijzen; vegen op telefoon). */
(function () {
  'use strict';
  var box = document.querySelector('[data-mdo-rotate]');
  if (!box) return;
  var sl = [].slice.call(box.querySelectorAll('.mdo-h-slide')), dots = [].slice.call(box.querySelectorAll('.mdo-h-dots button'));
  if (sl.length < 2) return;
  var i = 0, timer = 0, paused = false;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function show(n) {
    sl[i].classList.remove('is-on'); sl[i].setAttribute('aria-hidden', 'true'); sl[i].tabIndex = -1;
    if (dots[i]) dots[i].removeAttribute('aria-current');
    i = (n + sl.length) % sl.length;
    sl[i].classList.add('is-on'); sl[i].removeAttribute('aria-hidden'); sl[i].removeAttribute('tabindex');
    if (dots[i]) dots[i].setAttribute('aria-current', 'true');
  }
  function start() { clearInterval(timer); if (!reduce) timer = setInterval(function () { if (!paused && !document.hidden) show(i + 1); }, 4500); }
  dots.forEach(function (d, k) { d.addEventListener('click', function () { show(k); start(); }); });
  box.addEventListener('mouseenter', function () { paused = true; });
  box.addEventListener('mouseleave', function () { paused = false; });
  box.addEventListener('focusin', function () { paused = true; });
  box.addEventListener('focusout', function () { paused = false; });
  var x0 = null;
  box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  box.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 40) { show(i + (dx < 0 ? 1 : -1)); start(); }
  });
  start();
})();

/* "Probeer je logo": logo van de bezoeker alleen in de browser verwerken (niet uploaden) en op alle voorbeelden zetten. */
(function () {
  'use strict';
  var sec = document.getElementById('mdo-h-try');
  if (!sec) return;
  var inp = sec.querySelector('.mdo-h-file'), reset = sec.querySelector('.mdo-h-reset'), msg = sec.querySelector('.mdo-h-try-msg');
  function say(t, bad) { msg.textContent = t; msg.classList.toggle('is-bad', !!bad); }
  function state() { var on = window.MDO_LOGO && window.MDO_LOGO.own(); reset.hidden = !on; sec.classList.toggle('has-own', !!on); }
  // Witte achtergrond weghalen (bij jpg's), randen bijsnijden, verkleinen tot max. 800 px.
  function prepare(img) {
    var max = 800, w = img.naturalWidth || 800, h = img.naturalHeight || 400, k = Math.min(1, max / Math.max(w, h));
    var c = document.createElement('canvas'); c.width = Math.max(1, Math.round(w * k)); c.height = Math.max(1, Math.round(h * k));
    var x = c.getContext('2d'); x.drawImage(img, 0, 0, c.width, c.height);
    var d = x.getImageData(0, 0, c.width, c.height), p = d.data, i, see = false;
    for (i = 3; i < p.length; i += 16) if (p[i] < 250) { see = true; break; }
    if (!see) {
      for (i = 0; i < p.length; i += 4) {
        var m = Math.min(p[i], p[i + 1], p[i + 2]);
        if (m > 238) p[i + 3] = 0; else if (m > 215) p[i + 3] = Math.round(255 * (238 - m) / 23);
      }
      x.putImageData(d, 0, 0);
    }
    var l = c.width, r = -1, t = c.height, b = -1, y, X;
    for (y = 0; y < c.height; y++) for (X = 0; X < c.width; X++) if (p[(y * c.width + X) * 4 + 3] > 24) { if (X < l) l = X; if (X > r) r = X; if (y < t) t = y; if (y > b) b = y; }
    if (r < l || b < t) return null;
    var o = document.createElement('canvas'); o.width = r - l + 1; o.height = b - t + 1;
    o.getContext('2d').drawImage(c, l, t, o.width, o.height, 0, 0, o.width, o.height);
    return o.toDataURL('image/png');
  }
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0];
    inp.value = '';
    if (!f) return;
    if (!/^image\/(png|jpe?g|webp|svg\+xml)$/.test(f.type)) { say('Kies een PNG, JPG of SVG. Een PDF of AI kun je bij het bestellen uploaden; voor dit voorbeeld is een afbeelding nodig.', true); return; }
    if (!window.MDO_LOGO) { say('Het voorbeeld kan nu niet geladen worden.', true); return; }
    say('Even bezig…');
    var url = URL.createObjectURL(f), im = new Image();
    im.onload = function () {
      var data = null;
      try { data = prepare(im); } catch (e) {}
      URL.revokeObjectURL(url);
      if (!data) { say('Dit bestand lukt niet als voorbeeld. Probeer een PNG met doorzichtige achtergrond.', true); return; }
      var kept = window.MDO_LOGO.set(data);
      say(kept ? 'Je logo staat nu op deze producten, en ook in de shop als je verder kijkt.' : 'Je logo staat nu op deze producten.');
      state();
    };
    im.onerror = function () { URL.revokeObjectURL(url); say('Dit bestand lukt niet als voorbeeld. Probeer een PNG of JPG.', true); };
    im.src = url;
  });
  reset.addEventListener('click', function () { window.MDO_LOGO.clear(); say(''); state(); });
  // shop.js laadt na dit script: status pas bepalen als alles klaar is.
  if (document.readyState === 'complete') state(); else window.addEventListener('load', state);
})();
