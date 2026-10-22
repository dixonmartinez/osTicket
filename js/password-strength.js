/**
 * js/password-strength.js — indicador de fuerza de la contraseña
 *
 * Se engancha a los campos marcados con `data-password-strength` (los de
 * crear cuenta y cambiar contraseña del portal del cliente) y, si el
 * formulario tiene un campo `data-password-confirm`, avisa cuando las dos
 * contraseñas no coinciden.
 *
 * Qué NO hace:
 *   - No bloquea el envío del formulario ni cambia la validación del
 *     servidor: es solo una ayuda visual, así que si el JS no carga (o el
 *     navegador es antiguo) el alta sigue funcionando igual.
 *   - No envía la contraseña a ningún sitio: todo se calcula en el navegador.
 *
 * Los textos salen de `window.OST_PW_STRENGTH` (los pone el pie del portal con
 * __()), con valores en inglés por si no está.
 */
(function (window, document) {
    'use strict';

    var LEVELS = 4;

    // Contraseñas y patrones que no deberían puntuar por longitud.
    var WEAK_WORDS = [
        'password', 'contrasena', 'contraseña', '123456', '12345678', 'qwerty',
        'abc123', 'letmein', 'iloveyou', 'admin', 'welcome', 'monkey',
        'dragon', 'football', 'master', 'sunshine', 'princess', 'superman',
        'osticket', 'helpdesk'
    ];

    function t(key, fallback) {
        var cfg = window.OST_PW_STRENGTH || {};

        return cfg[key] || fallback;
    }

    function classesFor(value) {
        var hasLower = /[a-z]/.test(value);
        var hasUpper = /[A-Z]/.test(value);
        var hasDigit = /[0-9]/.test(value);
        var hasSymbol = /[^A-Za-z0-9]/.test(value);

        return {
            lower: hasLower,
            upper: hasUpper,
            digit: hasDigit,
            symbol: hasSymbol,
            mixed: (hasLower && hasUpper) || (hasDigit && hasSymbol),
            variety: (hasLower ? 1 : 0) + (hasUpper ? 1 : 0)
                + (hasDigit ? 1 : 0) + (hasSymbol ? 1 : 0)
        };
    }

    /**
     * Devuelve un nivel de 0 (vacía) a 4 (fuerte).
     *
     * La idea es la de siempre: lo que encarece un ataque de diccionario es
     * la longitud y la variedad de caracteres. Se cuenta por tramos para que
     * una contraseña de 20 letras minúsculas no salga «fuerte».
     */
    function score(value) {
        if (!value)
            return 0;

        var c = classesFor(value);
        var points = 0;

        if (value.length >= 8)
            points++;
        if (value.length >= 12)
            points++;
        if (value.length >= 16)
            points++;
        if (c.variety >= 3)
            points++;
        if (c.variety === 4)
            points++;

        var lower = value.toLowerCase();

        for (var i = 0; i < WEAK_WORDS.length; i++) {
            if (lower.indexOf(WEAK_WORDS[i]) !== -1) {
                points = Math.min(points, 1);
                break;
            }
        }

        // Repeticiones y secuencias del teclado tampoco suman.
        if (/^(.)\1+$/.test(value) || /(0123|1234|2345|3456|4567|5678|6789|abcd|qwer|asdf)/i.test(value))
            points = Math.min(points, 1);

        if (value.length < 8)
            points = Math.min(points, 1);

        if (points <= 1)
            return 1;
        if (points === 2)
            return 2;
        if (points === 3)
            return 3;

        return 4;
    }

    function labelFor(level) {
        switch (level) {
            case 1: return t('weak', 'Weak');
            case 2: return t('fair', 'Fair');
            case 3: return t('good', 'Good');
            default: return t('strong', 'Strong');
        }
    }

    function build(input) {
        var id = input.id || (input.name || 'password') + '-strength';
        var box = document.createElement('div');

        box.className = 'pw-strength';
        box.id = id;

        box.innerHTML = '<div class="pw-strength-bars" aria-hidden="true">'
            + new Array(LEVELS + 1).join('<span class="pw-strength-bar"></span>')
            + '</div><p class="pw-strength-label" role="status" aria-live="polite"></p>';

        if (input.parentNode)
            input.parentNode.insertBefore(box, input.nextSibling);

        input.setAttribute('aria-describedby',
            ((input.getAttribute('aria-describedby') || '') + ' ' + id).trim());

        return box;
    }

    function update(input, box, confirm) {
        var value = input.value || '';
        var level = score(value);
        var label = box.querySelector('.pw-strength-label');
        var bars = box.querySelectorAll('.pw-strength-bar');
        var text = '';
        var i;

        box.setAttribute('data-level', String(level));

        for (i = 0; i < bars.length; i++)
            bars[i].className = 'pw-strength-bar' + (i < level ? ' on' : '');

        if (!value) {
            text = t('empty', 'Use at least 8 characters, with numbers and symbols.');
        } else if (level <= 1) {
            text = labelFor(1) + '. ' + t('hintShort', 'Add length and variety of characters.');
        } else {
            text = labelFor(level);
        }

        if (value && confirm && confirm.value && confirm.value !== value)
            text = t('mismatch', 'The passwords do not match.');

        label.textContent = text;
        box.className = 'pw-strength' + (value ? ' is-active' : '');
    }

    function enhance(form) {
        var input = form.querySelector('[data-password-strength]');

        if (!input || input.getAttribute('data-pw-ready'))
            return;

        var confirm = form.querySelector('[data-password-confirm]');
        var box = build(input);

        input.setAttribute('data-pw-ready', '1');

        function refresh() {
            update(input, box, confirm);
        }

        input.addEventListener('input', refresh);
        input.addEventListener('change', refresh);

        if (confirm) {
            confirm.addEventListener('input', refresh);
            confirm.addEventListener('change', refresh);
        }

        refresh();
    }

    function run() {
        var forms = document.querySelectorAll('form');

        for (var i = 0; i < forms.length; i++)
            enhance(forms[i]);
    }

    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', run);
    else
        run();
}(window, document));
