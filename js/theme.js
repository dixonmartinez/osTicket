/**
 * js/theme.js — selector de tema claro/oscuro
 *
 * Se carga en el <head>, ANTES del <body>, para aplicar el tema antes del
 * primer pintado. Sin esto, el usuario de tema oscuro vería un destello
 * blanco en cada navegación.
 *
 * Preferencia, por orden:
 *   1. Lo que el usuario haya elegido (localStorage, clave "ost-theme").
 *   2. El valor por defecto del despliegue: atributo data-theme-default en
 *      <html>, que viene de OST_DEFAULT_THEME en .env  (auto | light | dark).
 *   3. "auto": lo que diga el sistema operativo (prefers-color-scheme).
 *
 * El tema se aplica como data-theme="dark" en <html>; css/dark.css hace el
 * resto. No se toca ninguna cookie: no hay nada que enviar al servidor.
 *
 * El mismo archivo resuelve la DENSIDAD (cómoda o compacta). Se aplica como
 * data-density en <html> y css/density.css cambia solo el espaciado:
 *   1. Lo elegido por el usuario (localStorage, clave "ost-density").
 *   2. data-density-default en <html>, que viene de OST_DEFAULT_DENSITY.
 *   3. "comfy" (el espaciado original de osTicket).
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'ost-theme';
    var DENSITY_KEY = 'ost-density';
    var DENSITIES = ['comfy', 'compact'];
    var root = document.documentElement;
    var THEME_COLORS = { dark: '#12161b', light: '#184E81' };
    var media = (window.matchMedia
        ? window.matchMedia('(prefers-color-scheme: dark)') : null);

    function readStored() {
        try {
            return window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            // Modo privado o cookies bloqueadas: se usa el valor por defecto.
            return null;
        }
    }

    function writeStored(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch (e) { /* sin persistencia: el tema dura esta página */ }
    }

    function isValid(value) {
        return value === 'light' || value === 'dark' || value === 'auto';
    }

    function preference() {
        var stored = readStored();
        if (isValid(stored))
            return stored;

        var fallback = root.getAttribute('data-theme-default');
        return isValid(fallback) ? fallback : 'auto';
    }

    function systemTheme() {
        return (media && media.matches) ? 'dark' : 'light';
    }

    function resolvedTheme() {
        var pref = preference();
        return pref === 'auto' ? systemTheme() : pref;
    }

    function updateButtons(theme) {
        var buttons = document.querySelectorAll('[data-ost-theme-toggle]');
        var toDark = (theme !== 'dark');
        var label = toDark
            ? (buttons[0] && buttons[0].getAttribute('data-label-to-dark')) || 'Switch to dark theme'
            : (buttons[0] && buttons[0].getAttribute('data-label-to-light')) || 'Switch to light theme';

        Array.prototype.forEach.call(buttons, function (btn) {
            btn.setAttribute('aria-pressed', toDark ? 'false' : 'true');
            btn.setAttribute('title', label);
            btn.setAttribute('aria-label', label);
        });
    }

    function apply(theme) {
        root.setAttribute('data-theme', theme);

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta)
            meta.setAttribute('content', THEME_COLORS[theme] || THEME_COLORS.light);

        updateButtons(theme);
    }

    function set(value, persist) {
        if (!isValid(value))
            return;

        if (persist)
            writeStored(value);

        apply(value === 'auto' ? systemTheme() : value);
    }

    // --- Densidad -----------------------------------------------------
    function readDensity() {
        try {
            return window.localStorage.getItem(DENSITY_KEY);
        } catch (e) {
            return null;
        }
    }

    function writeDensity(value) {
        try {
            window.localStorage.setItem(DENSITY_KEY, value);
        } catch (e) { /* sin persistencia */ }
    }

    function isDensity(value) {
        return DENSITIES.indexOf(value) !== -1;
    }

    function densityPreference() {
        var stored = readDensity();
        if (isDensity(stored))
            return stored;

        var fallback = root.getAttribute('data-density-default');
        return isDensity(fallback) ? fallback : 'comfy';
    }

    function updateDensityButtons(density) {
        var compact = (density === 'compact');

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-ost-density-toggle]'),
            function (btn) {
                var label = compact
                    ? (btn.getAttribute('data-label-comfy') || 'Switch to comfortable spacing')
                    : (btn.getAttribute('data-label-compact') || 'Switch to compact spacing');

                btn.setAttribute('aria-pressed', compact ? 'true' : 'false');
                btn.setAttribute('title', label);
                btn.setAttribute('aria-label', label);
            });
    }

    function applyDensity(density) {
        root.setAttribute('data-density', density);
        updateDensityButtons(density);
    }

    function setDensity(value, persist) {
        if (!isDensity(value))
            return;

        if (persist)
            writeDensity(value);

        applyDensity(value);
    }

    // --- 1. Aplicar cuanto antes -------------------------------------
    apply(resolvedTheme());
    applyDensity(densityPreference());

    // --- 2. Seguir al sistema cuando la preferencia es "auto" --------
    if (media) {
        var onSystemChange = function () {
            if (preference() === 'auto')
                apply(systemTheme());
        };

        if (media.addEventListener)
            media.addEventListener('change', onSystemChange);
        else if (media.addListener)
            media.addListener(onSystemChange);   // navegadores antiguos
    }

    // --- 3. Conectar los botones (ya con el DOM disponible) ----------
    document.addEventListener('DOMContentLoaded', function () {
        updateButtons(root.getAttribute('data-theme') || resolvedTheme());

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-ost-theme-toggle]'),
            function (btn) {
                btn.addEventListener('click', function (event) {
                    event.preventDefault();

                    var next = (root.getAttribute('data-theme') === 'dark')
                        ? 'light' : 'dark';

                    set(next, true);

                    // Anunciar el cambio a lectores de pantalla
                    var live = document.getElementById('ost-theme-live');
                    if (live)
                        live.textContent = btn.getAttribute('aria-label') || '';
                });
            });
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-ost-density-toggle]'),
            function (btn) {
                btn.addEventListener('click', function (event) {
                    event.preventDefault();

                    var next = (root.getAttribute('data-density') === 'compact')
                        ? 'comfy' : 'compact';

                    setDensity(next, true);

                    var live = document.getElementById('ost-theme-live');
                    if (live)
                        live.textContent = btn.getAttribute('aria-label') || '';
                });
            });

        updateDensityButtons(root.getAttribute('data-density') || densityPreference());
    });

    // API mínima, útil para pruebas y para integraciones
    window.OstTheme = {
        get: function () { return root.getAttribute('data-theme'); },
        preference: preference,
        set: function (value) { set(value, true); },
        toggle: function () {
            set(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', true);
        },
        density: function () { return root.getAttribute('data-density'); },
        setDensity: function (value) { setDensity(value, true); },
        toggleDensity: function () {
            setDensity(root.getAttribute('data-density') === 'compact'
                ? 'comfy' : 'compact', true);
        }
    };
}());
