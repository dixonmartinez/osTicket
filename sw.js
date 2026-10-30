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
// v3: entró css/density.css, js/dialog-focus.js y js/icons.js, y el tema
// oscuro cambió bastantes reglas. Sin subir la versión, la caché sigue
// sirviendo el CSS anterior y los arreglos "no se ven".
// v4: correcciones de contraste del tema claro en css/ui.css (acentos del
// panel, botones del portal) y el botón de reset de selects.
// v5: js/icons.js dibuja los iconos en SVG y css/ui.css les da forma.
// v6: entra js/password-strength.js (indicador de fuerza del alta).
// v7: HTML de terceros en oscuro (citas, código, visor de PDF, editor) y el
//     medidor de contraseña también en el panel.
// v8: los estáticos pasan a red-primero. Con caché-primero, tras un
//     `git pull` el navegador servía una hoja vieja junto a un JS nuevo
//     (iconos gigantes, CSS sin aplicar) hasta la segunda recarga.
// v9: iconos de todos los menús del panel (correo, ajustes, gestión) y
//     padding del hueco del icono.
// v10: barra y menús de Redactor en oscuro, zona de adjuntos y estados de
//     hover con fondo claro.
// v11: icono de búsqueda y «abrir ticket» del portal en oscuro.
// v12: #nav y #sub_nav pasan a máscaras SVG (css/ui.css) y entra
//     js/actions.js, que sustituye a los onclick más repetidos.
// v13: avisos del sistema con máscara SVG y acciones nuevas de actions.js
//     (borrar la fila más cercana, mostrar/ocultar, borradores).
// v14: js/icons.js conserva href/data-* al pasar a SVG, para que los iconos
//     de ayuda sigan abriendo su texto.
// v15: tercera tanda de acciones declarativas (borradores, recarga, mostrar
//     y ocultar según el valor del control).
// v16: el menú de desbordamiento reconoce los iconos convertidos a SVG.
// v17: cuarta tanda de acciones declarativas (pulsar elementos y envío por
//     PJAX).
// v18: quinta tanda (escribir campos del formulario y llamar a funciones con
//     nombre).
// v19: sexta tanda (diálogo con el id de la fila y columnas heredadas).
// v20: séptima tanda (tema de ayuda, zona horaria, ordenación y filtros por
//     PJAX) y js/actions.js en el pie del panel.
// v21: los diálogos pasan a data-ost-dialog (data-dialog es de osTicket y
//     guarda una URL); sin subir la versión se sirve el actions.js viejo.
// v26: entra css/modern.css (capa moderna: cromo, tablas y formularios).
// v27: la barra de colas vuelve a su flujo (el flex escondia las colas) y
//     el indicador de orden deja de ser un bloque de color.
// v28: los controles de la barra de la cola (Sort, filtro, More) se ven
//     pulsables y el buscador dice que busca.
// v31: las hojas y los scripts se piden con ?v=<mtime> (Http::asset). Si el
//     navegador recibe HTML nuevo con CSS de la cache de 7 dias de nginx,
//     los cambios de interfaz "no se ven".
// v36: las pantallas de Ajustes y administracion (table.form_table) pasan al
//     lenguaje del resto del panel: tarjeta, cabecera sin doble banda y fila
//     resaltada. Sin subir la version se sirve el modern.css de la cache.
// v34: el tema oscuro se engancha a `.redactor-in` (la clase real del area
//     editable; `.redactor-editor` no existe) y gana la especificidad de la
//     barra del editor. La barra del editor estrena botones y separadores de
//     grupo, y la zona de adjuntos se marca con la clase `ost-drop-over`.
// v37: el editor deja de anclarse en un separador `|` (una excepcion de jQuery
//     abortaba su arranque y el texto quedaba invisible), y la ficha de la FAQ
//     deja de usar el papel claro del hilo: respuesta y barra lateral siguen
//     el tema oscuro.
// v38: el desplegable de colas se ancla al borde inferior de su pestana (con
//     31 px fijos quedaba un hueco y se cerraba antes de poder hacer clic) y
//     las cifras atenuadas de las colas se ven en oscuro (el icono de
//     comentarios era negro al 35 % sobre fondo oscuro).
// v39: el editor de nota deja de ser una caja sin botones: los campos memo
//     salen con la barra a la vista, la nota rapida la monta el editor comun
//     (no la barra de fabrica de Redactor) y en oscuro la superficie del
//     editor de nota sigue el tema en vez del papel claro de los correos.
// v40: la parte de administracion: barra de acciones en los formularios de
//     ajustes (con la accion principal en acento), galeria de logos y fondos
//     en filas, pestanas inactivas sin relleno y la banda de aviso con su
//     icono al lado del texto. Sin subir la version se sirve el modern.css
//     de la cache.
// v41: administracion, tercera pasada: la vista previa de la galeria es una
//     rejilla (dos imagenes ya no se salen encima del nombre), el formato
//     admitido se explica junto al selector y los ajustes largos van en
//     pestanas. Sin subir la version se sirven el modern.css y el scp.js de
//     la cache, y la barra de acciones nueva no aparece.
// v42: las pestanas inactivas dejan de salir rellenas (la regla vieja ganaba
//     por especificidad) y el editor de plantillas escribe en tinta sobre el
//     papel. Sin subir la version, dark.css llega de la cache y no se ve.
// v43: el estandar de las pantallas de administracion: etiqueta en cada
//     casilla, bandas a dos columnas y los avisos en rejilla. Sin subir la
//     version se sirven el modern.css y las plantillas de la cache.
const VERSION = 'ost-v45';
const STATIC_CACHE = `${VERSION}-static`;

// Recursos que hacen que la interfaz arranque rápido sin conexión.
const PRECACHE = [
  'images/pwa/icon-192.png',
  'images/pwa/icon-512.png',
  'images/pwa/apple-touch-icon.png',
  'css/mobile.css',
  'css/ui.css',
  'css/modern.css',
  'css/dark.css',
  'css/density.css',
  'css/thread.css',
  'css/redactor.css',
  'css/filedrop.css',
  'scp/css/scp.css',
  'scp/css/dashboard.css',
  'scp/css/analytics.css',
  'js/theme.js',
  'js/dialog-focus.js',
  'js/icons.js',
  'js/actions.js',
  'js/password-strength.js',
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

  // Estáticos: red primero y caché como red de seguridad.
  //
  // Antes era caché primero con revalidación en segundo plano, que es más
  // rápido pero deja una ventana peligrosa: tras actualizar el código (un
  // `git pull`, una imagen nueva), la primera carga servía la hoja de
  // estilos vieja junto al JavaScript nuevo. Con osTicket eso se ve como
  // iconos gigantes o como una pantalla sin estilos. Ahora se pide a la red
  // y sólo se tira de la caché si no hay conexión (o si la red falla).
  if (STATIC_EXT.test(url.pathname)) {
    event.respondWith((async () => {
      const cache = await caches.open(STATIC_CACHE);

      try {
        const response = await fetch(request);

        if (response && response.ok && response.type === 'basic')
          cache.put(request, response.clone()).catch(() => { /* cuota */ });

        return response;
      }
      catch (e) {
        // Las hojas y los scripts se piden con `?v=<mtime>` (Http::asset),
        // asi que la copia precacheada (sin sufijo) no coincide con la
        // peticion. Se busca primero exacta y despues por ruta: sin red, la
        // version antigua de la hoja sigue sirviendo y la pagina no sale sin
        // estilos.
        const cached = await cache.match(request)
          || await cache.match(url.pathname);

        return cached || Response.error();
      }
    })());
  }
});
