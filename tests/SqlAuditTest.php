<?php
/**
 * tests/SqlAuditTest.php
 *
 * Pruebas de `tools/quality/sql-audit.php` y de `tools/quality/lib/source.php`
 * (frente 3 de docs/CODE_QUALITY.md): qué cuenta como SQL a mano, cómo se
 * clasifica, y cómo se compara con la línea base.
 *
 * El archivo de la herramienta empieza por `#!`, así que se incluye con la
 * salida capturada.
 */
ob_start();
require_once dirname(__DIR__) . '/tools/quality/sql-audit.php';
ob_end_clean();

class SqlAuditTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Comentarios                                                         */
    /* ------------------------------------------------------------------ */

    public function testUnComentarioNoEsCodigo(): void {
        $source = "<?php\n// db_query('SELECT 1')\n\$a = 1;\n";

        $this->assertFalse(strpos(PhpSource::withoutComments($source),
            'db_query') !== false);
    }

    public function testElComentarioDeBloqueTampoco(): void {
        $source = "<?php\n/* db_query('SELECT 1') */\n\$a = 1;\n";

        $this->assertFalse(strpos(PhpSource::withoutComments($source),
            'db_query') !== false);
    }

    public function testLaDocumentacionTampoco(): void {
        $source = "<?php\n/**\n * db_query('SELECT 1')\n */\nfunction f() {}\n";

        $this->assertFalse(strpos(PhpSource::withoutComments($source),
            'db_query') !== false);
    }

    public function testSeConservanLasLineas(): void {
        // Si un comentario se come líneas, el informe da números falsos
        $source = "<?php\n\n// uno\ndos\n/* tres\ncuatro */\ncinco();\n";
        $clean = PhpSource::withoutComments($source);

        $this->assertSame(substr_count($source, "\n"),
            substr_count($clean, "\n"));
        $this->assertStringContainsString('cinco()', $clean);
    }

    public function testElCodigoDeVerdadSeQueda(): void {
        $source = "<?php\n\$x = db_query('SELECT 1');\n";

        $this->assertStringContainsString("db_query('SELECT 1')",
            PhpSource::withoutComments($source));
    }

    public function testUnaAlmohadillaDentroDeUnaCadenaNoCortaLaLinea(): void {
        // El `#` de la cadena no abre comentario: la llamada de después cuenta
        $source = "<?php\n\$x = '# no es comentario';\ndb_query('SELECT 1');\n";

        $this->assertSame(1, SqlAudit::countCalls($source));
    }

    /* ------------------------------------------------------------------ */
    /* Cuántas llamadas                                                   */
    /* ------------------------------------------------------------------ */

    public function testCuentaUnaLlamada(): void {
        $calls = SqlAudit::callsIn("<?php db_query('SELECT 1');");

        $this->assertCount(1, $calls);
        $this->assertSame(1, $calls[0]['line']);
    }

    public function testNoCuentaLaDefinicionDeLaFuncion(): void {
        // `include/mysqli.php` declara db_query; eso es el driver, no deuda
        $source = "<?php function db_query(\$sql, \$die=true) { return 1; }";

        $this->assertSame(1, SqlAudit::countCalls($source));
        $this->assertSame('driver', SqlAudit::categoryOf('include/mysqli.php'));
    }

    public function testCuentaVarias(): void {
        $source = "<?php\ndb_query('SELECT 1');\ndb_query('SELECT 2');";

        $this->assertSame(2, SqlAudit::countCalls($source));
    }

    public function testInformaLaLinea(): void {
        $source = "<?php\n\n\n\$a = db_query('SELECT 1');";
        $calls = SqlAudit::callsIn($source);

        $this->assertSame(4, $calls[0]['line']);
    }

    /* ------------------------------------------------------------------ */
    /* De qué tipo es                                                      */
    /* ------------------------------------------------------------------ */

    public function testReconoceUnSelect(): void {
        $calls = SqlAudit::callsIn("<?php db_query('SELECT * FROM t');");

        $this->assertSame('select', $calls[0]['kind']);
    }

    public function testReconoceUnaModificacion(): void {
        $this->assertSame('dml', SqlAudit::kindOf("'UPDATE t SET a=1'"));
        $this->assertSame('dml', SqlAudit::kindOf("'INSERT INTO t (a) VALUES (1)'"));
        $this->assertSame('dml', SqlAudit::kindOf("'DELETE FROM t'"));
    }

    public function testReconoceMantenimiento(): void {
        $this->assertSame('ddl', SqlAudit::kindOf("'OPTIMIZE TABLE t'"));
        $this->assertSame('ddl', SqlAudit::kindOf("'ALTER TABLE t ADD c INT'"));
    }

    public function testReconoceUnSqlConstruidoPorTrozos(): void {
        // El SQL empieza por `$sql='...'`, no por la palabra clave
        $this->assertSame('select',
            SqlAudit::kindOf("\$sql='SELECT a FROM t' . ' WHERE b=1'"));
    }

    public function testNoSeConfundeConPalabrasDentroDeValores(): void {
        // 'deleted' no convierte en DML un SELECT
        $this->assertSame('select',
            SqlAudit::kindOf("'SELECT a FROM t WHERE status = \"deleted\"'"));
    }

    /* ------------------------------------------------------------------ */
    /* Literal o construida                                                */
    /* ------------------------------------------------------------------ */

    public function testUnaCadenaEsLiteral(): void {
        $calls = SqlAudit::callsIn("<?php db_query('SELECT 1');");

        $this->assertFalse($calls[0]['built']);
    }

    public function testUnaVariableEsConstruida(): void {
        $calls = SqlAudit::callsIn("<?php db_query(\$sql);");

        $this->assertTrue($calls[0]['built']);
    }

    /* ------------------------------------------------------------------ */
    /* Datos de la petición                                                */
    /* ------------------------------------------------------------------ */

    public function testDetectaDatosDeLaPeticionSinEscapar(): void {
        $calls = SqlAudit::callsIn(
            "<?php db_query('SELECT * FROM t WHERE a=' . \$_POST['a']);");

        $this->assertTrue($calls[0]['request']);
    }

    public function testNoAvisaSiEstaEscapado(): void {
        $calls = SqlAudit::callsIn(
            "<?php db_query('SELECT * FROM t WHERE a=' . db_input(\$_POST['a']));");

        $this->assertFalse($calls[0]['request']);
    }

    public function testNoAvisaSiSeConvierteAEntero(): void {
        $calls = SqlAudit::callsIn(
            "<?php db_query('SELECT * FROM t WHERE a=' . (int) \$_GET['a']);");

        $this->assertFalse($calls[0]['request']);
    }

    public function testSigueLaVariableHastaDondeSeConstruyo(): void {
        // El caso peligroso de verdad: db_query($sql) con el valor dentro
        $source = "<?php\n"
            . "\$sql = 'SELECT * FROM t WHERE a=' . \$_POST['a'];\n"
            . "db_query(\$sql);\n";
        $calls = SqlAudit::callsIn($source);

        $this->assertTrue($calls[0]['request']);
    }

    public function testSigueLasConcatenacionesHaciaArriba(): void {
        $source = "<?php\n"
            . "\$sql = 'SELECT * FROM t';\n"
            . "\$sql .= ' WHERE a = ' . db_input(\$_POST['a']);\n"
            . "db_query(\$sql);\n";
        $calls = SqlAudit::callsIn($source);

        $this->assertSame('select', $calls[0]['kind']);
        $this->assertFalse($calls[0]['request']);
    }

    /* ------------------------------------------------------------------ */
    /* SQL no migrable                                                     */
    /* ------------------------------------------------------------------ */

    public function testUnaMarcaSeReconoce(): void {
        $source = "<?php\n"
            . "// sql-audit:legacy OPTIMIZE TABLE no tiene modelo\n"
            . "db_query('OPTIMIZE TABLE t');\n";

        $calls = SqlAudit::callsIn($source);

        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['legacy']);
        $this->assertSame('OPTIMIZE TABLE no tiene modelo', $calls[0]['reason']);
    }

    public function testLaMarcaValeHastaTresLineasArriba(): void {
        $source = "<?php\n"
            . "// sql-audit:legacy SHOW STATUS\n"
            . "\$sql = 'SHOW STATUS';\n"
            . "\n"
            . "db_query(\$sql);\n";

        $calls = SqlAudit::callsIn($source);

        $this->assertTrue($calls[0]['legacy']);
    }

    public function testLaMarcaNoAlcanzaCuatroLineasArriba(): void {
        $source = "<?php\n"
            . "// sql-audit:legacy SHOW STATUS\n"
            . "\$sql = 'SHOW STATUS';\n"
            . "\n"
            . "\n"
            . "db_query(\$sql);\n";

        $this->assertFalse(SqlAudit::callsIn($source)[0]['legacy']);
    }

    public function testUnaMarcaSinMotivoNoVale(): void {
        $scan = array('app' => array('a.php' => SqlAudit::callsIn(
            "<?php\n// sql-audit:legacy\ndb_query('SHOW STATUS');\n")));

        $this->assertCount(1, SqlAudit::badMarks($scan));
        $this->assertCount(0, SqlAudit::badMarks(array('app' => array(
            'a.php' => SqlAudit::callsIn("<?php\n// sql-audit:legacy SHOW STATUS\n"
                . "db_query('SHOW STATUS');\n")))));
    }

    public function testLoMarcadoNoCuentaParaElObjetivo(): void {
        $calls = SqlAudit::callsIn("<?php\n"
            . "db_query('SELECT 1 FROM t');\n"
            . "\n\n\n"
            . "// sql-audit:legacy SHOW STATUS\ndb_query('SHOW STATUS');\n");
        $files = array('a.php' => $calls);

        $this->assertSame(array('a.php' => 1), SqlAudit::counts($files));
        $this->assertSame(array('a.php' => 2), SqlAudit::counts($files, true));
        $this->assertSame(1, SqlAudit::breakdown($files)['legacy']);
    }

    public function testElTopeDeMarcasEsBajo(): void {
        // Si se pudiera marcar todo, el contador no significaría nada.
        $this->assertTrue(SqlAudit::LEGACY_MAX <= 30);
    }

    public function testLosDoblesDePruebaNoCuentanComoInseguros(): void {
        $scan = array('tests' => array('t.php' => SqlAudit::callsIn(
            "<?php\ndb_query('SELECT * FROM t WHERE a=' . \$_POST['a']);\n")));

        $this->assertCount(0, SqlAudit::unsafe($scan));
    }

    /* ------------------------------------------------------------------ */
    /* Clasificación por archivo                                           */
    /* ------------------------------------------------------------------ */

    public function testElDriverNoEsDeuda(): void {
        $this->assertSame('driver',
            SqlAudit::categoryOf('include/mysqli.php'));
    }

    public function testElActualizadorEsHistorico(): void {
        $this->assertSame('upgrader', SqlAudit::categoryOf(
            'include/upgrader/streams/core/abc.task.php'));
    }

    public function testElInstaladorCorreAntesDelEsquema(): void {
        $this->assertSame('installer',
            SqlAudit::categoryOf('setup/inc/class.installer.php'));
    }

    public function testLoDemasEsAplicacion(): void {
        $this->assertSame('app', SqlAudit::categoryOf('include/class.ticket.php'));
        $this->assertSame('app', SqlAudit::categoryOf('scp/tickets.php'));
        $this->assertSame('app', SqlAudit::categoryOf('api/http.php'));
    }

    public function testLasCategoriasSonLasEsperadas(): void {
        $this->assertSame(array('app', 'installer', 'upgrader', 'driver',
            'tests'), SqlAudit::categories());
    }

    /* ------------------------------------------------------------------ */
    /* Línea base                                                          */
    /* ------------------------------------------------------------------ */

    public function testSinCambiosEstaBien(): void {
        $result = SqlAudit::compare(array('a.php' => 2), array('a.php' => 2));

        $this->assertTrue($result['ok']);
    }

    public function testUnArchivoNuevoNoEstaBien(): void {
        $result = SqlAudit::compare(array('b.php' => 1), array());

        $this->assertFalse($result['ok']);
        $this->assertSame(array('b.php' => 1), $result['added']);
    }

    public function testUnaSubidaNoEstaBien(): void {
        $result = SqlAudit::compare(array('a.php' => 3), array('a.php' => 2));

        $this->assertFalse($result['ok']);
        $this->assertSame(array('a.php' => array(2, 3)), $result['grown']);
    }

    public function testUnaBajadaEstaBien(): void {
        $result = SqlAudit::compare(array('a.php' => 1), array('a.php' => 5));

        $this->assertTrue($result['ok']);
        $this->assertSame(array('a.php' => array(5, 1)), $result['shrunk']);
    }

    public function testDesaparecerCuentaComoBajada(): void {
        $result = SqlAudit::compare(array(), array('a.php' => 4));

        $this->assertTrue($result['ok']);
        $this->assertSame(array('a.php' => array(4, 0)), $result['shrunk']);
    }

    public function testLaLineaBaseSeGuardaOrdenada(): void {
        $path = tempnam(sys_get_temp_dir(), 'sql-audit-');
        try {
            $this->assertTrue(SqlAudit::save($path, array('b.php' => 1,
                'a.php' => 2)));
            $this->assertSame(array('a.php' => 2, 'b.php' => 1),
                SqlAudit::load($path));
        } finally {
            @unlink($path);
        }
    }
}
