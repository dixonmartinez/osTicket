#!/usr/bin/env php
<?php
/**
 * tests/support/create-ticket.php
 *
 * Crea un ticket **de verdad** contra la base de pruebas, por los dos caminos
 * que tiene la aplicación:
 *
 *   portal          open.php  →  Ticket::create($vars, $errors, 'Web')
 *   staff           scp/tickets.php?a=open  →  Ticket::open($vars, $errors)
 *                   (que comprueba el permiso de creación en el departamento
 *                   y acaba llamando a Ticket::create(..., 'staff', false))
 *   staff-sin-permiso  lo mismo, con un agente cuyo rol no puede crear
 *                   tickets: tiene que negarse y no dejar rastro.
 *
 * Por qué un proceso aparte y no dentro de `tests/run.php`: `Ticket::create()`
 * necesita el entorno completo de la aplicación —constantes de tabla,
 * configuración en la base, `$ost`, `$cfg`, `$thisstaff` y la sesión—. Montarlo
 * en el proceso de la suite obligaría a redefinir constantes que
 * `tests/bootstrap.php` ya fijó y dejaría el traductor, la sesión y el
 * manejador de errores de osTicket activos para el resto de las pruebas. Aquí
 * se arranca igual que el cron (`api/cron.php`), en su propio proceso, y se
 * devuelve el resultado en una línea JSON que lee
 * `tests/integration/TicketCreateIntegrationTest.php`.
 *
 * Uso:
 *   php tests/support/create-ticket.php portal
 *   php tests/support/create-ticket.php staff
 *   php tests/support/create-ticket.php staff-sin-permiso
 *
 * Variables: las mismas de `tests/lib/Database.php` (OST_DB_HOST, OST_DB_PORT,
 * OST_DB_NAME, OST_DB_PREFIX, OST_DB_ROOT_PASS).
 *
 * Código de salida: 0 siempre que haya podido emitir el JSON; 2 si se lanza
 * fuera de la línea de comandos.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Sólo se ejecuta desde la línea de comandos\n");
    exit(2);
}

$mode = isset($argv[1]) ? $argv[1] : 'portal';

/* Respuesta para la prueba: una línea JSON y nada más. */
$respondido = false;
function responder(array $datos) {
    global $respondido;
    $respondido = true;
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        "\n";
    exit(0);
}

/* Red de seguridad: la aplicación apaga `display_errors`, así que un error
 * fatal (una clase que no se encuentra, una consulta rechazada) dejaría la
 * salida vacía y la prueba fallaría sin decir por qué. */
register_shutdown_function(function () {
    global $respondido;

    if ($respondido)
        return;

    $error = error_get_last();

    echo json_encode(array(
        'ok' => false,
        'motivo' => 'el ayudante murió: '
            . ($error ? $error['message'] : 'sin detalle de PHP'),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
});

/* 1. El arranque de la aplicación, con las constantes que pone ost-config.php
 *    pero sin sesión de usuario ni redirecciones. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';

if (!defined('TABLE_PREFIX'))
    define('TABLE_PREFIX', TestDatabase::settings()['prefix']);
if (!defined('ADMIN_EMAIL'))
    define('ADMIN_EMAIL', 'admin@example.test');
if (!defined('SECRET_SALT'))
    define('SECRET_SALT', 'tests');
if (!defined('SESSION_SECRET'))
    define('SESSION_SECRET', md5(SECRET_SALT));
if (!defined('SESSION_TTL'))
    define('SESSION_TTL', 86400);

Bootstrap::defineTables(TABLE_PREFIX);
Bootstrap::loadCode();

/* Las clases que usan los YAML del instalador y el alta del ticket. `loadCode()`
 * no las carga todas (en la aplicación las piden las páginas que las usan) y un
 * «class not found» a mitad de la siembra sería un error a ciegas. */
foreach (array('class.list.php', 'class.sequence.php', 'class.queue.php',
        'class.thread.php', 'class.thread.event.php', 'class.filter.php',
        'class.organization.php', 'class.team.php', 'class.topic.php',
        'class.sla.php', 'class.schedule.php', 'class.role.php',
        'class.dept.php', 'class.dynamic_forms.php', 'class.file.php',
        'class.template.php', 'class.page.php', 'class.canned.php',
        'class.priority.php', 'class.ticket.php') as $clase)
    require_once INCLUDE_DIR . $clase;

/* `Internationalization` lee `$cfg` en el constructor. */
$GLOBALS['cfg'] = null;

if (($motivo = TestDatabase::unavailable()))
    responder(array('ok' => false, 'sin_base' => true,
        'motivo' => 'sin base de datos: ' . $motivo));

/* 2. Base desechable con el esquema del instalador. */
try {
    TestDatabase::schema();
}
catch (RuntimeException $e) {
    responder(array('ok' => false, 'sin_base' => true,
        'motivo' => 'no se pudo preparar la base: ' . $e->getMessage()));
}

/* 3. Los datos con los que arranca una instalación nueva, por el mismo camino
 *    que usa el instalador (`setup/inc/class.installer.php`). */
function sembrar() {
    if ((int) TestDatabase::value('SELECT COUNT(*) FROM `'
            . TestDatabase::table('config') . '`'))
        return true;

    $i18n = new Internationalization('en_US');
    $i18n->loadDefaultData();

    return (bool) TestDatabase::value('SELECT COUNT(*) FROM `'
        . TestDatabase::table('help_topic') . '`');
}

/** Primer id de una tabla, para no fijar ids a mano. */
function primerId($tabla, $columna = 'id') {
    return (int) TestDatabase::value(sprintf(
        'SELECT `%s` FROM `%s` ORDER BY `%s` LIMIT 1',
        $columna, TestDatabase::table($tabla), $columna));
}

/**
 * Lo que el instalador escribe en la configuración **después** de sembrar
 * (`setup/inc/class.installer.php`, «Create config settings»).
 *
 * `loadDefaultData()` sólo deja los valores por omisión de cada sección; estos
 * —departamento, SLA, horario, plantilla y, sobre todo, la firma del esquema—
 * los pone el instalador a mano. Sin ellos el ticket saldría sin departamento
 * y `osTicket::start()` creería que hay una actualización pendiente.
 */
function configurarInstalacion($dept_id) {
    require_once INCLUDE_DIR . 'class.migrater.php';

    $streams = DatabaseMigrater::getUpgradeStreams(UPGRADE_DIR . 'streams/');

    $config = new Config('core');
    $config->updateAll(array(
        // Sin cuenta de correo a propósito: así el alta no intenta avisar a
        // nadie y la prueba no depende de un SMTP.
        'default_email_id' => 0,
        'alert_email_id' => 0,
        'default_dept_id' => $dept_id,
        'default_sla_id' => primerId('sla'),
        'schedule_id' => primerId('schedule'),
        'default_template_id' => (int) TestDatabase::value(
            'SELECT `tpl_id` FROM `'
            . TestDatabase::table('email_template_group')
            . '` ORDER BY `tpl_id` LIMIT 1'),
        'default_timezone' => 'UTC',
        'admin_email' => ADMIN_EMAIL,
        'schema_signature' => isset($streams['core']) ? $streams['core'] : '',
        'helpdesk_url' => 'http://osticket.test/',
        'helpdesk_title' => 'Pruebas de osTicket',
    ));

    // Las demás corrientes del esquema también llevan su firma.
    foreach ($streams as $stream => $firma) {
        if ($stream == 'core')
            continue;
        $otra = new Config($stream);
        $otra->update('schema_signature', $firma);
    }
}

if (!sembrar())
    responder(array('ok' => false, 'motivo' => 'la siembra no dejó temas de ayuda'));

$dept_id = primerId('department');
configurarInstalacion($dept_id);

$role_id = primerId('role');
$topic_id = primerId('help_topic');

function permisosDeAdmin() {
    return array(
        User::PERM_CREATE, User::PERM_EDIT, User::PERM_DELETE,
        User::PERM_MANAGE, User::PERM_DIRECTORY,
        Organization::PERM_CREATE, Organization::PERM_EDIT,
        Organization::PERM_DELETE,
        FAQ::PERM_MANAGE, Email::PERM_BANLIST, Dept::PERM_DEPT,
        Staff::PERM_STAFF,
    );
}

/** Un agente con todos los permisos, como el que crea el instalador. */
function crearAgente($dept_id, $role_id, $username, $email, $isadmin = 1) {
    $staff = Staff::create(array(
        'isactive' => 1,
        'isadmin' => $isadmin,
        'max_page_size' => 25,
        'dept_id' => $dept_id,
        'role_id' => $role_id,
        'email' => $email,
        'firstname' => 'Agente',
        'lastname' => 'De Prueba',
        'username' => $username,
    ));
    $staff->setPassword('AgenteDePrueba123!');
    $staff->save();

    return $staff;
}

$agente = crearAgente($dept_id, $role_id, 'agente', 'agente@example.test');
$agente->updatePerms(permisosDeAdmin());
$agente->save();

/* 4. El ticket, por el camino que toque.
 *
 * `$vars` lleva todas las claves que lee la aplicación sin comprobar si
 * existen (es el POST real del formulario), para no disparar avisos que en una
 * prueba serían fallos. */
$vars = array(
    'name' => 'Cliente De Prueba',
    'email' => 'cliente@example.test',
    'subject' => 'No puedo entrar en mi cuenta',
    'message' => 'Desde ayer el portal me dice que mi contraseña no es válida.',
    'topicId' => $topic_id,
    'deptId' => 0,
    'emailId' => 0,
    'uid' => 0,
    'source' => 'Web',
    'ip' => '127.0.0.1',
    'priorityId' => 0,
    'statusId' => 0,
    'slaId' => 0,
    'staffId' => 0,
    'teamId' => 0,
    'cannedResponseId' => 0,
    'system_emails' => array(),
    'ccs' => array(),
    'duedate' => '',
    'response' => '',
    'note' => '',
    'assignId' => 0,
    'reply-to' => 'none',
    'signature' => '',
);

$errors = array();
$ticket = null;

if ($mode === 'portal') {
    /* open.php pone estos dos a cero a propósito: el portal no elige correo ni
     * departamento, salen del tema de ayuda. */
    $vars['deptId'] = $vars['emailId'] = 0;
    unset($vars['source']);

    /* Sin $thisstaff: el portal no tiene agente en sesión. */
    $GLOBALS['thisstaff'] = null;

    try {
        $GLOBALS['ost'] = osTicket::start();
        $GLOBALS['cfg'] = $GLOBALS['ost']->getConfig();
    }
    catch (Throwable $e) {
        responder(array('ok' => false,
            'motivo' => 'no arrancó la aplicación: ' . $e->getMessage()));
    }

    $ticket = Ticket::create($vars, $errors, 'Web', false, false);
}
else {
    /* Un agente sin permiso para crear tickets: rol propio, sin
     * `ticket.create`, asignado al departamento del ticket. */
    if ($mode === 'staff-sin-permiso') {
        $errores_rol = array();
        $rol = Role::__create(array(
            'name' => 'Sin crear tickets',
            'notes' => 'Rol de la prueba de integración',
            'flags' => 1,
            'permissions' => array('ticket.edit'),
        ), $errores_rol);

        if (!$rol || $errores_rol)
            responder(array('ok' => false,
                'motivo' => 'no se pudo crear el rol restringido'));

        /* Agente nuevo, no un cambio de rol del anterior: el rol se lee al
         * cargar el agente y cambiarlo después no basta. */
        $agente = crearAgente($dept_id, $rol->getId(), 'sin-permiso',
            'sinpermiso@example.test', 0);
    }

    $vars['deptId'] = $dept_id;
    $vars['source'] = 'Phone';

    $GLOBALS['thisstaff'] = $agente;

    try {
        $GLOBALS['ost'] = osTicket::start();
        $GLOBALS['cfg'] = $GLOBALS['ost']->getConfig();
    }
    catch (Throwable $e) {
        responder(array('ok' => false,
            'motivo' => 'no arrancó la aplicación: ' . $e->getMessage()));
    }

    $ticket = Ticket::open($vars, $errors);
}

/* 5. Lo que la prueba necesita comprobar. */
$datos = array(
    'ok' => (bool) $ticket,
    'modo' => $mode,
    'errores' => array_values(array_filter(array_keys($errors), 'is_string')),
    'err' => isset($errors['err']) ? $errors['err'] : null,
    'tickets' => (int) TestDatabase::value('SELECT COUNT(*) FROM `'
        . TestDatabase::table('ticket') . '`'),
);

if ($ticket) {
    $datos += array(
        'id' => (int) $ticket->getId(),
        'number' => $ticket->getNumber(),
        'dept_id' => (int) $ticket->getDeptId(),
        'topic_id' => (int) $ticket->getTopicId(),
        'status_id' => (int) $ticket->getStatusId(),
        'source' => $ticket->getSource(),
        'subject' => $ticket->getSubject(),
        'user_email' => $ticket->getEmail(),
        'user_name' => $ticket->getName(),
        'entradas' => (int) TestDatabase::value(
            'SELECT COUNT(*) FROM `' . TestDatabase::table('thread_entry')
            . '` WHERE `thread_id` = ' . (int) $ticket->getThreadId()),
    );
}

responder($datos);
