<?php
/**
 * tests/SeedPurgeTest.php
 *
 * Ejecuta `SeedManager::purge()` —el corazón de `make seed-fresh`— con modelos
 * simulados, sin base de datos.
 *
 * Por qué existe: la purga es el único camino del sembrador que **borra**, y
 * hasta ahora no lo ejecutaba ninguna prueba. Dos fallos reales salieron de
 * ahí, y ninguno se veía hasta correr el comando contra MySQL:
 *
 *   1. `Call to undefined method SeedManager::teams()` — los equipos eran un
 *      array dentro de `seedTeams()` y la purga por nombre los pedía como
 *      paquete de datos: `--fresh` moría antes de borrar nada;
 *   2. registros que quedaban sin borrar porque se recorría el resultado de la
 *      consulta mientras se borraban filas de la misma tabla.
 *
 * Lo que se comprueba aquí es el **recorrido**: que el manifiesto se lea, que
 * cada paso borre por su modelo, que la segunda pasada (por nombre) alcance a
 * los restos, que el administrador en uso sobreviva y que un manifiesto
 * perdido no impida limpiar.
 *
 * Los modelos son de mentira a propósito: `FAQ`, `Team`, `Ticket`… no se cargan
 * en las pruebas (arrastran la base de datos y media aplicación).
 */

if (!class_exists('Module')) {
    class Module {
        static $reg = array();
        static function register($action, $class) {
            self::$reg[$action] = $class;
        }
    }
}

/** Colector de salida: guarda lo escrito para poder mirarlo. */
class SeedPurgeStream {
    public $lines = array();
    function write($text) {
        $this->lines[] = rtrim($text, "\n");
        return strlen($text);
    }
    function flush() {}
    function text() { return implode("\n", $this->lines); }
}

/** Resultado de `AlgúnModelo::objects()`: filtra, ordena y se recorre. */
class SeedPurgeSet implements IteratorAggregate {
    private $rows;

    function __construct(array $rows) {
        $this->rows = array_values($rows);
    }

    /** Sólo lo que usa la purga: `campo__operador => valor`. */
    function filter($filter) {
        if (!is_array($filter))
            return $this;

        $rows = $this->rows;
        foreach ($filter as $key => $value) {
            $parts = explode('__', $key);
            $field = $parts[0];
            if (!is_array($value))
                continue;
            $rows = array_values(array_filter($rows, function ($model)
                    use ($field, $value) {
                return in_array($model->getField($field), $value);
            }));
        }

        return new self($rows);
    }

    function order_by($order) { return $this; }
    function limit($limit) { return $this; }
    function all() { return $this->rows; }
    function count() { return count($this->rows); }
    function getIterator(): Traversable { return new ArrayIterator($this->rows); }
}

/** Modelo simulado: lo mínimo que toca la purga. */
abstract class SeedPurgeModel {
    /** Filas de cada modelo, por nombre de clase. */
    static $rows = array();
    /** Ids borrados, para poder afirmarlos en las pruebas. */
    static $deleted = array();

    public $id = 0;
    public $name = '';
    public $question = '';

    function __construct($id = 0, $name = '') {
        $this->id = (int) $id;
        $this->name = $name;
        $this->question = $name;
    }

    static function objects() {
        return new SeedPurgeSet(self::$rows[static::class] ?? array());
    }

    static function lookup($id) {
        foreach (self::$rows[static::class] ?? array() as $model)
            if ($model->getId() == $id)
                return $model;

        return null;
    }

    static function create() {
        $id = count(self::$rows[static::class] ?? array()) + 1;
        $model = new static($id);
        self::$rows[static::class][] = $model;

        return $model;
    }

    function getField($field) {
        if ($field == 'question')
            return $this->question;

        return $this->name;
    }

    function getId() { return $this->id; }
    function getName() { return $this->name; }
    function getUserName() { return $this->name; }

    function delete() {
        self::$deleted[static::class][] = $this->id;
        $keep = array();
        foreach (self::$rows[static::class] as $model)
            if ($model->getId() != $this->id)
                $keep[] = $model;
        self::$rows[static::class] = $keep;

        return true;
    }
}

/* Los modelos que recorre la purga. Nombres reales porque el módulo los usa
   tal cual (`FAQ::objects()`, `Team::lookup()`…).

   Falta `Ticket` a propósito: `tests/ThreadEmailTest.php` declara su propio
   doble con ese nombre y comprueba que sea suyo. Los tickets del manifiesto se
   borran con el mismo `lookup()->delete()` que los departamentos, que sí está
   cubierto aquí. */
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('FAQ', false)) {
    class FAQ extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('Category', false)) {
    class Category extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('Team', false)) {
    class Team extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('Staff', false)) {
    class Staff extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('Dept', false)) {
    class Dept extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('SLA', false)) {
    class SLA extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('User', false)) {
    class User extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('Organization', false)) {
    class Organization extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('Canned', false)) {
    class Canned extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('Topic', false)) {
    class Topic extends SeedPurgeModel {}
}
/* Igual que el doble de `Ticket` en tests/ThreadEmailTest.php: si el
   modelo de verdad está cargado (pruebas de integración), manda él y
   esta copia no se declara. */
if (!class_exists('Task', false)) {
    class Task extends SeedPurgeModel {}
}

require_once INCLUDE_DIR . 'cli/modules/seed.php';

/** Escribe el manifiesto en el directorio temporal, no en el repositorio. */
class SeedPurgeManager extends SeedManager {
    /** La CLI de verdad declara estas dos; el `Module` de las pruebas no. */
    public $stdout;
    public $stderr;

    function manifestPath() {
        return sys_get_temp_dir() . '/seed-purge-test-manifest.json';
    }

    /**
     * Las dos búsquedas del conocimiento van a la base de datos por SQL; en
     * las pruebas se resuelven sobre los modelos simulados, con el mismo
     * criterio (pregunta exacta y nombre exacto).
     */
    function faqIdByQuestion($question) {
        $question = trim((string) $question);
        foreach (SeedPurgeModel::$rows['FAQ'] ?? array() as $row)
            if ($row->question === $question)
                return $row->getId();

        return null;
    }

    function categoryByName($name) {
        $name = trim((string) $name);
        foreach (SeedPurgeModel::$rows['Category'] ?? array() as $row)
            if ($row->name === $name)
                return $row;

        return null;
    }
}

class SeedPurgeTest extends UnitTestCase {

    private SeedPurgeStream $err;

    /** Modelos vacíos y manifiesto limpio antes de cada prueba. */
    private function reset(): void {
        SeedPurgeModel::$rows = array();
        SeedPurgeModel::$deleted = array();
        @unlink(sys_get_temp_dir() . '/seed-purge-test-manifest.json');
    }

    private function seed(?SeedPurgeStream $err = null): SeedManager {
        $seed = new SeedPurgeManager();
        $seed->stderr = $err ?: new SeedPurgeStream();
        $seed->stdout = new SeedPurgeStream();
        $this->err = $seed->stderr;

        return $seed;
    }

    /** N filas de un modelo, con nombres sacados de un paquete real. */
    private function rows($class, array $names): array {
        $out = array();
        foreach ($names as $i => $name) {
            $model = $class::create();
            $model->name = $name;
            $model->question = $name;
            $out[] = $model;
        }

        return $out;
    }

    /** Las tres primeras preguntas del conocimiento y dos categorías. */
    private function faqNames(): array {
        $questions = array();
        foreach (SeedManager::faqs() as $articles)
            foreach ($articles as $article)
                $questions[] = $article[0];

        return array_slice($questions, 0, 3);
    }

    private function categoryNames(): array {
        return array_slice(array_keys(SeedManager::faqs()), 0, 2);
    }

    private function teamNames(): array {
        $names = array();
        foreach (SeedManager::teams() as $team)
            $names[] = $team[0];

        return $names;
    }

    /* ------------------------------------------------------------------ */
    /* El recorrido de la purga                                            */
    /* ------------------------------------------------------------------ */

    public function testLaPurgaBorraElManifiestoYLosRestosPorNombre(): void {
        $this->reset();

        $tasks = $this->rows('Task', array('tarea uno', 'tarea dos'));
        $agents = $this->rows('Staff', array('admin'));
        $depts = $this->rows('Dept', array('Soporte'));
        $faqs = $this->rows('FAQ', $this->faqNames());
        $categories = $this->rows('Category', $this->categoryNames());
        $teams = $this->rows('Team', $this->teamNames());

        $seed = $this->seed();
        $seed->adminStaff = $agents[0];
        $seed->manifest = array(
            'tasks' => array($tasks[0]->getId()),
            // La segunda tarea no está en el manifiesto: no se toca
            'faq' => array('c' . $categories[0]->getId(),
                'f' . $faqs[0]->getId()),
            'teams' => array($teams[0]->getId()),
            'depts' => array($depts[0]->getId()),
        );

        $this->assertTrue($seed->purge());
        $this->assertSame(1, Task::objects()->count(),
            'sólo se borra la tarea del manifiesto');
        $this->assertSame(0, Dept::objects()->count());
        // Lo demás lo alcanza la pasada por nombre
        $this->assertSame(0, FAQ::objects()->count());
        $this->assertSame(0, Category::objects()->count());
        $this->assertSame(0, Team::objects()->count());
        // El administrador sigue ahí
        $this->assertSame(1, Staff::objects()->count());
        $this->assertSame('', trim($this->err->text()), 'sin avisos');
    }

    public function testLaPurgaNoBorraAlAdministradorEnUso(): void {
        $this->reset();

        $agents = $this->rows('Staff', array('admin', 'supervisor'));
        $seed = $this->seed();
        $seed->adminStaff = $agents[0];
        // El administrador está en el manifiesto: lo creó el propio sembrador
        // en una ejecución anterior
        $seed->manifest = array('agents' => array($agents[0]->getId()));

        $this->assertTrue($seed->purge());
        $this->assertSame(2, Staff::objects()->count(),
            'el administrador en uso no se borra');
    }

    public function testSinManifiestoLaPurgaBuscaRestosIgual(): void {
        $this->reset();

        $this->rows('FAQ', $this->faqNames());
        $this->rows('Team', $this->teamNames());
        $agents = $this->rows('Staff', array('admin'));

        $seed = $this->seed();
        $seed->adminStaff = $agents[0];
        $seed->manifest = array();

        $this->assertTrue($seed->purge());
        $this->assertSame(0, FAQ::objects()->count(),
            'sin manifiesto hay que limpiar el conocimiento por nombre');
        $this->assertSame(0, Team::objects()->count());
        $this->assertStringContainsString('se buscan restos por nombre',
            $seed->stdout->text());
    }

    public function testLaPurgaSePuedeRepetir(): void {
        $this->reset();

        $this->rows('FAQ', $this->faqNames());
        $agents = $this->rows('Staff', array('admin'));

        $seed = $this->seed();
        $seed->adminStaff = $agents[0];
        $seed->manifest = array('faq' => array('f1'));

        $this->assertTrue($seed->purge());
        $this->assertTrue($seed->purge(), 'la segunda pasada no debe fallar');
        $this->assertSame('', trim($this->err->text()));
    }

    public function testLaPurgaNoTocaNombresAjenos(): void {
        $this->reset();

        // Un artículo y un equipo que no son del sembrador
        $this->rows('FAQ', array('Mi pregunta propia'));
        $this->rows('Team', array('Mi equipo'));
        $agents = $this->rows('Staff', array('admin'));

        $seed = $this->seed();
        $seed->adminStaff = $agents[0];
        $seed->manifest = array();

        $this->assertTrue($seed->purge());
        $this->assertSame(1, FAQ::objects()->count());
        $this->assertSame(1, Team::objects()->count());
    }
}
