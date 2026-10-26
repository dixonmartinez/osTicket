<?php
/**
 * tests/ScheduleFieldsTest.php
 *
 * Pruebas de `ScheduleEntryFormFields` (`include/class.schedule.fields.php`),
 * los trece campos del formulario de una entrada de horario (frente 2 de
 * docs/CODE_QUALITY.md).
 *
 * La definición estaba dentro de `ScheduleEntryForm::buildFields()` (175
 * líneas). Aquí se fija lo que el agente ve y lo que el formulario exige:
 * claves y orden, tipos, obligatoriedad, valores por omisión, opciones de cada
 * lista y las restricciones de visibilidad que hacen aparecer el día de la
 * semana, el del mes o el mes concreto.
 */

/* `ScheduleEntry` y los campos extienden modelos del ORM: necesitan las clases
   de la aplicación aunque no haya base de datos. */
require_once INCLUDE_DIR . 'class.orm.php';
require_once INCLUDE_DIR . 'class.forms.php';
require_once INCLUDE_DIR . 'class.schedule.php';

/* Las tablas, como las define `Bootstrap::defineTables()`: sólo se usan como
   nombre en la meta del modelo, no se consulta nada. */
if (!defined('SCHEDULE_TABLE'))
    define('SCHEDULE_TABLE', 'ost_schedule');
if (!defined('SCHEDULE_ENTRY_TABLE'))
    define('SCHEDULE_ENTRY_TABLE', 'ost_schedule_entry');

class ScheduleFieldsTest extends UnitTestCase {

    private function fields(array $options = array()) {
        return \ScheduleEntryFormFields::build($options);
    }

    /* ------------------------------------------------------------------ */
    /* Estructura                                                          */
    /* ------------------------------------------------------------------ */

    public function testLosTreceCamposYSuOrden(): void {
        // El orden importa: es el que sigue el formulario en pantalla
        $this->assertSame(array(
            'name', 'starts_on', 'allday', 'starts_at', 'ends_at', 'repeats',
            'stops_on', 'weekly_day', 'monthly', 'monthly_day',
            'yearly', 'yearly_day', 'yearly_month',
        ), array_keys($this->fields()));
    }

    public function testCadaCampoTieneSuTipo(): void {
        $expected = array(
            'name' => 'TextboxField',
            'starts_on' => 'DatetimeField',
            'allday' => 'BooleanField',
            'starts_at' => 'TimeField',
            'ends_at' => 'TimeField',
            'repeats' => 'ChoiceField',
            'stops_on' => 'DatetimeField',
            'weekly_day' => 'ChoiceField',
            'monthly' => 'ChoiceField',
            'monthly_day' => 'ChoiceField',
            'yearly' => 'ChoiceField',
            'yearly_day' => 'ChoiceField',
            'yearly_month' => 'ChoiceField',
        );
        foreach ($this->fields() as $name => $field)
            $this->assertSame($expected[$name], get_class($field), $name);
    }

    public function testSoloLosObligatoriosSonObligatorios(): void {
        $required = array();
        foreach ($this->fields() as $name => $field)
            if ($field->isRequired())
                $required[] = $name;

        // «Hasta» es opcional: vacío significa «para siempre»
        $this->assertSame(array('name', 'starts_on', 'starts_at', 'ends_at',
            'repeats', 'weekly_day', 'monthly', 'monthly_day', 'yearly',
            'yearly_day', 'yearly_month'), $required);
    }

    /* ------------------------------------------------------------------ */
    /* Valores por omisión                                                 */
    /* ------------------------------------------------------------------ */

    public function testHorarioNormalEmpiezaYOchoYAcabaACinco(): void {
        $fields = $this->fields();

        $this->assertFalse($fields['allday']->get('default'));
        $this->assertSame('8:00 am', $fields['starts_at']->get('default'));
        $this->assertSame('5:00 pm', $fields['ends_at']->get('default'));
    }

    public function testHorarioDeVacacionesEsTodoElDia(): void {
        // Con `holidays`, el horario cubre el día completo
        $fields = $this->fields(array('holidays' => true));

        $this->assertTrue((bool) $fields['allday']->get('default'));
        $this->assertSame('12:00 am', $fields['starts_at']->get('default'));
        $this->assertSame('11:59 pm', $fields['ends_at']->get('default'));
    }

    public function testLaZonaHorariaLlegaALosCamposDeHora(): void {
        $fields = $this->fields(array('timezone' => 'America/Caracas'));

        $this->assertSame('America/Caracas',
            $fields['starts_at']->get('configuration')['timezone']);
        $this->assertSame('America/Caracas',
            $fields['ends_at']->get('configuration')['timezone']);
        // Y no se muestra al lado del campo
        $this->assertFalse(
            $fields['starts_at']->get('configuration')['showtimezone']);
    }

    public function testSinOpcionesNoRevienta(): void {
        // Antes, construir el formulario sin opciones dejaba «Undefined array
        // key» en el log por cada campo
        $opts = \ScheduleEntryFormFields::options();

        $this->assertSame(array('timezone' => null, 'allday' => false), $opts);
        $this->assertCount(13, $this->fields());
    }

    /* ------------------------------------------------------------------ */
    /* Listas                                                              */
    /* ------------------------------------------------------------------ */

    public function testLaFrecuenciaOfreceLasCincoFormas(): void {
        $choices = $this->fields()['repeats']->getChoices();

        // Una sola vez, diario, semanal, mensual y anual
        $this->assertCount(5, $choices);
        $this->assertArrayHasKey('daily', $choices);
        $this->assertArrayHasKey('weekly', $choices);
        $this->assertArrayHasKey('monthly', $choices);
        $this->assertArrayHasKey('yearly', $choices);
    }

    public function testMensualOfreceDiaDelMesOEnesimoDia(): void {
        $choices = $this->fields()['monthly']->getChoices();

        $this->assertSame(array('day', 1, 2, 3, 4, 5, -1),
            array_keys($choices));
        $this->assertSame('day', $this->fields()['monthly']->get('default'));
    }

    public function testAnualOfreceFechaExactaOEnesimoDia(): void {
        $choices = $this->fields()['yearly']->getChoices();

        // Las claves numéricas las normaliza PHP a enteros, como antes
        $this->assertSame(array('date', 1, 2, 3, 4, 5, -1),
            array_keys($choices));
    }

    public function testLosDiasDeLaSemanaSonSiete(): void {
        $this->assertCount(7, $this->fields()['monthly_day']->getChoices());
    }

    /* ------------------------------------------------------------------ */
    /* Visibilidad                                                         */
    /* ------------------------------------------------------------------ */

    /** Restricción de un campo: qué campo mira, con qué operador y valor. */
    private function constraint($field): array {
        $visibility = $field->get('visibility');
        $constraints = $visibility->constraint->constraints;

        $this->assertCount(1, $constraints);
        $key = array_keys($constraints)[0];

        return array(
            'key' => $key,
            'value' => $constraints[$key],
            'initial' => $visibility->initial,
        );
    }

    public function testLasHorasSeOcultanConTodoElDia(): void {
        $fields = $this->fields();

        foreach (array('starts_at', 'ends_at') as $name) {
            $c = $this->constraint($fields[$name]);
            $this->assertSame('allday__eq', $c['key']);
            $this->assertFalse($c['value']);
            // Ocultos salvo que se desmarque «todo el día»
            $this->assertSame(\VisibilityConstraint::HIDDEN, $c['initial']);
        }
    }

    public function testElDiaDeLaSemanaSoloApareceSiRepiteSemanal(): void {
        $c = $this->constraint($this->fields()['weekly_day']);

        $this->assertSame('repeats__eq', $c['key']);
        $this->assertSame('weekly', $c['value']);
        $this->assertSame(\VisibilityConstraint::HIDDEN, $c['initial']);
    }

    public function testElDiaDelMesSoloApareceSiNoEsElDiaExacto(): void {
        $c = $this->constraint($this->fields()['monthly_day']);

        $this->assertSame('monthly__neq', $c['key']);
        $this->assertSame('day', $c['value']);
        $this->assertSame(\VisibilityConstraint::HIDDEN, $c['initial']);
    }

    public function testElMesSoloApareceSiNoEsLaFechaExacta(): void {
        foreach (array('yearly_day', 'yearly_month') as $name) {
            $c = $this->constraint($this->fields()[$name]);
            $this->assertSame('yearly__neq', $c['key']);
            $this->assertSame('date', $c['value']);
        }
    }

    public function testHastaSeVeSoloSiHayRepeticion(): void {
        $c = $this->constraint($this->fields()['stops_on']);

        // Cualquier frecuencia menos «una sola vez»
        $this->assertSame('repeats__eq', $c['key']);
        $this->assertSame('daily|weekly|monthly|yearly', $c['value']);
        $this->assertSame(\VisibilityConstraint::VISIBLE, $c['initial']);
    }

    /* ------------------------------------------------------------------ */
    /* El formulario que los usa                                           */
    /* ------------------------------------------------------------------ */

    public function testElFormularioDevuelveLosMismosCampos(): void {
        $form = new \ScheduleEntryForm(null, array(
            'timezone' => 'UTC', 'holidays' => false));

        $this->assertCount(13, $form->getFields());
        $this->assertSame('8:00 am',
            $form->getField('starts_at')->get('default'));
    }
}
