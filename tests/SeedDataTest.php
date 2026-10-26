<?php
/**
 * tests/SeedDataTest.php
 *
 * Pruebas de los datos del sembrador de demostración
 * (`include/cli/modules/seed.php`, ver `docs/SEED.md`).
 *
 * Lo que se crea de verdad —organizaciones, agentes, temas, tickets— necesita
 * base de datos y no se prueba aquí. Lo que sí se prueba es la **coherencia de
 * los datos**: que cada ticket apunte a un tema que existe, que cada agente
 * esté en un departamento que se crea, que los SLA citados sean los que se
 * crean, que no haya asuntos ni correos repetidos y que el orden de los pasos
 * respete las dependencias (un departamento hijo después de su padre).
 *
 * Un error de este tipo no se ve al escribir el archivo: se ve al ejecutar
 * `make seed` contra la base, a mitad de la carga, con la mitad de los datos
 * ya metidos. Estas pruebas lo detectan sin base de datos.
 *
 * Para cargar el módulo hace falta la clase `Module` de la CLI. Se usa la de
 * verdad si ya está cargada; si no, una mínima con sólo `register()`, que es
 * lo único que el módulo usa a nivel de archivo.
 */

if (!class_exists('Module')) {
    class Module {
        static $reg = array();
        static function register($action, $class) {
            self::$reg[$action] = $class;
        }
    }
}

require_once INCLUDE_DIR . 'cli/modules/seed.php';

class SeedDataTest extends UnitTestCase {

    /** Los datos de un paso, como pares [clave => valor] normalizados. */
    private function tickets(): array {
        return SeedManager::tickets();
    }

    /** Nombres de los temas que crea el paso `topics`. */
    private function topicNames(): array {
        $names = array();
        foreach (SeedManager::topics() as $t)
            $names[$t[0]] = true;

        return $names;
    }

    private function deptNames(): array {
        $names = array();
        foreach (SeedManager::departments() as $d)
            $names[$d[0]] = true;

        return $names;
    }

    private function agentNames(): array {
        $names = array();
        foreach (SeedManager::agents() as $a)
            $names[$a[0]] = true;

        return $names;
    }

    /* ------------------------------------------------------------------ */
    /* El módulo y sus pasos                                               */
    /* ------------------------------------------------------------------ */

    public function testSeRegistraComoModulo(): void {
        $this->assertSame('SeedManager', Module::$reg['seed']);
    }

    public function testLosPasosVanEnOrdenDeDependencia(): void {
        // Los departamentos antes que los temas y los equipos; las
        // organizaciones antes que los usuarios; los tickets al final.
        $steps = SeedManager::steps();
        $this->assertSame(array('orgs', 'users', 'slas', 'depts', 'agents',
            'teams', 'topics', 'canned', 'faq', 'tickets', 'tasks'), $steps);

        // Un departamento apunta a su SLA y cada agente a su departamento
        $this->assertLessThan(array_search('depts', $steps), array_search('slas', $steps));
        $this->assertLessThan(array_search('agents', $steps), array_search('depts', $steps));
        $this->assertLessThan(array_search('teams', $steps), array_search('agents', $steps));
        $this->assertLessThan(array_search('topics', $steps), array_search('depts', $steps));
        // Las tareas se enganchan a tickets: van las últimas
        $this->assertLessThan(array_search('tasks', $steps), array_search('tickets', $steps));
    }

    public function testCadaPaqueteQueSeUsaEstaDefinido(): void {
        // `self::teams()` se llamaba desde la purga sin existir —los equipos
        // eran un array dentro de `seedTeams()`— y `make seed-fresh` moría con
        // un `Error` fatal antes de borrar nada. Aquí se recorren todas las
        // llamadas `self::x()` del módulo.
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        preg_match_all('/self::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $found);
        $names = array_values(array_unique($found[1]));

        $this->assertTrue(count($names) > 5, 'no se encontraron llamadas self::');
        foreach ($names as $name)
            $this->assertTrue(method_exists('SeedManager', $name),
                "SeedManager::$name() no existe");
    }

    public function testCadaMetodoPropioQueSeUsaEstaDefinido(): void {
        // Un `$this->metodo()` que no existe es un `Error` fatal en tiempo de
        // ejecución, y sólo se ve al correr el comando contra la base. Aquí se
        // comparan todas las llamadas con lo que declara la clase.
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $found);
        $names = array_values(array_unique($found[1]));

        $this->assertTrue(count($names) > 20, 'no se encontraron llamadas $this->');
        foreach ($names as $name)
            $this->assertTrue(method_exists('SeedManager', $name)
                || method_exists('Module', $name),
                "SeedManager::$name() no existe");
    }

    public function testCadaPasoTieneSuMetodo(): void {
        foreach (SeedManager::steps() as $step)
            $this->assertTrue(method_exists('SeedManager', 'seed' . ucfirst($step)),
                "falta el método del paso $step");
    }

    /* ------------------------------------------------------------------ */
    /* Organizaciones y personas                                           */
    /* ------------------------------------------------------------------ */

    public function testOrganizacionesConNombreYDominio(): void {
        $orgs = SeedManager::organizations();
        $seed = new SeedManager();
        $this->assertCount(3, $orgs);
        foreach ($orgs as $org) {
            $this->assertTrue(strlen($org['name']) > 2, 'nombre de organización');
            // El dominio se compone con el dominio en uso: `slug` +
            // `--domain`, para que no queden dominios de otra ejecución
            $this->assertTrue(strlen($org['slug']) > 2, 'dominio de organización');
            $this->assertStringContainsString('.', $org['slug'] . '.' . $seed->domain);
        }
    }

    public function testElDominioDeDemostracionResuelvePorDNS(): void {
        // `Staff::update()` valida el correo con DNS cuando «Verify email
        // addresses» está activo, que es el valor por omisión. Un TLD
        // reservado pero no resoluble (`demo.invalid`) hace imposible crear
        // agentes: el alta falla con «Valid email is required».
        $seed = new SeedManager();
        $this->assertSame('example.com', SeedManager::DOMAIN);
        $this->assertSame('example.com', $seed->domain);
        $this->assertFalse(strpos($seed->domain, '.invalid'),
            'el dominio por omisión no puede ser un TLD que no resuelve');
    }

    public function testLaOpcionDomainCambiaLosCorreos(): void {
        $seed = new SeedManager();
        $opciones = array();
        foreach ($seed->options as $name => $opt)
            $opciones[$name] = $opt[0] . $opt[1];

        $this->assertArrayHasKey('domain', $opciones);
        $this->assertSame('--domain', $opciones['domain']);
    }

    public function testLasPersonasApuntanAUnaOrganizacion(): void {
        $orgs = SeedManager::organizations();
        foreach (SeedManager::people() as $person) {
            $this->assertTrue(strlen($person[1]) > 2, 'correo local de la persona');
            $this->assertTrue($person[2] === null || isset($orgs[$person[2]]),
                'organización de ' . $person[0]);
        }
    }

    public function testCorreosDeClienteSinRepetir(): void {
        $seed = new SeedManager();
        $emails = array();
        foreach (SeedManager::people() as $person)
            $emails[] = $person[1] . '@' . $seed->domain;

        $this->assertSame(count($emails), count(array_unique($emails)));
    }

    /* ------------------------------------------------------------------ */
    /* Agentes, departamentos y equipos                                    */
    /* ------------------------------------------------------------------ */

    public function testCadaAgenteEnUnDepartamentoQueSeCrea(): void {
        $depts = $this->deptNames();
        foreach (SeedManager::agents() as $agent)
            $this->assertTrue(isset($depts[$agent[3]]),
                "el departamento {$agent[3]} de {$agent[0]} no se crea");
    }

    public function testHayUnSoloAdministradorYUsuariosDistintos(): void {
        $admins = 0;
        $usernames = array();
        foreach (SeedManager::agents() as $agent) {
            if ($agent[4])
                $admins++;
            $usernames[] = $agent[0];
        }

        $this->assertSame(1, $admins, 'un solo administrador');
        $this->assertSame(count($usernames), count(array_unique($usernames)));
    }

    public function testLosAgentesNoChocanConLosCorreosDeLosClientes(): void {
        $seed = new SeedManager();
        $clients = array();
        foreach (SeedManager::people() as $person)
            $clients[] = $person[1] . '@' . $seed->domain;

        foreach (SeedManager::agents() as $agent)
            $this->assertFalse(in_array($agent[0] . '@' . $seed->domain, $clients),
                'el agente ' . $agent[0] . ' usa el correo de un cliente');
    }

    public function testLosDepartamentosPadreSeCreanAntesQueLosHijos(): void {
        $seen = array();
        foreach (SeedManager::departments() as $dept) {
            if ($dept[1] !== null)
                $this->assertTrue(isset($seen[$dept[1]]),
                    "el padre {$dept[1]} de {$dept[0]} se crea después");
            $seen[$dept[0]] = true;
        }
    }

    public function testLosDepartamentosApuntanASlasQueSeCrean(): void {
        $slas = array();
        foreach (SeedManager::slas() as $sla)
            $slas[$sla[0]] = $sla[1];

        foreach (SeedManager::departments() as $dept) {
            if ($dept[3] === null)
                continue;
            $this->assertArrayHasKey($dept[3], $slas, 'SLA del departamento ' . $dept[0]);
            $this->assertTrue($slas[$dept[3]] > 0, 'horas de gracia del SLA');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Temas                                                               */
    /* ------------------------------------------------------------------ */

    public function testCadaTemaConDepartamentoYPrioridadConocidos(): void {
        $depts = $this->deptNames();
        $slas = array();
        foreach (SeedManager::slas() as $sla)
            $slas[$sla[0]] = true;

        foreach (SeedManager::topics() as $topic) {
            list($name, $dept, $public, $sla, $priority) = $topic;
            $this->assertTrue(isset($depts[$dept]), "departamento del tema $name");
            $this->assertTrue($sla === null || isset($slas[$sla]), "SLA del tema $name");
            // Las claves de `priority.yaml`: low, normal, high, emergency
            $this->assertTrue(in_array($priority,
                array('low', 'normal', 'high', 'emergency'), true),
                "prioridad del tema $name: $priority");
            $this->assertTrue(strlen($name) >= 5, 'un tema necesita 5 caracteres');
        }
    }

    public function testHayTemasPublicosYAlgunoPrivado(): void {
        $topics = SeedManager::topics();
        $public = 0;
        foreach ($topics as $topic) {
            if ($topic[2])
                $public++;
        }

        $this->assertTrue($public >= 4, 'hacen falta temas públicos');
        $this->assertTrue(count($topics) - $public >= 1,
            'al menos un tema privado (sólo agentes)');
    }

    /* ------------------------------------------------------------------ */
    /* Respuestas y base de conocimiento                                   */
    /* ------------------------------------------------------------------ */

    public function testRespuestasConTituloYTexto(): void {
        $titles = array();
        foreach (SeedManager::canned() as $canned) {
            list($title, $response) = $canned;
            // `Canned::update()` exige 3 caracteres y texto de respuesta
            $this->assertTrue(strlen($title) >= 3, 'título de respuesta');
            $this->assertTrue(strlen(trim($response)) > 20, 'texto de ' . $title);
            $titles[] = $title;
        }

        $this->assertSame(count($titles), count(array_unique($titles)));
    }

    public function testArticulosConCategoriaDeTresLetras(): void {
        $total = 0;
        foreach (SeedManager::faqs() as $category => $articles) {
            // `Category::update()` exige 3 caracteres y descripción
            $this->assertTrue(strlen($category) >= 3, 'nombre de categoría');
            $this->assertTrue(count($articles) > 0, "sin artículos en $category");
            foreach ($articles as $article) {
                list($question, $answer) = $article;
                $this->assertTrue(strlen($question) > 10, 'pregunta del artículo');
                $this->assertTrue(strlen(trim($answer)) > 40, 'respuesta del artículo');
                $total++;
            }
        }

        // La base de conocimiento tiene que dar juego en el portal: varias
        // categorías y bastantes artículos, no una muestra de dos.
        $this->assertTrue(count(SeedManager::faqs()) >= 5, 'categorías de conocimiento');
        $this->assertTrue($total >= 25, 'artículos de conocimiento');
    }

    public function testLasPreguntasDelConocimientoNoSeRepiten(): void {
        $questions = array();
        foreach (SeedManager::faqs() as $articles)
            foreach ($articles as $article)
                $questions[] = $article[0];

        $this->assertSame(count($questions), count(array_unique($questions)));
    }

    /* ------------------------------------------------------------------ */
    /* Tickets                                                             */
    /* ------------------------------------------------------------------ */

    public function testTicketsDeSeisCampos(): void {
        $tickets = $this->tickets();
        // El conjunto se ha ampliado para que las colas tengan volumen
        $this->assertTrue(count($tickets) >= 50, 'tickets de demostración');
        foreach ($tickets as $ticket)
            $this->assertCount(6, $ticket);
    }

    public function testCadaTicketApuntaAUnTemaQueSeCrea(): void {
        $topics = $this->topicNames();
        foreach ($this->tickets() as $ticket)
            $this->assertTrue(isset($topics[$ticket[1]]),
                "el tema {$ticket[1]} del ticket «{$ticket[0]}» no se crea");
    }

    public function testCadaTicketAsignadoATUnAgenteQueExiste(): void {
        $agents = $this->agentNames();
        foreach ($this->tickets() as $ticket)
            $this->assertTrue($ticket[5] === null || isset($agents[$ticket[5]]),
                "el agente {$ticket[5]} del ticket «{$ticket[0]}» no se crea");
    }

    public function testAntiguedadYMensajesRazonables(): void {
        $long = 0;
        foreach ($this->tickets() as $ticket) {
            $subject = $ticket[0];
            $age = $ticket[2];
            $closed = $ticket[3];
            $messages = $ticket[4];
            // El estado final lo reparte `seedTickets()` entre los estados
            // reales de la instalación; aquí sólo se marca si va cerrado.
            $this->assertTrue(is_bool($closed),
                "la pista de estado de «{$subject}» no es booleana");
            $this->assertTrue($age >= 0 && $age <= 60, "antigüedad de «{$subject}»");
            $this->assertTrue($messages >= 0 && $messages <= 14,
                "mensajes de «{$subject}»");
            if ($messages >= 6)
                $long++;
        }

        // «Un amplio chat de respuestas»: la mayoría de los tickets tienen
        // un hilo largo, no dos mensajes
        $this->assertTrue($long >= 20, 'tickets con hilo largo');
    }

    public function testAsuntosSinRepetir(): void {
        $subjects = array();
        foreach ($this->tickets() as $ticket)
            $subjects[] = $ticket[0];

        $this->assertSame(count($subjects), count(array_unique($subjects)));
    }

    public function testHayMezclaDeEstadosYAsignaciones(): void {
        $open = $closed = $unassigned = 0;
        foreach ($this->tickets() as $ticket) {
            if ($ticket[3])
                $closed++;
            else
                $open++;
            if ($ticket[5] === null)
                $unassigned++;
        }

        $this->assertTrue($open >= 20, 'tickets abiertos');
        $this->assertTrue($closed >= 15, 'tickets cerrados');
        $this->assertTrue($unassigned >= 3, 'tickets sin asignar');
    }

    public function testAntiguedadRepartidaEnMasDeUnMes(): void {
        $ages = array();
        foreach ($this->tickets() as $ticket)
            $ages[] = $ticket[2];

        // Las colas y las analíticas necesitan un rango amplio, no todo «hoy»
        $this->assertTrue(max($ages) - min($ages) > 20);
        $this->assertSame(0, min($ages), 'al menos un ticket de hoy');
    }

    /* ------------------------------------------------------------------ */
    /* Conversación y tareas                                               */
    /* ------------------------------------------------------------------ */

    public function testLaConversacionTieneFrasesDeLosDosLados(): void {
        $phrases = SeedManager::conversation();
        $this->assertTrue(count($phrases['agent']) >= 8, 'frases del agente');
        $this->assertTrue(count($phrases['client']) >= 8, 'frases del cliente');

        foreach ($phrases as $side => $lines)
            foreach ($lines as $line)
                $this->assertTrue(strlen(trim($line)) > 20,
                    "frase demasiado corta en {$side}");

        // `sprintf()` sobre estas frases no puede fallar: como mucho llevan
        // un `%s` (el número de ticket)
        foreach (array_merge($phrases['agent'], $phrases['client']) as $line)
            $this->assertLessThan(2, substr_count($line, '%s'),
                'una frase lleva más de un comodín');
    }

    public function testCadaTareaApuntaADatosQueSeCrean(): void {
        $depts = $this->deptNames();
        $agents = $this->agentNames();
        $subjects = array();
        foreach ($this->tickets() as $ticket)
            $subjects[$ticket[0]] = true;

        foreach (SeedManager::tasks() as $task) {
            list($title, $dept, $age, $done, $dueIn, $agent, $description,
                $ticketSubject) = $task;
            $this->assertTrue(strlen($title) > 10, 'título de tarea');
            $this->assertTrue(isset($depts[$dept]),
                "el departamento {$dept} de la tarea «{$title}» no se crea");
            $this->assertTrue($agent === null || isset($agents[$agent]),
                "el agente {$agent} de la tarea «{$title}» no se crea");
            $this->assertTrue(is_bool($done), 'la tarea debe decir si está completada');
            $this->assertTrue($age >= 0, "antigüedad de la tarea «{$title}»");
            $this->assertTrue(strlen($description) > 30,
                "descripción de la tarea «{$title}»");
            $this->assertTrue($ticketSubject === null
                    || isset($subjects[$ticketSubject]),
                "el ticket «{$ticketSubject}» de la tarea «{$title}» no se crea");
        }
    }

    public function testHayTareasAbiertasYCompletadas(): void {
        $open = $closed = $overdue = $loose = 0;
        $titles = array();
        foreach (SeedManager::tasks() as $task) {
            $task[3] ? $closed++ : $open++;
            if (!$task[3] && $task[4] < 0)
                $overdue++;
            if ($task[7] === null)
                $loose++;
            $titles[] = $task[0];
        }

        // osTicket sólo distingue estos dos estados de tarea
        $this->assertTrue($open >= 5, 'tareas abiertas');
        $this->assertTrue($closed >= 5, 'tareas completadas');
        $this->assertTrue($overdue >= 1, 'alguna tarea vencida y sin cerrar');
        $this->assertTrue($loose >= 1, 'alguna tarea suelta, sin ticket');
        $this->assertSame(count($titles), count(array_unique($titles)));
    }

    /* ------------------------------------------------------------------ */
    /* Fechas y manifiesto                                                 */
    /* ------------------------------------------------------------------ */

    public function testDaysAgoDevuelveUnaFechaSqlEnElPasado(): void {
        $seed = new SeedManager();

        $date = $seed->daysAgo(5);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date);
        $this->assertTrue(strtotime($date) < time(), 'la fecha está en el pasado');
        $this->assertSame(date('Y-m-d'), substr($seed->daysAgo(0), 0, 10),
            'cero días es hoy');
    }

    public function testDaysAgoConDesplazamientoNoCambiaElDia(): void {
        $seed = new SeedManager();

        // El desplazamiento (minutos) sirve para que dos entradas del mismo
        // día no compartan marca de tiempo, no para mover el día
        $this->assertSame(date('Y-m-d'), substr($seed->daysAgo(0, 90), 0, 10));
        $this->assertSame(substr($seed->daysAgo(4, 0), 0, 10),
            substr($seed->daysAgo(4, 25), 0, 10));
        $this->assertTrue(strtotime($seed->daysAgo(4, 25))
            > strtotime($seed->daysAgo(4, 0)));
    }

    public function testElOrigenDelTicketEsDeLosQueAdmiteOSTicket(): void {
        $seed = new SeedManager();
        // `Ticket::create()` rechaza un origen que no esté en la lista
        $valid = array('Web', 'Email', 'Phone', 'API', 'Other');
        $seen = array();
        for ($i = 0; $i < 12; $i++) {
            $source = $seed->sourceFor($i);
            $this->assertTrue(in_array($source, $valid, true),
                "origen no válido: {$source}");
            $seen[$source] = true;
        }

        $this->assertTrue(count($seen) >= 4, 'orígenes variados');
    }

    public function testElMensajeDeAperturaCitaElAsuntoYElTema(): void {
        $seed = new SeedManager();
        $text = $seed->message('El informe mensual sale vacío', 'Problema técnico');

        $this->assertStringContainsString('el informe mensual sale vacío', $text);
        $this->assertStringContainsString('Problema técnico', $text);
    }

    public function testLasAyudasNuevasTienenLaFirmaQueUsaElSembrador(): void {
        // `closeTicket()` pasa el estado elegido; `seedTasks()` necesita un
        // hilo propio y una nota interna. Si cambian de firma, aquí se ve.
        $seed = new SeedManager();
        $this->assertTrue(method_exists($seed, 'seedConversation'));
        $this->assertTrue(method_exists($seed, 'seedTasks'));
        $this->assertSame(4, (new ReflectionMethod($seed, 'closeTicket'))
            ->getNumberOfParameters());
        $this->assertSame(4, (new ReflectionMethod($seed, 'agentNote'))
            ->getNumberOfParameters());
        $this->assertSame(4, (new ReflectionMethod($seed, 'taskNote'))
            ->getNumberOfParameters());
        $this->assertSame(2, (new ReflectionMethod($seed, 'daysAgo'))
            ->getNumberOfParameters());
        $this->assertSame(4, (new ReflectionMethod($seed, 'threadDay'))
            ->getNumberOfParameters());
        $this->assertSame(1, (new ReflectionMethod($seed, 'sourceFor'))
            ->getNumberOfParameters());
    }

    /* ------------------------------------------------------------------ */
    /* Purga: a quién corresponde cada entrada del manifiesto              */
    /* ------------------------------------------------------------------ */

    public function testLaReferenciaDeConocimientoDistingueCategoriaDeArticulo(): void {
        // Mismo número, dos tablas: sin el tipo se borraba el registro
        // equivocado y `--fresh` dejaba categorías sueltas.
        $this->assertSame(array('type' => 'category', 'id' => 3),
            SeedManager::faqRef('c3'));
        $this->assertSame(array('type' => 'article', 'id' => 3),
            SeedManager::faqRef('f3'));
    }

    public function testLaReferenciaAdmiteManifiestosAntiguos(): void {
        // Antes se guardaba sólo el id: `null` significa «no sé qué es»
        $this->assertNull(SeedManager::faqRef(12));
        $this->assertNull(SeedManager::faqRef('12'));
    }

    public function testLaReferenciaRechazaLoQueNoReconoce(): void {
        foreach (array('', 'c', 'x7', 'c7f', 'c-1', null) as $ref)
            $this->assertNull(SeedManager::faqRef($ref),
                'no debería reconocer «' . var_export($ref, true) . '»');
    }

    /* ------------------------------------------------------------------ */
    /* El administrador de la instalación                                  */
    /* ------------------------------------------------------------------ */

    public function testElSembradorNoCreaUnSegundoAdministrador(): void {
        // Quien ya instaló osTicket tiene su propio administrador. El paso
        // `agents` sólo debe crear el de demostración cuando no hay ninguno,
        // y para saberlo hay que preguntar por `isadmin`, no por el nombre de
        // usuario: el instalador deja elegir cualquiera.
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');

        $this->assertStringContainsString('function firstAdmin()', $source);
        $this->assertStringContainsString("'isadmin' => 1", $source);
        // El administrador de demostración se salta si ya hay uno en uso
        $this->assertStringContainsString('if ($isadmin && $this->adminStaff)',
            $source);
        // Y lo que necesita sesión de agente se firma con él
        $this->assertStringContainsString('$this->adminStaff ?: $this->firstAdmin()',
            $source);
    }

    public function testHayUnSoloAdministradorEnLosDatos(): void {
        $admins = 0;
        foreach (SeedManager::agents() as $agent)
            if ($agent[4])
                $admins++;

        $this->assertSame(1, $admins);
    }

    public function testElAdministradorEnUsoSeEligeAlEmpezar(): void {
        // `--only=tasks` no pasa por el paso `agents`: el administrador tiene
        // que quedar resuelto antes de los pasos, no dentro de uno.
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        $start = strpos($source, '$this->loadManifest();');
        $set = strpos($source, '$this->adminStaff = $this->firstAdmin();');

        $this->assertTrue($set !== false, 'falta resolver el administrador');
        $this->assertTrue($set > $start,
            'el administrador debe resolverse al cargar el manifiesto');
    }

    /* ------------------------------------------------------------------ */
    /* La purga de `--fresh`                                               */
    /* ------------------------------------------------------------------ */

    public function testLaPurgaTieneSegundaPasadaPorNombre(): void {
        // El manifiesto sólo sirve si la ejecución anterior terminó: cuando un
        // borrado fallaba (los equipos necesitaban sesión de agente), los ids
        // desaparecían y `--fresh` decía «ya existía» para siempre.
        $this->assertTrue(method_exists('SeedManager', 'purgeByName'));
        $this->assertTrue(method_exists('SeedManager', 'purgeAll'));

        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        $this->assertStringContainsString('$loose = $this->purgeByName();', $source);
    }

    public function testLaPurgaPorNombreSoloTocaLoInequivocamentePropio(): void {
        // El conocimiento y los equipos tienen nombres que sólo existen en
        // este módulo. Departamentos, SLA, organizaciones, usuarios, tickets y
        // tareas **no** se borran por nombre: si alguien tiene un
        // departamento «Soporte» propio, borrarlo por parecerse sería perder
        // su configuración. Esos van sólo por identificador.
        $method = new ReflectionMethod('SeedManager', 'purgeByName');
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        $lines = explode("\n", $source);
        $body = implode("\n", array_slice($lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1));

        foreach (array('FAQ', 'Category', 'Team') as $class)
            $this->assertStringContainsString($class . '::objects()', $body,
                "la purga por nombre no borra $class");

        foreach (array('Ticket', 'Task', 'Canned', 'Topic', 'Staff', 'Dept',
                'SLA', 'User', 'Organization') as $class)
            $this->assertTrue(strpos($body, $class . '::objects()') === false,
                "$class no debe borrarse por nombre");
    }

    public function testElManifiestoSeApuntaAlCrearCadaRegistro(): void {
        // Si la ejecución se corta a mitad, lo creado hasta ahí tiene que
        // poder borrarse: el manifiesto se escribe registro a registro, no al
        // final del comando.
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        $init = strpos($source, 'function __construct');
        $created = strpos($source, 'function created(');
        $end = strpos($source, 'function counted(', $created);
        $body = substr($source, $created, $end - $created);

        $this->assertStringContainsString('$this->saveManifest();', $body);
    }

    public function testLaPurgaRespetaAlAdministradorDeLaCasa(): void {
        // Si la instalación nació con la cuenta de demostración (`admin`, con
        // correo en el dominio de prueba), el manifiesto la conoce y `--fresh`
        // la borraría: el panel se quedaría sin acceso. Se excluye por id.
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        $this->assertStringContainsString(
            "if (\$this->adminStaff\n                                && \$this->adminStaff->getId() == \$id)",
            $source);
    }

    public function testLaExistenciaDeUnArticuloNoDependeDelORM(): void {
        // La comprobación anterior (`FAQ::lookup`) decía «no existe» y luego
        // `FAQ::update()` rechazaba la pregunta por duplicada: 8 artículos
        // quedaban sin crear con un aviso. Se pregunta por el id directamente.
        $this->assertTrue(method_exists('SeedManager', 'faqIdByQuestion'));
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        $this->assertStringContainsString('FROM %s WHERE question = %s LIMIT 1',
            $source);
        $this->assertStringContainsString('FAQ_TABLE, db_input($question)', $source);
        $this->assertStringContainsString('$this->faqIdByQuestion($question)', $source);
    }

    public function testCadaActualizacionEmpiezaSinErrores(): void {
        // `$errors` es acumulativo: reutilizarlo entre registros hacía que el
        // aviso de un fallo anterior se pegara al siguiente registro creado.
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/seed.php');
        $creates = preg_match_all(
            '/\$(staff|dept|team|sla|topic|category|faq) = \w+::create\(\);\s*\n'
            . '\s*\$errors = array\(\);/',
            $source);

        $this->assertSame(7, $creates,
            'cada `create()` debe ir seguido de `$errors = array()`');
    }

    /* ------------------------------------------------------------------ */
    /* Fechas del hilo                                                     */
    /* ------------------------------------------------------------------ */

    public function testElPrimerMensajeVaJustoDespuesDeAbrirElTicket(): void {
        $seed = new SeedManager();
        // Ticket abierto hace 10 días: la conversación empieza al día
        // siguiente, no antes (una respuesta anterior a la apertura da
        // tiempos de respuesta negativos en los informes).
        $this->assertSame(9, $seed->threadDay(10, 4, 1));
        $this->assertSame(9, $seed->threadDay(10, 1, 1));
    }

    public function testUnTicketCerradoNoTieneMensajesDespuesDelCierre(): void {
        $seed = new SeedManager();
        // Abierto hace 20 días, cerrado hace 15 (minDay = 15): el último
        // mensaje cae el día del cierre, nunca después.
        $this->assertSame(15, $seed->threadDay(20, 5, 5, 15));
        $this->assertSame(19, $seed->threadDay(20, 5, 1, 15));
    }

    public function testElRepartoDeDiasEsMonotonoYAjustado(): void {
        $seed = new SeedManager();
        $previous = PHP_INT_MAX;

        for ($r = 1; $r <= 8; $r++) {
            $day = $seed->threadDay(30, 8, $r, 10);
            $this->assertLessThan($previous + 1, $day,
                'los mensajes tienen que ir del más viejo al más nuevo');
            $this->assertLessThan(30, $day, 'ningún mensaje antes de la apertura');
            $this->assertGreaterThan(9, $day, 'ningún mensaje después del cierre');
            $previous = $day;
        }
    }

    public function testElRepartoAguantaDatosRaros(): void {
        $seed = new SeedManager();
        // Un mensaje, edad cero, cierre «después» de la apertura o índice
        // fuera de rango: el sembrador nunca debe devolver un día negativo.
        foreach (array(array(0, 3, 1, 0), array(5, 3, 9, 0), array(2, 4, 1, 30))
                as $case)
            $this->assertGreaterThan(-1, $seed->threadDay($case[0], $case[1],
                $case[2], $case[3]));
    }

    public function testElManifiestoNoSeVersiona(): void {
        // `--fresh` borra por número de identificador: si el manifiesto se
        // versionara, se borrarían registros de otra instalación.
        $gitignore = file_get_contents(ROOT_DIR . '/.gitignore');
        $this->assertStringContainsString('seed-manifest.json', $gitignore);
    }
}
