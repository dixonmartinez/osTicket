<?php
/*********************************************************************
    include/cli/modules/seed.writes.php

    Los pasos del sembrador que escriben en la base de datos: organizaciones,
    usuarios, agentes, departamentos, equipos, SLA, temas, respuestas
    predefinidas, articulos, tickets con su conversacion y tareas.

    Es un rasgo de SeedManager y no una clase aparte porque cada paso usa el
    estado de la ejecucion (`$this->created`, `$this->skip`, el manifiesto para
    `--fresh` y el plan de volumen). Se sacaron de
    include/cli/modules/seed.php (frente 6 de docs/CODE_QUALITY.md) moviendo
    los metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait SeedWrites {

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
            $organization->set('domain',
                $org['slug'] . '.' . $this->domain);
            $organization->save();

            $this->created('orgs', $organization->getId(), $org['name']);
        }
    }

function seedUsers($limit = null) {
        // Las organizaciones se buscan **por nombre**, no por posición: la
        // instalación puede tener otras y el índice de `organizations()` no
        // tiene por qué coincidir con el orden de la base.
        $orgs = array();
        foreach (self::organizations() as $i => $org) {
            if ($found = Organization::lookup(array('name' => $org['name'])))
                $orgs[$i] = $found;
        }

        // Se avisa una vez, no una por usuario: con `--count=200` y sin el
        // paso `orgs`, doscientos avisos idénticos hacen fallar la ejecución
        // y esconden el motivo real.
        // Nota, no aviso: los usuarios se crean igual y la ejecución no debe
        // terminar en error por un paso que no se pidió.
        $noOrgs = !$orgs;
        if ($noOrgs)
            $this->out('  · no hay organizaciones: los usuarios se crean sin '
                . 'organización (el paso orgs no se ha ejecutado)');

        foreach (self::peoplePlan($limit) as $i => $person) {
            list($name, $local, $orgIndex) = $person;
            $email = $local . '@' . $this->domain;

            if (($user = User::lookupByEmail($email))) {
                $this->skip($email);
                continue;
            }

            $vars = array('name' => $name, 'email' => $email);
            if ($orgIndex !== null && isset($orgs[$orgIndex]))
                $vars['org_id'] = $orgs[$orgIndex]->getId();
            elseif ($orgIndex !== null && !$noOrgs)
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

        // `Staff::update()` valida el correo con `Validator::is_valid_email()`
        // y, con «Verify email addresses» activo (el valor por omisión), eso
        // exige que el dominio tenga MX o A/AAAA. Si el DNS del contenedor no
        // resuelve el dominio elegido, no hay agente posible: se dice una vez
        // y con la salida, en vez de fallar cinco veces con «Valid email is
        // required».
        if ($cfg->verifyEmailAddrs()
                && !Validator::is_valid_email('demo@' . $this->domain)) {
            $this->warn(sprintf('el dominio «%s» no resuelve por DNS y el '
                . 'panel tiene activa la verificación de correos, así que no '
                . 'se puede crear ningún agente. Use --domain=example.com o '
                . 'desactive «Verify email addresses» en Admin Panel → '
                . 'Emails → Settings.', $this->domain));
            return;
        }

        $roles = $this->roleIndex();
        $depts = $this->deptIndex();

        // Ya hay un administrador en la instalación: se usa ése y no se crea
        // otro. Al instalador se le pide un nombre de usuario, así que el
        // administrador de la casa puede llamarse de cualquier manera: buscar
        // por `isadmin` es lo único que funciona en todos los casos.
        $existing = $this->firstAdmin();
        if ($existing) {
            $this->adminStaff = $existing;
            $this->out(sprintf('  · se reutiliza el administrador existente: '
                . '%s (%s); su contraseña no se toca',
                $existing->getName(), $existing->getUserName()));
        }

        foreach (self::agents() as $agent) {
            list($username, $first, $last, $deptName, $isadmin, $roleName)
                = $agent;
            $email = $username . '@' . $this->domain;

            // El sembrador no crea un segundo administrador: ya se avisó
            // arriba con qué cuenta se entra, y aquí no hay nada que crear.
            if ($isadmin && $this->adminStaff)
                continue;

            if (Staff::lookup(array('username' => $username))
                    || Staff::lookup(array('email' => $email))) {
                // Puede ser el propio administrador de la instalación
                if ($isadmin && !$this->adminStaff)
                    $this->adminStaff = Staff::lookup(array('username' => $username));
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

            $vars = $this->agentVars($username, $first, $last, $dept, $role,
                $isadmin, $depts);

            $staff = Staff::create();
            $errors = array();
            if (!$staff->update($vars, $errors)) {
                $this->failed("el agente " . $username, $errors);
                continue;
            }

            if ($isadmin && !$this->adminStaff)
                $this->adminStaff = $staff;

            $this->created('agents', $staff->getId(), $username);
        }

        if (!$this->adminStaff)
            $this->adminStaff = $this->firstAdmin();
    }

/**
     * Los campos que `Staff::update()` espera para un agente de demostración.
     *
     * Están aquí y no dentro de `seedAgents()` para que aquél quede en un
     * puñado de líneas: la lista de campos es datos, no flujo.
     */
    private function agentVars($username, $first, $last, $dept, $role,
            $isadmin, $depts) {
        $vars = array(
            'id' => 0,
            'username' => $username,
            'firstname' => $first,
            'lastname' => $last,
            'email' => $username . '@' . $this->domain,
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
            // El administrador de demostración tiene todos los permisos del
            // panel, como el que crea el instalador. Con la lista vacía
            // `hasPerm()` dice que no a todo —incluido «Stats»— y la pantalla
            // de analíticas se queda en «usted sólo ve sus departamentos», que
            // no es lo que se quiere demostrar.
            'perms' => $isadmin ? $this->allPermissions() : array(),
        );
        // Acceso a todos los departamentos menos al primario, que ya lo tiene
        // por serlo (si se repite, `updateAccess()` lo rechaza)
        foreach ($depts as $d) {
            if ($d->getId() == $dept->getId())
                continue;
            $vars['dept_access'][] = $d->getId();
            $vars['dept_access_role'][$d->getId()] = $role;
            $vars['dept_access_alerts'][$d->getId()] = 1;
        }

        return $vars;
    }

/**
     * Da al administrador que ya existía acceso a los departamentos sembrados.
     *
     * Esto no es cosmético: las colas aplican la **visibilidad del agente**
     * (`dept_id IN (sus departamentos) OR asignado OR referido`), y esos
     * departamentos salen de `dept_access`, del departamento principal y de los
     * que gestiona. Un administrador no ve, sólo por serlo, los departamentos
     * que no tiene asignados.
     *
     * El sembrador reparte los tickets por los departamentos que crea, así que
     * sin este paso los tickets existen, los informes los cuentan… y las colas
     * no los listan: justo el síntoma «23 tickets, tabla vacía». Al
     * administrador de la instalación no se le toca la contraseña ni los
     * permisos: sólo se le añaden filas en `staff_dept_access`, y sólo las que
     * faltan.
     */
    function grantAdminDepartments() {
        $admin = $this->adminStaff ?: $this->firstAdmin();
        if (!$admin)
            return;

        $have = array_map('intval', $admin->getDepts());
        $roles = $this->roleIndex();
        $role = (int) $admin->get('role_id') ?: $this->roleFor($roles, true);
        $granted = array();

        $all = array();
        foreach ($this->deptIndex() as $name => $dept)
            $all[$dept->getId()] = $name;

        foreach (self::deptsToGrant($all, $have, (int) $admin->getDeptId()) as $deptId) {
            $exists = StaffDeptAccess::objects()
                ->filter(array('staff_id' => $admin->getId(),
                    'dept_id' => $deptId))
                ->exists();
            if ($exists)
                continue;

            $access = new StaffDeptAccess(array(
                'staff_id' => $admin->getId(),
                'dept_id' => $deptId,
                'role_id' => $role,
            ));
            // Avisos activados, igual que hace el panel al conceder acceso
            $access->setAlerts(true);
            if ($access->save())
                $granted[] = $all[$deptId];
        }

        if ($granted) {
            // No va al manifiesto: no es un dato que se borre con --fresh
            // (los departamentos se llevan por delante sus accesos).
            $this->out(sprintf('  · acceso del administrador %s a: %s',
                $admin->getUserName(), implode(', ', $granted)));
        }
    }

/**
     * Qué departamentos hay que conceder: todos menos los que el agente ya
     * tiene y el suyo principal (que ya da acceso por sí mismo y que
     * `Staff::updateAccess()` rechazaría como duplicado).
     */
    static function deptsToGrant($allDepts, $haveDepts, $primaryDept) {
        $have = array_map('intval', $haveDepts);
        $missing = array();
        foreach (array_keys($allDepts) as $id) {
            $id = (int) $id;
            if (in_array($id, $have) || $id === (int) $primaryDept)
                continue;
            $missing[] = $id;
        }

        return $missing;
    }

/** El primer administrador activo de la instalación, o null. */
    function firstAdmin() {
        foreach (Staff::objects()
                ->filter(array('isadmin' => 1, 'isactive' => 1))
                ->order_by('staff_id') as $staff)
            return $staff;

        return null;
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
            $errors = array();
            if (!$dept->update($vars, $errors)) {
                $this->failed("el departamento " . $name, $errors);
                continue;
            }
            $depts[$name] = $dept;
            $this->created('depts', $dept->getId(), $name);
        }

        // Sin acceso, los tickets de estos departamentos no salen en las colas
        $this->grantAdminDepartments();
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

        foreach (self::teams() as $row) {
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
            $errors = array();
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
            $errors = array();
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
            $errors = array();
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
            $category = $this->categoryByName($categoryName);
            if (!$category) {
                $vars = array(
                    'id' => 0,
                    'name' => $categoryName,
                    'pid' => 0,
                    'ispublic' => Category::VISIBILITY_FEATURED,
                    'description' => sprintf('Artículos sobre %s',
                        self::lower($categoryName)),
                    'notes' => 'Categoría de demostración',
                );
                $category = Category::create();
                $errors = array();
                if (!$category->update($vars, $errors)) {
                    // Si el nombre ya estaba (por ejemplo, un resto de una
                    // ejecución anterior que la purga no vio), se recupera y
                    // se siguen creando sus artículos: perderlos todos por
                    // una categoría duplicada no tiene sentido.
                    $category = $this->categoryByName($categoryName);
                    if (!$category) {
                        $this->failed("la categoría " . $categoryName, $errors);
                        continue;
                    }
                    $this->skip('categoría ' . $categoryName);
                }
                else {
                    $this->createdRef('faq', 'c', $category->getId(),
                        'categoría: ' . $categoryName);
                }
            }
            else {
                $this->skip('categoría ' . $categoryName);
            }

            $moved = 0;
            foreach ($articles as $article) {
                list($question, $answer) = $article;

                // El mismo criterio que usa `FAQ::update()` para rechazar
                // duplicados (`findIdByQuestion`), pero sin su `first()`, que
                // avisa al no encontrar nada.
                $vars = array(
                    'id' => 0,
                    'question' => $question,
                    'answer' => $answer,
                    'category_id' => $category->getId(),
                    'ispublished' => FAQ::VISIBILITY_PUBLIC,
                    'notes' => 'Artículo de demostración',
                    'topics' => array_keys($topics),
                );
                if ($id = $this->faqIdByQuestion($question)) {
                    // Ya existe. Si se quedó sin categoría —o en otra— se
                    // recoloca: un artículo huérfano no se ve en la base de
                    // conocimiento y su categoría es de las que crea este
                    // módulo, así que no se toca nada ajeno.
                    $existing = FAQ::lookup($id);
                    $vars['id'] = $id;
                    $errors = array();
                    if ($existing && $existing->getCategoryId() != $category->getId()
                            && $existing->update($vars, $errors)) {
                        $moved++;
                        $this->createdRef('faq', 'f', $id, $question . ' (recolocado)');
                        continue;
                    }
                    $this->createdRef('faq', 'f', $id, $question);
                    $this->skip($question);
                    continue;
                }

                $faq = FAQ::create();
                $errors = array();
                if (!$faq->update($vars, $errors)) {
                    // El artículo puede haberlo creado otra ejecución entre
                    // la comprobación y el INSERT, o estar con otro formato.
                    if ($this->faqIdByQuestion($question)) {
                        $this->skip($question);
                        continue;
                    }
                    $this->failed("el artículo " . $question, $errors);
                    continue;
                }
                $this->createdRef('faq', 'f', $faq->getId(), $question);
            }

            if ($moved)
                $this->out(sprintf('  · %d artículo(s) de «%s» recolocados a '
                    . 'su categoría (estaban sueltos)', $moved, $categoryName));
        }
    }

/** La categoría de la base de conocimiento con ese nombre, o null. */
    function categoryByName($name) {
        $name = trim((string) $name);
        if (!strlen($name))
            return null;

        // Por el nombre (búsqueda del ORM) y, si no aparece, por id: la
        // comprobación por nombre ya falló una vez con los artículos, así que
        // no se deja el resultado en manos de un solo camino.
        if ($category = Category::lookup(array('name' => $name)))
            return $category;

        if (!function_exists('db_query') || !defined('FAQ_CATEGORY_TABLE'))
            return null;

        $res = db_query(sprintf('SELECT category_id FROM %s WHERE name = %s LIMIT 1',
            FAQ_CATEGORY_TABLE, db_input($name)));
        $row = $res ? db_fetch_row($res) : null;

        return ($row && $row[0]) ? Category::lookup((int) $row[0]) : null;
    }

/**
     * Id del artículo con esa pregunta exacta, o null.
     *
     * Se pregunta a la tabla y no al ORM a propósito: `FAQ::lookup(['question'
     * => …])` devolvía «no existe» para artículos que sí estaban —y que
     * `FAQ::update()` rechazaba después por duplicados, con el consiguiente
     * aviso—, así que la comprobación se hace con la misma columna con la que
     * el modelo decide que hay duplicado.
     */
    function faqIdByQuestion($question) {
        $question = trim((string) $question);
        if (!strlen($question) || !function_exists('db_query'))
            return null;

        $res = db_query(sprintf('SELECT faq_id FROM %s WHERE question = %s LIMIT 1',
            FAQ_TABLE, db_input($question)));
        if (!$res)
            return null;

        $row = db_fetch_row($res);

        return ($row && $row[0]) ? (int) $row[0] : null;
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
        // Los usuarios que se crearon con `users` (o los del plan curado si
        // ese paso no se ejecutó). Se leen de la base para que el correo del
        // ticket sea de un usuario que existe.
        $people = $this->ticketPeople();
        $plan = self::ticketPlan($limit, array_keys($topics),
            array_keys($agents));

        // `--with-tasks`: los tickets que se creen en esta pasada, para
        // colgarles después su tarea de seguimiento.
        $created = array();

        // Estado de cada ticket: se reparten entre todos los estados que
        // tenga la instalación (no sólo «abierto» y «cerrado»), así que las
        // colas, los filtros y los informes tienen algo de cada uno.
        $openStatuses = $this->statusIdsByStates(array('open', 'in progress',
            'answered'));
        $closedStatuses = $this->statusIdsByStates(array('resolved', 'closed'));
        if (!$openStatuses)
            $openStatuses = array((int) $cfg->getDefaultTicketStatusId());

        $phrases = self::conversation();
        $userCount = count($people);

        foreach ($plan as $i => $row) {
            list($subject, $topicName, $age, $closedHint, $messages,
                $agentUsername) = $row;

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

            $person = $people[$i % $userCount];
            // Antigüedad con algo de dispersión para que dos tickets del
            // mismo día no compartan fecha exacta
            $when = $this->daysAgo($age, ($i % 8) * 45);
            $agent = ($agentUsername && isset($agents[$agentUsername]))
                ? $agents[$agentUsername] : null;

            // `Ticket::create()` lee estas claves con `$vars[...]` sin
            // comprobar que existan: en el portal siempre vienen del
            // formulario, aquí hay que ponerlas o el log se llena de avisos.
            $data = array(
                'name' => $person[0],
                'email' => $person[1] . '@' . $this->domain,
                'subject' => $subject,
                'message' => $this->message($subject, $topicName),
                'topicId' => $topics[$topicName],
                'source' => $this->sourceFor($i),
                'ip' => '10.0.' . (1 + intdiv($i, 250)) . '.' . (10 + $i % 240),
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
            // Antigüedad: se corrige después porque create() pone NOW(), y
            // también la del evento «created» del hilo (es lo que lee el
            // Statistics nativo)
            $this->backdate($ticket, $when);
            $this->backdateEvent($ticket, 'created', $when);

            // El hilo: mensajes alternos del agente y del cliente, con una
            // nota interna de vez en cuando (una «conversación» de verdad).
            // Un ticket cerrado no puede tener respuestas posteriores al
            // cierre: la conversación termina el día en que se cierra.
            $closeDay = ($closedHint && $closedStatuses)
                ? max(0, $age - $messages - 1) : 0;

            $this->seedConversation($ticket, $agent, $messages, $age, $i,
                $phrases, $closeDay);

            // Estado final: cerrado de verdad o uno de los estados abiertos
            if ($closedHint && $closedStatuses) {
                $this->closeTicket($ticket, $agent,
                    $this->daysAgo($closeDay),
                    $closedStatuses[$i % count($closedStatuses)]);
            }
            else {
                Ticket::objects()
                    ->filter(array('ticket_id' => $ticket->getId()))
                    ->update(array(
                        'status_id' => $openStatuses[$i % count($openStatuses)],
                        'staff_id' => $agent ? $agent->getId() : 0,
                        'isanswered' => $i % 2,
                    ));
                // Un tercio de los tickets abiertos, vencidos: el SLA ya
                // pasó y la cola de vencidos no queda vacía
                if ($i % 3 == 0) {
                    $overdue = $this->daysAgo(max(0, $age - 1));
                    Ticket::objects()
                        ->filter(array('ticket_id' => $ticket->getId()))
                        ->update(array(
                            'duedate' => $overdue,
                            'isoverdue' => 1,
                        ));
                    // El evento es lo que cuenta el «Overdue» de Statistics
                    $this->logEventAt($ticket, 'overdue', $overdue, $agent);
                }
            }

            $this->created('tickets', $ticket->getId(), $ticket->getNumber());
            $created[] = array('subject' => $subject, 'age' => $age,
                'agent' => $agentUsername);
        }

        if (!($this->stats['tickets']['created'] ?? 0)
                && !($this->stats['tickets']['skipped'] ?? 0))
            $this->warn('no se creó ningún ticket');
        else
            $this->refreshTicketView();

        // Las tareas de seguimiento van después de refrescar la vista: la
        // búsqueda del ticket por asunto pasa por `ticket__cdata`.
        if ($this->withTasks && $created) {
            $this->out(sprintf('  · una tarea de seguimiento por cada uno de '
                . 'los %d tickets nuevos', count($created)));
            $this->seedTasks(null,
                SeedPlan::followUps($created, array_keys($this->deptIndex())));
        }
    }

/**
     * Los usuarios que existen, para repartir los tickets entre ellos.
     *
     * Antes se usaba la lista curada y el correo se inventaba; con
     * `--only=tickets` sobre una instalación donde los usuarios del plan no
     * existen (o con 200 usuarios generados) eso dejaba tickets de gente que
     * no está. Se prefiere la base y, si no hay nadie, se cae a la lista.
     *
     * @return array  filas `array(nombre, correo local)`
     */
    function ticketPeople() {
        $people = array();
        try {
            foreach (User::objects()->limit(500)->all() as $user) {
                $email = (string) $user->getEmail();
                // Sólo los del dominio de demostración: los de una
                // instalación real no son parte del sembrador.
                if (substr($email, -strlen('@' . $this->domain))
                        === '@' . $this->domain)
                    $people[] = array($user->getName(),
                        substr($email, 0, strrpos($email, '@')));
            }
        }
        catch (Throwable $e) {
            $people = array();
        }

        return $people ?: self::people();
    }

/**
     * Reconstruye `ticket__cdata` al terminar de sembrar.
     *
     * Esa tabla no se rellena hacia atrás: la crea `CREATE TABLE … AS SELECT`
     * cuando no existe y, a partir de ahí, sólo se *actualiza* al guardar una
     * respuesta. Un ticket que se crea mientras la vista ya existe puede
     * quedarse fuera, y entonces **no sale en ninguna cola**: las columnas
     * (Subject, entre otras) hacen un JOIN interno contra ella, mientras el
     * recuento —que no usa columnas— lo sigue contando. El síntoma exacto es
     * «23 tickets» y la tabla vacía.
     *
     * Reconstruirla es barato con datos de demostración y deja el dato
     * coherente con `form_entry_values`, que es la fuente de la verdad.
     */
    function refreshTicketView() {
        try {
            DynamicForm::rebuildDynamicDataViews();
        }
        catch (Throwable $e) {
            $this->warn('no se pudo reconstruir la vista de datos de los '
                . 'tickets: ' . $e->getMessage()
                . ' (ejecute `make db-rebuild-views`)');
        }
    }

/**
     * Los mensajes de un ticket: `$count` entradas que van alternando
     * respuesta del agente y mensaje del cliente, con una nota interna cada
     * cuatro. Las fechas se reparten desde la apertura hasta hoy.
     */
    function seedConversation($ticket, $agent, $count, $age, $offset,
            $phrases = null, $minDay = 0) {
        if (!$phrases)
            $phrases = self::conversation();

        $agentLines = $phrases['agent'];
        $clientLines = $phrases['client'];

        for ($r = 1; $r <= $count; $r++) {
            $days = $this->threadDay($age, $count, $r, $minDay);
            $when = $this->daysAgo($days, 20 + $r * 7);

            $lines = ($r % 2) ? $agentLines : $clientLines;
            $lines = array_values($lines);
            $text = sprintf($lines[($offset + $r) % count($lines)],
                $ticket->getNumber());

            if ($r % 4 == 0) {
                // Nota interna: se ve en el panel, no la recibe el cliente
                $this->agentNote($ticket, $agent, $text
                    . "\n\n(Nota interna: seguimiento del caso.)", $when);
            }
            elseif ($r % 2) {
                $this->agentReply($ticket, $agent, $text, $when);
            }
            else {
                $this->clientReply($ticket, $text, $when);
            }
        }
    }

/**
     * Tareas de demostración, abiertas y completadas.
     *
     * `Task::create()` trabaja con la sesión de un agente (`$thisstaff`) y
     * comprueba `Task::PERM_CREATE`: aquí se pone como agente en curso el
     * administrador, que tiene todos los permisos. Las tareas se enganchan a
     * un ticket cuando el plan trae el asunto (`object_type` = `T`) y, si no,
     * quedan sueltas (`object_id` = 0), que es como las crea el panel.
     */
    /**
     * @param int|null $limit  cuántas tareas (del plan curado o generadas)
     * @param array|null $plan filas ya armadas; `--with-tasks` pasa las suyas
     */
    function seedTasks($limit = null, array $plan = null) {
        global $thisstaff;

        // Red de seguridad: si se ejecuta `--only=tasks` sobre una instalación
        // donde nadie ha creado una tarea, la tabla puede no existir. Con
        // `true` se construye en vez de abortar.
        TaskForm::ensureDynamicDataView(true);

        $depts = $this->deptIndex();
        $agents = $this->agentIndex();
        // El administrador con el que se firma: el de la instalación o el que
        // haya creado el paso `agents`
        $admin = $this->adminStaff ?: $this->firstAdmin();
        if (!$admin) {
            $this->warn('no hay ningún agente administrador: ejecute antes el '
                . 'paso de agents');
            return;
        }

        // Permisos y departamento del agente que firma
        $thisstaff = $admin;

        // `Task::create()` devuelve `null` sin explicación cuando el agente no
        // puede crear tareas (un administrador con un rol limitado). Mejor
        // decirlo una vez que fallar dieciséis veces con «sin más datos».
        if (method_exists($admin, 'hasPerm')
                && !$admin->hasPerm(Task::PERM_CREATE, false)) {
            $this->warn(sprintf('el administrador «%s» no tiene permiso para '
                . 'crear tareas (rol sin «Create»): no se puede sembrar el paso '
                . 'tasks con esa cuenta', $admin->getUserName()));
            $thisstaff = null;
            return;
        }

        if ($plan === null)
            $plan = self::taskPlan($limit, array_keys($depts), array_keys($agents));

        $comments = self::conversation();

        foreach ($plan as $i => $row) {
            list($title, $deptName, $age, $done, $dueIn, $agentUsername,
                $description, $ticketSubject) = $row;

            if (!isset($depts[$deptName])) {
                $this->warn("el departamento «{$deptName}» no existe: se salta "
                    . "la tarea «{$title}»");
                continue;
            }
            // Una tarea por título: si ya existe, se salta
            if (Task::objects()
                    ->filter(array('cdata__title__contains' => $title))
                    ->count()) {
                $this->skip($title);
                continue;
            }

            $agent = ($agentUsername && isset($agents[$agentUsername]))
                ? $agents[$agentUsername] : null;

            // Ticket de origen, si el plan lo pide
            $ticket = null;
            if ($ticketSubject) {
                $found = Ticket::objects()
                    ->filter(array('cdata__subject__contains' => $ticketSubject))
                    ->all();
                $ticket = $found ? $found[0] : null;
                // Sin el ticket (por ejemplo con `--only=tasks` sobre una
                // base sin tickets) la tarea sigue siendo válida: se crea
                // suelta y no se cuenta como fallo.
                if (!$ticket)
                    $this->out(sprintf('  · el ticket «%s» no está: la tarea '
                        . '«%s» queda suelta', $ticketSubject, $title));
            }

            $when = $this->daysAgo($age);
            $due = date('Y-m-d H:i', strtotime(sprintf('%+d days 18:00',
                (int) $dueIn)));

            $vars = array(
                'object_id' => $ticket ? $ticket->getId() : 0,
                'object_type' => $ticket
                    ? ObjectModel::OBJECT_TYPE_TICKET
                    : ObjectModel::OBJECT_TYPE_TASK,
                'default_formdata' => array(
                    'title' => $title,
                    'description' => $description,
                ),
                'internal_formdata' => array(
                    'dept_id' => $depts[$deptName]->getId(),
                    'assignee' => $agent,
                    'duedate' => $due,
                ),
                'description' => $description,
                'message' => $description,
                'staffId' => $admin->getId(),
                'poster' => $admin->getName(),
                'ip_address' => '127.0.0.1',
            );

            $errors = array();
            try {
                $task = Task::create($vars);
            }
            catch (Throwable $e) {
                $this->warn("la tarea «{$title}»: " . $e->getMessage());
                continue;
            }
            if (!$task) {
                $this->failed("la tarea «{$title}»", $errors);
                continue;
            }

            $this->backdateTask($task, $when);

            // Un par de notas internas: la tarea también tiene hilo
            if ($agent) {
                // Las frases llevan como mucho un `%s`: el número de tarea
                $this->taskNote($task, $admin,
                    sprintf($comments['agent'][$i % count($comments['agent'])],
                        $task->getNumber()),
                    $this->daysAgo(max(0, $age - 2), 30));
            }

            if ($done) {
                $closing = sprintf(
                    $comments['agent'][($i + 3) % count($comments['agent'])],
                    $task->getNumber());
                $errors = array();
                try {
                    $task->setStatus('closed', $closing, $errors);
                }
                catch (Throwable $e) {
                    $this->warn("no se pudo completar la tarea «{$title}»: "
                        . $e->getMessage());
                }
                if ($errors)
                    $this->warn("no se pudo completar la tarea «{$title}»: "
                        . Format::array_implode(' · ', ' · ', $errors));
                else
                    $this->taskNote($task, $admin, $closing,
                        $this->daysAgo(max(0, $age - 3), 45));
            }

            // Fechas: create() y setStatus() ponen NOW()
            $closed = $done
                ? $this->daysAgo(max(0, $age - 3), 50) : null;
            Task::objects()
                ->filter(array('id' => $task->getId()))
                ->update(array(
                    'created' => $when,
                    'updated' => $closed ?: $when,
                    'duedate' => date('Y-m-d H:i:s', strtotime($due)),
                    'closed' => $closed,
                    'staff_id' => $agent ? $agent->getId() : 0,
                ));

            $this->created('tasks', $task->getId(), $task->getNumber());
        }

        if (!($this->stats['tasks']['created'] ?? 0)
                && !($this->stats['tasks']['skipped'] ?? 0))
            $this->warn('no se creó ninguna tarea');
    }
}
