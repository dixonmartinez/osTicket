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

    // Lo mismo que va en el JSON, pero legible: la prueba enseña la salida
    // entera y así el diagnóstico se lee sin descifrar una línea JSON.
    if (isset($datos['filas']) || isset($datos['siembra'])
            || isset($datos['estado']))
        informeSiembra($datos);

    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        "\n";
    exit(0);
}

/** Resumen de la siembra para la consola (va a stderr, antes del JSON). */
function informeSiembra(array $datos) {
    $partes = array('--- siembra ---');

    foreach (array('filas' => 'filas', 'estado' => 'yaml',
            'siembra' => 'errores', 'sql_ultimo' => 'sql') as $clave => $titulo) {
        if (empty($datos[$clave]))
            continue;
        $valor = is_array($datos[$clave])
            ? json_encode($datos[$clave], JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES)
            : (string) $datos[$clave];
        $partes[] = $titulo . ': ' . $valor;
    }

    if (defined('STDERR'))
        fwrite(STDERR, implode("\n", $partes) . "\n");
    else
        error_log(implode("\n", $partes));
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

/* `Bootstrap::loadCode()` basta: `class.search.php` arrastra las formas
 * dinámicas, `class.user.php` la organización y `class.queue.php` el resto, así
 * que ya están `Dept`, `SLA`, `Topic`, `Role`, `Ticket`, `Staff`… (Comprobado
 * con un listado de clases; antes se cargaban veinte archivos a mano con el
 * riesgo de olvidar el que hiciera falta.) */

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
    /* La marca de «ya sembrado» **no** puede ser `config`: el propio esquema
     * del instalador la llena (`INSERT INTO %TABLE_PREFIX%config …, línea 109
     * de install-mysql.sql`), así que el guardia se cumplía siempre y el
     * sembrador no llegaba a ejecutarse. De ahí que la tabla de departamentos
     * estuviera vacía y, con ella, todo lo demás: los temas de ayuda se crean
     * con `dept_id = 0` y sin queja. Lo que sólo existe si se sembró son los
     * temas de ayuda. */
    if ((int) TestDatabase::value('SELECT COUNT(*) FROM `'
            . TestDatabase::table('help_topic') . '`'))
        return true;

    $i18n = new Internationalization('en_US');
    $i18n->loadDefaultData();

    // Lo que el sembrador recogió: `loadModels()` ya no se queda los errores
    // de cada registro (incluido el del motor, que es donde se ve una columna
    // desconocida o un valor obligatorio sin omisión).
    $GLOBALS['siembra'] = $i18n->getSeedErrors();

    return (bool) TestDatabase::value('SELECT COUNT(*) FROM `'
        . TestDatabase::table('help_topic') . '`');
}

/** Cuántas filas dejó la siembra en cada tabla que importa. */
function filasSembradas() {
    $filas = array();
    foreach (array('sla', 'department', 'help_topic', 'form', 'list', 'team',
            'filter', 'role', 'ticket_status', 'ticket_priority',
            'email_template_group', 'queue', 'schedule') as $tabla)
        $filas[$tabla] = (int) TestDatabase::value(
            'SELECT COUNT(*) FROM `' . TestDatabase::table($tabla) . '`');

    return $filas;
}

/**
 * Qué YAML de la siembra se leería y con qué creador.
 *
 * Para cuando la tabla está vacía y `siembra` no trae nada: distingue «el YAML
 * no trae objetos» y «el creador no está disponible» —los dos fallos mudos de
 * `loadModels()`— de un error de validación o de SQL, que sí sale en `siembra`.
 */
function estadoSiembra() {
    $mapa = array(
        'sla.yaml' => 'SLA::__create',
        'department.yaml' => 'Dept::__create',
        'help_topic.yaml' => 'Topic::__create',
        'role.yaml' => 'Role::__create',
    );

    $i18n = new Internationalization('en_US');
    $estado = array();
    foreach ($mapa as $yaml => $creador) {
        $estado[$yaml] = array(
            'objetos' => count((array) $i18n->getTemplate($yaml)->getData()),
            'creador' => $creador . ' ' . (is_callable($creador)
                ? 'disponible' : 'NO disponible'),
        );
    }

    return $estado;
}

/**
 * Primer id de una tabla, para no fijar ids a mano.
 *
 * La columna se dice siempre: no todas las tablas llaman `id` a su clave
 * (`ost_help_topic` usa `topic_id`, `ost_team` `team_id`…). Y se comprueba
 * que exista antes de consultarla, para que un nombre equivocado salga como
 * «la tabla X no tiene columna Y» en vez de un error de SQL a mitad.
 */
function primerId($tabla, $columna = 'id') {
    if (!preg_match('/^\w+$/', $tabla) || !preg_match('/^\w+$/', $columna))
        responder(array('ok' => false,
            'motivo' => 'nombre de tabla o columna inválido'));

    $real = TestDatabase::table($tabla);
    if (!TestDatabase::value(sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'",
                $real, $columna)))
        responder(array('ok' => false, 'motivo' => sprintf(
            'la tabla %s no tiene columna %s', $real, $columna)));

    return (int) TestDatabase::value(sprintf(
        'SELECT `%s` FROM `%s` ORDER BY `%s` LIMIT 1',
        $columna, $real, $columna));
}

/**
 * Por qué falla el cliente del ticket.
 *
 * `Ticket::create()` mete todo lo del contacto en un solo `$errors['user']`
 * («Incomplete client information») tanto si un campo del formulario no valida
 * como si el `INSERT` del cliente se rechaza. Aquí se separan las dos cosas:
 * los errores campo a campo del formulario y el error del motor, que
 * `User::fromVars()` se traga (`catch (OrmException) { return null; }`).
 */
function diagnosticoUsuario($vars) {
    $info = array();

    try {
        $form = UserForm::getUserForm()->getForm($vars);
        $info['valida'] = (bool) $form->isValid(
            TicketCreate::fieldFilter('web')('user'));
        $info['campos'] = $form->errors();
        $limpio = $form->getClean();
        $info['limpio'] = is_array($limpio)
            ? array_intersect_key($limpio, array_flip(array('name', 'email')))
            : null;

        if ($info['valida']) {
            VerySimpleModel::$lastError = null;
            $info['creado'] = (bool) User::fromVars($limpio, true);
            $info['sql'] = VerySimpleModel::getLastError();
        }
    }
    catch (Throwable $e) {
        $info['excepcion'] = get_class($e) . ': ' . $e->getMessage();
    }

    return $info;
}

/**
 * Los formularios dinámicos y sus campos.
 *
 * Si la siembra creó el formulario pero no sus campos, el del cliente queda
 * sin `email` ni `name`, la validación pasa sin mirar nada y `User::fromVars()`
 * se queda sin dirección con la que crear al cliente. Desde fuera eso es
 * «Incomplete client information» y nada más.
 */
function estadoFormularios() {
    $formularios = array();
    foreach (TestDatabase::rows(sprintf(
            'SELECT f.id, f.type, f.title,'
            . ' (SELECT COUNT(*) FROM `%s` ff WHERE ff.form_id = f.id) AS campos'
            . ' FROM `%s` f ORDER BY f.id',
            TestDatabase::table('form_field'),
            TestDatabase::table('form'))) as $fila)
        $formularios[] = $fila;

    return $formularios;
}

/** Los últimos errores que la aplicación apuntó en `ost_syslog`. */
function ultimosAvisos($cuantos = 5) {
    $avisos = array();
    foreach (TestDatabase::rows(sprintf(
            'SELECT log_type, title, LEFT(log, 600) AS detalle FROM `%s`'
            . ' ORDER BY log_id DESC LIMIT %d',
            TestDatabase::table('syslog'), (int) $cuantos)) as $fila)
        $avisos[] = $fila;

    return $avisos;
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
        'default_sla_id' => primerId('sla', 'id'),
        'schedule_id' => primerId('schedule', 'id'),
        'default_template_id' => (int) TestDatabase::value(
            'SELECT `tpl_id` FROM `'
            . TestDatabase::table('email_template_group')
            . '` ORDER BY `tpl_id` LIMIT 1'),
        'default_timezone' => 'UTC',
        // Sin comprobación DNS del correo del cliente. Una instalación nueva
        // la trae activada (`Config::$defaults['verify_email_addrs'] => 1`) y
        // entonces el campo del cliente pregunta por el registro MX de
        // `example.test`, que no existe: el alta fallaba con «Enter a valid
        // email address» por un motivo que no tiene nada que ver con el
        // ticket, y dependía de si el contenedor tenía red.
        'verify_email_addrs' => 0,
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

/* Cada tabla llama a su clave de una forma: `department.id`, pero
 * `help_topic.topic_id`. Los ids se piden por su nombre real. */
$ids = array(
    'department' => primerId('department', 'id'),
    'help_topic' => primerId('help_topic', 'topic_id'),
    'role' => primerId('role', 'id'),
);
$vacios = array_keys(array_filter($ids, function ($id) { return !$id; }));
if ($vacios)
    responder(array(
        'ok' => false,
        'motivo' => 'la siembra no dejó filas en ' . implode(', ', $vacios),
        'filas' => filasSembradas(),
        'siembra' => isset($GLOBALS['siembra']) ? $GLOBALS['siembra'] : array(),
        'estado' => estadoSiembra(),
        'sql_ultimo' => VerySimpleModel::getLastError(),
    ));

$dept_id = $ids['department'];
$topic_id = $ids['help_topic'];
$role_id = $ids['role'];

/**
 * El tema de prueba, con un departamento propio.
 *
 * Los temas que siembra el instalador **no traen departamento** (`dept_id =
 * 0`): el ticket cae entonces al departamento por omisión. Para poder
 * comprobar que el departamento sale del tema hay que dárselo, y que no sea
 * el de por omisión, o la comprobación pasaría por casualidad. Se elige el
 * primer departamento público que no sea el de por omisión.
 */
function departamentoDelTema($topic_id, $default_id) {
    $id = (int) TestDatabase::value(sprintf(
        'SELECT `id` FROM `%s` WHERE `id` <> %d AND (`ispublic` = 1'
        . ' OR `ispublic` IS NULL) ORDER BY `id` LIMIT 1',
        TestDatabase::table('department'), (int) $default_id));

    if (!$id)
        $id = (int) TestDatabase::value(sprintf(
            'SELECT `id` FROM `%s` WHERE `id` <> %d ORDER BY `id` LIMIT 1',
            TestDatabase::table('department'), (int) $default_id));

    if (!$id)
        return 0;

    $tema = Topic::lookup($topic_id);
    if (!$tema)
        return 0;

    $tema->dept_id = $id;
    $tema->save();

    return $id;
}

$topic_dept_id = departamentoDelTema($topic_id, $dept_id);

configurarInstalacion($dept_id);

/* 4. La aplicación en marcha: `Ticket::create()` y `Ticket::open()` usan los
 *    globales `$ost`, `$cfg` y `$thisstaff`, y `osTicket::start()` es quien
 *    define las funciones de traducción de sistema (`_S`, `_N`). Se arranca
 *    antes de crear el agente para que el modelo las tenga si las necesita. */
try {
    $GLOBALS['ost'] = osTicket::start();
    $GLOBALS['cfg'] = $GLOBALS['ost']->getConfig();
}
catch (Throwable $e) {
    responder(array('ok' => false,
        'motivo' => 'no arrancó la aplicación: ' . $e->getMessage()));
}

if (!$GLOBALS['cfg'])
    responder(array('ok' => false,
        'motivo' => 'la aplicación arrancó sin configuración'));

/**
 * La cuenta de correo del sistema y el mensaje que llega a ella.
 *
 * Se crea aquí, con la aplicación ya arrancada, porque `Email::update()`
 * necesita `$cfg` —comprueba que la dirección no sea la del administrador— y
 * el ruteo del correo la usa para decidir el departamento.
 */
function crearCuentaDeCorreo($dept_id) {
    $errores = array();
    $cuenta = Email::create(array('email' => 'soporte@example.test'));
    if (!$cuenta->update(array(
            'name' => 'Soporte',
            'email' => 'soporte@example.test',
            'dept_id' => $dept_id,
            'priority_id' => 0,
            'topic_id' => 0,
            'noautoresp' => 0,
            'notes' => 'Cuenta de la prueba de integración',
        ), $errores) || $errores)
        responder(array('ok' => false, 'motivo' => 'no se pudo crear la cuenta '
            . 'de correo: ' . json_encode($errores, JSON_UNESCAPED_UNICODE)));

    return $cuenta;
}

/** Un correo de verdad, con sus cabeceras, que el cliente envía al sistema. */
function correoDePrueba($destino) {
    return implode("\r\n", array(
        'Return-Path: <cliente@example.test>',
        'From: "Cliente De Prueba" <cliente@example.test>',
        'To: <' . $destino . '>',
        'Subject: No puedo entrar en mi cuenta',
        'Date: ' . date('r'),
        'Message-ID: <prueba-' . bin2hex(random_bytes(6)) . '@example.test>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        'Desde ayer el portal me dice que mi contraseña no es válida.',
        '',
        'Gracias.',
    ));
}

function permisosDeAdmin() {
    /* Todos los permisos que registra la aplicación, no una lista a mano.
     * La lista a mano ya se había quedado corta: le faltaba `ticket.create`,
     * que es justo el que `Ticket::open()` comprueba en el departamento. */
    $todos = array();
    foreach (RolePermission::allPermissions() as $grupo => $permisos)
        $todos = array_merge($todos, array_keys($permisos));

    return $todos;
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

/**
 * Acciones masivas, **en proceso**: `massProcess()` con un agente real.
 *
 * No se levanta HTTP ni se mantiene una sesión: se instancia el mismo
 * controlador que atiende el AJAX (`TicketsAjaxAPI`), se le pone el `$_POST`
 * que manda el diálogo y un `$thisstaff` de verdad. Lo que se comprueba
 * después es la base: departamento, agente asignado, estado y filas que
 * quedan.
 *
 * Una acción por proceso a propósito: cuando la acción se completa,
 * `MassAction::finish()` responde con `Http::response(201)`, que termina el
 * script (como en la petición real). El informe se emite desde una función de
 * apagado, que sí corre en ese caso.
 */
function accionesMasivas($accion, $agente, $dept_id, $vars) {
    require_once INCLUDE_DIR . 'ajax.tickets.php';

    // Tres tickets del portal, de clientes distintos, para tener con qué.
    $ids = array();
    foreach (array(1, 2, 3) as $i) {
        $v = $vars;
        $v['email'] = sprintf('cliente%d@example.test', $i);
        $v['name'] = sprintf('Cliente %d', $i);
        $v['subject'] = sprintf('Ticket %d de la accion masiva', $i);
        $v['deptId'] = $v['emailId'] = 0;
        unset($v['source']);

        $GLOBALS['thisstaff'] = null;
        $errores = array();
        $ticket = Ticket::create($v, $errores, 'Web', false, false);
        if (!$ticket)
            responder(array('ok' => false,
                'motivo' => 'no se pudo crear el ticket ' . $i,
                'errores' => $errores));
        $ids[] = (int) $ticket->getId();
    }

    $sql = sprintf('SELECT dept_id, staff_id, status_id, flags FROM `%s`'
        . ' WHERE ticket_id = %d', TestDatabase::table('ticket'), $ids[0]);
    $antes = TestDatabase::rows($sql);

    /* El agente y el destino del traslado. El destino tiene que ser distinto
     * del departamento en el que están los tickets, o `transfer()` lo rechaza
     * con «Ticket already in the department». */
    $origen = (int) TestDatabase::value(sprintf(
        'SELECT dept_id FROM `%s` WHERE ticket_id = %d',
        TestDatabase::table('ticket'), $ids[0]));

    /* Para el caso sin permiso: un agente con un rol que **no** incluye
     * `ticket.transfer`, en el mismo departamento que los tickets. */
    if ($accion === 'sin-permiso') {
        $errores_rol = array();
        $rol = Role::__create(array(
            'name' => 'Sin trasladar tickets',
            'notes' => 'Rol de la prueba de acciones masivas',
            'flags' => 1,
            'permissions' => array('ticket.edit', 'ticket.postreply'),
        ), $errores_rol);

        if (!$rol || $errores_rol)
            responder(array('ok' => false,
                'motivo' => 'no se pudo crear el rol sin permiso de traslado'));

        $agente = crearAgente($origen ?: $dept_id, $rol->getId(),
            'sin-traslado', 'sintraslado@example.test', 0);
    }
    $destino = (int) TestDatabase::value(sprintf(
        'SELECT id FROM `%s` WHERE id <> %d ORDER BY id LIMIT 1',
        TestDatabase::table('department'), $origen));

    // Lo que envía el diálogo de cada acción
    switch ($accion) {
    case 'transfer':
        $_POST = array('dept' => $destino,
            'tids' => array($ids[0], $ids[1]));
        $ejecutar = 'transfer';
        break;
    case 'claim':
        $_POST = array('assignee' => array('s' . $agente->getId()),
            'tids' => array($ids[0]));
        $ejecutar = 'claim';
        break;
    case 'delete':
        $_POST = array('comments' => 'Borrado en bloque de la prueba',
            'tids' => array($ids[0]));
        $ejecutar = 'delete';
        break;
    case 'sin-permiso':
        $_POST = array('dept' => $destino, 'tids' => array($ids[0]));
        $ejecutar = 'transfer';
        break;
    default:
        responder(array('ok' => false,
            'motivo' => 'acción masiva desconocida: ' . $accion));
    }
    $_REQUEST = $_POST;
    $_SERVER['PATH_INFO'] = '';

    /* El informe se emite al final, salga como salga `massProcess()` (puede
     * terminar el script con `Http::response`). Se marca la respuesta como
     * emitida para que la red de seguridad de errores fatales no añada otra
     * línea. */
    $GLOBALS['respondido'] = true;
    register_shutdown_function(function () use ($ids, $accion, $ejecutar,
            $origen, $destino, $antes) {
        $filas = array();
        foreach ($ids as $id) {
            $fila = TestDatabase::rows(sprintf(
                'SELECT ticket_id, dept_id, staff_id, status_id, flags'
                . ' FROM `%s` WHERE ticket_id = %d',
                TestDatabase::table('ticket'), $id));
            $filas[] = $fila ? $fila[0] : null;
        }

        echo json_encode(array(
            'ok' => true,
            'modo' => 'masiva-' . $accion,
            'accion' => $ejecutar,
            'ids' => $ids,
            'origen' => $origen,
            'destino' => $destino,
            'antes' => $antes ? $antes[0] : null,
            'tickets' => $filas,
            'agente' => (int) $GLOBALS['thisstaff']->getId(),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    });

    $api = new TicketsAjaxAPI();
    $api->massProcess($ejecutar);

    exit(0);
}

/* 5. El ticket, por el camino que toque.
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

/* Las acciones masivas se prueban aquí, con el agente y los tickets ya
 * montados: no hay un ticket «propio» que crear en estos modos. */
if (strpos($mode, 'masiva-') === 0)
    accionesMasivas(substr($mode, strlen('masiva-')), $agente, $dept_id, $vars);

if ($mode === 'email') {
    /* Correo entrante: se analiza con el mismo analizador que usa el buzón
     * (`EmailDataParser`) y se pasa por `Ticket::create()` con origen
     * «Email», que es exactamente lo que hace `processEmail()` cuando el
     * mensaje no pertenece a ningún hilo. */
    $cuenta = crearCuentaDeCorreo($dept_id);

    // `ApiEmailDataParser` vive en `class.api.php`, que sólo cargan los
    // puntos de entrada de la API; aquí se pide a mano.
    require_once INCLUDE_DIR . 'class.api.php';
    $analizador = new ApiEmailDataParser();
    $datos_correo = $analizador->parse(
        correoDePrueba('soporte@example.test'));

    if (!is_array($datos_correo) || !$datos_correo)
        responder(array('ok' => false,
            'motivo' => 'no se pudo analizar el correo: '
                . $analizador->lastError()));

    $datos_correo['emailId'] = $cuenta->getId();
    $vars = $datos_correo;
    $vars['ip'] = '127.0.0.1';

    $GLOBALS['thisstaff'] = null;
    $ticket = Ticket::create($vars, $errors, 'Email', false, false);
}
elseif ($mode === 'portal') {
    /* open.php pone estos dos a cero a propósito: el portal no elige correo ni
     * departamento, salen del tema de ayuda. */
    $vars['deptId'] = $vars['emailId'] = 0;
    unset($vars['source']);

    /* Sin $thisstaff: el portal no tiene agente en sesión. */
    $GLOBALS['thisstaff'] = null;

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

    $ticket = Ticket::open($vars, $errors);
}

/* 6. Lo que la prueba necesita comprobar. */
$datos = array(
    'ok' => (bool) $ticket,
    'modo' => $mode,
    'errores' => array_keys(array_filter($errors, 'is_string')),
    'err' => isset($errors['err']) ? $errors['err'] : null,
    'tickets' => (int) TestDatabase::value('SELECT COUNT(*) FROM `'
        . TestDatabase::table('ticket') . '`'),
);

/* El motivo de un fallo, con nombre y apellidos. `$errors['user']` es un
 * mensaje genérico («Incomplete client information»): lo que hace falta saber
 * es **qué campo** no pasó la validación o qué dijo el motor al guardar. */
if (!$ticket)
    $datos['detalle'] = array(
        'errores' => $errors,
        'usuario' => diagnosticoUsuario($vars),
        'formularios' => estadoFormularios(),
        'filas' => filasSembradas(),
        'syslog' => ultimosAvisos(),
        'sql_ultimo' => VerySimpleModel::getLastError(),
    );

if ($ticket) {
    $datos += array(
        'id' => (int) $ticket->getId(),
        'number' => $ticket->getNumber(),
        'dept_id' => (int) $ticket->getDeptId(),
        'topic_id' => (int) $ticket->getTopicId(),
        // El departamento que tiene el tema y el de por omisión: la prueba
        // comprueba que el del ticket es el del tema, no el de por omisión.
        'topic_dept_id' => (int) $topic_dept_id,
        'default_dept_id' => (int) $dept_id,
        // La cuenta de correo que recibió el mensaje, cuando el modo es
        // «email»: su departamento es el que tiene que salir en el ticket.
        'email_id' => isset($cuenta) ? (int) $cuenta->getId() : 0,
        'status_id' => (int) $ticket->getStatusId(),
        'source' => $ticket->getSource(),
        'subject' => $ticket->getSubject(),
        // `getEmail()` devuelve el modelo de la dirección y `getName()` el
        // objeto de nombre: al JSON tienen que ir como texto, que es lo que
        // compara la prueba (y lo que se ve en el ticket).
        'user_email' => (string) $ticket->getEmail(),
        'user_name' => (string) $ticket->getName(),
        'entradas' => (int) TestDatabase::value(
            'SELECT COUNT(*) FROM `' . TestDatabase::table('thread_entry')
            . '` WHERE `thread_id` = ' . (int) $ticket->getThreadId()),
    );
}

responder($datos);
