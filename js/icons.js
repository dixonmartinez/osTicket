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
 *   4. Los iconos dibujados en SVG (ver SVG_ICONS) se cambian por un `<svg>`
 *      en línea con las mismas clases, el mismo tamaño (1em) y el color
 *      heredado (currentColor). Los que no están en el mapa siguen con la
 *      fuente de Font Awesome: la sustitución es aditiva, nunca rompe un
 *      icono que no se haya dibujado.
 *
 * No cambia el marcado de las plantillas: el `<i class="icon-x">` de origen se
 * sustituye en el navegador, así que las plantillas y las hojas de estilo
 * siguen igual. Para desactivarlo, `data-svg-icons="off"` en <html>.
 *
 * Se reejecuta tras cada navegación PJAX y cuando aparecen iconos nuevos
 * (diálogos, AJAX), con un observador de mutaciones limitado a los nodos que
 * aún no se han revisado.
 */
(function (window, document) {
    'use strict';

    var PROCESSED = 'ostIconChecked';
    var scheduled = false;
    var SVG_NS = 'http://www.w3.org/2000/svg';

    /* Iconos dibujados a mano sobre una rejilla de 24×24, trazo de 2 px y
       esquinas redondeadas. Solo hacen falta los que más se repiten en las
       plantillas (medido con grep sobre include/ y scp/): el resto sigue
       usando Font Awesome. Para añadir uno, basta con una entrada más. */
    var SVG_ICONS = {
        'icon-home': '<path d="M3 10.6 12 3l9 7.6V21h-6v-6H9v6H3z"/>',
        'icon-dashboard': '<path d="M4 18a8 8 0 1 1 16 0"/><path d="M12 18l4-6"/>',
        'icon-tachometer': '<path d="M4 18a8 8 0 1 1 16 0"/><path d="M12 18l4-6"/>',
        'icon-ticket': '<path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a3 3 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a3 3 0 0 0 0-6z"/>',
        'icon-tasks': '<path d="M4 4h16v16H4z"/><path d="M8 12.5l2.8 2.8L16 9.5"/>',
        'icon-check-square': '<path d="M4 4h16v16H4z"/><path d="M8 12.5l2.8 2.8L16 9.5"/>',
        'icon-plus': '<path d="M12 5v14M5 12h14"/>',
        'icon-plus-sign': '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'icon-plus-circle': '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'icon-minus': '<path d="M5 12h14"/>',
        'icon-remove': '<path d="M6 6l12 12M18 6L6 18"/>',
        'icon-times': '<path d="M6 6l12 12M18 6L6 18"/>',
        'icon-close': '<path d="M6 6l12 12M18 6L6 18"/>',
        'icon-remove-sign': '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'icon-remove-circle': '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'icon-times-circle': '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'icon-ok': '<path d="M4 12.5l5.5 5.5L20 6.5"/>',
        'icon-check': '<path d="M4 12.5l5.5 5.5L20 6.5"/>',
        'icon-ok-sign': '<circle cx="12" cy="12" r="9"/><path d="M8 12.2l2.7 2.7L16 9.5"/>',
        'icon-ok-circle': '<circle cx="12" cy="12" r="9"/><path d="M8 12.2l2.7 2.7L16 9.5"/>',
        'icon-check-circle': '<circle cx="12" cy="12" r="9"/><path d="M8 12.2l2.7 2.7L16 9.5"/>',
        'icon-question-sign': '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .9-1 1.6v.4"/><path d="M12 16.8h.01"/>',
        'icon-question-circle': '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .9-1 1.6v.4"/><path d="M12 16.8h.01"/>',
        'icon-info-sign': '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.6h.01"/>',
        'icon-info-circle': '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.6h.01"/>',
        'icon-warning-sign': '<path d="M12 3.5 22 20H2z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
        'icon-exclamation-triangle': '<path d="M12 3.5 22 20H2z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
        'icon-ban-circle': '<circle cx="12" cy="12" r="9"/><path d="M6 18 18 6"/>',
        'icon-ban': '<circle cx="12" cy="12" r="9"/><path d="M6 18 18 6"/>',
        'icon-lock': '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10"/>',
        'icon-unlock': '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8.5 10V7.5a3.5 3.5 0 0 1 6.8-1.2"/>',
        'icon-user': '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        'icon-users': '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 6.6"/><path d="M17.5 14.4A6.5 6.5 0 0 1 21.5 20"/>',
        'icon-group': '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 6.6"/><path d="M17.5 14.4A6.5 6.5 0 0 1 21.5 20"/>',
        'icon-edit': '<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="M14 6l4 4"/>',
        'icon-pencil': '<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="M14 6l4 4"/>',
        'icon-trash': '<path d="M4 7h16"/><path d="M9.5 4.5h5"/><path d="M6.5 7 7.6 20h8.8L17.5 7"/><path d="M10 11v5M14 11v5"/>',
        'icon-trash-o': '<path d="M4 7h16"/><path d="M9.5 4.5h5"/><path d="M6.5 7 7.6 20h8.8L17.5 7"/><path d="M10 11v5M14 11v5"/>',
        'icon-sort': '<path d="M8 20V4"/><path d="M4.5 7.5 8 4l3.5 3.5"/><path d="M16 4v16"/><path d="M12.5 16.5 16 20l3.5-3.5"/>',
        'icon-sort-down': '<path d="M6 9h12l-6 7z" fill="currentColor" stroke="none"/>',
        'icon-caret-down': '<path d="M6 9h12l-6 7z" fill="currentColor" stroke="none"/>',
        'icon-sort-up': '<path d="M6 15h12l-6-7z" fill="currentColor" stroke="none"/>',
        'icon-caret-up': '<path d="M6 15h12l-6-7z" fill="currentColor" stroke="none"/>',
        'icon-caret-left': '<path d="M15 6v12l-7-6z" fill="currentColor" stroke="none"/>',
        'icon-caret-right': '<path d="M9 6v12l7-6z" fill="currentColor" stroke="none"/>',
        'icon-chevron-down': '<path d="M6 9.5l6 6 6-6"/>',
        'icon-chevron-up': '<path d="M6 14.5l6-6 6 6"/>',
        'icon-chevron-left': '<path d="M14.5 6l-6 6 6 6"/>',
        'icon-chevron-right': '<path d="M9.5 6l6 6-6 6"/>',
        'icon-search': '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
        'icon-refresh': '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20.5 4.5v4.2h-4.2"/>',
        'icon-repeat': '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20.5 4.5v4.2h-4.2"/>',
        'icon-save': '<path d="M4 4h11l5 5v11H4z"/><path d="M8 4v6h6V4"/><path d="M8 20v-5h8v5"/>',
        'icon-filter': '<path d="M3 5h18l-7 8.2V20l-4-2.2v-4.6z"/>',
        'icon-file': '<path d="M6 3h8l6 6v12H6z"/><path d="M14 3v6h6"/>',
        'icon-file-text': '<path d="M6 3h8l6 6v12H6z"/><path d="M14 3v6h6"/><path d="M9 13h6M9 16.5h6"/>',
        'icon-file-text-alt': '<path d="M6 3h8l6 6v12H6z"/><path d="M14 3v6h6"/><path d="M9 13h6M9 16.5h6"/>',
        'icon-paperclip': '<path d="M19.5 11.5 12 19a4.2 4.2 0 0 1-6-6l8.2-8.2a2.8 2.8 0 0 1 4 4L10 17a1.4 1.4 0 0 1-2-2l7-7"/>',
        'icon-paper-clip': '<path d="M19.5 11.5 12 19a4.2 4.2 0 0 1-6-6l8.2-8.2a2.8 2.8 0 0 1 4 4L10 17a1.4 1.4 0 0 1-2-2l7-7"/>',
        'icon-reorder': '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'icon-bars': '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'icon-link': '<path d="M10 14a4 4 0 0 1 0-5.7l2.3-2.3a4 4 0 0 1 5.7 5.7l-1.3 1.3"/><path d="M14 10a4 4 0 0 1 0 5.7l-2.3 2.3a4 4 0 0 1-5.7-5.7l1.3-1.3"/>',
        'icon-undo': '<path d="M4 10h9.5a5.5 5.5 0 0 1 0 11H9"/><path d="M7.5 6 4 10l3.5 4"/>',
        'icon-reply': '<path d="M4 10h9.5a5.5 5.5 0 0 1 0 11H9"/><path d="M7.5 6 4 10l3.5 4"/>',
        'icon-share': '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.8l7.6-3.6M8.2 13.2l7.6 3.6"/>',
        'icon-share-alt': '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.8l7.6-3.6M8.2 13.2l7.6 3.6"/>',
        'icon-globe': '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a13 13 0 0 1 0 18 13 13 0 0 1 0-18z"/>',
        'icon-asterisk': '<path d="M12 4v16M5.1 8l13.8 8M18.9 8 5.1 16"/>',
        'icon-code-fork': '<circle cx="7" cy="5" r="2"/><circle cx="7" cy="19" r="2"/><circle cx="17" cy="8" r="2"/><path d="M7 7v10"/><path d="M17 10v2a4 4 0 0 1-4 4H7"/>',
        'icon-envelope': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7 12 13l8.5-6"/>',
        'icon-envelope-alt': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7 12 13l8.5-6"/>',
        'icon-calendar': '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'icon-time': '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
        'icon-clock-o': '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
        'icon-folder-close': '<path d="M3 6.5h6l2 2h10V20H3z"/>',
        'icon-folder': '<path d="M3 6.5h6l2 2h10V20H3z"/>',
        'icon-folder-open': '<path d="M3 6.5h6l2 2h10V9H8l-2 11H3z"/><path d="M6 20h15l2-9H9z"/>',
        'icon-folder-open-alt': '<path d="M3 6.5h6l2 2h10V9H8l-2 11H3z"/><path d="M6 20h15l2-9H9z"/>',
        'icon-print': '<path d="M7 8V3h10v5"/><rect x="3" y="8" width="18" height="8" rx="1.5"/><path d="M7 13h10v8H7z"/>',
        'icon-download': '<path d="M12 3v11"/><path d="M8 10.5 12 14.5l4-4"/><path d="M4 17v3h16v-3"/>',
        'icon-download-alt': '<path d="M12 3v11"/><path d="M8 10.5 12 14.5l4-4"/><path d="M4 17v3h16v-3"/>',
        'icon-upload': '<path d="M12 15V4"/><path d="M8 7.5 12 3.5l4 4"/><path d="M4 17v3h16v-3"/>',
        'icon-external-link': '<path d="M13 4h7v7"/><path d="M20 4l-9 9"/><path d="M18 14v5a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19V7.5A1.5 1.5 0 0 1 5 6h5"/>',
        'icon-comment': '<path d="M3.5 5.5h17v11h-9l-5 4v-4h-3z"/>',
        'icon-comments': '<path d="M3.5 4.5h13v9h-6l-4 3v-3h-3z"/><path d="M19 8.5h1.5v9h-2.5v3l-4-3h-3"/>',
        'icon-bell': '<path d="M6 17V11a6 6 0 0 1 12 0v6l2 2H4z"/><path d="M10 21h4"/>',
        'icon-star': '<path d="M12 3.5l2.7 5.6 6.1.8-4.5 4.3 1.1 6.1L12 17.4 6.6 20.3l1.1-6.1L3.2 9.9l6.1-.8z" fill="currentColor" stroke="none"/>',
        'icon-flag': '<path d="M6 21V3"/><path d="M6 4h11l-2 4 2 4H6z"/>',
        'icon-eye-open': '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'icon-eye': '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'icon-list': '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
        'icon-list-alt': '<rect x="3.5" y="4" width="17" height="16" rx="2"/><path d="M7 9h10M7 13h10M7 17h6"/>',
        'icon-th-list': '<rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="M3.5 10h17M3.5 14.5h17M9 5v14"/>',
        'icon-arrow-left': '<path d="M20 12H4"/><path d="M9 7l-5 5 5 5"/>',
        'icon-arrow-right': '<path d="M4 12h16"/><path d="M15 7l5 5-5 5"/>',
        'icon-arrow-up': '<path d="M12 20V4"/><path d="M7 9l5-5 5 5"/>',
        'icon-arrow-down': '<path d="M12 4v16"/><path d="M7 15l5 5 5-5"/>',
        'icon-cloud': '<path d="M7 18a4 4 0 0 1 0-8 5.5 5.5 0 0 1 10.5-1A3.5 3.5 0 0 1 17.5 18z"/>',
        'icon-database': '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'icon-bullhorn': '<path d="M4 10v4h3l9 4V6l-9 4z"/><path d="M7 14v5h3v-4"/>',
        'icon-tag': '<path d="M4 4h7l9 9-7 7-9-9z"/><circle cx="8" cy="8" r="1.2" fill="currentColor" stroke="none"/>',
        'icon-tags': '<path d="M3 3h6l8 8-6 6-8-8z"/><path d="M14 4l6 6-6 6"/>',
        'icon-bold': '<path d="M7 4h6a4 4 0 0 1 0 8H7z"/><path d="M7 12h7a4 4 0 0 1 0 8H7z"/>',
        'icon-italic': '<path d="M10 4h8M6 20h8M14 4 10 20"/>',
        'icon-underline': '<path d="M7 4v7a5 5 0 0 0 10 0V4"/><path d="M5 21h14"/>',
        'icon-cog': '<circle cx="12" cy="12" r="3.2"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
        'icon-gear': '<circle cx="12" cy="12" r="3.2"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>'
    };

    function accessibleName(el) {
        return el.getAttribute('aria-label')
            || el.getAttribute('aria-labelledby')
            || el.getAttribute('title')
            || (el.textContent || '').trim();
    }

    function svgIconsDisabled() {
        var root = document.documentElement;

        return root.getAttribute('data-svg-icons') === 'off';
    }

    function svgNameFor(icon) {
        var names = (icon.getAttribute('class') || '').split(/\s+/);
        var i;

        for (i = 0; i < names.length; i++)
            if (SVG_ICONS.hasOwnProperty(names[i]))
                return names[i];

        return null;
    }

    /**
     * Cambia <i class="icon-x"> por un SVG en línea equivalente.
     *
     * Se conserva la lista de clases (para que el tamaño, el color, los
     * márgenes y los `pull-right` de las hojas de estilo sigan aplicando),
     * el estilo en línea y el color heredado. Si el navegador no puede
     * construir el SVG, se deja el <i> original con Font Awesome.
     */
    function toSvg(icon, name) {
        if (icon.namespaceURI !== 'http://www.w3.org/1999/xhtml')
            return;

        // Solo iconos vacíos: nunca se come texto ni otros nodos.
        if (icon.children.length || (icon.textContent || '').trim().length)
            return;

        var svg = document.createElementNS(SVG_NS, 'svg');

        try {
            svg.innerHTML = SVG_ICONS[name];
        } catch (e) {
            return;
        }

        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('data-icon', name);
        svg.setAttribute('class', 'ost-icon ' + (icon.getAttribute('class') || ''));

        if (icon.getAttribute('style'))
            svg.setAttribute('style', icon.getAttribute('style'));

        if (icon.getAttribute('title'))
            svg.setAttribute('title', icon.getAttribute('title'));

        svg.setAttribute(PROCESSED, '1');
        icon.parentNode.replaceChild(svg, icon);
    }

    /**
     * Un enlace o botón que solo lleva un icono no tiene nombre accesible.
     * Se le copia el `title` (que en osTicket describe la acción) para que el
     * lector de pantalla no anuncie un botón vacío.
     */
    function nameParent(parent) {
        if ((parent.textContent || '').trim().length > 0)
            return;

        if (parent.getAttribute('aria-label')
                || parent.getAttribute('aria-labelledby')
                || parent.getAttribute(PROCESSED))
            return;

        if (parent.getAttribute('title'))
            parent.setAttribute('aria-label', parent.getAttribute('title'));

        parent.setAttribute(PROCESSED, '1');
    }

    function processIcons(root) {
        var icons = root.querySelectorAll('i[class*="icon-"]');
        var i, icon, parent;

        for (i = 0; i < icons.length; i++) {
            icon = icons[i];

            if (icon.getAttribute('aria-hidden') === null)
                icon.setAttribute('aria-hidden', 'true');

            // Dibujo en SVG cuando el icono está en el mapa (ver SVG_ICONS).
            var name = svgNameFor(icon);

            if (name && !svgIconsDisabled()) {
                // El nombre accesible se resuelve antes de mover el nodo:
                // después, el icono ya no cuelga del enlace.
                if (icon.parentNode) {
                    parent = icon.closest('a, button');

                    if (parent)
                        nameParent(parent);
                }

                toSvg(icon, name);
                continue;
            }

            icon.setAttribute(PROCESSED, '1');

            // El icono no es el único contenido: el texto del enlace se lee.
            parent = icon.closest('a, button');

            if (!parent)
                continue;

            nameParent(parent);
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
