# Security Policy

## Reporting a Vulnerability

Please **do not open a public issue** for security problems.

Send the details to **security[at]osticket[dot]com**, including:

- affected version / commit,
- a description of the issue and the impact,
- reproduction steps or a proof of concept,
- any suggested fix.

You will receive an acknowledgement as soon as possible. Please allow a
reasonable window for a fix to be prepared before any public disclosure.

## Hardening quick reference

The repository ships a security audit and a static analyzer that can be run
at any time:

```bash
make audit          # security scan (fails on new high severity findings)
make audit-all      # full report
make lint           # PHP syntax check on every file
make security       # PHP version vs. php2shell (CVE-2026-109618 / 109619)
```

### php2shell (2026-10-21) — **critical, act now**

Two PHP interpreter vulnerabilities were published on 2026-10-21 and are
exploited in the wild (CVSS v4.0 9.2, unauthenticated RCE):

| CVE | Fixed in |
|---|---|
| CVE-2026-109618 | 8.2.35 · 8.3.36 · 8.4.27 · 8.5.12 |
| CVE-2026-109619 | same releases |

osTicket's own code is **not** affected; the interpreter running it is. Check
your server and follow the mitigation checklist:

```bash
php tools/security/php-cve-check.php        # exit code 1 = vulnerable
```

* Deployment guide and temporary mitigations:
  [`docs/SECURITY_PHP2SHELL.md`](docs/SECURITY_PHP2SHELL.md)
* The bundled Docker image already uses a patched version (8.4.27) and refuses
  to start on a vulnerable interpreter (`OST_FAIL_ON_VULNERABLE_PHP=1`).
* The admin panel shows the result under
  *Admin → System Settings → Security Posture*.

Read [`docs/SECURITY_AUDIT.md`](docs/SECURITY_AUDIT.md) for:

- the findings of the latest review and their remediation status,
- the deployment hardening checklist (PHP, Apache, Nginx, permissions),
- the list of patterns detected automatically,
- the recommended secure coding patterns.

Two deployment steps are mandatory and are **not** handled by the installer:

1. Delete (or block) the `setup/` directory once the help desk is installed.
2. Define a long random `SECRET_SALT` in `include/ost-config.php`
   (installations created before this option existed derive it from public
   data — see OST-006 in the audit).

## Supported versions

Security fixes are applied to the current stable branch and to `develop`.
Older releases should be upgraded; see `UPGRADING.txt`.
