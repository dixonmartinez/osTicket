#!/usr/bin/env node
/**
 * tools/ui/dark-audit.js
 *
 * Busca «zonas claras» en el tema oscuro: elementos visibles cuyo fondo sigue
 * siendo de tema claro (un `#fff`, un `#eee` que se coló, una hoja que llegó
 * después de css/dark.css…). Es el complemento de contrast-audit.js: aquél
 * mide texto ilegible, éste mide superficies que se quedaron blancas.
 *
 * No se ejecuta en `make ci` porque necesita un navegador. Se usa a mano
 * cuando se toca css/dark.css o css/ui.css, o cuando alguien manda una
 * captura con una zona rara.
 *
 * Preparación (una sola vez, fuera del contenedor):
 *   npm install --no-save playwright-core
 *   # y un Chromium: PLAYWRIGHT_BROWSERS_PATH, o CHROME_PATH=/ruta/al/chrome
 *
 * Uso:
 *   node tools/ui/dark-audit.js                        # la vista previa
 *   node tools/ui/dark-audit.js http://localhost:8090/scp/tickets.php
 *   node tools/ui/dark-audit.js <url> --json
 *
 * Hay superficies que SON claras a propósito (el cuerpo de un correo, el
 * visor de PDF, el editor enriquecido: son «papel»). Se marcan en el HTML
 * con `data-allow-light` y el auditor las ignora, igual que las imágenes y
 * los iframes.
 *
 * Código de salida: 0 sin hallazgos, 1 con hallazgos, 2 error de uso.
 */

'use strict';

const path = require('path');

/* ------------------------------------------------------------------ */
/* Auditor: se ejecuta DENTRO de la página                             */
/* ------------------------------------------------------------------ */
function audit() {
    const parse = (c) => {
        const m = String(c).match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/);
        if (!m) return null;
        return { r: +m[1], g: +m[2], b: +m[3], a: m[4] === undefined ? 1 : +m[4] };
    };
    const lum = ({ r, g, b }) => {
        const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };

    const SKIP_TAGS = ['HTML', 'BODY', 'IMG', 'IFRAME', 'VIDEO', 'CANVAS', 'SVG', 'PATH', 'OBJECT'];
    const ALLOW = '[data-allow-light], .thread-body, .preview-body, .redactor-in, .ost-paper';

    const pathOf = (el) => {
        let p = el.tagName.toLowerCase();
        if (el.id) p += '#' + el.id;
        if (el.className && typeof el.className === 'string' && el.className.trim())
            p += '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.');
        return p;
    };

    const out = [];
    const seen = new Set();

    document.querySelectorAll('body *').forEach((el) => {
        if (SKIP_TAGS.indexOf(el.tagName) !== -1)
            return;
        if (el.closest(ALLOW))
            return;

        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0)
            return;

        const r = el.getBoundingClientRect();
        if (r.width < 40 || r.height < 14)
            return;
        if (r.width * r.height < 900)
            return;

        const bg = parse(cs.backgroundColor);
        /* Sólo fondos opacos: uno translúcido se mezcla con el del padre. */
        if (!bg || bg.a < 0.9)
            return;
        if (lum(bg) < 0.35)
            return;

        /* Una imagen de fondo puede tapar el color plano. */
        if (cs.backgroundImage && cs.backgroundImage !== 'none')
            return;

        const key = pathOf(el);
        if (seen.has(key))
            return;
        seen.add(key);

        const text = (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 50);
        out.push({
            el: key,
            bg: `rgb(${Math.round(bg.r)}, ${Math.round(bg.g)}, ${Math.round(bg.b)})`,
            size: `${Math.round(r.width)}×${Math.round(r.height)}`,
            text,
        });
    });

    return out;
}

/* ------------------------------------------------------------------ */
/* Arranque                                                            */
/* ------------------------------------------------------------------ */
(async () => {
    const args = process.argv.slice(2);
    const flags = {};
    const positional = [];
    for (let i = 0; i < args.length; i++) {
        if (args[i].startsWith('--')) {
            const name = args[i].slice(2);
            const next = args[i + 1];
            if (next && !next.startsWith('--')) { flags[name] = next; i++; }
            else flags[name] = true;
        } else {
            positional.push(args[i]);
        }
    }

    const url = positional[0]
        || 'file://' + path.join(__dirname, '..', '..', 'docs', 'theme-preview.html');
    const json = !!flags.json;

    let chromium;
    try {
        chromium = require('playwright-core');
    } catch (e) {
        /* La imagen oficial de Playwright trae el paquete como `playwright`. */
        try {
            chromium = require('playwright');
        } catch (e2) {
            console.error('Falta playwright (comprobación opcional, necesita navegador).\n'
                + '  Con Docker no hay que instalar nada: make docker-' + 'dark-audit' + '\n'
                + '  Sin Docker, en el equipo:\n'
                + '    npm install --no-save playwright-core\n'
                + '    npx playwright install chromium\n'
                + '  y, si el navegador no está en la ruta por defecto:\n'
                + '    export CHROME_PATH=/ruta/al/chrome\n'
                + 'Esto no entra en make ci: make theme cubre la parte automática.');
            process.exit(2);
        }
    }

    const launch = { headless: true, args: ['--disable-dev-shm-usage'] };

    if (process.env.CHROME_PATH)
        launch.executablePath = process.env.CHROME_PATH;

    /* Dentro de un contenedor se ejecuta como root y Chromium no arranca con
       el sandbox activado. */
    if (process.env.OST_NO_SANDBOX === '1'
            || (typeof process.getuid === 'function' && process.getuid() === 0))
        launch.args.push('--no-sandbox');

    let browser;
    try {
        browser = await chromium.chromium.launch(launch);
    } catch (e) {
        console.error('No se pudo abrir Chromium: '
            + String(e && e.message ? e.message : e).split('\n')[0] + '\n'
            + '  · Con Docker: make docker-dark-audit (los navegadores vienen en la imagen)\n'
            + '  · En el equipo:  npx playwright install chromium\n'
            + '  · Si ya está descargado: export CHROME_PATH=/ruta/al/chrome');
        process.exit(2);
    }
    const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });

    if (flags.cookie) {
        const [name, ...rest] = String(flags.cookie).split('=');
        const domain = new URL(url).hostname;
        await context.addCookies([{ name, value: rest.join('='), domain, path: '/' }]);
    }

    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForTimeout(400);

    await page.evaluate(() => {
        if (window.OstTheme && window.OstTheme.set) window.OstTheme.set('dark');
        else document.documentElement.setAttribute('data-theme', 'dark');
    });
    await page.waitForTimeout(300);

    /* Los desplegables y los menús flotantes sólo existen abiertos. */
    const open = await page.$$('#nav > li, #sub_nav > li, .action-dropdown, .dialog');
    let findings = await page.evaluate(audit);
    for (const el of open) {
        await el.hover().catch(() => {});
        await page.waitForTimeout(200);
        findings = findings.concat(await page.evaluate(audit));
    }

    await browser.close();

    const unique = [];
    const seen = new Set();
    for (const f of findings) {
        if (seen.has(f.el)) continue;
        seen.add(f.el);
        unique.push(f);
    }

    if (json) {
        console.log(JSON.stringify(unique, null, 2));
    } else if (unique.length === 0) {
        console.log('Sin hallazgos: no quedan superficies claras en el tema oscuro.');
    } else {
        console.log(`\n${unique.length} superficie(s) clara(s) en el tema oscuro:\n`);
        for (const f of unique)
            console.log(`  ${f.el}\n    fondo ${f.bg} · ${f.size}${f.text ? ' · «' + f.text + '»' : ''}`);
        console.log('\nSi alguna es intencionada (papel de correo, PDF, editor),');
        console.log('márcala con data-allow-light en el HTML.');
    }

    process.exit(unique.length ? 1 : 0);
})().catch((e) => {
    console.error(e);
    process.exit(2);
});
