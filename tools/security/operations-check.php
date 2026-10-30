#!/usr/bin/env php
<?php
/**
 * tools/security/operations-check.php
 *
 * Informe de las cuatro recomendaciones **de operación** del análisis de
 * seguridad que no son código pendiente sino decisiones de despliegue:
 *
 *   OST-103  Límite de intentos de acceso (nginx: limit_req).
 *   OST-104  El directorio setup/ no debe seguir accesible tras instalar.
 *   OST-105  Contraseñas guardadas con MD5 heredado: cuántas quedan.
 *   OST-106  Inventario de librerías (Composer y empaquetadas).
 *
 * No modifica nada ni contacta con servicios externos: lee archivos y, si se
 * le dan, cuenta hashes. Es el complemento de `php-cve-check.php`, que se
 * ocupa del intérprete.
 *
 * Uso:
 *   php tools/security/operations-check.php
 *   php tools/security/operations-check.php --strict
 *   php tools/security/operations-check.php --hashes=passwd.txt
 *   php tools/security/operations-check.php --root=/var/www/html --json
 *
 * Para obtener el archivo de hashes (OST-105):
 *   mysql -N -e "SELECT passwd FROM ost_staff
 *                 UNION ALL SELECT passwd FROM ost_user" osticket > passwd.txt
 *
 * Códigos de salida:
 *   0  nada urgente
 *   1  setup/ expuesto (o, con --strict, cualquier aviso)
 *   2  error de uso
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
// class.passwd.php carga PasswordHash desde INCLUDE_DIR; sin esto revienta
// cuando la herramienta se ejecuta suelta (sin el bootstrap de osTicket).
if (!defined('INCLUDE_DIR'))
    define('INCLUDE_DIR', $root . '/include/');
require_once $root . '/include/class.passwd.php';
require_once $root . '/include/class.security.php';

$options = getopt('', ['root::', 'hashes::', 'json', 'strict', 'help']);

if (isset($options['help'])) {
    echo <<<TXT
operaciones — OST-103/104/105/106

  php tools/security/operations-check.php [opciones]

  --root=DIR     raíz del proyecto (por defecto, la de este archivo)
  --hashes=FILE  un hash de contraseña por línea (ver la consulta SQL arriba)
  --json         salida para máquinas
  --strict       los avisos también cuentan como fallo
  --help         esta ayuda

OST-103  límite de intentos  → docker/nginx/00-limits.conf + default.conf
OST-104  setup/ accesible    → borrarlo o bloquearlo en el servidor
OST-105  MD5 heredados       → se reescriben en el primer acceso correcto
OST-106  librerías           → inventario con su versión

TXT;
    exit(0);
}

if (isset($options['root']))
    $root = rtrim($options['root'], '/');

if (!is_dir($root . '/include')) {
    fwrite(STDERR, "No parece la raíz de osTicket: $root\n");
    exit(2);
}

$json = isset($options['json']);

$rateLimit = SecurityPosture::rateLimit($root);
$setup = SecurityPosture::setupState($root);

$hashes = null;
if (isset($options['hashes'])) {
    if (!is_readable($options['hashes'])) {
        fwrite(STDERR, "No se puede leer {$options['hashes']}\n");
        exit(2);
    }
    $hashes = SecurityPosture::classifyHashes(
        file($options['hashes'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

$inventory = SecurityPosture::dependencyInventory($root);

// Avisos accionables
$warnings = [];
if (!$rateLimit['configured'])
    $warnings[] = 'OST-103: ' . implode('; ', $rateLimit['problems']);

if ($setup['state'] === 'exposed')
    $warnings[] = 'OST-104: ' . $setup['detail'];
elseif ($setup['state'] === 'unknown')
    $warnings[] = 'OST-104: ' . $setup['detail'];

if ($hashes && !$hashes['done'])
    $warnings[] = sprintf(
        'OST-105: %d de %d contraseñas siguen con hash heredado.',
        $hashes['legacy'], $hashes['total']);

if ($json) {
    echo json_encode([
        'root' => $root,
        'rate_limit' => $rateLimit,
        'setup' => $setup,
        'legacy_hashes' => $hashes,
        'dependencies' => $inventory,
        'warnings' => $warnings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}
else {
    echo "Operación — OST-103/104/105/106\n";
    printf("Raíz: %s\n\n", $root);

    // --- OST-103
    printf("OST-103  Límite de intentos de acceso\n");
    if ($rateLimit['zones'])
        printf("  Zonas: %s\n", implode(', ', $rateLimit['zones']));
    if ($rateLimit['paths'])
        printf("  Rutas: %s\n", implode(', ', $rateLimit['paths']));
    if ($rateLimit['configured'])
        printf("  ✓ configurado (%s)\n", 'limit_req en nginx');
    else
        foreach ($rateLimit['problems'] as $problem)
            printf("  ✗ %s\n", $problem);
    printf("  Nota: el límite protege los accesos, no sustituye a una\n"
        . "  contraseña larga. Ajusta `rate` al tráfico real.\n\n");

    // --- OST-104
    printf("OST-104  Directorio setup/\n");
    printf("  %s %s\n\n",
        $setup['state'] === 'exposed' ? '✗' : ($setup['state'] === 'unknown' ? '?' : '✓'),
        $setup['detail']);

    // --- OST-105
    printf("OST-105  Contraseñas con hash heredado\n");
    if ($hashes === null) {
        printf("  ? sin datos: pasa --hashes=ARCHIVO para contarlas\n");
        printf("    %s\n", 'SELECT passwd FROM ost_staff UNION ALL SELECT passwd FROM ost_user');
    }
    else {
        printf("  %d de %d son heredadas, %d modernas%s\n",
            $hashes['legacy'], $hashes['total'], $hashes['modern'],
            $hashes['done'] ? ' — no queda ninguna' : '');
        printf("  Se reescriben solas en el siguiente acceso correcto\n"
            . "  (Passwd::needsRehash). Los usuarios que no vuelvan a entrar\n"
            . "  conservan el hash antiguo: si urge, obliga un cambio de\n"
            . "  contraseña desde el panel.\n");
    }
    echo "\n";

    // --- OST-106
    printf("OST-106  Inventario de librerías (%d)\n", count($inventory));
    foreach ($inventory as $dep)
        printf("  %-28s %-14s %s\n", $dep['name'], $dep['version'], $dep['source']);
    printf("\n  Es un inventario, no una auditoría: para avisos de\n"
        . "  vulnerabilidades, `composer audit` sobre require-dev.\n");
}

$failed = ($setup['state'] === 'exposed') || (isset($options['strict']) && $warnings);

if (!$json) {
    if ($warnings) {
        echo "\nAvisos:\n";
        foreach ($warnings as $warning)
            printf("  ! %s\n", $warning);
    }
    printf("\nResultado: %s\n", $failed ? 'ACCIÓN REQUERIDA' : 'OK');
}

exit($failed ? 1 : 0);
