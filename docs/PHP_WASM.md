# PHP sin instalarlo: el arnés php-wasm

Este documento describe cómo se ejecutan las pruebas, el lint y las
herramientas de calidad **sin PHP en el equipo** y **sin Docker**, con
[`php-wasm`](https://github.com/seanmorris/php-wasm) (PHP compilado a
WebAssembly y ejecutado por Node).

Es la tercera vía, para cuando no hay contenedor levantado:

| Vía | Cuándo | Cómo |
|---|---|---|
| Docker (recomendada) | Equipo de desarrollo normal | `make docker-test`, `make ci` |
| PHP local | Hay PHP 8.x en el `PATH` | `make test`, `make ci` |
| **php-wasm** | Sin PHP y sin Docker, o para un visto bueno rápido | el arnés de este documento |

Lo que se ejecuta en php-wasm es el **mismo código** del repositorio, con el
SAPI `embed` en vez de `cli`. Eso obliga a tres ajustes que el arnés hace
solo:

1. **Guardianes de línea de comandos.** Las herramientas empiezan con
   `if (PHP_SAPI !== 'cli') { … exit(2); }`. La copia que se ejecuta les quita
   ese bloque (nunca el archivo del repositorio).
2. **`STDERR`/`STDOUT`.** En `embed` no existen como constantes: se definen
   antes, apuntando a `php://stderr` y `php://stdout`.
3. **`$_SERVER`.** `SCRIPT_FILENAME` y `PHP_SELF` se rellenan para que las
   herramientas que solo actúan como programa principal (`sql-audit`,
   `input-audit`, `class-map`) hagan su trabajo en vez de quedarse calladas.

## Puesta en marcha

```bash
mkdir -p ~/.w2 && cd ~/.w2
npm install php-wasm            # una sola vez; no toca el repositorio
```

El arnés son cuatro archivos pequeños (ver «El arnés»). Se guardan **fuera**
del repositorio a propósito: el proyecto no depende de Node, y el arnés es una
herramienta de la máquina de quien lo usa.

## Uso

```bash
node run.mjs tests/run.php              # toda la suite unitaria
node run.mjs tests/run.php Plugin       # solo las clases cuyo nombre contenga «Plugin»
node run.mjs tools/ui/theme-check.php   # las 195 comprobaciones del tema
node run.mjs tools/quality/sql-audit.php
node run.mjs tools/quality/input-audit.php
node run.mjs tools/quality/class-map.php
node run.mjs tools/quality/metrics.php
node run.mjs tools/scaffold.php plugin demo --title="Demo"
node lint.mjs include/class.plugin.php  # sintaxis (equivale a `php -l`)
```

`run.mjs` copia el repositorio a un sistema de archivos en memoria (deja fuera
binarios, `.git` y cualquier archivo de más de 4 MB), le quita a la copia los
guardianes de CLI y ejecuta el script. **El repositorio no se modifica**: lo
que un generador escriba se queda en la copia y desaparece al terminar, salvo
que se pida lo contrario.

## Qué no se puede hacer aquí

- **Base de datos.** No hay MySQL: `make unit` (sin `--integration`) funciona;
  `make docker-integration` no.
- **Extensiones ausentes** (`dom`, `mbstring`, `intl`, `mysqli`): las pruebas
  que dependen de ellas se **omiten** y se informan como tales. El número de
  omitidas en php-wasm es mayor que en el contenedor; no es un fallo.
- **Lint del proyecto entero con `php -l`**: `lint.mjs` compila con
  `token_get_all(…, TOKEN_PARSE)`, que detecta errores de sintaxis pero no los
  avisos de compilación del binario.
- **Rendimiento.** php-wasm es entre 5 y 20 veces más lento. Sirve para saber
  si algo pasa o no, no para medir tiempos.

## El arnés

| Archivo | Qué hace |
|---|---|
| `run.mjs` | Copia el repo a MEMFS y ejecuta un script con `PhpNode` |
| `lint.mjs` | Compila uno o varios archivos y dice si hay `ParseError` |
| `verify.mjs` | Variante usada para mirar qué generó `scaffold.php` (opcional) |
| — | `npm install php-wasm` es la única dependencia |

Un `run.mjs` mínimo, por si hay que rehacerlo:

```js
import { PhpNode } from 'php-wasm/PhpNode';
const php = new PhpNode();
php.addEventListener('output', (ev) => process.stdout.write(ev.detail?.[0] ?? ''));
const mod = await php.binary;                    // FS en memoria
mod.FS.writeFile('/repo/x.php', '<?php echo "hola\n";');
const status = await php.run("<?php require '/repo/x.php';");
process.exit(Number(status) || 0);
```

`mod.FS` (no `mod.NODEFS`) es el sistema de archivos en memoria: no hay montaje
del disco del equipo, de ahí la copia inicial.

## Cuándo NO usar el arnés

- Antes de dar por bueno un cambio de interfaz: hace falta un navegador
  (`make browser-audit`, `make dark-audit`).
- Antes de tocar el correo o los destinatarios: hace falta el buzón y Mailpit
  (`docs/MAIL.md`).
- Para el veredicto final de una tanda: `make docker-test`, que es el entorno
  de referencia (PHP 8.4 con `dom`).
