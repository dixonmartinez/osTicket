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
            'teams', 'topics', 'canned', 'faq', 'tickets'), $steps);

        // Un departamento apunta a su SLA y cada agente a su departamento
        $this->assertLessThan(array_search('depts', $steps), array_search('slas', $steps));
        $this->assertLessThan(array_search('agents', $steps), array_search('depts', $steps));
        $this->assertLessThan(array_search('teams', $steps), array_search('agents', $steps));
        $this->assertLessThan(array_search('topics', $steps), array_search('depts', $steps));
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
        $this->assertCount(3, $orgs);
        foreach ($orgs as $org) {
            $this->assertTrue(strlen($org['name']) > 2, 'nombre de organización');
            $this->assertTrue(strlen($org['domain']) > 3, 'dominio de organización');
        }
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
        $emails = array();
        foreach (SeedManager::people() as $person)
            $emails[] = $person[1] . '@' . SeedManager::DOMAIN;

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
        $clients = array();
        foreach (SeedManager::people() as $person)
            $clients[] = $person[1] . '@' . SeedManager::DOMAIN;

        foreach (SeedManager::agents() as $agent)
            $this->assertFalse(in_array($agent[0] . '@' . SeedManager::DOMAIN, $clients),
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

    public function testHayTemasPublicosYUnTemaPrivado(): void {
        $topics = SeedManager::topics();
        $public = 0;
        foreach ($topics as $topic) {
            if ($topic[2])
                $public++;
        }

        $this->assertTrue($public >= 2, 'hacen falta temas públicos');
        $this->assertSame(1, count($topics) - $public, 'un tema privado');
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

        $this->assertSame(8, $total, 'ocho artículos en la base de conocimiento');
    }

    /* ------------------------------------------------------------------ */
    /* Tickets                                                             */
    /* ------------------------------------------------------------------ */

    public function testTreintaTicketsDeSeisCampos(): void {
        $tickets = $this->tickets();
        $this->assertCount(30, $tickets);
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

    public function testEstadosAntiguedadYRespuestasRazonables(): void {
        foreach ($this->tickets() as $ticket) {
            $subject = $ticket[0];
            $age = $ticket[2];
            $state = $ticket[3];
            $replies = $ticket[4];
            $this->assertTrue(in_array($state, array('Abierto', 'Cerrado'), true),
                "estado del ticket «{$subject}»");
            $this->assertTrue($age >= 0 && $age <= 60, "antigüedad de «{$subject}»");
            $this->assertTrue($replies >= 0 && $replies <= 5, "respuestas de «{$subject}»");
        }
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
            if ($ticket[3] == 'Abierto')
                $open++;
            else
                $closed++;
            if ($ticket[5] === null)
                $unassigned++;
        }

        $this->assertTrue($open >= 10, 'tickets abiertos');
        $this->assertTrue($closed >= 10, 'tickets cerrados');
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

    public function testElManifiestoNoSeVersiona(): void {
        // `--fresh` borra por número de identificador: si el manifiesto se
        // versionara, se borrarían registros de otra instalación.
        $gitignore = file_get_contents(ROOT_DIR . '/.gitignore');
        $this->assertStringContainsString('seed-manifest.json', $gitignore);
    }
}
