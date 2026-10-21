<?php
/*********************************************************************
    manifest.php

    Manifiesto de aplicación web (PWA) generado en el servidor para que el
    nombre, el idioma y la ruta salgan de la configuración de osTicket.

    ?scope=scp     → abre el panel de agentes (por defecto)
    ?scope=client  → abre el portal del cliente

    Se sirve sin autenticación: no expone nada que no esté ya en el HTML
    público. Los iconos viven en images/pwa/.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require('main.inc.php');

$scope = (isset($_GET['scope']) && $_GET['scope'] === 'client')
    ? 'client' : 'scp';

// Idioma y dirección del texto según la configuración de osTicket
$lang = Internationalization::getCurrentLanguage();
$info = $lang ? Internationalization::getLanguageInfo($lang) : null;
$isRtl = $info && (($info['direction'] ?? '') === 'rtl');

$base = defined('ROOT_PATH') ? ROOT_PATH : '/';
$siteTitle = ($cfg && $cfg->getTitle()) ? $cfg->getTitle() : 'osTicket';

$name = $scope === 'client'
    ? sprintf('%s — %s', $siteTitle, __('Support Center'))
    : sprintf('%s — %s', $siteTitle, __('Agent Panel'));

$shortName = mb_substr($siteTitle, 0, 12);

$manifest = [
    'name' => $name,
    'short_name' => $shortName,
    'description' => __('Support ticket system'),
    'lang' => str_replace('_', '-', $lang ?: 'en'),
    'dir' => $isRtl ? 'rtl' : 'ltr',
    'start_url' => $scope === 'client'
        ? $base . 'index.php'
        : $base . 'scp/',
    'scope' => $base,
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#ffffff',
    'theme_color' => '#184E81',
    'icons' => [
        [
            'src' => $base . 'images/pwa/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $base . 'images/pwa/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $base . 'images/pwa/icon-512-maskable.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
    'shortcuts' => $scope === 'client'
        ? [
            ['name' => __('Open a new ticket'), 'url' => $base . 'open.php'],
            ['name' => __('Check ticket status'), 'url' => $base . 'view.php'],
        ]
        : [
            ['name' => __('Ticket Queue'), 'url' => $base . 'scp/tickets.php'],
            ['name' => __('Analytics'), 'url' => $base . 'scp/analytics.php'],
            ['name' => __('My Dashboard'), 'url' => $base . 'scp/dashboard.php'],
        ],
];

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo json_encode($manifest,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
