<?php
/**
 * tests/lib/IntegrationTestCase.php
 *
 * Base de las pruebas que necesitan una base de datos de verdad.
 *
 * Se separan de las unitarias por una razón práctica: `make unit` tiene que
 * seguir corriendo en cualquier sitio, sin Docker, sin red y sin base de
 * datos. Estas viven en `tests/integration/` y se ejecutan con
 * `make integration` (o `make docker-integration`, que levanta MariaDB).
 *
 * Si no hay base de datos, la prueba se **omite con el motivo**. Con
 * `--require-db` (`make integration`) en cambio **falla**: si has pedido
 * integración y no hay base, un verde silencioso sería peor que un error.
 */
abstract class IntegrationTestCase extends UnitTestCase {

    /** ¿Se ha pedido la ejecución estricta? */
    static function required() {
        return defined('OST_TEST_REQUIRE_DB') && OST_TEST_REQUIRE_DB;
    }

    protected function setUp(): void {
        try {
            TestDatabase::schema();
        }
        catch (RuntimeException $e) {
            if (self::required())
                throw new RuntimeException('prueba de integración sin base de '
                    . 'datos: ' . $e->getMessage());

            $this->markTestSkipped('sin base de datos: ' . $e->getMessage());
        }

        $this->beforeEach();
    }

    /** Para lo que cada prueba tenga que preparar después de conectar. */
    protected function beforeEach(): void {
    }

    /** Ejecuta SQL y devuelve el resultado. */
    protected function query($sql) {
        return TestDatabase::query($sql);
    }

    /** Primera columna de la primera fila. */
    protected function value($sql, $default = null) {
        return TestDatabase::value($sql, $default);
    }

    /** Filas como arrays asociativos. */
    protected function rows($sql) {
        return TestDatabase::rows($sql);
    }

    /** Nombre real (con prefijo) de una tabla de la aplicación. */
    protected function table($name) {
        return TestDatabase::table($name);
    }
}
