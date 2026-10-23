<?php
/*********************************************************************
    class.http.php

    Http helper.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
class Http {

    static function header_code_verbose($code) {
        switch($code):
        case 200: return '200 OK';
        case 201: return '201 Created';
        case 204: return '204 No Content';
        case 205: return '205 Reset Content';
        case 400: return '400 Bad Request';
        case 401: return '401 Unauthorized';
        case 403: return '403 Forbidden';
        case 404: return '404 Not Found';
        case 405: return '405 Method Not Allowed';
        case 416: return '416 Requested Range Not Satisfiable';
        case 418: return "418 I'm a teapot";
        case 422: return '422 Unprocessable Entity';
        default:  return '500 Internal Server Error';
        endswitch;
    }

    static function response($code,$content=false,$contentType='text/html',$charset='UTF-8') {

        header('HTTP/1.1 '.Http::header_code_verbose($code));
		header('Status: '.Http::header_code_verbose($code)."\r\n");
		header("Connection: Close\r\n");
        $ct = "Content-Type: $contentType";
        if ($charset)
            $ct .= "; charset=$charset";
        header($ct);
        if (is_string($content)) {
            header('Content-Length: '.strlen($content)."\r\n\r\n");
            print $content;
            exit;
        }
    }

    /*
     *  Flush the content to requester without exiting
     *
     */
    static function flush($code, $content, $contentType='text/html', $charset='UTF-8') {
        self::response($code, null, $contentType, $charset);
        header('Cache-Control: no-cache, must-revalidate');
        header('Content-Length: '.strlen($content)."\r\n\r\n");
        print $content;
        // Flush the request buffer
        while(@ob_end_flush());
        flush();
        // Terminate the request
        if (function_exists('fastcgi_finish_request'))
            fastcgi_finish_request();
    }

    static function redirect($url,$delay=0,$msg='') {

        $iis = strpos($_SERVER['SERVER_SOFTWARE'], 'IIS') !== false;
        @list($name, $version) = explode('/', $_SERVER['SERVER_SOFTWARE']);
        // Legacy code for older versions of IIS that would not emit the
        // correct HTTP status and headers when using the `Location`
        // header alone
        if ($iis && version_compare($version, '7.0', '<')) {
            header("Refresh: $delay; URL=$url");
        }else{
            header("Location: $url");
        }
        print('<html></html>');
        flush();
        ob_flush();
        exit;
    }

    static function cacheable($etag, $modified=false, $ttl=3600) {
        // Thanks, http://stackoverflow.com/a/1583753/1025836
        // Timezone doesn't matter here — but the time needs to be
        // consistent round trip to the browser and back.
        if ($modified) {
            $last_modified = strtotime($modified." GMT");
            header("Last-Modified: ".date('D, d M Y H:i:s', $last_modified)." GMT", false);
        }
        header('ETag: "'.$etag.'"');
        header("Cache-Control: private, max-age=$ttl");
        header('Expires: ' . gmdate('D, d M Y H:i:s', Misc::gmtime() + $ttl)." GMT");
        header('Pragma: private');
        if (($modified && @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $last_modified)
            || @trim($_SERVER['HTTP_IF_NONE_MATCH'], '" ') == $etag) {
                header("HTTP/1.1 304 Not Modified");
                exit();
        }
    }

    /**
     * Creates the filename=... part of the Content-Disposition header. This
     * is browser dependent, so the user agent is inspected to determine the
     * best encoding format for the filename
     */
    static function getDispositionFilename($filename) {
        $user_agent = strtolower ($_SERVER['HTTP_USER_AGENT']);
        if (false !== strpos($user_agent,'msie')
                && false !== strpos($user_agent,'win'))
            return 'filename='.rawurlencode($filename);
        else
            // Use RFC5987
            return "filename*=UTF-8''".rawurlencode($filename);
    }

    static function download($filename, $type, $data=null, $disposition='attachment') {
        if (strpos($type, 'image/') !== false && preg_match('/image\/.*\+.*/', $type))
          $disposition='attachment';

        header('Pragma: private');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Cache-Control: private', false);
        header('Content-Type: '.$type);
        header(sprintf('Content-Disposition: %s; %s',
            $disposition,
            self::getDispositionFilename(basename($filename))));
        header('Content-Transfer-Encoding: binary');
        if ($data !== null) {
            header('Content-Length: '.strlen($data));
            print $data;
            exit;
        }
    }

    static function build_query($vars, $encode=true, $separator='&amp;') {

        if (!$vars)
            return '';

        if ($encode)
            $vars = Format::htmlchars($vars);

        return http_build_query($vars, '', $separator);
    }

    /**
     * host
     *
     * Return the current request host after validating it against a strict
     * grammar. The Host header is fully attacker controlled and is used to
     * build cookies, redirects, canonical links and outbound email links, so
     * anything that is not a plain hostname / IP literal (optionally with a
     * port) is discarded and replaced with the server name.
     *
     * @param string $default Value returned when no Host header is present
     * @return string A safe "host[:port]" string
     */
    static function host($default = null) {
        $host = isset($_SERVER['HTTP_HOST']) ? trim($_SERVER['HTTP_HOST']) : '';
        // Hostname (or IPv6 literal) with an optional numeric port
        if (preg_match('/^(\[[0-9a-fA-F:.]+\]|[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?)(?::(\d{1,5}))?$/D', $host, $m)) {
            $name = strtolower($m[1]);
            // Reject absurd lengths and empty labels
            if (strlen($name) <= 253 && strpos($name, '..') === false)
                return isset($m[2]) && $m[2] !== '' ? "$name:{$m[2]}" : $name;
        }

        if ($default)
            return $default;

        // Fall back to the value provided by the web server, never to the
        // raw header.
        $name = isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME']
            ? $_SERVER['SERVER_NAME'] : 'localhost';
        return strtolower(preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $name)) ?: 'localhost';
    }

    /**
     * Emit the security headers shared by every HTML response. The
     * Content-Security-Policy is composed by each interface (client vs
     * staff) because the allowed script sources differ.
     */
    static function security_headers($cfg = null, array $options = []) {
        if (headers_sent())
            return;

        $defaults = [
            'nosniff'   => true,
            'referrer'  => 'strict-origin-when-cross-origin',
            'frame'     => true,
            'hsts'      => true,
            'permissions' => 'geolocation=(), microphone=(), camera=()',
        ];
        $options += $defaults;

        if ($options['nosniff'])
            header('X-Content-Type-Options: nosniff');
        if ($options['referrer'])
            header('Referrer-Policy: ' . $options['referrer']);
        if ($options['permissions'])
            header('Permissions-Policy: ' . $options['permissions']);

        // X-Frame-Options is a legacy fallback for frame-ancestors. It can
        // only express "same origin", so it is skipped when the help desk is
        // explicitly allowed to be framed by a third party.
        $ancestors = $cfg ? $cfg->getAllowIframes() : "'self'";
        if ($options['frame'] && (!$ancestors || trim($ancestors) === "'self'"))
            header('X-Frame-Options: SAMEORIGIN');

        if ($options['hsts'] && osTicket::is_https())
            header('Strict-Transport-Security: max-age=31536000');
    }

    /**
     * Build a well formed Content-Security-Policy header value.
     */
    static function csp($cfg = null, array $directives = []) {
        // Deliberately conservative: only directives that cannot break
        // existing customizations (remote images, plugin endpoints and
        // embedded chat widgets) are enforced by default. Use the
        // $directives argument to tighten them per installation.
        $base = [
            'object-src'  => "'none'",
            'base-uri'    => "'self'",
            'style-src'   => "'self' 'unsafe-inline'",
        ];
        // The agent panel relies on eval() for a few legacy widgets
        $script = "'self' 'unsafe-inline'";
        if (!empty($directives['unsafe-eval']))
            $script .= " 'unsafe-eval'";
        unset($directives['unsafe-eval']);
        $base['script-src'] = $script;

        $base['frame-ancestors'] = ($cfg && $cfg->getAllowIframes())
            ? trim($cfg->getAllowIframes()) : "'self'";

        $directives = array_merge($base, $directives);

        $out = [];
        foreach ($directives as $name => $value) {
            if ($value === null || $value === '')
                continue;
            $out[] = sprintf('%s %s', $name, $value);
        }
        return implode('; ', $out);
    }

    static function domain() {
        $domain = null;
        $host = self::host();
        if (strpos($host, '.') !== false
                && !Validator::is_ip($host))
            // Remote port specification, as it will make an invalid domain
            list($domain) = explode(':', $host);

        return $domain;
    }

    // Get current url
    static function url() {
        // Scheme + Host.
        $https =  osTicket::is_https();
        $url  = sprintf('http%s://%s',
                $https ? 's' : '',
                self::host());
        //  Add Port if not 80 and not https
        $port = osTicket::get_client_port();
        if ($port && $port != 80 && !$https && !str_contains($url, $port))
            $url .= ":{$port}";
        // Path + Query string
        if (isset($_SERVER['REQUEST_URI']))
            $url  .= $_SERVER['REQUEST_URI'];
        else
            $url  .= sprintf('%s?$s',
                    $_SERVER['PHP_SELF'], $_SERVER['QUERY_STRING']);

        return $url;
    }

    // Parse path from given url or the current url
    static function url_path(?string $url = null, bool $htmlentities = true) {
        $path = parse_url($url ?: self::url(), PHP_URL_PATH);
        return ($htmlentities && $path)
            ? htmlentities($path) : $path;
    }

    // Parse query string from current url
    static function query_string(?string $url = null) {
        return parse_url($url ?: self::url(), PHP_URL_QUERY);
    }

    /* ------------------------------------------------------------------ */
    /* Entrada de la petición (frente 4 de docs/CODE_QUALITY.md)           */
    /* ------------------------------------------------------------------ */

    /**
     * Valor de `$_GET`, o `$default` si no viene.
     *
     * Un valor vacío es un valor: `?q=` devuelve `''`, no el valor por
     * defecto. Las listas (`?ids[]=1&ids[]=2`) se devuelven tal cual.
     */
    static function get($name, $default=null) {
        return array_key_exists($name, $_GET) ? $_GET[$name] : $default;
    }

    /** Valor de `$_POST`, o `$default` si no viene. */
    static function post($name, $default=null) {
        return array_key_exists($name, $_POST) ? $_POST[$name] : $default;
    }

    /** Valor de `$_REQUEST`, o `$default` si no viene. */
    static function request($name, $default=null) {
        return array_key_exists($name, $_REQUEST) ? $_REQUEST[$name] : $default;
    }

    /** `$_GET[$name]` como entero. Una lista o un valor no numérico dan 0. */
    static function getInt($name, $default=0) {
        return self::toInt(self::get($name), $default);
    }

    /** `$_POST[$name]` como entero. */
    static function postInt($name, $default=0) {
        return self::toInt(self::post($name), $default);
    }

    /** `$_REQUEST[$name]` como entero. */
    static function requestInt($name, $default=0) {
        return self::toInt(self::request($name), $default);
    }

    private static function toInt($value, $default=0) {
        if (!is_scalar($value))
            return (int) $default;

        return (int) $value;
    }

    // Refresh url
    static function refresh_url(array $queryFilter = []) {
        $url = self::url();
        $refresh_url = self::url_path($url);
        if (($qs=self::query_string($url)))
            $refresh_url .= sprintf('?%s',
                    Format::http_query_string($qs, $queryFilter));
        return $refresh_url;
    }
}
?>
