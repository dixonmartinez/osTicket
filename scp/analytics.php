<?php
/*********************************************************************
    analytics.php

    Panel de analíticas del help desk.

    Métricas orientadas a la operación diaria: tiempos de primera respuesta
    y resolución, cumplimiento de SLA, antigüedad del pendiente, carga por
    agente y distribución por origen/hora/tema.

    Salidas:
      * HTML (por defecto)
      * CSV  (?export=csv)
      * JSON (?export=json) — pensado para la app móvil y para automatizar
        informes; respeta los mismos permisos que la vista HTML.

    Los datos se calculan en el servidor con consultas agregadas: no hay
    JavaScript obligatorio y la página funciona en cualquier móvil.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require('staff.inc.php');

require_once INCLUDE_DIR . 'class.report.php';
require_once INCLUDE_DIR . 'class.analytics.php';

/* --- Parámetros (validados, nunca interpolados en SQL) ------------------- */
$validRanges = array_keys(Analytics::$ranges);
$range = $_GET['range'] ?? '30d';
if (!in_array($range, $validRanges, true))
    $range = '30d';

/* Fecha ISO estricta: se descarta cualquier otro formato. */
$cleanDate = function ($value) {
    return (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
        ? $value : '';
};
$startDate = $cleanDate($_GET['start'] ?? '') ?: '';
$endDate = $cleanDate($_GET['end'] ?? '') ?: '';

$analytics = new Analytics($range, $thisstaff, $startDate ?: null,
    $endDate ?: null);

/* --- Exportaciones ------------------------------------------------------- */
$export = $_GET['export'] ?? '';
if ($export) {
    $stamp = gmdate('Ymd');
    if ($export === 'json') {
        Http::download("analytics-$stamp.json", 'application/json',
            json_encode($analytics->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        exit;
    }

    if ($export === 'csv') {
        Http::download("analytics-$stamp.csv", 'text/csv');
        $rows = [];
        $summary = $analytics->getSummary();
        $columns = ['metric', 'value'];
        foreach ($summary as $key => $value)
            $rows[] = [$key, $value === null ? '' : $value];
        Analytics::toCsv($rows, $columns);
        exit;
    }

    Http::response(400, __('Unknown export format'));
    exit;
}

/* --- Vista --------------------------------------------------------------- */
$nav->setTabActive('dashboard');
$ost->addExtraHeader(
    '<meta name="tip-namespace" content="dashboard.analytics" />',
    "$('#content').data('tipNamespace', 'dashboard.analytics');");

require(STAFFINC_DIR . 'header.inc.php');
require(STAFFINC_DIR . 'analytics.inc.php');
include(STAFFINC_DIR . 'footer.inc.php');
?>
