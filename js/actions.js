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
 *   data-ost-dialog="201"      abre `$.dialog(url, 201)` con la url del href
 *                              (`#algo` → `algo`), como hacía el onclick.
 *                              Con data-ost-dialog-url="..." se usa esa url y
 *                              con data-ost-dialog-size="large" se pasa el
 *                              tamaño.
 *                              OJO: **no es `data-dialog`**. Ese atributo ya
 *                              existe en osTicket (scp/js/scp.js) y guarda la
 *                              URL, no el código; usar el mismo nombre hacía
 *                              que `.load()` recibiera un número y reventara
 *                              con «e.indexOf is not a function».
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
 *   data-click-target="sel"    pulsa los elementos que casen (con
 *                              data-click-scope="parent" busca dentro del
 *                              padre); no cancela la acción propia
 *   data-pjax-submit[="sel"]   envía el formulario por PJAX al contenedor
 *                              indicado (por defecto `#pjax-container`)
 *   data-pjax-query="a=x&b=y"  recarga la lista por PJAX añadiendo a la URL
 *                              actual los parámetros `a`, `b`… tomados de
 *                              los `data-x` / `data-y` del elemento pulsado
 *   data-set-name="campo"      escribe un campo del formulario antes de
 *                              enviarlo; el valor sale de `data-set-value`
 *                              (literal) o de `data-set-from="clave"` (el
 *                              `data-clave` del elemento o del que se pulsó).
 *                              Con `data-submit-form` se envía el formulario
 *   data-ost-user-lookup="url" abre el buscador de usuarios (`$.userLookup`)
 *                              y aplica el usuario elegido según los atributos:
 *                              -data-ost-user-lookup-fill="#a=campo" escribe
 *                                el texto (`#a=campo|angle` → «<email>»,
 *                                `|name-email` → «Nombre <email>»). Se separan
 *                                por comas y `{id}` en el selector se cambia
 *                                por el id del usuario elegido
 *                              -data-ost-user-lookup-value="#campo=id" pone el
 *                                value de un campo (los <input hidden>)
 *                              -data-ost-user-lookup-add="#select" añade una
 *                                <option> y dispara `change` (formato con
 *                                -add-format="email-name")
 *                              -data-ost-user-lookup-redirect="url" navega
 *                              -data-ost-user-lookup-save-draft llama antes a
 *                                `saveDraft()`
 *                              En la url, `{#selector}` se sustituye por el
 *                              value de ese campo (p. ej. el usuario buscado)
 *   data-call="funcion"        llama a `window.funcion(event)`; si devuelve
 *                              `false` se cancela la acción (formularios).
 *                              Hay dos funciones incluidas:
 *                                ostHelpTopic           recarga el formulario
 *                                  dinámico al cambiar de tema de ayuda
 *                                  (data-form="#dynamic-form")
 *                                ostAutodetectTimezone  carga js/jstz.min.js y
 *                                  pone la zona detectada en el <select> de
 *                                  data-timezone-target
 *
 * Todo se resuelve con delegación en `document`, así que funciona también con
 * el contenido que carga PJAX o los diálogos por AJAX. Si un elemento no tiene
 * el atributo, no se toca: es una capa aditiva.
 */
(function (window, document) {
    'use strict';

    var CLICKABLE = '[data-href],[data-go-back],[data-ost-dialog],[data-ost-dialog-url],[data-confirm],'
        + '[data-remove-closest],[data-save-draft],[data-discard-draft],'
        + '[data-toggle-target],[data-reload],[data-click-target],'
        + '[data-set-name],[data-call],[data-ost-dialog-row],[data-pjax-query],'
        + '[data-ost-user-lookup]';

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

    /* Busca `data-<name>` desde el elemento pulsado hacia arriba hasta el
       contenedor que lleva el manejador. */
    function attrFrom(el, event, name) {
        var node = event && event.target ? event.target : el;
        var attr = 'data-' + name;

        while (node && node.nodeType === 1) {
            if (node.getAttribute && node.getAttribute(attr) !== null)
                return node.getAttribute(attr);
            if (node === el)
                break;
            node = node.parentElement;
        }

        return '';
    }

    /* Recarga por PJAX añadiendo parámetros a la URL actual: el equivalente
       declarativo de `addSearchParam(...)` + `$.pjax(...)`. */
    function pjaxQuery(el, event) {
        var spec = el.getAttribute('data-pjax-query') || '';
        var params = {};
        var pairs = spec.split('&');
        var i;

        for (i = 0; i < pairs.length; i++) {
            var kv = pairs[i].split('=');

            if (!kv[0])
                continue;

            params[decodeURIComponent(kv[0])] = attrFrom(el, event, kv[1] || kv[0]);
        }

        var query;

        if (typeof window.addSearchParam === 'function') {
            query = window.addSearchParam(params);
        } else {
            var search = window.location.search.substring(1);
            var out = [];
            var keys = Object.keys(params);
            var seen = {};

            if (search) {
                var current = search.split('&');

                for (i = 0; i < current.length; i++) {
                    var name = decodeURIComponent(current[i].split('=')[0]);

                    if (Object.prototype.hasOwnProperty.call(params, name)) {
                        out.push(encodeURIComponent(name) + '='
                            + encodeURIComponent(params[name]));
                        seen[name] = true;
                    } else {
                        out.push(current[i]);
                    }
                }
            }

            for (i = 0; i < keys.length; i++) {
                if (!seen[keys[i]])
                    out.push(encodeURIComponent(keys[i]) + '='
                        + encodeURIComponent(params[keys[i]]));
            }

            query = out.join('&');
        }

        if (!window.jQuery || !window.jQuery.pjax)
            return;

        window.jQuery.pjax({
            url: '?' + query,
            timeout: 2000,
            container: el.getAttribute('data-pjax-container') || '#pjax-container'
        });
    }

    /* Escribe un campo del formulario. El valor puede venir de otro `data-*`
       del propio elemento o del que se pulsó dentro del contenedor. */
    function applyFormSet(el, event) {
        var name = el.getAttribute('data-set-name');
        var form = el.form || closest(el, 'form');

        if (!name || !form)
            return;

        var from = el.getAttribute('data-set-from');
        var value = el.getAttribute('data-set-value');

        if (from) {
            value = el.getAttribute('data-' + from);

            if (value === null && event && event.target
                    && event.target.getAttribute)
                value = event.target.getAttribute('data-' + from);
        }

        var field = form.elements[name]
            || form.querySelector('[name="' + name + '"]');

        if (field)
            field.value = value === null ? '' : value;
    }

    /* Llama a una función con nombre. `false` cancela la acción, igual que
       `return false` en el onclick original. */
    function callNamed(el, event) {
        var name = el.getAttribute('data-call');
        var fn = name ? window[name] : null;

        if (typeof fn !== 'function')
            return true;

        return fn.call(el, event) !== false;
    }

    function submitForm(form) {
        if (!form)
            return;

        if (window.jQuery && window.jQuery.fn.submit)
            window.jQuery(form).submit();
        else
            form.submit();
    }

    /* Pulsa los elementos indicados, como hacía `$(sel).trigger('click')`.
       No cancela la acción del propio elemento: en sequence-manage el botón
       que lo usa es un submit y el envío tiene que seguir su curso. */
    function clickTarget(el) {
        var selector = el.getAttribute('data-click-target');
        var scope = el.getAttribute('data-click-scope');
        var root = document;

        if (!selector)
            return;

        if (scope === 'parent')
            root = el.parentElement || document;
        else if (scope && scope.indexOf('closest:') === 0)
            root = closest(el, scope.substring(8)) || document;

        var nodes = root.querySelectorAll(selector);
        var i;

        for (i = 0; i < nodes.length; i++)
            nodes[i].click();
    }

    /* `$.userLookup(url, callback)` devuelve el usuario elegido. Lo que cada
       onclick hacía con él se describe con los atributos data-ost-user-lookup-*:
       así el marcado no lleva JavaScript y el comportamiento se prueba solo.

       Las listas (`-fill`, `-value`) se escriben como `selector=campo` y, si
       hace falta dar formato, `selector=campo|formato`. El separador se busca
       al final para no confundirlo con los `=` de un selector de atributos
       (`select#emailreply option[value="1"]=email|name-email`). */
    function userSpec(spec, user) {
        var out = [];

        if (!spec)
            return out;

        spec.split(',').forEach(function (entry) {
            entry = entry.replace(/^\s+|\s+$/g, '');

            var at = entry.lastIndexOf('=');

            if (at < 1)
                return;

            var parts = entry.substring(at + 1).split('|');
            var field = parts[0];
            var value = user[field];

            out.push({
                selector: entry.substring(0, at).replace(/\{id\}/g, user.id || ''),
                field: field,
                format: parts[1] || '',
                value: value === undefined || value === null ? '' : String(value)
            });
        });

        return out;
    }

    function applyUser(el, user) {
        var $ = window.jQuery;

        userSpec(el.getAttribute('data-ost-user-lookup-fill'), user)
            .forEach(function (item) {
                var value = item.value;

                if (item.format === 'angle')
                    value = '<' + value + '>';
                else if (item.format === 'name-email')
                    value = (user.name || '') + ' <' + (user.email || '') + '>';

                try {
                    $(item.selector).text(value);
                } catch (e) {
                    /* Un selector que ya no está en la página no es un fallo. */
                }
            });

        userSpec(el.getAttribute('data-ost-user-lookup-value'), user)
            .forEach(function (item) {
                try {
                    $(item.selector).val(item.value);
                } catch (e) {
                    /* idem */
                }
            });

        var select = el.getAttribute('data-ost-user-lookup-add');

        if (select) {
            var label = el.getAttribute('data-ost-user-lookup-add-format') === 'email-name'
                ? (user.email || '') + ' - ' + (user.name || '')
                : (user.name || '');

            try {
                $(select).append(new Option(label, user.id, true, true))
                    .trigger('change');
            } catch (e) {
                /* idem */
            }
        }
    }

    function userLookup(el, event) {
        event.preventDefault();
        event.stopPropagation();

        var $ = window.jQuery;

        if (!$ || typeof $.userLookup !== 'function')
            return;

        var url = el.getAttribute('data-ost-user-lookup') || '';

        if (!url)
            return;

        /* `ajax.php/users/select/{#uid}`: el id sale del campo del formulario. */
        url = url.replace(/\{([#.][^}]*)\}/g, function (match, selector) {
            var node = document.querySelector(selector.replace(/^\s+|\s+$/g, ''));

            return node && node.value ? node.value : '';
        });

        if (el.hasAttribute('data-ost-user-lookup-save-draft')
                && typeof window.saveDraft === 'function')
            window.saveDraft();

        $.userLookup(url, function (user) {
            if (!user)
                return;

            applyUser(el, user);

            var redirect = el.getAttribute('data-ost-user-lookup-redirect');

            if (redirect)
                window.location.href = redirect;
        });
    }

    /* `$.dialog` muestra el overlay «Loading» antes de pedir la URL: si la
       petición falla con una excepción, el overlay se queda encima de la
       página y no hay forma de cerrarlo. Se recoge el fallo y se limpia. */
    function openDialog(url, size, options) {
        var $ = window.jQuery;

        try {
            $.dialog(url, size, null, options || null);
        } catch (e) {
            if (typeof $.toggleOverlay === 'function')
                $.toggleOverlay(false);
            $('.dialog#popup').hide();
            if (window.console && console.error)
                console.error('No se pudo abrir el diálogo de', url, e);
        }
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

        if (el.hasAttribute('data-pjax-query')) {
            event.preventDefault();
            pjaxQuery(el, event);
            return;
        }

        if (el.hasAttribute('data-set-name'))
            applyFormSet(el, event);

        if (el.hasAttribute('data-submit-form')) {
            event.preventDefault();
            submitForm(el.form || closest(el, 'form'));
        }

        /* En un <form> el data-call se ejecuta en el evento submit, no en el
           clic del botón que lo envía: si no, se llamaría dos veces. */
        if (el.tagName !== 'FORM' && el.hasAttribute('data-call')
                && !callNamed(el, event)) {
            event.preventDefault();
            return;
        }

        if (el.hasAttribute('data-click-target'))
            clickTarget(el);

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

        if (el.hasAttribute('data-ost-dialog-row')) {
            var row = closest(el, el.getAttribute('data-ost-dialog-row-scope') || 'tr');
            var field = el.getAttribute('data-ost-dialog-row-field');
            var node = row && field ? row.querySelector(field) : null;
            var id = node ? node.value : '';

            event.preventDefault();

            if (!id || !window.jQuery || !window.jQuery.dialog)
                return;

            openDialog(el.getAttribute('data-ost-dialog-row') + id,
                parseInt(el.getAttribute('data-ost-dialog'), 10) || 201);
            return;
        }

        if (el.hasAttribute('data-ost-user-lookup')) {
            userLookup(el, event);
            return;
        }

        if (el.hasAttribute('data-ost-dialog') || el.hasAttribute('data-ost-dialog-url')) {
            var url = el.getAttribute('data-ost-dialog-url') || dialogUrl(el);
            var size = parseInt(el.getAttribute('data-ost-dialog'), 10) || 201;
            var options = el.getAttribute('data-ost-dialog-size');

            if (!url || !window.jQuery || !window.jQuery.dialog)
                return;

            event.preventDefault();
            openDialog(url, size, options ? {size: options} : null);
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

        if (el.hasAttribute('data-set-name'))
            applyFormSet(el, event);

        if (el.hasAttribute('data-submit-form')) {
            submitForm(el.form || closest(el, 'form'));
            return;
        }

        if (el.hasAttribute('data-call') && !callNamed(el, event))
            return;

        if (el.hasAttribute('data-click-target')) {
            clickTarget(el);
            return;
        }

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
                /* En la cola guardada hay dos grupos: las columnas propias y
                   las estándar. Si no existe el segundo, no hace nada. */
                window.jQuery(table).find('.standard-columns').toggle(!!el.checked);
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

        if (form.hasAttribute && form.hasAttribute('data-pjax-submit')) {
            if (window.jQuery && window.jQuery.pjax) {
                event.preventDefault();
                window.jQuery.pjax({
                    url: (form.getAttribute('action') || '') + '?'
                        + window.jQuery(form).serialize(),
                    container: form.getAttribute('data-pjax-submit')
                        || '#pjax-container',
                    timeout: 2000
                });
                return;
            }

            /* Sin pjax cargado, el formulario se envía de la forma normal. */
        }

        if (form.hasAttribute && form.hasAttribute('data-call')
                && !callNamed(form, event)) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        var submitter = event.submitter;
        var message = form.getAttribute('data-confirm')
            || (submitter && submitter.getAttribute('data-confirm'));

        if (message && !window.confirm(message))
            event.preventDefault();
    }, true);
    /* ------------------------------------------------------------------ */
    /* Dos acciones con lógica propia, movidas aquí desde las plantillas    */
    /* ------------------------------------------------------------------ */

    /* Cambio de tema de ayuda: pide el formulario dinámico nuevo y mete su
       HTML (y los <script>/css que lo acompañan) en la página. */
    window.ostHelpTopic = function () {
        var el = this;
        var form = el.getAttribute('data-form') || '#dynamic-form';
        var base = el.getAttribute('data-help-topic-url') || 'ajax.php/form/help-topic/';
        var $ = window.jQuery;

        if (!$)
            return;

        $.ajax(base + el.value, {
            data: $(form).find(':input[name]').serialize(),
            dataType: 'json',
            success: function (json) {
                $(form).empty().append(json.html);
                $(document.head).append(json.media);
            }
        });
    };

    /* Auto-detección de zona horaria: carga jstz la primera vez, espera a que
       esté y escribe la zona en el <select>. */
    window.ostAutodetectTimezone = function () {
        var el = this;
        var target = el.getAttribute('data-timezone-target');
        var src = el.getAttribute('data-jstz-src') || 'js/jstz.min.js';

        if (!target)
            return false;

        var apply = function () {
            var select = document.querySelector(target);

            if (!select || !window.jstz)
                return;

            select.value = window.jstz.determine().name();

            if (window.jQuery)
                window.jQuery(select).trigger('change');
            else
                select.dispatchEvent(new Event('change', { bubbles: true }));
        };

        if (window.jstz) {
            apply();
            return false;
        }

        var script = document.createElement('script');
        script.src = src;
        script.onload = apply;
        document.head.appendChild(script);

        return false;
    };
})(window, document);
