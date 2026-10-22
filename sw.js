/* ---------------------------------------------------------------------------
 * sw.js — service worker de osTicket
 * ---------------------------------------------------------------------------
 * Objetivo: que la aplicación se sienta nativa en el móvil (arranque rápido,
 * icono en la pantalla de inicio) SIN cachear datos de tickets.
 *
 * Decisiones deliberadas de seguridad y privacidad:
 *
 *   1. **Nunca** se cachea HTML ni JSON. Todas las respuestas generadas por
 *      PHP (tickets, usuarios, sesión) van siempre a la red. Un ticket en la
 *      caché de un móvil compartido sería una fuga de datos.
 *   2. Sólo se cachean recursos estáticos propios (CSS, JS, imágenes,
 *      tipografías).
 *   3. Las navegaciones sin conexión muestran una página mínima de aviso,
 *      no una copia antigua de la interfaz.
 *   4. No se interceptan POST ni peticiones a otros orígenes.
 *
 * Para desactivarlo: borra la etiqueta <link rel="manifest"> y el script
 * js/pwa.js del encabezado, o elimina este archivo.
 * ------------------------------------------------------------------------- */

// Sube la versión al cambiar los recursos estáticos para invalidar la caché.
const VERSION = 'ost-v2';
const STATIC_CACHE = `${VERSION}-static`;

// Recursos que hacen que la interfaz arranque rápido sin conexión.
const PRECACHE = [
  'images/pwa/icon-192.png',
  'images/pwa/icon-512.png',
  'images/pwa/apple-touch-icon.png',
  'css/mobile.css',
  'css/ui.css',
  'css/dark.css',
  'css/thread.css',
  'scp/css/scp.css',
  'scp/css/dashboard.css',
  'scp/css/analytics.css',
  'js/theme.js',
  'js/jquery-3.7.0.min.js'
];

// Extensiones que se consideran estáticas y por tanto cacheables.
const STATIC_EXT = /\.(?:css|js|mjs|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot)$/i;

const OFFLINE_HTML = `<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sin conexión</title>
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
       font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
       background:#f5f7fa;color:#1f2933;padding:24px;color-scheme:light dark}
  .box{max-width:380px;text-align:center;background:#fff;border:1px solid #e4e7eb;border-radius:14px;padding:28px}
  h1{font-size:1.2rem;margin:0 0 8px}
  p{color:#616e7c;line-height:1.5;margin:0 0 18px}
  button{min-height:44px;padding:10px 20px;border-radius:8px;border:0;background:#184E81;color:#fff;
         font-size:1rem;font-weight:600;cursor:pointer}
  /* Sin conexión no se puede leer el tema guardado: se sigue al sistema */
  @media (prefers-color-scheme: dark){
    body{background:#12161b;color:#e6ebf0}
    .box{background:#171d24;border-color:#333e4a}
    p{color:#a6b2be}
    button{background:#2f6dad}
  }
</style></head>
<body><div class="box">
  <h1>Sin conexión</h1>
  <p>No hay red disponible. Los datos de los tickets no se guardan en el
     dispositivo por seguridad, así que necesitas conexión para continuar.</p>
  <button onclick="location.reload()">Reintentar</button>
</div></body></html>`;

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(STATIC_CACHE);
    // addAll falla entero si un recurso no existe: se añaden uno a uno.
    await Promise.all(PRECACHE.map((url) =>
      cache.add(url).catch(() => { /* recurso opcional */ })
    ));
    // Toma el control en la siguiente navegación sin romper la actual.
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys
      .filter((key) => key !== STATIC_CACHE)
      .map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  // Sólo GET del mismo origen
  if (request.method !== 'GET')
    return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin)
    return;

  // Navegaciones: siempre red. Sin red, aviso (nunca HTML cacheado).
  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        return await fetch(request);
      }
      catch (e) {
        return new Response(OFFLINE_HTML, {
          status: 503,
          headers: { 'Content-Type': 'text/html; charset=UTF-8' }
        });
      }
    })());
    return;
  }

  // Estáticos: sirve de caché y actualiza en segundo plano.
  if (STATIC_EXT.test(url.pathname)) {
    event.respondWith((async () => {
      const cache = await caches.open(STATIC_CACHE);
      const cached = await cache.match(request);
      const network = fetch(request).then((response) => {
        if (response && response.ok && response.type === 'basic')
          cache.put(request, response.clone());
        return response;
      }).catch(() => null);
      return cached || (await network) || Response.error();
    })());
  }
});
