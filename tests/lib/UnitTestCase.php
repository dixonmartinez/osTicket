<?php
/**
 * tests/lib/UnitTestCase.php
 *
 * Clase base mínima con la misma API que PHPUnit para las comprobaciones que
 * usan las pruebas del proyecto. Existe para que `make unit` funcione sin
 * `composer install`: un clon recién bajado no tiene `vendor/` y no debería
 * necesitar red para comprobar que Format o Validator no han cambiado.
 *
 * Si PHPUnit está instalado, tests/bootstrap.php alía esta clase a la de
 * PHPUnit y este archivo no se carga.
 */
class UnitTestCase {

    private $expectedException = null;
    private $assertions = 0;

    /* ---------------------------------------------------------------- */
    /* Ejecución                                                        */
    /* ---------------------------------------------------------------- */

    protected function setUp(): void {
    }

    protected function tearDown(): void {
    }

    /**
     * Ejecuta un método de prueba, como haría PHPUnit.
     *
     * @return int número de comprobaciones realizadas
     * @throws AssertionFailed cuando una comprobación falla
     */
    public function runTest(string $method): int {
        $this->assertions = 0;
        $this->expectedException = null;
        $this->setUp();

        try {
            $this->{$method}();
        } catch (AssertionFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($this->expectedException
                    && $e instanceof $this->expectedException)
                return ++$this->assertions;

            throw $e;
        } finally {
            $this->tearDown();
        }

        if ($this->expectedException)
            $this->failure("se esperaba la excepción {$this->expectedException} y no se lanzó");

        return $this->assertions;
    }

    /* ---------------------------------------------------------------- */
    /* Comprobaciones                                                   */
    /* ---------------------------------------------------------------- */

    public function assertSame($expected, $actual, string $message = ''): void {
        $this->assertions++;

        if ($expected !== $actual)
            $this->failure(sprintf("%s\n        esperado: %s\n        recibido: %s",
                $message ?: 'los valores no son idénticos',
                $this->describe($expected), $this->describe($actual)));
    }

    public function assertEquals($expected, $actual, string $message = ''): void {
        $this->assertions++;

        if ($expected != $actual)
            $this->failure(sprintf("%s\n        esperado: %s\n        recibido: %s",
                $message ?: 'los valores no son iguales',
                $this->describe($expected), $this->describe($actual)));
    }

    public function assertTrue($condition, string $message = ''): void {
        $this->assertSame(true, $condition, $message ?: 'se esperaba true');
    }

    public function assertFalse($condition, string $message = ''): void {
        $this->assertSame(false, $condition, $message ?: 'se esperaba false');
    }

    public function assertNull($value, string $message = ''): void {
        $this->assertSame(null, $value, $message ?: 'se esperaba null');
    }

    public function assertNotNull($value, string $message = ''): void {
        $this->assertions++;

        if ($value === null)
            $this->failure($message ?: 'no se esperaba null');
    }

    public function assertCount(int $expected, $haystack, string $message = ''): void {
        $this->assertSame($expected, is_array($haystack) ? count($haystack)
            : iterator_count($haystack), $message ?: 'el número de elementos no coincide');
    }

    public function assertArrayHasKey($key, array $array, string $message = ''): void {
        $this->assertions++;

        if (!array_key_exists($key, $array))
            $this->failure($message ?: "no existe la clave '$key'");
    }

    public function assertStringContainsString(string $needle, string $haystack,
            string $message = ''): void {
        $this->assertions++;

        if (strpos($haystack, $needle) === false)
            $this->failure(($message ? $message . ': ' : '')
                . "'$needle' no aparece en '" . $haystack . "'");
    }

    public function assertMatchesRegularExpression(string $pattern, string $string,
            string $message = ''): void {
        $this->assertions++;

        if (!preg_match($pattern, $string))
            $this->failure(($message ? $message . ': ' : '')
                . "el texto '$string' no cumple $pattern");
    }

    public function assertGreaterThan($expected, $actual, string $message = ''): void {
        $this->assertions++;

        if (!($actual > $expected))
            $this->failure($message ?: "se esperaba algo mayor que {$expected}");
    }

    public function assertLessThan($expected, $actual, string $message = ''): void {
        $this->assertions++;

        if (!($actual < $expected))
            $this->failure($message ?: "se esperaba algo menor que {$expected}");
    }

    public function expectException(string $class): void {
        $this->expectedException = $class;
    }

    public function fail(string $message = ''): void {
        $this->failure($message);
    }

    /* ---------------------------------------------------------------- */
    /* Utilidades                                                       */
    /* ---------------------------------------------------------------- */

    private function failure(string $message): void {
        throw new AssertionFailed($message);
    }

    private function describe($value): string {
        if (is_string($value))
            return "'" . $value . "'";
        if (is_bool($value))
            return $value ? 'true' : 'false';
        if ($value === null)
            return 'null';
        if (is_array($value))
            return 'array(' . count($value) . ')';
        if (is_object($value))
            return get_class($value);

        return (string) $value;
    }
}

class AssertionFailed extends \Exception {
}
