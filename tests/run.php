#!/usr/bin/env php
<?php
/**
 * tests/run.php
 *
 * Ejecutor de las pruebas unitarias sin Composer ni red: recorre
 * `tests/*Test.php`, crea cada clase y llama a todos sus métodos `test*`.
 *
 * Con `vendor/` instalado, `make unit` prefiere PHPUnit (informe más
 * completo). Este ejecutor existe para que el proyecto se pueda comprobar
 * recién clonado y dentro del contenedor sin descargar nada.
 *
 * Uso:
 *   php tests/run.php                 # todo
 *   php tests/run.php Format          # solo las clases cuyo nombre contenga
 *   php tests/run.php --list          # lista las pruebas
 *
 * Código de salida: 0 todo bien, 1 algún fallo.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Sólo se ejecuta desde la línea de comandos\n");
    exit(2);
}

require_once __DIR__ . '/bootstrap.php';

$filter = null;
$list = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--list')
        $list = true;
    elseif ($arg !== '' && $arg[0] !== '-')
        $filter = $arg;
}

$files = glob(__DIR__ . '/*Test.php') ?: array();
sort($files);

$passed = 0;
$failed = 0;
$skipped = 0;
$failures = array();
$started = microtime(true);

foreach ($files as $file) {
    $before = get_declared_classes();
    require_once $file;
    $classes = array_diff(get_declared_classes(), $before);

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()
                || !$reflection->isSubclassOf('UnitTestCase'))
            continue;

        if ($filter && stripos($class, $filter) === false)
            continue;

        $methods = array();

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (strpos($method->getName(), 'test') === 0)
                $methods[] = $method->getName();
        }

        sort($methods);

        if (!$methods) {
            $skipped++;
            continue;
        }

        $short = $reflection->getShortName();

        foreach ($methods as $method) {
            if ($list) {
                echo "$short::$method\n";
                continue;
            }

            $instance = $reflection->newInstance();

            try {
                $assertions = $instance->runTest($method);
                $passed++;
                printf("  ok    %s::%s (%d)\n", $short, $method, $assertions);
            } catch (AssertionFailed $e) {
                $failed++;
                $failures[] = array("$short::$method", $e->getMessage());
                printf("  FALLO %s::%s\n", $short, $method);
            } catch (Throwable $e) {
                $failed++;
                $failures[] = array("$short::$method",
                    get_class($e) . ': ' . $e->getMessage());
                printf("  ERROR %s::%s — %s\n", $short, $method, $e->getMessage());
            }
        }
    }
}

if ($list)
    exit(0);

$elapsed = (microtime(true) - $started) * 1000;

echo "\n";

if ($failures) {
    echo "Fallos:\n";

    foreach ($failures as $failure) {
        printf("  %s\n        %s\n", $failure[0], str_replace("\n", "\n        ",
            $failure[1]));
    }

    echo "\n";
}

printf("%d pruebas correctas, %d fallidas (%d clases sin pruebas) en %.0f ms\n",
    $passed, $failed, $skipped, $elapsed);

exit($failed ? 1 : 0);
