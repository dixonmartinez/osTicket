<?php
/**
 * Regresiones de seguridad para los helpers endurecidos.
 *
 * Se ejecuta con:  php setup/test/run-tests.php security
 *
 * Cubre:
 *   - Http::host()    saneamiento de la cabecera Host (OST-002)
 *   - Http::csp()     composición de la Content-Security-Policy (OST-004)
 *   - Passwd::*       coste del hash y migración de hashes débiles (OST-007)
 */

require_once INCLUDE_DIR . 'class.http.php';
require_once INCLUDE_DIR . 'class.passwd.php';

class TestSecurityHardening extends Test {
    var $name = 'Security hardening helpers';
    var $saved;

    function setup() {
        $this->saved = $_SERVER;
    }

    function teardown() {
        $_SERVER = $this->saved;
    }

    private function withHost($host) {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SERVER_NAME'] = 'helpdesk.example.org';
        return Http::host();
    }

    function testHostAcceptsValidNames() {
        $this->assertEqual('example.com', $this->withHost('example.com'));
        $this->assertEqual('example.com:8443', $this->withHost('Example.COM:8443'));
        $this->assertEqual('192.168.0.10', $this->withHost('192.168.0.10'));
        $this->assertEqual('[::1]:8080', $this->withHost('[::1]:8080'));
    }

    function testHostRejectsInjection() {
        // HTML / header injection attempts must fall back to SERVER_NAME
        $this->assertEqual('helpdesk.example.org',
            $this->withHost('evil.com"><script>alert(1)</script>'));
        $this->assertEqual('helpdesk.example.org',
            $this->withHost("evil.com\r\nX-Injected: 1"));
        $this->assertEqual('helpdesk.example.org', $this->withHost('a..b.com'));
        $this->assertEqual('helpdesk.example.org', $this->withHost(''));
    }

    function testCspComposition() {
        $cfg = new class {
            function getAllowIframes() { return "'self'"; }
        };
        $csp = Http::csp($cfg);
        $this->assert(strpos($csp, "object-src 'none'") !== false);
        $this->assert(strpos($csp, "base-uri 'self'") !== false);
        $this->assert(strpos($csp, "frame-ancestors 'self'") !== false);
        $this->assert(strpos($csp, "'unsafe-eval'") === false);

        $staff = Http::csp($cfg, ['unsafe-eval' => true]);
        $this->assert(strpos($staff, "'unsafe-eval'") !== false);
    }

    function testPasswordHashStrength() {
        $hash = Passwd::hash('correct horse battery staple');
        $this->assert($hash);
        // bcrypt with the configured cost (generator may emit $2a$ or $2y$)
        $this->assert((bool) preg_match('/^\$2[abxy]\$10\$/', $hash));
        $this->assert(Passwd::cmp('correct horse battery staple', $hash));
        $this->assert(!Passwd::cmp('wrong password', $hash));
        $this->assert(!Passwd::needsRehash($hash));
    }

    function testNeedsRehash() {
        // Lower cost than the configured default
        $this->assert(Passwd::needsRehash('$2y$08$abcdefghijklmnopqrstuv'));
        // Legacy MD5 (32 hex chars) must be upgraded
        $this->assert(Passwd::needsRehash(md5('password')));
        // Garbage / empty values are not reported
        $this->assert(!Passwd::needsRehash(''));
        $this->assert(!Passwd::needsRehash(null));
    }
}
