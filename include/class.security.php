<?php
/**
 * include/class.security.php
 *
 * Postura de seguridad del despliegue.
 *
 * Reúne las comprobaciones que dependen del entorno (no del código de la
 * aplicación) y que históricamente se pasan por alto:
 *
 *   - Versión de PHP frente a vulnerabilidades conocidas del intérprete
 *     (php2shell: CVE-2026-109618 / CVE-2026-109619).
 *   - Directivas `php.ini` que amplían la superficie de ataque.
 *   - Secretos derivados de datos públicos (SECRET_SALT).
 *
 * Se usa desde el panel de administración (avisos) y desde
 * `tools/security/php-cve-check.php` (CLI/CI/Docker), por lo que **no debe
 * depender de nada** salvo funciones nativas de PHP.
 */

class SecurityPosture
{
    /** Vulnerabilidades del intérprete con fecha de divulgación 2026-10-21 */
    const PHP2SHELL_ADVISORY = 'CVE-2026-109618 (GHSA-w8hw-w9fx-j79w) / CVE-2026-109619 (GHSA-6wmf-4rv5-p3rp)';

    /**
     * Primera versión corregida de cada rama soportada.
     * Las ramas no listadas (<= 8.1) están fuera de soporte y no reciben
     * parche del proyecto PHP.
     */
    public static $phpFixedVersions = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** @var array Niveles ordenados de menor a mayor gravedad */
    public static $levels = ['info', 'warning', 'critical'];

    /**
     * Convierte "8.4.27" o "8.4.27-1ubuntu1" en [8, 4, 27].
     */
    public static function parseVersion($version)
    {
        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', (string) $version, $m))
            return null;

        return [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
    }

    /**
     * Evalúa una versión de PHP frente a php2shell.
     *
     * @param string $version           Versión a evaluar (por defecto la actual)
     * @param bool   $assumePatched     true cuando la distribución ha
     *                                  backporteado el parche a una rama EOL
     * @return array{vulnerable:bool,eol:bool,severity:string,fixed:?string,branch:string}
     */
    public static function checkPhpVersion($version = PHP_VERSION, $assumePatched = false)
    {
        $parts = self::parseVersion($version);
        if (!$parts)
            return [
                'vulnerable' => true,
                'eol'        => true,
                'severity'   => 'critical',
                'fixed'      => null,
                'branch'     => 'unknown',
            ];

        list($major, $minor, $patch) = $parts;
        $branch = sprintf('%d.%d', $major, $minor);

        if (!isset(self::$phpFixedVersions[$branch])) {
            // Rama sin soporte oficial: no habrá parche del proyecto PHP.
            return [
                'vulnerable' => !$assumePatched,
                'eol'        => true,
                'severity'   => $assumePatched ? 'warning' : 'critical',
                'fixed'      => null,
                'branch'     => $branch,
            ];
        }

        $fixed = self::parseVersion(self::$phpFixedVersions[$branch]);
        $vulnerable = version_compare(
            sprintf('%d.%d.%d', $major, $minor, $patch),
            self::$phpFixedVersions[$branch],
            '<'
        );

        return [
            'vulnerable' => $vulnerable && !$assumePatched,
            'eol'        => false,
            'severity'   => ($vulnerable && !$assumePatched) ? 'critical' : 'ok',
            'fixed'      => self::$phpFixedVersions[$branch],
            'branch'     => $branch,
        ];
    }

    /**
     * Devuelve un listado de problemas del entorno ordenado por gravedad.
     *
     * Cada entrada: ['code', 'level', 'message', 'current', 'recommended']
     *
     * @param object|null $cfg Configuración de osTicket (opcional)
     * @return array
     */
    public static function getIssues($cfg = null)
    {
        $issues = [];

        // 1. Versión de PHP -------------------------------------------------
        $assumePatched = (bool) getenv('OST_PHP2SHELL_BACKPORT');
        $status = self::checkPhpVersion(PHP_VERSION, $assumePatched);
        if ($status['vulnerable']) {
            $issues[] = [
                'code' => 'php2shell',
                'level' => 'critical',
                'message' => sprintf(
                    'PHP %s is affected by php2shell (%s). Update to %s or later; it is the only complete fix.',
                    PHP_VERSION,
                    self::PHP2SHELL_ADVISORY,
                    $status['fixed'] ?: 'a supported PHP branch (8.2.35+, 8.3.36+, 8.4.27+, 8.5.12+)'
                ),
                'current' => PHP_VERSION,
                'recommended' => $status['fixed'] ?: '8.4.27+',
            ];
        }
        elseif ($status['eol']) {
            $issues[] = [
                'code' => 'php-eol',
                'level' => 'warning',
                'message' => sprintf(
                    'PHP %s is end-of-life and no longer receives security fixes. Upgrade to a supported branch.',
                    PHP_VERSION
                ),
                'current' => PHP_VERSION,
                'recommended' => '8.4.27+',
            ];
        }

        // 2. Directivas relevantes para php2shell ---------------------------
        // Desactivar el progreso de subida elimina una de las rutas
        // confirmadas de CVE-2026-109619.
        if (self::iniFlagEnabled('session.upload_progress.enabled')) {
            $issues[] = [
                'code' => 'upload-progress',
                'level' => 'warning',
                'message' => 'session.upload_progress.enabled is on; osTicket does not need it and it is one of the confirmed php2shell paths.',
                'current' => 'On',
                'recommended' => 'Off',
            ];
        }

        // PHP 8.5: las trazas de errores fatales habilitan la ruta más
        // fiable de CVE-2026-109619.
        $backtraces = ini_get('fatal_error_backtraces');
        if ($backtraces !== false && self::iniFlagEnabled('fatal_error_backtraces')) {
            $issues[] = [
                'code' => 'fatal-backtraces',
                'level' => 'warning',
                'message' => 'fatal_error_backtraces is on (PHP 8.5). Turn it off; it is the path behind the confirmed php2shell exploit.',
                'current' => 'On',
                'recommended' => 'Off',
            ];
        }

        // 3. Configuración general ------------------------------------------
        if (self::iniFlagEnabled('display_errors')) {
            $issues[] = [
                'code' => 'display-errors',
                'level' => 'warning',
                'message' => 'display_errors is on; PHP errors leak paths and SQL to visitors.',
                'current' => 'On',
                'recommended' => 'Off',
            ];
        }

        if (self::iniFlagEnabled('expose_php')) {
            $issues[] = [
                'code' => 'expose-php',
                'level' => 'info',
                'message' => 'expose_php advertises the PHP version in every response.',
                'current' => 'On',
                'recommended' => 'Off',
            ];
        }

        $postMax = self::iniBytes(ini_get('post_max_size'));
        if ($postMax > 32 * 1024 * 1024) {
            $issues[] = [
                'code' => 'post-max-size',
                'level' => 'info',
                'message' => 'post_max_size is above 32M; PHP recommends the lowest value that keeps uploads working.',
                'current' => ini_get('post_max_size'),
                'recommended' => '32M or less',
            ];
        }

        // 4. Secretos -------------------------------------------------------
        if (defined('SECRET_SALT') && defined('ADMIN_EMAIL') && defined('TABLE_PREFIX')
                && SECRET_SALT === md5(TABLE_PREFIX . ADMIN_EMAIL)) {
            $issues[] = [
                'code' => 'weak-secret-salt',
                'level' => 'critical',
                'message' => 'SECRET_SALT is derived from public data. Define a long random SECRET_SALT in include/ost-config.php.',
                'current' => 'derived',
                'recommended' => 'random, 32+ characters',
            ];
        }

        usort($issues, function ($a, $b) {
            return array_search($b['level'], self::$levels, true)
                - array_search($a['level'], self::$levels, true);
        });

        return $issues;
    }

    /** Devuelve el problema más grave, o null. */
    public static function getMostSevereIssue($cfg = null)
    {
        $issues = self::getIssues($cfg);
        return $issues ? $issues[0] : null;
    }

    /** true si la versión de PHP en ejecución está afectada. */
    public static function isVulnerablePhp()
    {
        $status = self::checkPhpVersion(PHP_VERSION,
            (bool) getenv('OST_PHP2SHELL_BACKPORT'));
        return $status['vulnerable'];
    }

    /**
     * Interpreta un valor booleano de php.ini.
     */
    public static function iniFlagEnabled($name)
    {
        $value = ini_get($name);
        if ($value === false)
            return false;

        return in_array(strtolower(trim((string) $value)),
            ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * Convierte "32M", "1G" o "512K" a bytes.
     */
    public static function iniBytes($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1')
            return $value === '-1' ? PHP_INT_MAX : 0;

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        switch ($unit) {
            case 'g': $number *= 1024;
            // no break
            case 'm': $number *= 1024;
            // no break
            case 'k': $number *= 1024;
        }

        return (int) $number;
    }

    /**
     * ¿Está /setup/ realmente accesible por HTTP?
     *
     * De la existencia del directorio no se deduce nada: en este despliegue
     * nginx lo bloquea (bloque "Producción" de docker/nginx/default.conf) y
     * la carpeta sigue en el repositorio porque es código versionado. Para no
     * pedirle al administrador que borre archivos versionados, se comprueba
     * de verdad si el instalador responde.
     *
     * Devuelve true (expuesto, hay que avisar) o false (bloqueado, silencio).
     * Si no hay forma de comprobarlo, devuelve true para no ocultar el aviso.
     */
    public static function isSetupExposed() {
        // Escape explícito para despliegues que bloquean /setup/ de otra forma
        // (proxy externo, WAF, autenticación básica).
        if (getenv('OST_SETUP_LOCKED') === '1')
            return false;

        // Sólo se puede comprobar si hay una URL interna alcanzable desde el
        // contenedor de PHP (en Docker, el servicio `nginx`).
        $base = getenv('OST_INTERNAL_URL') ?: '';
        if (!$base)
            return true;

        $cache = sys_get_temp_dir() . '/ost-setup-probe-' . md5($base) . '.cache';
        $now = time();

        // 5 minutos de validez para un resultado definitivo, 30 s si falló.
        if (is_readable($cache)
                && ($data = @file_get_contents($cache)) !== false
                && ($parts = explode(' ', trim($data))) === 2
                && is_numeric($parts[1])) {
            list($state, $when) = $parts;
            $ttl = ($state === 'unknown') ? 30 : 300;
            if ($now - (int) $when < $ttl)
                return $state !== 'blocked';
        }

        $status = self::probeSetupStatus($base);
        self::setSetupProbeStatus($status);

        if ($status === 403 || $status === 404)
            $state = 'blocked';
        elseif ($status === 200)
            $state = 'exposed';
        else
            // Sin respuesta: no se puede afirmar nada. Se avisa (postura
            // conservadora) y se reintenta pronto.
            $state = 'unknown';

        @file_put_contents($cache, $state . ' ' . $now);

        return $state !== 'blocked';
    }

    /** Último código HTTP visto por el sondeo (0 = sin respuesta). */
    private static $setupProbeStatus = null;

    public static function setSetupProbeStatus($status) {
        self::$setupProbeStatus = $status;
    }

    public static function getSetupProbeStatus() {
        return self::$setupProbeStatus;
    }

    /**
     * Pregunta por HTTP si /setup/install.php responde.
     *
     * Se hace con fsockopen y una petición HTTP/1.0 a mano, NO con
     * file_get_contents: bootstrap.php ejecuta ini_set('allow_url_fopen', 0)
     * y el wrapper http:// deja de funcionar, con lo que el sondeo siempre
     * habría concluido "sin respuesta".
     *
     * @return int código HTTP (0 si no hubo respuesta)
     */
    public static function probeSetupStatus($base, $timeout = 1.0) {
        $parts = parse_url($base);
        if (!$parts || empty($parts['host']))
            return 0;

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = rtrim($parts['path'] ?? '', '/') . '/setup/install.php';

        $target = ($scheme === 'https' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @fsockopen($target, $port, $errno, $errstr, $timeout);
        if (!$fp)
            return 0;

        stream_set_timeout($fp, (int) ceil($timeout));
        $hostHeader = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        @fwrite($fp, "GET {$path} HTTP/1.0\r\n"
            . "Host: {$hostHeader}\r\n"
            . "User-Agent: osTicket-setup-probe\r\n"
            . "Connection: close\r\n\r\n");

        $line = @fgets($fp, 256);
        @fclose($fp);

        if ($line && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m))
            return (int) $m[1];

        return 0;
    }

}
