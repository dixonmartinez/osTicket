# Inventario de dependencias embebidas

osTicket distribuye sus librerías dentro de `include/` (no usa Composer en
tiempo de ejecución). Este documento centraliza **qué versión hay instalada**
para poder evaluar avisos de seguridad (ver OST-106 en
[`SECURITY_AUDIT.md`](SECURITY_AUDIT.md)).

Última revisión: 2026-10-30.

**Esta tabla ya no se mantiene a mano.** `make operations` (o
`php tools/security/operations-check.php --json`) genera el inventario leyendo
lo que hay: los `composer.lock` empaquetados, la constante `VERSION` de mPDF,
la cabecera de htmLawed y phpass, y el `$Id:` de PEAR Mail, que no declara
versión. Del lock de cada librería sólo cuentan los paquetes que existen de
verdad en su `vendor/`: el lock de laminas-mail lista también psalm, phpunit y
los estándares de código que usa su desarrollo y que **no** se despliegan.
Hoy salen 27 entradas: 19 del `vendor/` de mPDF y laminas-mail, htmLawed y
phpass con su versión, las tres de `composer.json` y las que no declaran
versión (`laminas/laminas-mail` «no fijada», `spyc`).

## Dependencia número uno: el intérprete PHP

En este proyecto la dependencia con más impacto de seguridad **no** es una
librería embebida, sino la versión de PHP. El 21 de octubre de 2026 se publicó
*php2shell* (CVE-2026-109618 / CVE-2026-109619, CVSS 9.2, RCE sin
autenticación), que afecta a todo intérprete sin el parche de ese día.

| Rama | Versión mínima segura | Notas |
|---|---|---|
| 8.2 | 8.2.35 | Mantenida |
| 8.3 | 8.3.36 | Mantenida |
| 8.4 | **8.4.27** | Recomendada; es la que usa la imagen Docker |
| 8.5 | 8.5.12 | Mantenida |
| ≤ 8.1 | — | Fin de vida, sin parche |

La comprobación está automatizada:

```bash
make security                                  # versión en ejecución
php tools/security/php-cve-check.php --json    # para CI
docker compose exec php php tools/security/php-cve-check.php
```

`make ci` incluye esta comprobación, y el contenedor **no arranca** con una
versión afectada si `OST_FAIL_ON_VULNERABLE_PHP=1` (valor por defecto).
Detalle y mitigaciones: [`SECURITY_PHP2SHELL.md`](SECURITY_PHP2SHELL.md).

## Librerías de terceros incluidas

| Librería | Ruta | Versión | Origen de la verdad |
|---|---|---|---|
| mPDF | `include/mpdf/` | 8.2.7 | `include/mpdf/composer.lock` |
| laminas-mail | `include/laminas-mail/` | **no fijada** ⚠️ | el lock sólo lista dependencias |
| laminas-loader | `include/laminas-mail/vendor/` | 2.10.0 | lock |
| laminas-mime | ídem | 2.12.0 | lock |
| laminas-servicemanager | ídem | 3.22.1 | lock |
| laminas-stdlib | ídem | 3.19.0 | lock |
| laminas-validator | ídem | 2.55.0 | lock |
| setasign/fpdi | `include/mpdf/vendor/` | 2.6.4 | lock |
| myclabs/deep-copy | ídem | 1.13.4 | lock |
| psr/log | ídem | 3.0.2 | lock |
| psr/container | `include/laminas-mail/vendor/` | 1.1.2 | lock |
| psr/http-message | ídem | 2.0 | lock |
| symfony/polyfill-* | ídem | v1.29.0 | lock |
| webmozart/assert | ídem | 1.11.0 | lock |
| htmLawed | `include/htmLawed.php` | sin versión en cabecera | — |
| phpass | `include/PasswordHash.php` | 0.3 (modificado) | comentario del archivo |
| Spyc (YAML) | `include/Spyc.php` | sin versión | — |
| PEAR (Mail, Mail_Mime, Net_Socket…) | `include/pear/` | sin versión | — |
| FPDF | `include/fpdf/` | sin versión | — |
| jQuery | `js/jquery-3.7.0.min.js` | 3.7.0 | nombre del archivo |
| jQuery UI | `js/jquery-ui-1.13.2.custom.min.js` | 1.13.2 | nombre del archivo |
| Redactor | `js/redactor.min.js` | sin versión | — |
| Select2 | `js/select2.min.js` | sin versión | — |
| Fabric.js | `js/fabric.min.js` | sin versión | — |
| Font Awesome | `css/font-awesome.min.css` | 3.x (IE7 incluido) | — |

## Riesgos detectados

1. **laminas-mail no declara su propia versión.** El `composer.lock` fija sus
   dependencias pero no el paquete raíz, por lo que no se puede correlacionar
   con avisos de seguridad sin inspeccionar el código.
2. **Librerías sin versión** (`htmLawed`, `Spyc`, `PEAR`, `FPDF`, Redactor,
   Select2, Fabric): imposible automatizar la comparación con CVEs.
3. **Font Awesome 3.x** incluye soporte para IE7 y está obsoleto; sustituirlo
   por SVG embebidos elimina también una petición y mejora la accesibilidad.

## Proceso recomendado

1. Al actualizar una librería embebida **o la versión de PHP**, actualizar
   **este documento** en el mismo commit. Para PHP basta con cambiar
   `PHP_VERSION` en `.env` y la tabla de `include/class.security.php` cuando se
   publique una rama nueva.
2. Añadir `"version"` al `composer.json` de `include/laminas-mail/` antes de
   empaquetar; así el lock y el paquete quedan correlacionados.
3. Ejecutar en CI una comprobación de avisos:

   ```bash
   # Ejemplo con un lock de referencia (no se instala nada en producción)
   composer audit --locked --no-dev
   ```

   o, para las librerías sin metadatos, una tarea programada mensual que
   consulte las bases de datos de CVEs (NVD, GitHub Advisory) por nombre.
4. Tras cada actualización: `make ci` y prueba manual de correo entrante,
   generación de PDF y exportaciones (las tres áreas que dependen de estas
   librerías).

## Librerías que ya no se usan (candidatas a eliminar)

| Ruta | Motivo |
|---|---|
| `include/mpdf/vendor/paragonie/random_compat` | Polyfill de PHP 5/7; innecesario en PHP 8.2+ |
| `css/font-awesome-ie7.min.css` | Soporte de Internet Explorer 7 |
| `js/jquery.pjax.js` | Envoltorio antiguo; revisar si sustituible por navegación normal |

Eliminar código muerto reduce la superficie de ataque y el peso de la
distribución, pero requiere una prueba funcional del panel completo.
