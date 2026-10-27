<?php
/**
 * tests/SourceEncodingTest.php
 *
 * Ningun archivo del proyecto puede tener caracteres de reemplazo (U+FFFD).
 *
 * El origen de esta prueba es un fallo real: al reescribir un archivo de
 * osTicket -que esta en latin-1- desde una herramienta que escribe UTF-8, los
 * bytes que no caben (la "n" con virgulilla, los acentos, el guion largo) se
 * sustituyen por U+FFFD. En PHP eso no da error de sintaxis: el texto queda
 * con un rombo y se publica asi. Paso con tres rasgos de `Ticket` y estuvo a
 * punto de colarse en un commit; se detecto comparando bytes contra HEAD.
 *
 * La comprobacion es de bytes porque el proyecto NO tiene una codificacion
 * unica: `include/` es latin-1 y las plantillas de `include/staff/` son UTF-8.
 * Convertir todo seria un cambio enorme y sin relacion con esto. Lo que si es
 * siempre un error es el caracter de reemplazo, en cualquiera de las dos.
 *
 * Uso: se ejecuta con la suite (`tests/run.php`) o sola.
 */

class SourceEncodingTest extends UnitTestCase {

    /** Carpetas con codigo propio (nada de dependencias de terceros). */
    private static $roots = array(
        'include', 'js', 'css', 'scp', 'tests', 'tools',
    );

    /** Carpetas que no son nuestras. */
    private static $skip = array(
        'mpdf', 'pear', 'vendor', 'i18n', 'jquery-', 'redactor', 'fabric',
        'spectrum', 'select2', 'node_modules', '.git',
    );

    /** Extensiones que se revisan. */
    private static $extensions = array('php', 'js', 'css', 'inc');

    private function files(): array {
        $found = array();
        foreach (self::$roots as $root) {
            $base = ROOT_DIR . '/' . $root;
            if (!is_dir($base))
                continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base,
                    FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile())
                    continue;
                $path = $file->getPathname();
                $rel = substr($path, strlen(ROOT_DIR) + 1);
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, self::$extensions, true))
                    continue;
                foreach (self::$skip as $skip)
                    if (strpos($rel, $skip) !== false)
                        continue 2;
                $found[] = $rel;
            }
        }
        sort($found);

        return $found;
    }

    /** El analizador: devuelve los numeros de linea con U+FFFD. */
    static function replacementLines(string $source): array {
        $lines = array();
        foreach (explode("\n", $source) as $number => $line)
            if (strpos($line, "\xEF\xBF\xBD") !== false)
                $lines[] = $number + 1;

        return $lines;
    }

    /* ------------------------------------------------------------------ */
    /* El analizador                                                       */
    /* ------------------------------------------------------------------ */

    public function testDetectaElCaracterDeReemplazo(): void {
        $source = "<?php\n// se\xEF\xBF\xBDales\n";

        $this->assertSame(array(2), self::replacementLines($source));
    }

    public function testNoConfundeElLatin1NiElUtf8BienEscritos(): void {
        // "senal" con virgulilla, en latin-1 (0xF1) y en UTF-8 (0xC3 0xB1)
        $latin = "<?php\n// se\xF1ales\n";
        $utf8 = "<?php\n// se\xC3\xB1ales\n";

        $this->assertSame(array(), self::replacementLines($latin));
        $this->assertSame(array(), self::replacementLines($utf8));
    }

    /* ------------------------------------------------------------------ */
    /* El proyecto                                                        */
    /* ------------------------------------------------------------------ */

    public function testNingunArchivoPropioTieneCaracteresDeReemplazo(): void {
        $files = $this->files();
        $this->assertGreaterThan(100, count($files),
            'no se encontraron los archivos del proyecto');

        $problemas = array();
        foreach ($files as $rel) {
            $source = file_get_contents(ROOT_DIR . '/' . $rel);
            foreach (self::replacementLines($source) as $line)
                $problemas[] = "$rel:$line";
        }

        $this->assertSame(array(), $problemas,
            "estos archivos tienen U+FFFD (texto corrupto al reescribirlos en "
            . "otra codificacion):\n  " . implode("\n  ", $problemas));
    }
}
