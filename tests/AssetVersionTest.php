<?php
/**
 * tests/AssetVersionTest.php
 *
 * Las hojas y los scripts propios se piden con una versión en la URL.
 *
 * El fallo que motiva esta prueba: nginx sirve los estáticos con
 * `expires 7d` (`docker/nginx/default.conf`), así que el navegador **no vuelve
 * a preguntar** en una semana. Tras un `git pull`, el HTML nuevo se pintaba
 * con el CSS viejo y el síntoma era el peor posible: media tanda de cambios de
 * interfaz «no se veía». No era que no se aplicaran: era la caché.
 *
 * `GIT_VERSION` no sirve como versión: en el árbol es la cadena literal
 * `'$git'` y no cambia. Lo que cambia cuando un archivo cambia es su
 * `filemtime()`.
 */

class AssetVersionTest extends UnitTestCase {

    /** Los tres encabezados y los dos pies que cargan recursos propios. */
    private static $templates = array(
        'include/staff/header.inc.php',
        'include/client/header.inc.php',
        'include/staff/login.header.php',
        'include/staff/footer.inc.php',
        'include/client/footer.inc.php',
    );

    /* ------------------------------------------------------------------ */
    /* Http::asset()                                                       */
    /* ------------------------------------------------------------------ */

    /** Un archivo que existe lleva `?v=` con su fecha de modificación. */
    public function testLaVersionEsLaFechaDelArchivo(): void {
        $url = \Http::asset('css/modern.css');
        $mtime = filemtime(ROOT_DIR . '/css/modern.css');

        $this->assertSame('css/modern.css?v=' . $mtime, ltrim($url, '/'),
            'Http::asset debe versionar con filemtime, no con GIT_VERSION');
    }

    /** Y cambia cuando el archivo cambia: es lo que rompe la caché. */
    public function testLaVersionCambiaConElArchivo(): void {
        $first = \Http::asset('css/ui.css');

        $copy = sys_get_temp_dir() . '/ost-asset-' . getmypid() . '.css';
        $this->assertTrue(copy(ROOT_DIR . '/css/ui.css', $copy));
        touch($copy, filemtime(ROOT_DIR . '/css/ui.css') + 60);

        // Se simula el cambio comparando la versión con la fecha retocada
        $this->assertTrue(strpos($first, '?v=' . filemtime(ROOT_DIR . '/css/ui.css')) !== false);
        $this->assertSame(false, strpos($first, '?v=' . (filemtime(ROOT_DIR . '/css/ui.css') - 1)));

        @unlink($copy);
    }

    /**
     * GIT_VERSION no puede ser la versión: en el árbol es la cadena literal
     * `'$git'` (bootstrap.php) y no cambia al actualizar el código. Aquí la
     * suite lo redefine como `'tests'`, así que se comprueba que la versión
     * **no** sale de esa constante, sea cual sea su valor.
     */
    public function testNoSeUsaGitVersion(): void {
        $url = \Http::asset('css/ui.css');

        $this->assertSame(false, strpos($url, GIT_VERSION),
            'la versión del recurso no puede ser GIT_VERSION');
        $this->assertTrue(strpos($url, '?v=' . filemtime(ROOT_DIR . '/css/ui.css')) !== false);
        $this->assertStringContainsString('$git', file_get_contents(ROOT_DIR . '/bootstrap.php'),
            'bootstrap.php sigue definiendo GIT_VERSION como $git');
    }

    /** Lo que no está en disco se devuelve tal cual: mejor sin versión que roto. */
    public function testUnRecursoQueNoExisteSeDevuelveSinVersion(): void {
        $this->assertSame('css/no-existe-jamas.css',
            ltrim(\Http::asset('css/no-existe-jamas.css'), '/'));
    }

    /** El prefijo de la URL es configurable (portal con ROOT_PATH propio). */
    public function testAceptaOtroPrefijo(): void {
        $url = \Http::asset('css/ui.css', '/support/');

        $this->assertStringContainsString('/support/css/ui.css?v=', $url);
    }

    /** Y no duplica barras si la ruta llega con barra inicial. */
    public function testNoDuplicaLaBarraInicial(): void {
        $this->assertSame(false, strpos(\Http::asset('/css/ui.css', '/x/'), '/x//'));
    }

    /* ------------------------------------------------------------------ */
    /* Las plantillas                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Ningún recurso propio se carga sin versión: buscar la forma antigua
     * (`echo ROOT_PATH` pegado a la ruta) tiene que dar cero.
     */
    public function testNingunaPlantillaCargaUnRecursoSinVersion(): void {
        $problemas = array();
        foreach (self::$templates as $file) {
            $source = file_get_contents(ROOT_DIR . '/' . $file);

            if (preg_match_all('/<\?php echo ROOT_PATH;? \?>\s*[^"\s]+\.(?:css|js)/',
                    $source, $m))
                $problemas[] = $file . ': ' . implode(', ', $m[0]);

            // Y cada hoja/script propio de esas plantillas pasa por Http::asset
            $this->assertStringContainsString('Http::asset(', $source,
                "$file no versiona sus recursos");
        }

        $this->assertSame(array(), $problemas,
            "estas plantillas cargan recursos sin versión (el navegador los "
            . "guardará 7 días):\n  " . implode("\n  ", $problemas));
    }

    /** Los cinco incluyen class.http.php por el arranque, no por sí mismos. */
    public function testHttpEstaDisponibleAntesDeLasPlantillas(): void {
        $bootstrap = file_get_contents(ROOT_DIR . '/bootstrap.php');

        $this->assertStringContainsString('class.http.php', $bootstrap,
            'bootstrap.php debe cargar class.http.php (Http::asset se usa en '
            . 'los encabezados)');
    }

    /* ------------------------------------------------------------------ */
    /* El service worker                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Sin red, la copia precacheada no tiene el `?v=`: el service worker
     * tiene que saber encontrarla por ruta.
     */
    public function testElServiceWorkerEncuentraLaCopiaSinVersion(): void {
        $sw = file_get_contents(ROOT_DIR . '/sw.js');

        $this->assertStringContainsString('cache.match(url.pathname)', $sw,
            'sw.js debe buscar la copia por ruta cuando la URL lleva ?v=');
    }
}
