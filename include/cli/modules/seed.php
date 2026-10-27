<?php
/*********************************************************************
    include/cli/modules/seed.php

    Datos de demostración para probar osTicket con contenido real.

        php manage.php seed                 # crea el conjunto completo
        php manage.php seed --fresh         # borra lo que creó y lo vuelve a crear
        php manage.php seed --only=tickets  # un solo paso
        php manage.php seed --list          # qué pasos hay

        php manage.php seed --only=users   --count=200   # 200 usuarios
        php manage.php seed --only=tickets --count=100   # 100 tickets
        php manage.php seed --only=tasks   --count=100   # 100 tareas sueltas
        php manage.php seed --only=tickets --count=50 --with-tasks
                                                         # y una tarea por ticket

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
        depts    5 departamentos, uno hijo de otro, con accesos
        agents   4 agentes; el administrador de la instalación se reutiliza
                 (sólo se crea «admin» si no hay ninguno)
        teams    2 equipos con miembros y un líder
        topics   11 temas de ayuda, uno privado y uno con SLA
        canned   5 respuestas predefinidas
        faq      6 categorías y 33 artículos publicados
        tickets  59 tickets: todos los estados, orígenes e hilos de hasta 14
                 mensajes (respuesta del agente, del cliente y nota interna)
        tasks    16 tareas: abiertas, completadas y vencidas, algunas ligadas
                 a un ticket y otras sueltas

    Esos números son el plan curado. `--count=N` pide más: el sembrador
    completa la lista con registros generados (deterministas y con el número
    en el asunto o el título, para no duplicar al repetir la ejecución). Vale
    para los pasos `users`, `tickets` y `tasks`; los demás no se repiten.
    `--with-tasks` añade, al sembrar tickets, una tarea de seguimiento por
    cada ticket creado.

    El orden **es** una dependencia: los SLA antes que los departamentos, los
    departamentos antes que los agentes (cada uno tiene su departamento
    primario) y los agentes antes que los equipos.

    Al terminar escribe `tools/dev/seed-manifest.json` con los identificadores
    creados; es lo que `--fresh` usa para borrar **sólo** lo suyo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once INCLUDE_DIR . 'cli/seed.plan.php';
/* Los pasos que escriben en la base: ver seed.writes.php */
require_once INCLUDE_DIR . 'cli/modules/seed.writes.php';
require_once INCLUDE_DIR . 'cli/modules/seed.repair.php';

class SeedManager extends Module {
    use SeedWrites;
    use SeedRepair;


    var $prologue = 'Crea datos de demostración (organizaciones, agentes, '
        . 'temas, tickets…) para probar la instalación con contenido real';

    var $arguments = array();

    var $options = array(
        'fresh' => array('-F', '--fresh', 'action' => 'store_true',
            'help' => 'Borra lo que creó una ejecución anterior y lo vuelve a crear'),
        'only' => array('-o', '--only', 'metavar' => 'PASO',
            'help' => 'Ejecuta un solo paso: ' . 'orgs, users, agents, depts, '
                . 'teams, slas, topics, canned, faq, tickets, tasks'),
        'list' => array('-l', '--list', 'action' => 'store_true',
            'help' => 'Lista los pasos disponibles y termina'),
        'no-tickets' => array('', '--no-tickets', 'action' => 'store_true',
            'help' => 'Crea la configuración, pero ni tickets ni tareas'),
        'domain' => array('', '--domain', 'metavar' => 'DOMINIO',
            'help' => 'Dominio de los correos de demostración (por omisión '
                . 'example.com, que resuelve por DNS)'),
        'count' => array('-n', '--count', 'metavar' => 'N',
            'help' => 'Cuántos registros crear del paso que se ejecuta: users, '
                . 'tickets o tasks (por omisión, los del plan curado: 12, 59 '
                . 'y 16). Con todos los pasos, se aplica a los tres'),
        'with-tasks' => array('', '--with-tasks', 'action' => 'store_true',
            'help' => 'Al sembrar tickets, crea además una tarea de '
                . 'seguimiento por cada ticket creado'),
        'quiet' => array('-q', '--quiet', 'action' => 'store_true',
            'help' => 'Sólo avisa de los errores'),
        'verbose' => array('-v', '--verbose', 'action' => 'store_true',
            'help' => 'Muestra cada registro creado'),
        'doctor' => array('', '--doctor', 'action' => 'store_true',
            'help' => 'No crea nada: revisa que los datos sembrados cuadren con '
                . 'los informes y las colas del panel'),
        'repair' => array('', '--repair', 'action' => 'store_true',
            'help' => 'Con `--doctor`: arregla lo que se puede arreglar sin '
                . 'borrar nada (artículos sin categoría, entradas fechadas '
                . 'antes que su mensaje padre, restos de hilo y vistas de datos)'),
    );

    /** Contraseña de todos los agentes y de las cuentas de cliente. */
    const PASSWORD = 'Demo1234!';

    /**
     * Dominio de los correos de demostración.
     *
     * `example.com` está reservado para documentación (RFC 2606) y **resuelve**
     * por DNS, que es lo que exige la comprobación de osTicket cuando
     * «Verify email addresses» está activo (lo está por omisión): con un TLD
     * que no resuelve —`demo.invalid` era el caso— el alta de agentes falla
     * con «Valid email is required», porque `Validator::is_email()` busca MX o
     * A/AAAA del dominio y no encuentra ninguno.
     *
     * No se envía correo a esas direcciones: `example.com` publica un MX nulo
     * (RFC 7505), así que cualquier envío se rechaza en el acto.
     */
    const DOMAIN = 'example.com';

    /** Dominio en uso; `--domain=` lo cambia para esta ejecución. */
    var $domain = self::DOMAIN;

    /** Dónde se apunta lo creado, para poder deshacerlo con --fresh. */
    const MANIFEST = 'tools/dev/seed-manifest.json';

    var $manifest = array();
    var $quiet = false;
    var $verbose = false;
    /** Cuántos registros por paso quiere `--count`; `null` = plan curado. */
    var $count;
    /** `--with-tasks`: una tarea de seguimiento por ticket creado. */
    var $withTasks = false;
    var $failed = array();

    /** Cuántos registros creó, se saltó y avisó cada paso. */
    var $stats = array();
    /** Paso en curso, para poder apuntar los avisos a su casilla. */
    var $step;
    /** Avisos de toda la ejecución: si hay alguno, el comando falla. */
    var $warnings = 0;
    /**
     * Administrador con el que se firma lo que necesita sesión de agente
     * (tareas, notas). Es el que ya tenía la instalación si lo hay, y el que
     * crea el sembrador sólo cuando no hay ninguno.
     */
    var $adminStaff;

    /* ------------------------------------------------------------------ */
    /* Paquetes de datos                                                   */
    /* ------------------------------------------------------------------ */

    static function organizations() {
        return array(
            // La clave `slug` se convierte en el dominio de la organización
            array('name' => 'Acme S.A.', 'slug' => 'acme'),
            array('name' => 'Globex Corporation', 'slug' => 'globex'),
            array('name' => 'Initech', 'slug' => 'initech'),
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

    /**
     * Los usuarios que se van a crear: el plan curado y, si `--count` pide
     * más, los generados.
     *
     * @param int|null $count
     * @return array
     */
    static function peoplePlan($count = null) {
        return SeedPlan::fill(self::people(), $count,
            function ($i) {
                return SeedPlan::person($i);
            });
    }

    /**
     * Los tickets que se van a crear, con los temas y agentes que **existen**
     * en la instalación (no los del paquete curado: puede faltar alguno).
     *
     * @param int|null $count
     * @param array $topics  nombres de tema disponibles
     * @param array $agents  nombres de usuario de agente
     * @return array
     */
    static function ticketPlan($count, array $topics = array(),
            array $agents = array()) {
        return SeedPlan::fill(self::tickets(), $count,
            function ($i) use ($topics, $agents) {
                return SeedPlan::ticket($i, $topics, $agents);
            });
    }

    /**
     * Las tareas que se van a crear. Las generadas van sueltas: es lo que
     * tiene sentido con `--only=tasks`, donde no hay tickets de los que
     * colgarlas.
     *
     * @param int|null $count
     * @param array $depts   nombres de departamento disponibles
     * @param array $agents  nombres de usuario de agente
     * @return array
     */
    static function taskPlan($count, array $depts = array(),
            array $agents = array()) {
        return SeedPlan::fill(self::tasks(), $count,
            function ($i) use ($depts, $agents) {
                return SeedPlan::task($i, $depts, $agents);
            });
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
            array('Seguridad', null, false, 'Respuesta 8 h'),
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
            array('Cuenta y acceso', 'Soporte Técnico', true, 'Respuesta 8 h', 'normal'),
            array('Seguridad', 'Seguridad', false, 'Respuesta 8 h', 'high'),
            array('Soporte técnico', 'Soporte Técnico', true, 'Respuesta 8 h', 'normal'),
            array('Uso del panel', 'Soporte', false, null, 'low'),
            array('Formación', 'Ventas', true, null, 'low'),
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

    /**
     * Equipos de demostración: nombre, notas y miembros.
     *
     * Es un paquete de datos y no un array dentro de `seedTeams()` porque la
     * purga también necesita los nombres: `purgeByName()` borra por nombre lo
     * que dejaron ejecuciones antiguas, y sin esta lista la llamada
     * `self::teams()` terminaba en un `Error` fatal a mitad de `--fresh`.
     */
    static function teams() {
        return array(
            array('Turno de noche', 'Quienes cubren las 22:00-06:00',
                array('agente1', 'agente3')),
            array('Escalado', 'Casos que necesitan dos miradas',
                array('supervisor', 'agente2', 'agente3')),
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
                    '<p>Sí. Responda al mensaje de notificación y su respuesta se añade al ticket, conservando el hilo y los adjuntos.</p>'),
                array('¿Qué significa cada estado?',
                    '<p><em>Abierto</em>: el equipo lo está atendiendo. <em>Pendiente</em>: esperamos información suya. <em>Resuelto</em>: hay una solución y se puede reabrir. <em>Cerrado</em>: ya no admite respuestas, pero conserva el historial.</p>'),
                array('¿Cuánto tarda una primera respuesta?',
                    '<p>Depende del tema: los incidentes técnicos tienen 8 horas de plazo y facturación 24 horas, en días laborables. El plazo se cuenta desde que el ticket entra en cola.</p>'),
                array('¿Puedo adjuntar archivos?',
                    '<p>Sí, hasta 10 MB por archivo. Para los registros de error, un archivo de texto es más útil que una captura de pantalla.</p>'),
            ),
            'Cuenta y acceso' => array(
                array('No recuerdo mi contraseña',
                    '<p>Use <em>¿Olvidó su contraseña?</em> en la pantalla de acceso. Si no le llega el correo, escriba a soporte desde otra cuenta: el enlace caduca a los 30 minutos.</p>'),
                array('¿Cómo cambio mi correo?',
                    '<p>En <em>Mi perfil</em>. El correo principal no se puede cambiar desde ahí por seguridad; pida el cambio a soporte, que lo verifica con el titular.</p>'),
                array('Mi cuenta está bloqueada',
                    '<p>Tras cinco intentos fallidos la cuenta se bloquea 15 minutos. Si el bloqueo persiste, abra un ticket en <em>Cuenta y acceso</em> y el equipo la desbloquea.</p>'),
                array('¿Cómo activo la verificación en dos pasos?',
                    '<p>En <em>Mi perfil → Seguridad</em>, con una aplicación de códigos (TOTP). Guarde los códigos de respaldo: sin ellos, la recuperación requiere intervención del administrador.</p>'),
                array('Pertenezco a dos organizaciones',
                    '<p>Cada cuenta tiene una organización principal. Para cambiar de organización, soporte ajusta el dominio de correo o crea una segunda cuenta.</p>'),
            ),
            'Facturación' => array(
                array('¿Cómo obtengo una factura?',
                    '<p>Las facturas se envían por correo el primer día de cada mes. Si necesita una copia, abra un ticket en el tema <em>Facturación y pagos</em> con el número de factura.</p>'),
                array('Me han cobrado dos veces',
                    '<p>Abra un ticket con la fecha y el importe del cargo. Anulamos el duplicado y el abono tarda entre 3 y 5 días hábiles.</p>'),
                array('¿Qué métodos de pago aceptan?',
                    '<p>Tarjeta, transferencia y domiciliación bancaria. El cambio de método se hace en <em>Facturación → Método de pago</em> y tarda un ciclo en aplicarse.</p>'),
                array('¿Cómo pido una factura a nombre de la empresa?',
                    '<p>Envíe la razón social, el NIF y la dirección fiscal. La factura se reemite en 24 horas; las anteriores no se pueden modificar, solo anular.</p>'),
                array('¿Qué pasa si no pago a tiempo?',
                    '<p>Hay 10 días de gracia. Después, el servicio pasa a solo lectura hasta que se regularice el pago, sin perder datos.</p>'),
                array('¿Cómo cancelo la suscripción?',
                    '<p>Desde <em>Facturación → Suscripción</em> antes de la fecha de renovación. La cancelación es efectiva al final del periodo ya pagado.</p>'),
            ),
            'Soporte técnico' => array(
                array('La aplicación va lenta',
                    '<p>Compruebe primero en una ventana privada y sin extensiones. Si sigue igual, abra un ticket con el navegador, la hora aproximada y el identificador del error si aparece.</p>'),
                array('Error 500 al subir un archivo',
                    '<p>Suele ser el tamaño (10 MB por archivo) o el tipo. Comprima el archivo o súbalo por partes; si es un registro, use texto plano.</p>'),
                array('No recibo los correos de notificación',
                    '<p>Revise la carpeta de correo no deseado y añada el remitente a contactos. Los avisos salen del dominio configurado por su administrador.</p>'),
                array('¿Cómo exporto un informe?',
                    '<p>En el informe, <em>Exportar → CSV</em>. Para más de 50.000 filas, use la exportación programada y le enviaremos un enlace de descarga.</p>'),
                array('La sincronización con el ERP se ha parado',
                    '<p>El proceso reintenta cada 30 minutos y avisa al tercer fallo. Si el error es de credenciales, hay que renovar el token en la integración.</p>'),
                array('¿Se puede usar sin conexión?',
                    '<p>La aplicación se instala como PWA y guarda la interfaz, pero los tickets necesitan conexión: no se cachean datos de clientes.</p>'),
            ),
            'Seguridad y privacidad' => array(
                array('¿Dónde se guardan los datos?',
                    '<p>En el servidor de su organización, en la base de datos del propio osTicket. No se envían a terceros salvo el envío de correo.</p>'),
                array('¿Quién puede ver mis tickets?',
                    '<p>Solo los agentes del departamento que atiende el ticket y los administradores. Los colaboradores ven el hilo en el que participan.</p>'),
                array('¿Cómo se tratan los datos personales?',
                    '<p>Se conservan mientras dure la relación y los plazos legales. Puede solicitar la exportación o el borrado de su cuenta abriendo un ticket.</p>'),
                array('¿Los adjuntos están cifrados?',
                    '<p>Se guardan fuera del directorio público y se sirven con permisos por sesión. El cifrado en reposo depende del disco del servidor.</p>'),
                array('Aviso de vulnerabilidad',
                    '<p>Escríbanos a la dirección de seguridad con los detalles y un plazo razonable antes de publicarlo. Confirmamos la recepción en 48 horas.</p>'),
            ),
            'Uso del panel' => array(
                array('¿Cómo asigno un ticket a otro agente?',
                    '<p>En la ficha del ticket, <em>Asignar</em>, y elija agente o equipo. Si su rol no incluye el permiso de asignación, pida el cambio al administrador.</p>'),
                array('¿Qué son las respuestas predefinidas?',
                    '<p>Textos guardados por departamento que se insertan en una respuesta con un clic. Admiten variables como el nombre del solicitante o el número de ticket.</p>'),
                array('¿Cómo creo una cola personalizada?',
                    '<p>En <em>Colas</em>, con los filtros y el orden que necesite. Cada agente puede fijar las suyas y compartirlas con el equipo.</p>'),
                array('¿Se pueden crear tareas?',
                    '<p>Sí: desde el propio ticket (<em>Tareas</em>) o de forma independiente. Una tarea tiene departamento, responsable y fecha límite, y se cierra aparte del ticket.</p>'),
                array('¿Cómo funciona el modo oscuro?',
                    '<p>Con el selector de la cabecera. La preferencia se guarda por usuario y respeta la del sistema si no se ha elegido ninguna.</p>'),
            ),
        );
    }

    /**
     * Los tickets de demostración.
     *
     * asunto, tema, días de antigüedad, «cerrado» (pista de estado), mensajes
     * en el hilo y agente asignado. El estado final se reparte después entre
     * **todos** los estados que tenga la instalación (`seedTickets()`), así
     * que esta columna sólo dice si el ticket debe quedar cerrado o abierto
     * cuando se ejecuta el paso sin reparto.
     */
    static function tickets() {
        return array(
            array('No puedo entrar en el panel', 'Problema técnico', 42, true, 10, 'agente1'),
            array('El informe mensual sale vacío', 'Problema técnico', 35, true, 6, 'agente1'),
            array('Error 500 al subir un archivo', 'Problema técnico', 2, false, 8, 'agente1'),
            array('La aplicación va muy lenta', 'Problema técnico', 0, false, 3, null),
            array('Factura de septiembre incorrecta', 'Facturación y pagos', 28, true, 12, 'agente2'),
            array('Cobro duplicado en la tarjeta', 'Facturación y pagos', 5, false, 7, 'agente2'),
            array('Necesito factura a nombre de la empresa', 'Facturación y pagos', 1, false, 4, null),
            array('Cambio de método de pago', 'Facturación y pagos', 12, true, 5, 'agente2'),
            array('Información sobre el plan anual', 'Solicitud de información', 20, true, 4, 'agente3'),
            array('Presupuesto para 50 usuarios', 'Solicitud de información', 3, false, 6, 'agente3'),
            array('¿Tienen versión para Linux?', 'Solicitud de información', 0, false, 2, null),
            array('Alta de tres usuarios nuevos', 'Alta de usuario', 15, true, 9, 'agente1'),
            array('Dar de baja a un empleado', 'Alta de usuario', 1, false, 4, 'agente1'),
            array('Reclamación por el tiempo de respuesta', 'Reclamación', 9, false, 9, 'supervisor'),
            array('Quiero cancelar el servicio', 'Reclamación', 22, true, 14, 'supervisor'),
            array('El correo de notificación no llega', 'Problema técnico', 6, false, 7, 'agente1'),
            array('Sincronización con el ERP', 'Problema técnico', 18, true, 11, 'agente1'),
            array('Impresora no imprime desde la app', 'Problema técnico', 4, false, 4, null),
            array('Mi cuenta está bloqueada', 'Problema técnico', 7, true, 6, 'agente1'),
            array('Duda con el IVA de la última factura', 'Facturación y pagos', 11, false, 6, 'agente2'),
            array('Adjunto demasiado grande', 'Problema técnico', 3, false, 3, 'agente1'),
            array('Solicitud de formación', 'Solicitud de información', 25, true, 8, 'agente3'),
            array('No recibo los avisos de estado', 'Problema técnico', 14, true, 5, 'agente1'),
            array('Cambio de titular de la cuenta', 'Facturación y pagos', 8, false, 5, 'agente2'),
            array('Error al exportar a CSV', 'Problema técnico', 5, false, 7, 'agente1'),
            array('Segunda incidencia del mismo error', 'Problema técnico', 1, false, 4, 'agente1'),
            array('Consulta sobre la política de privacidad', 'Solicitud de información', 30, true, 4, 'agente3'),
            array('Baja de un departamento completo', 'Alta de usuario', 10, false, 5, 'supervisor'),
            array('El ticket se reabrió solo', 'Problema técnico', 13, false, 10, 'agente1'),
            array('Falta un pago en el histórico', 'Facturación y pagos', 2, false, 2, null),
            array('La web del portal no carga en Safari', 'Problema técnico', 9, true, 8, 'agente1'),
            array('Se pierden los adjuntos al responder', 'Problema técnico', 4, false, 6, 'agente1'),
            array('Duplicar una suscripción por error', 'Facturación y pagos', 16, true, 7, 'agente2'),
            array('¿Hay descuento por volumen?', 'Solicitud de información', 7, false, 5, 'agente3'),
            array('Migración desde otra herramienta', 'Solicitud de información', 33, true, 13, 'agente3'),
            array('Usuarios sin acceso tras la migración', 'Alta de usuario', 21, true, 10, 'agente1'),
            array('Solicitud de permisos de administrador', 'Alta de usuario', 3, false, 3, 'agente1'),
            array('Retraso en la respuesta de una incidencia', 'Reclamación', 6, false, 8, 'supervisor'),
            array('Facturación distinta a lo pactado', 'Reclamación', 19, true, 9, 'supervisor'),
            array('El buscador no encuentra tickets antiguos', 'Problema técnico', 11, true, 5, 'agente1'),
            array('Caducidad del enlace de restablecimiento', 'Cuenta y acceso', 8, false, 6, 'agente1'),
            array('Cambio de idioma de la interfaz', 'Cuenta y acceso', 2, false, 4, null),
            array('No puedo activar la verificación en dos pasos', 'Cuenta y acceso', 5, false, 7, 'agente1'),
            array('Correo de bienvenida en otro idioma', 'Cuenta y acceso', 13, true, 5, 'agente1'),
            array('Aviso de posible incidente de seguridad', 'Seguridad', 1, false, 6, 'supervisor'),
            array('Solicitud de exportación de datos', 'Seguridad', 17, true, 4, 'supervisor'),
            array('Adjunto con datos personales por error', 'Seguridad', 4, false, 8, 'supervisor'),
            array('Pregunta sobre retención de registros', 'Seguridad', 26, true, 3, 'supervisor'),
            array('Cómo crear una cola para el equipo', 'Uso del panel', 10, true, 6, 'agente1'),
            array('Las respuestas predefinidas no aparecen', 'Uso del panel', 3, false, 5, 'agente1'),
            array('No veo el modo oscuro en mi navegador', 'Uso del panel', 0, false, 2, null),
            array('Tareas que no se cierran al cerrar el ticket', 'Uso del panel', 12, true, 7, 'agente1'),
            array('Informe de tiempos sin datos', 'Uso del panel', 6, false, 4, 'supervisor'),
            array('Reapertura automática de tickets cerrados', 'Problema técnico', 23, true, 9, 'agente1'),
            array('El número de ticket no sigue la secuencia', 'Problema técnico', 15, true, 6, 'agente1'),
            array('Integración con Slack no envía avisos', 'Problema técnico', 2, false, 5, 'agente1'),
            array('Copias de seguridad: ¿cada cuánto?', 'Soporte técnico', 20, true, 4, 'supervisor'),
            array('Solicitud de entorno de pruebas', 'Soporte técnico', 1, false, 3, 'agente1'),
            array('Error al importar un CSV de clientes', 'Soporte técnico', 7, false, 6, 'agente1'),
        );
    }

    /**
     * Frases de los hilos. Se alternan agente y cliente para que el «chat» de
     * cada ticket parezca una conversación y no un mensaje repetido N veces.
     * Los `%s` que aparecen se sustituyen por el número de ticket.
     */
    static function conversation() {
        return array(
            'agent' => array(
                'Gracias por avisar. Ya lo estoy revisando y le confirmo en cuanto tenga algo.',
                'He reproducido el problema en un entorno de pruebas; parece relacionado con la configuración de su cuenta.',
                '¿Podría confirmarme el navegador y si ocurre también en una ventana privada?',
                'He abierto una incidencia con el equipo de desarrollo; la referencia interna es INC-%s.',
                'Con la última actualización el error debería desaparecer. ¿Puede probarlo de nuevo?',
                'Mientras tanto, como solución provisional puede usar la exportación manual desde el menú de informes.',
                'Le dejo el resumen: el problema venía de un registro duplicado que ya hemos limpiado.',
                'Si vuelve a ocurrir, responda a este mismo ticket y lo reabrimos sin perder el historial.',
                'He revisado los registros del servidor de esa franja horaria y no veo errores, pero sigo mirando.',
                'Le confirmo que el cambio ya está aplicado en su cuenta; no hace falta reiniciar la sesión.',
                'Voy a necesitar un ejemplo concreto para reproducirlo; con uno me basta.',
                'Quedo a la espera de su confirmación para dar el caso por resuelto.',
            ),
            'client' => array(
                'Gracias por la respuesta. Sigo pendiente.',
                'Lo he probado y el error continúa, esta vez con otro mensaje distinto.',
                'Adjunto una captura de lo que veo en pantalla.',
                '¿Hay alguna novedad? Llevamos dos días con el mismo problema.',
                'Perfecto, esa solución provisional nos sirve. Quedo atento al arreglo definitivo.',
                'Confirmo que ahora funciona correctamente. Muchas gracias por la rapidez.',
                'Una última duda: ¿esto afecta también a los usuarios de la otra sede?',
                'De acuerdo, cierro por mi parte. Si hace falta algo más, escribo de nuevo.',
                'Probé desde otro equipo y ocurre igual, así que no parece cosa del navegador.',
                'Sigue pasando, pero menos; ahora sólo al guardar por segunda vez.',
                'Entendido. Le paso también el enlace donde lo vemos, por si ayuda.',
                'Quedo pendiente de su respuesta. No es urgente, pero me gustaría cerrarlo esta semana.',
            ),
        );
    }

    /**
     * Las tareas de demostración.
     *
     * título, departamento, días de antigüedad, completada (bool), días de
     * plazo (negativo = vencida), agente asignado (o null) y descripción.
     */
    static function tasks() {
        // título, departamento, antigüedad, completada, plazo en días
        // (negativo = vencida), agente, descripción y ticket al que se
        // engancha (null = tarea suelta, sin ticket)
        return array(
            array('Revisar los registros del servidor de correo', 'Soporte Técnico',
                12, true, -5, 'agente1',
                'Buscar en los registros de los últimos siete días los rechazos '
                . 'del dominio de demostración y anotar los rebotes.',
                'El correo de notificación no llega'),
            array('Actualizar la plantilla de respuesta de facturación', 'Facturación',
                9, true, -2, 'agente2',
                'Añadir el nuevo formato de factura y la referencia al portal de pagos.',
                'Necesito factura a nombre de la empresa'),
            array('Documentar el procedimiento de altas masivas', 'Soporte Técnico',
                6, false, 4, 'agente1',
                'Un documento con los pasos, el formato del CSV y los errores más '
                . 'habituales al importar.', null),
            array('Migrar las colas personalizadas al nuevo esquema', 'Soporte',
                4, false, -1, 'supervisor',
                'Revisar que los filtros por estado sigan apuntando a estados '
                . 'existentes tras el cambio.', null),
            array('Preparar el entorno de pruebas para la actualización', 'Soporte',
                2, false, 7, null,
                'Una copia de la base de datos con datos de demostración y la '
                . 'versión nueva del código.', null),
            array('Revisar los permisos del rol de agente', 'Soporte',
                15, true, -8, 'supervisor',
                'Comprobar que los agentes pueden responder y cerrar, pero no borrar.',
                'Solicitud de permisos de administrador'),
            array('Contactar con el proveedor de la pasarela de pago', 'Facturación',
                3, false, 2, 'agente2',
                'Preguntar por los cargos duplicados de la última semana y pedir '
                . 'el abono de los confirmados.', 'Cobro duplicado en la tarjeta'),
            array('Escribir artículos sobre el nuevo portal', 'Ventas',
                8, false, 10, 'agente3',
                'Tres artículos: cómo abrir un ticket, cómo seguirlo y cómo adjuntar '
                . 'archivos.', null),
            array('Renovar el certificado del portal', 'Soporte',
                30, true, -20, null,
                'Se renovó el certificado y se comprobó la cadena completa en los '
                . 'tres navegadores.', null),
            array('Revisar los tickets cerrados sin respuesta', 'Soporte',
                5, false, 1, 'supervisor',
                'Listar los cerrados en los últimos 30 días que no tuvieron '
                . 'respuesta del agente.', null),
            array('Formación interna sobre el nuevo flujo de tareas', 'Soporte',
                1, false, 14, null,
                'Sesión de una hora con el equipo: cuándo usar una tarea y cuándo '
                . 'una nota interna.', null),
            array('Limpiar cuentas de usuario inactivas', 'Soporte Técnico',
                18, true, -10, 'agente1',
                'Desactivar las cuentas sin acceso en los últimos doce meses, previa '
                . 'revisión con las organizaciones.', 'Dar de baja a un empleado'),
            array('Preparar el informe trimestral de soporte', 'Soporte',
                2, false, 5, 'supervisor',
                'Tiempos de primera respuesta, resolución y cumplimiento de SLA del '
                . 'trimestre.', null),
            array('Revisar el texto de las notificaciones automáticas', 'Soporte',
                7, false, -3, null,
                'Ajustar el tono y quitar las referencias al sistema antiguo.', null),
            array('Comprobar la búsqueda de tickets antiguos', 'Soporte Técnico',
                11, true, -4, 'agente1',
                'Reproducir la búsqueda sobre los tickets de hace más de un año y '
                . 'verificar el índice.', 'El buscador no encuentra tickets antiguos'),
            array('Unificar el formato de las respuestas predefinidas', 'Ventas',
                6, false, 9, 'agente3',
                'Mismo saludo, misma despedida y variables en todas las respuestas '
                . 'del departamento.', 'Las respuestas predefinidas no aparecen'),
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
            'topics', 'canned', 'faq', 'tickets', 'tasks');
    }

    function run($args, $options) {
        global $ost, $cfg;

        $this->quiet = (bool) $options['quiet'];
        $this->verbose = (bool) $options['verbose'];

        // `--domain=midominio.test` cambia los correos de demostración. Se
        // limpia lo que el usuario escriba: `@midominio.test`, mayúsculas o
        // un punto final rompen el alta de agentes sin decir por qué.
        if (!empty($options['domain']))
            $this->domain = strtolower(trim(trim($options['domain']), '@. '));

        // `--list` y un `--only` mal escrito no necesitan la base de datos:
        // se resuelven antes de conectar para que sirvan como ayuda rápida.
        $steps = self::steps();
        if ($options['list']) {
            $this->out('Pasos: ' . implode(', ', $steps));
            $this->out('Use --only=PASO para ejecutar uno solo.');
            $this->out('Con --count=N se piden más registros de users, '
                . 'tickets o tasks: --only=users --count=200.');
            $this->out('Con --with-tasks, cada ticket nuevo lleva su tarea '
                . 'de seguimiento.');
            return 0;
        }

        if ($options['only'] && !in_array($options['only'], $steps))
            $this->fail("Paso desconocido: {$options['only']}. Son: "
                . implode(', ', $steps));

        // El recuento se valida aquí, con el resto de las opciones: un
        // `--count=2oo` (letra o) tiene que decirlo antes de conectar y de
        // crear la mitad del conjunto.
        if (($error = $this->parseCount($options['count'])))
            $this->fail($error);

        $this->withTasks = (bool) $options['with-tasks'];
        if ($this->withTasks && $options['only']
                && !in_array($options['only'], array('tickets', 'tasks')))
            $this->fail('--with-tasks sólo tiene sentido con el paso tickets '
                . '(o con la ejecución completa)');

        Bootstrap::connect();
        if (!($ost = osTicket::start()) || !($cfg = $ost->getConfig()))
            $this->fail('No se pudo leer la configuración de la base de datos');

        // Estados y prioridades viven en las listas internas; `class.ticket.php`
        // arrastra los formularios y `class.task.php`.
        require_once INCLUDE_DIR . 'class.list.php';
        require_once INCLUDE_DIR . 'class.priority.php';
        require_once INCLUDE_DIR . 'class.ticket.php';

        // Las tablas `*__cdata` (asunto del ticket, título de la tarea…) son
        // tablas materializadas que sólo aparecen tras la primera consulta.
        // Sin ellas, el ORM no puede buscar por asunto ni por título —que es
        // como el sembrador evita duplicar— y `ensureDynamicDataView()` con
        // sus valores por omisión terminaría con un `die()`.
        DynamicForm::ensureDynamicDataViews(true);

        $this->loadManifest();

        // El administrador que ya tiene la instalación (si lo hay) es el que
        // firma las tareas y las notas, y el que se muestra al final. Nunca
        // se crea un segundo administrador.
        $this->adminStaff = $this->firstAdmin();

        // `--doctor` no toca nada: sólo mira y dictamina. `--repair` arregla
        // lo que se puede arreglar y después pasa la misma revisión.
        if ($options['doctor'] || $options['repair'])
            return $this->doctor($options['repair']);

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
            // `--no-tickets` deja la instalación configurada y sin volumen:
            // ni tickets ni tareas, que es lo que pide quien sólo quiere
            // probar el panel.
            if (in_array($step, array('tickets', 'tasks'))
                    && $options['no-tickets'])
                continue;

            $this->step = $step;
            $this->out('');
            $this->out("[{$step}]");

            try {
                $this->{'seed' . ucfirst($step)}($this->limitFor($step));
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

        if ($this->adminStaff) {
            $this->out(sprintf('Listo. Entre al panel con el usuario «%s» (el '
                . 'administrador de la instalación: su contraseña es la de '
                . 'siempre).', $this->adminStaff->getUserName()));
            $this->out('Los demás agentes (supervisor, agente1…) y las cuentas '
                . 'de cliente usan la contraseña indicada arriba.');
        }
        else {
            $this->out('Listo. Entre al panel con el usuario «admin» y la '
                . 'contraseña indicada arriba.');
        }
        $this->out('Para deshacerlo: php manage.php seed --fresh (o borre la '
            . 'base y vuelva a instalarla).');

        return 0;
    }

    /**
     * Lee `--count`. Devuelve el motivo del error o `null` si vale.
     *
     * Sin `--count` el plan es el curado, que es lo que hacía siempre. Un
     * cero explícito tampoco crea: `--count=0` deja el paso sin registros,
     * que es la forma de decir «configura, pero no llenes».
     */
    function parseCount($value) {
        if ($value === null || $value === '')
            return $this->count = null;

        if (!preg_match('/^\d+$/', (string) $value))
            return "--count tiene que ser un número entero: «{$value}»";

        return $this->count = (int) $value;
    }

    /**
     * Cuántos registros pide el paso, o `null` si va con el plan curado.
     *
     * Sólo hay tres pasos que se puedan repetir con sentido: los usuarios,
     * los tickets y las tareas. Un `--count=50` con `--only=topics` no
     * significa nada, y en vez de inventar cincuenta temas iguales se avisa.
     */
    function limitFor($step) {
        if ($this->count === null)
            return null;

        if (!in_array($step, array('users', 'tickets', 'tasks'))) {
            if ($this->count > 0)
                $this->out("  (--count no se aplica al paso {$step})");
            return null;
        }

        return $this->count;
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
    /* Ayudas de los pasos                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Primeros 40 caracteres, para localizar la entrada recién creada por su
     * cuerpo. Sin `mbstring` (algunas instalaciones mínimas) se corta por
     * bytes: sirve igual para buscar.
     */
    static function excerpt($text) {
        return function_exists('mb_substr')
            ? mb_substr($text, 0, 40) : substr($text, 0, 40);
    }

    static function lower($text) {
        return function_exists('mb_strtolower')
            ? mb_strtolower($text) : strtolower($text);
    }

    /** Un texto de apertura con algo de cuerpo, según el tema. */
    function message($subject, $topic) {
        return sprintf(
            "Hola,\n\nEscribo por lo siguiente: %s.\n\n"
            . "Tema: %s\n\nLo he intentado con el navegador y con la "
            . "aplicación móvil, y pasa igual.\n\nGracias.",
            self::lower($subject), $topic);
    }

    function clientReply($ticket, $text, $when) {
        try {
            // Sin avisos: no hay cuenta de correo configurada en una
            // instalación recién creada y el hilo se llena igual.
            $entry = $ticket->postMessage(array(
                'userId' => $ticket->getUserId(),
                'poster' => (string) $ticket->getName(),
                'message' => $text,
                'ip_address' => '127.0.0.1',
                'reply-to' => null,
                'ccs' => null,
                'header' => null,
            ), 'Web', false);
            if ($entry)
                $this->backdateEntry($ticket, $entry, $when);
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
    /**
     * Día (antigüedad) de la entrada `$r` de un hilo de `$count` mensajes.
     *
     * Se reparte entre `$age - 1` (lo más antiguo, justo después de abrir el
     * ticket) y `$minDay` (lo más reciente: 0 = hoy, o el día del cierre si el
     * ticket ya está cerrado). Sin ese reparto los mensajes se fechaban hasta
     * hoy aunque el ticket llevara semanas cerrado, y las cuentas de tiempo de
     * respuesta y resolución salían raras.
     *
     * Es una función pura a propósito: la prueba comprueba el reparto sin
     * base de datos.
     */
    function threadDay($age, $count, $r, $minDay = 0) {
        $age = max(0, (int) $age);
        $count = max(1, (int) $count);
        $r = min($count, max(1, (int) $r));

        $latest = max(0, (int) $age - 1);
        $earliest = min(max(0, (int) $minDay), $latest);

        if ($count == 1)
            return $latest;

        // De `$latest` (r=1) a `$earliest` (r=$count)
        return (int) round($latest
            - ($latest - $earliest) * ($r - 1) / ($count - 1));
    }

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

            if (!$ok) {
                $ok = $ticket->postNote(array(
                    'note' => $text,
                    'staffId' => $agent->getId(),
                    'userId' => 0,
                    'poster' => $agent->getName(),
                    'ip_address' => '127.0.0.1',
                    'note_status_id' => 0,
                ), $errors, false, false);
            }

            if ($ok)
                $this->backdateEntry($ticket, $ok, $when);
            else
                $this->warn('respuesta del agente: '
                    . Format::array_implode(' · ', ' · ', $errors));
        }
        catch (Throwable $e) {
            $this->warn('respuesta del agente: ' . $e->getMessage());
        }
    }

    /**
     * Fecha un evento del hilo (`created`, `closed`, `overdue`…).
     *
     * `logEvent()` (y `ThreadEvent::create()`) ponen `NOW()` en el timestamp,
     * y el *Statistics* nativo de osTicket se calcula **sólo** con la tabla de
     * eventos: si todos quedan en el instante del sembrado, el «Service Time»
     * sale 0.0 y «Closed» sale 0 aunque haya tickets cerrados. No basta con
     * ajustar `ticket.created`: hay que ajustar el evento.
     */
    function backdateEvent($object, $state, $when) {
      try {
        if (!class_exists('ThreadEvent') || !class_exists('Event'))
            return;

        if (!($thread = $object->getThread()))
            return;

        if (!($eventId = Event::getIdByName($state)))
            return;

        $found = ThreadEvent::objects()
            ->filter(array('thread_id' => $thread->getId(),
                'event_id' => $eventId))
            ->order_by('-id')
            ->all();

        if ($found) {
            // `ThreadEvent` no declara `getId()` (sólo `Event` lo tiene): el
            // id es la columna `id`, que se lee como propiedad.
            ThreadEvent::objects()
                ->filter(array('id' => $found[0]->id))
                ->update(array('timestamp' => $when));
        }
      }
      catch (Throwable $e) {
        // Fechar es cosmético para el hilo, pero imprescindible para los
        // informes: se avisa y se sigue con el resto (un fallo aquí no debe
        // dejar el paso `tickets` a medias).
        $this->warn("no se pudo fechar el evento «{$state}»: "
            . $e->getMessage());
      }
    }

    /** Registra un evento del hilo con fecha del pasado. */
    function logEventAt($object, $state, $when, $staff = null) {
        try {
            $object->logEvent($state, null, $staff);
            $this->backdateEvent($object, $state, $when);
        }
        catch (Throwable $e) {
            $this->warn("evento «{$state}»: " . $e->getMessage());
        }
    }

    /**
     * Mueve la fecha de creación de todo el ticket: ticket, hilo **y la
     * entrada con el mensaje de apertura**.
     *
     * La entrada de apertura se quedaba en `NOW()`. Como las respuestas sí se
     * fechan en el pasado (`backdateEntry()`), quedaban *antes* de su mensaje
     * padre: el *Statistics* nativo mide el tiempo de respuesta como
     * `timestampdiff(parent.created, entry.created)` y salía **negativo** en
     * la tabla por departamento. Ahora la apertura y la conversación están en
     * el mismo eje de tiempo.
     *
     * Se llama justo después de `Ticket::create()`, cuando el hilo tiene una
     * sola entrada, así que mover todas las entradas del hilo es mover la de
     * apertura.
     */
    function backdate($ticket, $when) {
        Ticket::objects()
            ->filter(array('ticket_id' => $ticket->getId()))
            ->update(array('created' => $when, 'lastupdate' => $when));
        Thread::objects()
            ->filter(array('object_id' => $ticket->getId(), 'object_type' => 'T'))
            ->update(array('created' => $when));
        if (($thread = $ticket->getThread()))
            ThreadEntry::objects()
                ->filter(array('thread_id' => $thread->getId()))
                ->update(array('created' => $when));
    }

    /**
     * Las entradas se crean con NOW(); para que el hilo tenga fechas
     * distintas hay que ajustarlas **después**.
     *
     * `$entry` es la entrada que devolvió `postReply()`/`postNote()`/
     * `postMessage()`. Antes se buscaba «la última entrada cuyo cuerpo
     * contenga estas 40 letras», y en un hilo de 14 mensajes las frases se
     * repiten: se movía la fecha de la entrada equivocada, quedaban respuestas
     * anteriores a su mensaje padre y el *Statistics* nativo mostraba tiempos
     * de respuesta **negativos**. Con el id que devuelve el propio método no
     * hay nada que adivinar.
     *
     * `$entry` puede llegar como texto (manifiestos y llamadas antiguas): en
     * ese caso se conserva la búsqueda por cuerpo como último recurso.
     */
    function backdateEntry($object, $entry, $when) {
        $thread = $object->getThread();
        if (!$thread)
            return;

        if (!$entry instanceof ThreadEntry) {
            $text = (string) $entry;
            $found = ThreadEntry::objects()
                ->filter(array('thread_id' => $thread->getId(),
                    'body__contains' => self::excerpt($text)))
                ->order_by('-id')
                ->all();
            $entry = $found ? $found[0] : null;
        }
        if ($entry) {
            ThreadEntry::objects()
                ->filter(array('id' => $entry->getId()))
                ->update(array('created' => $when));
        }
        Thread::objects()
            ->filter(array('id' => $thread->getId()))
            ->update(array('lastmessage' => $when));
    }

    /**
     * Deja el ticket en un estado terminado (`resolved` o `closed`).
     *
     * No se usa `Ticket::setStatus()`: exige un agente con permiso de cierre,
     * dispara avisos por correo y puede devolver `false` sin decir por qué.
     * Aquí se escribe el estado y, si el estado elegido es de tipo `closed`,
     * también la fecha de cierre: el ticket queda como si se hubiera cerrado
     * desde el panel.
     */
    function closeTicket($ticket, $agent, $when, $closed = null) {
        try {
            $closed = $closed ?: $this->statusIdByState('closed');
            $status = $closed ? TicketStatus::lookup($closed) : null;
            $isClosed = $status && !strcasecmp($status->getState(), 'closed');

            Ticket::objects()
                ->filter(array('ticket_id' => $ticket->getId()))
                ->update(array(
                    'closed' => $isClosed ? $when : null,
                    'status_id' => $closed ?: $ticket->status_id,
                    'staff_id' => $agent ? $agent->getId() : $ticket->staff_id,
                    'isanswered' => 1,
                    'lastupdate' => $when,
                ));

            // El cierre se hace por `UPDATE` (desde la CLI `Ticket::setStatus()`
            // devuelve `false`), así que el evento «closed» hay que registrarlo
            // aquí: sin él, `Statistics` cuenta 0 cerrados y el «Service Time»
            // no tiene con qué calcularse.
            $this->logEventAt($ticket, 'closed', $when, $agent);
        }
        catch (Throwable $e) {
            $this->warn('no se pudo cerrar el ticket: ' . $e->getMessage());
        }
    }

    /**
     * Fecha SQL, N días atrás, a una hora laborable. `$minutes` desplaza la
     * hora para que dos entradas del mismo día no compartan marca de tiempo
     * (el hilo se ordena por fecha además de por id).
     */
    function daysAgo($days, $minutes = 0) {
        return date('Y-m-d H:i:s', strtotime(sprintf('-%d days 10:30',
            max(0, (int) $days))) + max(0, (int) $minutes) * 60);
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

    /**
     * Los ids de los estados habilitados cuyo `state` está en la lista, en el
     * orden de la lista interna (así «Abierto» va antes que «En progreso»).
     */
    function statusIdsByStates($states) {
        $ids = array();
        foreach (TicketStatus::objects()
                ->filter(array('state__in' => $states,
                    'mode__hasbit' => TicketStatus::ENABLED))
                ->order_by('sort', 'id') as $status)
            $ids[] = (int) $status->getId();

        // Si la instalación ha desactivado algún estado, el hueco se cubre
        // con el primero disponible para no dejar tickets sin estado.
        return $ids;
    }

    /** Origen del ticket, repartido como en una instalación real. */
    function sourceFor($i) {
        $sources = array('Web', 'Web', 'Email', 'Phone', 'API', 'Other');
        return $sources[$i % count($sources)];
    }

    /** Mueve la fecha de creación de la tarea y de su hilo (create pone NOW). */
    function backdateTask($task, $when) {
        Task::objects()
            ->filter(array('id' => $task->getId()))
            ->update(array('created' => $when, 'updated' => $when));
        Thread::objects()
            ->filter(array('object_id' => $task->getId(),
                'object_type' => ObjectModel::OBJECT_TYPE_TASK))
            ->update(array('created' => $when, 'lastmessage' => $when));
    }

    /** Nota interna de una tarea (la tarea no tiene respuestas al cliente). */
    function taskNote($task, $poster, $text, $when) {
        $errors = array();
        try {
            $ok = $task->postNote(array(
                'note' => $text,
                'title' => sprintf('Seguimiento de la tarea #%s',
                    $task->getNumber()),
                'staffId' => $poster->getId(),
                'userId' => 0,
                'poster' => $poster->getName(),
                'ip_address' => '127.0.0.1',
            ), $errors, $poster, false);

            if ($ok)
                $this->backdateEntry($task, $ok, $when);
            else
                $this->warn('nota de la tarea: '
                    . Format::array_implode(' · ', ' · ', $errors));
        }
        catch (Throwable $e) {
            $this->warn('nota de la tarea: ' . $e->getMessage());
        }
    }

    /** Nota interna del agente (no se envía al cliente ni necesita correo). */
    function agentNote($ticket, $agent, $text, $when) {
        if (!$agent)
            return;

        $errors = array();
        try {
            $ok = $ticket->postNote(array(
                'note' => $text,
                'staffId' => $agent->getId(),
                'userId' => 0,
                'poster' => $agent->getName(),
                'ip_address' => '127.0.0.1',
                'note_status_id' => 0,
            ), $errors, false, false);

            if ($ok)
                $this->backdateEntry($ticket, $ok, $when);
            else
                $this->warn('nota interna: '
                    . Format::array_implode(' · ', ' · ', $errors));
        }
        catch (Throwable $e) {
            $this->warn('nota interna: ' . $e->getMessage());
        }
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
            $index[self::lower((string) $role->getName())] = $role->getId();
            if ($i == 0)
                $index['_first'] = $role->getId();
            elseif ($i == 1)
                $index['_second'] = $role->getId();
        }

        return $index;
    }

    /** Todos los permisos para el administrador; el siguiente, para el resto. */
    /** Todas las claves de permiso que entiende osTicket. */
    function allPermissions() {
        $keys = array();
        foreach (RolePermission::allPermissions() as $group)
            $keys = array_merge($keys, array_keys($group));

        return $keys;
    }

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
        // Se apunta **ya**, no al final: si la ejecución se corta a mitad (un
        // error de PHP, un Ctrl-C, un contenedor que se reinicia), lo creado
        // hasta ese momento tiene que poder borrarse con `--fresh`. Sin esto,
        // los registros quedaban para siempre y cada ejecución decía «ya
        // existía».
        $this->saveManifest();
        $this->counted($step, 'created');
        if ($this->verbose)
            $this->out(sprintf('  + %s (#%s)', $label, $id));
    }

    /**
     * Interpreta una entrada del manifiesto del paso `faq`.
     *
     * Las categorías y los artículos de la base de conocimiento son dos
     * tablas con numeración independiente: la categoría 3 y el artículo 3
     * existen a la vez. El manifiesto guarda `c3` / `f3` para no
     * confundirlos; los manifiestos anteriores tenían sólo el número.
     *
     * @param mixed $ref entrada tal como quedó en el manifiesto
     * @return array{type:string,id:int}|null null si es un id suelto
     */
    static function faqRef($ref) {
        if (!preg_match('/^([cf])(\d+)$/', (string) $ref, $m))
            return null;

        return array(
            'type' => $m[1] == 'c' ? 'category' : 'article',
            'id' => (int) $m[2],
        );
    }

    /**
     * Como `created()`, pero guarda una **referencia con tipo** (`c7`, `f12`).
     *
     * En la base de conocimiento, categorías y artículos son dos tablas
     * distintas con numeración independiente: el artículo 3 y la categoría 3
     * existen a la vez. Guardar sólo el número hacía que `--fresh` borrara el
     * artículo correcto y dejara la categoría —o al revés—, y el siguiente
     * sembrado las veía como «ya existía».
     */
    function createdRef($step, $type, $id, $label) {
        $this->created($step, $type . $id, $label);
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
    /**
     * Segunda pasada de la purga: lo que el manifiesto no conoce.
     *
     * El manifiesto se escribe ahora después de **cada** registro, así que una
     * ejecución interrumpida ya no pierde identificadores. Esta pasada queda
     * para los restos de versiones anteriores del sembrador (las que perdían
     * el manifiesto) y se limita a lo que es **inequívocamente** del
     * sembrador: los artículos y categorías de la base de conocimiento
     * —preguntas y nombres que sólo existen en `SeedManager::faqs()`— y los dos
     * equipos de demostración.
     *
     * Deliberadamente **no** borra por nombre departamentos, SLA,
     * organizaciones, usuarios, tickets ni tareas: si alguien tiene un
     * departamento «Soporte» o un SLA «Respuesta 8 h» propios, con el mismo
     * nombre, borrarlos por parecerse sería destruir su configuración. Esos se
     * borran sólo por identificador, que es lo que el manifiesto guarda.
     *
     * @return int cuántos registros sueltos se borraron
     */
    function purgeByName() {
        $removed = 0;

        // Conocimiento: primero los artículos, después sus categorías.
        //
        // Se buscan **con la misma función que usa el sembrador para decidir
        // si algo ya existe** (`faqIdByQuestion()`, `categoryByName()`), no con
        // un `IN` aparte. Cuando los dos criterios no coinciden, la purga
        // borra las categorías, deja los artículos huérfanos y el paso `faq`
        // responde «6 creadas, 33 ya existían»: los artículos quedan sin
        // categoría y no se ven en la base de conocimiento.
        $articles = array();
        foreach (self::faqs() as $items)
            foreach ($items as $article)
                if ($id = $this->faqIdByQuestion($article[0]))
                    if ($faq = FAQ::lookup($id))
                        $articles[] = $faq;
        $removed += $this->purgeAll($articles, 'artículo');

        $categories = array();
        foreach (array_keys(self::faqs()) as $name)
            if ($category = $this->categoryByName($name))
                $categories[] = $category;
        $removed += $this->purgeAll($categories, 'categoría');

        // Los equipos de demostración tienen nombres que no se repiten
        $teams = array();
        foreach (self::teams() as $row)
            $teams[] = $row[0];
        if ($teams)
            $removed += $this->purgeAll(Team::objects()->filter(
                array('name__in' => $teams)), 'equipo');

        return $removed;
    }

    /**
     * Borra las filas de hilo y de entrada que quedaron sin dueño.
     *
     * `Ticket::delete()` borra el ticket y su hilo… casi siempre. Cuando no
     * (o cuando el borrado se hizo por `UPDATE`/SQL), quedan filas en
     * `thread`, `thread_entry` y `thread_event` sin ticket detrás. No se ven
     * en ninguna cola, pero **sí cuentan en los informes**: el *Statistics*
     * nativo recorre `thread_event` sin comprobar que el hilo tenga ticket, y
     * por eso aparecía «Deleted 20» para un agente que no había borrado nada
     * a mano. Sólo se borran huérfanas: lo que tiene dueño no se toca.
     *
     * @return int filas borradas
     */
    function cleanupOrphans() {
        if (!function_exists('db_query'))
            return 0;

        $removed = 0;
        $queries = array(
            // Eventos de hilos que ya no existen
            'DELETE e FROM ' . THREAD_EVENT_TABLE . ' e
                LEFT JOIN ' . THREAD_TABLE . ' t ON t.id = e.thread_id
              WHERE t.id IS NULL',
            // Entradas de hilos que ya no existen
            'DELETE en FROM ' . THREAD_ENTRY_TABLE . ' en
                LEFT JOIN ' . THREAD_TABLE . ' t ON t.id = en.thread_id
              WHERE t.id IS NULL',
            // Hilos sin objeto detrás (ni ticket, ni tarea)
            'DELETE t FROM ' . THREAD_TABLE . ' t
                LEFT JOIN ' . TICKET_TABLE . ' k
                    ON k.ticket_id = t.object_id AND t.object_type = \'T\'
                LEFT JOIN ' . TASK_TABLE . ' a
                    ON a.id = t.object_id AND t.object_type = \'A\'
              WHERE t.object_type IN (\'T\', \'A\')
                AND k.ticket_id IS NULL AND a.id IS NULL',
        );

        foreach ($queries as $sql) {
            // sql-audit:legacy borrado de huérfanos con LEFT JOIN
            if (db_query($sql, false))
                $removed += db_affected_rows();
            else
                $this->warn('no se pudieron limpiar filas huérfanas de hilo');
        }

        if ($removed)
            $this->out(sprintf('  · %d fila(s) de hilo sin dueño eliminadas '
                . '(no cuentan en los informes)', $removed));

        return $removed;
    }

    /** Un único valor de una consulta, o null si falla. */
    function scalar($sql) {
        if (!function_exists('db_query'))
            return null;

        // sql-audit:legacy consultas de sólo lectura del chequeo
        $res = db_query($sql, false);
        if (!$res)
            return null;

        $row = db_fetch_row($res);

        return ($row && isset($row[0])) ? $row[0] : null;
    }

    /**
     * Borra lo que devuelve un QuerySet, uno a uno (para que cada modelo haga
     * su limpieza) y sin que un fallo detenga el resto.
     *
     * @param mixed  $set  modelos a borrar
     * @param string $what nombre del tipo, para los avisos
     * @return int cuántos se borraron
     */
    function purgeAll($set, $what) {
        $removed = 0;

        // `all()` y no el iterador: el QuerySet lee las filas de la consulta
        // según se recorre (resultado sin buffer del driver), y borrar una
        // fila mientras se recorre la misma tabla descuadra el resto —se
        // saltaban registros—. Materializar primero no cuesta nada: son las
        // decenas de registros del sembrador.
        if (!is_array($set) && $set instanceof QuerySet)
            $set = $set->all();

        foreach ($set as $obj) {
            try {
                if ($obj->delete() === false)
                    $this->warn(sprintf('no se pudo borrar %s #%s: puede tener '
                        . 'datos asociados', $what, $obj->getId()));
                else
                    $removed++;
            }
            catch (Throwable $e) {
                $this->warn(sprintf('no se pudo borrar %s #%s: %s', $what,
                    $obj->getId(), $e->getMessage()));
            }
        }

        return $removed;
    }

    function purge() {
        global $thisstaff;

        // Sin manifiesto (una ejecución antigua lo perdió, o nunca se
        // escribió) todavía queda la pasada por nombre: no se sale sin
        // intentarlo.
        $m = $this->manifest ?: array();
        $this->out($m
            ? 'Borrando el conjunto anterior…'
            : 'No hay manifiesto: se buscan restos por nombre…');

        // `Team::delete()` (y otros modelos) exigen un agente en sesión y
        // devuelven `false` sin decir nada cuando no lo hay: en la CLI no hay
        // sesión, así que se pone el administrador y luego se restaura.
        $previous = $thisstaff;
        $agents = $this->agentIndex();
        // El administrador de la instalación sabe borrar todo lo que crea el
        // sembrador; cualquier otro agente puede no tener permiso y
        // `delete()` devolvería `false` sin decir nada.
        $thisstaff = $this->adminStaff
            ?: ($agents['admin'] ?? ($agents ? reset($agents) : null));

        // De lo más dependiente a lo más básico: los departamentos antes que
        // los SLA (apuntan a ellos) y los agentes antes que los departamentos.
        foreach (array('tasks', 'tickets', 'faq', 'canned', 'topics', 'teams',
                'agents', 'depts', 'slas', 'users', 'orgs') as $step) {
            foreach ($m[$step] ?? array() as $id) {
                try {
                    switch ($step) {
                    case 'tasks':
                        if ($t = Task::lookup($id))
                            $t->delete();
                        break;
                    case 'tickets':
                        if ($t = Ticket::lookup($id))
                            $t->delete();
                        break;
                    case 'faq':
                        // Referencias con tipo (`c7` categoría, `f12` artículo):
                        // sin ellas el id de un artículo podía coincidir con el
                        // de una categoría y se borraba el registro equivocado.
                        if ($ref = self::faqRef($id)) {
                            $obj = $ref['type'] == 'category'
                                ? Category::lookup($ref['id'])
                                : FAQ::lookup($ref['id']);
                            if ($obj && $obj->delete() === false)
                                $this->warn(sprintf('no se pudo borrar el '
                                    . 'registro de conocimiento %s', $id));
                            break;
                        }
                        // Manifiestos de versiones anteriores: id suelto
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
                        if (($t = Team::lookup($id)) && $t->delete() === false)
                            $this->warn(sprintf('no se pudo borrar el equipo '
                                . '#%s: el conjunto anterior queda a medias',
                                $id));
                        break;
                    case 'agents':
                        // El administrador en uso nunca se borra: si la
                        // instalación nació con la cuenta de demostración
                        // (`admin`, correo del dominio de prueba), borrarla
                        // dejaría el panel sin acceso.
                        if ($this->adminStaff
                                && $this->adminStaff->getId() == $id)
                            break;
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

        // Los registros que la ejecución anterior no pudo borrar (o que no
        // llegaron al manifiesto) se quitan por nombre
        $loose = $this->purgeByName();
        if ($loose)
            $this->out(sprintf('  · %d registro(s) sueltos de ejecuciones '
                . 'anteriores', $loose));

        $thisstaff = $previous;

        // Las filas de hilo que quedaron sin dueño (sus tickets se borraron)
        // siguen contando en los informes: `Statistics` sumaba «Deleted 20»
        // al administrador por los tickets que había borrado `--fresh`.
        $this->cleanupOrphans();

        // Lo borrado ya no tiene que estar apuntado: si la ejecución se corta
        // ahora, no debe quedar un manifiesto que cite ids muertos.
        $this->manifest = array();
        $this->saveManifest();
        @unlink($this->manifestPath());

        return true;
    }
}

Module::register('seed', 'SeedManager');
