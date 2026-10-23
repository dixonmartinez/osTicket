<?php
/**
 * tests/OrmDirtyTest.php
 *
 * Qué cuenta como «campo cambiado» en el ORM.
 *
 * Esto salió de la primera ejecución de las pruebas de integración: un modelo
 * nuevo con `name => ''` no se guardaba. La causa es que `save()` no hace
 * nada si no hay campos en `$dirty`, y la comparación era `$old != $value`;
 * en PHP, `null == ''` y `null == 0` son ciertos, así que asignar una cadena
 * vacía o un cero a un campo que aún no estaba **no** marcaba el modelo como
 * cambiado.
 *
 * En una instalación se ve como un campo que se vacía en el formulario y
 * sigue con el valor anterior; cuesta encontrar porque el `save()` devuelve
 * `true`.
 *
 * Son pruebas de modelo: no tocan la base de datos (los campos del modelo se
 * declaran a mano, como en `QuerySqlTest`).
 */

require_once INCLUDE_DIR . 'class.orm.php';

if (!class_exists('OrmDirtyRow')) {
    class OrmDirtyRow extends VerySimpleModel {
        static $meta = array(
            'table' => 'dirty_probe',
            'pk' => array('id'),
        );
    }
}

class OrmDirtyTest extends UnitTestCase {

    protected function setUp(): void {
        OrmDirtyRow::getMeta()->fields =
            array('id', 'name', 'qty', 'flag', 'notes');
    }

    /* ------------------------------------------------------------------ */
    /* Lo que no se guardaba                                               */
    /* ------------------------------------------------------------------ */

    public function testUnaCadenaVaciaMarcaElCampoComoCambiado(): void {
        $row = new OrmDirtyRow(array('name' => ''));

        $this->assertArrayHasKey('name', $row->dirty);
        $this->assertSame('', $row->name);
    }

    public function testUnCeroMarcaElCampoComoCambiado(): void {
        $row = new OrmDirtyRow(array('qty' => 0));

        $this->assertArrayHasKey('qty', $row->dirty);
    }

    public function testUnFalsoMarcaElCampoComoCambiado(): void {
        $row = new OrmDirtyRow(array('flag' => false));

        $this->assertArrayHasKey('flag', $row->dirty);
    }

    public function testVaciarUnaCadenaQueYaTeniaValor(): void {
        $row = new OrmDirtyRow();
        $row->notes = 'algo';
        $row->dirty = array();

        $row->notes = '';

        $this->assertArrayHasKey('notes', $row->dirty);
        $this->assertSame('', $row->notes);
    }

    /* ------------------------------------------------------------------ */
    /* Lo que sigue sin ser un cambio                                      */
    /* ------------------------------------------------------------------ */

    public function testAsignarElMismoValorNoCuenta(): void {
        $row = new OrmDirtyRow(array('name' => 'igual'));
        $row->dirty = array();

        $row->name = 'igual';

        $this->assertSame(array(), $row->dirty);
    }

    public function testUnEnteroDeLaBaseYUnNumeroAsignadoSonLoMismo(): void {
        // La base devuelve `qty` como cadena; el formulario lo asigna como
        // número. No es un cambio, y marcarlo sería un UPDATE por cada guardado.
        $row = new OrmDirtyRow();
        $row->ht['qty'] = '5';
        $row->dirty = array();

        $row->qty = 5;

        $this->assertSame(array(), $row->dirty);
    }

    public function testPonerNullSobreUnValorSiCuenta(): void {
        $row = new OrmDirtyRow(array('notes' => 'texto'));
        $row->dirty = array();

        $row->notes = null;

        $this->assertArrayHasKey('notes', $row->dirty);
        $this->assertNull($row->notes);
    }

    public function testElPrimerValorSeGuardaAunqueSeaVacio(): void {
        // Lo que hacía `save()`: si no hay nada en `dirty`, termina sin
        // consultar. Con la cadena vacía dentro, el INSERT la incluye.
        $row = new OrmDirtyRow(array('name' => ''));

        $this->assertCount(1, $row->dirty);

        $compiler = new MySqlCompiler();
        $sql = $compiler->compileInsert($row)->sql;

        $this->assertStringContainsString('`name`', $sql);
        $this->assertSame(array(''), $compiler->params);
    }

    public function testElOrmTieneSusDependenciasCargadas(): void {
        // `save()` y `delete()` emiten señales, y `db_connect()` siembra el
        // generador con `Misc`. Si el bootstrap de las pruebas deja de
        // cargarlas, la primera prueba con base de datos falla con «Class not
        // found» y el mensaje no dice nada de la base.
        $this->assertTrue(class_exists('Signal'));
        $this->assertTrue(class_exists('Misc'));
    }

    public function testVariosCamposVaciosEnElMismoModelo(): void {
        $row = new OrmDirtyRow(array('name' => '', 'qty' => 0, 'flag' => 0));

        $this->assertCount(3, $row->dirty);

        $compiler = new MySqlCompiler();
        $sql = $compiler->compileInsert($row)->sql;

        $this->assertStringContainsString('`name`', $sql);
        $this->assertStringContainsString('`qty`', $sql);
        $this->assertStringContainsString('`flag`', $sql);
    }
}
