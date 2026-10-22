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
 *   data-remove-closest="tr"   borra del DOM el ancestro que case con el
 *                              selector (filas, condiciones, idiomas…)
 *   data-toggle-target="#id"   muestra u oculta el destino; con
 *                              data-toggle-mode="show|hide|toggle" (por
 *                              defecto toggle), data-toggle-when="valor"
 *                              para decidir por el valor del control y
 *                              data-toggle-speed="200"
 *   data-inherit-toggle        en un checkbox: muestra `.if-not-inherited`
 *                              de su tabla según el estado del checkbox
 *   data-save-draft            llama a `saveDraft()` (redactor de osTicket)
 *   data-discard-draft[="url"] descarta los borradores del editor del
 *                              formulario y recarga (o va a `url`)
 *   data-reload                recarga la página
 *
 * Todo se resuelve con delegación en `document`, así que funciona también con
 * el contenido que carga PJAX o los diálogos por AJAX. Si un elemento no tiene
 * el atributo, no se toca: es una capa aditiva.
 */
(function (window, document) {
    'use strict';

    var CLICKABLE = '[data-href],[data-go-back],[data-dialog],[data-confirm],'
        + '[data-remove-closest],[data-save-draft],[data-discard-draft],'
        + '[data-toggle-target],[data-reload]';

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

    /* Muestra u oculta un destino. Se usa jQuery (ya cargado en las dos
       interfaces) para conservar la animación de `.show(200)`. */
    function toggleTarget(el) {
        var selector = el.getAttribute('data-toggle-target');
        var mode = el.getAttribute('data-toggle-mode') || 'toggle';
        var speed = el.getAttribute('data-toggle-speed');

        if (!selector)
            return;

        /* `data-toggle-when="valor"`: muestra el destino sólo cuando el
           control vale eso (y lo esconde en caso contrario). */
        var when = el.getAttribute('data-toggle-when');

        if (when !== null)
            mode = el.value === when ? 'show' : 'hide';

        if (window.jQuery) {
            var $target = window.jQuery(selector);

            if (speed)
                $target[mode](parseInt(speed, 10) || undefined);
            else
                $target[mode]();

            return;
        }

        var nodes = document.querySelectorAll(selector);
        var i;

        for (i = 0; i < nodes.length; i++)
            nodes[i].style.display = mode === 'hide' ? 'none' : '';
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

        /* Va antes del borrado: hay enlaces que quitan una fila y, además,
           muestran un aviso (`data-toggle-target` + `data-remove-closest`). */
        if (el.hasAttribute('data-toggle-target') && !el.matches('select')) {
            event.preventDefault();
            toggleTarget(el);

            if (!el.hasAttribute('data-remove-closest'))
                return;
        }

        if (el.hasAttribute('data-remove-closest')) {
            var target = closest(el, el.getAttribute('data-remove-closest'));

            if (target) {
                event.preventDefault();
                if (target.parentNode)
                    target.parentNode.removeChild(target);
            }
            return;
        }

        if (el.hasAttribute('data-save-draft')) {
            event.preventDefault();
            if (typeof window.saveDraft === 'function')
                window.saveDraft();
            return;
        }

        if (el.hasAttribute('data-discard-draft')) {
            event.preventDefault();

            var form = el.form || closest(el, 'form');
            var next = el.getAttribute('data-discard-draft');

            if (form && window.jQuery) {
                try {
                    window.jQuery('textarea.richtext', form).redactor('deleteDraft');
                } catch (e) {
                    /* Sin editor inicializado no hay borrador que borrar. */
                }
            }

            if (next)
                window.location.href = next;
            else
                window.location.reload();

            return;
        }

        if (el.hasAttribute('data-reload')) {
            event.preventDefault();
            window.location.reload();
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

    document.addEventListener('change', function (event) {
        var el = event.target;

        if (!el || !el.hasAttribute)
            return;

        if (el.hasAttribute('data-toggle-target')) {
            toggleTarget(el);
            return;
        }

        if (el.hasAttribute('data-inherit-toggle')) {
            var table = closest(el, 'table');

            if (!table)
                return;

            /* jQuery, como el onclick original: `.toggle()` restaura el
               display que traía el tbody (table-row-group), no `block`. */
            if (window.jQuery) {
                window.jQuery(table).find('.if-not-inherited').toggle(!el.checked);
                return;
            }

            var nodes = table.querySelectorAll('.if-not-inherited');
            var i;

            for (i = 0; i < nodes.length; i++)
                nodes[i].style.display = el.checked ? 'none' : '';
        }
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
