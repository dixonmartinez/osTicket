<?php
/**
 * include/class.schedule.fields.php
 *
 * Los campos del formulario de una entrada de horario (`ScheduleEntryForm`),
 * sacados a su propio archivo (frente 2 de docs/CODE_QUALITY.md).
 *
 * El formulario son trece campos con reglas cruzadas: la hora de inicio y de
 * fin dependen de «todo el día», y día de la semana, día del mes e «in» sólo
 * aparecen según cómo repita la entrada. Todo eso estaba en un único
 * `buildFields()` de 175 líneas dentro de `class.schedule.php`.
 *
 * La definición es **la misma**: mismas claves, mismo orden, mismos valores
 * por omisión y las mismas restricciones de visibilidad. Lo único que cambia
 * es que ahora se puede construir sin tocar la base de datos y comprobar en
 * una prueba, campo a campo, qué se le pide al agente.
 *
 * No guarda nada ni consulta nada: devuelve el arreglo de campos.
 *
 * @see ScheduleEntryForm::buildFields()
 */

class ScheduleEntryFormFields {

    /**
     * Las dos opciones que condicionan los campos, con su valor por omisión.
     *
     * Leerlas sin comprobar que existan dejaba un «Undefined array key» en el
     * log cada vez que el formulario se construía sin opciones.
     */
    static function options(array $options = array()) {
        return array(
            'timezone' => $options['timezone'] ?? null,
            // Un horario de vacaciones («hdays») empieza y acaba en el día
            'allday' => (bool) ($options['holidays'] ?? false),
        );
    }

    /**
     * Los trece campos del formulario, en el orden de siempre.
     */
    static function build(array $options = array()) {
        $opts = self::options($options);

        return self::head($opts)
            + self::weekly()
            + self::monthly()
            + self::yearly();
    }

    /** Nombre, fecha, tramo horario y frecuencia. */
    static function head(array $opts) {
        $timezone = $opts['timezone'];
        $allday = $opts['allday'];

        return array(
            'name' => new TextboxField(array(
                'required' => true,
                'layout' => new GridFluidCell(8),
                'label' => __('Name'),
                'configuration' => array(
                    'size' => 60,
                    'length' => 0,
                    'autofocus' => true,
                    ),
            )),
            'starts_on' => new DatetimeField(array(
                'label' => __('Starts on'),
                'required' => true,
                'layout' => new GridFluidCell(6),
                'configuration' => array(
                    'time' => false,
                    'gmt' => false,
                    'future' => true,
                    'showtimezone' => false,
                    ),
            )),
            'allday' => new BooleanField(array(
                'required' => false,
                'default' => $allday,
                'label' => __('All Day'),
                'layout' => new GridFluidCell(6),
                'configuration' => array(
                    'desc' => __('Full 24-hour day')),
            )),
            'starts_at' => new TimeField(array(
                'required' => true,
                'default' => $allday ? '12:00 am' : '8:00 am',
                'label' => __('Starts at'),
                'layout' => new GridFluidCell(6),
                'configuration' => array(
                    'timezone' => $timezone,
                    'showtimezone' => false,
                    ),
                'visibility' => new VisibilityConstraint(
                    new Q(array('allday__eq' => false)),
                    VisibilityConstraint::HIDDEN),
            )),
            'ends_at' => new TimeField(array(
                'required' => true,
                'default' => $allday ? '11:59 pm' : '5:00 pm',
                'label' => __('Ends at'),
                'layout' => new GridFluidCell(6),
                'configuration' => array(
                    'timezone' => $timezone,
                    'showtimezone' => false,
                    ),
                'visibility' => new VisibilityConstraint(
                    new Q(array('allday__eq' => false)),
                    VisibilityConstraint::HIDDEN),
            )),
            'repeats' => new ChoiceField(array(
                'required' => true,
                'layout' => new GridFluidCell(6),
                'label' => __('Repeats'),
                'validator-error' => __('Selection required'),
                'choices' => ScheduleEntry::getFrequencies(),
            )),
            'stops_on' => new DatetimeField(array(
                'label' => __('Until'),
                'required' => false,
                'layout' => new GridFluidCell(4),
                'configuration' => array(
                    'time' => false,
                    'gmt' => false,
                    'future' => true,
                    'placeholder' => __('Forever'),
                    'showtimezone' => false,
                    ),
                'visibility' => new VisibilityConstraint(
                    new Q(array('repeats__eq' => 'daily|weekly|monthly|yearly')),
                    VisibilityConstraint::VISIBLE),
            )),
        );
    }

    /** Repetición semanal: qué día de la semana. */
    static function weekly() {
        return array(
            'weekly_day' => new ChoiceField(array(
                'required' => true,
                'default' => '',
                'layout' => new GridFluidCell(6),
                'label' => __('Day of the Week'),
                'choices' => ScheduleEntry::getTranslatedDays() + array(
                    'weekdays' => sprintf('%s (%s)',
                        __('Weekdays'), __('Mon-Fri')),
                    'weekends' => sprintf('%s (%s)',
                        __('Weekends'), __('Sat-Sun'))),
                'validator-error' => __('Selection required'),
                'configuration' => array('prompt' => __('Select Day of the Week')),
                'visibility' => new VisibilityConstraint(
                    new Q(array('repeats__eq' => 'weekly')),
                    VisibilityConstraint::HIDDEN),
            )),
        );
    }

    /** Repetición mensual: el día del mes o el enésimo día de la semana. */
    static function monthly() {
        return array(
            'monthly' => new ChoiceField(array(
                'required' => true,
                'default' => 'day',
                'layout' => new GridFluidCell(6, array('break' => true)),
                'label' => __('On the'),
                'validator-error' => __('Selection required'),
                'choices' => array(
                    'day' => __('Day of the Month Entered'),
                    '1' => __('First'),
                    '2' => __('Second'),
                    '3' => __('Third'),
                    '4' => __('Fourth'),
                    '5' => __('Fifth'),
                    '-1' => __('Last'),
                    ),
                'visibility' => new VisibilityConstraint(
                    new Q(array('repeats__eq' => 'monthly')),
                    VisibilityConstraint::HIDDEN),
            )),
            'monthly_day' => new ChoiceField(array(
                'required' => true,
                'default' => '',
                'layout' => new GridFluidCell(6),
                'label' => __('Day'),
                'choices' => ScheduleEntry::getTranslatedDays(),
                'validator-error' => __('Selection required'),
                'visibility' => new VisibilityConstraint(
                    new Q(array('monthly__neq' => 'day')),
                    VisibilityConstraint::HIDDEN),
            )),
        );
    }

    /** Repetición anual: fecha exacta o enésimo día de un mes concreto. */
    static function yearly() {
        return array(
            'yearly' => new ChoiceField(array(
                'required' => true,
                'layout' => new GridFluidCell(4),
                'label' => __('On the'),
                'validator-error' => __('Selection required'),
                'choices' => array(
                    'date' => __('Date Entered'),
                    '1' => __('First'),
                    '2' => __('Second'),
                    '3' => __('Third'),
                    '4' => __('Fourth'),
                    '5' => __('Fifth'),
                    '-1' => __('Last'),
                    ),
                'visibility' => new VisibilityConstraint(
                    new Q(array('repeats__eq' => 'yearly')),
                    VisibilityConstraint::HIDDEN),
            )),
            'yearly_day' => new ChoiceField(array(
                'required' => true,
                'default' => '',
                'layout' => new GridFluidCell(4),
                'label' => __('Day'),
                'choices' => ScheduleEntry::getTranslatedDays(),
                'validator-error' => __('Selection required'),
                'configuration' => array('prompt' => __('Day')),
                'visibility' => new VisibilityConstraint(
                    new Q(array('yearly__neq' => 'date')),
                    VisibilityConstraint::HIDDEN),
            )),
            'yearly_month' => new ChoiceField(array(
                'required' => true,
                'default' => 0,
                'layout' => new GridFluidCell(4),
                'label' => __('In'),
                'choices' => ScheduleEntry::getMonths(),
                'validator-error' => __('Selection required'),
                'configuration' => array('prompt' => __('Month')),
                'visibility' => new VisibilityConstraint(
                    new Q(array('yearly__neq' => 'date')),
                    VisibilityConstraint::HIDDEN),
            )),
        );
    }
}
