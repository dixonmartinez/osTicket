#!/usr/bin/env php
<?php
/*********************************************************************
    tools/dev/mail-test.php

    Envía un correo de prueba al buzón de desarrollo (Mailpit) hablando SMTP
    a pelo, sin depender de la configuración de osTicket ni de librerías.

        docker compose exec -T php php tools/dev/mail-test.php
        docker compose exec -T php php tools/dev/mail-test.php --to=a@b.c

    Sirve para separar dos problemas que se parecen: «osTicket no manda
    correo» y «el buzón no recibe correo». Si este script funciona, el buzón
    está bien y lo que falta es la cuenta de correo en el panel
    (Admin → Emails → Add Email), que es lo que explica docs/MAIL.md.

    Variables de entorno:
        MAILPIT_HOST   host SMTP (por omisión `mailpit`)
        MAILPIT_PORT   puerto SMTP (por omisión 1025)

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Sólo se ejecuta desde la línea de comandos\n");
    exit(2);
}

$options = getopt('', array('to::', 'from::', 'subject::', 'help'));

if (isset($options['help'])) {
    echo "tools/dev/mail-test.php — correo de prueba al buzón de desarrollo\n\n"
        . "  --to=DIRECCIÓN       destinatario (por omisión pruebas@demo.invalid)\n"
        . "  --from=DIRECCIÓN     remitente (por omisión osticket@demo.invalid)\n"
        . "  --subject=TEXTO      asunto\n\n"
        . "Variables: MAILPIT_HOST (mailpit), MAILPIT_PORT (1025).\n";
    exit(0);
}

$host = getenv('MAILPIT_HOST') ?: 'mailpit';
$port = (int) (getenv('MAILPIT_PORT') ?: 1025);
$to = $options['to'] ?? 'pruebas@demo.invalid';
$from = $options['from'] ?? 'osticket@demo.invalid';
$subject = $options['subject'] ?? 'Prueba de correo de osTicket';
$marker = 'ost-' . bin2hex(random_bytes(4));

printf("Conectando a %s:%d …\n", $host, $port);

$socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
if (!$socket) {
    fwrite(STDERR, sprintf("No se pudo conectar a %s:%d — %s (%d)\n",
        $host, $port, $errstr ?: 'sin detalle', $errno));
    fwrite(STDERR, "\n  ¿Están los contenedores arriba?  docker compose ps\n"
        . "  ¿Publica el servicio el puerto?  docker compose config | "
        . "grep MAILPIT_SMTP_PORT\n"
        . "  Desde el contenedor de PHP, el host es `mailpit`, no "
        . "`localhost`: es la red de Docker, no la de tu equipo.\n");
    exit(1);
}

/** Lee una respuesta SMTP completa (una o varias líneas `NNN-`). */
function smtp_read($socket, $timeout = 5) {
    stream_set_timeout($socket, $timeout);
    $response = '';
    while (($line = fgets($socket, 1024)) !== false) {
        $response .= $line;
        // La última línea de la respuesta empieza por el código y un espacio
        if (strlen($line) < 4 || $line[3] != '-')
            break;
    }
    return $response;
}

/** Manda un comando y comprueba que la respuesta empiece por el código esperado. */
function smtp_cmd($socket, $command, $expected = '250') {
    fwrite($socket, $command . "\r\n");
    $response = smtp_read($socket);
    if (strpos(trim($response), $expected) !== 0) {
        fwrite(STDERR, sprintf("Fallo en «%s»\n  esperado %s, recibido: %s\n",
            preg_replace('/^AUTH.*|^MAIL FROM.*/i', '(credenciales)', $command),
            $expected, trim($response)));
        fclose($socket);
        exit(1);
    }
    return $response;
}

smtp_cmd($socket, 'EHLO osticket.local', '250');
smtp_cmd($socket, 'MAIL FROM:<' . $from . '>', '250');
smtp_cmd($socket, 'RCPT TO:<' . $to . '>', '250');
smtp_cmd($socket, 'DATA', '354');

$body = "Este correo lo envió tools/dev/mail-test.php.\n\n"
    . "Marcador: $marker\n"
    . "Hora: " . date('c') . "\n\n"
    . "Si lo ves en Mailpit, el buzón recibe correo y el problema, si lo hay,\n"
    . "está en la cuenta de correo de osTicket (Admin → Emails).\n";

$headers = array(
    'From: osTicket <' . $from . '>',
    'To: <' . $to . '>',
    'Subject: ' . $subject . ' (' . $marker . ')',
    'Date: ' . date('r'),
    'Message-ID: <' . $marker . '@osticket.local>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
);

fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n"
    . str_replace("\n.", "\n..", $body) . "\r\n.\r\n");
$response = smtp_read($socket);
if (strpos(trim($response), '250') !== 0) {
    fwrite(STDERR, 'El buzón rechazó el mensaje: ' . trim($response) . "\n");
    fclose($socket);
    exit(1);
}

fwrite($socket, "QUIT\r\n");
@fclose($socket);

printf("✓ Aceptado por %s:%d para <%s>\n", $host, $port, $to);
printf("  Abre la interfaz de Mailpit y busca el marcador %s.\n", $marker);
printf("  Si no aparece, revisa `docker compose logs mailpit`.\n");
