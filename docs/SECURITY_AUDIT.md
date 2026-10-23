# Auditoría de seguridad — osTicket

| Campo | Valor |
|---|---|
| Proyecto | osTicket (rama `develop`, versión 1.18.4) |
| Fecha | 2026-10-21 |
| Alcance | Núcleo PHP (`include/`, `scp/`, `api/`, raíz), plantillas, configuración y cabeceras HTTP |
| Metodología | Revisión manual de flujo de datos + análisis estático propio + verificación de configuraciones |
| Herramientas | `tools/audit/scan.php` (nueva), `tools/lint.sh` (nueva), PHP 8.5 CLI |
| Estado | 12 hallazgos corregidos · 6 recomendaciones abiertas |
| Actualización | 2 hallazgos nuevos añadidos el 2026-10-21 tras la divulgación de **php2shell** (ver §3.11 y §3.12) |

---

## 1. Resumen ejecutivo

osTicket es una aplicación madura y, en las rutas revisadas, **no se han
encontrado vulnerabilidades críticas explotables** (RCE, SQLi directa,
subida de archivos ejecutables o bypass de autenticación). El diseño de
seguridad de base es sólido:

- Todas las consultas pasan por `db_input()`/PDO o por el ORM.
- El token CSRF se valida de forma **global** en `client.inc.php` y
  `scp/staff.inc.php` para `POST`, `PUT`, `PATCH` y `DELETE`.
- Las contraseñas se almacenan con bcrypt (phpass).
- Los adjuntos se guardan en base de datos, no en un directorio web.
- La descarga de archivos exige firma HMAC con caducidad.
- La sesión se regenera al iniciar sesión.

Los problemas encontrados son de **endurecimiento** (defensa en
profundidad) y de **exposición de información**, más un XSS reflejado real y
una gestión mejorable de secretos heredados. Todos ellos se han corregido en
esta rama salvo los que requieren una decisión operativa del administrador
(ver §6).

### Hallazgos por severidad

| Severidad | Corregidos | Abiertos |
|---|---|---|
| Crítica | **1** | 0 |
| Alta | 1 | 0 |
| Media | 6 | 5 |
| Baja | 4 | 1 |

> El único hallazgo crítico (OST-011) es una vulnerabilidad del **intérprete
> PHP**, no del código de osTicket. Se contabiliza porque el proyecto ahora la
> controla y la bloquea (§3.11).

### Tabla de hallazgos

| ID | Severidad | Hallazgo | Estado |
|---|---|---|---|
| OST-001 | Media (5.4) | XSS reflejado en el parámetro `order` (panel y portal) | ✅ Corregido |
| OST-002 | Media (5.3) | `Host` no validado → envenenamiento de enlaces/redirecciones | ✅ Corregido |
| OST-003 | Media (5.3) | `display_errors` activo en producción | ✅ Corregido |
| OST-004 | Baja (3.1) | Cabeceras de seguridad incompletas y CSP débil | ✅ Corregido |
| OST-005 | Baja (3.7) | Comparación de hashes/OTP no constante en tiempo | ✅ Corregido |
| OST-006 | Alta (7.4)* | `SECRET_SALT` predecible en instalaciones heredadas | ⚠️ Mitigado + aviso |
| OST-007 | Media (5.9)* | Ruta heredada MD5 para contraseñas | ✅ Mitigado (rehash) |
| OST-008 | Media (4.9) | Interpolación SQL en la búsqueda de plugins | ✅ Corregido |
| OST-009 | Baja (2.0) | Banner del servidor impreso sin escapar | ✅ Corregido |
| OST-010 | Baja (3.1) | Cookie de sesión inicial sin `SameSite` | ✅ Corregido |
| OST-011 | **Crítica (9.2)** | PHP vulnerable a **php2shell** (CVE-2026-109618 / 109619) | ✅ Mitigado + controlado |
| OST-012 | Media | Configuración PHP que habilita rutas de php2shell (`session.upload_progress`, `fatal_error_backtraces`) | ✅ Corregido |
| OST-101 | Baja | `validateId()` siempre `true` (modo estricto inefectivo) | ⏳ Recomendación |
| OST-102 | Media | `unsafe-inline` / `unsafe-eval` en la CSP | 🔄 En curso (29 manejadores inline) |
| OST-103 | Media | Sin limitación de intentos a nivel de infraestructura | ⏳ Recomendación |
| OST-104 | Media | Directorio `setup/` presente tras instalar | ⏳ Operación |
| OST-105 | Media | Contraseñas heredadas MD5 en base de datos | ⏳ Operación |
| OST-106 | Media | Inventario/servicio de actualizaciones de librerías | ⏳ Recomendación |

\* La severidad aplica **sólo** a instalaciones que cumplen la condición
indicada (heredadas o con `SECRET_SALT` ausente). En una instalación nueva
el riesgo real es bajo.

---

## 2. Alcance y metodología

### 2.1 Superficie revisada

| Área | Archivos clave |
|---|---|
| Enrutado y arranque | `bootstrap.php`, `main.inc.php`, `client.inc.php`, `scp/staff.inc.php`, `include/class.dispatcher.php` |
| Autenticación | `include/class.auth.php`, `include/class.staff.php`, `include/class.client.php`, `scp/login.php`, `login.php`, `pwreset.php`, `scp/pwreset.php` |
| Sesión/CSRF | `include/class.ostsession.php`, `include/class.session.php`, `include/class.csrf.php` |
| Entrada/salida | `include/class.format.php`, `include/htmLawed.php`, plantillas de `include/client/` e `include/staff/` |
| Datos | `include/mysqli.php`, `include/class.orm.php`, `include/class.search.php` |
| Archivos | `file.php`, `include/class.file.php`, `include/class.attachment.php`, `include/class.forms.php` |
| API | `api/*.php`, `include/class.api.php`, `include/api.*.php` |
| Configuración | `include/ost-sampleconfig.php`, `web.config`, `.htaccess`, cabeceras en cabeceras de plantilla |
| Criptografía | `include/class.crypto.php`, `include/PasswordHash.php`, `include/class.passwd.php`, `include/class.2fa.php` |

### 2.2 Método

1. **Inventario**: 1.522 archivos PHP en el repositorio; **512 revisados**
   (se excluyó código de terceros: `include/pear`, `include/fpdf`,
   `include/mpdf`, `include/laminas-mail`).
2. **Revisión de flujo de datos**: se siguió el recorrido de cada
   superglobal (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`,
   cabeceras) hasta los *sinks*: salida HTML, SQL, cabeceras HTTP, sistema
   de archivos, comandos y criptografía.
3. **Análisis estático**: se implementó `tools/audit/scan.php`, que codifica
   los patrones de riesgo históricos de este proyecto y se ejecuta en cada
   cambio y en CI.
4. **Verificación**: sintaxis validada en los 519 archivos con PHP 8.5
   (`0` errores); pruebas unitarias de los helpers nuevos (§5).
5. **Revisión de configuración**: `php.ini`, servidor web, permisos y
   valores por defecto de la aplicación.

### 2.3 Limitaciones

- No se realizó pentesting dinámico contra una instancia con base de datos,
  ni pruebas de fuzzing de los parsers de correo (MIME/TNEF).
- Los módulos de terceros (`mpdf`, `laminas-mail`, `pear`) se revisaron sólo
  por versión e integración, no línea a línea.
- Los plugins de terceros y los idiomas personalizados quedan fuera del
  alcance.

---

## 3. Hallazgos corregidos

### OST-001 — XSS reflejado en el parámetro `order` · Media

**Ubicación**: `include/staff/directory.inc.php` y
`include/client/tickets.inc.php`.

**Evidencia (antes)**

```php
$x = $sort.'_sort';
$$x = ' class="'.strtolower($_REQUEST['order'] ?: 'desc').'" ';
```

El valor de `order` se concatena **sin escapar** dentro de un atributo HTML
que luego se imprime (`<?php echo $name_sort; ?>`). Aunque la dirección de
ordenación usada en SQL sí se validaba, el valor reflejado no:

```
/scp/directory.php?order="><script>alert(document.domain)</script>
```

produce `<a class=""><script>…</script>" href=…>` y ejecuta el script en el
contexto del agente que pulsa el enlace (robo de sesión/CSRF desde el
navegador de la víctima). Igual en `tickets.php?order=…` del portal del
cliente.

**Impacto**: ejecución de JavaScript en el origen de la aplicación. Requiere
que la víctima (agente o cliente autenticado) abra una URL manipulada.

**Corrección aplicada**: se sustituye el reflejo por una lista blanca de dos
valores (`asc`/`desc`), independiente del texto recibido:

```php
$order_dir = (isset($_REQUEST['order'])
        && strcasecmp($_REQUEST['order'], 'ASC') === 0) ? 'asc' : 'desc';
$$x = ' class="'.$order_dir.'" ';
```

**Verificación**: la regla `OST-XSS-ATTR` del analizador detectaba este
patrón y ya no produce hallazgos.

---

### OST-002 — `Host` no validado (envenenamiento de enlaces y redirecciones) · Media

**Ubicación**: `include/class.http.php` (`domain()`, `url()`),
`include/class.osticket.php` (`get_base_url()`),
`include/client/header.inc.php` (enlaces `alternate`),
`main.inc.php` (redirección forzada a HTTPS).

**Evidencia (antes)**

```php
header("Location: https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
$url = sprintf('http%s://%s', $https ? 's' : '', $_SERVER['HTTP_HOST']);
<link rel="alternate" href="//<?php echo $_SERVER['HTTP_HOST'] . ...; ?>">
```

La cabecera `Host` la controla el cliente. Se usaba sin validar para:

- construir la URL base (enlaces en correos, exportaciones, `ETag`);
- redirigir a HTTPS (redirección abierta hacia un dominio del atacante);
- emitir enlaces canónicos (`hreflang`) con HTML sin escapar;
- derivar el dominio de la cookie de sesión (`cookie tossing`).

**Corrección aplicada**: nuevo helper único `Http::host()` que acepta sólo
`hostname[:puerto]` o un literal IPv6 y descarta cualquier otro valor
(sustituyéndolo por `SERVER_NAME`). Todos los puntos anteriores lo usan
ahora. Los enlaces `alternate` además escapan la salida con
`Format::htmlchars()` y la redirección conserva únicamente ruta y *query
string* locales.

**Verificación**: 8 casos de prueba, incluidos `evil.com"><script>`,
`evil.com\r\nX-Injected: 1` y `a..b.com`, devuelven el valor seguro (§5).

---

### OST-003 — `display_errors` activo en producción · Media

**Ubicación**: `bootstrap.php` (`Bootstrap::init()`).

**Evidencia (antes)**

```php
#Don't display errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
```

Cualquier *warning* o excepción no capturada se envía al navegador: rutas
absolutas, consultas SQL, credenciales en *stack traces* y estructura
interna. Es una fuente habitual de información para escalar un ataque.

**Corrección aplicada**: por defecto se ocultan y se registran en el log. Se
activan sólo si el administrador define `OST_DEBUG` en
`include/ost-config.php` (documentado en `include/ost-sampleconfig.php`).

---

### OST-004 — Cabeceras de seguridad incompletas · Baja

**Ubicación**: `include/client/header.inc.php`,
`include/staff/header.inc.php`, `include/staff/login.header.php`.

**Evidencia (antes)**: sólo se emitía `Content-Security-Policy` con
`script-src`, `object-src` y `frame-ancestors`. Faltaban
`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options` (respaldo
para navegadores antiguos) y HSTS.

**Corrección aplicada**: `Http::security_headers()` y `Http::csp()`
componen las cabeceras de forma centralizada:

```
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
X-Frame-Options: SAMEORIGIN            (sólo si no se permite iframe)
Strict-Transport-Security: ...         (sólo sobre HTTPS)
Content-Security-Policy: object-src 'none'; base-uri 'self';
    style-src 'self' 'unsafe-inline';
    script-src 'self' 'unsafe-inline' ['unsafe-eval' sólo en el panel];
    frame-ancestors <configuración del administrador>
```

La CSP se mantiene **deliberadamente conservadora**: sólo se añaden
directivas que no rompen imágenes remotas, plugins ni widgets embebidos.
`frame-ancestors` sigue respetando la configuración de iframes del panel.

---

### OST-005 — Comparaciones no constantes en tiempo · Baja

**Ubicación**: `include/PasswordHash.php` (`CheckPassword`),
`include/class.2fa.php` (`_validate`).

**Evidencia (antes)**

```php
return $hash == $stored_hash;                  // phpass
return (!strcmp($store['otp'], $otp));         // 2FA
```

Ambas comparaciones terminan en el primer byte distinto, lo que filtra
información temporal sobre el hash/OTP.

**Corrección aplicada**: `hash_equals()` en ambos casos, con validación de
tipo en el OTP para evitar errores con entradas no escalares.

---

### OST-006 — `SECRET_SALT` predecible en instalaciones heredadas · Alta

**Ubicación**: `bootstrap.php` (compatibilidad con instalaciones antiguas).

**Evidencia**

```php
if (!defined('SECRET_SALT'))
    define('SECRET_SALT', md5(TABLE_PREFIX.ADMIN_EMAIL));
```

`TABLE_PREFIX` (`ost_` por defecto) y `ADMIN_EMAIL` son datos públicos. Ese
valor alimenta la firma de enlaces (`file.php`, tokens de acceso, enlaces de
restablecimiento), el token CSRF y el cifrado de credenciales almacenadas
(`Crypto`). Un atacante que conozca el correo del administrador podría
falsificar firmas.

**Por qué no se cambia la derivación**: alterar el valor invalidaría los
enlaces y credenciales cifradas ya existentes. Las instalaciones nuevas
reciben un salto aleatorio de 32 caracteres generado con
`Crypto::random()` durante la instalación (`%CONFIG-SIRI`).

**Mitigación aplicada**: aviso persistente en el panel de administración
cuando se detecta el valor heredado, con instrucciones para definir un
`SECRET_SALT` aleatorio en `include/ost-config.php`.

**Acción recomendada** (§6): definir manualmente un `SECRET_SALT` de 32+
caracteres aleatorios en instalaciones heredadas y planificar la
recaducidad de enlaces.

---

### OST-007 — Ruta heredada MD5 para contraseñas · Media

**Ubicación**: `include/class.client.php`,
`include/class.staff.php` (`check_passwd`).

**Evidencia (antes)**: tras fallar bcrypt se comparaba `MD5($password)` y se
rehasheaba sólo en ese caso. Un hash bcrypt con coste antiguo (8) nunca se
actualizaba.

**Corrección aplicada**:

- coste por defecto de bcrypt elevado de **8 a 10**;
- nuevo `Passwd::needsRehash()` y rehash transparente **en el inicio de
  sesión** tanto de hashes MD5 heredados como de bcrypt con coste inferior;
- comparación constante en tiempo (OST-005).

Las contraseñas existentes siguen funcionando; el coste se migra de forma
incremental sin intervención del usuario.

---

### OST-008 — Interpolación SQL en la búsqueda de plugins · Media

**Ubicación**: `include/class.plugin.php` (`getPluginByName`).

**Evidencia (antes)**

```php
$sql = sprintf('SELECT * FROM %s WHERE name="%s"', PLUGIN_TABLE, $name);
```

Hoy los llamadores pasan constantes, por lo que no es explotable, pero es un
patrón peligroso en una API pública de plugins.

**Corrección aplicada**: `db_input($name)`, que devuelve el valor escapado y
entrecomillado.

---

### OST-009 — Banner del servidor sin escapar · Baja

**Ubicación**: `include/staff/system.inc.php`.

`$_SERVER['SERVER_SOFTWARE']` se imprimía sin escapar en la página de
información del sistema. Aunque lo controla el servidor, se escapa por
coherencia (`Format::htmlchars`).

---

### OST-010 — Cookie de sesión inicial sin `SameSite` · Baja

**Ubicación**: `include/class.ostsession.php`.

**Evidencia (antes)**: `session_set_cookie_params($ttl, $path, $domain,
$secure, true)` no permite declarar `SameSite`; sólo la renovación posterior
(`renewCookie()`, con `Strict`) lo añadía.

**Corrección aplicada**: se usa la forma de array con `SameSite=Lax` en la
cookie inicial (la renovación mantiene `Strict`), respetando
`Secure`/`HttpOnly` y la configuración de iframes.

---

### OST-011 — PHP vulnerable a php2shell (`CVE-2026-109618` / `CVE-2026-109619`) · Crítica

**Fecha**: 2026-10-21 (divulgación y parche el mismo día).

**Ubicación**: no está en el código de osTicket, sino en el **intérprete PHP**
sobre el que se ejecuta. Afecta a cualquier despliegue con una versión sin el
parche del 21 de octubre.

**Evidencia (antes)**: el proyecto no comprobaba la versión de PHP en ningún
punto. Un servidor con PHP 8.4.26 se instalaba y funcionaba igual que uno
parcheado, sin ningún aviso.

**Riesgo**: RCE sin autenticación, CVSS v4.0 9.2. Explotación observada en
honeypots en las primeras horas. El vector principal es `multipart/form-data`
con agotamiento de memoria (CVE-2026-109618); en PHP 8.5 se suma el estado
obsoleto entre peticiones por *upload-progress* y *backtraces* fatales
(CVE-2026-109619). Detalle completo en [`SECURITY_PHP2SHELL.md`](SECURITY_PHP2SHELL.md).

**Corrección aplicada** (control continuo, no un parche único):

1. `include/class.security.php` — `SecurityPosture` con la tabla de versiones
   corregidas (8.2.35 / 8.3.36 / 8.4.27 / 8.5.12), `parseVersion()` tolerante a
   sufijos de distribución (`8.4.27-1ubuntu1`) y `isVulnerablePhp()`.
2. `tools/security/php-cve-check.php` — comprobación en CLI y CI con `exit 1`
   cuando la versión está afectada (`--json` para pipelines). Integrada en
   `make security` y `make ci`.
3. Docker — imagen basada en `PHP_VERSION=8.4.27` y entrypoint que **aborta el
   arranque** con `OST_FAIL_ON_VULNERABLE_PHP=1`.
4. Interfaz — *Admin → System Settings → Security Posture* y aviso en el panel
   cuando hay un hallazgo grave.

**Pendiente operativo**: en servidores que no usan la imagen del proyecto hay que
actualizar PHP a mano; el checklist de [`SECURITY_PHP2SHELL.md`](SECURITY_PHP2SHELL.md) §5
lo cubre.

---

### OST-012 — Configuración PHP que habilita rutas de php2shell · Media

**Ubicación**: configuración del intérprete (`php.ini` y pool de PHP-FPM).

**Evidencia (antes)**: `session.upload_progress.enabled` activo (valor por
defecto de PHP) y, en PHP 8.5, `fatal_error_backtraces` activado por defecto.
Ambos son rutas confirmadas de CVE-2026-109619.

**Corrección aplicada** (`docker/php/conf.d/00-osticket.ini` y pool generado por
el entrypoint):

```ini
session.upload_progress.enabled = Off
fatal_error_backtraces = Off        ; sólo existe en PHP 8.5
display_errors = Off
upload_max_filesize = 20M
post_max_size = 24M
max_multipart_body_parts = 200
```

Complementos:

* **Ningún ajuste por petición**: no se usan `php_value`/`php_flag` (Apache),
  `fastcgi_param PHP_VALUE` (nginx), `.user.ini` ni `ini_set()` para seguridad,
  porque se aplican demasiado tarde y una advertencia suya puede formar parte del
  desencadenante.
* `PHP2SHELL_STRICT=1` reduce los límites a 4M/6M/5 archivos en instalaciones
  que no reciben adjuntos grandes.
* `pm.max_requests` configurable; a `1` elimina el estado entre peticiones.
* IIS (`web.config`): `hiddenSegments` y `requestLimits` de 24 MB.

**Por qué no basta con ocultar errores**: `file_uploads=Off`, `display_errors=Off`
y `session.use_strict_mode` **no** mitigan el fallo. La tabla de medidas que no
sirven está en [`SECURITY_PHP2SHELL.md`](SECURITY_PHP2SHELL.md) §3.3.

---

## 4. Controles verificados que **no** requieren cambios

| Control | Resultado |
|---|---|
| Inyección SQL | Todas las consultas revisadas usan `db_input()`, parámetros o el ORM. Sin concatenaciones explotables. |
| CSRF | Validación global para métodos con estado en `client.inc.php` y `scp/staff.inc.php`; AJAX envía `X-CSRFToken`. |
| Contraseñas | bcrypt (phpass) con sal por usuario; coste elevado a 10; rehash automático. |
| Sesión | Regeneración de ID en el inicio de sesión; expiración y limpieza por usuario; `HttpOnly`/`Secure`/`SameSite`. |
| Subida de archivos | Validación de extensión y MIME, límite de tamaño, almacenamiento en base de datos (no ejecutable por el servidor web). |
| Descarga de archivos | Firma HMAC + caducidad (`verifySignature`), comprobación de acceso al ticket y a la sesión. |
| SSRF | Las peticiones salientes usan URLs configuradas por el administrador; sin parámetros de usuario. |
| Inclusión de archivos | Los `include` dinámicos se resuelven a rutas internas conocidas; sin rutas de usuario. |
| Escape en plantillas | Predomina `Format::htmlchars()`/`Format::display()`; los contenidos ricos pasan por htmLawed. |
| Serialización | `unserialize()` sólo sobre datos internos (caché de ORM, traducciones). Sin entrada directa de usuario. |
| Fijación de sesión | Mitigada por `session_regenerate_id()` en el login. |

---

## 5. Verificación de las correcciones

```bash
# 1. Sintaxis de todo el proyecto (519 archivos, excluye terceros)
tools/lint.sh

# 2. Análisis de seguridad (debe terminar con código 0)
php tools/audit/scan.php --severity=high --baseline=tools/audit/baseline.json

# 3. Informe completo
make audit-all
```

```bash
# 4. php2shell: versión del intérprete y directivas relacionadas
make security
php tools/security/php-cve-check.php --php-version=8.4.26   # debe fallar (exit 1)
php tools/security/php-cve-check.php --php-version=8.4.27   # debe pasar
```

Resultados obtenidos tras los cambios:

- `tools/lint.sh`: **0 errores** de sintaxis en 519 archivos.
- `tools/security/php-cve-check.php`: matriz de 12 casos verificada —
  8.2.34 ✗, 8.2.35 ✓, 8.3.35 ✗, 8.3.36 ✓, 8.4.26 ✗, 8.4.27 ✓, 8.5.11 ✗,
  8.5.12 ✓, 8.1.31 ✗ (fin de vida), 7.4.33 ✗, `8.4.27-1ubuntu1` ✓ y
  `OST_PHP2SHELL_BACKPORT=1` marcando como parcheada una rama EOL.
- `tools/audit/scan.php`: **13 hallazgos residuales** (1 alto, 2 medios de
  contraseña heredada, 5 de deserialización interna, 5 informativos) y
  **0 hallazgos altos nuevos** frente a la línea base.
- Compatibilidad con PHP 8.5: se corrigieron las deprecaciones que emitía el
  motor al compilar (`(integer)`/`(double)`, `case ...;`, operador de
  retroceso) en `include/JSON.php`, `include/class.misc.php`,
  `include/class.orm.php`, `include/class.forms.php`, `scp/faq.php`,
  `include/cli/modules/*` e `include/staff/system.inc.php`. El barrido
  completo con PHP 8.5 no reporta avisos.
- Pruebas de los helpers nuevos (ejecutadas con PHP 8.5):
  - `Http::host()` — 8/8 casos, incluidos intentos de inyección de
    cabeceras y de HTML.
  - `Http::csp()` — salida válida en portal, panel y login.
  - `Passwd::needsRehash()`/`hash()`/`cmp()` — 4/4 casos; hash generado con
    coste 10 y verificación correcta/incorrecta.

Estas comprobaciones son reproducibles en cualquier máquina o contenedor con
`make ci` (no se ejecutan en un servicio de CI alojado).

---

## 6. Recomendaciones abiertas

### OST-101 — Modo estricto de sesión inefectivo · Baja

`include/class.session.php` implementa `validateId()` devolviendo siempre
`true`, por lo que activar `session.use_strict_mode` no aporta protección.

**Recomendación**: implementar `validateId()` consultando la existencia del
registro en el backend (`lookupRecord($id, false)`), activar
`session.use_strict_mode=1` en `Bootstrap::init()` y validar en un entorno
de staging los flujos de API (`API_SESSION`), SSO y `renewCookie()`.
El riesgo actual es bajo porque el ID se regenera al iniciar sesión.

### OST-102 — CSP con `unsafe-inline` / `unsafe-eval` · Media

La CSP mitiga XSS sólo parcialmente. La deuda viene de scripts y estilos
inline en plantillas.

**Recomendación** (por fases):
1. Mover los `onclick=`/`<script>` inline a `js/` y usar `addEventListener`.
2. Emitir un *nonce* por petición (`Http::csp()` ya acepta directivas).
3. Eliminar `unsafe-eval` del panel sustituyendo los widgets que usan `eval`.
4. Medir con `Content-Security-Policy-Report-Only` antes de aplicar.

**Estado: en curso.** `js/actions.js` implementa las acciones declarativas
que sustituyen a los `onclick` más repetidos (`data-href`, `data-go-back`,
`data-submit-on-change`, `data-ost-dialog`, `data-confirm`) y las plantillas se
van migrando en tandas. `tools/ui/inline-handlers.php` (`make inline-audit`)
cuenta lo que queda —**29** al escribir esto, desde 124— y falla con
`--max=N` si el número vuelve a subir. Falta el paso 2 (nonce) y los
manejadores con lógica propia (editores, ordenación de columnas, combos de
la búsqueda avanzada).

### OST-103 — Sin límite de intentos a nivel de infraestructura · Media

La aplicación cuenta *strikes* por usuario (`class.auth.php`) y rota el
token CSRF en cada intento, pero un ataque distribuido con muchos usuarios
no se frena.

**Recomendación**: limitar en el balanceador/WAF (`/scp/login.php`,
`/login.php`, `/api/`, `/pwreset.php`) p. ej. 10 intentos/minuto por IP y
100/hora por ASN, con bloqueo progresivo. Considerar `fail2ban` sobre el log
de osTicket.

### OST-104 — Directorio `setup/` tras la instalación · Media

`scp/admin.inc.php` ya avisa, pero el directorio sigue accesible y contiene
herramientas de instalación/actualización.

**Recomendación**: eliminar `setup/` (o bloquearlo en el servidor web) en
producción como parte del despliegue. Incluido en el checklist (§7).

### OST-105 — Hashes MD5 heredados en base de datos · Media

Sólo desaparecen cuando el usuario inicia sesión (ahora con rehash
automático). Un volcado de base de datos filtrado permitiría crackearlos.

**Recomendación**: forzar el cambio de contraseña a los usuarios cuyo hash
no empiece por `$2` o `$P$` (consulta de auditoría incluida en §7).

### OST-106 — Ciclo de vida de dependencias · Media

`mpdf`, `laminas-mail`, `pear`, `htmLawed` y `Spyc` están embebidos y sus
versiones no se rastrean en el repositorio.

**Recomendación**: inventariar versiones exactas (hecho: ver
[`DEPENDENCIES.md`](DEPENDENCIES.md)), suscribirse a los avisos de seguridad de cada
proyecto y automatizar la comprobación en CI (por ejemplo con
`composer audit` sobre un `composer.lock` de referencia o un script que lea
los `composer.lock` embebidos).

---

## 7. Checklist de endurecimiento del despliegue

### Aplicación

- [ ] `include/ost-config.php` con permisos `640` y propietario del usuario
      del servidor web.
- [ ] `SECRET_SALT` aleatorio de 32+ caracteres definido manualmente.
- [ ] `setup/` eliminado o bloqueado tras instalar/actualizar.
- [ ] Panel de administración restringido por IP o VPN (`scp/admin.php`).
- [ ] HTTPS forzado (`force_https`) y HSTS activo.
- [ ] 2FA obligatorio para agentes (`force_2fa`).
- [ ] Buzón de correo de entrada con contraseña dedicada y sin reutilizar.
- [ ] Copias de seguridad cifradas y probadas (base de datos + adjuntos).

### PHP (`php.ini`)

- [ ] Versión **con el parche de php2shell** (≥ 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12).
      Ver [`SECURITY_PHP2SHELL.md`](SECURITY_PHP2SHELL.md).
- [ ] `php tools/security/php-cve-check.php` termina con código 0.
- [ ] Sin `php_value`/`php_flag`/`PHP_VALUE`/`.user.ini` que alteren la seguridad.

```ini
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php/osticket-error.log
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
; PHP >= 8.4: session.sid_length y session.sid_bits_per_character están
; obsoletas; el id de sesión es siempre de 32 caracteres hexadecimales.
allow_url_include = Off
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_multi_exec,parse_ini_file,show_source
open_basedir = /var/www/osticket:/tmp
upload_max_filesize = 20M
post_max_size = 24M
max_execution_time = 60
; php2shell: rutas de CVE-2026-109619
session.upload_progress.enabled = Off
fatal_error_backtraces = Off        ; sólo PHP 8.5
```

> Ajusta `disable_functions` y `open_basedir` según tus plugins (algunos
> requieren `exec` para conversiones o `curl`).

### Apache

```apache
ServerTokens Prod
ServerSignature Off
TraceEnable Off

<Directory /var/www/osticket>
    Options -Indexes -MultiViews
    AllowOverride All
    Require all granted
</Directory>

# Bloquear rutas internas por defensa en profundidad
RedirectMatch 404 ^/include/.*$
RedirectMatch 404 ^/(setup|\.git|tools)/.*$
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

### Nginx

```nginx
server_tokens off;

location ~ ^/(include|setup|tools|\.git)/ { deny all; return 404; }
location ~ /\.(?!well-known) { deny all; }

add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

client_max_body_size 24m;
```

### Consultas de auditoría útiles

```sql
-- Cuentas con hashes heredados (MD5) que deben resetear contraseña
SELECT id, username FROM ost_staff
 WHERE passwd NOT LIKE '$2%' AND passwd NOT LIKE '$P$%';
SELECT id, username FROM ost_user_account
 WHERE passwd NOT LIKE '$2%' AND passwd NOT LIKE '$P$%';

-- Sesiones activas inusualmente largas
SELECT session_id, user_id, lastseen FROM ost_session
 WHERE lastseen < NOW() - INTERVAL 30 DAY;

-- Registro de intentos fallidos (nivel aplicación)
SELECT * FROM ost_syslog WHERE title LIKE 'Failed%login%' ORDER BY created DESC LIMIT 50;
```

---

## 8. Mantenimiento del análisis

El analizador es parte del repositorio y no tiene dependencias:

```bash
make audit          # falla si aparece un hallazgo alto nuevo
make audit-all      # informe completo
make baseline       # acepta los hallazgos actuales (tras revisarlos)
make ci             # lint + auditoría + versión de PHP + tema
```

Reglas implementadas:

| Regla | Qué detecta |
|---|---|
| `OST-XSS-ECHO` | `echo`/`print` de datos de la petición sin escapar |
| `OST-XSS-ATTR` | datos de la petición dentro de atributos HTML |
| `OST-SQLI` | SQL construido concatenando datos de la petición |
| `OST-EVAL` | `eval()` y equivalentes |
| `OST-RCE` | `system`, `exec`, `shell_exec`, `passthru`, `popen` |
| `OST-LFI` | `include`/`require` con ruta variable |
| `OST-OBJ-INJECT` | `unserialize()` |
| `OST-WEAK-RANDOM` | `rand`/`mt_rand`/`uniqid` para tokens |
| `OST-WEAK-HASH` | `md5`/`sha1` en contraseñas |
| `OST-WEAK-SECRET` | `SECRET_SALT` derivado de datos públicos |
| `OST-WEAK-COMPARE` | `==` sobre contraseñas/OTP |
| `OST-HARDCODED-SECRET` | credenciales en el código |
| `OST-TLS-VERIFY` | verificación TLS desactivada |
| `OST-INSECURE-COOKIE` | cookies sin `HttpOnly`/`Secure` |
| `OST-OPEN-REDIRECT` | redirección a parámetros de la petición |
| `OST-DEBUG-DUMP` | `var_dump`/`print_r` olvidados |
| `OST-DISPLAY-ERRORS` | `display_errors` activado |

Los casos revisados y aceptados se anotan en la línea anterior con:

```php
// audit:ignore OST-RCE -- comando fijo, sólo CLI
```

Esto mantiene el informe limpio sin silenciar patrones nuevos.

---

## 9. Anexo: patrones seguros de referencia

```php
// Salida HTML
echo Format::htmlchars($valor);          // texto
echo Format::display($htmlSaneado);      // HTML ya saneado (htmLawed)

// SQL
$sql = 'SELECT * FROM '.USER_TABLE.' WHERE id='.db_input($id);
// o mejor, con el ORM
User::objects()->filter(['id' => $id])->one();

// Redirecciones
Http::redirect(ROOT_PATH.'tickets.php?id='.(int) $id);

// Cabeceras y URLs
$host = Http::host();                    // nunca $_SERVER['HTTP_HOST']
$url  = ROOT_PATH.'file.php?key='.urlencode($key);

// Comparaciones sensibles
if (hash_equals($esperado, $recibido)) { /* ... */ }

// Aleatoriedad
$token = bin2hex(Crypto::random(32));    // nunca rand()/uniqid()

// Archivos
if (!in_array($ext, $permitidas, true)) Http::response(415, __('No permitido'));
```

---

*Documento generado como parte de la auditoría. Mantener actualizado el
estado de las recomendaciones abiertas en cada revisión.*
