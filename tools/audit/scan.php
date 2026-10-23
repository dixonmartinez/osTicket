#!/usr/bin/env php
<?php
/**
 * tools/audit/scan.php
 *
 * Dependency-free static security scanner for osTicket.
 *
 * It is intentionally *not* a full SAST engine: it encodes the patterns that
 * have historically caused vulnerabilities in this code base (reflected XSS,
 * SQL string interpolation, dynamic includes, weak password handling, unsafe
 * session/cookie settings, leftover debug code, ...) so that:
 *
 *   1. Every change can be validated in a couple of seconds.
 *   2. Regressions are caught before they reach production.
 *
 * Usage:
 *   php tools/audit/scan.php                     # scan the repository
 *   php tools/audit/scan.php --json              # machine readable output
 *   php tools/audit/scan.php --severity=medium   # only medium and above
 *   php tools/audit/scan.php --baseline=.audit-baseline.json
 *   php tools/audit/scan.php --fail-on=none      # never fail (report mode)
 *
 * Exit codes: 0 = clean, 1 = findings at/above --fail-on, 2 = usage error.
 *
 * Suppressing a finding on purpose:
 *   // audit:ignore OST-XSS-ECHO -- reason
 * on the offending line, or on the line immediately above it.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line\n");
    exit(2);
}

const SCAN_VERSION = '1.0.0';

class Finding {
    public $rule;
    public $severity;
    public $file;
    public $line;
    public $message;
    public $snippet;

    public function __construct($rule, $severity, $file, $line, $message, $snippet = '') {
        $this->rule = $rule;
        $this->severity = $severity;
        $this->file = $file;
        $this->line = $line;
        $this->message = $message;
        $this->snippet = trim($snippet);
    }

    public function fingerprint() {
        return sha1($this->rule . '|' . $this->file . '|' . $this->snippet);
    }

    public function toArray() {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity,
            'file' => $this->file,
            'line' => $this->line,
            'message' => $this->message,
            'snippet' => $this->snippet,
            'fingerprint' => $this->fingerprint(),
        ];
    }
}

class Scanner {
    const SEVERITIES = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0];

    /** Directories that are third party code and out of scope */
    private $excluded = [
        '/.git/',
        '/node_modules/',
        '/include/pear/',
        '/include/fpdf/',
        '/include/mpdf/',
        '/include/laminas-mail/',
        '/include/tcpdf/',
        '/vendor/',
        '/include/Spyc.php',
        '/include/htmLawed.php',
        '/setup/test/',
        // Configuración LOCAL de la instalación: la genera el instalador (o el
        // entrypoint desde .env), contiene credenciales por definición y está
        // en .gitignore. Escanearla solo produce falsos positivos.
        '/include/ost-config.php',
        '/include/settings.php',
    ];

    /** Directories scanned when the tool is run without arguments */
    private $roots = [
        'account.php', 'ajax.php', 'avatar.php', 'bootstrap.php', 'captcha.php',
        'client.inc.php', 'file.php', 'index.php', 'login.php', 'logo.php',
        'logout.php', 'main.inc.php', 'manage.php', 'offline.php', 'open.php',
        'profile.php', 'pwreset.php', 'secure.inc.php', 'tickets.php',
        'view.php', 'api', 'apps', 'include', 'kb', 'pages', 'scp', 'setup',
        'tools',
    ];

    public $findings = [];
    private $ignored = [];      // rule => true when the line opts out
    private $baseline = [];

    /** Variables/expression that make an echo safe */
    private static $sanitizers = [
        'htmlchars', 'htmlspecialchars', 'htmlentities', 'intval', 'floatval',
        'urlencode', 'rawurlencode', 'json_encode', 'Format::striptags',
        'Format::sanitize', 'Format::safe_html', 'dn', 'db_input', 'nl2br',
        'ThreadEntryBody::clean', 'Http::build_query', 'Format::json_encode',
        'Format::striptags', 'Crypto::',
    ];

    private static $superglobals = [
        '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES',
    ];

    public function defaultRoots() {
        return $this->roots;
    }

    public function setBaseline(array $fps) {
        $this->baseline = array_fill_keys($fps, true);
    }

    public function scan($root, array $paths) {
        foreach ($paths as $path) {
            $full = rtrim($root, '/') . '/' . ltrim($path, '/');
            if (is_file($full)) {
                $this->scanFile($full, $root);
            }
            elseif (is_dir($full)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->getExtension() !== 'php')
                        continue;
                    $this->scanFile($f->getPathname(), $root);
                }
            }
        }
        usort($this->findings, function ($a, $b) {
            $s = self::SEVERITIES[$b->severity] - self::SEVERITIES[$a->severity];
            return $s ?: strcmp($a->file . $a->line, $b->file . $b->line);
        });
    }

    private function isExcluded($file) {
        $file = str_replace('\\', '/', $file);
        foreach ($this->excluded as $dir)
            if (strpos($file, $dir) !== false)
                return true;
        return false;
    }

    private function add($rule, $severity, $file, $line, $message, $snippet = '') {
        $rel = ltrim(str_replace($this->relativeRoot, '', $file), '/');
        $f = new Finding($rule, $severity, $rel, $line, $message, $snippet);
        if (isset($this->baseline[$f->fingerprint()]))
            return;
        $this->findings[] = $f;
    }

    private $relativeRoot = '';

    public function scanFile($file, $root) {
        if ($this->isExcluded($file))
            return;
        $this->relativeRoot = rtrim($root, '/') . '/';
        $code = file_get_contents($file);
        if ($code === false || $code === '')
            return;

        $lines = preg_split("/\r\n|\n|\r/", $code);
        $this->scanTokens($file, $code, $lines);
        $this->scanPatterns($file, $lines);
    }

    /* ------------------------------------------------------------------ */
    /* Token based rules (structure aware)                                 */
    /* ------------------------------------------------------------------ */
    private function scanTokens($file, $code, array $lines) {
        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        }
        catch (Throwable $t) {
            return; // Syntax errors are reported by tools/lint.sh
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok))
                continue;

            list($id, $text, $line) = $tok;

            // Honour `// audit:ignore` annotations for token rules too
            if ($this->isIgnored($lines, $line - 1))
                continue;

            /* -- echo / print / <?= of raw request data ---------------- */
            if (in_array($id, [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
                $expr = $this->collectExpression($tokens, $i);
                if ($this->isTaintedEcho($expr)) {
                    $this->add('OST-XSS-ECHO', 'high', $file, $line,
                        'Request data is printed without escaping (use Format::htmlchars())',
                        $this->snippet($lines, $line));
                }
            }

            /* -- dangerous sinks --------------------------------------- */
            if ($id === T_STRING) {
                $fn = strtolower($text);
                $map = [
                    'eval' => ['OST-EVAL', 'critical', 'eval() with dynamic data can lead to remote code execution'],

                    'create_function' => ['OST-EVAL', 'high', 'create_function() is eval() in disguise'],
                    'system' => ['OST-RCE', 'high', 'system() executes shell commands'],
                    'shell_exec' => ['OST-RCE', 'high', 'shell_exec() executes shell commands'],
                    'passthru' => ['OST-RCE', 'high', 'passthru() executes shell commands'],
                    'exec' => ['OST-RCE', 'high', 'exec() executes shell commands'],
                    'popen' => ['OST-RCE', 'medium', 'popen() executes shell commands'],
                    'pcntl_exec' => ['OST-RCE', 'high', 'pcntl_exec() executes programs'],
                ];
                if (isset($map[$fn]) && $this->isCall($tokens, $i)) {
                    list($rule, $sev, $msg) = $map[$fn];
                    $this->add($rule, $sev, $file, $line, $msg,
                        $this->snippet($lines, $line));
                }

                /* -- object injection / deserialization ---------------- */
                if (in_array($fn, ['unserialize'], true) && $this->isCall($tokens, $i)) {
                    $this->add('OST-OBJ-INJECT', 'medium', $file, $line,
                        'unserialize() on untrusted data allows object injection; prefer JSON',
                        $this->snippet($lines, $line));
                }

                /* -- weak random for security tokens ------------------- */
                if (in_array($fn, ['rand', 'mt_rand', 'uniqid', 'lcg_value'], true)
                        && $this->isCall($tokens, $i)
                        && $this->lineMentions($lines, $line, ['token', 'salt', 'secret', 'passw', 'key', 'otp', 'nonce'])) {
                    $this->add('OST-WEAK-RANDOM', 'high', $file, $line,
                        'Non-cryptographic random source used for a security sensitive value (use Crypto::random())',
                        $this->snippet($lines, $line));
                }

                /* -- dynamic include ----------------------------------- */
                if (in_array($fn, ['include', 'include_once', 'require', 'require_once'], true)) {
                    $expr = $this->collectExpression($tokens, $i);
                    if (preg_match('/\$[A-Za-z_]/', $expr)) {
                        $this->add('OST-LFI', 'medium', $file, $line,
                            'File included from a variable path; ensure it cannot be influenced by the request',
                            $this->snippet($lines, $line));
                    }
                }
            }

            /* -- SQL built by string interpolation --------------------- */
            if ($id === T_VARIABLE && in_array($text, self::$superglobals, true)) {
                $this->checkSqlInterpolation($file, $tokens, $i, $line, $lines);
            }

            /* -- md5/sha1 used for passwords --------------------------- */
            if ($id === T_STRING && in_array(strtolower($text), ['md5', 'sha1'], true)
                    && $this->isCall($tokens, $i)
                    && basename($file) !== 'PasswordHash.php'
                    && $this->lineMentions($lines, $line, ['passw', 'pwd'])) {
                $this->add('OST-WEAK-HASH', 'medium', $file, $line,
                    'Legacy md5()/sha1() password path — ensure it re-hashes to bcrypt',
                    $this->snippet($lines, $line));
            }
        }
    }

    /** Collect the text of the expression that follows a token until ; or , */
    private function collectExpression(array $tokens, $start, $max = 60) {
        $out = '';
        $depth = 0;
        for ($i = $start + 1, $n = count($tokens); $i < $n && $i < $start + $max; $i++) {
            $t = $tokens[$i];
            $text = is_array($t) ? $t[1] : $t;
            if ($text === '(' || $text === '[')
                $depth++;
            elseif ($text === ')' || $text === ']') {
                if ($depth === 0)
                    break;
                $depth--;
            }
            elseif ($text === ';' && $depth === 0)
                break;
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    private function isCall(array $tokens, $i) {
        // Method calls ($obj->exec()) and declarations (function exec())
        // are not the dangerous global functions this rule targets.
        for ($j = $i - 1; $j >= 0 && $j > $i - 4; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
                continue;
            if ($t === '-' || $t === ':' || (is_array($t)
                    && in_array($t[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)))
                return false;
            break;
        }
        for ($j = $i + 1, $n = count($tokens); $j < $n && $j < $i + 3; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE)
                continue;
            return $tokens[$j] === '(';
        }
        return false;
    }

    private function isTaintedEcho($expr) {
        // `"\$_GET"` es texto literal dentro de la cadena, no la variable: la
        // barra invertida la escapa. Sin esto, un mensaje de ayuda que nombra
        // las superglobales se marca como XSS (le pasó a
        // tools/quality/input-audit.php).
        $expr = str_replace('\\$', '', $expr);

        $tainted = false;
        foreach (self::$superglobals as $sg)
            if (strpos($expr, $sg) !== false)
                $tainted = true;
        // Variables that look like request data but were re-assigned are
        // not tracked; require an explicit superglobal use to stay silent.
        if (!$tainted)
            return false;
        foreach (self::$sanitizers as $fn)
            if (stripos($expr, $fn) !== false)
                return false;
        if (preg_match('/\(\s*int\s*\)|\(\s*float\s*\)|\(\s*bool\s*\)/', $expr))
            return false;
        return true;
    }

    private function checkSqlInterpolation($file, array $tokens, $i, $line, array $lines) {
        // Join the current statement (a small window is enough for the
        // idioms used by this code base) and look for a SQL string literal
        // that is directly concatenated with request data, ie:
        //   '... WHERE name="' . $_POST['name'] . '"'
        $stmt = '';
        for ($j = $i - 1, $min = max(0, $i - 25); $j >= $min; $j--) {
            $t = $tokens[$j];
            $text = is_array($t) ? $t[1] : $t;
            if ($text === ';' || $text === '{' || $text === '}'
                    || (is_array($t) && $t[0] === T_OPEN_TAG))
                break;
            $stmt = $text . $stmt;
        }
        $stmt .= (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]);

        // The query string itself must contain a SQL keyword and must be
        // concatenated with (or interpolate) the request value.
        if (!preg_match('/["\'][^"\']*\b(select|insert|update|delete|replace|where|order\s+by)\b[^"\']*["\']\s*\.\s*\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER)/i', $stmt)
                && !preg_match('/["\'][^"\']*\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER)[^"\']*\b(select|insert|update|delete|replace|where)\b/i', $stmt))
            return;

        // Escaped values are fine
        if (preg_match('/db_input\s*\(|db_real_escape|db_escape|intval|\(int\)/i', $stmt))
            return;

        $this->add('OST-SQLI', 'critical', $file, $line,
            'SQL statement concatenates request data; escape with db_input() or use the ORM',
            $this->snippet($lines, $line));
    }

    /* ------------------------------------------------------------------ */
    /* Line based rules                                                    */
    /* ------------------------------------------------------------------ */
    private function scanPatterns($file, array $lines) {
        $rules = [
            // rule, severity, regex, message
            ['OST-XSS-ATTR', 'high',
             '/\$\$\w+\s*=\s*[\'"][^\'"]*(?:class|style|href|src|value)=.*\$_(GET|POST|REQUEST|COOKIE)/i',
             'Request data is concatenated into an HTML attribute (escape or whitelist it)'],
            ['OST-DEBUG-DUMP', 'low',
             '/(?<!->)\b(var_dump|print_r|debug_zval_dump)\s*\(/',
             'Debug helper left in the code; it can leak internal data'],
            ['OST-DISPLAY-ERRORS', 'medium',
             '/ini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*(1|true|[\'"]1[\'"])/i',
             'display_errors is being enabled; stack traces leak paths and SQL'],
            ['OST-WEAK-SECRET', 'high',
             '/define\s*\(\s*[\'"]SECRET_SALT[\'"]\s*,\s*md5\s*\(/i',
             'SECRET_SALT is derived from guessable values (set a strong random salt in ost-config.php)'],
            ['OST-HARDCODED-SECRET', 'high',
             '/define\s*\(\s*[\'"](?:SECRET_SALT|API_KEY|DBPASS|PASSWD|PASSWORD|AUTH_KEY)[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*\)/i',
             'Hardcoded secret in source control'],
            ['OST-TLS-VERIFY', 'high',
             '/CURLOPT_SSL_VERIFYPEER\s*,\s*(0|false)/i',
             'TLS certificate verification disabled'],
            ['OST-WEAK-COMPARE', 'medium',
             '/(?:passwd|password|otp|authkey|auth_key|apikey|api_key)\w*\s*==\s*[^=]/i',
             'Loose comparison on a security sensitive value (use hash_equals())'],
            ['OST-INSECURE-COOKIE', 'medium',
             '/session_set_cookie_params\s*\([^)]*,\s*(false|0)\s*\)/i',
             'Session cookie created without the HttpOnly/Secure flags'],
            ['OST-OPEN-REDIRECT', 'medium',
             '/Http::redirect\s*\(\s*\$_(GET|POST|REQUEST)/i',
             'Redirect target taken directly from the request (open redirect)'],
            ['OST-CMD-INPUT', 'high',
             '/(?:system|exec|shell_exec|passthru)\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)/i',
             'Shell command built from request data (command injection)'],
            ['OST-EVAL-INPUT', 'critical',
             '/\beval\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)/i',
             'eval() on request data (remote code execution)'],
            ['OST-XXE', 'high',
             '/simplexml_load_(?:string|file)\s*\(|DOMDocument\s*\([^)]*\)[^;]*->loadXML/i',
             'XML parsed without disabling external entities (check LIBXML_NOENT)'],
        ];

        // phpass implements the legacy MD5 based "$P$" scheme on purpose;
        // flagging it would be noise (the migration path lives in Passwd).
        $basename = basename($file);

        foreach ($lines as $idx => $code) {
            $num = $idx + 1;
            if ($this->isIgnored($lines, $idx))
                continue;
            if ($basename === 'PasswordHash.php'
                    || stripos($basename, 'sampleconfig') !== false)
                continue;
            foreach ($rules as $r) {
                list($rule, $sev, $re, $msg) = $r;
                if (preg_match($re, $code)) {
                    $this->add($rule, $sev, $file, $num, $msg, trim($code));
                }
            }
        }
    }

    private function isIgnored(array $lines, $idx) {
        foreach ([$idx, $idx - 1] as $i) {
            if ($i >= 0 && isset($lines[$i])
                    && stripos($lines[$i], 'audit:ignore') !== false)
                return true;
        }
        return false;
    }

    private function lineMentions(array $lines, $line, array $words) {
        $text = $lines[$line - 1] ?? '';
        foreach ($words as $w)
            if (stripos($text, $w) !== false)
                return true;
        return false;
    }

    private function snippet(array $lines, $line) {
        return isset($lines[$line - 1]) ? trim($lines[$line - 1]) : '';
    }
}

/* ---------------------------------------------------------------------- */
/* CLI                                                                     */
/* ---------------------------------------------------------------------- */
function usage() {
    $help = <<<HELP
osTicket static security scanner (v%s)

  php tools/audit/scan.php [options] [paths...]

Options
  --severity=LEVEL   Minimum severity to report (critical|high|medium|low|info) [low]
  --fail-on=LEVEL    Exit non-zero when a finding at/above LEVEL exists [high]
                     Use --fail-on=none for report-only mode.
  --json             Emit JSON instead of text
  --baseline=FILE    Ignore findings recorded in FILE (JSON)
  --write-baseline=FILE  Record the current findings and exit 0
  --no-color         Disable ANSI colors
  --help             Show this help

Examples
  php tools/audit/scan.php --severity=medium
  php tools/audit/scan.php --write-baseline=.audit-baseline.json
  php tools/audit/scan.php include/ajax.tickets.php

HELP;
    printf($help, SCAN_VERSION);
}

$options = getopt('', ['severity::', 'fail-on::', 'json', 'baseline::',
    'write-baseline::', 'no-color', 'help']);

if (isset($options['help'])) {
    usage();
    exit(0);
}

$root = dirname(__DIR__, 2);
$scanner = new Scanner();

$baselineFile = $options['baseline'] ?? null;
if ($baselineFile && is_file($baselineFile)) {
    $data = json_decode(file_get_contents($baselineFile), true);
    if (is_array($data) && isset($data['findings']))
        $scanner->setBaseline(array_column($data['findings'], 'fingerprint'));
}

$paths = array_slice($argv, 1);
$paths = array_values(array_filter($paths, function ($p) {
    return substr($p, 0, 2) !== '--';
}));
if (!$paths)
    $paths = $scanner->defaultRoots();

$scanner->scan($root, $paths);

$min = strtolower($options['severity'] ?? 'low');
if (!isset(Scanner::SEVERITIES[$min]))
    $min = 'low';
$threshold = Scanner::SEVERITIES[$min];
$findings = array_values(array_filter($scanner->findings, function ($f) use ($threshold) {
    return Scanner::SEVERITIES[$f->severity] >= $threshold;
}));

$counts = array_fill_keys(array_keys(Scanner::SEVERITIES), 0);
foreach ($findings as $f)
    $counts[$f->severity]++;

if (isset($options['write-baseline'])) {
    $payload = [
        'generated_at' => gmdate('c'),
        'scanner' => SCAN_VERSION,
        'findings' => array_map(function ($f) { return $f->toArray(); }, $scanner->findings),
    ];
    file_put_contents($options['write-baseline'],
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("Baseline written to %s (%d findings)\n",
        $options['write-baseline'], count($scanner->findings));
    exit(0);
}

if (isset($options['json'])) {
    echo json_encode([
        'scanner' => SCAN_VERSION,
        'root' => $root,
        'summary' => $counts,
        'total' => count($findings),
        'findings' => array_map(function ($f) { return $f->toArray(); }, $findings),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}
else {
    $colors = !isset($options['no-color']) && function_exists('posix_isatty')
        ? (posix_isatty(STDOUT) ? true : false) : false;
    $paint = function ($text, $color) use ($colors) {
        $map = ['critical' => '1;31', 'high' => '0;31', 'medium' => '1;33',
                'low' => '0;36', 'info' => '0;37'];
        return $colors ? "\033[" . $map[$color] . "m" . $text . "\033[0m" : $text;
    };

    printf("osTicket security scan — %d finding(s)\n", count($findings));
    foreach ($findings as $f) {
        printf("\n%s %s\n  %s:%d\n  %s\n",
            $paint(strtoupper($f->severity), $f->severity),
            $f->rule,
            $f->file,
            $f->line,
            $f->message);
        if ($f->snippet)
            printf("  > %s\n", mb_substr($f->snippet, 0, 160));
    }
    printf("\nSummary: %s\n", implode(', ', array_map(
        function ($k, $v) { return "$k=$v"; },
        array_keys($counts), $counts)));
}

$failOn = strtolower($options['fail-on'] ?? 'high');
if ($failOn === 'none' || !isset(Scanner::SEVERITIES[$failOn]))
    exit(0);
$limit = Scanner::SEVERITIES[$failOn];
foreach ($findings as $f) {
    if (Scanner::SEVERITIES[$f->severity] >= $limit)
        exit(1);
}
exit(0);
