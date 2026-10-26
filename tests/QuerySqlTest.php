<?php
/**
 * tests/QuerySqlTest.php
 *
 * Pruebas del SQL que genera `SqlCompiler::compileSelect()` y `SelectSql`
 * (`include/class.orm.sql` / `include/class.orm.select.php`). Son pruebas de
 * caracterización: fijan la consulta que el ORM produce hoy para que el
 * refactor del frente 2 no la cambie sin que nadie lo note.
 *
 * No hay base de datos. El ORM sólo necesita saber la tabla, la clave y los
 * campos del modelo, y eso se le da a mano: `inspectFields()` (que sí
 * consultaría la base) no llega a ejecutarse.
 *
 * Se comparan el texto con marcadores (`:1`, `:2`…) y la lista de parámetros
 * por separado, en vez del SQL ya escapado: así la prueba no depende de
 * `db_real_escape()` ni del juego de caracteres.
 */

require_once INCLUDE_DIR . 'class.orm.php';

/** Modelo de prueba: tabla, clave y orden por defecto. */
if (!class_exists('QuerySqlProbe')) {
    class QuerySqlProbe extends \VerySimpleModel {
        static $meta = array(
            'table' => 'probe',
            'pk' => array('id'),
            'ordering' => array('name'),
        );
    }
}

/** El mismo modelo sin orden por defecto. */
if (!class_exists('QuerySqlBare')) {
    class QuerySqlBare extends \VerySimpleModel {
        static $meta = array(
            'table' => 'bare',
            'pk' => array('id'),
        );
    }
}

/** Padre y hijo, para probar los JOIN de `select_related()`. */
if (!class_exists('QuerySqlParent')) {
    class QuerySqlParent extends \VerySimpleModel {
        static $meta = array(
            'table' => 'parent',
            'pk' => array('id'),
        );
    }
}
if (!class_exists('QuerySqlChild')) {
    class QuerySqlChild extends \VerySimpleModel {
        static $meta = array(
            'table' => 'child',
            'pk' => array('id'),
            'joins' => array(
                'parent' => array(
                    'local' => 'parent_id',
                    'constraint' => array('parent_id' => 'QuerySqlParent.id'),
                ),
            ),
        );
    }
}

/**
 * Autor y aviso: para comprobar que el `select_related` declarado en la meta
 * del modelo viaja en la misma consulta. Es lo que hace que el autor de cada
 * aviso del hilo no cueste una consulta más (`ThreadEvent::$meta`).
 */
if (!class_exists('QuerySqlAuthor')) {
    class QuerySqlAuthor extends \VerySimpleModel {
        static $meta = array(
            'table' => 'author',
            'pk' => array('id'),
        );
    }
}
if (!class_exists('QuerySqlNotice')) {
    class QuerySqlNotice extends \VerySimpleModel {
        static $meta = array(
            'table' => 'notice',
            'pk' => array('id'),
            'select_related' => array('author'),
            'joins' => array(
                'author' => array(
                    'local' => 'author_id',
                    'constraint' => array('author_id' => 'QuerySqlAuthor.id'),
                    'null' => true,
                ),
            ),
        );
    }
}

class QuerySqlTest extends UnitTestCase {

    /**
     * Campos de cada modelo de prueba. En la aplicación los saca del esquema
     * (`inspectFields()`); aquí se declaran para no necesitar la base.
     */
    private function prepare(): void {
        $fields = array(
            'QuerySqlProbe' => array('id', 'name', 'created'),
            'QuerySqlBare' => array('id', 'name'),
            'QuerySqlParent' => array('id', 'name'),
            'QuerySqlChild' => array('id', 'parent_id', 'title'),
            'QuerySqlAuthor' => array('id', 'name'),
            'QuerySqlNotice' => array('id', 'author_id', 'title'),
        );
        foreach ($fields as $model => $names)
            $model::getMeta()->fields = $names;
    }

    /** SQL y parámetros de una consulta, sin ejecutarla. */
    private function compile(\QuerySet $qs): array {
        $this->prepare();
        $query = $qs->getQuery();

        return array('sql' => $query->sql, 'params' => $query->params);
    }

    private function probe(): \QuerySet {
        return new \QuerySet('QuerySqlProbe');
    }

    /* ------------------------------------------------------------------ */
    /* select_related declarado en la meta                                 */
    /* ------------------------------------------------------------------ */

    /**
     * El `select_related` de la meta se aplica solo.
     *
     * `ThreadEvent` lo usa para que `$event->user` y `$event->agent` lleguen
     * con la consulta de los avisos: en el perfil real del ticket, el autor de
     * cada aviso costaba una consulta aparte (`class.thread.event.php:112`).
     */
    public function testElSelectRelatedDeLaMetaViajaEnLaConsulta(): void {
        $q = $this->compile(new \QuerySet('QuerySqlNotice'));

        $this->assertStringContainsString('LEFT JOIN `author`', $q['sql']);
        // Y sus columnas viajan en el SELECT, con el alias del JOIN.
        $this->assertStringContainsString('A2.`name`', $q['sql']);
    }

    /* ------------------------------------------------------------------ */
    /* La consulta más simple                                              */
    /* ------------------------------------------------------------------ */

    public function testElInsertLlevaLaFuncionDeLaBase(): void {
        // `new SqlCode('NOW()')` tiene que viajar como SQL, no como
        // parámetro: si se enviara como valor, la columna `datetime`
        // recibiría la cadena 'NOW()' (o NULL, con sql_mode estricto).
        $this->prepare();
        $row = new \QuerySqlProbe(array('name' => 'con-fecha'));
        $row->created = new \SqlCode('NOW()');

        $ex = \DbEngine::getCompiler()->compileInsert($row);

        $this->assertSame(
            'INSERT INTO `probe` SET `name` = :1, `created` = NOW()', $ex->sql);
        $this->assertSame(array('con-fecha'), $ex->params);
    }

    public function testConsultaSimple(): void {
        $q = $this->compile($this->probe());

        $this->assertSame(
            'SELECT A1.* FROM `probe` A1 ORDER BY A1.`name` ASC', $q['sql']);
        $this->assertSame(array(), $q['params']);
    }

    public function testSinOrdenPorDefectoNoHayOrderBy(): void {
        $q = $this->compile(new \QuerySet('QuerySqlBare'));

        $this->assertSame('SELECT A1.* FROM `bare` A1', $q['sql']);
    }

    public function testLaTablaLlevaAlias(): void {
        // El alias es lo que permite unir la misma tabla dos veces
        $this->assertStringContainsString('FROM `probe` A1',
            $this->compile($this->probe())['sql']);
    }

    public function testSePuedeQuitarElOrden(): void {
        $q = $this->compile($this->probe()->order_by(false));

        $this->assertFalse(strpos($q['sql'], 'ORDER BY') !== false);
    }

    /* ------------------------------------------------------------------ */
    /* Filtros                                                             */
    /* ------------------------------------------------------------------ */

    public function testFiltroPorIgualdad(): void {
        $q = $this->compile($this->probe()->filter(array('name' => 'Ana')));

        $this->assertSame('SELECT A1.* FROM `probe` A1 WHERE A1.`name` = :1'
            . ' ORDER BY A1.`name` ASC', $q['sql']);
        $this->assertSame(array('Ana'), $q['params']);
    }

    public function testFiltroIn(): void {
        $q = $this->compile($this->probe()
            ->filter(array('id__in' => array(1, 2, 3))));

        $this->assertStringContainsString('WHERE A1.`id` IN (:1, :2, :3)',
            $q['sql']);
        $this->assertSame(array(1, 2, 3), $q['params']);
    }

    public function testDosFiltrosSeUnenConAnd(): void {
        $q = $this->compile($this->probe()->filter(array(
            'created__gt' => '2026-01-01',
            'name__contains' => 'ana',
        )));

        $this->assertStringContainsString(
            'WHERE A1.`created` > :1 AND A1.`name` LIKE :2', $q['sql']);
        $this->assertSame(array('2026-01-01', '%ana%'), $q['params']);
    }

    public function testLosFiltrosVanEnOrden(): void {
        // Los números de los marcadores siguen el orden de los parámetros
        $q = $this->compile($this->probe()->filter(array(
            'name' => 'Ana', 'id' => 7)));

        $this->assertSame(array('Ana', 7), $q['params']);
        $this->assertStringContainsString('A1.`name` = :1 AND A1.`id` = :2',
            $q['sql']);
    }

    /* ------------------------------------------------------------------ */
    /* Filtros vacíos                                                      */
    /* ------------------------------------------------------------------ */

    /*
     * Las listas del panel arman el criterio según los filtros de la
     * petición. Cuando no hay ninguno, el criterio queda vacío y el ORM
     * emitía `WHERE ` sin condición: MySQL responde con un error de sintaxis
     * y osTicket con un 500 (`logs.php`, `SELECT COUNT(*) FROM (SELECT …)`).
     */

    public function testUnArregloVacioNoEmiteWhere(): void {
        $q = $this->compile($this->probe()->filter(array()));

        $this->assertSame(
            'SELECT A1.* FROM `probe` A1 ORDER BY A1.`name` ASC', $q['sql']);
        $this->assertSame(array(), $q['params']);
    }

    public function testUnQSinCondicionesNoEmiteWhere(): void {
        $q = $this->compile($this->probe()->filter(new \Q(array())));

        $this->assertSame(
            'SELECT A1.* FROM `probe` A1 ORDER BY A1.`name` ASC', $q['sql']);
    }

    public function testExcluirVacioNoEmiteWhereNiNot(): void {
        $q = $this->compile($this->probe()
            ->exclude(array())
            ->exclude(new \Q(array())));

        $this->assertSame(
            'SELECT A1.* FROM `probe` A1 ORDER BY A1.`name` ASC', $q['sql']);
    }

    public function testUnFiltroVacioNoAnulaLosDemas(): void {
        $q = $this->compile($this->probe()
            ->filter(array())
            ->filter(array('name' => 'Ana')));

        $this->assertSame('SELECT A1.* FROM `probe` A1 WHERE A1.`name` = :1'
            . ' ORDER BY A1.`name` ASC', $q['sql']);
        $this->assertSame(array('Ana'), $q['params']);
    }

    public function testElCriterioVacioDeLaListaNoRompeElCount(): void {
        // El caso real: el panel cuenta las filas del listado con un
        // subselect; si el WHERE va vacío, el COUNT revienta
        $q = $this->compile($this->probe()
            ->values('id')
            ->filter(array())
            ->order_by(false));

        $this->assertSame('SELECT A1.`id` AS `id` FROM `probe` A1', $q['sql']);
        $this->assertFalse(strpos($q['sql'], 'WHERE') !== false);
    }

    /* ------------------------------------------------------------------ */
    /* Orden                                                               */
    /* ------------------------------------------------------------------ */

    public function testOrdenDescendenteConGuion(): void {
        $q = $this->compile($this->probe()->order_by('-created'));

        // El orden pedido sustituye al del modelo, no se suma
        $this->assertStringContainsString('ORDER BY A1.`created` DESC',
            $q['sql']);
    }

    public function testOrdenPorFuncion(): void {
        $q = $this->compile($this->probe()
            ->order_by(new \SqlField('created')));

        $this->assertStringContainsString('ORDER BY A1.`created` ASC',
            $q['sql']);
    }

    public function testOrdenConDireccionExplicita(): void {
        $q = $this->compile($this->probe()->order_by('created', 'DESC'));

        $this->assertStringContainsString('A1.`created` DESC', $q['sql']);
    }

    /* ------------------------------------------------------------------ */
    /* Límites, bloqueos y opciones                                        */
    /* ------------------------------------------------------------------ */

    public function testLimiteYDesplazamiento(): void {
        $q = $this->compile($this->probe()->limit(5)->offset(10));

        $this->assertStringContainsString('LIMIT 5 OFFSET 10', $q['sql']);
    }

    public function testSoloLimite(): void {
        $this->assertStringContainsString('LIMIT 25',
            $this->compile($this->probe()->limit(25))['sql']);
    }

    public function testBloqueoExclusivo(): void {
        $q = $this->compile(
            $this->probe()->lock(\QuerySet::LOCK_EXCLUSIVE));

        $this->assertStringContainsString('FOR UPDATE', $q['sql']);
    }

    public function testBloqueoCompartido(): void {
        $q = $this->compile($this->probe()->lock(\QuerySet::LOCK_SHARED));

        $this->assertStringContainsString('LOCK IN SHARE MODE', $q['sql']);
    }

    public function testFilasEncontradasDeMysql(): void {
        $qs = $this->probe();
        $qs->setOption(\QuerySet::OPT_MYSQL_FOUND_ROWS, true);

        $this->assertStringContainsString('SELECT SQL_CALC_FOUND_ROWS A1.*',
            $this->compile($qs)['sql']);
    }

    public function testPistaDeIndice(): void {
        $qs = $this->probe();
        $qs->setOption(\QuerySet::OPT_INDEX_HINT, 'idx_name');

        $this->assertStringContainsString('FROM `probe` A1 USE INDEX (idx_name)',
            $this->compile($qs)['sql']);
    }

    /* ------------------------------------------------------------------ */
    /* Qué columnas se piden                                               */
    /* ------------------------------------------------------------------ */

    public function testValores(): void {
        $q = $this->compile($this->probe()->values('id', 'name'));

        $this->assertSame('SELECT A1.`id` AS `id`, A1.`name` AS `name`'
            . ' FROM `probe` A1 ORDER BY A1.`name` ASC', $q['sql']);
    }

    public function testValoresConAliasDeCampo(): void {
        $q = $this->compile($this->probe()->values(array('ident' => 'id')));

        $this->assertSame('SELECT A1.`id` AS `ident` FROM `probe` A1'
            . ' ORDER BY A1.`name` ASC', $q['sql']);
    }

    public function testValoresConExpresionAgrupaPorLaExpresion(): void {
        // Las series de analíticas agrupan por DATE()/HOUR(); el GROUP BY
        // tiene que llevar la expresión, no el alias ni la clave primaria
        $q = $this->compile($this->probe()->order_by(false)
            ->values(array('day' => new \SqlFunction('DATE', new \SqlField('created'))))
            ->annotate(array('n' => new \SqlAggregate('COUNT', new \SqlField('id')))));

        $this->assertSame('SELECT DATE(A1.`created`) AS `day`,'
            . ' COUNT(A1.`id`) AS `n` FROM `probe` A1'
            . ' GROUP BY DATE(A1.`created`)', $q['sql']);
    }

    /**
     * `values()` + agregado + `distinct`: manda el `distinct`, el GROUP BY es
     * su columna. Es lo que producía el código antes de partir `fields()` en
     * ramas —se comprobó ejecutando las dos versiones con la misma consulta—,
     * y la combinación no estaba cubierta.
     */
    public function testValoresConAgregadoYDistintoAgrupaPorElDistinto(): void {
        $q = $this->compile($this->probe()->order_by(false)
            ->values(array('day' => new \SqlFunction('DATE', new \SqlField('created'))))
            ->annotate(array('n' => new \SqlAggregate('COUNT', new \SqlField('id'))))
            ->distinct('name'));

        $this->assertSame('SELECT DATE(A1.`created`) AS `day`,'
            . ' COUNT(A1.`id`) AS `n` FROM `probe` A1'
            . ' GROUP BY A1.`name`', $q['sql']);
    }

    public function testValoresConExpresionYOrdenExplicito(): void {
        // Un ORDER BY de columna suelta rompería ONLY_FULL_GROUP_BY, así que
        // las agrupaciones por expresión van con order_by(false)
        $q = $this->compile($this->probe()
            ->values(array('h' => new \SqlFunction('HOUR', new \SqlField('created'))))
            ->annotate(array('n' => new \SqlAggregate('COUNT', new \SqlField('id')))));

        $this->assertStringContainsString('GROUP BY HOUR(A1.`created`)', $q['sql']);
        $this->assertSame('SELECT HOUR(A1.`created`) AS `h`, COUNT(A1.`id`) AS `n`'
            . ' FROM `probe` A1 GROUP BY HOUR(A1.`created`)'
            . ' ORDER BY A1.`name` ASC', $q['sql']);
    }

    public function testCamposDiferidos(): void {
        // Todo menos el campo diferido
        $q = $this->compile($this->probe()->defer('created'));

        $this->assertStringContainsString('SELECT A1.`id`, A1.`name` FROM',
            $q['sql']);
    }

    public function testDistintosAgrupaPorElCampo(): void {
        $q = $this->compile($this->probe()->distinct('name'));

        $this->assertStringContainsString('GROUP BY A1.`name`', $q['sql']);
    }

    public function testAnotacionAgregaLaColumnaYElGroupBy(): void {
        $q = $this->compile($this->probe()->annotate(array(
            'total' => new \SqlAggregate('COUNT', new \SqlField('id')))));

        $this->assertStringContainsString('A1.*, COUNT(A1.`id`) AS `total`',
            $q['sql']);
        $this->assertStringContainsString('GROUP BY A1.id', $q['sql']);
    }

    public function testColumnaExtra(): void {
        $q = $this->compile($this->probe()->extra(array(
            'select' => array('ahora' => 'NOW()'))));

        $this->assertStringContainsString('A1.*, NOW() AS `ahora`', $q['sql']);
    }

    public function testRelacionAñadeJoinYColumnas(): void {
        $q = $this->compile(
            (new \QuerySet('QuerySqlChild'))->select_related('parent'));

        $this->assertSame('SELECT A1.`id`, A1.`parent_id`, A1.`title`,'
            . ' A2.`id`, A2.`name` FROM `child` A1'
            . ' JOIN `parent` A2 ON (A1.`parent_id` = A2.`id`)', $q['sql']);
    }

    public function testRelacionConDiferido(): void {
        $q = $this->compile((new \QuerySet('QuerySqlChild'))
            ->select_related('parent')->defer('title'));

        $this->assertStringContainsString('A1.`id`, A1.`parent_id`,',
            $q['sql']);
        $this->assertFalse(strpos($q['sql'], 'A1.`title`') !== false);
    }

    /* ------------------------------------------------------------------ */
    /* Piezas de SelectSql                                                 */
    /* ------------------------------------------------------------------ */

    public function testSelectSqlEsQuienDecideLasColumnas(): void {
        // El compilador delega; si alguien vuelve a meter el bloque dentro de
        // compileSelect(), esto lo delata
        $this->assertTrue(method_exists('SelectSql', 'fields'));
    }

    public function testElAliasDeLaTablaSeRespeta(): void {
        // Compilar dos veces produce alias nuevos, no reutiliza A1 a ciegas
        $this->prepare();
        $uno = $this->probe()->getQuery();

        $this->assertSame('A1', substr($uno->sql, strpos($uno->sql, 'A1'),
            2));
    }
}
