/* PromoFun – pdf's (maattabel, verklaringen) in een venster op de eigen pagina tonen. */
(function () {
  'use strict';
  var C = window.MDO_PDF || {};
  var libLoading = null;

  function loadLib() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (libLoading) return libLoading;
    libLoading = new Promise(function (ok, fail) {
      var s = document.createElement('script');
      s.src = C.lib;
      s.onload = function () {
        if (!window.pdfjsLib) return fail(new Error('pdf.js'));
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = C.worker;
        ok(window.pdfjsLib);
      };
      s.onerror = fail;
      document.head.appendChild(s);
    });
    return libLoading;
  }

  function open(url, title) {
    var ov = document.createElement('div');
    ov.className = 'mdo-pdf-ov';
    ov.innerHTML = '<div class="mdo-pdf-box" role="dialog" aria-modal="true" aria-label="' + (title || 'Document') + '">' +
      '<div class="mdo-pdf-head"><strong></strong><span><a class="mdo-pdf-dl" target="_blank" rel="noopener">Downloaden</a><button type="button" class="mdo-pdf-x" aria-label="Sluiten">&times;</button></span></div>' +
      '<div class="mdo-pdf-pages"><p class="mdo-pdf-msg">Laden…</p></div></div>';
    ov.querySelector('strong').textContent = title || 'Document';
    ov.querySelector('.mdo-pdf-dl').href = url;
    document.body.appendChild(ov);
    document.documentElement.classList.add('mdo-pdf-open');
    function close() { ov.remove(); document.documentElement.classList.remove('mdo-pdf-open'); document.removeEventListener('keydown', esc); }
    function esc(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', esc);
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.mdo-pdf-x')) close(); });

    var pages = ov.querySelector('.mdo-pdf-pages');
    loadLib().then(function (lib) {
      return lib.getDocument({ url: url }).promise;
    }).then(function (pdf) {
      pages.innerHTML = '';
      var width = pages.clientWidth - 16, dpr = Math.min(2, window.devicePixelRatio || 1), chain = Promise.resolve();
      for (var n = 1; n <= pdf.numPages; n++) {
        (function (n) {
          chain = chain.then(function () { return pdf.getPage(n); }).then(function (page) {
            var v0 = page.getViewport({ scale: 1 }), scale = width / v0.width, v = page.getViewport({ scale: scale * dpr });
            var c = document.createElement('canvas');
            c.width = v.width; c.height = v.height; c.style.width = (v.width / dpr) + 'px';
            pages.appendChild(c);
            return page.render({ canvasContext: c.getContext('2d'), viewport: v }).promise;
          });
        })(n);
      }
      return chain;
    }).catch(function () {
      // Lukt tonen niet: gewoon de pdf openen.
      close();
      window.open(url, '_blank', 'noopener');
    });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a.mdo-pdf');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    open(a.href, a.getAttribute('data-title'));
  });

  var css = '.mdo-pdf-open,.mdo-pdf-open body{overflow:hidden}' +
    '.mdo-pdf-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:12px}' +
    '.mdo-pdf-box{background:#fff;border-radius:12px;width:min(900px,100%);height:min(92vh,100%);display:flex;flex-direction:column;overflow:hidden}' +
    '.mdo-pdf-head{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #eee;gap:10px}' +
    '.mdo-pdf-head span{display:flex;align-items:center;gap:14px}' +
    '.mdo-pdf-dl{font-size:14px;text-decoration:underline}' +
    '.mdo-pdf-x{background:none!important;border:0!important;font-size:28px;line-height:1;cursor:pointer;padding:0 4px!important;color:#333!important;min-height:0}' +
    '.mdo-pdf-pages{flex:1;overflow:auto;background:#F3F3F3;padding:8px;display:flex;flex-direction:column;align-items:center;gap:8px}' +
    '.mdo-pdf-pages canvas{background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.15);max-width:100%;height:auto}' +
    '.mdo-pdf-msg{margin:auto;color:#646970}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
})();
