# Calidad del código: radiografía y plan

osTicket no es "espagueti" en el sentido clásico: tiene un ORM propio, clases
por dominio y una separación razonable entre `include/` (lógica), `scp/`
(páginas del panel) y `include/staff/` (plantillas). Lo que arrastra son
**cuatro problemas estructurales** que se notan al tocarlo: clases que crecen
sin límite, SQL escrito a mano al lado del ORM, JavaScript embebido en las
plantillas y ausencia total de pruebas automáticas.

Este documento mide esos problemas y propone un orden para atacarlos. No es
una lista de deseos: cada frente tiene un criterio de éxito medible.

---

## 1. Cómo se mide

```bash
make metrics          # o: php tools/quality/metrics.php
make metrics-json     # para comparar dos momentos
```

`tools/quality/metrics.php` recorre sólo el código propio (excluye `vendor/`,
`mpdf`, `pear`, `laminas-mail`) y cuenta tamaño, funciones largas y las
señales de deuda de la tabla siguiente. Es una herramienta de medida, no un
test: nunca falla, sólo informa.

**Úsala antes y después de cada refactor.** Si un cambio sube
`accesos directos a $_POST` o el tamaño medio de función, se ha ido en la
dirección contraria.

---

## 2. Radiografía (medida el 2026-10-22)

| Métrica | Valor |
|---|---|
| Archivos PHP propios | 497 |
| Líneas | 131.728 |
| Funciones | 5.987 |
| Tamaño medio de función | 14,7 líneas |
| Archivo más grande | `include/class.forms.php` — 6.229 líneas |
| Función más larga | `Queue::getCriteria()` — 1.414 líneas |

| Señal | Ocurrencias |
|---|---|
| SQL a mano (`db_query`) | 183 |
| `db_input()` (escape manual) | 163 |
| Accesos directos a `$_POST`/`$_REQUEST`/`$_GET` | 1.269 |
| `global $...` | 560 |
| `eval()` | 13 |
| `onclick=`/`onchange=` en HTML | 127 |
| `<script>` embebido en plantillas | 166 |

La media de 14,7 líneas por función es **buena**. El problema no es el estilo
general, son los valores atípicos: hay 5 archivos por encima de 2.000 líneas y
unas pocas funciones de 500 a 1.400 líneas que concentran casi toda la
dificultad de mantenimiento.

Los cinco archivos que más pesan:

| Archivo | Líneas | Qué contiene |
|---|---|---|
| `include/class.forms.php` | 6.229 | Formularios dinámicos, campos, validación y widgets |
| `include/class.ticket.php` | 4.855 | Ticket: creación, edición, correo, SLA, adjuntos |
| `include/class.orm.php` | 3.663 | ORM propio (consultas, joins, instrumentación) |
| `include/class.thread.php` | 3.451 | Hilo de entradas, correo entrante, colaboradores |
| `include/class.queue.php` | 3.233 | Colas, criterios y ordenación |

`Queue::getCriteria()` con 1.414 líneas es el caso más claro: es la función
que construye la consulta de una cola a partir de sus filtros, y mezcla
construcción de SQL, validación, permisos y presentación.

---

## 3. Los seis frentes, por relación impacto/riesgo

### Frente 1 — Red de seguridad antes de tocar nada (imprescindible)

No hay pruebas que cubran el comportamiento actual: `setup/test/run-tests.php`
valida el instalador, no la aplicación. **Cualquier refactor sin esto es una
apuesta.**

- Qué hacer: una carpeta `tests/` con PHPUnit (ya está en `composer.json`) y
  pruebas de caracterización sobre las piezas puras: `Format::*`,
  `Validator::*`, `Queue::getSortOptions()`, `TicketStatus`, `Filter`.
- Por qué primero: son funciones sin base de datos, se prueban en segundos y
  permiten refactorizar el resto con red.
- Riesgo: bajo (no se toca código de producción).
- Éxito: `make test` cubre al menos `Format` y `Validator` con casos reales y
  falla si cambian.

### Frente 2 — Partir las funciones gigantes (empezando por `getCriteria`)

- Qué hacer: extraer tramos con nombre propio (`applyStatusFilter()`,
  `applyAssigneeFilter()`, `applyDateRange()`) manteniendo la firma pública.
  Un método que sigue llamándose igual y devuelve lo mismo es un refactor
  seguro si hay pruebas.
- Por qué: es donde se concentra el miedo a tocar. 1.414 líneas se leen peor
  que ocho métodos de 150.
- Riesgo: medio. Necesita pruebas de la consulta generada (comparar SQL).
- Éxito: ninguna función por encima de 400 líneas; `getCriteria()` delega.

### Frente 3 — Terminar la migración al ORM

- Qué hacer: los 183 `db_query()` restantes son SQL escrito a mano que evita
  el ORM (y con él, el escapado y la instrumentación). Migrarlos por áreas,
  empezando por los `SELECT` simples.
- Por qué: cada `db_query` con concatenación es una superficie de inyección
  potencial y un punto que no se beneficia de las mejoras del ORM.
- Riesgo: medio-alto. Requiere revisar cada consulta y sus índices.
- Éxito: `db_query` por debajo de 100 y sin ninguna concatenación de datos de
  la petición (el escáner `tools/audit` ya vigila lo segundo).

### Frente 4 — Entrada única y tipada

- Qué hacer: no tocar los 1.269 accesos uno a uno. Añadir una fachada —
  `Http::get()`, `Http::post()`, `Http::postInt()`— y **usarla en el código
  nuevo**; migrar sólo los archivos que se vayan tocando por otro motivo
  (regla del boy scout).
- Por qué: estandariza la validación sin una migración masiva de riesgo alto.
- Riesgo: bajo si se aplica sólo a código nuevo.
- Éxito: ningún archivo nuevo usa `$_POST[...]` directamente; el contador
  total baja de forma sostenida sin subir nunca.

### Frente 5 — Sacar el JavaScript de las plantillas (CSP)

- Qué hacer: **los `onclick=` ya están a cero** (`make inline-audit`, diez
  tandas con `js/actions.js`). Quedan los bloques `<script>` dentro de las
  plantillas: necesitan `nonce` o pasar a archivos para poder quitar
  `'unsafe-inline'` (y `'unsafe-eval'` en el panel).
- Por qué: es la única forma de endurecer de verdad la CSP (ver OST-102 en
  `SECURITY_AUDIT.md`). Mientras exista `unsafe-inline`, una inyección de
  HTML ejecuta código.
- Riesgo: medio, pero **incremental**: cada pantalla migrada se puede probar
  sola.
- Éxito: `script-src` sin `'unsafe-inline'` en el portal del cliente (hoy
  `onclick=` ya está a cero; falta el nonce de los `<script>`).

### Frente 6 — Partir los archivos de más de 2.000 líneas

- Qué hacer: `class.forms.php` y `class.ticket.php` en rasgos (`trait`) o
  clases colaboradoras, sin cambiar nombres públicos. Aquí sí conviene ir con
  calma: es donde más se rompe.
- Por qué: 6.000 líneas en un archivo hacen que cada búsqueda cueste y que
  los conflictos de fusión sean inevitables.
- Riesgo: alto. Sólo después de los frentes 1 y 2.
- Éxito: ningún archivo por encima de 2.000 líneas.

---

## 4. Orden recomendado

1. **Frente 1** (pruebas) — sin esto, lo demás es fe.
2. **Frente 4** (fachada de entrada) — barato y frena el sangrado.
3. **Frente 2** (`getCriteria` y funciones > 400 líneas).
4. **Frente 5** (JS inline) — se puede hacer pantalla a pantalla.
5. **Frente 3** (SQL a mano) — por áreas, con pruebas.
6. **Frente 6** (archivos gigantes) — al final, con todo lo anterior en verde.

## 5. Lo que NO conviene hacer

- **Reescribir osTicket.** El coste de reimplementar tickets, formularios
  dinámicos, SLA, correo y colas supera cualquier ganancia de estilo.
- **Cambiar la convención de nombres** (`class.ticket.php`, `scp/`) para
  "modernizar". Rompe enlaces, plugins y documentación sin mejorar nada.
- **Un refactor masivo de `$_POST`.** 1.269 sitios, con lógica de negocio
  mezclada: es el cambio con peor relación riesgo/beneficio.
- **Tocar `eval()` del ORM** sin pruebas. Los 13 usos sostienen la generación
  dinámica de clases; sustituirlos exige un rediseño, no un reemplazo.

## 6. Cómo se mide el progreso

```bash
make metrics        # radiografía completa
make metrics-json > antes.json
# ... refactor ...
make metrics-json > despues.json
diff <(python3 -m json.tool antes.json) <(python3 -m json.tool despues.json)
```

Un refactor está justificado si **baja** alguna señal sin que suba ninguna
otra, y si `make ci` sigue en verde.
