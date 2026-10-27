<?php
/**
 * include/class.mailer.message.php
 *
 * Piezas de `Mailer::send()` que no envían nada ni tocan la base de datos
 * (frente 2 de docs/CODE_QUALITY.md): el asunto de una línea, las cabeceras
 * que dependen del tipo de aviso, el envoltorio que lleva el identificador
 * del mensaje, el cuerpo de texto, el dominio de las imágenes incrustadas, el
 * separador de la respuesta citada y la reescritura de los `cid:`.
 *
 * Lo que NO está aquí, a propósito: las cuentas SMTP, el envío por Sendmail y
 * los destinatarios. Los destinatarios se traducen a `to`/`cc`/`bcc` según su
 * clase (`TicketOwner`, `Staff`, `Collaborator`…), así que para probarlos
 * habría que construir medio osTicket; eso queda para la prueba de
 * integración.
 *
 * El archivo vive en el mismo espacio de nombres que `Mailer` y se puede
 * cargar solo (con `Format`, para el cuerpo de texto).
 */
namespace osTicket\Mail;

class MailerMessage {

    /**
     * Asunto en una sola línea: sin saltos de línea, sin espacios en los
     * extremos. Un asunto con un salto se colaría como cabecera nueva.
     */
    static function cleanSubject($subject) {
        return preg_replace("/(\r\n|\r|\n)/s", '', trim((string) $subject));
    }

    /**
     * Cabeceras que dependen del tipo de aviso.
     *
     * Se aplican en este orden con `+=`, así que **lo primero gana**, igual
     * que antes: `bulk`, luego `autoreply` y luego `notice`. Un correo que sea
     * a la vez automático y aviso sale con `Auto-Submitted: auto-replied`.
     *
     * @return array  nombre de cabecera => valor
     */
    static function extraHeaders(array $options) {
        $headers = array();

        // Boletín
        if (isset($options['bulk']) && $options['bulk'])
            $headers += array('Precedence' => 'bulk');

        // Respuesta automática: marcarla y suprimir las respuestas automáticas
        if (isset($options['autoreply']) && $options['autoreply'])
            $headers += array(
                    'Precedence' => 'auto_reply',
                    'X-Autoreply' => 'yes',
                    'X-Auto-Response-Suppress' => 'DR, RN, OOF, AutoReply',
                    'Auto-Submitted' => 'auto-replied');

        // Aviso: es automático, pero no queremos respuestas automáticas de vuelta
        if (isset($options['notice']) && $options['notice'])
            $headers += array(
                    'X-Auto-Response-Suppress' => 'OOF, AutoReply',
                    'Auto-Submitted' => 'auto-generated');

        return $headers;
    }

    /**
     * Envoltorio invisible con el identificador del mensaje y el separador de
     * la respuesta citada, delante del cuerpo.
     *
     * El formato es el de siempre, saltos de línea y sangría incluidos: el
     * correo que recibe el cliente no cambia.
     */
    static function wrapMid($body, $midToken, $replyTag) {
        if (!$replyTag && !$midToken)
            return $body;

        return sprintf('<div style="display:none"
                        class="mid-%s">%s</div>%s',
                        $midToken,
                        $replyTag,
                        $body);
    }

    /**
     * Cuerpo de texto a partir del HTML, más la referencia al identificador
     * del mensaje que usa osTicket para enlazar respuestas.
     */
    static function textBody($body, $messageId = null) {
        $text = rtrim(\Format::html2text($body, 90, false));

        return $text . ($messageId ? "\nRef-Mid: $messageId\n" : '');
    }

    /**
     * Separador que se pone delante de la respuesta citada.
     *
     * Tres casos, en este orden (es el orden del código original):
     *
     *   - la opción no viene: el separador configurado, si se recorta la cita,
     *     y nada si no;
     *   - la opción viene en `false`: nada (el llamador lo pidió así);
     *   - la opción viene con texto: tal cual.
     */
    static function replyTag(array $options, $stripQuoted, $separator = '') {
        if (!array_key_exists('reply-tag', $options))
            return $stripQuoted ? $separator . '<br/><br/>' : '';

        return $options['reply-tag'] === false ? '' : (string) $options['reply-tag'];
    }

    /**
     * Los `cid:` de las imágenes incrustadas llevan su identificador corto
     * (32 caracteres) y, en el correo que sale, el dominio detrás.
     *
     * `$link` recibe el identificador y devuelve el sufijo a añadir —el
     * dominio— o `null` para dejar ese `cid:` como está (por ejemplo, si el
     * archivo ya no existe: el cuerpo conserva la referencia rota en vez de
     * perder texto).
     */
    static function rewriteContentIds($body, $domain, callable $link) {
        return preg_replace_callback('/cid:([\w.-]{32})/',
            function ($match) use ($domain, $link) {
                $suffix = $link($match[1]);

                return $suffix === null ? $match[0] : $match[0] . $suffix;
            }, (string) $body);
    }

    /**
     * Dominio con el que se completan los `cid:` de las imágenes
     * incrustadas. Tiene que ser compatible con PEAR `Mail_Mime`; si la
     * dirección de salida no trae dominio, se usa `@localhost`.
     */
    static function contentIdDomain($fromAddress) {
        $matches = array();

        if (preg_match('#(@[0-9a-zA-Z\-\.]+)#', (string) $fromAddress, $matches))
            return $matches[1];

        return '@localhost';
    }
}
