/*
 * ds.js — comportamiento del design system (vanilla, sin deps).
 *
 * Estado de carga de botones: poné data-loading-text en un botón y al enviar su
 * formulario se deshabilita, muestra un spinner y cambia el texto. Si el form
 * navega (POST clásico) no hay que hacer nada más. Si el flujo es fetch y NO
 * navega, resetear manualmente en el .then/.catch con window.dsBtn.reset(btn).
 *
 *   <button class="ds-cta" data-loading-text="Procesando…">Ingresar</button>
 *
 * Ver context/11-design-system.md §loading.
 */
(function () {
  'use strict';

  function start(btn) {
    if (!btn || btn.classList.contains('is-loading')) { return; }
    btn._dsOriginalHTML = btn.innerHTML;
    var loadingText = btn.getAttribute('data-loading-text') || btn.textContent;
    btn.classList.add('is-loading');
    btn.setAttribute('aria-busy', 'true');
    btn.disabled = true;
    btn.textContent = loadingText;
  }

  function reset(btn) {
    if (!btn || !btn.classList.contains('is-loading')) { return; }
    btn.classList.remove('is-loading');
    btn.removeAttribute('aria-busy');
    btn.disabled = false;
    if (btn._dsOriginalHTML != null) {
      btn.innerHTML = btn._dsOriginalHTML;
      delete btn._dsOriginalHTML;
    }
  }

  // Auto: al enviar un form, arrancar el loading del submit con data-loading-text.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') { return; }
    var btn = e.submitter || form.querySelector('[type="submit"]');
    if (btn && btn.hasAttribute('data-loading-text')) { start(btn); }
  }, true);

  window.dsBtn = { start: start, reset: reset };
})();
