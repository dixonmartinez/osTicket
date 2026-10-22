#!/usr/bin/env php
<?php
/**
 * tools/security/setup-probe.php
 *
 * Comprueba si el directorio /setup/ sigue siendo alcanzable por HTTP, que es
 * lo que decide si el panel de administración muestra el aviso
 * «The setup directory is reachable from the web».
 *
 * Uso:
 *   php tools/security/setup-probe.php             # una comprobación
 *   php tools/security/setup-probe.php --verbose   # muestra el objetivo
 *
 * Variables de entorno:
 *   OST_INTERNAL_URL   URL interna del servidor web (por defecto http://nginx)
 *   OST_SETUP_LOCKED   1 = se da por bloqueado sin comprobar
 *
 * Código de salida: 0 si está protegido, 1 si está expuesto o no se pudo saber.
 */

require_once dirname(__file__) . '/../../include/class.security.php';

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$base = getenv('OST_INTERNAL_URL') ?: 'http://nginx';

printf("Objetivo: %s/setup/install.php\n", rtrim($base, '/'));

if (getenv('OST_SETUP_LOCKED') === '1') {
    echo "OST_SETUP_LOCKED=1 → se considera bloqueado sin comprobar.\n";
    exit(0);
}

$status = SecurityPosture::probeSetupStatus($base);

if (!$status) {
    echo "✗ Sin respuesta del servidor web.\n";
    echo "  El sondeo usa fsockopen (bootstrap.php desactiva allow_url_fopen).\n";
    echo "  Revisa que OST_INTERNAL_URL apunte al servicio correcto y que\n";
    echo "  nginx esté levantado:  docker compose ps nginx\n";
    exit(1);
}

printf("Código HTTP: %d\n", $status);

if ($status === 200) {
    echo "✗ /setup/ SIGUE ACCESIBLE. Descomenta el bloque «Producción» de\n";
    echo "  docker/nginx/default.conf y ejecuta: docker compose restart nginx\n";
    exit(1);
}

if ($status === 403 || $status === 404) {
    echo "✓ /setup/ está bloqueado por el servidor web ($status).\n";
    echo "  El aviso del panel desaparecerá al refrescar (caché de 5 minutos).\n";
    echo "  Si quieres forzarlo ahora:  rm -f /tmp/ost-setup-probe-*.cache\n";
    exit(0);
}

echo "? Código inesperado ($status). Revisa la configuración de nginx.\n";
exit(1);
