#!/usr/bin/env php
<?php
/**
 * tools/quality/csp-nonce.php
 *
 * Comprueba que todo `<script>` en línea de las plantillas lleva `nonce`.
 *
 * La CSP de la aplicación ya no anuncia `'unsafe-inline'` para `script-src`:
 * un `<script>` sin `nonce` no se ejecuta. Este inventario es lo que impide
 * que eso pase en silencio al añadir una plantilla nueva.
 *
 * Qué cuenta:
 *   - sólo el HTML de verdad, es decir los tramos `T_INLINE_HTML` del
 *     archivo. Un `<script>` dentro de una cadena de PHP o de un comentario
 *     no llega al navegador tal cual, así que no se revisa;
 *   - tampoco se revisan los `<script src="…">`: no son código en línea.
 *
 * Lo que se arma en PHP (un heredoc, `addExtraHeader()`) pasa por
 * `Http::nonceScripts()`, que añade el atributo al vuelo; eso no se puede
 * comprobar leyendo el archivo, y por eso `tests/CspNonceTest.php` prueba esa
 * función aparte.
 *
 * Uso:
 *   php tools/quality/csp-nonce.php          # resumen
 *   php tools/quality/csp-nonce.php --list   # detalle, uno por línea
 *   php tools/quality/csp-nonce.php --json   # para comparar versiones
 *
 * Códigos de salida:
 *   0  todos los `<script>` en línea llevan nonce
 *   1  hay alguno sin nonce
 *   2  error de uso
 */

/**
 * Bloques `<script>` en línea de un archivo, con su línea.
 *
 * El código PHP se sustituye por espacios (conservando los saltos de línea)
 * antes de buscar: si no, una etiqueta con `nonce="<?php echo … ?>"` quedaría
 * partida en trozos y no se reconocería. Al conservar la longitud, el número
 * de línea sigue siendo el del archivo.
 *
 * @return array<int,array{line:int,tag:string}>
 */
function csp_nonce_inline_tags($path) {
    $source = file_get_contents($path);
    if (stripos($source, '<script') === false)
        return [];

    $masked = '';
    foreach (token_get_all($source) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (is_array($token) && $token[0] === T_INLINE_HTML) {
            $masked .= $text;
            continue;
        }
        // Espacios, salvo los saltos de línea: la posición no se mueve
        $masked .= preg_replace('/[^\n]/', ' ', $text);
    }

    if (!preg_match_all('/<script\b[^>]*>/i', $masked, $matches,
            PREG_OFFSET_CAPTURE))
        return [];

    $tags = [];
    foreach ($matches[0] as $match) {
        list($tag, $offset) = $match;
        // `src` no es código en línea y no necesita nonce
        if (preg_match('/\bsrc\s*=/i', $tag))
            continue;
        $tags[] = [
            'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
            'tag' => preg_replace('/\s+/', ' ', trim($tag)),
        ];
    }
    return $tags;
}

function csp_nonce_main($root, array $argv) {
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--list')
            $options['list'] = true;
        elseif ($arg === '--json')
            $options['json'] = true;
        elseif ($arg === '--help' || $arg === '-h')
            $options['help'] = true;
    }

    if (isset($options['help'])) {
        echo "csp-nonce — `<script>` en línea sin nonce\n\n"
            . "  php tools/quality/csp-nonce.php [--list] [--json]\n\n";
        return 0;
    }

    /* Directorios con plantillas. `setup/` es el instalador: tiene su propia
       CSP y no usa scripts en línea. */
    $areas = ['include', 'scp', 'api'];
    $skip = ['mpdf', 'vendor', 'pear', 'laminas-mail', 'oauth2', 'plugins',
        'lib', 'upgrader', 'test'];

    $files = [];
    foreach ($areas as $area) {
        $base = "$root/$area";
        if (!is_dir($base))
            continue;
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base,
                    FilesystemIterator::SKIP_DOTS),
                function ($f) use ($skip) {
                    return !($f->isDir()
                        && in_array($f->getFilename(), $skip, true));
                }));
        foreach ($it as $file) {
            if ($file->isFile()
                    && preg_match('/\.(php|inc|tpl)$/', $file->getFilename()))
                $files[] = $file->getPathname();
        }
    }
    sort($files);

    $inline = 0;
    $missing = [];
    $checked = 0;
    foreach ($files as $path) {
        $tags = csp_nonce_inline_tags($path);
        if (!$tags)
            continue;
        $checked++;
        foreach ($tags as $tag) {
            $inline++;
            if (preg_match('/\bnonce\s*=/i', $tag['tag']))
                continue;
            $missing[] = [
                'file' => substr($path, strlen($root) + 1),
                'line' => $tag['line'],
                'tag' => $tag['tag'],
            ];
        }
    }

    if (isset($options['json'])) {
        echo json_encode([
            'inline' => $inline,
            'missing' => count($missing),
            'detail' => $missing,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        return $missing ? 1 : 0;
    }

    printf("Scripts en línea: %d en %d archivos\n", $inline, $checked);
    printf("Sin nonce: %d\n", count($missing));

    if ($missing) {
        foreach ($missing as $m)
            printf("  %s:%d  %s\n", $m['file'], $m['line'], $m['tag']);
        fwrite(STDERR, sprintf(
            "\nFalla: %d bloques <script> en línea sin nonce.\n"
            . "Añade nonce=\"<?php echo Http::nonce(); ?>\" al <script>.\n",
            count($missing)));
        return 1;
    }

    if (isset($options['list']))
        echo "\nNinguno sin nonce.\n";

    return 0;
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "Sólo se ejecuta desde la línea de comandos\n");
        exit(2);
    }
    exit(csp_nonce_main(dirname(__DIR__, 2), $argv ?? []));
}
