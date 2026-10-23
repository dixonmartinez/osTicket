# Guía de desarrollo — osTicket

Esta guía describe cómo está organizado el código, cómo se procesa una
petición y **dónde tocar cada cosa**, para reducir el tiempo entre la idea y
el cambio publicado.

---

## 1. Puesta en marcha

### Requisitos

- PHP 8.2+ con `mysqli`, `mbstring`, `gettext`, `gd`, `intl`, `zip`, `xml`
  — **con el parche de php2shell del 2026-10-21** (8.2.35 / 8.3.36 / 8.4.27 /
  8.5.12 o superior, ver [`SECURITY_PHP2SHELL.md`](SECURITY_PHP2SHELL.md)).
- MySQL 5.7+/8.0 o MariaDB 10.3+.
- Servidor web (Apache/Nginx) o `php -S` para desarrollo.

### Opción recomendada: Docker (no instala nada en el host)

```bash
make docker-up                  # nginx + PHP-FPM (Xdebug) + MariaDB + Mailpit
# VS Code → F5 → "Xdebug: osTicket (web)"
```

Todo el mundo trabaja con la misma versión de PHP y las mismas extensiones de
servidor, y el depurador queda configurado sin tocar el sistema operativo.
Guías: [`VSCODE.md`](VSCODE.md) (día a día en el editor) y
[`DOCKER.md`](DOCKER.md) (stack de contenedores).

### Instalación local sin Docker

```bash
cp include/ost-sampleconfig.php include/ost-config.php
# edita host, usuario, contraseña y prefijo
php -S 0.0.0.0:8080 -t .        # o: make serve
# abre http://localhost:8080/setup/
```

> `include/ost-config.php` está en `.gitignore`: **nunca** lo subas al
> repositorio.

### Herramientas del repositorio

| Comando | Qué hace | Tiempo |
|---|---|---|
| `make lint` | Sintaxis de todos los PHP (paralelo) | ~2 s |
| `make audit` | Análisis de seguridad, falla con hallazgos nuevos altos | ~2 s |
| `make audit-all` | Informe completo con severidad baja | ~2 s |
| `make unit` | Pruebas unitarias sin base de datos: `Format`, `Validator`, piezas puras de ticket e hilo, y las herramientas de calidad | ~0,2 s |
| `make test` | Alias de `make unit` | ~0,1 s |
| `make test-legacy` | Suite histórica del instalador (`setup/test/`) | variable |
| `make security` | Versión de PHP frente a php2shell (CVE-2026-109618/19) | ~1 s |
| `make theme` | Integridad del tema claro/oscuro (sin navegador) | ~1 s |
| `make metrics` | Radiografía del código: tamaño y deuda técnica | ~2 s |
| `make classes` | Mapa de clases por archivo; falla si una clase se declara dos veces o un `include` literal apunta a un archivo que no existe | ~1 s |
| `make ci` | lint + audit + security + theme + classes + unit (verificación previa al commit) | ~9 s |
| `make docker-test` | `make ci` dentro del contenedor | ~30 s |
| `make docker-qa` | PHPStan + PHP_CodeSniffer (requiere `make docker-composer`) | variable |
| `php tools/scaffold.php` | Genera esqueletos de página/AJAX | instantáneo |

No requieren Composer ni extensiones especiales.

---

## 2. Mapa del código

```
.
├── bootstrap.php           Arranque: ini, config, clases, conexión
├── main.inc.php            Núcleo común: configuración + sesión + $ost
├── client.inc.php          Contexto del portal del cliente (+ CSRF global)
├── index.php open.php …    Vistas públicas
├── login.php pwreset.php   Autenticación del cliente
├── tickets.php view.php    Área privada del cliente
├── file.php avatar.php     Servido de archivos con firma
├── ajax.php                Router AJAX del cliente
├── scp/                    Panel de agentes
│   ├── staff.inc.php       Contexto de agente (+ CSRF global)
│   ├── admin.inc.php       Contexto de administración (permisos)
│   ├── ajax.php            Router AJAX del panel
│   ├── tickets.php …       Pantallas (controladores finos)
│   └── css/ js/            Recursos exclusivos del panel
├── api/                    API HTTP (http.php, cron.php, pipe.php)
├── include/
│   ├── class.*.php         Dominio: ticket, user, staff, ticket, orm…
│   ├── ajax.*.php          Controladores AJAX
│   ├── staff/*.inc.php     Vistas del panel (HTML)
│   ├── client/*.inc.php    Vistas del portal (HTML)
│   ├── staff/templates/    Fragmentos reutilizables
│   ├── i18n/               Traducciones
│   └── plugins/            Plugins instalados
├── setup/                  Instalador (eliminar en producción)
├── css/ js/ assets/        Tema base
└── tools/                  Utilidades de desarrollo (nuevo)
```

### Reglas de ubicación

| Si vas a añadir… | Va en |
|---|---|
| Lógica de negocio reutilizable | `include/class.<dominio>.php` |
| Una pantalla del panel | `scp/<nombre>.php` (control) + `include/staff/<nombre>.inc.php` (vista) |
| Una pantalla del portal | `<nombre>.php` + `include/client/<nombre>.inc.php` |
| Un endpoint AJAX | `include/ajax.<nombre>.php` + ruta en `ajax.php` o `scp/ajax.php` |
| Un endpoint de API | `include/api.<nombre>.php` + ruta en `api/http.php` |
| Estilos globales | `css/` (núcleo) o `assets/default/css/` (tema personalizable) |
| Scripts | `js/` |
| Traducciones | `include/i18n/` (herramientas en `include/cli/modules/i18n.php`) |

---

## 3. Ciclo de una petición

```
Petición HTTP
   │
   ├─ .htaccess / web.config → rewrite a index.php / scp/index.php / api/http.php
   │
   ├─ bootstrap.php
   │     Bootstrap::init()       ini hardening, errores, sesión
   │     Bootstrap::loadConfig() lee include/ost-config.php
   │     Bootstrap::loadCode()   registra clases (autoloader)
   │     Bootstrap::connect()    mysqli + configuración de la BD
   │
   ├─ main.inc.php               $ost, $cfg, sesión, HTTPS, errores
   │
   ├─ client.inc.php  |  scp/staff.inc.php
   │     ACL, usuario ($thisclient / $thisstaff), CSRF para POST/PUT/…
   │
   ├─ Controlador (ej. scp/tickets.php)
   │     valida permisos, procesa $_POST, llama al dominio (class.*.php)
   │
   └─ Vista (include/staff/*.inc.php) → header.inc.php … footer.inc.php
```

Puntos clave:

- **CSRF es global**: `client.inc.php` y `scp/staff.inc.php` rechazan
  cualquier `POST`/`PUT`/`PATCH`/`DELETE` sin token. No hace falta repetirlo
  en cada formulario, pero sí incluir `<?php csrf_token(); ?>`.
- **Permisos**: `staff.inc.php` exige sesión; cada pantalla afina con
  `$thisstaff->hasPerm(...)`. `admin.inc.php` exige `isAdmin()`.
- **PJAX**: el panel navega con PJAX; los enlaces que no deben usarlo llevan
  `class="no-pjax"`.

---

## 4. Recetas

### 4.1 Nueva pantalla del panel (2 minutos)

```bash
php tools/scaffold.php page informes --title="Informes"
# crea scp/informes.php e include/staff/informes.inc.php
```

Revisa:

1. `scp/informes.php` → ajusta el permiso (`$thisstaff->hasPerm(...)`).
2. `include/staff/informes.inc.php` → construye la vista.
3. Si debe salir en el menú, añádelo en `include/class.nav.php`
   (`StaffNav::addSubMenu` o el `addTab` correspondiente).
4. Traduce los textos nuevos con `__('…')`.

### 4.2 Nuevo endpoint AJAX

```bash
php tools/scaffold.php ajax inventario
```

La clase generada extiende `AjaxController`. Consideraciones:

- `$this->staffOnly()` si sólo lo usa el panel.
- Devuelve siempre con `$this->json_encode([...])` o
  `Http::response(código, mensaje)`.
- Registra la ruta:

```php
// scp/ajax.php
url('^/inventario/(?P<id>\d+)$', array('ajax.inventario.php:InventarioAjaxAPI', 'get')),
url_post('^/inventario/(?P<id>\d+)$', array('ajax.inventario.php:InventarioAjaxAPI', 'update')),
```

- En el cliente se envía automáticamente la cabecera `X-CSRFToken`
  (`js/osticket.js`), pero el endpoint **debe** seguir validando permisos.

### 4.3 Nuevo campo en un formulario

Los formularios son dinámicos (`include/class.forms.php`). Un campo nuevo
implica:

1. Implementar (o reutilizar) una clase en `include/class.forms.php` que
   extienda `FormField`.
2. Registrar el tipo en `DynamicFormField::getImpl()` / el mapa
   `$field_types`.
3. Renderizar: `FormField::render()` usa las plantillas de
   `include/staff/templates/`.
4. Validación: `FormField::validate()` en el servidor; el cliente es sólo
   comodidad.
5. Persistencia: `getClean()`, `to_database()`, `from_database()`.

### 4.4 Reaccionar a un evento (signals)

```php
Signal::connect('ticket.created', function ($ticket) {
    // ...
});
```

Eventos disponibles: `grep -rn "Signal::send(" include/`.

### 4.5 Nueva métrica en el panel de analíticas

`include/class.analytics.php` expone una clase con métodos independientes
(`getSummary()`, `getAgentBreakdown()`, `getBacklogAge()`, …). Para añadir una
métrica:

1. Escribe el método en `Analytics` con **consultas agregadas** (no traigas
   filas a PHP) y aplica `$this->deptClause()` para respetar permisos.
2. Añádelo a `toArray()` si debe salir por el endpoint JSON
   (`scp/analytics.php?export=json`).
3. Píntalo en `include/staff/analytics.inc.php` con `AnalyticsChart::barList()`
   o `lineSvg()` (SVG generado en el servidor, sin JavaScript).

### 4.6 Tarea programada / cron

Añade la lógica en `include/class.cron.php` (`Cron::run()` → tareas
registradas) o como módulo CLI en `include/cli/modules/`. El cron del
sistema ejecuta `api/cron.php` con la clave configurada.

### 4.6 Nuevo idioma o traducción

```bash
php manage.php i18n --help
```

Los textos deben ir siempre en `__()` (interfaz) o `_S()` (mensajes de
sistema) para que entren en el catálogo.

---

## 5. Convenciones obligatorias

### Salida

```php
echo Format::htmlchars($texto);   // siempre para datos
echo Format::display($html);      // sólo si el HTML ya se saneó (htmLawed)
printf('<td>%d</td>', (int) $n);  // números con cast
```

### Entrada

```php
$id   = (int) ($_GET['id'] ?? 0);              // identificadores
$name = trim((string) ($_POST['name'] ?? '')); // texto
if (!Validator::is_email($email)) { … }        // formatos
```

No confíes en la validación del navegador (`required`, `type=email`).

### SQL

```php
// Preferido: ORM
$user = User::lookup($id);
$rows = User::objects()->filter(['org_id' => $org])->limit(20);

// SQL directo
$sql = 'SELECT * FROM '.USER_TABLE.' WHERE id='.db_input($id);
```

Nunca interpoles datos sin `db_input()`/`db_real_escape()`.

### Redirecciones y URLs

```php
Http::redirect(ROOT_PATH.'tickets.php?id='.(int) $id);
$host = Http::host();      // nunca $_SERVER['HTTP_HOST'] directamente
```

### Errores

```php
$errors = [];
if (!$algo) $errors['campo'] = __('Mensaje para el usuario');
$ost->logError(__('Título'), $detalle);   // log interno, sin datos sensibles
```

---

## 6. Rendimiento: dónde mirar primero

| Síntoma | Dónde revisar |
|---|---|
| Listados lentos | `include/class.search.php`, `include/class.queue.php`; añadir índices en la tabla correspondiente y limitar `JOIN` |
| Panel lento al cargar | Número de consultas por página: activa `OST_DEBUG` y usa el log de SQL (`db_query` con `$ost->logDebug`) |
| Correo lento | `include/class.mailer.php`; prioriza cola (`cron`) sobre envío síncrono |
| Adjuntos grandes | `CHUNK_SIZE` en `include/class.file.php`; `max_allowed_packet` de MySQL |
| Búsqueda lenta | Motor de búsqueda configurable (`class.search.php`, soporte FULLTEXT/Sphinx) |
| Sesiones | Backend `database` vs `memcache` (`include/class.ostsession.php`) |

Optimizaciones ya disponibles: caché de configuración (`class.config.php`),
caché de instancias del ORM, carga diferida de listas, PJAX para el panel.

---

## 7. Pruebas y calidad

- **Sintaxis**: `make lint` (obligatorio antes de cada commit).
- **Deuda técnica**: `make metrics` antes y después de un refactor; plan en
  [`CODE_QUALITY.md`](CODE_QUALITY.md). `make classes` dice qué clase vive en
  qué archivo y detecta duplicados que `make lint` no puede ver (cada archivo
  es válido por separado).
- **Seguridad**: `make audit` (falla con hallazgos altos nuevos). Cualquier
  excepción deliberada se anota con `// audit:ignore <REGLA> -- motivo`.
- **Pruebas unitarias**: `make unit` (o `make test`). Viven en `tests/` como
  `*Test.php` y extienden `UnitTestCase`. No necesitan base de datos ni
  Composer: `tests/run.php` las ejecuta igual. Con `composer install`,
  `make unit` usa PHPUnit. Detalle en
  [`CODE_QUALITY.md`](CODE_QUALITY.md#frente-1--red-de-seguridad-antes-de-tocar-nada-imprescindible).
- **Suite histórica**: `make test-legacy` (`setup/test/run-tests.php`:
  métodos inexistentes, variables sin inicializar, tabs, etc.).
- **Sin CI alojado**: este fork no configura pipelines automáticos en GitHub.
  La verificación (`make ci`: lint + auditoría + versión de PHP + tema +
  pruebas unitarias) se ejecuta a mano o como paso previo a cada integración,
  en local o dentro del contenedor (`make docker-test`).

### Checklist de pull request

- [ ] `make ci` en verde.
- [ ] Toda salida de datos pasa por `Format::htmlchars()`/`Format::display()`.
- [ ] Toda consulta usa ORM o `db_input()`.
- [ ] Los formularios incluyen `csrf_token()`.
- [ ] Los permisos se validan en el servidor (`hasPerm`, `isAdmin`, `staffOnly`).
- [ ] Los textos nuevos usan `__()` y no rompen el layout en idiomas largos.
- [ ] Probado en escritorio y en ventana estrecha (< 782 px).
- [ ] Sin `var_dump`, `print_r` ni credenciales en el código.
- [ ] Documentación/comentarios actualizados si cambia el comportamiento.

---

## 8. Depuración

### Con Xdebug (recomendado, ya configurado)

```bash
make docker-up
```

- `F5` → **“Xdebug: osTicket (web)”** y navega a <http://localhost:8080>.
- `F5` → **“Xdebug: script CLI (vía Docker)”** con el script abierto en el
  editor; se ejecuta dentro del contenedor.
- El *path mapping* (`/var/www/html` → `${workspaceFolder}`) ya está resuelto.
- Variables: `XDEBUG_MODE` (`debug`, `develop`, `coverage`, `off`) y
  `XDEBUG_TRIGGER` cuando se usa `start_with_request=trigger`.

Flujo de trabajo y problemas frecuentes: [`VSCODE.md`](VSCODE.md).
Variables de Xdebug: [`DOCKER.md`](DOCKER.md) §3.

### Sin Xdebug

```php
// include/ost-config.php (sólo en local)
define('OST_DEBUG', true);   // muestra errores PHP en pantalla
```

- Log de la aplicación: panel → *Dashboard → System Logs* (`ost_syslog`).
- Log de PHP: según `error_log` del `php.ini`.
- Correo: `scp/emailtest.php` y `php manage.php` para diagnósticos.
- Consultas: añade temporalmente un `Signal::connect('sql.query', …)` o
  instrumenta `db_query()` en local.

---

## 9. Dónde tocar para las tareas más frecuentes

| Necesito… | Archivo |
|---|---|
| Cambiar el asunto/plantilla de un correo | `include/class.template.php`, `include/i18n/<lang>/templates/` |
| Añadir un estado o prioridad | `include/class.ticket.php`, `include/class.priority.php`, `include/staff/templates/` |
| Cambiar las reglas de asignación | `include/class.filter.php`, `include/class.filter_action.php` |
| Añadir un campo al formulario de ticket | `include/class.dynamic_forms.php` + panel de formularios |
| Ajustar SLA | `include/class.sla.php` |
| Modificar la navegación | `include/class.nav.php` |
| Añadir una página de ayuda/KB | `include/class.faq.php`, `include/class.knowledgebase.php` |
| Autenticación externa (SSO/OAuth2) | `include/class.oauth2.php`, `include/class.auth.php` |
| API de tickets | `include/api.tickets.php`, `api/http.php` |
| Cabeceras de seguridad | `include/class.http.php`, `include/*/header.inc.php` |

---

## 10. Estilo y compatibilidad

- Indentación con 4 espacios, UTF-8 sin BOM, final de línea LF
  (`.editorconfig`).
- PHP compatible con 8.2-8.4; evita sintaxis experimental más allá de 8.4.
- No introduzcas dependencias nuevas de Composer en el *runtime*: el
  proyecto se distribuye con las librerías embebidas.
- Mantén el estilo del archivo que editas (el núcleo histórico no sigue
  PSR-12); el código nuevo en `tools/` sí lo sigue (`phpcs.xml.dist`).
- Los comentarios y mensajes de usuario existentes están en inglés; las
  cadenas visibles deben pasar por gettext. Esta documentación está en
  español por requisito del equipo.
