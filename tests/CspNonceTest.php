<?php
/**
 * tests/CspNonceTest.php
 *
 * Pruebas del nonce de la CSP (OST-102, fila 1b de la lista de pendientes de
 * docs/UI_GUIDE.md).
 *
 * Lo que se fija aquí:
 *   - el nonce tiene forma de nonce y no cambia dentro de una petición;
 *   - `script-src` sale con `'nonce-…'` y **sin** `'unsafe-inline'`;
 *   - `Http::nonceScripts()` añade el atributo a lo que se arma en PHP;
 *   - la auditoría `tools/quality/csp-nonce.php` reconoce un `<script>` en
 *     línea sin nonce (si no, el cero de las plantillas no significaría nada).
 */

require_once dirname(__DIR__) . '/tools/quality/csp-nonce.php';

class CspNonceTest extends UnitTestCase {

    public function setUp(): void {
        Http::resetNonce();
    }

    /** Directiva `script-src` de un CSP, ya separada del resto. */
    private function scriptDirective($csp): string {
        foreach (explode(';', $csp) as $directive) {
            $directive = trim($directive);
            if (strpos($directive, 'script-src ') === 0)
                return $directive;
        }
        return '';
    }

    public function testElNonceTieneFormaDeNonce(): void {
        // Base64 sin relleno: lo que admite la especificación de CSP
        $this->assertTrue((bool) preg_match('/^[A-Za-z0-9_\-]{20,}$/', Http::nonce()));
    }

    public function testElNonceNoCambiaDentroDeLaPeticion(): void {
        $this->assertSame(Http::nonce(), Http::nonce());
        $this->assertSame(Http::nonceAttr(), 'nonce="' . Http::nonce() . '"');
    }

    public function testUnNonceNuevoEsDistinto(): void {
        $first = Http::nonce();
        Http::resetNonce();

        $this->assertTrue($first !== Http::nonce());
    }

    public function testLaCspLlevaElNonceYSinInline(): void {
        $script = $this->scriptDirective(Http::csp());

        $this->assertStringContainsString("'self'", $script);
        $this->assertStringContainsString("'nonce-" . Http::nonce() . "'", $script);
        $this->assertFalse(strpos($script, 'unsafe-inline') !== false);
    }

    public function testLaCspAceptaUnNonceExplicito(): void {
        $script = $this->scriptDirective(Http::csp(null, ['nonce' => 'abc123']));

        $this->assertStringContainsString("'nonce-abc123'", $script);
    }

    public function testLaCspConservaElEstiloEnLinea(): void {
        // Quitar `'unsafe-inline'` de los estilos es otro trabajo: los
        // atributos `style=` son cientos y no ejecutan código
        $csp = Http::csp();

        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
    }

    public function testSinNonceSeVuelveAlComportamientoAnterior(): void {
        // Salida de emergencia para instalaciones con plantillas propias
        $script = $this->scriptDirective(Http::csp(null, ['nonce' => false]));

        $this->assertStringContainsString("'unsafe-inline'", $script);
        $this->assertFalse(strpos($script, "'nonce-") !== false);
    }

    public function testElPanelYaNoUsaEval(): void {
        // OST-102, fase 3: el único eval() del panel era el del selector de
        // fecha y hora (`js/jquery-ui-timepicker-addon.js`), ya sustituido
        // por JSON.parse. La directiva no se emite ni aunque se pida.
        $script = $this->scriptDirective(Http::csp(null, ['unsafe-eval' => true]));

        $this->assertFalse(strpos($script, 'unsafe-eval') !== false);
        $this->assertStringContainsString("'nonce-", $script);
    }

    public function testElJavaScriptDelProyectoNoTieneEval(): void {
        // Si alguien vuelve a meter eval(), esto lo dice antes de que la CSP
        // lo bloquee en producción (y sin avisar en el navegador)
        $encontrados = array();
        foreach (array('js', 'scp/js') as $dir) {
            $path = dirname(__DIR__) . '/' . $dir;
            foreach (glob($path . '/*.js') as $file) {
                $code = file_get_contents($file);
                // eval( suelto, sin contar el texto de los comentarios
                if (preg_match('/(?<![\w$.])eval\s*\(/', $code))
                    $encontrados[] = basename($file);
            }
        }

        $this->assertSame(array(), $encontrados,
            'eval() en: ' . implode(', ', $encontrados));
    }

    public function testNonceScriptsAñadeElAtributo(): void {
        $html = Http::nonceScripts(
            '<script>uno()</script><script type="text/javascript">dos()</script>');

        $this->assertSame(2, substr_count($html, 'nonce="' . Http::nonce() . '"'));
    }

    public function testNonceScriptsNoDuplicaElAtributo(): void {
        $once = Http::nonceScripts('<script>uno()</script>');
        $twice = Http::nonceScripts($once);

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'nonce='));
    }

    public function testNonceScriptsRespetaLoQueNoEsHtml(): void {
        $this->assertSame('', Http::nonceScripts(''));
        $this->assertSame('sin etiquetas', Http::nonceScripts('sin etiquetas'));
    }

    public function testLaAuditoriaDetectaUnScriptSinNonce(): void {
        $dir = sys_get_temp_dir() . '/csp-nonce-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/plantilla.inc.php';
        file_put_contents($path, "<?php echo 'x'; ?>\n<script>alert(1)</script>\n"
            . "<script nonce=\"<?php echo Http::nonce(); ?>\">ok()</script>\n"
            . "<?php // <script>en un comentario</script>\n");

        $tags = csp_nonce_inline_tags($path);
        unlink($path);
        rmdir($dir);

        $this->assertSame(2, count($tags));
        $this->assertSame(2, $tags[0]['line']);
        $this->assertFalse(strpos($tags[0]['tag'], 'nonce') !== false);
        $this->assertTrue(strpos($tags[1]['tag'], 'nonce=') !== false);
    }

    public function testLasPlantillasNoTienenScriptsSinNonce(): void {
        $missing = [];
        foreach (['include', 'scp'] as $area) {
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(ROOT_DIR . '/' . $area,
                        FilesystemIterator::SKIP_DOTS),
                    function ($f) {
                        return !($f->isDir() && in_array($f->getFilename(),
                            ['mpdf', 'vendor', 'pear', 'laminas-mail', 'oauth2',
                             'plugins', 'lib', 'upgrader', 'test'], true));
                    }));
            foreach ($it as $file) {
                if (!$file->isFile()
                        || !preg_match('/\.(php|inc|tpl)$/', $file->getFilename()))
                    continue;
                foreach (csp_nonce_inline_tags($file->getPathname()) as $tag)
                    if (!preg_match('/\bnonce\s*=/i', $tag['tag']))
                        $missing[] = $file->getPathname() . ':' . $tag['line'];
            }
        }

        $this->assertSame([], array_slice($missing, 0, 10));
    }
}
