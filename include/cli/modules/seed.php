<?php
/*********************************************************************
    include/cli/modules/seed.php

    Datos de demostración para probar osTicket con contenido real.

        php manage.php seed                 # crea el conjunto completo
        php manage.php seed --fresh         # borra lo que creó y lo vuelve a crear
        php manage.php seed --only=tickets  # un solo paso
        php manage.php seed --list          # qué pasos hay

    Por qué no es un archivo .sql: las tablas que el ORM usa para los campos
    dinámicos (`ticket__cdata`, `user__cdata`) son **vistas** que se construyen
    a partir de `form_entry_values`, y los identificadores de formularios y
    campos dependen del orden en que el instalador los creó. Un INSERT a mano
    deja filas que la aplicación no ve. Este módulo usa los mismos métodos que
    el panel (`Dept::create()->update()`, `Ticket::create()`, …), así que todo
    el papeleo —hilo, entradas, formularios, secuencias— queda bien hecho.

    Lo que crea, y en qué orden (cada paso se puede saltar con --only):

        orgs     3 organizaciones con contactos
        users    12 usuarios, repartidos entre las organizaciones
        slas     2 SLA (24/7 y horario de oficina)
        depts    4 departamentos, uno hijo de otro, con accesos
        agents   5 agentes (uno administrador) con contraseña conocida
        teams    2 equipos con miembros y un líder
        topics   6 temas de ayuda, uno privado y uno con SLA
        canned   5 respuestas predefinidas
        faq      3 categorías y 8 artículos publicados
        tickets  30 tickets con estados, orígenes y antigüedad variados

    El orden **es** una dependencia: los SLA antes que los departamentos, los
    departamentos antes que los agentes (cada uno tiene su departamento
    primario) y los agentes antes que los equipos.

    Al terminar escribe `tools/dev/seed-manifest.json` con los identificadores
    creados; es lo que `--fresh` usa para borrar **sólo** lo suyo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class SeedManager extends Module {

    var $prologue = 'Crea datos de demostración (organizaciones, agentes, '
        . 'temas, tickets…) para probar la instalación con contenido real';

    var $arguments = array();

    var $options = array(
        'fresh' => array('-F', '--fresh', 'action' => 'store_true',
            'help' => 'Borra lo que creó una ejecución anterior y lo vuelve a crear'),
        'only' => array('-o', '--only', 'metavar' => 'PASO',
            'help' => 'Ejecuta un solo paso: ' . 'orgs, users, agents, depts, '
                . 'teams, slas, topics, canned, faq, tickets'),
        'list' => array('-l', '--list', 'action' => 'store_true',
            'help' => 'Lista los pasos disponibles y termina'),
        'no-tickets' => array('', '--no-tickets', 'action' => 'store_true',
            'help' => 'Crea la configuración pero ningún ticket'),
        'count' => array('-n', '--count', 'default' => '30', 'metavar' => 'N',
            'help' => 'Cuántos tickets crear (por omisión 30)'),
        'quiet' => array('-q', '--quiet', 'action' => 'store_true',
            'help' => 'Sólo avisa de los errores'),
        'verbose' => array('-v', '--verbose', 'action' => 'store_true',
            'help' => 'Muestra cada registro creado'),
    );

    /** Contraseña de todos los agentes y de las cuentas de cliente. */
    const PASSWORD = 'Demo1234!';

    /** Dominio de los correos de demostración (no existe: no rebota nada). */
    const DOMAIN = 'demo.invalid';

    /** Dónde se apunta lo creado, para poder deshacerlo con --fresh. */
    const MANIFEST = 'tools/dev/seed-manifest.json';

    var $manifest = array();
    var $quiet = false;
    var $verbose = false;
    var $failed = array();

    /** Cuántos registros creó, se saltó y avisó cada paso. */
    var $stats = array();
    /** Paso en curso, para poder apuntar los avisos a su casilla. */
    var $step;
    /** Avisos de toda la ejecución: si hay alguno, el comando falla. */
    var $warnings = 0;

    /* ------------------------------------------------------------------ */
    /* Paquetes de datos                                                   */
    /* ------------------------------------------------------------------ */

    static function organizations() {
        return array(
            array('name' => 'Acme S.A.', 'domain' => 'acme.demo.invalid'),
            array('name' => 'Globex Corporation', 'domain' => 'globex.demo.invalid'),
            array('name' => 'Initech', 'domain' => 'initech.demo.invalid'),
        );
    }

    static function people() {
        // nombre, correo (local), organización (índice de organizations())
        return array(
            array('Ana Torres', 'ana.torres', 0),
            array('Luis Pérez', 'luis.perez', 0),
            array('María Gómez', 'maria.gomez', 0),
            array('Carlos Rivas', 'carlos.rivas', 1),
            array('Sofía Martín', 'sofia.martin', 1),
            array('Pedro Salas', 'pedro.salas', 1),
            array('Lucía Fernández', 'lucia.fernandez', 2),
            array('Jorge Medina', 'jorge.medina', 2),
            array('Elena Ríos', 'elena.rios', 2),
            array('Raúl Castro', 'raul.castro', null),
            array('Inés Bravo', 'ines.bravo', null),
            array('Tomás Vidal', 'tomas.vidal', null),
        );
    }

    static function agents() {
        // usuario, nombre, apellido, departamento, admin, rol
        return array(
            array('admin', 'Ada', 'Administradora', 'Soporte', true, 'Administrador'),
            array('supervisor', 'Sergio', 'Supervisor', 'Soporte', false, 'Supervisor'),
            array('agente1', 'Gabriela', 'Núñez', 'Soporte Técnico', false, 'Agente'),
            array('agente2', 'Héctor', 'Díaz', 'Facturación', false, 'Agente'),
            array('agente3', 'Nora', 'Paz', 'Ventas', false, 'Agente'),
        );
    }

    static function departments() {
        // nombre, padre, público, sla (nombre o null)
        return array(
            array('Soporte', null, true, 'Respuesta 8 h'),
            array('Soporte Técnico', 'Soporte', true, 'Respuesta 8 h'),
            array('Facturación', 'Soporte', true, 'Respuesta 24 h'),
            array('Ventas', null, true, null),
        );
    }

    static function slas() {
        // nombre, horas de gracia
        return array(
            array('Respuesta 8 h', 8),
            array('Respuesta 24 h', 24),
        );
    }

    static function topics() {
        // tema, departamento, público, sla, prioridad
        return array(
            array('Problema técnico', 'Soporte Técnico', true, 'Respuesta 8 h', 'high'),
            array('Facturación y pagos', 'Facturación', true, 'Respuesta 24 h', 'normal'),
            array('Solicitud de información', 'Ventas', true, null, 'low'),
            array('Alta de usuario', 'Soporte Técnico', true, null, 'normal'),
            array('Reclamación', 'Soporte', true, 'Respuesta 24 h', 'high'),
            array('Incidente interno', 'Soporte', false, null, 'normal'),
        );
    }

    static function canned() {
        return array(
            array('Saludo inicial',
                "Hola %{recipient.name},\n\nGracias por escribirnos. Hemos recibido su solicitud #%{ticket.number} y ya la estamos revisando.\n\nUn saludo,\n%{ticket.dept.name}"),
            array('Petición de información',
                "Hola %{recipient.name},\n\nPara poder continuar necesitamos que nos confirme:\n\n  1. ...\n  2. ...\n\nGracias."),
            array('Ticket resuelto',
                "Hola %{recipient.name},\n\nDamos por resuelta su solicitud #%{ticket.number}. Si el problema vuelve, responda a este mismo correo y el ticket se reabre.\n\nUn saludo."),
            array('Escalado a segundo nivel',
                "Hola %{recipient.name},\n\nHemos pasado su caso al equipo de segundo nivel. Le escribiremos en cuanto tengamos novedades."),
            array('Factura duplicada',
                "Hola %{recipient.name},\n\nHemos anulado el cargo duplicado; el abono se verá en su extracto en 3-5 días hábiles."),
        );
    }

    static function faqs() {
        // categoría => artículos (pregunta, respuesta)
        return array(
            'Primeros pasos' => array(
                array('¿Cómo abro un ticket?',
                    '<p>Entre en el portal y pulse <em>Abrir un ticket</em>. Elija el tema de ayuda que mejor describa su caso: de eso depende el departamento que lo atiende y el plazo de respuesta.</p>'),
                array('¿Cómo consulto el estado de mi ticket?',
                    '<p>Con el enlace que recibe por correo, o desde <em>Estado del ticket</em> con su correo y el número.</p>'),
                array('¿Puedo responder por correo?',
                    '<p>Sí. Responda al mensaje de notificación y su respuesta se añade al ticket, conservando el hilo.</p>'),
            ),
            'Cuenta y acceso' => array(
                array('No recuerdo mi contraseña',
                    '<p>Use <em>¿Olvidó su contraseña?</em> en la pantalla de acceso. Si no le llega el correo, escriba a soporte.</p>'),
                array('¿Cómo cambio mi correo?',
                    '<p>En <em>Mi perfil</em>. El correo principal no se puede cambiar desde ahí por seguridad; pida el cambio a soporte.</p>'),
            ),
            'Facturación' => array(
                array('¿Cómo obtengo una factura?',
                    '<p>Las facturas se envían por correo el primer día de cada mes. Si necesita una copia, abra un ticket en el tema <em>Facturación y pagos</em>.</p>'),
                array('Me han cobrado dos veces',
                    '<p>Abra un ticket con la fecha y el importe del cargo. Anulamos el duplicado y el abono tarda entre 3 y 5 días hábiles.</p>'),
                array('¿Qué métodos de pago aceptan?',
                    '<p>Tarjeta, transferencia y domiciliación bancaria.</p>'),
            ),
        );
    }

    /**
     * Los tickets de demostración. La antigüedad, el estado y las respuestas
     * están pensados para que las colas y el panel de analíticas tengan algo
     * que mostrar: abiertos, cerrados, vencidos, sin asignar y reabiertos.
     */
    static function tickets() {
        return array(
            // asunto, tema, días de antigüedad, estado, respuestas, asignado a
            array('No puedo entrar en el panel', 'Problema técnico', 42, 'Cerrado', 3, 'agente1'),
            array('El informe mensual sale vacío', 'Problema técnico', 35, 'Cerrado', 2, 'agente1'),
            array('Error 500 al subir un archivo', 'Problema técnico', 2, 'Abierto', 1, 'agente1'),
            array('La aplicación va muy lenta', 'Problema técnico', 0, 'Abierto', 0, null),
            array('Factura de septiembre incorrecta', 'Facturación y pagos', 28, 'Cerrado', 4, 'agente2'),
            array('Cobro duplicado en la tarjeta', 'Facturación y pagos', 5, 'Abierto', 2, 'agente2'),
            array('Necesito factura a nombre de la empresa', 'Facturación y pagos', 1, 'Abierto', 1, null),
            array('Cambio de método de pago', 'Facturación y pagos', 12, 'Cerrado', 2, 'agente2'),
            array('Información sobre el plan anual', 'Solicitud de información', 20, 'Cerrado', 2, 'agente3'),
            array('Presupuesto para 50 usuarios', 'Solicitud de información', 3, 'Abierto', 1, 'agente3'),
            array('¿Tienen versión para Linux?', 'Solicitud de información', 0, 'Abierto', 0, null),
            array('Alta de tres usuarios nuevos', 'Alta de usuario', 15, 'Cerrado', 3, 'agente1'),
            array('Dar de baja a un empleado', 'Alta de usuario', 1, 'Abierto', 1, 'agente1'),
            array('Reclamación por el tiempo de respuesta', 'Reclamación', 9, 'Abierto', 3, 'supervisor'),
            array('Quiero cancelar el servicio', 'Reclamación', 22, 'Cerrado', 5, 'supervisor'),
            array('El correo de notificación no llega', 'Problema técnico', 6, 'Abierto', 2, 'agente1'),
            array('Sincronización con el ERP', 'Problema técnico', 18, 'Cerrado', 4, 'agente1'),
            array('Impresora no imprime desde la app', 'Problema técnico', 4, 'Abierto', 1, null),
            array('Mi cuenta está bloqueada', 'Problema técnico', 7, 'Cerrado', 3, 'agente1'),
            array('Duda con el IVA de la última factura', 'Facturación y pagos', 11, 'Abierto', 2, 'agente2'),
            array('Adjunto demasiado grande', 'Problema técnico', 3, 'Abierto', 1, 'agente1'),
            array('Solicitud de formación', 'Solicitud de información', 25, 'Cerrado', 3, 'agente3'),
            array('No recibo los avisos de estado', 'Problema técnico', 14, 'Cerrado', 2, 'agente1'),
            array('Cambio de titular de la cuenta', 'Facturación y pagos', 8, 'Abierto', 2, 'agente2'),
            array('Error al exportar a CSV', 'Problema técnico', 5, 'Abierto', 2, 'agente1'),
            array('Segunda incidencia del mismo error', 'Problema técnico', 1, 'Abierto', 1, 'agente1'),
            array('Consulta sobre la política de privacidad', 'Solicitud de información', 30, 'Cerrado', 2, 'agente3'),
            array('Baja de un departamento completo', 'Alta de usuario', 10, 'Abierto', 1, 'supervisor'),
            array('El ticket se reabrió solo', 'Problema técnico', 13, 'Abierto', 3, 'agente1'),
            array('Falta un pago en el histórico', 'Facturación y pagos', 2, 'Abierto', 0, null),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Arranque                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Los pasos, en el orden en que se ejecutan.
     *
     * El orden **es** una dependencia: cada paso busca lo que crearon los
     * anteriores. Los SLA van antes que los departamentos (un departamento
     * apunta a su SLA), los departamentos antes que los agentes (cada agente
     * tiene un departamento primario) y los agentes antes que los equipos.
     */
    static function steps() {
        return array('orgs', 'users', 'slas', 'depts', 'agents', 'teams',
            'topics', 'canned', 'faq', 'tickets');
    }

    function run($args, $options) {
        global $ost, $cfg;

        $this->quiet = (bool) $options['quiet'];
        $this->verbose = (bool) $options['verbose'];

        // `--list` y un `--only` mal escrito no necesitan la base de datos:
        // se resuelven antes de conectar para que sirvan como ayuda rápida.
        $steps = self::steps();
        if ($options['list']) {
            $this->out('Pasos: ' . implode(', ', $steps));
            $this->out('Use --only=PASO para ejecutar uno solo.');
            return 0;
        }

        if ($options['only'] && !in_array($options['only'], $steps))
            $this->fail("Paso desconocido: {$options['only']}. Son: "
                . implode(', ', $steps));

        Bootstrap::connect();
        if (!($ost = osTicket::start()) || !($cfg = $ost->getConfig()))
            $this->fail('No se pudo leer la configuración de la base de datos');

        // Estados y prioridades viven en las listas internas
        require_once INCLUDE_DIR . 'class.list.php';
        require_once INCLUDE_DIR . 'class.priority.php';

        $this->loadManifest();

        if ($options['fresh'] && !$this->purge())
            $this->fail('No se pudo borrar el conjunto anterior; no se crea nada');

        // Sin --fresh se puede seguir sobre lo que ya hay: cada paso busca
        // antes de crear y no duplica.
        $this->out(sprintf('Datos de demostración — contraseña: %s',
            self::PASSWORD));

        $only = $options['only'];
        foreach ($steps as $step) {
            if ($only && $only != $step)
                continue;
            if ($step == 'tickets' && $options['no-tickets'])
                continue;

            $this->step = $step;
            $this->out('');
            $this->out("[{$step}]");

            try {
                $this->{'seed' . ucfirst($step)}(
                    $step == 'tickets' ? max(0, (int) $options['count']) : null);
            }
            catch (Throwable $e) {
                $this->failed[] = $step . ': ' . $e->getMessage();
                $this->err(sprintf('  ERROR — %s: %s (%s:%d)', $step,
                    $e->getMessage(), basename($e->getFile()), $e->getLine()));
            }

            // Un resumen por paso: sin él, un paso que no crea nada porque
            // no encuentra lo que necesita se ve igual que uno que funciona.
            $this->summary($step);
        }

        $this->saveManifest();

        $this->out('');
        if ($this->failed || $this->warnings) {
            if ($this->failed) {
                $this->err(sprintf('%d paso(s) con error:', count($this->failed)));
                foreach ($this->failed as $f)
                    $this->err('  - ' . $f);
            }
            if ($this->warnings) {
                $this->err(sprintf('%d aviso(s): los registros avisados no se '
                    . 'crearon (ver el detalle arriba).', $this->warnings));
            }
            $this->err('');
            $this->err('Los pasos son independientes: lo creado por los demás '
                . 'sigue en la base. Repita el que falle con --only=<paso> -v.');
            $this->err('El motivo exacto de un fallo al guardar suele estar '
                . 'también en el panel del agente, en «Logs».');
            // `manage.php` ignora lo que devuelve el módulo: para que el
            // comando —y `make seed`— salgan con error hay que salir aquí.
            exit(1);
        }

        $this->out('Listo. Entre al panel con el usuario «admin» (o el que '
            . 'creó al instalar) y la contraseña indicada arriba.');
        $this->out('Para deshacerlo: php manage.php seed --fresh (o borre la '
            . 'base y vuelva a instalarla).');

        return 0;
    }

    /**
     * `Module::fail()` usa `die()` sin argumento, que sale con 0: para que
     * `make seed` falle de verdad (y pare una ejecución en lote) hay que
     * salir con 1.
     */
    function fail($message) {
        $this->err($message);
        exit(1);
    }

    function out($text) {
        if (!$this->quiet)
            $this->stdout->write($text . "\n");
    }

    function err($text) {
        $this->stderr->write($text . "\n");
    }

    /* ------------------------------------------------------------------ */
    /* Pasos                                                               */
    /* ------------------------------------------------------------------ */

    function seedOrgs() {
        foreach (self::organizations() as $org) {
            if (Organization::lookup(array('name' => $org['name']))) {
                $this->skip($org['name']);
                continue;
            }

            $vars = array('name' => $org['name']);
            $organization = Organization::fromVars($vars);
            if (!$organization) {
                $this->warn('no se pudo crear la organización ' . $org['name']
                    . ': ' . ($this->saveError() ?: 'sin más datos'));
                continue;
            }
            // El dominio reparte los usuarios de esa organización solo
            $organization->set('domain', $org['domain']);
            $organization->save();

            $this->created('orgs', $organization->getId(), $org['name']);
        }
    }

    function seedUsers() {
        // Las organizaciones se buscan **por nombre**, no por posición: la
        // instalación puede tener otras y el índice de `organizations()` no
        // tiene por qué coincidir con el orden de la base.
        $orgs = array();
        foreach (self::organizations() as $i => $org) {
            if ($found = Organization::lookup(array('name' => $org['name'])))
                $orgs[$i] = $found;
        }

        foreach (self::people() as $i => $person) {
            list($name, $local, $orgIndex) = $person;
            $email = $local . '@' . self::DOMAIN;

            if (($user = User::lookupByEmail($email))) {
                $this->skip($email);
                continue;
            }

            $vars = array('name' => $name, 'email' => $email);
            if ($orgIndex !== null && isset($orgs[$orgIndex]))
                $vars['org_id'] = $orgs[$orgIndex]->getId();
            elseif ($orgIndex !== null)
                $this->warn('falta la organización del usuario ' . $email);

            // Teléfono: el formulario de usuario lo pide, pero no es
            // obligatorio; se rellena para que la ficha no esté vacía
            $vars['phone'] = sprintf('+58 412 %03d %04d', 100 + $i, 1000 + $i * 37);

            if (!($user = User::fromVars($vars, true, true))) {
                $this->warn('no se pudo crear el usuario ' . $email
                    . ': ' . ($this->saveError() ?: 'sin más datos'));
                continue;
            }
            $this->created('users', $user->getId(), $email);
        }
    }

    function seedAgents() {
        global $cfg;

        $roles = $this->roleIndex();
        $depts = $this->deptIndex();

        foreach (self::agents() as $agent) {
            list($username, $first, $last, $deptName, $isadmin, $roleName)
                = $agent;
            $email = $username . '@' . self::DOMAIN;

            if (Staff::lookup(array('username' => $username))
                    || Staff::lookup(array('email' => $email))) {
                $this->skip($username);
                continue;
            }

            $dept = $depts[$deptName] ?? null;
            if (!$dept) {
                $this->warn("falta el departamento $deptName para $username");
                continue;
            }
            if (!($role = $this->roleFor($roles, $isadmin))) {
                $this->warn("no hay ningún rol para $username");
                continue;
            }

            $vars = array(
                'id' => 0,
                'username' => $username,
                'firstname' => $first,
                'lastname' => $last,
                'email' => $email,
                'phone' => '',
                'phone_ext' => '',
                'mobile' => '',
                'notes' => 'Agente de demostración',
                'dept_id' => $dept->getId(),
                'role_id' => $role,
                // `Staff::update()` compara con la cadena '1' para decidir si
                // hay que proteger al último administrador
                'isadmin' => $isadmin ? '1' : 0,
                'islocked' => null,     // null deja la cuenta activa
                'isvisible' => 1,
                'onvacation' => 0,
                'assigned_only' => 0,
                'change_passwd' => 0,
                'passwd1' => self::PASSWORD,
                'passwd2' => self::PASSWORD,
                'backend' => '',
                'welcome_email' => 0,
                'teams' => array(),
                'team_alerts' => array(),
                'dept_access' => array(),
                'dept_access_role' => array(),
                'dept_access_alerts' => array(),
                'perms' => array(),
            );
            // Acceso a todos los departamentos menos al primario, que ya lo
            // tiene por serlo (si se repite, `updateAccess()` lo rechaza)
            foreach ($depts as $d) {
                if ($d->getId() == $dept->getId())
                    continue;
                $vars['dept_access'][] = $d->getId();
                $vars['dept_access_role'][$d->getId()] = $role;
                $vars['dept_access_alerts'][$d->getId()] = 1;
            }

            $staff = Staff::create();
            if (!$staff->update($vars, $errors)) {
                $this->failed("el agente " . $username, $errors);
                continue;
            }

            $this->created('agents', $staff->getId(), $username);
        }
    }

    function seedDepts() {
        $slas = $this->slaIndex();
        $depts = $this->deptIndex();

        foreach (self::departments() as $row) {
            list($name, $parentName, $ispublic, $slaName) = $row;
            if (isset($depts[$name])) {
                $this->skip($name);
                continue;
            }

            $vars = array(
                'id' => 0,
                'name' => $name,
                'pid' => $parentName && isset($depts[$parentName])
                    ? $depts[$parentName]->getId() : 0,
                'ispublic' => $ispublic ? 1 : 0,
                'sla_id' => $slaName && isset($slas[$slaName])
                    ? $slas[$slaName]->getId() : 0,
                'manager_id' => 0,
                'email_id' => 0,
                'tpl_id' => 0,
                'autoresp_email_id' => 0,
                'signature' => '',
                'group_membership' => 0,
                'ticket_auto_response' => 1,
                'message_auto_response' => 1,
                'flags' => 0,
                'status' => 'active',
                'assignment_flag' => 'all',
                'members' => array(),
            );

            $dept = Dept::create();
            if (!$dept->update($vars, $errors)) {
                $this->failed("el departamento " . $name, $errors);
                continue;
            }
            $depts[$name] = $dept;
            $this->created('depts', $dept->getId(), $name);
        }
    }

    function seedTeams() {
        $depts = $this->deptIndex();
        $agents = $this->agentIndex();
        if (!$depts || !$agents) {
            $this->warn('hacen falta departamentos o agentes: ejecute antes '
                . 'los pasos depts y agents');
            return;
        }
        $soporte = reset($depts);

        $teams = array(
            array('Turno de noche', 'Quienes cubren las 22:00-06:00',
                array('agente1', 'agente3')),
            array('Escalado', 'Casos que necesitan dos miradas',
                array('supervisor', 'agente2', 'agente3')),
        );

        foreach ($teams as $row) {
            list($name, $notes, $members) = $row;
            if (Team::getIdByName($name)) {
                $this->skip($name);
                continue;
            }

            $vars = array(
                'id' => 0,
                'name' => $name,
                'notes' => $notes,
                'isenabled' => 1,
                'lead_id' => 0,
                'remove' => array(),
                'members' => array(),
                'member_alerts' => array(),
            );
            foreach ($members as $username) {
                if (!isset($agents[$username])) {
                    $this->warn("el agente $username no existe: el equipo "
                        . "$name queda sin él");
                    continue;
                }
                $vars['members'][] = $agents[$username]->getId();
                $vars['member_alerts'][$agents[$username]->getId()] = 1;
            }
            // El primer miembro hace de líder
            if ($vars['members'])
                $vars['lead_id'] = $vars['members'][0];

            $team = Team::create();
            if (!$team->update($vars, $errors)) {
                $this->failed("el equipo " . $name, $errors);
                continue;
            }
            $this->created('teams', $team->getId(), $name);
        }
    }

    function seedSlas() {
        foreach (self::slas() as $row) {
            list($name, $hours) = $row;
            if (SLA::objects()->filter(array('name' => $name))->count()) {
                $this->skip($name);
                continue;
            }

            $vars = array(
                'id' => 0,
                'name' => $name,
                'grace_period' => $hours,
                'schedule_id' => 0, // 24/7: sin horario asignado
                'isactive' => 1,
                'notes' => sprintf('Plazo de %d horas, todos los días', $hours),
                'transient' => 0,
                'disable_overdue_alerts' => 0,
                'enable_priority_escalation' => 0,
            );

            $sla = SLA::create();
            if (!$sla->update($vars, $errors)) {
                $this->failed("el SLA " . $name, $errors);
                continue;
            }
            $this->created('slas', $sla->getId(), $name);
        }
    }

    function seedTopics() {
        $depts = $this->deptIndex();
        $slas = $this->slaIndex();
        $priorities = $this->priorityIndex();
        $openStatus = $this->statusIdByState('open');

        $sort = 10;
        foreach (self::topics() as $row) {
            list($name, $deptName, $ispublic, $slaName, $priorityName) = $row;
            if (Topic::lookup(array('topic' => $name))) {
                $this->skip($name);
                continue;
            }
            if (!isset($depts[$deptName])) {
                $this->warn("falta el departamento $deptName para el tema $name");
                continue;
            }

            $vars = array(
                'id' => 0,
                'topic' => $name,
                'topic_pid' => 0,
                'ispublic' => $ispublic ? 1 : 0,
                'noautoresp' => 0,
                // El estado activo va en `flags` (lo pone `status`); no hay
                // columna `isactive` en `help_topic`, y el ORM inserta todo
                // lo que quede «sucio»: ponerla aquí rompe el INSERT.
                'status' => 'active',
                'dept_id' => $depts[$deptName]->getId(),
                'priority_id' => isset($priorities[$priorityName])
                    ? $priorities[$priorityName] : 0,
                'sla_id' => $slaName && isset($slas[$slaName])
                    ? $slas[$slaName]->getId() : 0,
                'status_id' => $openStatus ?: 0,
                'page_id' => 0,
                'sequence_id' => 0,
                'number_format' => '',
                'custom-numbers' => 0,
                'fields' => null,       // sin tocar los campos del formulario
                'notes' => sprintf('Tema de demostración (%d)', $sort),
                'assign' => 0,
            );

            $topic = Topic::create();
            if (!$topic->update($vars, $errors)) {
                $this->failed("el tema " . $name, $errors);
                continue;
            }
            $sort += 10;
            $this->created('topics', $topic->getId(), $name);
        }
    }

    function seedCanned() {
        $depts = $this->deptIndex();
        $first = $depts ? reset($depts)->getId() : 0;

        foreach (self::canned() as $row) {
            list($title, $response) = $row;
            if (Canned::lookup(array('title' => $title))) {
                $this->skip($title);
                continue;
            }

            $canned = Canned::create();
            if (!$canned->update(array(
                'id' => 0,
                'title' => $title,
                'response' => $response,
                'dept_id' => $first,
                'isenabled' => 1,
                'notes' => 'Respuesta de demostración',
            ), $errors)) {
                $this->failed("la respuesta " . $title, $errors);
                continue;
            }
            $this->created('canned', $canned->getId(), $title);
        }
    }

    function seedFaq() {
        $topics = Topic::getHelpTopics(false, false, false);

        foreach (self::faqs() as $categoryName => $articles) {
            $category = Category::lookup(array('name' => $categoryName));
            if (!$category) {
                $vars = array(
                    'id' => 0,
                    'name' => $categoryName,
                    'pid' => 0,
                    'ispublic' => Category::VISIBILITY_FEATURED,
                    'description' => sprintf('Artículos sobre %s',
                        mb_strtolower($categoryName)),
                    'notes' => 'Categoría de demostración',
                );
                $category = Category::create();
                if (!$category->update($vars, $errors)) {
                    $this->failed("la categoría " . $categoryName, $errors);
                    continue;
                }
                $this->created('faq', $category->getId(), 'categoría: ' . $categoryName);
            }
            else {
                $this->skip('categoría ' . $categoryName);
            }

            foreach ($articles as $article) {
                list($question, $answer) = $article;
                if (FAQ::lookup(array('question' => $question))) {
                    $this->skip($question);
                    continue;
                }

                $vars = array(
                    'id' => 0,
                    'question' => $question,
                    'answer' => $answer,
                    'category_id' => $category->getId(),
                    'ispublished' => FAQ::VISIBILITY_PUBLIC,
                    'notes' => 'Artículo de demostración',
                    'topics' => array_keys($topics),
                );

                $faq = FAQ::create();
                if (!$faq->update($vars, $errors)) {
                    $this->failed("el artículo " . $question, $errors);
                    continue;
                }
                $this->created('faq', $faq->getId(), $question);
            }
        }
    }

    function seedTickets($limit = null) {
        global $cfg;

        $topics = array();
        foreach (Topic::getHelpTopics(false, false, false) as $id => $name)
            $topics[$name] = $id;

        if (!$topics) {
            // Sin temas no hay ticketera posible: el ticket necesita tema y
            // departamento. Mejor decirlo una vez que fallar treinta.
            $this->warn('no hay ningún tema de ayuda activo: ejecute antes '
                . 'los pasos depts y topics');
            return;
        }

        $agents = $this->agentIndex();
        $people = self::people();
        $plan = self::tickets();
        if ($limit !== null)
            $plan = array_slice($plan, 0, $limit);

        foreach ($plan as $i => $row) {
            list($subject, $topicName, $age, $state, $replies, $agentUsername)
                = $row;

            if (!isset($topics[$topicName])) {
                $this->warn("el tema «{$topicName}» no existe: se salta el "
                    . "ticket «{$subject}»");
                continue;
            }
            // Un ticket por asunto: si ya existe, se salta
            if (Ticket::objects()
                    ->filter(array('cdata__subject__contains' => $subject))
                    ->count()) {
                $this->skip($subject);
                continue;
            }

            $person = $people[$i % count($people)];
            $email = $person[1] . '@' . self::DOMAIN;
            $when = $this->daysAgo($age);

            // `Ticket::create()` lee estas claves con `$vars[...]` sin
            // comprobar que existan: en el portal siempre vienen del
            // formulario, aquí hay que ponerlas o el log se llena de avisos.
            $data = array(
                'name' => $person[0],
                'email' => $email,
                'subject' => $subject,
                'message' => $this->message($subject, $topicName),
                'topicId' => $topics[$topicName],
                'source' => 'Web',
                'ip' => '10.0.0.' . (10 + $i),
                'uid' => 0,
                'deptId' => 0,          // lo pone el tema
                'statusId' => 0,        // lo pone el tema
                'priorityId' => 0,      // lo pone el tema
                'duedate' => null,
            );

            $errors = array();
            // Sin respuesta automática ni aviso: no hay correo configurado y
            // este comando tiene que funcionar igual
            $ticket = Ticket::create($data, $errors, 'API', false, false);
            if (!$ticket || $errors) {
                $this->failed("el ticket «" . $subject . "»", $errors);
                continue;
            }
            // Antigüedad: se corrige después porque create() pone NOW()
            $this->backdate($ticket, $when);

            // Respuestas del cliente y del agente, alternando
            for ($r = 1; $r <= $replies; $r++) {
                $replyWhen = $this->daysAgo(max(0, $age - $r));
                if ($r % 2) {
                    $this->agentReply($ticket,
                        $agents[$agentUsername] ?? null,
                        "Hemos revisado su caso y estamos trabajando en ello.\n\n"
                        . "Actualización {$r} del ticket.", $replyWhen);
                }
                else {
                    $this->clientReply($ticket,
                        "Gracias por la respuesta. Sigo pendiente.\n\n"
                        . "Mensaje {$r} del solicitante.", $replyWhen);
                }
            }

            // Estado
            if ($state == 'Cerrado') {
                $this->closeTicket($ticket, $agents[$agentUsername] ?? null,
                    $this->daysAgo(max(0, $age - $replies - 1)));
            }
            elseif ($agentUsername && isset($agents[$agentUsername])) {
                // Asignado, sin pasar por la lógica de reparto
                Ticket::objects()
                    ->filter(array('ticket_id' => $ticket->getId()))
                    ->update(array('staff_id' => $agents[$agentUsername]->getId()));
            }

            $this->created('tickets', $ticket->getId(), $ticket->getNumber());
        }

        if (!($this->stats['tickets']['created'] ?? 0)
                && !($this->stats['tickets']['skipped'] ?? 0))
            $this->warn('no se creó ningún ticket');
    }

    /* ------------------------------------------------------------------ */
    /* Ayudas de los pasos                                                 */
    /* ------------------------------------------------------------------ */

    /** Un texto de apertura con algo de cuerpo, según el tema. */
    function message($subject, $topic) {
        return sprintf(
            "Hola,\n\nEscribo por lo siguiente: %s.\n\n"
            . "Tema: %s\n\nLo he intentado con el navegador y con la "
            . "aplicación móvil, y pasa igual.\n\nGracias.",
            mb_strtolower($subject), $topic);
    }

    function clientReply($ticket, $text, $when) {
        try {
            // Sin avisos: no hay cuenta de correo configurada en una
            // instalación recién creada y el hilo se llena igual.
            $ticket->postMessage(array(
                'userId' => $ticket->getUserId(),
                'poster' => (string) $ticket->getName(),
                'message' => $text,
                'ip_address' => '127.0.0.1',
                'reply-to' => null,
                'ccs' => null,
                'header' => null,
            ), 'Web', false);
            $this->backdateEntry($ticket, $text, $when);
        }
        catch (Throwable $e) {
            $this->warn('respuesta del cliente: ' . $e->getMessage());
        }
    }

    /**
     * Respuesta del agente. Necesita una cuenta de correo del sistema para
     * firmar la entrada; si no hay ninguna configurada —una instalación recién
     * creada— se guarda como nota interna, que no manda correo.
     */
    function agentReply($ticket, $agent, $text, $when) {
        if (!$agent)
            return;

        $errors = array();
        try {
            $ok = $ticket->postReply(array(
                'response' => $text,
                'staffId' => $agent->getId(),
                'poster' => $agent->getName(),
                'ip_address' => '127.0.0.1',
                'reply-to' => null,
                'ccs' => null,
                'reply_status_id' => 0,
                'from_email_id' => 0,
            ), $errors, false, false);

            if (!$ok)
                $ok = $ticket->postNote(array(
                    'note' => $text,
                    'staffId' => $agent->getId(),
                    'userId' => 0,
                    'poster' => $agent->getName(),
                    'ip_address' => '127.0.0.1',
                    'note_status_id' => 0,
                ), $errors, false, false);

            if ($ok)
                $this->backdateEntry($ticket, $text, $when);
            else
                $this->warn('respuesta del agente: '
                    . Format::array_implode(' · ', ' · ', $errors));
        }
        catch (Throwable $e) {
            $this->warn('respuesta del agente: ' . $e->getMessage());
        }
    }

    /** Mueve la fecha de creación de todo el ticket (hilo incluido). */
    function backdate($ticket, $when) {
        Ticket::objects()
            ->filter(array('ticket_id' => $ticket->getId()))
            ->update(array('created' => $when, 'lastupdate' => $when));
        Thread::objects()
            ->filter(array('object_id' => $ticket->getId(), 'object_type' => 'T'))
            ->update(array('created' => $when));
    }

    /**
     * Las entradas se crean con NOW(); para que el hilo tenga fechas
     * distintas se busca la última entrada con ese cuerpo y se ajusta.
     */
    function backdateEntry($ticket, $text, $when) {
        $thread = $ticket->getThread();
        if (!$thread)
            return;

        $entry = ThreadEntry::objects()
            ->filter(array('thread_id' => $thread->getId(),
                'body__contains' => mb_substr($text, 0, 40)))
            ->order_by('-id')
            ->one();
        if ($entry) {
            ThreadEntry::objects()
                ->filter(array('id' => $entry->getId()))
                ->update(array('created' => $when));
        }
        Thread::objects()
            ->filter(array('id' => $thread->getId()))
            ->update(array('lastmessage' => $when));
    }

    function closeTicket($ticket, $agent, $when) {
        try {
            $closed = $this->statusIdByState('closed');
            if ($closed)
                $ticket->setStatus($closed);

            Ticket::objects()
                ->filter(array('ticket_id' => $ticket->getId()))
                ->update(array(
                    'closed' => $when,
                    'status_id' => $closed ?: $ticket->status_id,
                    'staff_id' => $agent ? $agent->getId() : $ticket->staff_id,
                    'lastupdate' => $when,
                ));
        }
        catch (Throwable $e) {
            $this->warn('no se pudo cerrar el ticket: ' . $e->getMessage());
        }
    }

    /** Fecha SQL, N días atrás, a una hora laborable. */
    function daysAgo($days) {
        return date('Y-m-d H:i:s',
            strtotime(sprintf('-%d days 10:30', max(0, (int) $days))));
    }

    /* ------------------------------------------------------------------ */
    /* Índices y utilidades                                                */
    /* ------------------------------------------------------------------ */

    /** Departamentos por nombre (los crea el instalador y este módulo). */
    function deptIndex() {
        $index = array();
        foreach (Dept::objects()->all() as $dept)
            $index[(string) $dept->getName()] = $dept;

        return $index;
    }

    function agentIndex() {
        $index = array();
        foreach (Staff::objects()->all() as $staff)
            $index[$staff->getUserName()] = $staff;

        return $index;
    }

    function slaIndex() {
        $index = array();
        foreach (SLA::objects()->all() as $sla)
            $index[(string) $sla->getName()] = $sla;

        return $index;
    }

    /** Prioridades por nombre y por descripción («Alta» / «High»). */
    function priorityIndex() {
        $index = array();
        foreach (Priority::objects()->values_flat('priority_id', 'priority',
                'priority_desc') as $row) {
            $index[(string) $row[1]] = $row[0];
            $index[(string) $row[2]] = $row[0];
        }

        return $index;
    }

    /** Id del primer estado con el `state` pedido (open, closed, resolved…). */
    function statusIdByState($state) {
        $row = TicketStatus::objects()
            ->filter(array('state' => $state))
            ->order_by('sort')
            ->values_flat('id')
            ->first();

        return $row ? $row[0] : null;
    }

    /**
     * Los roles por nombre (en minúsculas), más los dos primeros por orden.
     *
     * La instalación trae cuatro («All Access», «Expanded Access»…), pero el
     * nombre depende del idioma con que se instaló; por eso además de buscar
     * por nombre se guardan los dos primeros, que son los de más permisos. Si
     * la base se creó a mano y no hay ninguno, `Staff::update()` lo exige: se
     * crea uno.
     */
    function roleIndex() {
        if (!Role::objects()->count()) {
            // Sin roles no se puede asignar un departamento. Se crea uno con
            // todos los permisos: `Role::update()` es el que los guarda y no
            // toca las marcas, así que la de «activo» va en el create.
            $perms = array();
            foreach (RolePermission::allPermissions() as $group)
                $perms += $group;
            $role = Role::create(array(
                'name' => 'Agente',
                'notes' => 'Rol de demostración',
                'flags' => Role::FLAG_ENABLED,
            ));
            $errors = array();
            $role->update(array('id' => 0, 'name' => 'Agente',
                'notes' => 'Rol de demostración',
                'perms' => array_keys($perms)), $errors);
        }

        $index = array();
        foreach (Role::objects()->order_by('id')->all() as $i => $role) {
            $index[mb_strtolower((string) $role->getName())] = $role->getId();
            if ($i == 0)
                $index['_first'] = $role->getId();
            elseif ($i == 1)
                $index['_second'] = $role->getId();
        }

        return $index;
    }

    /** Todos los permisos para el administrador; el siguiente, para el resto. */
    function roleFor(array $roles, $isadmin) {
        if ($isadmin)
            return $roles['all access'] ?? $roles['_first'];

        return $roles['expanded access'] ?? $roles['_second'] ?? $roles['_first'];
    }

    /* ------------------------------------------------------------------ */
    /* Manifiesto: lo que se creó, para poder borrarlo                     */
    /* ------------------------------------------------------------------ */

    function manifestPath() {
        return ROOT_DIR . self::MANIFEST;
    }

    function loadManifest() {
        $path = $this->manifestPath();
        if (is_file($path) && ($data = json_decode(file_get_contents($path), true)))
            $this->manifest = $data + $this->manifest;
    }

    function saveManifest() {
        $path = $this->manifestPath();
        if (!is_dir(dirname($path)))
            @mkdir(dirname($path), 0775, true);
        @file_put_contents($path,
            json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    function created($step, $id, $label) {
        $this->manifest[$step][] = $id;
        $this->manifest[$step] = array_values(array_unique($this->manifest[$step]));
        $this->counted($step, 'created');
        if ($this->verbose)
            $this->out(sprintf('  + %s (#%s)', $label, $id));
    }

    function skip($label, $step = null) {
        $this->counted($step ?: $this->step, 'skipped');
        if ($this->verbose)
            $this->out('  = ' . $label . ' (ya existe)');
    }

    function warn($text) {
        $this->warnings++;
        $this->counted($this->step, 'warnings');
        $this->err('  AVISO — ' . $text);
    }

    function counted($step, $what) {
        if (!$step)
            return;
        if (!isset($this->stats[$step][$what]))
            $this->stats[$step][$what] = 0;
        $this->stats[$step][$what]++;
    }

    /** Una línea por paso: cuántos quedaron y cuántos avisos hubo. */
    function summary($step) {
        $s = $this->stats[$step] ?? array();
        $created = $s['created'] ?? 0;
        $skipped = $s['skipped'] ?? 0;
        $warnings = $s['warnings'] ?? 0;

        $line = sprintf('  %d creado(s), %d ya existía(n)', $created, $skipped);
        if ($warnings)
            $line .= sprintf(', %d aviso(s)', $warnings);
        $this->out($line);
    }

    /**
     * El motivo real de un fallo al guardar. `VerySimpleModel::save()` se traga
     * la excepción del motor y devuelve `false`; sin esto, lo único que se ve
     * es el «Internal error occurred» del modelo.
     */
    function saveError() {
        if (class_exists('VerySimpleModel')
                && ($e = VerySimpleModel::getLastError()))
            return $e;

        if (function_exists('db_error') && ($e = db_error()))
            return $e;

        return '';
    }

    /** Aviso uniforme: el modelo no guardó, con lo que dijo la base. */
    function failed($what, $errors = null) {
        $detail = is_array($errors)
            ? (string) Format::array_implode(' · ', ' · ', $errors)
            : (string) $errors;

        if (($e = $this->saveError()))
            $detail .= ($detail ? ' — ' : '') . $e;

        $this->warn(sprintf('no se pudo crear %s: %s', $what,
            $detail ?: 'sin más datos'));
    }

    /**
     * Borra lo que creó este módulo, de lo más dependiente a lo más básico.
     * Nunca toca nada que no esté en el manifiesto.
     */
    function purge() {
        $m = $this->manifest;
        if (!$m) {
            $this->out('No hay nada que borrar (no existe el manifiesto).');
            return true;
        }

        $this->out('Borrando el conjunto anterior…');

        // De lo más dependiente a lo más básico: los departamentos antes que
        // los SLA (apuntan a ellos) y los agentes antes que los departamentos.
        foreach (array('tickets', 'faq', 'canned', 'topics', 'teams', 'agents',
                'depts', 'slas', 'users', 'orgs') as $step) {
            foreach ($m[$step] ?? array() as $id) {
                try {
                    switch ($step) {
                    case 'tickets':
                        if ($t = Ticket::lookup($id))
                            $t->delete();
                        break;
                    case 'faq':
                        // El manifiesto mezcla categorías y artículos; se
                        // intenta por los dos lados
                        if ($f = FAQ::lookup($id))
                            $f->delete();
                        elseif ($c = Category::lookup($id))
                            $c->delete();
                        break;
                    case 'canned':
                        if ($c = Canned::lookup($id))
                            $c->delete();
                        break;
                    case 'topics':
                        if ($t = Topic::lookup($id))
                            $t->delete();
                        break;
                    case 'slas':
                        if ($s = SLA::lookup($id))
                            $s->delete();
                        break;
                    case 'teams':
                        if ($t = Team::lookup($id))
                            $t->delete();
                        break;
                    case 'agents':
                        if ($s = Staff::lookup($id))
                            $s->delete();
                        break;
                    case 'depts':
                        if ($d = Dept::lookup($id))
                            $d->delete();
                        break;
                    case 'users':
                        if ($u = User::lookup($id))
                            $u->delete();
                        break;
                    case 'orgs':
                        if ($o = Organization::lookup($id))
                            $o->delete();
                        break;
                    }
                }
                catch (Throwable $e) {
                    $this->warn(sprintf('no se pudo borrar %s #%s: %s',
                        $step, $id, $e->getMessage()));
                }
            }
        }

        $this->manifest = array();
        @unlink($this->manifestPath());

        return true;
    }
}

Module::register('seed', 'SeedManager');
