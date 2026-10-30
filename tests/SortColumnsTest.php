<?php
/**
 * tests/SortColumnsTest.php
 *
 * Las cabeceras de las listas del panel ordenan con `$sortOptions`: la clave es
 * lo que va en la URL (`?sort=status`) y el valor, la columna con la que se
 * pide el `ORDER BY`.
 *
 * Copiar el nombre del campo del formulario en vez del de la columna rompe la
 * pantalla entera. Pasó en *Equipos*:
 *
 *   SQL error: Unknown column 'A1.isenabled' in 'ORDER BY'
 *   (include/staff/teams.inc.php → `Team::objects()->order_by(...)`)
 *
 * `ost_team` no tiene `isenabled`: el estado vive en `flags` y el modelo lo
 * traduce (`Team::isEnabled()`). Lo mismo en *Planes de SLA*, que pedía
 * `isactive` sobre `ost_sla`, donde también es `flags`.
 *
 * Estas pruebas leen el instalador (`install-mysql.sql`) y las constantes de
 * `bootstrap.php` para comprobar que cada columna de orden existe de verdad en
 * la tabla del modelo que usa esa pantalla. No hace falta base de datos.
 */
class SortColumnsTest extends UnitTestCase {

    /** tabla => columnas, tal como las crea el instalador. */
    private function columnasPorTabla(): array {
        $sql = (string) file_get_contents(ROOT_DIR
            . '/setup/inc/streams/core/install-mysql.sql');

        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?'
            . '`%TABLE_PREFIX%(\w+)` \((.*?)\n\)/s', $sql, $tablas,
            PREG_SET_ORDER);

        $columnas = array();
        foreach ($tablas as $tabla) {
            preg_match_all('/^\s+`(\w+)`/m', $tabla[2], $cols);
            $columnas[$tabla[1]] = $cols[1];
        }

        return $columnas;
    }

    /** `TEAM_TABLE => 'team'`, leído de `bootstrap.php`. */
    private function constantesDeTabla(): array {
        $php = (string) file_get_contents(ROOT_DIR . '/bootstrap.php');

        preg_match_all("/define\('(\w+_TABLE)',\s*\\\$prefix\.'(\w+)'\)/",
            $php, $m, PREG_SET_ORDER);

        $constantes = array();
        foreach ($m as $c)
            $constantes[$c[1]] = $c[2];

        return $constantes;
    }

    /**
     * La tabla del modelo que abre la lista. Se busca la clase en
     * `include/class.*.php` y se lee el `'table'` de su propio bloque: hay
     * ficheros con varias clases (`class.template.php` tiene dos).
     */
    private function tablaDelModelo(string $clase, array $constantes,
            int $profundidad = 0) {
        if ($profundidad > 4)
            return null;

        foreach (glob(ROOT_DIR . '/include/class.*.php') as $ruta) {
            $php = (string) file_get_contents($ruta);
            // Hay clases cuyo nombre empieza por el de otra
            // (`class UserEmailModel` y `class User`): se busca el límite de
            // palabra y se usa esa posición, no la primera aparición.
            if (!preg_match('/(^|\n)class\s+' . preg_quote($clase, '/')
                    . '\b/', $php, $m, PREG_OFFSET_CAPTURE))
                continue;

            $desde = strpos($php, 'class ', $m[0][1]);
            $siguiente = strpos($php, "\nclass ", $desde + 1);
            $bloque = substr($php, $desde,
                ($siguiente === false ? strlen($php) : $siguiente) - $desde);

            if (preg_match("/'table'\s*=>\s*(\w+)/", $bloque, $t))
                return isset($constantes[$t[1]]) ? $constantes[$t[1]] : null;

            // `class User extends UserModel` no repite el `$meta`.
            if (preg_match('/class\s+\w+\s+extends\s+(\w+)/', $bloque, $padre)
                    && strcasecmp($padre[1], 'VerySimpleModel') !== 0)
                return $this->tablaDelModelo($padre[1], $constantes,
                    $profundidad + 1);

            return null;
        }

        return null;
    }

    /**
     * Las columnas que la propia pantalla calcula con `annotate()` (contadores
     * como `members_count`) no están en la tabla y son válidas.
     */
    private function columnasCalculadas(string $php): array {
        preg_match_all('/annotate\(array\((.*?)\)\)/s', $php, $bloques);

        $columnas = array();
        foreach ($bloques[1] as $bloque) {
            preg_match_all("/'(\w+)'\s*=>/", $bloque, $nombres);
            $columnas = array_merge($columnas, $nombres[1]);
        }

        return $columnas;
    }

    /** Clave de la lista => columna pedida. */
    private function opcionesDeOrden(string $php): array {
        if (!preg_match('/\$sortOptions\s*=\s*array\((.*?)\);/s', $php, $opciones))
            return array();

        preg_match_all("/'([^']+)'\s*=>\s*(?:'([^']*)'|array\(([^)]*)\))/",
            $opciones[1], $pares, PREG_SET_ORDER);

        $salida = array();
        foreach ($pares as $par) {
            // `array('firstname', 'lastname')`: dos columnas, se comprueban
            // aparte y aquí sólo se dejan pasar.
            if (!isset($par[2]) || $par[2] === '')
                continue;
            $salida[$par[1]] = $par[2];
        }

        return $salida;
    }

    /**
     * Cada columna de orden tiene que existir en la tabla de su modelo. El
     * fallo se ve en pantalla como «DB Error #1054: Unknown column … in
     * 'ORDER BY'» al pulsar la cabecera.
     */
    public function testCadaOrdenDeListaEsUnaColumnaReal(): void {
        $tablas = $this->columnasPorTabla();
        $constantes = $this->constantesDeTabla();
        $this->assertTrue(count($tablas) > 50,
            sprintf('sólo se leyeron %d tablas del instalador', count($tablas)));

        $revisadas = 0;

        foreach (glob(ROOT_DIR . '/include/staff/*.inc.php') as $ruta) {
            $pantalla = basename($ruta);
            $php = (string) file_get_contents($ruta);

            $opciones = $this->opcionesDeOrden($php);
            if (!$opciones)
                continue;

            if (!preg_match('/(\w+)::objects\(\)/', $php, $modelo))
                continue;

            $tabla = $this->tablaDelModelo($modelo[1], $constantes);
            if (!$tabla || !isset($tablas[$tabla]))
                continue;

            $calculadas = $this->columnasCalculadas($php);

            foreach ($opciones as $clave => $columna) {
                // `dept__name`, `lead__lastname`: un JOIN, no una columna.
                if (strpos($columna, '__') !== false)
                    continue;
                // `filter.name`, `rule.val`: la columna de otra tabla unida.
                if (strpos($columna, '.') !== false)
                    continue;
                if (in_array($columna, $calculadas, true))
                    continue;

                $revisadas++;

                $this->assertTrue(in_array($columna, $tablas[$tabla], true),
                    sprintf('%s: ordenar por «%s» pide la columna `%s`, que no '
                        . 'existe en `%s` (columnas: %s)', $pantalla, $clave,
                        $columna, $tabla, implode(', ', $tablas[$tabla])));
            }
        }

        $this->assertTrue($revisadas > 30,
            sprintf('sólo se revisaron %d columnas de orden: ¿se perdió el '
                . 'listado?', $revisadas));
    }

    /**
     * El caso concreto que rompió *Equipos* y *Planes de SLA*: el estado no es
     * una columna, es un bit de `flags` que el modelo traduce.
     */
    public function testElEstadoSeOrdenaPorFlags(): void {
        foreach (array('teams', 'slaplans', 'departments') as $pantalla) {
            $php = (string) file_get_contents(ROOT_DIR
                . "/include/staff/$pantalla.inc.php");

            $opciones = $this->opcionesDeOrden($php);
            $this->assertTrue(isset($opciones['status']),
                sprintf('%s perdió el orden por estado', $pantalla));
            $this->assertSame('flags', $opciones['status'],
                sprintf('%s ordena el estado por `%s`; en su tabla el estado '
                    . 'es un bit de `flags`', $pantalla, $opciones['status']));
        }
    }
}
