/* Winkelwagen: klik op de staffeltip zet het aantal en werkt de winkelwagen bij. */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-mdo-qty]');
    if (!a) return;
    e.preventDefault();
    var form = a.closest('form') || document.querySelector('.woocommerce-cart-form');
    var key = a.getAttribute('data-mdo-key');
    var input = form && form.querySelector('input[name="cart[' + key + '][qty]"]');
    if (!input) return;
    input.value = a.getAttribute('data-mdo-qty');
    input.dispatchEvent(new Event('change', { bubbles: true }));
    var btn = form.querySelector('[name="update_cart"]');
    if (btn) { btn.disabled = false; btn.removeAttribute('aria-disabled'); btn.click(); }
  });
})();
