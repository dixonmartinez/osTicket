/**
 * js/dialog-focus.js — teclado y foco en los diálogos
 *
 * Los diálogos de osTicket (`.dialog#popup`, `.dialog#alert`,
 * `.dialog#confirm-action` y los de jQuery UI) se muestran encima de la
 * página, pero el foco seguía en el documento: con Tab se salía del diálogo y
 * al cerrarlo el foco se perdía al principio de la página. Eso deja fuera a
 * quien navega con teclado o lector de pantalla.
 *
 * Qué hace, sin tocar el marcado de los diálogos:
 *   1. Al abrirse uno, guarda el elemento que tenía el foco y lleva el foco
 *      dentro (al primer campo, o al primero con autofocus).
 *   2. Con Tab y Shift+Tab el foco cicla dentro del diálogo visible.
 *   3. Al cerrarlo (Escape, botón de cerrar, acción completada) devuelve el
 *      foco al elemento que lo abrió, si sigue en la página.
 *
 * Escape ya cerraba los diálogos en scp/js/scp.js; aquí no se duplica.
 *
 * No depende de scp.js: si existe `$.toggleOverlay` (panel de agentes) se
 * engancha a él, y en el portal del cliente funciona con los eventos de foco y
 * con un observador de mutaciones sobre `.ui-dialog`.
 */
(function ($) {
    'use strict';

    if (!$)
        return;

    var FOCUSABLE = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    var opener = null;          // elemento que tenía el foco al abrir
    var lastFocusWithin = null; // último foco dentro del diálogo
    var isOpen = false;         // estado del diálogo en el tick anterior

    /* Solo cuentan los diálogos modales: en el panel son `.dialog` con
       position:fixed (los de jQuery UI, `.ui-dialog`). Se excluyen los que
       solo se usan como maqueta o los paneles fijos de la propia página. */
    function visibleDialog() {
        var $dialog = $('.dialog:visible').filter(function () {
            var $el = $(this);
            return $el.css('display') !== 'none'
                && $el.css('position') === 'fixed';
        });

        if (!$dialog.length)
            $dialog = $('.ui-dialog:visible');

        // El último abierto es el que está encima (z-index mayor)
        return $dialog.last();
    }

    function focusables($root) {
        return $root.find(FOCUSABLE).filter(function () {
            var $el = $(this);
            return $el.is(':visible')
                && $el.css('visibility') !== 'hidden'
                && $el.attr('aria-hidden') !== 'true';
        });
    }

    function focusFirst($dialog) {
        var $auto = $dialog.find('[autofocus]').filter(':visible');
        var $target = $auto.length ? $auto.first() : focusables($dialog).first();

        if ($target.length) {
            $target.trigger('focus');
            return true;
        }

        // Diálogo sin controles: se enfoca el contenedor para que el lector
        // de pantalla anuncie su contenido.
        if (!$dialog.attr('tabindex'))
            $dialog.attr('tabindex', '-1');

        $dialog.trigger('focus');
        return false;
    }

    function onOpen($dialog) {
        if (!$dialog || !$dialog.length)
            return;

        var active = document.activeElement;
        if (!$dialog.is(active) && !$.contains($dialog[0], active))
            opener = active;

        // Los diálogos se muestran justo después de este punto: se espera al
        // siguiente tick para buscar los controles ya visibles.
        window.setTimeout(function () {
            var $current = visibleDialog();
            if (!$current.length)
                return;

            var active2 = document.activeElement;
            if (!$current.is(active2) && !$.contains($current[0], active2))
                focusFirst($current);
        }, 0);
    }

    function onClose() {
        var previous = opener;
        opener = null;
        lastFocusWithin = null;

        if (!previous || !document.contains(previous))
            return;

        window.setTimeout(function () {
            // Si mientras tanto se abrió otro diálogo, no se roba el foco.
            if (visibleDialog().length)
                return;

            try {
                previous.focus({ preventScroll: false });
            } catch (e) {
                previous.focus();
            }
        }, 0);
    }

    function onTab(event) {
        if (event.key !== 'Tab' && event.keyCode !== 9)
            return;

        var $dialog = visibleDialog();
        if (!$dialog.length)
            return;

        var $items = focusables($dialog);
        if (!$items.length) {
            event.preventDefault();
            $dialog.trigger('focus');
            return;
        }

        var first = $items[0];
        var last = $items[$items.length - 1];
        var active = document.activeElement;
        var inside = $dialog.is(active) || $.contains($dialog[0], active);

        if (!inside) {
            // El foco se escapó (clic en el fondo, PJAX): se trae de vuelta.
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
            return;
        }

        if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function trackFocus(event) {
        var $dialog = visibleDialog();
        if ($dialog.length && $.contains($dialog[0], event.target))
            lastFocusWithin = event.target;
    }

    /* Un único punto de entrada: mira si hay diálogo visible y reacciona
       solo cuando cambia el estado. Así da igual quién lo abra (scp.js,
       jQuery UI, un script de un plugin) y no se repite el trabajo en cada
       mutación del DOM. */
    var scheduled = false;

    function sync() {
        scheduled = false;

        var $dialog = visibleDialog();

        if ($dialog.length && !isOpen) {
            isOpen = true;
            onOpen($dialog);
        } else if (!$dialog.length && isOpen) {
            isOpen = false;
            onClose();
        }
    }

    function scheduleSync() {
        if (scheduled)
            return;

        scheduled = true;

        if (window.requestAnimationFrame)
            window.requestAnimationFrame(sync);
        else
            window.setTimeout(sync, 16);
    }

    // --- Enganches -----------------------------------------------------

    // Panel de agentes: todas las aperturas y cierres pasan por aquí.
    if (typeof $.toggleOverlay === 'function') {
        var toggleOverlay = $.toggleOverlay;

        $.toggleOverlay = function () {
            var result = toggleOverlay.apply(this, arguments);
            scheduleSync();
            return result;
        };
    }

    // Portal del cliente y diálogos de jQuery UI: se detectan por el foco y
    // por la aparición del elemento en el DOM.
    $(document).on('focusin', function (event) {
        var $dialog = visibleDialog();
        if (!$dialog.length)
            return;

        if ($.contains($dialog[0], event.target)) {
            lastFocusWithin = event.target;
            return;
        }

        // Foco fuera del diálogo abierto: se devuelve dentro.
        var $items = focusables($dialog);
        if ($items.length) {
            event.stopPropagation();
            $items.first().trigger('focus');
        }
    });

    $(document).on('keydown', onTab);

    if (window.MutationObserver) {
        var observer = new MutationObserver(scheduleSync);

        document.addEventListener('DOMContentLoaded', function () {
            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['style', 'class']
            });

            // Un diálogo puede venir ya abierto en el HTML.
            scheduleSync();
        });
    }
}(window.jQuery));
