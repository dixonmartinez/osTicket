/**
 * js/actions.js — acciones declarativas (sin onclick en el marcado)
 *
 * osTicket arrastra atributos `onclick=` por todas las plantillas. Una CSP
 * sin `'unsafe-inline'` los bloquea, así que hay que sacarlos del HTML. Este
 * archivo da los casos más repetidos, con la misma funcionalidad y sin tocar
 * el aspecto de los botones:
 *
 *   data-href="url"            navega a la url (botones «Cancel»)
 *   data-go-back               vuelve a la página anterior
 *   data-submit-on-change      en un <select>: envía el formulario al cambiar
 *   data-dialog="201"          abre `$.dialog(url, 201)` con la url del href
 *                              (`#algo` → `algo`), como hacía el onclick.
 *                              Con data-dialog-url="..." se usa esa url y con
 *                              data-dialog-size="large" se pasa el tamaño
 *   data-confirm="¿Seguro?"    pide confirmación; si se cancela, no hace nada
 *                              (también en un <form>: confirma antes de enviar)
 *
 * Todo se resuelve con delegación en `document`, así que funciona también con
 * el contenido que carga PJAX o los diálogos por AJAX. Si un elemento no tiene
 * el atributo, no se toca: es una capa aditiva.
 */
(function (window, document) {
    'use strict';

    var CLICKABLE = '[data-href],[data-go-back],[data-dialog],[data-confirm]';

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if (el.matches ? el.matches(selector) : false)
                return el;
            el = el.parentElement;
        }

        return null;
    }

    function confirmed(el) {
        var message = el.getAttribute('data-confirm');

        return !message || window.confirm(message);
    }

    function dialogUrl(el) {
        var href = el.getAttribute('href') || '';

        /* El onclick clásico hacía `$(this).attr('href').substr(1)`: el href
           venía como `#ajax.php/...`. Se aceptan las dos formas. */
        return href.charAt(0) === '#' ? href.substring(1) : href;
    }

    document.addEventListener('click', function (event) {
        var el = closest(event.target, CLICKABLE);

        if (!el || event.defaultPrevented)
            return;

        if (!confirmed(el)) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        if (el.hasAttribute('data-go-back')) {
            event.preventDefault();
            window.history.back();
            return;
        }

        if (el.hasAttribute('data-href')) {
            event.preventDefault();
            window.location.href = el.getAttribute('data-href');
            return;
        }

        if (el.hasAttribute('data-dialog')) {
            var url = el.getAttribute('data-dialog-url') || dialogUrl(el);
            var size = parseInt(el.getAttribute('data-dialog'), 10) || 201;
            var options = el.getAttribute('data-dialog-size');

            if (!url || !window.jQuery || !window.jQuery.dialog)
                return;

            event.preventDefault();
            window.jQuery.dialog(url, size, null, options ? {size: options} : null);
        }
    }, false);

    document.addEventListener('change', function (event) {
        var el = event.target;

        if (!el || !el.hasAttribute || !el.hasAttribute('data-submit-on-change'))
            return;

        if (el.form && !el.disabled)
            el.form.submit();
    }, false);

    /* Un formulario puede llevar data-confirm (borrados) o el botón que lo
       envía. En captura, para llegar antes que el resto de manejadores. */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var submitter = event.submitter;
        var message = form.getAttribute('data-confirm')
            || (submitter && submitter.getAttribute('data-confirm'));

        if (message && !window.confirm(message))
            event.preventDefault();
    }, true);
})(window, document);
