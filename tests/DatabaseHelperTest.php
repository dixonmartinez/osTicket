<?php
/**
 * tests/DatabaseHelperTest.php
 *
 * Pruebas de la parte de `tests/lib/Database.php` que **no** necesita la base
 * de datos: cómo se parte el volcado del instalador y cómo se decide el
 * nombre de la base de pruebas.
 *
 * Se prueban aquí y no en `tests/integration/` porque esto tiene que correr
 * en `make unit`: si el separador de sentencias se rompe, las pruebas de
 * integración fallarían por un motivo engañoso.
 */

require_once __DIR__ . '/lib/Database.php';
/* El error de la última consulta fallida: es lo que permite que el ORM diga
   **por qué** falló en vez de «Unable to prepare query:  SELECT …». Se carga
   en su propio archivo (`class.db.failure.php`) y no desde `mysqli.php`
   porque definir `db_query()` cambiaría el camino que toman las pruebas del
   sembrador. */
require_once INCLUDE_DIR . 'class.db.failure.php';

class DatabaseHelperTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Separación de sentencias                                            */
    /* ------------------------------------------------------------------ */

    public function testSeparaDosSentencias(): void {
        $this->assertSame(array('SELECT 1', 'SELECT 2'),
            TestDatabase::split('SELECT 1; SELECT 2;'));
    }

    public function testUnPuntoYComaDentroDeUnaCadenaNoParte(): void {
        $this->assertCount(1, TestDatabase::split(
            "INSERT INTO t VALUES ('uno; dos');"));
    }

    public function testUnaComillaEscapadaNoCierraLaCadena(): void {
        $this->assertCount(1, TestDatabase::split(
            "INSERT INTO t VALUES ('no es \\'esto; ni esto');"));
    }

    public function testUnaComillaDentroDeOtra(): void {
        $this->assertCount(1, TestDatabase::split(
            "INSERT INTO t VALUES ('dijo \"hola; adiós\"');"));
    }

    public function testLosComentariosDeLineaNoCuentan(): void {
        $parts = TestDatabase::split("-- comentario; con punto y coma\nSELECT 1;");

        $this->assertCount(1, $parts);
        $this->assertSame('SELECT 1', $parts[0]);
    }

    public function testElComentarioConAlmohadillaTampoco(): void {
        $this->assertCount(1, TestDatabase::split("# otra; nota\nSELECT 1;"));
    }

    public function testLaUltimaSentenciaSinPuntoYComaSeQueda(): void {
        $this->assertSame(array('SELECT 1', 'SELECT 2'),
            TestDatabase::split('SELECT 1; SELECT 2'));
    }

    public function testUnTextoVacioNoDaSentencias(): void {
        $this->assertSame(array(), TestDatabase::split("  \n-- nada\n\n"));
    }

    public function testLosIdentificadoresConComillasInvertidasSeRespetan(): void {
        $this->assertCount(1, TestDatabase::split(
            'ALTER TABLE `ost_ticket` ADD COLUMN `x` int;'));
    }

    /* ------------------------------------------------------------------ */
    /* El volcado del instalador                                           */
    /* ------------------------------------------------------------------ */

    public function testElVolcadoDelInstaladorSeParteEntero(): void {
        $file = ROOT_DIR . '/setup/inc/streams/core/install-mysql.sql';
        $sql = str_replace('%TABLE_PREFIX%', 'ost_', file_get_contents($file));
        $parts = TestDatabase::split($sql);

        // Un archivo de 1.000 líneas con 133 sentencias: si el separador se
        // comiera alguna, esto baja en picado.
        $this->assertGreaterThan(120, count($parts));

        foreach ($parts as $part)
            $this->assertFalse(strpos($part, '%TABLE_PREFIX%') !== false,
                'quedó un marcador de prefijo sin sustituir');

        $this->assertStringContainsString('CREATE TABLE `ost_ticket`', $sql);
    }

    public function testCadaSentenciaDelVolcadoEmpiezaPorUnaPalabraConocida(): void {
        $file = ROOT_DIR . '/setup/inc/streams/core/install-mysql.sql';
        $parts = TestDatabase::split(str_replace('%TABLE_PREFIX%', 'ost_',
            file_get_contents($file)));

        $conocidas = array('CREATE', 'DROP', 'ALTER', 'INSERT', 'REPLACE',
            'SET', 'UPDATE', 'DELETE');

        foreach ($parts as $part) {
            list($primera) = explode(' ', ltrim($part), 2);
            $this->assertTrue(in_array(strtoupper($primera), $conocidas),
                'sentencia inesperada: ' . substr($part, 0, 40));
        }
    }

    /* ------------------------------------------------------------------ */
    /* La base de pruebas no puede ser la de la instalación                */
    /* ------------------------------------------------------------------ */

    public function testElNombreDeLaBaseLlevaSufijoDePruebas(): void {
        $settings = TestDatabase::settings();

        $this->assertStringContainsString('_test', $settings['name']);
        $this->assertSame('_test', substr($settings['name'], -5));
    }

    public function testElNombreNoCoincideConElDeLaInstalacion(): void {
        $name = getenv('OST_DB_NAME') ?: 'osticket';

        $this->assertFalse($name === TestDatabase::settings()['name'],
            'la base de pruebas no puede ser la de la instalación');
    }

    /* ------------------------------------------------------------------ */
    /* Error de la última consulta (mysqli.php)                            */
    /* ------------------------------------------------------------------ */

    public function testRecuerdaElUltimoErrorDeLaConexion(): void {
        $GLOBALS['__db_last_failure'] = null;
        unset($GLOBALS['__db_last_failure']);

        db_remember_failure(1146, "Table 'ost_ticket' doesn't exist",
            'SELECT * FROM ost_ticket');

        $this->assertSame(1146, db_last_errno());
        $this->assertSame("Table 'ost_ticket' doesn't exist", db_last_error());
        $this->assertStringContainsString('[1146]', db_last_failure_text());
        $this->assertStringContainsString("doesn't exist",
            db_last_failure_text());
    }

    public function testSinErrorNoInventaMensaje(): void {
        unset($GLOBALS['__db_last_failure']);

        $this->assertSame('', db_last_error());
        $this->assertSame(0, db_last_errno());
        $this->assertSame('', db_last_failure_text());
    }

    public function testUnErrorSinMensajeSeExplicaIgual(): void {
        unset($GLOBALS['__db_last_failure']);

        // Pasa de verdad con los errores del lado del cliente: mysqli deja
        // `error` en blanco. El texto tiene que decir algo igualmente.
        db_remember_failure(2014, '', 'SELECT 1');

        $this->assertSame(2014, db_last_errno());
        $this->assertStringContainsString('no dio explicación',
            db_last_failure_text());
    }

    public function testUnFalloVacioNoPisaElAnterior(): void {
        unset($GLOBALS['__db_last_failure']);
        db_remember_failure(1064, 'You have an error in your SQL syntax', 'x');

        db_remember_failure(0, '', 'y');

        $this->assertSame(1064, db_last_errno());
        $this->assertStringContainsString('SQL syntax', db_last_error());
    }

    public function testSinMysqliHayUnMotivo(): void {
        if (extension_loaded('mysqli'))
            $this->markTestSkipped('aquí mysqli sí está cargada');

        $this->assertSame('la extensión mysqli no está cargada',
            TestDatabase::unavailable());
    }
}
