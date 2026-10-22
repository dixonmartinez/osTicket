#!/usr/bin/env node
/**
 * tools/ui/contrast-audit.js
 *
 * Mide el contraste real de una página del proyecto con un navegador de
 * verdad y lista los textos que no llegan al mínimo de WCAG 2.1 (4.5:1 para
 * texto normal, 3:1 para texto grande). Sirve para revisar el tema oscuro sin
 * ir pantalla por pantalla a ojo.
 *
 * No se ejecuta en `make ci` porque necesita un navegador. Se usa a mano
 * cuando se toca css/dark.css o css/ui.css.
 *
 * Preparación (una sola vez, fuera del contenedor):
 *   npm install --no-save playwright-core
 *   # y un Chromium: PLAYWRIGHT_BROWSERS_PATH, o CHROME_PATH=/ruta/al/chrome
 *
 * Uso:
 *   node tools/ui/contrast-audit.js                      # la vista previa
 *   node tools/ui/contrast-audit.js http://localhost:8090/scp/tickets.php
 *   node tools/ui/contrast-audit.js <url> --theme light
 *   node tools/ui/contrast-audit.js <url> --json
 *
 * Contra la aplicación real hace falta sesión: exporta la cookie antes
 * (ver docs/UI_GUIDE.md §5) y pásala con --cookie "OSTSESSID=...".
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
        const m = c.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/);
        if (!m) return null;
        return { r: +m[1], g: +m[2], b: +m[3], a: m[4] === undefined ? 1 : +m[4] };
    };
    const lum = ({ r, g, b }) => {
        const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const blend = (fg, bg) => ({
        r: fg.r * fg.a + bg.r * (1 - fg.a),
        g: fg.g * fg.a + bg.g * (1 - fg.a),
        b: fg.b * fg.a + bg.b * (1 - fg.a),
        a: 1,
    });
    const ratio = (a, b) => {
        const la = lum(a), lb = lum(b), hi = Math.max(la, lb), lo = Math.min(la, lb);
        return (hi + 0.05) / (lo + 0.05);
    };
    // Sube por los ancestros acumulando fondos translúcidos hasta uno opaco
    const bgOf = (el) => {
        let node = el, acc = null;
        while (node && node.nodeType === 1) {
            const c = parse(getComputedStyle(node).backgroundColor);
            if (c && c.a > 0) {
                acc = acc ? blend(acc, c) : c;
                if (acc.a === 1 || c.a === 1) return acc;
            }
            node = node.parentElement;
        }
        return acc || { r: 255, g: 255, b: 255, a: 1 };
    };
    const pathOf = (el) => {
        let p = el.tagName.toLowerCase();
        if (el.id) p += '#' + el.id;
        if (el.className && typeof el.className === 'string')
            p += '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.');
        return p;
    };

    const out = [];
    const seen = new Set();

    document.querySelectorAll('body *').forEach((el) => {
        const text = Array.from(el.childNodes)
            .filter((n) => n.nodeType === 3)
            .map((n) => n.textContent.trim()).join(' ').trim();
        if (!text) return;

        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0) return;

        const r = el.getBoundingClientRect();
        if (r.width < 2 || r.height < 2) return;

        const fg = parse(cs.color);
        if (!fg) return;

        const bg = bgOf(el);
        const fgc = fg.a < 1 ? blend(fg, bg) : fg;
        const cr = ratio(fgc, bg);

        const size = parseFloat(cs.fontSize);
        const bold = parseInt(cs.fontWeight, 10) >= 700;
        const large = size >= 24 || (size >= 18.66 && bold);
        const need = large ? 3 : 4.5;
        if (cr >= need) return;

        const key = pathOf(el) + '|' + Math.round(cr * 10);
        if (seen.has(key)) return;
        seen.add(key);
        out.push({
            el: pathOf(el),
            text: text.slice(0, 60),
            ratio: +cr.toFixed(2),
            need,
            fg: cs.color,
            bg: `rgb(${Math.round(bg.r)}, ${Math.round(bg.g)}, ${Math.round(bg.b)})`,
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
    const theme = flags.theme === 'light' ? 'light' : 'dark';
    const json = !!flags.json;

    let chromium;
    try {
        chromium = require('playwright-core');
    } catch (e) {
        console.error('Falta playwright-core. Instálalo con:\n'
            + '  npm install --no-save playwright-core\n'
            + 'y apunta a un Chromium con CHROME_PATH o PLAYWRIGHT_BROWSERS_PATH.');
        process.exit(2);
    }

    const launch = { headless: true };
    if (process.env.CHROME_PATH) {
        launch.executablePath = process.env.CHROME_PATH;
        launch.args = ['--no-sandbox', '--disable-dev-shm-usage'];
    }

    const browser = await chromium.chromium.launch(launch);
    const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });

    if (flags.cookie) {
        const [name, ...rest] = String(flags.cookie).split('=');
        const domain = new URL(url).hostname;
        await context.addCookies([{
            name, value: rest.join('='), domain, path: '/',
        }]);
    }

    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForTimeout(400);

    // Si la página trae el selector de tema, se fuerza el que se pide
    await page.evaluate((t) => {
        if (window.OstTheme && window.OstTheme.set) window.OstTheme.set(t);
        else document.documentElement.setAttribute('data-theme', t);
    }, theme);
    await page.waitForTimeout(200);

    // Los menús desplegables sólo se pueden medir abiertos
    const menus = await page.$$('#nav > li, #sub_nav > li');
    const findings = await page.evaluate(audit);
    for (const menu of menus) {
        await menu.hover().catch(() => {});
        await page.waitForTimeout(350);
        findings.push(...await page.evaluate(audit));
    }

    await browser.close();

    // Deduplicar tras los hovers
    const unique = [];
    const seen = new Set();
    for (const f of findings) {
        const key = f.el + '|' + f.text + '|' + f.ratio;
        if (!seen.has(key)) { seen.add(key); unique.push(f); }
    }
    unique.sort((a, b) => a.ratio - b.ratio);

    if (json) {
        console.log(JSON.stringify({ url, theme, count: unique.length, findings: unique }, null, 2));
    } else {
        console.log(`${url}  (tema ${theme})`);
        if (!unique.length) {
            console.log('Sin hallazgos: todo el texto visible llega al mínimo de WCAG 2.1.');
        } else {
            console.log(`${unique.length} texto(s) por debajo del mínimo:\n`);
            for (const f of unique) {
                console.log(`  ${String(f.ratio).padStart(5)}:1 (mín ${f.need})  ${f.el}`);
                console.log(`         "${f.text}"  ${f.fg} sobre ${f.bg}`);
            }
        }
    }

    process.exit(unique.length ? 1 : 0);
})().catch((e) => {
    console.error('Error:', e.message);
    process.exit(2);
});
