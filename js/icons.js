/**
 * js/icons.js — accesibilidad de los iconos
 *
 * osTicket usa la fuente de iconos de Font Awesome: cada icono es un
 * `<i class="icon-algo">` cuyo dibujo sale de un pseudo-elemento. Los lectores
 * de pantalla leen ese carácter como basura («círculo, signo más»), así que se
 * marcan como decorativos.
 *
 * Qué hace:
 *   1. `<i class="icon-...">` sin texto → `aria-hidden="true"` (decorativo).
 *   2. Ese mismo `<i>` dentro de un enlace o botón que no tiene más texto y
 *      tampoco nombre accesible (aria-label/aria-labelledby/title) → se copia
 *      el `title` del enlace al `aria-label`, para que el botón no quede mudo.
 *   3. `<svg>` en línea → `focusable="false"`, que en navegadores antiguos
 *      metía el SVG en el orden de tabulación.
 *
 * No cambia el marcado de las plantillas ni sustituye la fuente de iconos: eso
 * es una migración aparte (ver docs/UI_GUIDE.md, «siguientes pasos»).
 *
 * Se reejecuta tras cada navegación PJAX y cuando aparecen iconos nuevos
 * (diálogos, AJAX), con un observador de mutaciones limitado a los nodos que
 * aún no se han revisado.
 */
(function (window, document) {
    'use strict';

    var PROCESSED = 'ostIconChecked';
    var scheduled = false;

    function accessibleName(el) {
        return el.getAttribute('aria-label')
            || el.getAttribute('aria-labelledby')
            || el.getAttribute('title')
            || (el.textContent || '').trim();
    }

    function processIcons(root) {
        var icons = root.querySelectorAll('i[class*="icon-"]');
        var i, icon, parent;

        for (i = 0; i < icons.length; i++) {
            icon = icons[i];

            if (icon.getAttribute('aria-hidden') === null)
                icon.setAttribute('aria-hidden', 'true');

            icon.setAttribute(PROCESSED, '1');

            // El icono no es el único contenido: el texto del enlace se lee.
            parent = icon.closest('a, button');

            if (!parent)
                continue;

            if ((parent.textContent || '').trim().length > 0)
                continue;

            if (parent.getAttribute('aria-label')
                    || parent.getAttribute('aria-labelledby')
                    || parent.getAttribute(PROCESSED))
                continue;

            // Enlace solo-icono: se le da nombre con su title, si lo tiene.
            if (parent.getAttribute('title'))
                parent.setAttribute('aria-label', parent.getAttribute('title'));

            parent.setAttribute(PROCESSED, '1');
        }

        var svgs = root.querySelectorAll('svg:not([focusable])');
        for (i = 0; i < svgs.length; i++) {
            svgs[i].setAttribute('focusable', 'false');

            if (svgs[i].getAttribute('aria-hidden') === null
                    && !svgs[i].getAttribute('role')
                    && !svgs[i].getAttribute('aria-label'))
                svgs[i].setAttribute('aria-hidden', 'true');
        }
    }

    function run() {
        scheduled = false;

        if (!document.body)
            return;

        processIcons(document.body);
    }

    function schedule() {
        if (scheduled)
            return;

        scheduled = true;

        if (window.requestAnimationFrame)
            window.requestAnimationFrame(run);
        else
            window.setTimeout(run, 16);
    }

    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', schedule);
    else
        schedule();

    // Navegación sin recarga (el panel usa PJAX para casi todo)
    if (window.jQuery) {
        window.jQuery(document).on('pjax:end', schedule);
        window.jQuery(document).on('pjax:success', schedule);
    }

    // Contenido nuevo: diálogos, AJAX, listas cargadas al vuelo
    if (window.MutationObserver) {
        var observer = new MutationObserver(function (records) {
            var i, j, added;

            for (i = 0; i < records.length; i++) {
                added = records[i].addedNodes;

                for (j = 0; j < added.length; j++) {
                    if (added[j].nodeType !== 1)
                        continue;

                    if (added[j].matches
                            && (added[j].matches('i[class*="icon-"], svg')
                                || added[j].querySelector('i[class*="icon-"], svg'))) {
                        schedule();
                        return;
                    }
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            observer.observe(document.body, { childList: true, subtree: true });
        });
    }
}(window, document));
