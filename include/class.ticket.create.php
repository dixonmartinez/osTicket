<?php
/**
 * include/class.ticket.create.php
 *
 * Piezas de Ticket::create() (frentes 2 y 6 de docs/CODE_QUALITY.md).
 *
 * create() era la función más larga de la aplicación: 536 líneas seguidas de
 * «haz esto y luego aquello» con siete niveles de anidación. Se partió por
 * fases, cada una con su nombre, y quedó en 219 líneas que se leen de arriba
 * abajo:
 *
 *   requestErrors()            campos que exige el origen y fecha límite
 *   topicForms()               formularios del tema, instanciados
 *   formErrors()               validación de los formularios
 *   routing()                  departamento, estado, tema, SLA, número
 *   saveForms()                guardar el formulario y los del tema
 *   addCollaborators()         colaboradores de la petición y de la org.
 *   linkOriginThread()         aviso cruzado con el ticket o la tarea origen
 *   applyStatusAndAssignment() SLA, estado, asignación, fecha estimada
 *   notify()                   respuesta automática y avisos
 *
 * Las cuatro primeras no tocan la base de datos (o sólo leen), y son las que
 * se prueban en `tests/TicketCreateTest.php`; las demás son flujo con
 * persistencia y se comprueban en la suite de integración.
 *
 * Lo puro que ya estaba: qué campos exige cada origen, qué campos se validan
 * según quién abre el ticket, si la fecha límite es válida y cómo se
 * normaliza el origen.
 */
class TicketCreate {

    /**
     * Campos obligatorios de la petición según el origen.
     *
     * Reproduce el `switch` de create(), incluido el caso de 'staff' que
     * además exige `source` (cae al bloque de 'api').
     *
     * @return array|false  false si el origen no es válido
     */
    static function requiredFields($origin) {
        switch (strtolower($origin)) {
        case 'web':
            return array(
                'topicId' => array('type' => 'int', 'required' => 1,
                    'error' => __('Select a Help Topic')),
            );

        case 'staff':
            return array(
                'deptId' => array('type' => 'int', 'required' => 0,
                    'error' => __('Department selection is required')),
                'topicId' => array('type' => 'int', 'required' => 1,
                    'error' => __('Help topic selection is required')),
                'duedate' => array('type' => 'date', 'required' => 0,
                    'error' => __('Invalid date format - must be MM/DD/YY')),
                'source' => array('type' => 'string', 'required' => 1,
                    'error' => __('Indicate ticket source')),
            );

        case 'api':
            return array(
                'source' => array('type' => 'string', 'required' => 1,
                    'error' => __('Indicate ticket source')),
            );

        case 'email':
            return array(
                'emailId' => array('type' => 'int', 'required' => 1,
                    'error' => __('Unknown system email')),
            );
        }

        return false;
    }

    /**
     * Filtro de validación de campos según quién abre el ticket.
     *
     * Se devuelve con la misma forma que usaba create() —un closure que
     * recibe un nombre de formulario ('user', 'ticket', 'topic') y devuelve
     * el filtro de campo— para no cambiar las llamadas:
     *
     *   $field_filter = TicketCreate::fieldFilter($origin);
     *   $form->isValid($field_filter('ticket'));
     *
     * El nombre del formulario no se usa: los cuatro orígenes se comportan
     * igual para todos.
     */
    static function fieldFilter($origin) {
        $origin = strtolower($origin);

        return function ($type) use ($origin) {
            return function ($f) use ($origin) {
                switch ($origin) {
                case 'email':
                    // Por correo no se puede validar nada
                    return false;
                case 'staff':
                    // Los campos de contacto no son obligatorios si abre un
                    // agente
                    return $f->isVisibleToStaff();
                case 'web':
                    return $f->isVisibleToUsers();
                }

                return true;
            };
        };
    }

    /**
     * Error de la fecha límite, o null si es correcta.
     *
     * @param string   $duedate  fecha tal como llega de la petición
     * @param callable $toGmt    conversión de hora local a GMT
     *                           (`Misc::user2gmtime`)
     * @return string|null       mensaje para el usuario
     */
    static function dueDateError($duedate, callable $toGmt) {
        if (!$duedate)
            return null;

        if (strtotime($duedate) === false)
            return __('Invalid due date');

        if ($toGmt($duedate) <= $toGmt())
            return __('Due date must be in the future');

        return null;
    }

    /** Origen tal como se guarda: la primera letra en mayúscula. */
    static function source($source) {
        return ucfirst((string) $source);
    }

    /* ------------------------------------------------------------------ */
    /* Fases de Ticket::create()                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Campos que exige el origen y comprobación de la fecha límite.
     *
     * Sustituye al bloque que abría la validación de create(). `$vars` viaja
     * por referencia porque Validator::process() lo normaliza.
     *
     * @return bool  false si ya hay errores
     */
    static function requestErrors($origin, &$vars, &$errors) {
        $fields = self::requiredFields($origin);
        if ($fields === false)
            $errors['err'] = $errors['origin'] =
                __('Invalid ticket origin given');

        // El resumen sólo se añade si el validador no puso uno propio
        if (!Validator::process($fields ?: array(), $vars, $errors)
                && !isset($errors['err']))
            $errors['err'] = sprintf('%s — %s',
                __('Missing or invalid data'),
                __('Correct any errors below and try again'));

        if ($error = self::dueDateError($vars['duedate'],
                    array('Misc', 'user2gmtime')))
            $errors['duedate'] = $error;

        return !$errors;
    }

    /**
     * Formularios asociados al tema de ayuda.
     *
     * Instancia un formulario por cada formulario del tema y, en el
     * formulario del ticket, desactiva los campos que el tema pide
     * desactivar. El resultado son los formularios del tema, sin el del
     * ticket (que se modifica en sitio).
     *
     * @param DynamicForm $form  formulario del ticket
     * @param array       $vars  datos de la petición
     * @return array<int,DynamicForm>
     */
    static function topicForms($form, array $vars) {
        $topic_forms = array();

        if (empty($vars['topicId'])
                || !($topic = Topic::lookup($vars['topicId'])))
            return $topic_forms;

        foreach ($topic->getForms() as $idx => $__F) {
            $disabled = array();
            foreach ($__F->getFields() as $field) {
                if (!$field->isEnabled()
                        && $field->hasFlag(DynamicFormField::FLAG_ENABLED))
                    $disabled[] = $field->get('id');
            }

            if ($__F->get('type') == 'T') {
                foreach ($form->getFields() as $field) {
                    if (false !== array_search($field->get('id'), $disabled))
                        $field->disable();
                }
                $form->sort = $idx;
                $__F = $form;
            }
            else {
                $__F = $__F->instanciate($idx);
                $__F->setSource($vars);
                $topic_forms[] = $__F;
            }

            // Campos desactivados, para poder deshacerlo al repintar
            $__F->extra = JsonDataEncoder::encode(array(
                'disable' => $disabled
            ));
        }

        return $topic_forms;
    }

    /**
     * Validación de los formularios: el del ticket y los del tema.
     *
     * `$topic` sale por referencia porque es aquí donde se resuelve el tema
     * de la petición, y create() lo necesita después. Si el tema no existe o
     * no está activo, se guarda el ticket sin tema, igual que antes.
     *
     * @return bool  false si hay errores de formulario
     */
    static function formErrors($form, array $topic_forms, &$vars,
            callable $field_filter, &$errors, &$topic) {
        if (!$form->isValid($field_filter('ticket')))
            $errors += $form->errors();

        if (!$vars['topicId'])
            return !$errors;

        if (is_numeric($vars['topicId'])
                && ($topic = Topic::lookup((int) $vars['topicId']))
                && $topic->isActive()) {
            foreach ($topic_forms as $topic_form) {
                $TF = $topic_form->getForm($vars);
                if (!$TF->isValid($field_filter('topic')))
                    $errors = array_merge($errors, $TF->errors());
            }
        }
        else {
            $vars['topicId'] = 0;
        }

        return !$errors;
    }

    /**
     * Departamento, estado, tema, prioridad, SLA, número y asignación.
     *
     * Es el tramo de create() que decide a dónde va el ticket: primero el
     * correo (que puede imponer departamento, prioridad y tema), después el
     * tema (departamento, estado, prioridad, SLA y asignación automática) y
     * por último el gestor de cuenta de la organización. Los valores por
     * omisión de la configuración cierran la lista.
     *
     * `$vars` y `$autorespond` viajan por referencia: el tema puede fijar
     * `slaId`, `staffId`, `teamId` y desactivar la respuesta automática.
     *
     * @return array{dept_id:int,status_id:int,topic:mixed,topic_id:int,
     *               source:string,ip:string,number:string,org:mixed}
     */
    static function routing(array &$vars, $cfg, $form, $topic, $user,
            &$autorespond) {
        $statusId = $vars['statusId'];
        $deptId = $vars['deptId']; //pre-selected Dept if any.
        $source = self::source($vars['source']);
        $org = null;

        // Los ajustes del correo mandan sobre los del tema
        if ($vars['emailId'] && ($email = Email::lookup($vars['emailId']))) {
            $deptId = $deptId ?: $email->getDeptId();
            $dept = Dept::lookup($deptId);
            if ($dept && !$dept->isActive())
                $deptId = $cfg->getDefaultDeptId();
            $priority = $form->getAnswer('priority');
            if (!$priority || !$priority->getIdValue())
                $form->setAnswer('priority', null, $email->getPriorityId());
            if ($autorespond)
                $autorespond = $email->autoRespond();
            if (!isset($topic)
                    && ($T = $email->getTopic())
                    && ($T->isActive())) {
                $topic = $T;
            }
            $email = null;
            $source = 'Email';
        }

        if (!isset($topic)) {
            // This may return NULL, no big deal
            $topic = $cfg->getDefaultTopic();
        }

        // Mapeo del tema: departamento, estado, prioridad y asignación
        if (isset($topic)) {
            $deptId = $deptId ?: $topic->getDeptId();
            $statusId = $statusId ?: $topic->getStatusId();
            $priority = $form->getAnswer('priority');
            if (!$priority || !$priority->getIdValue())
                $form->setAnswer('priority', null, $topic->getPriorityId());
            if ($autorespond)
                $autorespond = $topic->autoRespond();

            //Auto assignment.
            if (!isset($vars['staffId']) && $topic->getStaffId())
                $vars['staffId'] = $topic->getStaffId();
            elseif (!isset($vars['teamId']) && $topic->getTeamId())
                $vars['teamId'] = $topic->getTeamId();

            // Un slaId a 0 significa «el del tema o el de por omisión»
            if ($vars['slaId'] == 0)
                unset($vars['slaId']);

            if (isset($vars['slaId']))
                $vars['slaId'] = $vars['slaId'] ?: $cfg->getDefaultSLAId();
            elseif ($topic->getSLAId())
                $vars['slaId'] = $topic->getSLAId();
        }

        // Asignación automática al gestor de cuenta de la organización
        if (($org = $user->getOrganization())
                && $org->autoAssignAccountManager()
                && ($code = $org->getAccountManagerId())) {
            if (!isset($vars['staffId']) && $code[0] == 's')
                $vars['staffId'] = substr($code, 1);
            elseif (!isset($vars['teamId']) && $code[0] == 't')
                $vars['teamId'] = substr($code, 1);
        }

        // Últimos valores por omisión, ya con todo resuelto
        $priority = $form->getAnswer('priority');
        if (!$priority || !$priority->getIdValue())
            $form->setAnswer('priority', null, $cfg->getDefaultPriorityId());

        return array(
            'dept_id' => $deptId ?: $cfg->getDefaultDeptId(),
            'status_id' => $statusId ?: $cfg->getDefaultTicketStatusId(),
            'topic' => $topic,
            'topic_id' => isset($topic) ? $topic->getId() : 0,
            'source' => $source ?: 'Web',
            'ip' => $vars['ip'] ?: $_SERVER['REMOTE_ADDR'],
            'number' => $topic ? $topic->getNewTicketNumber()
                : $cfg->getNewTicketNumber(),
            'org' => $org,
        );
    }

    /**
     * Guarda el formulario del ticket y los del tema, y recarga los datos
     * dinámicos del ticket recién creado.
     */
    static function saveForms($ticket, $form, array $topic_forms, $topic) {
        // El asunto es obligatorio: si viene vacío, se usa el del tema
        $subject = $form->getAnswer('subject');
        if ($subject && !$subject->getValue() && $topic)
            $subject->setValue($topic->getFullName());

        $form->setTicketId($ticket->getId());
        $form->save();

        foreach ($topic_forms as $topic_form) {
            $topic_form->setTicketId($ticket->getId());
            $topic_form->save();
        }

        $ticket->loadDynamicData(true);
    }

    /**
     * Colaboradores del ticket: los de la petición y los de la organización,
     * según cómo esté configurada.
     */
    static function addCollaborators($ticket, array $vars, $org, &$errors) {
        if (isset($vars['ccs']) && count($vars['ccs']))
            $ticket->addCollaborators($vars['ccs'], array(), $errors);

        if (!$org || !$org->autoAddCollabs())
            return;

        $pris = $org->autoAddPrimaryContactsAsCollabs();
        $members = $org->autoAddMembersAsCollabs();
        $settings = array('isactive' => true);
        $collabs = array();
        foreach ($org->allMembers() as $u) {
            $_errors = array();
            if ($members || ($pris && $u->isPrimaryContact())) {
                if ($c = $ticket->addCollaborator($u, $settings, $_errors))
                    $collabs[] = (string) $c;
            }
        }

        if ($collabs)
            $ticket->logEvent('collab', array('org' => $org->getId()));
    }

    /**
     * Aviso interno cuando el ticket nace de una entrada de otro ticket o de
     * una tarea: se anota el origen en el nuevo y el destino en el viejo.
     */
    static function linkOriginThread($ticket, $thisstaff, &$errors) {
        if (!isset($_SESSION[':form-data'])
                || (empty($_SESSION[':form-data']['ticketId'])
                    && empty($_SESSION[':form-data']['taskId'])))
            return;

        $oldTicket = Ticket::lookup($_SESSION[':form-data']['ticketId']);
        $oldTask = Task::lookup($_SESSION[':form-data']['taskId']);

        //add internal note to new ticket.
        //New ticket should have link to old task/ticket:
        $link = sprintf('<a href="%s.php?id=%d"><b>#%s</b></a>',
            $oldTicket ? 'tickets' : 'tasks',
            $oldTicket ? $oldTicket->getId() : $oldTask->getId(),
            $oldTicket ? $oldTicket->getNumber() : $oldTask->getNumber());

        $note = array(
            'title' => __('Ticket Created From Thread Entry'),
            'body' => sprintf(__(
                // %1$s is the word Ticket or Task, %2$s will be a link to it
                'This Ticket was created from %1$s %2$s'),
                $oldTicket ? __('Ticket') : __('Task'), $link));

        $ticket->logNote($note['title'], $note['body'], $thisstaff);

        //add internal note to referenced ticket/task
        // Old ticket/task should have link to new ticket
        $ticketLink = sprintf('<a href="tickets.php?id=%d"><b>#%s</b></a>',
            $ticket->getId(), $ticket->getNumber());

        $entryLink = sprintf('<a href="#entry-%d"><b>%s</b></a>',
            $_SESSION[':form-data']['eid'],
            Format::datetime($_SESSION[':form-data']['timestamp']));

        $ticketNote = array(
            'title' => __('Ticket Created From Thread Entry'),
            'body' => sprintf(__('Ticket %1$s<br/> Thread Entry: %2$s'),
                $ticketLink, $entryLink));

        $taskNote = array(
            'title' => __('Ticket Created From Thread Entry'),
            'note' => sprintf(__('Ticket %1$s<br/> Thread Entry: %2$s'),
                $ticketLink, $entryLink));

        if ($oldTicket)
            $oldTicket->logNote($ticketNote['title'], $ticketNote['body'],
                $thisstaff);
        elseif ($oldTask)
            $oldTask->postNote($taskNote, $errors, $thisstaff);
    }

    /**
     * SLA, estado, asignación y fecha límite estimada del ticket nuevo.
     *
     * El estado se fuerza cuando la petición trae uno que no existe: un
     * ticket sin estado no se puede mostrar.
     */
    static function applyStatusAndAssignment($ticket, array $vars, $statusId,
            $origin, $cfg, &$errors) {
        $ticket->selectSLAId($vars['slaId']);

        $status = TicketStatus::lookup($statusId);
        if (!$status || !$ticket->setStatus($status, false, $errors,
                    !strcasecmp($origin, 'staff')))
            $ticket->setStatusId($cfg->getDefaultTicketStatusId());

        // Sólo se asigna si el ticket queda en un estado abierto
        if ($ticket->isOpen()) {
            if ($vars['assignId']) {
                $asnform = $ticket->getAssignmentForm(array(
                    'assignee' => $vars['assignId'],
                    'comments' => $vars['note']));
                $e = array();
                $ticket->assign($asnform, $e);
            }
            else {
                $username = __('Ticket Filter');
                if ($vars['staffId'])
                    $ticket->assignToStaff($vars['staffId'], false, true,
                        $username);
                if ($vars['teamId'])
                    // Sin aviso al equipo si además se asigna a un agente
                    $ticket->assignToTeam($vars['teamId'], false,
                        !$vars['staffId'], $username);
            }
        }

        $ticket->updateEstDueDate();
    }

    /**
     * Respuesta automática, acciones en lote del origen y avisos de cierre
     * por límite de tickets abiertos.
     */
    static function notify($ticket, $message, array $vars, $autorespond,
            $alertstaff, $origin, $cfg) {
        // Si el remitente es uno de los correos internos, no se responde:
        // sería un bucle
        if ($autorespond && (Email::getIdByEmail($ticket->getEmail())))
            $autorespond = false;

        // Un rebote no recibe respuesta automática
        if (isset($vars['mailflags']) && $vars['mailflags']['bounce'])
            $autorespond = false;
        if ($autorespond && $message instanceof ThreadEntry
                && $message->isAutoReply())
            $autorespond = false;

        // Respuesta predefinida: sustituye a la automática
        if ($vars['cannedResponseId']
                && $ticket->postCannedReply($vars['cannedResponseId'],
                    $message, $autorespond)) {
            $ticket->markUnAnswered();
            $autorespond = false;
        }

        if ($vars['system_emails'])
            $ticket->systemReferral($vars['system_emails']);

        $dept = $ticket->getDept();
        if ($autorespond && $dept && !$dept->autoRespONNewTicket())
            $autorespond = false;

        // Un rebote tampoco avisa al personal
        if ($alertstaff && $message instanceof ThreadEntry
                && $message->isBounce())
            $alertstaff = false;

        $ticket->onNewTicket($message, $autorespond, $alertstaff);

        // ¿El usuario acaba de llegar al límite de tickets abiertos?
        if ($cfg->getMaxOpenTickets() > 0
                && ($user = $ticket->getOwner())
                && ($user->getNumOpenTickets() == $cfg->getMaxOpenTickets()))
            $ticket->onOpenLimit($autorespond && strcasecmp($origin, 'staff'));
    }
}
