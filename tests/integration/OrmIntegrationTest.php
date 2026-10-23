<?php
/**
 * tests/integration/OrmIntegrationTest.php
 *
 * El ORM contra MariaDB de verdad.
 *
 * `tests/QuerySqlTest.php` fija el **texto** del SQL; esto comprueba lo otro:
 * que MariaDB lo acepte y que devuelva lo que toca. Un `UPDATE` con un
 * `JOIN` mal compilado o un `IN` con parámetros mal colocados pasa la prueba
 * de texto y revienta en producción.
 *
 * Se ejecuta con `make integration` (o `make docker-integration`, que
 * levanta la base). Sin base de datos, las pruebas se omiten.
 */

require_once INCLUDE_DIR . 'class.orm.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/IntegrationTestCase.php';

/**
 * Modelo de prueba sobre una tabla propia, para no depender del esquema de
 * la aplicación ni de sus constantes.
 *
 * El nombre es fijo (`ost_test_row`) y no lleva el prefijo configurable a
 * propósito: es una tabla de pruebas, no de osTicket.
 */
if (!class_exists('OrmIntegrationRow')) {
    class OrmIntegrationRow extends VerySimpleModel {
        static $meta = array(
            'table' => 'ost_test_row',
            'pk' => array('id'),
            'ordering' => array('name'),
        );
    }
}

class OrmIntegrationTest extends IntegrationTestCase {

    protected function beforeEach(): void {
        // Tabla propia, siempre igual: si existe de una ejecución anterior,
        // se tira. No se toca nada del esquema de la aplicación.
        $this->query('DROP TABLE IF EXISTS `ost_test_row`');
        $this->query('CREATE TABLE `ost_test_row` ('
            . '`id` int unsigned not null auto_increment,'
            . '`name` varchar(64) not null,'
            . '`qty` int not null default 0,'
            . '`flag` tinyint(1) not null default 0,'
            . '`notes` varchar(64) null,'
            . '`stamp` datetime null,'
            . 'primary key (`id`),'
            . 'key `name` (`name`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /* ------------------------------------------------------------------ */
    /* Escritura y lectura                                                 */
    /* ------------------------------------------------------------------ */

    public function testGuardaUnaFilaYLaVuelveALeer(): void {
        $row = new OrmIntegrationRow(array('name' => 'uno', 'qty' => 5));

        $this->assertTrue($row->save());

        $id = $row->id;
        $this->assertGreaterThan(0, $id);

        $leida = OrmIntegrationRow::lookup($id);
        $this->assertSame('uno', $leida->name);
        $this->assertSame(5, (int) $leida->qty);
    }

    public function testLosValoresNulosYVaciosSeGuardan(): void {
        // `null == ''` en PHP hacía que este campo no se marcara como
        // cambiado: `save()` se daba por terminado y no insertaba nada.
        $row = new OrmIntegrationRow(array('name' => '', 'qty' => 0));
        $this->assertTrue($row->save());

        $this->assertSame('', $this->value('SELECT `name` FROM `ost_test_row`'));
        $this->assertSame(1, (int) $this->value(
            'SELECT COUNT(*) FROM `ost_test_row`'));
    }

    public function testVaciarUnCampoQueYaTeniaValor(): void {
        $row = new OrmIntegrationRow(array('name' => 'algo'));
        $this->assertTrue($row->save());

        $row->name = '';
        $this->assertTrue($row->save());

        $this->assertSame('', $this->value('SELECT `name` FROM `ost_test_row`'));
    }

    public function testBorrarUnCampoOpcionalLoDejaEnNull(): void {
        $row = new OrmIntegrationRow(array('name' => 'x', 'notes' => 'algo'));
        $this->assertTrue($row->save());

        $this->assertSame('algo', $this->value(
            'SELECT `notes` FROM `ost_test_row` WHERE `id` = ' . $row->id));

        $row->notes = null;
        $this->assertTrue($row->save());

        $this->assertNull($this->value(
            'SELECT `notes` FROM `ost_test_row` WHERE `id` = ' . $row->id));
    }

    /* ------------------------------------------------------------------ */
    /* Recuento, filtros y orden                                           */
    /* ------------------------------------------------------------------ */

    public function testCuentaIgualQueElSqlAMano(): void {
        $this->seed();

        $this->assertSame(3, OrmIntegrationRow::objects()->count());
        $this->assertSame((int) $this->value(
            'SELECT COUNT(*) FROM `ost_test_row`'),
            OrmIntegrationRow::objects()->count());
    }

    public function testFiltroInDevuelveLasFilasPedidas(): void {
        $ids = $this->seed();

        $this->assertSame(2, OrmIntegrationRow::objects()
            ->filter(array('id__in' => array($ids[0], $ids[2])))->count());

        $nombres = OrmIntegrationRow::objects()
            ->filter(array('id__in' => array($ids[0], $ids[2])))
            ->values_flat('name');
        $vistos = array();
        foreach ($nombres as list($name))
            $vistos[] = $name;

        sort($vistos);
        // Los ids pedidos son el primero y el tercero: uno y tres
        $this->assertSame(array('tres', 'uno'), $vistos);
    }

    public function testOrdenaYLimita(): void {
        $this->seed();

        $vistos = array();
        foreach (OrmIntegrationRow::objects()->order_by('name')
                ->limit(2)->values_flat('name') as list($name))
            $vistos[] = $name;

        // El orden por defecto del modelo es `name`, así que sale igual
        $this->assertSame(array('dos', 'tres'), $vistos);
    }

    public function testElFiltroExcludeDejaFueraLoIndicado(): void {
        $ids = $this->seed();

        $this->assertSame(2, OrmIntegrationRow::objects()
            ->exclude(array('id' => $ids[1]))->count());
    }

    public function testFiltroOConGruposAnidados(): void {
        $this->seed();

        // Dos grupos unidos con OR, cada uno con sus condiciones unidas con
        // AND. Si los grupos se compilaran como campos sueltos, esto sería un
        // error de SQL en vez de dos coincidencias.
        $qs = OrmIntegrationRow::objects()->filter(Q::any(array(
            Q::all(array('name' => 'uno', 'qty' => 1)),
            Q::all(array('name' => 'dos', 'qty' => 2)),
            Q::all(array('name' => 'tres', 'qty' => 99)),
        )));

        $this->assertSame(2, $qs->count());
    }

    public function testFiltroNegado(): void {
        $ids = $this->seed();

        OrmIntegrationRow::objects()
            ->filter(array('id' => $ids[0]))
            ->update(array('flag' => 1));

        $this->assertSame(2, OrmIntegrationRow::objects()
            ->filter(Q::not(array('flag' => 1)))->count());
    }

    public function testFiltroContainsSobreTexto(): void {
        $this->seed();

        OrmIntegrationRow::objects()
            ->filter(array('name' => 'uno'))
            ->update(array('notes' => 'hola mundo'));

        $this->assertSame(1, OrmIntegrationRow::objects()
            ->filter(array('notes__contains' => 'mundo'))->count());
        // El guion bajo del patrón viaja escapado: no debe coincidir con
        // cualquier carácter.
        $this->assertSame(0, OrmIntegrationRow::objects()
            ->filter(array('notes__contains' => 'mundo_'))->count());
    }

    /* ------------------------------------------------------------------ */
    /* Modificación y borrado                                              */
    /* ------------------------------------------------------------------ */

    public function testActualizaEnBloqueYDevuelveLasFilasTocadas(): void {
        $ids = $this->seed();

        $tocadas = OrmIntegrationRow::objects()
            ->filter(array('id__in' => array($ids[0], $ids[1])))
            ->update(array('qty' => 9));

        $this->assertSame(2, $tocadas);
        $this->assertSame(2, (int) $this->value(
            'SELECT COUNT(*) FROM `ost_test_row` WHERE `qty` = 9'));
    }

    public function testActualizarSinCoincidenciasNoTocaNada(): void {
        $this->seed();

        $this->assertSame(0, OrmIntegrationRow::objects()
            ->filter(array('name' => 'no-existe'))->update(array('qty' => 1)));
    }

    public function testBorraUnaFila(): void {
        $ids = $this->seed();
        $row = OrmIntegrationRow::lookup($ids[0]);

        $this->assertTrue($row->delete());
        $this->assertSame(2, OrmIntegrationRow::objects()->count());
        $this->assertSame(0, (int) $this->value(
            'SELECT COUNT(*) FROM `ost_test_row` WHERE `id` = ' . $ids[0]));
    }

    public function testBorraEnBloque(): void {
        $this->seed();

        $this->assertSame(3, OrmIntegrationRow::objects()
            ->filter(array('qty__gte' => 0))->delete());
        $this->assertSame(0, OrmIntegrationRow::objects()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Valores que calcula la base                                         */
    /* ------------------------------------------------------------------ */

    public function testInsertaConLaFechaDeLaBase(): void {
        // El frente 3 sustituyó `updated=NOW()` en el SQL a mano por
        // `new SqlCode('NOW()')`: el valor tiene que ponerlo MariaDB, no PHP.
        $row = new OrmIntegrationRow(array('name' => 'con-fecha'));
        $row->stamp = new SqlCode('NOW()');

        $this->assertTrue($row->save());
        $this->assertNotNull($this->value(
            'SELECT `stamp` FROM `ost_test_row` WHERE `id` = ' . $row->id));

        // El valor que quedó en el modelo es el que devolvió la base
        $leida = OrmIntegrationRow::lookup($row->id);
        $this->assertNotNull($leida->stamp);
    }

    public function testActualizaEnBloqueConLaFechaDeLaBase(): void {
        $ids = $this->seed();

        $tocadas = OrmIntegrationRow::objects()
            ->filter(array('id__in' => $ids))
            ->update(array('stamp' => new SqlCode('NOW()')));

        $this->assertSame(3, $tocadas);
        $this->assertSame(3, (int) $this->value(
            'SELECT COUNT(*) FROM `ost_test_row` WHERE `stamp` IS NOT NULL'));
    }

    public function testActualizarDosVecesElMismoValorDevuelveCero(): void {
        // `update()` devuelve filas modificadas, no filas encontradas: asignar
        // el valor que ya había da 0 aunque el UPDATE se ejecute. Es lo mismo
        // que hacía `db_affected_rows()`, y por eso `setStatus()` ya no usa el
        // número para decidir si falló.
        $ids = $this->seed();

        $this->assertSame(3, OrmIntegrationRow::objects()
            ->filter(array('id__in' => $ids))
            ->update(array('qty' => 4)));
        $this->assertSame(0, OrmIntegrationRow::objects()
            ->filter(array('id__in' => $ids))
            ->update(array('qty' => 4)));
    }

    /* ------------------------------------------------------------------ */
    /* Esquema real                                                        */
    /* ------------------------------------------------------------------ */

    public function testElEsquemaDelInstaladorSeCarga(): void {
        // El esquema ya lo cargó IntegrationTestCase::setUp() con el mismo
        // install-mysql.sql del instalador.
        $this->assertTrue(TestDatabase::installed());
        $this->assertGreaterThan(40, (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE()'));

        // Una tabla conocida con sus columnas: si el esquema no se cargó
        // entero, esto no existe.
        $this->assertSame(1, (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = '
            . "'" . $this->table('ticket') . "'"));
    }

    /* ------------------------------------------------------------------ */

    /** Tres filas: dos, tres y uno por orden alfabético. Devuelve sus ids. */
    private function seed() {
        $ids = array();
        foreach (array(array('name' => 'uno', 'qty' => 1),
                array('name' => 'dos', 'qty' => 2),
                array('name' => 'tres', 'qty' => 3)) as $vars) {
            $row = new OrmIntegrationRow($vars);
            $this->assertTrue($row->save());
            $ids[] = $row->id;
        }

        return $ids;
    }
}
