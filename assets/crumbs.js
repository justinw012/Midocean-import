/* PromoFun – compact kruimelpad in plaats van de grote grijze titelbalk van het thema.
 * Zoekt de titelbalk (titel + kruimelpad samen in één blok, zonder productinhoud), verbergt die
 * en zet er één smalle regel voor in de plaats. Vindt hij het blok niet zeker, dan verandert er niets. */
(function () {
  'use strict';
  var C = window.MDO_CRUMBS || {};
  function outside(el) { return !el.closest('.summary, .mdo-home, .mdo-filter, #mdo-configurator, .mdo, ul.products, .widget, footer, #colophon'); }
  var crumb = [].slice.call(document.querySelectorAll('.woocommerce-breadcrumb, .breadcrumb-trail, .breadcrumbs, .breadcrumb, nav[aria-label*="readcrumb"], [class*="breadcrumb"]')).filter(outside)[0];
  if (!crumb) return;
  // Titel zoeken in de blokken rond het kruimelpad (max. 4 niveaus omhoog); dat blok is de titelbalk.
  var head = null, box = crumb.parentNode;
  for (var lv = 0; lv < 4 && box && box !== document.body; lv++, box = box.parentNode) {
    head = [].slice.call(box.querySelectorAll('h1, h2, h3, .page-title, .entry-title')).filter(function (h) { return !crumb.contains(h) && outside(h); })[0];
    if (head) break;
  }
  if (!head) return;
  if (!box || box === document.body || box.matches('main, #main, #content, #primary, .site-content, #page, .site')) return;
  if (box.querySelector('div.product, .type-product, ul.products, #mdo-filter, #mdo-configurator, .summary, form.cart')) return;
  if (box.getBoundingClientRect().height > 600) return;

  // Kruimels overnemen (links + huidige pagina).
  var links = [].slice.call(crumb.querySelectorAll('a')).map(function (a) { return { t: a.textContent.trim(), u: a.href }; }).filter(function (l) { return l.t; });
  var current = (head ? head.textContent : crumb.textContent.split(/[\/›»>]/).pop()).trim();
  if (!links.length) return;
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function cap(s) { return s && s === s.toUpperCase() ? s.charAt(0) + s.slice(1).toLowerCase() : s; }
  var parent = links[links.length - 1];
  var nav = document.createElement('nav');
  nav.className = 'mdo-crumbs';
  nav.setAttribute('aria-label', 'Kruimelpad');
  nav.innerHTML = '<a class="mdo-crumbs-back" href="' + esc(parent.u) + '"><span aria-hidden="true">‹</span> ' + esc(cap(parent.t)) + '</a>' +
    '<ol>' + links.map(function (l) { return '<li><a href="' + esc(l.u) + '">' + esc(cap(l.t)) + '</a></li>'; }).join('') +
    '<li aria-current="page">' + esc(current) + '</li></ol>';

  var product = document.querySelector('div.product.type-product, .single-product div.product');
  var wrap = document.createElement('div');
  wrap.className = 'mdo-crumbs-wrap';
  wrap.appendChild(nav);
  if (product) {
    product.parentNode.insertBefore(wrap, product);
  } else {
    // Categorie/zoekpagina: titel blijft, maar klein en links uitgelijnd.
    if (head) { var h1 = document.createElement('h1'); h1.className = 'mdo-crumbs-title'; h1.textContent = head.textContent.trim(); wrap.appendChild(h1); }
    var anchor = document.getElementById('mdo-filter') || document.querySelector('.woocommerce-products-header, ul.products, .woocommerce-notices-wrapper');
    if (!anchor) return;
    anchor.parentNode.insertBefore(wrap, anchor);
  }
  box.classList.add('mdo-banner-hide');
})();
