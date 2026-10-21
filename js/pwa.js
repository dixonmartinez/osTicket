/* ---------------------------------------------------------------------------
 * js/pwa.js — registro del service worker y ayudas de instalación
 * ---------------------------------------------------------------------------
 * Se incluye en el panel de agentes y en el portal del cliente. Si el
 * navegador no soporta service workers, no hace nada (la aplicación sigue
 * funcionando exactamente igual).
 *
 * No usa variables globales ni rutas absolutas: deduce la raíz de osTicket a
 * partir de su propia etiqueta <script>, de modo que funciona igual en /,
 * /support/ o en un subdirectorio.
 * ------------------------------------------------------------------------- */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator))
        return;

    var script = document.currentScript;
    var base = '/';
    if (script && script.src) {
        // .../js/pwa.js -> .../
        base = script.src.replace(/js\/pwa\.js(?:\?.*)?$/, '');
    }

    // Evita registrar dos veces si el encabezado se recarga por PJAX.
    if (window.__ostSwRegistered)
        return;
    window.__ostSwRegistered = true;

    window.addEventListener('load', function () {
        navigator.serviceWorker.register(base + 'sw.js', { scope: base })
            .catch(function () {
                /* Sin service worker la app funciona igual: sólo se pierde
                   el arranque rápido desde la pantalla de inicio. */
            });
    });
})();
