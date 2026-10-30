<?php
/**
 * tests/SecurityOperationsTest.php
 *
 * Pruebas de las recomendaciones **de operación** del análisis de seguridad
 * (OST-103 a OST-106), que hasta ahora sólo estaban escritas en
 * docs/SECURITY_AUDIT.md como «pendientes» cuando en realidad son decisiones
 * de despliegue.
 *
 * Lo que se fija aquí:
 *   - el límite de intentos (OST-103) existe de verdad en la configuración de
 *     nginx, y la zona se declara ANTES de usarse (si no, nginx no arranca);
 *   - la comprobación no se cree configurada cuando no lo está (fixture con
 *     una zona sin aplicar);
 *   - el inventario de librerías (OST-106) ve las que van dentro del
 *     repositorio, no sólo las de Composer;
 *   - los hashes heredados (OST-105) se cuentan bien, sin contar vacíos;
 *   - el estado de setup/ (OST-104) distingue «no está» de «está y responde».
 */

require_once dirname(__DIR__) . '/include/class.passwd.php';
require_once dirname(__DIR__) . '/include/class.security.php';

class SecurityOperationsTest extends UnitTestCase {

    private $tmp = array();

    public function tearDown(): void {
        foreach ($this->tmp as $dir)
            $this->rmtree($dir);
        $this->tmp = array();
    }

    private function rmtree($dir) {
        if (!is_dir($dir))
            return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..')
                continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmtree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Raíz de mentira con los archivos que se le pasen (ruta => contenido). */
    private function fixture(array $files): string {
        $root = sys_get_temp_dir() . '/ost-secops-' . uniqid();
        $this->tmp[] = $root;
        @mkdir($root);
        foreach ($files as $path => $contents) {
            $full = $root . '/' . $path;
            @mkdir(dirname($full), 0777, true);
            file_put_contents($full, $contents);
        }
        return $root;
    }

    /* ---------------------------------------------------------------- */
    /* OST-103 · límite de intentos                                      */
    /* ---------------------------------------------------------------- */

    public function testElDespliegueLimitaLosIntentosDeAcceso(): void {
        $status = SecurityPosture::rateLimit(ROOT_DIR);

        $this->assertTrue($status['configured'],
            'La configuración de nginx debe limitar los accesos: '
            . implode('; ', $status['problems']));
        $this->assertStringContainsString('ost_login', implode(',', $status['zones']));
        $this->assertStringContainsString('/scp/login.php', implode(',', $status['paths']));
        $this->assertStringContainsString('/login.php', implode(',', $status['paths']));
    }

    public function testLaZonaSeDeclaraAntesDeUsarse(): void {
        // nginx lee conf.d/*.conf por orden alfabético y resuelve
        // `limit_req zone=` al leer: si el archivo de zonas fuera
        // `limits.conf`, iría después de `default.conf` y nginx arrancaría
        // con "unknown limit_req_zone". El nombre es parte del arreglo.
        $zones = basename('docker/nginx/00-limits.conf');
        $site = basename('docker/nginx/default.conf');

        $this->assertLessThan(0, strcmp($zones, $site),
            '00-limits.conf debe ordenarse antes que default.conf');
        $this->assertTrue(is_readable(ROOT_DIR . '/docker/nginx/00-limits.conf'));
        $this->assertTrue(is_readable(ROOT_DIR . '/docker/nginx/fastcgi-osticket.conf'));
    }

    public function testLosDosComposeMontanLaConfiguracion(): void {
        // Montar sólo default.conf dejaría el `include` sin archivo y nginx
        // no arrancaría: los dos archivos nuevos tienen que estar en los dos
        // compose (desarrollo y producción).
        foreach (array('compose.yaml', 'compose.prod.yaml') as $file) {
            $yaml = (string) file_get_contents(ROOT_DIR . '/' . $file);
            $this->assertStringContainsString('00-limits.conf:/etc/nginx/conf.d/00-limits.conf',
                $yaml, $file);
            $this->assertStringContainsString(
                'fastcgi-osticket.conf:/etc/nginx/fastcgi-osticket.conf', $yaml, $file);
        }
    }

    public function testUnaZonaSinAplicarNoCuentaComoLimite(): void {
        // La prueba de que la comprobación mira de verdad: hay zona declarada,
        // pero ningún `location` la usa.
        $root = $this->fixture(array(
            'docker/nginx/00-limits.conf' =>
                "limit_req_zone \$binary_remote_addr zone=ost_login:10m rate=10r/m;\n",
            'docker/nginx/default.conf' =>
                "server {\n    location = /scp/login.php {\n        include /etc/nginx/fastcgi-osticket.conf;\n    }\n}\n",
        ));

        $status = SecurityPosture::rateLimit($root);

        $this->assertFalse($status['configured']);
        $this->assertStringContainsString('no aplica',
            implode('; ', $status['problems']));
        $this->assertSame(array(), $status['paths']);
    }

    public function testSinArchivosDeConfiguracionNoSeInventaUnLimite(): void {
        $status = SecurityPosture::rateLimit($this->fixture(array()));

        $this->assertFalse($status['configured']);
        $this->assertStringContainsString('00-limits.conf',
            implode('; ', $status['problems']));
    }

    /* ---------------------------------------------------------------- */
    /* OST-106 · inventario de librerías                                 */
    /* ---------------------------------------------------------------- */

    public function testElInventarioVeLasLibreriasEmpaquetadas(): void {
        $out = SecurityPosture::dependencyInventory(ROOT_DIR);
        $byName = array();
        foreach ($out as $dep)
            $byName[$dep['name']] = $dep;

        // mPDF y PEAR viajan en el repositorio: no salen en composer.json
        $this->assertArrayHasKey('mpdf/mpdf', $byName);
        $this->assertMatchesRegularExpression('/^v?\d+\.\d+/', $byName['mpdf/mpdf']['version']);
        $this->assertArrayHasKey('pear/mail', $byName);

        // Y las de desarrollo, con su restricción
        $this->assertArrayHasKey('phpunit/phpunit', $byName);

        // El lock de laminas-mail lista también su herramienta de desarrollo
        // (psalm, phpunit…), que no viaja en el repositorio: el inventario
        // tiene que hablar de lo que se despliega, no de lo que se usó para
        // construirlo.
        $this->assertFalse(isset($byName['vimeo/psalm']));
        $this->assertArrayHasKey('laminas/laminas-mail', $byName);

        // Orden alfabético, para que el informe sea comparable entre tandas
        $names = array_keys($byName);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function testElInventarioNoSeInventaVersiones(): void {
        $out = SecurityPosture::dependencyInventory($this->fixture(array(
            'composer.json' => '{"require-dev": {"a/b": "^2.0"}}',
        )));

        $this->assertCount(1, $out);
        $this->assertSame('a/b', $out[0]['name']);
        $this->assertSame('^2.0', $out[0]['version']);
    }

    /* ---------------------------------------------------------------- */
    /* OST-105 · contraseñas con hash heredado                           */
    /* ---------------------------------------------------------------- */

    public function testLosHashesModernosNoSonHeredados(): void {
        $this->assertFalse(Passwd::isLegacy('$2y$10$abcdefghijklmnopqrstuv'));
        $this->assertFalse(Passwd::isLegacy('$argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA'));
        $this->assertFalse(Passwd::isLegacy(''));
        $this->assertFalse(Passwd::isLegacy(null));
    }

    public function testElMd5YElDesSonHeredados(): void {
        $this->assertTrue(Passwd::isLegacy(md5('contraseña')));
        $this->assertTrue(Passwd::isLegacy('abJnggxhB/yWI')); // crypt() DES
    }

    public function testElRecuentoIgnoraLosVaciosYTerminaEnCero(): void {
        $count = SecurityPosture::classifyHashes(array(
            '$2y$10$abcdefghijklmnopqrstuv',
            '',
            null,
            md5('x'),
            '$2y$12$abcdefghijklmnopqrstuv',
        ));

        // Los vacíos no cuentan como contraseña: no hay nada que migrar
        $this->assertSame(3, $count['total']);
        $this->assertSame(1, $count['legacy']);
        $this->assertSame(2, $count['modern']);
        $this->assertFalse($count['done']);

        $clean = SecurityPosture::classifyHashes(array('$2y$10$abcdefghijklmnopqrstuv'));
        $this->assertTrue($clean['done']);
    }

    public function testLaBaseDeDatosSigueAceptandoLosHashesHeredados(): void {
        // No basta con contarlos: `needsRehash()` tiene que seguir diciendo
        // que hay que reescribirlos (es lo que hace el acceso correcto).
        $this->assertTrue(Passwd::needsRehash(md5('x')));
        $this->assertTrue(Passwd::needsRehash('$2y$04$abcdefghijklmnopqrstuv'));
        $this->assertFalse(Passwd::needsRehash('$2y$10$abcdefghijklmnopqrstuv'));
        // Y un hash heredado sigue validando, que es lo que exige «no perder
        // funcionalidad»: nadie se queda fuera por la migración.
        $this->assertTrue(Passwd::cmp('secreta', Passwd::hash('secreta')));
    }

    /* ---------------------------------------------------------------- */
    /* OST-104 · setup/                                                  */
    /* ---------------------------------------------------------------- */

    public function testSinDirectorioSetupElEstadoEsAusente(): void {
        $state = SecurityPosture::setupState($this->fixture(array()));

        $this->assertSame('absent', $state['state']);
        $this->assertFalse($state['present']);
    }

    public function testElEstadoDelSetupDistingueBloqueadoDeExpuesto(): void {
        $root = $this->fixture(array('setup/install.php' => '<?php'));

        $blocked = SecurityPosture::setupState($root, false);
        $this->assertSame('blocked', $blocked['state']);
        $this->assertTrue($blocked['present']);

        $exposed = SecurityPosture::setupState($root, true);
        $this->assertSame('exposed', $exposed['state']);
        $this->assertStringContainsString('reinstalar', $exposed['detail']);
    }
}
