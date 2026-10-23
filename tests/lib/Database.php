<?php
/**
 * tests/lib/Database.php
 *
 * Base de datos desechable para las pruebas de integración.
 *
 * Hasta ahora la suite era estática: `tests/QuerySqlTest.php` comprueba el
 * SQL que genera el ORM, pero no que MariaDB lo acepte. Eso es justo lo que
 * faltaba para el frente 2 (no se puede trocear `Ticket::create()` a ciegas)
 * y para terminar de migrar el SQL a mano del frente 3.
 *
 * Reglas de esta clase:
 *
 *   - **Nunca toca la base de la instalación.** Crea `<OST_DB_NAME>_test` y
 *     trabaja ahí. Si no puede, las pruebas se omiten con el motivo.
 *   - Se conecta con el usuario **root** del contenedor porque crear y borrar
 *     una base necesita privilegios; la instalación sigue usando el suyo.
 *   - El esquema sale del **instalador de verdad**
 *     (`setup/inc/streams/core/install-mysql.sql`): si el instalador se rompe,
 *     esto se entera.
 *
 * Variables (las pone `compose.yaml` en el servicio `tools`):
 *
 *   OST_DB_HOST, OST_DB_PORT, OST_DB_NAME, OST_DB_PREFIX, OST_DB_ROOT_PASS
 */

class TestDatabase {

    /** Nombre de la base de pruebas, una vez creada. */
    static private $schema = null;

    /** Motivo por el que no hay base de datos, para omitir con fundamento. */
    static private $unavailable = null;

    /** Tablas de la aplicación ya cargadas. */
    static private $installed = false;

    /* ------------------------------------------------------------------ */
    /* Configuración                                                       */
    /* ------------------------------------------------------------------ */

    static function settings() {
        return array(
            'host'   => getenv('OST_DB_HOST') ?: '127.0.0.1',
            'port'   => getenv('OST_DB_PORT') ?: '3307',
            'user'   => getenv('OST_DB_USER') ?: 'osticket',
            'pass'   => getenv('OST_DB_PASS') ?: 'osticket',
            'root'   => getenv('OST_DB_ROOT_PASS') ?: 'root',
            // Sufijo fijo: así no hay forma de apuntar a la base real ni por
            // error al escribir la variable de entorno.
            'name'   => (getenv('OST_DB_NAME') ?: 'osticket') . '_test',
            'prefix' => getenv('OST_DB_PREFIX') ?: 'ost_',
        );
    }

    /** null si se puede usar, o el motivo por el que no. */
    static function unavailable() {
        if (!extension_loaded('mysqli'))
            return 'la extensión mysqli no está cargada';

        if (self::$unavailable)
            return self::$unavailable;

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Conexión                                                            */
    /* ------------------------------------------------------------------ */

    static function connect() {
        global $__db;

        if ($__db)
            return $__db;

        require_once INCLUDE_DIR . 'mysqli.php';

        $s = self::settings();
        // db_connect() se niega sin contraseña (salvo con SSL), y la imagen
        // de MariaDB siempre trae una.
        if (!db_connect($s['host'] . ':' . $s['port'], 'root', $s['root'])) {
            $motivo = isset($GLOBALS['__db_connect_error'])
                ? $GLOBALS['__db_connect_error'] : 'sin detalle';
            throw new RuntimeException(sprintf(
                'no se pudo conectar con %s:%s (%s)', $s['host'], $s['port'],
                $motivo));
        }

        return $__db;
    }

    /**
     * Crea (o recrea) la base de pruebas y carga el esquema del instalador.
     *
     * Es idempotente dentro del proceso: la primera prueba la crea y las
     * demás la reutilizan; cada una limpia sus tablas en `setUp()`.
     */
    static function schema() {
        if (self::$schema !== null)
            return self::$schema;

        // Sin la extensión no se llega ni a intentarlo: `mysqli_init()` no
        // existe y el error sería un fatal en vez de una omisión explicada.
        if ($reason = self::unavailable()) {
            self::$unavailable = $reason;
            throw new RuntimeException($reason);
        }

        try {
            self::connect();
        }
        catch (RuntimeException $e) {
            self::$unavailable = $e->getMessage();
            throw $e;
        }

        $s = self::settings();

        // `DROP`/`CREATE DATABASE` no se pueden parametrizar; el nombre lo
        // construye esta clase y no viene de la petición.
        self::exec('DROP DATABASE IF EXISTS `' . $s['name'] . '`', true);
        self::exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 '
            . 'COLLATE utf8mb4_unicode_ci', $s['name']), true);

        if (!db_select_database($s['name']))
            throw new RuntimeException('no se pudo seleccionar ' . $s['name']);

        self::loadSchema();

        return self::$schema = $s['name'];
    }

    /**
     * Carga `install-mysql.sql` sustituyendo el prefijo.
     *
     * Es el mismo archivo que usa el instalador, así que una errata en él
     * aparece aquí antes que en una instalación nueva.
     */
    static function loadSchema() {
        $file = ROOT_DIR . '/setup/inc/streams/core/install-mysql.sql';

        if (!is_readable($file))
            throw new RuntimeException('no se encuentra ' . $file);

        $sql = str_replace('%TABLE_PREFIX%', self::settings()['prefix'],
            file_get_contents($file));

        $statements = self::split($sql);
        // Sin `quiet`: si una sentencia del instalador falla, la prueba tiene
        // que decirlo. Silenciarla sería justo lo contrario de lo que se
        // busca con este banco.
        foreach ($statements as $statement)
            self::exec($statement);

        self::$installed = true;

        return count($statements);
    }

    /** ¿Está cargado el esquema de la aplicación en esta base? */
    static function installed() {
        return self::$installed;
    }

    /**
     * Parte un volcado en sentencias, respetando cadenas y comentarios.
     *
     * No sirve `explode(';')`: hay valores con punto y coma dentro de las
     * cadenas (descripciones, plantillas) que partirían la sentencia.
     */
    static function split($sql) {
        $statements = array();
        $buffer = '';
        $quote = null;
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if ($quote) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $len)
                    $buffer .= $sql[++$i];
                elseif ($char === $quote)
                    $quote = null;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            // Comentarios de línea: hasta el salto, que sí se conserva
            if (($char === '-' && substr($sql, $i, 2) === '--')
                    || $char === '#') {
                while ($i < $len && $sql[$i] !== "\n")
                    $i++;
                $buffer .= "\n";
                continue;
            }

            if ($char === ';') {
                $statements[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '')
            $statements[] = trim($buffer);

        return array_values(array_filter($statements, 'strlen'));
    }

    /* ------------------------------------------------------------------ */
    /* Consultas                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Ejecuta y devuelve el resultado. Lanza si la sentencia falla: en una
     * prueba, un SQL rechazado es un fallo, no un `false` que se ignora.
     */
    static function query($sql) {
        self::connect();

        $res = db_query($sql, false);
        if ($res === false)
            throw new RuntimeException(sprintf("%s\n        SQL: %s",
                db_error(), self::excerpt($sql)));

        return $res;
    }

    static function exec($sql, $quiet = false) {
        try {
            return self::query($sql);
        }
        catch (RuntimeException $e) {
            if (!$quiet)
                throw $e;
            return false;
        }
    }

    /** Primera columna de la primera fila. */
    static function value($sql, $default = null) {
        $res = self::query($sql);
        $row = db_fetch_row($res);
        db_free_result($res);

        // Sin filas, `db_fetch_row()` devuelve NULL; no se puede desestructurar
        // sin un aviso de PHP (y un aviso es un fallo en esta suite).
        return is_array($row) && $row[0] !== null ? $row[0] : $default;
    }

    /** Todas las filas como arrays asociativos. */
    static function rows($sql) {
        $res = self::query($sql);
        $rows = db_assoc_array($res);
        db_free_result($res);

        return $rows;
    }

    /** Nombre real de una tabla de la aplicación, con su prefijo. */
    static function table($name) {
        return self::settings()['prefix'] . $name;
    }

    /** Deja una tabla vacía entre pruebas. */
    static function truncate($table) {
        self::exec('TRUNCATE TABLE `' . self::table($table) . '`');
    }

    /** Primer trozo del SQL, para los mensajes de error. */
    static private function excerpt($sql) {
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        return strlen($sql) > 160 ? substr($sql, 0, 160) . '…' : $sql;
    }
}
