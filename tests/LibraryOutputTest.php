<?php
/**
 * tests/LibraryOutputTest.php
 *
 * Un archivo de librería no puede escribir nada al cargarse.
 *
 * El fallo que motiva esta prueba: al repartir `class.dynamic_forms.php` en el
 * frente 6, el `include_once()` del archivo nuevo se añadió **después** del
 * `?>` final. En PHP eso no es código: es texto de salida. El resultado fue que
 * todas las páginas del portal —el login incluido— empezaban con la línea
 *
 *     /* Buscador de la lista de seleccion: ... *\/ include_once(INCLUDE_DIR...
 *
 * delante del `<!DOCTYPE>`. La suite no lo vio porque ninguna prueba renderiza
 * una página; se vio en una captura del navegador del usuario.
 *
 * Lo que se comprueba es **texto fuera de PHP a nivel superior** (T_INLINE_HTML
 * con profundidad de llaves cero): lo que está dentro de un método es el HTML
 * de una plantilla que se devuelve como cadena y no sale por pantalla. Por eso
 * el análisis cuenta llaves de verdad, con las de interpolación (`"{$x}"`)
 * tratadas como lo que son, y no con `substr_count()` sobre el texto.
 */

class LibraryOutputTest extends UnitTestCase {

    /**
     * Archivos que se revisan: las librerías que carga cualquier página.
     *
     * Se dejan fuera las plantillas (`.inc.php`, `.tmpl.php`) porque su trabajo
     * es justamente escribir HTML, y las librerías copiadas (`pear`, `mpdf`…).
     */
    private static function libraries() {
        $root = dirname(__DIR__) . '/include/';
        $files = array_merge(
            glob($root . '*.php') ?: array(),
            glob($root . 'cli/*.php') ?: array(),
            glob($root . 'cli/modules/*.php') ?: array()
        );
        sort($files);

        return $files;
    }

    /**
     * Las líneas de texto que el archivo escribe al cargarse.
     *
     * @return array  lista de array('line' => int, 'text' => string)
     */
    static function topLevelHtml($source) {
        $found = array();
        $depth = 0;

        foreach (@token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML && $depth === 0)
                $found[] = array('line' => $token[2], 'text' => trim($token[1]));

            // `"{$x}"` abre llave y se cierra con el token `}` de un carácter;
            // contar sólo caracteres de los tokens de texto descuadra la cuenta.
            if (is_array($token))
                $depth += in_array($token[0],
                    array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true) ? 1 : 0;
            else
                $depth += substr_count($token, '{') - substr_count($token, '}');
        }

        return $found;
    }

    /* ------------------------------------------------------------------ */
    /* El analizador                                                       */
    /* ------------------------------------------------------------------ */

    /** Texto después del `?>` final es salida: ni un espacio de más. */
    public function testDetectaTextoDespuesDelCierreDePhp(): void {
        $source = "<?php\nclass A {}\n?>\ninclude_once('x.php');\n";

        $found = self::topLevelHtml($source);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('include_once', $found[0]['text']);
    }

    /** Un archivo que sólo cierra PHP y termina no escribe nada. */
    public function testNoCuentaElSaltoDeLineaDelFinal(): void {
        $this->assertSame(array(), self::topLevelHtml("<?php\nclass A {}\n?>\n"));
    }

    /** El HTML dentro de un método es una plantilla, no salida. */
    public function testElHtmlDentroDeUnMetodoNoCuenta(): void {
        $source = "<?php\nclass A {\n    function render() {\n        ?>\n"
            . "        <div>hola</div>\n        <?php\n    }\n}\n";

        $this->assertSame(array(), self::topLevelHtml($source));
    }

    /** Y con la llave de interpolación por medio, tampoco. */
    public function testLaInterpolacionNoDescuadraLaCuenta(): void {
        $source = '<?php $a = "{$b}"; class C { function r() { ?>'
            . '<b>x</b><?php } } ?>' . "\n";

        $this->assertSame(array(), self::topLevelHtml($source));
    }

    /* ------------------------------------------------------------------ */
    /* La librería de verdad                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Ningún archivo de `include/` que no sea plantilla escribe nada.
     *
     * Es la comprobación que habría cazado el `include_once()` colocado detrás
     * del `?>`: se carga en cada página, así que su texto sale en todas.
     */
    public function testNingunaLibreriaEscribeAlCargarse(): void {
        $files = self::libraries();
        $this->assertGreaterThan(100, count($files),
            'no se encontraron los archivos de librería');

        $problemas = array();
        foreach ($files as $file) {
            foreach (self::topLevelHtml(file_get_contents($file)) as $found)
                $problemas[] = sprintf('%s:%d  %s',
                    basename($file), $found['line'],
                    substr($found['text'], 0, 70));
        }

        $this->assertSame(array(), $problemas,
            "estos archivos escriben texto al cargarse y lo verían todas las "
            . "páginas:\n  " . implode("\n  ", $problemas));
    }
}
