<?php
/**
 * tests/PluginTest.php
 *
 * Pruebas de `PluginInfo` (`include/class.plugin.info.php`), la lectura del
 * `plugin.php` que declara cada plugin (frente 2 de docs/CODE_QUALITY.md).
 *
 * No cargan `class.plugin.php`: ese archivo hereda del ORM y toca la base de
 * datos. Aquí se prueba lo único que decide cómo se instala, se engancha y se
 * compara un plugin, que es justo lo que estaba mal.
 */
class PluginTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Claves                                                              */
    /* ------------------------------------------------------------------ */

    public function testUnPluginCompletoConservaSusDatos(): void {
        $info = PluginInfo::normalize(array(
            'plugin' => 'auth.php:AuthPlugin',
            'name' => 'Autenticación LDAP',
            'version' => '1.2.0',
            'ost_version' => '1.18',
            'author' => 'Ana',
            'description' => 'Entra con la cuenta de la empresa',
        ), 'plugins/ldap');

        $this->assertSame('auth.php:AuthPlugin', $info['plugin']);
        $this->assertSame('Autenticación LDAP', $info['name']);
        $this->assertSame('1.2.0', $info['version']);
        $this->assertSame('1.18', $info['ost_version']);
        $this->assertSame('Ana', $info['author']);
        $this->assertSame('plugins/ldap', $info['install_path']);
    }

    public function testUnPluginCortoSNoDejaClavesSinDefinir(): void {
        // antes: «Undefined array key» en la lista de plugins
        $info = PluginInfo::normalize(array('name' => 'Mínimo'), 'plugins/x');

        foreach (array_keys(PluginInfo::defaults()) as $key)
            $this->assertTrue(array_key_exists($key, $info),
                "falta la clave $key");

        $this->assertSame('', $info['plugin']);
        $this->assertSame('', $info['version']);
        $this->assertSame('', $info['author']);
        $this->assertNull($info['ost_version']);
        $this->assertSame('include/', $info['include']);
    }

    public function testUnPluginQueNoDevuelveNadaNoRompe(): void {
        // un plugin.php sin `return` devuelve 1 al incluirse
        $info = PluginInfo::normalize(1, 'plugins/roto');

        $this->assertSame('', $info['name']);
        $this->assertSame('plugins/roto', $info['install_path']);
    }

    public function testElPharSeMarca(): void {
        $this->assertTrue(PluginInfo::normalize(array(), 'plugins/x.phar', true)['isphar']);
        $this->assertFalse(PluginInfo::normalize(array(), 'plugins/x')['isphar']);
    }

    /* ------------------------------------------------------------------ */
    /* Carpeta de clases                                                   */
    /* ------------------------------------------------------------------ */

    public function testLaCarpetaPorDefectoEsInclude(): void {
        $this->assertSame('include/',
            PluginInfo::normalize(array(), 'plugins/x')['include']);
    }

    public function testLaCarpetaAceptaBarrasDeMas(): void {
        foreach (array('include', 'include/', '/include/', ' include/ ') as $dir)
            $this->assertSame('include/', PluginInfo::includeDir($dir),
                'no se normalizó: ' . $dir);
    }

    public function testLaCarpetaPuedeSerLaRaizDelPlugin(): void {
        // Un plugin puede poner sus clases junto al plugin.php
        $this->assertSame('', PluginInfo::includeDir(''));
        $this->assertSame('', PluginInfo::includeDir('/'));
        $this->assertSame('', PluginInfo::includeDir(null));
    }

    public function testSeAceptaElNombreViejoIncludePath(): void {
        // `Plugin::getIncludePath()` leía `include_path`; el instalador
        // publica `include`. Un plugin con el nombre viejo sigue entrando.
        $info = PluginInfo::normalize(array('include_path' => 'src'), 'plugins/x');

        $this->assertSame('src/', $info['include']);
    }

    public function testLaRutaDeClasesCuelgaDelPlugin(): void {
        $info = PluginInfo::normalize(array(), 'plugins/ldap');

        $this->assertSame('plugins/ldap/include/',
            PluginInfo::includePath('plugins/ldap', $info));
        // Sin barra final en la ruta de instalación tampoco se duplica
        $this->assertSame('plugins/ldap/include/',
            PluginInfo::includePath('plugins/ldap/', $info));
    }

    public function testUnPluginEnLaRaizNoLlevaSubcarpeta(): void {
        $info = PluginInfo::normalize(array('include' => ''), 'plugins/x');

        $this->assertSame('plugins/x/', PluginInfo::includePath('plugins/x', $info));
    }

    /* ------------------------------------------------------------------ */
    /* archivo.php:Clase                                                   */
    /* ------------------------------------------------------------------ */

    public function testSeSeparaElArchivoDeLaClase(): void {
        $this->assertSame(array('auth.php', 'AuthPlugin'),
            PluginInfo::declared(array('plugin' => 'auth.php:AuthPlugin')));
    }

    public function testSinClaseSeDevuelveSoloElArchivo(): void {
        // `getInstance()` usaba el archivo como nombre de clase; ahora lo
        // decide quien llama, pero la lectura tiene que distinguirlo
        $this->assertSame(array('auth.php', null),
            PluginInfo::declared(array('plugin' => 'auth.php')));
    }

    public function testUnPluginSinClaseNoDeclaraNada(): void {
        $this->assertSame(array(null, null),
            PluginInfo::declared(array('plugin' => '')));
        $this->assertSame(array(null, null), PluginInfo::declared(array()));
    }

    public function testUnaClaseConNamespaceNoSeParte(): void {
        $this->assertSame(array('lib/ldap.php', 'Acme\\Ldap'),
            PluginInfo::declared(array('plugin' => 'lib/ldap.php:Acme\\Ldap')));
    }

    /* ------------------------------------------------------------------ */
    /* Compatibilidad                                                      */
    /* ------------------------------------------------------------------ */

    public function testSinRequisitoTodoEsCompatible(): void {
        // La mayoría de plugins no pide versión
        $this->assertTrue(PluginInfo::isCompatible(null));
        $this->assertTrue(PluginInfo::isCompatible(''));
    }

    public function testUnRequisitoDeDosPartesSeComparaPorMayorYMenor(): void {
        // `1.18` acepta cualquier 1.18.x: la versión exacta puede no existir
        // en un clon de git
        $this->assertTrue(PluginInfo::isCompatible('1.18', '1.18.4'));
        $this->assertTrue(PluginInfo::isCompatible('1.18', '1.18'));
        $this->assertFalse(PluginInfo::isCompatible('1.19', '1.18.4'));
    }

    public function testUnRequisitoDeTresPartesSeComparaEntero(): void {
        $this->assertTrue(PluginInfo::isCompatible('1.18.2', '1.18.4'));
        $this->assertFalse(PluginInfo::isCompatible('1.18.5', '1.18.4'));
    }

    public function testUnaVersionMayorNuevaCumpleElRequisito(): void {
        $this->assertTrue(PluginInfo::isCompatible('1.17.2', '1.18.4'));
    }

    /* ------------------------------------------------------------------ */
    /* Actualización al cargar                                             */
    /* ------------------------------------------------------------------ */

    public function testSinVersionDeclaradaNoHayActualizacion(): void {
        // antes: la comparación usaba la clave `verion` (sin la «s»), que no
        // existe, así que esto se daba por cierto en cada carga de página
        $this->assertFalse(PluginInfo::needsUpgrade('1.0', array()));
        $this->assertFalse(PluginInfo::needsUpgrade('1.0', array('version' => '')));
    }

    public function testLaVersionDistintaPideActualizacion(): void {
        $this->assertTrue(PluginInfo::needsUpgrade('1.0',
            array('version' => '1.1')));
    }

    public function testLaMismaVersionNoHaceNada(): void {
        $this->assertFalse(PluginInfo::needsUpgrade('1.1',
            array('version' => '1.1')));
    }

    public function testUnPluginSinVersionGuardadaSeActualiza(): void {
        $this->assertTrue(PluginInfo::needsUpgrade(null,
            array('version' => '1.1')));
    }

    /* ------------------------------------------------------------------ */
    /* El esqueleto de `tools/scaffold.php` cumple el contrato             */
    /* ------------------------------------------------------------------ */

    /**
     * Extrae una plantilla heredoc de `tools/scaffold.php`.
     *
     * El generador no se puede ejecutar aquí (necesita escribir en disco y
     * un SAPI de consola), así que se lee su plantilla y se comprueba que el
     * `plugin.php` que produce es válido para `PluginInfo`. Si alguien cambia
     * la plantilla y rompe el contrato, salta.
     */
    private function plantilla($variable) {
        $source = file_get_contents(dirname(__DIR__) . '/tools/scaffold.php');
        $this->assertTrue($source !== false, 'no se pudo leer tools/scaffold.php');

        $start = strpos($source, '$' . $variable . " = <<<'PHP'");
        $this->assertTrue($start !== false, "no existe la plantilla $variable");

        $start = strpos($source, "\n", $start) + 1;
        $end = strpos($source, "\nPHP;", $start);
        $this->assertTrue($end !== false, "la plantilla $variable no cierra");

        return str_replace(
            array('{{slug}}', '{{class}}', '{{title}}', '{{author}}'),
            array('demo', 'Demo', 'Demo', 'Ana'),
            substr($source, $start, $end - $start));
    }

    public function testElPluginGeneradoDeclaraLoTodo(): void {
        $code = $this->plantilla('pluginTpl');

        // Se evalúa sólo el `return`, sin el require de la clase
        $return = strpos($code, 'return array(');
        $this->assertTrue($return !== false, 'el esqueleto no devuelve un arreglo');
        $info = eval(substr($code, $return));

        $this->assertTrue(is_array($info), 'plugin.php debe devolver un arreglo');

        foreach (array('plugin', 'name', 'version', 'ost_version', 'author',
                       'description') as $key)
            $this->assertTrue(array_key_exists($key, $info),
                "el esqueleto no declara $key");
    }

    public function testElPluginGeneradoApuntaASuClase(): void {
        $code = $this->plantilla('pluginTpl');
        $info = eval(substr($code, strpos($code, 'return array(')));

        // `archivo.php:Clase`, y el archivo es el que genera el andamiaje
        $this->assertSame(array('class.demo.php', 'DemoPlugin'),
            PluginInfo::declared($info));
    }

    public function testElPluginGeneradoPideUnaVersionConocida(): void {
        $code = $this->plantilla('pluginTpl');
        $info = PluginInfo::normalize(
            eval(substr($code, strpos($code, 'return array('))), 'plugins/demo');

        // Se compara contra la versión que declara el proyecto (MAJOR_VERSION
        // no está definida en el arnés de pruebas)
        $this->assertTrue(preg_match('/^\d+\.\d+(\.\d+)?$/',
            $info['ost_version']) === 1,
            'ost_version debe ser mayor.menor, no «' . $info['ost_version'] . '»');
    }

    public function testLaClaseGeneradaEsLaQueDeclaraElPlugin(): void {
        $clase = $this->plantilla('classTpl');

        $this->assertTrue(strpos($clase, 'class DemoPlugin extends Plugin') !== false);
        $this->assertTrue(strpos($clase, "var \$config_class = 'DemoPluginConfig';") !== false);
        $this->assertTrue(strpos($clase, 'class DemoPluginConfig extends PluginConfig') !== false);
        $this->assertTrue(strpos($clase, 'function bootstrap()') !== false);
    }
}
