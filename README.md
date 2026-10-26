osTicket
========
<a href="https://osticket.com"><img height="80px" width="80px" src="images/favicon.png"
align="left" hspace="10" vspace="6"></a>

**osTicket** is a widely-used open source support ticket system. It seamlessly
integrates inquiries created via email, phone and web-based forms into a
simple easy-to-use multi-user web interface. Manage, organize and archive
all your support requests and responses in one place while providing your
customers with accountability and responsiveness they deserve.

How osTicket works for you
--------------------------
  1. Users create tickets via your website, email, or phone
  1. Incoming tickets are saved and assigned to agents
  1. Agents help your users resolve their issues

osTicket is an attractive alternative to higher-cost and complex customer
support systems; simple, lightweight, reliable, open source, web-based and
easy to setup and use. The best part is, it's completely free.

> **Security notice (2026-10-21):** PHP *php2shell*
> (CVE-2026-109618 / CVE-2026-109619, CVSS 9.2) is being exploited in the
> wild. Run **PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 or later** and follow
> [`docs/SECURITY_PHP2SHELL.md`](docs/SECURITY_PHP2SHELL.md). Check any server
> with `php tools/security/php-cve-check.php`.

Requirements
------------
  * HTTP server running Microsoft® IIS, Apache or nginx
  * PHP version 8.2 - 8.5 — **with the 2026-10-21 php2shell patch**
    (8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 or later)
  * mysqli extension for PHP
  * MySQL database version 5.5 (or greater)

### Recommendations
  * ctype, fileinfo, gd, gettext, iconv, imap, intl, json, mbstring,
    Zend OPcache, phar, xml, xml-dom, and zip extensions for PHP
  * APCu module enabled and configured for PHP

Deployment
----------
osTicket now supports bleeding-edge installations. The easiest way to
install the software and track updates is to clone the public repository.
Create a folder on you web server (using whatever method makes sense for
you) and cd into it. Then clone the repository (the folder must be empty!):

    git clone https://github.com/osTicket/osTicket

And deploy the code into somewhere in your server's www root folder, for
instance

    cd osTicket
    php manage.php deploy --setup /var/www/htdocs/osticket/

Then you can configure your server if necessary to serve that folder, and
visit the page and install osTicket as usual. Go ahead and even delete
setup/ folder out of the deployment location when you’re finished. Then,
later, you can fetch updates and deploy them (from the folder where you
cloned the git repo into)

    git pull
    php manage.php deploy -v /var/www/htdocs/osticket/

Quick start with Docker (nothing installed on your computer)
------------------------------------------------------------
Everything —PHP, MariaDB, nginx, Xdebug and the quality tools— runs in
containers. You only need Docker Desktop (or Engine + Compose v2) and VS Code.

    make docker-up        # first run builds the PHP image (a few minutes)
    # http://localhost:8080        client portal
    # http://localhost:8080/scp/   agent panel
    # http://localhost:8080/setup/ installer (first time)
    # http://localhost:8025        Mailpit (captured email)

Debugging works out of the box: open the folder in VS Code, press `F5` and
pick **“Xdebug: osTicket (web)”**, then browse to the app. Nothing PHP-related
is installed on your machine and there is no container to “reopen”: Xdebug
inside the `php` container connects to your editor on port 9003.

See [`docs/VSCODE.md`](docs/VSCODE.md) for the day-to-day workflow (launchers,
tasks, CLI scripts, troubleshooting) and [`docs/DOCKER.md`](docs/DOCKER.md)
for the stack itself (ports, installation, production overlay).

Development
-----------
The repository includes a small, dependency-free toolchain (no Composer
required on the host):

    make lint          # PHP syntax check on every file (parallel)
    make audit         # static security scan (fails on new high findings)
    make audit-all     # full security report
    make security      # PHP version vs. php2shell (CVE-2026-109618/19)
    make theme         # light/dark theme integrity (no browser needed)
    make metrics       # code size and technical-debt report
    make sql-audit     # hand-written SQL; fails if the count grows
    make ci            # lint + audit + security + theme (full pre-merge check)

Inside containers: `make docker-test`, `make docker-integration` (ORM against
a throwaway MariaDB database), `make docker-security`, `make docker-composer`,
`make docker-qa`.

Project documentation:

  * [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md) — architecture,
    request lifecycle, recipes and conventions.
  * [`docs/SECURITY_AUDIT.md`](docs/SECURITY_AUDIT.md) — audit findings,
    remediation status and deployment hardening checklist.
  * [`docs/UI_GUIDE.md`](docs/UI_GUIDE.md) — accessibility/UI layer and how
    to customise it without breaking the theme.
  * [`docs/DOCKER.md`](docs/DOCKER.md) — containers, Xdebug, ports,
    production image.
  * [`docs/VSCODE.md`](docs/VSCODE.md) — working in VS Code: opening the
    project, launchers, daily loop, running commands in containers.
  * [`docs/MOBILE.md`](docs/MOBILE.md) — mobile/PWA usage and the roadmap for
    a separate app (API surface, auth, offline, push).
  * [`docs/SECURITY_PHP2SHELL.md`](docs/SECURITY_PHP2SHELL.md) — the
    2026-10-21 PHP vulnerability: versions, mitigations, checklists.
  * [`docs/CODE_QUALITY.md`](docs/CODE_QUALITY.md) — code health: measured
    figures, the six refactoring fronts and what not to touch.
  * [`docs/SEED.md`](docs/SEED.md) — demo data (`make seed`): what it
    creates, how to undo it and why it is not a `.sql` file.
  * [`docs/MAIL.md`](docs/MAIL.md) — test mail with Mailpit, step by step:
    host, port, mailbox settings and inbound mail.
  * [`docs/QUEUES.md`](docs/QUEUES.md) — queue diagnostics
    (`make queue-check`): why a queue can count tickets it does not list, and
    how to tell the data from the query from the count.
  * [`docs/DEPENDENCIES.md`](docs/DEPENDENCIES.md) — bundled library inventory.

New in this branch:

  * **Analytics panel** — `scp/analytics.php`: first response and resolution
    times, SLA compliance, backlog age, agent workload, source/hour/topic
    breakdowns, CSV and JSON export. Server-rendered SVG charts, no
    JavaScript dependency.
  * **Mobile** — responsive agent panel (`css/mobile.css`), installable PWA
    (`manifest.php`, `sw.js`) that caches *only* static assets, never tickets.
  * **Demo data** — `make seed` fills a fresh install with 59 tickets in every
    status (with long threads), 16 open, completed and overdue tasks, 12
    clients, 5 agents, departments, teams, SLAs, help topics, canned responses
    and a 6-category knowledge base with 33 articles, through the same API the
    agent panel uses.
    `make seed-fresh` removes only what it created. See
    [`docs/SEED.md`](docs/SEED.md).
  * **Test mail** — `make test-mail` sends a message to the bundled Mailpit
    (SMTP `mailpit:1025`, inbox at <http://localhost:8025>); step-by-step
    setup, including the mailbox tab for inbound mail, in
    [`docs/MAIL.md`](docs/MAIL.md).

Scaffold new screens or endpoints with:

    php tools/scaffold.php page informes --title="Informes"
    php tools/scaffold.php ajax inventario

Upgrading
---------
osTicket supports upgrading from 1.6-rc1 and later versions. As with any
upgrade, strongly consider a backup of your attachment files, database, and
osTicket codebase before embarking on an upgrade. Please review our [Upgrade
Guide](https://docs.osticket.com/en/latest/Getting%20Started/Upgrade%20and%20Migration.html)
or the [UPGRADING.txt file](UPGRADING.txt) for upgrade instructions.

Help
----
Visit the [Documentation](https://docs.osticket.com/) or the
[forum](https://forum.osticket.com/). And if you'd like professional help
managing your osTicket installation,
[commercial support](https://osticket.com/support/) is available.

Contributing
------------
Create your own fork of the project and use
[git-flow](https://github.com/nvie/gitflow) to create a new feature. Once
the feature is published in your fork, send a pull request to begin the
conversation of integrating your new feature into osTicket.

### Localization
[![Crowdin](https://badges.crowdin.net/osticket-official/localized.svg)](https://crowdin.com/project/osticket-official)

The interface for osTicket is now completely translatable. Language packs
are available on the [download page](https://osticket.com/download). If you
do not see your language there, join the [Crowdin](https://crowdin.com/project/osticket-official)
project and request to have your language added. Languages which reach 100%
translated are are significantly reviewed will be made available on the
osTicket download page.

The software can also be translated in place in our [JIPT site](http://jipt.i18n.osticket.com).
Once you have a Crowdin account, login and translate the software in your browser!

Localizing strings in new code requires usage of a [few rules](setup/doc/i18n.md).

License
-------
osTicket is released under the GPL2 license. See the included LICENSE.txt
file for the gory details of the General Public License.

osTicket is supported by several magical open source projects including:

  * [Font-Awesome](https://fontawesome.com/)
  * [HTMLawed](https://www.bioinformatics.org/phplabware/internal_utilities/htmLawed)
  * [jQuery dropdown](https://labs.abeautifulsite.net/jquery-dropdown/) (Project Deleted)
  * [jsTimezoneDetect](https://pellepim.bitbucket.org/jstz/)
  * [laminas-mail](https://github.com/laminas/laminas-mail)
  * [mPDF](https://github.com/mpdf/mpdf)
  * [PasswordHash](https://www.openwall.com/phpass/)
  * [PEAR](https://pear.php.net/package/PEAR)
  * [PEAR/Auth_SASL](https://pear.php.net/package/Auth_SASL)
  * [PEAR/Mail](https://pear.php.net/package/mail)
  * [PEAR/Net_SMTP](https://pear.php.net/package/Net_SMTP)
  * [PEAR/Net_Socket](https://pear.php.net/package/Net_Socket)
  * [PEAR/Serivces_JSON](https://pear.php.net/package/Services_JSON)
  * [php-gettext](https://launchpad.net/php-gettext/)
  * [phpseclib](https://phpseclib.sourceforge.net/)
  * [Spyc](https://github.com/mustangostang/spyc)
