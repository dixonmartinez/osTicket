<?php
/**
 * tests/EntryPointTest.php
 *
 * Pruebas de las 21 páginas públicas de la raíz (`index.php`, `open.php`,
 * `tickets.php`, …). No hay base de datos ni sesión: se revisa el código
 * fuente, que es donde estaban los defectos que se corrigieron.
 *
 * Lo que se fija aquí:
 *   - los 21 archivos siguen siendo los mismos y todos parsean;
 *   - están en UTF-8 sin BOM y con finales de línea uniformes;
 *   - ninguna página manda una cabecera `Location` a mano: `Http::redirect()`
 *     termina la petición, `header()` no;
 *   - el captcha se sirve como imagen y sin caché;
 *   - los enlaces de acceso usan el parámetro `t` que leen;
 *   - la descarga de archivos reconoce al cliente con sesión;
 *   - las inclusiones internas no dependen del directorio de trabajo;
 *   - `bootstrap.php` no volvió a acumular código muerto.
 */

class EntryPointTest extends UnitTestCase {

    /**
     * Las páginas de la raíz. Añadir una obliga a pasar por esta prueba: el
     * arranque de osTicket es el sitio más fácil para colar un fallo que
     * afecta a todo el mundo.
     */
    private static $pages = array(
        'account.php', 'ajax.php', 'avatar.php', 'bootstrap.php',
        'captcha.php', 'client.inc.php', 'file.php', 'index.php',
        'login.php', 'logo.php', 'logout.php', 'main.inc.php',
        'manage.php', 'manifest.php', 'offline.php', 'open.php',
        'profile.php', 'pwreset.php', 'secure.inc.php', 'tickets.php',
        'view.php',
    );

    private function path(string $name): string {
        return ROOT_DIR . '/' . $name;
    }

    private function source(string $name): string {
        $path = $this->path($name);
        $this->assertTrue(is_file($path), "falta $name");

        return (string) file_get_contents($path);
    }

    /**
     * Cuando la prueba de los finales de línea falla, el arreglo es un
     * objetivo del Makefile: si alguien lo quita, el mensaje de la prueba
     * mandaría a un comando que no existe.
     */
    public function testElArregloDeLosFinalesDeLineaEstaEnElMakefile(): void {
        $makefile = (string) file_get_contents(ROOT_DIR . '/Makefile');

        $this->assertTrue(strpos($makefile, 'fix-eol: ##') !== false,
            'falta el objetivo fix-eol en el Makefile');
        $this->assertTrue(strpos($makefile, 'git ls-files --eol') !== false,
            'fix-eol debe preguntar a git qué archivos están en CRLF');
        // Sin esta comprobación, `make fix-eol` borraría cambios de verdad.
        $this->assertTrue(strpos($makefile, '--ignore-cr-at-eol') !== false,
            'fix-eol debe comprobar que sólo cambian los finales de línea');
    }

    /* ------------------------------------------------------------------ */
    /* Integridad de los archivos                                          */
    /* ------------------------------------------------------------------ */

    public function testSiguenSiendoLasMismasPaginas(): void {
        $found = array_map('basename', glob(ROOT_DIR . '/*.php') ?: array());
        sort($found);
        $expected = self::$pages;
        sort($expected);

        // `tests/run.php` vive en tests/, así que aquí sólo salen las páginas
        $this->assertSame($expected, $found);
    }

    public function testTodasLasPaginasParsean(): void {
        if (!function_exists('token_get_all'))
            $this->markTestSkipped('token_get_all no está disponible');

        $errors = array();
        foreach (self::$pages as $name) {
            try {
                // TOKEN_PARSE analiza la sintaxis sin ejecutar nada
                token_get_all($this->source($name), TOKEN_PARSE);
            }
            catch (ParseError $ex) {
                $errors[] = $name . ': ' . $ex->getMessage();
            }
        }

        $this->assertSame(array(), $errors, implode('; ', $errors));
    }

    public function testSinBomYConFinalesDeLineaUniformes(): void {
        $problems = array();

        foreach (self::$pages as $name) {
            $src = $this->source($name);

            if (substr($src, 0, 3) === "\xEF\xBB\xBF")
                $problems[] = "$name empieza con BOM";

            if ($crlf = substr_count($src, "\r\n"))
                $problems[] = sprintf('%s tiene %d líneas con CRLF', $name, $crlf);
        }

        // Se informan todas de una vez, con la cuenta de líneas: cuando el
        // archivo lo guardó un editor desde Windows, saber si son dos líneas o
        // el archivo entero es la diferencia entre un retoque y volver a
        // sacarlo de git. Y `git ls-files --eol` distingue las dos causas.
        $this->assertSame(array(), $problems, implode("; ", $problems)
            . ". Los archivos del repositorio están en LF: ejecuta `make "
            . "fix-eol` y los vuelve a dejar como están en git (se salta los "
            . "que tengan cambios propios). Si sale el árbol entero, revisa "
            . "`git config core.autocrlf`");
    }

    /* ------------------------------------------------------------------ */
    /* Redirecciones y respuestas                                          */
    /* ------------------------------------------------------------------ */

    public function testNingunaPaginaMandaLocationAMano(): void {
        // Una cabecera suelta no detiene el script: la página se sigue
        // ejecutando (y a veces intenta mandar cabeceras después de la
        // salida). `Http::redirect()` manda la cabecera y termina.
        $raw = array();
        foreach (self::$pages as $name) {
            if (preg_match('/^\s*@?header\s*\(\s*[\'"]Location/im',
                    $this->source($name), $m))
                $raw[] = $name;
        }

        $this->assertSame(array(), $raw, implode(', ', $raw));
    }

    public function testElCaptchaSeSirveComoImagenYNoSeCachea(): void {
        $src = $this->source('captcha.php');

        $this->assertStringContainsString("header('Content-Type: image/png')", $src);
        $this->assertStringContainsString('no-store', $src);
        $this->assertStringContainsString('Cache-Control', $src);
    }

    /* ------------------------------------------------------------------ */
    /* Regresiones concretas                                               */
    /* ------------------------------------------------------------------ */

    public function testElEnlaceDeAccesoUsaElParametroQueLee(): void {
        // Antes comprobaba `id` y redirigía con `t`: el enlace del correo no
        // llevaba al ticket
        $src = $this->source('view.php');

        $this->assertStringContainsString("isset(\$_GET['t'])", $src);
        $this->assertStringContainsString("'tickets.php?id='.\$_GET['t']", $src);
        $this->assertFalse(strpos($src, "\$_GET['id']") !== false);
    }

    public function testLaDescargaDeArchivosVeAlClienteConSesion(): void {
        // `$thisuser` no existe en el arranque del cliente: la condición daba
        // siempre verdadero y un cliente con sesión volvía al login
        $src = $this->source('file.php');

        $this->assertFalse(strpos($src, '$thisuser') !== false);
        $this->assertStringContainsString('&& !$thisclient', $src);
    }

    public function testLasInclusionesInternasNoDependenDelDirectorio(): void {
        // `require('client.inc.php')` se resuelve contra el directorio de
        // trabajo, que no tiene por qué ser la raíz (p. ej. `kb/faq.php`)
        $this->assertStringContainsString('dirname(__FILE__)',
            $this->source('secure.inc.php'));
        $this->assertStringContainsString("require(__DIR__.'/bootstrap.php')",
            $this->source('main.inc.php'));
        $this->assertStringContainsString("ROOT_DIR.'profile.php'",
            $this->source('client.inc.php'));
    }

    public function testElBootstrapNoAcumulaCodigoMuerto(): void {
        $bootstrap = $this->source('bootstrap.php');

        // El relleno de exif_imagetype estaba copiado dos veces
        $this->assertSame(1, substr_count($bootstrap, 'function exif_imagetype'));
        // Directivas y constantes que no existen en PHP 8
        $this->assertFalse(
            strpos($bootstrap, "ini_get('register_globals')") !== false);
        $this->assertFalse(strpos($bootstrap, "define('PATH_SEPARATOR'") !== false);
        $this->assertFalse(strpos($bootstrap, 'phpversion() < 7') !== false);
        // La limpieza de «magic quotes» se fue de main.inc.php
        $this->assertFalse(strpos($this->source('main.inc.php'),
            'get_magic_quotes_gpc') !== false);
    }
}
