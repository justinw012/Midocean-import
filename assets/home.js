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
