# osTicket en el móvil

Cómo gestionar tickets desde un teléfono, qué se puede llevar a una app y con
qué opciones — desde lo que ya funciona hoy sin escribir código hasta el
proyecto independiente que se puede construir más adelante.

---

## 0. Resumen ejecutivo

| Escenario | Qué usar | Esfuerzo | Resultado |
|---|---|---|---|
| **Hoy mismo**, sin desarrollo | osTicket web instalado como PWA + app de correo + notificaciones del navegador | 0 | Usable en móvil; funciona sin conexión sólo para los estáticos |
| **Esta semana**, poca inversión | PWA instalada + atajos + widgets nativos + API de osTicket para automatizaciones (Zapier/Make/n8n) | Bajo | Flujo de agentes cómodo; notificaciones reales |
| **Este trimestre** | App propia (React Native / Flutter / Capacitor) contra la API HTTP de osTicket | Medio | Experiencia nativa, *push* real, modo offline de lectura |
| **Largo plazo** | Backend propio (BFF) + app + integraciones (Slack/Teams/WhatsApp) | Alto | Producto móvil completo |

La app **no hace falta para empezar**: el panel de agentes ya es responsive
(ver §2) y se puede instalar como aplicación (§3). Lo que la PWA no puede hacer
—notificaciones *push* fiables en iOS, lectura sin conexión de los tickets— es
exactamente lo que justifica la app aparte (§5).

---

## 1. Qué se puede llevar a una app móvil

Contraste entre lo que expone osTicket y lo que conviene mostrar en una pantalla
de 6 pulgadas.

### 1.1 Imprescindible (lo que un agente hace 20 veces al día)

| Función | Endpoint / fuente | Notas para móvil |
|---|---|---|
| Ver mi cola | `GET /api/tickets.json?query=assigned:me status:open` | Lista paginada; guardar el *query* como filtro favorito |
| Ver un ticket | `GET /api/tickets/{number}.json` | Incluye hilo, adjuntos y campos personalizados |
| Responder | `POST /api/tickets/{id}/reply` | Texto plano + adjuntos; el HTML del editor no cabe en móvil |
| Nota interna | `POST /api/tickets/{id}/note` | Muy útil: es la acción más frecuente tras responder |
| Cerrar / reabrir | `POST /api/tickets/{id}` con `status` | Confirmar siempre: cerrar por error es caro |
| Asignar / transferir | `PUT /api/tickets/{id}` (`assignee_id`, `dept_id`) | Selector con búsqueda, no lista completa |
| Buscar | `GET /api/tickets.json?query=...` | Búsqueda por número, correo o texto |
| Adjuntar foto | `POST /api/tickets/{id}/reply` con `attachments[]` | La cámara es la ventaja real del móvil |

### 1.2 Útil (consulta y contexto)

* **Ficha del usuario**: `GET /api/users/lookup?email=` e historial de sus tickets.
* **Organización**: departamento, SLA y tema del ticket.
* **Base de conocimiento**: `GET /api/help/topics` y respuestas enlatadas
  (`/api/canned` en versiones recientes) para responder en dos toques.
* **Panel de analíticas**: `scp/analytics.php?export=json` (añadido en este
  proyecto) devuelve los KPIs ya calculados: creados, cerrados, tiempos de
  primera respuesta y resolución, SLA, carga por agente. Es la fuente natural
  para una pestaña de “Resumen” en la app.
* **Mi actividad**: tickets asignados, vencidos y sin responder del agente
  autenticado.

### 1.3 No llevar (o llevar sólo como lectura en escritorio)

* Configuración del sistema, roles y permisos, plugins, campos de formulario.
* Gestión de correo (SMTP/IMAP) y de cuentas de correo.
* Importación/exportación masiva y edición de la base de conocimiento.
* Listados de más de ~50 filas sin paginación por cursor.

### 1.4 Autenticación: la decisión que más afecta

La API de osTicket usa **claves de API por agente** (`X-API-Key`), creadas en
*Admin → Manage → API Keys*. Para una app móvil:

| Opción | Ventaja | Riesgo | Recomendación |
|---|---|---|---|
| Clave de API guardada en el *keychain* del sistema | Simple, funciona hoy | La clave no caduca sola; hay que revocarla a mano | **Válida para uso interno** con claves individuales y rotación |
| Inicio de sesión web + cookies + CSRF en un *WebView* | Reutiliza el login existente | Convivencia frágil con PJAX y CSRF | Sólo si hace falta SSO |
| BFF propio con OAuth2/OIDC | Revocación, caducidad, auditoría, *refresh tokens* | Hay que construirlo y mantenerlo | **Camino recomendado para producción** |

Regla práctica: nunca incluir una clave de API en el binario de la app. Si se
usa el enfoque de clave, el agente la introduce en el primer arranque y se guarda
en el *keychain* (`flutter_secure_storage`, `react-native-keychain`,
`expo-secure-store`).

---

## 2. La interfaz web en el móvil (ya incluido)

Cambios aplicados en este repositorio, sin tocar la lógica ni el aspecto en
escritorio:

* **`<meta name="viewport">`** en el panel de agentes
  (`include/staff/header.inc.php`). Antes faltaba y el móvil mostraba la versión
  de escritorio reducida al 40 %.
* **`css/mobile.css`** — hoja **aditiva**, todo dentro de
  `@media (max-width: 1040px)`:
  * El contenedor de 960 px pasa a fluido.
  * La barra de pestañas (`#nav`, `#sub_nav`) se desplaza en horizontal con
    inercia, en lugar de partirse en varias líneas.
  * Área de contenido con desplazamiento horizontal propio: las tablas anchas
    se leen sin cortar columnas.
  * Objetivos táctiles ≥ 40 px en botones, pestañas y enlaces de fila.
  * Campos de formulario a 16 px para evitar el zoom automático de iOS.
  * Utilidad `table.stack` para convertir cualquier tabla en tarjetas apiladas
    (usar `data-label="Columna"` en cada `<td>`).
* **Panel de analíticas** (`scp/analytics.php`) diseñado primero para móvil:
  rejilla de tarjetas que colapsa a dos columnas, gráficas SVG sin JavaScript,
  tablas que se convierten en tarjetas con etiqueta.
* **Impresión** limpia: se ocultan navegación y botones.

Comprobado en anchos de 1024, 768, 480 y 360 px. Si necesitas ajustar algo, todas
las reglas están en un solo archivo y llevan un comentario con su motivo.

---

## 3. PWA: instalarlo como aplicación (sin desarrollo)

El proyecto ya genera:

* `manifest.php` — manifiesto dinámico (`?scope=scp` o `?scope=client`), con
  nombre, colores, iconos y **atajos** a *Cola de tickets*, *Analíticas* y
  *Mi panel*.
* `sw.js` — *service worker* que cachea **sólo estáticos** (CSS, JS, imágenes).
  Las respuestas de PHP nunca se guardan: un ticket en la caché de un móvil
  compartido sería una fuga de datos.
* `js/pwa.js` — registro del service worker, silencioso si el navegador no lo
  soporta.
* `images/pwa/` — iconos 192, 512, 512 *maskable* y `apple-touch-icon`.

### 3.1 Instalar en Android (Chrome/Edge)

1. Abre `https://tu-servidor/scp/` e inicia sesión.
2. Menú → **“Añadir a pantalla de inicio”** / **“Instalar aplicación”**.
3. Aparecerá con el icono de ticket y abrirá a pantalla completa.
4. Con los atajos, manteniendo pulsado el icono, se llega directo a la cola o a
   las analíticas.

### 3.2 Instalar en iOS (Safari)

1. Abre `https://tu-servidor/scp/` **en Safari** (en iOS ningún otro navegador
   puede instalar PWA).
2. Botón **Compartir** → **“Añadir a pantalla de inicio”**.
3. Se abre a pantalla completa sin barra de Safari.

Limitaciones reales en iOS: no hay notificaciones *push* para PWA instaladas
desde Safari (sí en Android), y el *service worker* se purga tras semanas sin uso.

### 3.3 Qué se gana y qué no

| Se gana | No se gana |
|---|---|
| Icono nativo, pantalla completa, arranque rápido | Notificaciones *push* fiables en iOS |
| Atajos a las pantallas más usadas | Lectura de tickets sin conexión |
| Cero instalaciones desde tiendas | Acceso a cámara con la misma fluidez que una app nativa |
| Actualización automática | Integración con el sistema (compartir, widgets) |

---

## 4. Opciones de apps existentes (sin escribir código)

Antes de desarrollar, vale la pena comprobar si alguna de estas cubre la
necesidad. Todas se conectan por API, correo o webhooks.

| Herramienta | Qué aporta | Encaje con osTicket | Coste |
|---|---|---|---|
| **Correo nativo del móvil** (Gmail, Outlook, Apple Mail) | Responder por correo al hilo del ticket | Total: osTicket enruta las respuestas al ticket por el asunto/cabeceras | Gratis |
| **Slack / Microsoft Teams** | Avisos de tickets nuevos en un canal, botones para tomar el ticket | Medio: requiere un puente (webhook o script) que consulte la API | Según plan |
| **Zapier / Make / n8n** | Automatizaciones: nuevo ticket → notificación, resumen diario, escalado | Alto con `n8n` autoalojado (llama a la API REST) | n8n: gratis autoalojado |
| **AppSheet / Glide / Retool Mobile** | App de bajo código sobre una hoja de cálculo o API | Medio: hay que sincronizar los tickets a su origen de datos | Según plan |
| **Twilio / WhatsApp Business** | Canal de entrada de tickets y respuestas | Medio: osTicket soporta canales por plugin | Por mensaje |
| **Home Assistant / Tasker** | Avisos en el móvil y acciones rápidas | Bajo, pero suficiente para “nuevo ticket urgente” | Gratis |

Lo que **no** existe: una app móvil oficial de osTicket. El proyecto upstream no
publica una, y las de la tienda son de terceros sin garantías. Por eso la ruta
recomendada es PWA hoy y app propia (o de bajo código) si hace falta más.

---

## 5. La app aparte: propuesta de proyecto

Cuando la PWA no baste, este es el plan mínimo viable.

### 5.1 Alcance de la primera versión

1. Inicio de sesión con clave de API (guardada en el *keychain*).
2. **Mi cola**: lista con filtros guardados (`assigned:me`, `status:open`,
   `status:overdue`).
3. **Detalle del ticket**: hilo, adjuntos, datos del usuario.
4. **Responder** y **nota interna** con texto plano y adjuntos (cámara/galería).
5. **Cambiar estado**, asignar y transferir.
6. **Notificaciones *push*** de tickets nuevos asignados.
7. **Modo sin conexión de lectura**: últimos 50 tickets vistos, con aviso claro
   de que las respuestas se encolan y se envían al recuperar red.

Fuera de la v1: analíticas, base de conocimiento, administración.

### 5.2 Stack sugerido

| Pieza | Opción A | Opción B | Por qué |
|---|---|---|---|
| Framework | **Flutter** | React Native (Expo) | Un solo código para iOS/Android; widgets nativos |
| Estado | Riverpod | Redux Toolkit / Zustand | Cache y reintentos explícitos |
| Almacenamiento | SQLite (`drift`) + *keychain* | SQLite (`expo-sqlite`) + *secure-store* | Offline de lectura y credenciales seguras |
| Notificaciones | FCM (Android) + APNs (iOS) | Expo Push | Requiere un backend mínimo que reciba los webhooks |
| Backend | **BFF Node/PHP** que hable con la API de osTicket | Llamada directa a la API | El BFF permite *push*, caché y revocación de credenciales |

Alternativa de bajo coste: **Capacitor** envolviendo la PWA ya existente. Se
gana cámara, notificaciones y publicación en tiendas sin reescribir la interfaz;
se pierde fluidez frente a una app nativa.

### 5.3 Contrato de datos sugerido para la app

El endpoint añadido en este proyecto, `scp/analytics.php?export=json`, es el
modelo a seguir: un JSON estable, con permisos aplicados en el servidor y sin
lógica en el cliente.

```json
{
  "range": { "start": "2026-09-22", "end": "2026-10-21" },
  "summary": {
    "created": 328, "closed": 291, "open": 45, "overdue": 7,
    "first_response_h": 2.4, "resolution_h": 45.6, "sla_pct": 93.1
  },
  "daily": { "labels": ["2026-09-22"], "created": [12], "closed": [10] },
  "agents": [{ "name": "Ana Pérez", "assigned": 34, "closed": 29 }],
  "departments": [{ "name": "Soporte", "created": 120, "closed": 110 }]
}
```

Para los tickets, la propia API de osTicket ya devuelve JSON estable
(`/api/tickets.json`); conviene envolverla en el BFF para normalizar errores,
paginar por cursor y añadir la versión del contrato (`Accept: application/json;
version=2`).

### 5.4 Consideraciones de seguridad específicas del móvil

* **Nunca** guardar la clave de API en `localStorage` ni en preferencias planas.
* Pantalla de bloqueo biométrica al abrir la app (opcional pero recomendable en
  entornos sanitarios o financieros).
* Borrado remoto de la sesión local si el agente se desactiva (`/api/staff`).
* Cifrado en reposo de la base local (SQLCipher en Flutter, `expo-sqlite` +
  cifrado a nivel de archivo).
* Registro de auditoría: osTicket ya registra quién responde; la app debe enviar
  el `User-Agent` propio para distinguir el canal.
* Límite de tamaño de adjuntos: respetar `upload_max_filesize` (20 MB en la
  imagen incluida) y comprimir fotos antes de subir.

### 5.5 Plan por fases

| Fase | Duración estimada | Entregable |
|---|---|---|
| 0. Validación | 1 semana | Prototipo con la PWA instalada; medir si el equipo la usa |
| 1. Núcleo | 3–4 semanas | Login, lista, detalle, responder, nota, estado |
| 2. Notificaciones | 1–2 semanas | BFF con webhooks + FCM/APNs |
| 3. Offline | 2 semanas | Cache local de lectura y cola de envío |
| 4. Publicación | 1 semana | Fichas de tienda, política de privacidad, *beta* cerrada |

---

## 6. Cómo probar todo esto sin un móvil físico

1. **Chrome DevTools** → *Toggle device toolbar* (`Ctrl+Shift+M`): 360×640, 390×844
   (iPhone 14), 412×915 (Pixel). Activa *“Show media queries”*.
2. **Modo offline**: DevTools → *Network* → *Offline*, recarga: debe aparecer la
   pantalla de “Sin conexión” del *service worker* (no una copia del panel).
3. **Instalación PWA**: DevTools → *Application* → *Manifest* (sin errores) y
   *Service Workers* (activo, con la versión `ost-v1`).
4. **Accesibilidad táctil**: *Lighthouse* → categoría *Accessibility*; busca
   objetivos táctiles menores de 24 px (el objetivo del proyecto es 40 px).
5. **Rendimiento móvil**: *Lighthouse* en modo móvil con *throttling*; el panel
   de analíticas debe quedar por debajo de 2 s de *First Contentful Paint* en un
   servidor local.

Si tienes acceso a un teléfono real en la misma red, apunta el navegador a
`http://IP-DE-TU-PC:8080`. Recuerda que **PWA e instalación requieren HTTPS**
(o `localhost`) en producción: en la red local por HTTP el *service worker* no
se registra, aunque la interfaz responsive funciona igual.

---

## 7. Lista de verificación antes de dar el visto bueno

- [ ] El panel de agentes se usa sin zoom en un móvil de 360 px.
- [ ] Se puede responder un ticket con una foto adjunta desde el teléfono.
- [ ] `/scp/` se instala como aplicación y abre a pantalla completa.
- [ ] El *service worker* no guarda ninguna respuesta de PHP (revisar *Cache
      Storage* en DevTools).
- [ ] Con el móvil en modo avión, la app muestra el aviso de “Sin conexión” y no
      datos antiguos.
- [ ] Los enlaces del pie y la navegación tienen objetivos táctiles ≥ 40 px.
- [ ] `php tools/security/php-cve-check.php` sigue en verde en el servidor que
      atiende al móvil.
