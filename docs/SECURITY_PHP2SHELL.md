# php2shell — vulnerabilidad crítica de PHP (2026-10-21)

Guía de respuesta para osTicket. Todo lo que hay que hacer, en orden, y lo que
ya está hecho en este repositorio.

---

## 1. Qué pasó

El **21 de octubre de 2026** se publicaron dos vulnerabilidades del intérprete
PHP, divulgadas conjuntamente como **php2shell**:

| CVE | Descripción | Vector |
|---|---|---|
| **CVE-2026-109618** | Corrupción de memoria por agotamiento de recursos al procesar cuerpos `multipart/form-data` | Ejecución remota de código **sin autenticación**; el proceso muere por OOM a mitad de una estructura de datos y queda memoria reutilizable |
| **CVE-2026-109619** | Estado obsoleto entre peticiones (upload-progress y *backtraces* de errores fatales) | Parte del mismo conjunto de exploits; afecta a PHP 8.5 por los *backtraces* activados por defecto |

* **Severidad:** CVSS v4.0 **9.2** (crítica).
* **Explotación:** confirmada en honeypots (watchTowr) y tráfico malicioso
  observado por Cloudflare en las primeras horas. CISA emitió aviso urgente.
* **Alcance:** es un fallo del **intérprete**, no de osTicket. Cualquier
  aplicación PHP servida por `mod_php`, PHP-FPM, CGI, etc. es vulnerable si la
  versión no está parcheada.

> Conclusión práctica: **osTicket no “tiene” la vulnerabilidad en su código**;
> la tiene el PHP sobre el que corre. Por eso todo el trabajo va orientado a (a)
> garantizar una versión parcheada y (b) reducir la superficie de ataque por si
> el parche tarda en llegar a algún servidor.

## 2. Versiones corregidas

| Rama | Versión parcheada | Estado de la rama |
|---|---|---|
| 8.2 | **8.2.35** | Mantenida |
| 8.3 | **8.3.36** | Mantenida |
| 8.4 | **8.4.27** | Mantenida — **versión por defecto de este proyecto** |
| 8.5 | **8.5.12** | Mantenida |
| ≤ 8.1 | sin parche | Fin de vida |

El parche se publicó hacia las **13:35 UTC** del 21 de octubre de 2026. Si tu
distribución empaqueta PHP (Debian/Ubuntu/RHEL/Alpine), comprueba el número de
versión **con el sufijo de revisión**: `8.4.27-1ubuntu1` está parcheado.

## 3. Qué hace este repositorio (ya implementado)

### 3.1 Detección automática

* **`include/class.security.php`** — clase `SecurityPosture` con la tabla de
  versiones corregidas. Sin dependencias de osTicket: se puede usar desde CLI,
  CI o Docker.
  * `parseVersion()` normaliza cadenas como `8.4.27-1ubuntu1` o `8.4.27RC1`.
  * `isVulnerablePhp()` compara contra la tabla.
  * `getIssues()` devuelve también los hallazgos de configuración
    (`session.upload_progress.enabled`, `fatal_error_backtraces`, `display_errors`,
    `SECRET_SALT` predecible, ramas EOL).
  * La variable `OST_PHP2SHELL_BACKPORT=1` marca como parcheada una rama EOL de
    la que la distribución haya publicado un *backport*.
* **`tools/security/php-cve-check.php`** — comprobación para CLI y CI:

  ```bash
  php tools/security/php-cve-check.php                 # usa la versión en ejecución
  php tools/security/php-cve-check.php --php-version=8.4.26
  php tools/security/php-cve-check.php --json          # para integrar en pipelines
  php tools/security/php-cve-check.php --quiet         # sólo falla (exit 1)
  ```

  Devuelve **exit 1** si la versión está afectada, de modo que
  `make security` y `make ci` fallan en un servidor vulnerable.
* **Interfaz** — *Admin → System Settings → **Security Posture*** muestra el
  estado del intérprete y de las directivas, con enlace a las versiones
  recomendadas. Si hay un hallazgo grave, el panel muestra un aviso.

### 3.2 Defensa en profundidad (por si el parche no llega)

* **`docker/php/conf.d/00-osticket.ini`**:
  * `session.upload_progress.enabled = Off` — cierra una de las rutas
    confirmadas de CVE-2026-109619.
  * `fatal_error_backtraces = Off` — desactiva la ruta del exploit en PHP 8.5.
  * `display_errors = Off` — aunque ocultar errores **no** mitiga el fallo, evita
    filtrar rutas y trazas.
  * Límites de subida contenidos: `upload_max_filesize=20M`, `post_max_size=24M`,
    `max_multipart_body_parts=200`, `max_file_uploads=10`.
* **Ningún ajuste de PHP por petición.** El aviso de la divulgación es explícito:
  `php_value`/`php_flag` en Apache, `fastcgi_param PHP_VALUE` en nginx,
  `.user.ini` e `ini_set()` se aplican demasiado tarde y una advertencia suya
  puede formar parte del desencadenante. Todo está en `php.ini` o en el pool de
  FPM (`php_admin_value`), nunca por petición.
* **Pool de PHP-FPM** generado por el entrypoint con
  `php_admin_value[memory_limit]=256M` y `pm.max_requests` configurable
  (a 1 elimina por completo el estado entre peticiones, a costa de rendimiento).
* **`OST_FAIL_ON_VULNERABLE_PHP=1`** (por defecto en Docker): el contenedor
  **aborta el arranque** si el intérprete está afectado. Es preferible un
  contenedor caído a un servicio explotable.
* **`PHP2SHELL_STRICT=1`** (opcional): baja los límites a
  `upload_max_filesize=4M`, `post_max_size=6M` y `max_file_uploads=5`. Útil en
  instalaciones que no reciben adjuntos grandes.
* **IIS** (`web.config`): `hiddenSegments` para `.git`, `tools`, `docs`, `docker`
  y `requestLimits` de 24 MB.
* **nginx** (`docker/nginx/default.conf`): bloqueo de rutas internas y de
  archivos de configuración; cabeceras `nosniff` y `Referrer-Policy`.

### 3.3 Lo que **no** sirve (probado y descartado)

Estas medidas circulan en foros pero **no** mitigan php2shell. No las apliques
creyendo que protegen:

| Medida | Por qué no sirve |
|---|---|
| `file_uploads = Off` | El fallo se dispara al **parsear** el cuerpo, antes de decidir si se guarda el archivo |
| Ocultar errores (`display_errors=Off`) | El exploit no depende de la salida de errores |
| `session.use_strict_mode = On` | No interviene en el camino vulnerable |
| Desactivar `session.upload_progress` **solo** | Cierra una ruta (109619) pero no la corrupción de memoria (109618) |
| Un WAF genérico de reglas | El tráfico puede ser `application/x-www-form-urlencoded`, que también se explota |
| `memory_limit = -1` sin cgroups | Empeora el problema: sin límite duro el proceso no muere limpiamente |

## 4. Qué hacer en un servidor que **no** usa este Docker

### 4.1 Lo primero: actualizar PHP

```bash
# Debian/Ubuntu
sudo apt update && sudo apt install --only-upgrade php8.4-fpm php8.4-cli
php -v      # debe ser >= 8.4.27 (o 8.2.35 / 8.3.36 / 8.5.12)

# RHEL/Alma/Rocky (Remi)
sudo dnf upgrade --enablerepo=remi-php84 'php*'

# Alpine
sudo apk upgrade php84
```

Reinicia **PHP-FPM y el servidor web** después (no basta con recargar la
configuración del sitio: el intérprete vive en el proceso).

Verifica con:

```bash
php tools/security/php-cve-check.php
```

### 4.2 Si no puedes actualizar todavía: mitigaciones temporales

Ordenadas por eficacia real y por impacto en funcionalidad:

1. **Pasar de `mod_php` a PHP-FPM.** En la divulgación se indica que `mod_php`
   es el escenario de mayor riesgo y que el vector
   `application/x-www-form-urlencoded` **no** se reproduce en FPM. Es el cambio
   con mejor relación beneficio/riesgo.
2. **Desactivar el progreso de subida y los backtraces** en `php.ini`:

   ```ini
   session.upload_progress.enabled = Off
   ; sólo PHP 8.5
   fatal_error_backtraces = Off
   ```

3. **Bloquear `multipart/form-data` en el servidor web**, salvo en los endpoints
   de subida de osTicket. Como osTicket usa adjuntos, no se puede bloquear en
   todo el sitio sin perder funcionalidad; hay que hacerlo por ruta.

   **nginx** (Map + `if` en el server block):

   ```nginx
   map $http_content_type $php2shell_block {
       default                              0;
       "~*^multipart/form-data"             1;
   }

   server {
       # Endpoints que sí necesitan adjuntos
       location ~ ^/(ajax\.php|open\.php|tickets\.php|api/|scp/ajax\.php) {
           set $php2shell_block 0;
           # ... configuración FastCGI normal ...
       }

       location ~ \.php$ {
           if ($php2shell_block) { return 413; }
           # ...
       }
   }
   ```

   **Apache 2.4** (`.htaccess` o configuración del sitio):

   ```apache
   <If "%{REQUEST_URI} !~ m#^/(ajax\.php|open\.php|tickets\.php|api/|scp/ajax\.php)#">
       <If "%{HTTP:Content-Type} =~ m#multipart/form-data#i">
           Require all denied
       </If>
   </If>
   ```

   **Caddy**:

   ```caddyfile
   @multipart {
       header Content-Type *multipart/form-data*
       not path /ajax.php /open.php /tickets.php /api/* /scp/ajax.php
   }
   respond @multipart 413
   ```

   **IIS** (URL Rewrite):

   ```xml
   <rule name="Block multipart" stopProcessing="true">
     <match url=".*" />
     <conditions>
       <add input="{HTTP_CONTENT_TYPE}" pattern="^multipart/form-data" />
       <add input="{URL}" pattern="^/(ajax\.php|open\.php|tickets\.php|api/|scp/ajax\.php)"
            negate="true" />
     </conditions>
     <action type="CustomResponse" statusCode="413" statusReason="Unsupported Media Type" />
   </rule>
   ```

   > Si tu instalación **no recibe adjuntos**, elimina las excepciones y bloquea
   > multipart en todo el sitio: es la configuración más segura.
4. **`memory_limit` explícito** con un valor finito (este proyecto usa 256 MB) y,
   si usas contenedores, límite de memoria del contenedor (`mem_limit` /
   `deploy.resources.limits.memory`). El fallo explota al agotar memoria: un
   límite bajo y estricto reduce la ventana.
5. **`pm.max_requests = 1`** en el pool de FPM: ningún proceso atiende dos
   peticiones, así que no puede arrastrar estado. Reduce el rendimiento entre un
   10 % y un 30 %; úsalo sólo mientras no puedas parchear.

### 4.3 Después de parchear

* **Restaura `memory_limit`** a su valor normal si lo bajaste: el parche añade un
  límite duro superior y un `memory_limit` demasiado bajo provocará errores
  fatales en tickets con adjuntos grandes.
* Revisa los logs del servidor desde el 21 de octubre buscando caídas de procesos
  PHP (`segfault`, `OOM`, workers que reinician solos).
* Rota la `SECRET_SALT` y las credenciales de base de datos **sólo si** hay
  indicios de compromiso: si el exploit tuvo éxito, la rotación es obligatoria.
* Comprueba integridad del código: `git status` en el repositorio desplegado y
  búsqueda de archivos nuevos en la raíz (`find . -maxdepth 1 -name '*.php' -newer
  include/ost-config.php`).
* Revisa los usuarios creados recientemente en osTicket (*Admin → Agents*) y las
  claves de API nuevas.

## 5. Lista de verificación

- [ ] `php -v` reporta una versión de la tabla del §2 (con su sufijo de revisión).
- [ ] `php tools/security/php-cve-check.php` termina con código 0.
- [ ] En Docker, `docker compose exec php php tools/security/php-cve-check.php`
      está en verde y el entrypoint **no** abortó.
- [ ] *Admin → System Settings → Security Posture* no muestra hallazgos críticos.
- [ ] `session.upload_progress.enabled = Off` y, en 8.5, `fatal_error_backtraces = Off`.
- [ ] No hay `php_value` / `php_flag` / `PHP_VALUE` / `.user.ini` en la
      configuración del servidor web.
- [ ] Si no se pudo parchear: FPM (no `mod_php`), multipart bloqueado fuera de los
      endpoints de subida, `memory_limit` finito, `pm.max_requests=1`.
- [ ] Los logs del 21 de octubre en adelante están revisados.
- [ ] `make ci` pasa (lint + auditoría + comprobación de PHP).

## 6. Referencias

* Aviso y mitigaciones tempranas: `https://ma.ttias.be/php-cve-2026-109618-early-mitigation/`
* GHSA-w8hw-w9fx-j79w (CVE-2026-109618) · GHSA-6wmf-4rv5-p3rp (CVE-2026-109619)
* Aviso de CISA y de los proveedores de PHP de cada distribución.
* Registro interno de hallazgos: [`SECURITY_AUDIT.md`](SECURITY_AUDIT.md) (OST-011, OST-012).
