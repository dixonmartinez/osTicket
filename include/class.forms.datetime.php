<?php
/*********************************************************************
    class.forms.datetime.php

    Formularios: campos de fecha y hora.

    Parte de include/class.forms.php (frente 6 de docs/CODE_QUALITY.md):
    el archivo tenia 4.515 lineas y 37 clases. Se movieron las clases
    enteras, sin tocar su codigo.

    DatetimeField y TimeField.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/


class DatetimeField extends FormField {
    static $widget = 'DatetimePickerWidget';

    var $min = null;
    var $max = null;

    static function intervals($count=2, $i='') {
        $intervals = array(
            'i' => _N('minute', 'minutes', $count),
            'h' => _N('hour', 'hours', $count),
            'd' => _N('day','days', $count),
            'w' => _N('week', 'weeks', $count),
            'm' => _N('month', 'months', $count),
        );
        return $i ? $intervals[$i] : $intervals;
    }

    static function periods($period='') {
        $periods = array(
                'td' => __('Today'),
                'yd' => __('Yesterday'),
                'tw' => __('This Week'),
                'tm' => __('This Month'),
                'tq' => __('This Quarter'),
                'ty' => __('This Year'),
                'lw' => __('Last Week'),
                'lm' => __('Last Month'),
                'lq' => __('Last Quarter'),
                'ly' => __('Last Year'),
        );
        return $period ? $periods[$period] : $periods;
    }

    // Get php DatateTime object of the field  - null if value is empty
    function getDateTime($value=null) {
        return Format::parseDateTime($value ?: $this->value);
    }

    // Get effective timezone for the field
    function getTimeZone() {
        global $cfg;

        $config = $this->getConfiguration();
        $timezone = new DateTimeZone($config['timezone'] ?:
                $cfg->getTimezone());

        return $timezone;
    }

    function getMinDateTime() {

        if (!isset($this->min)) {
            $config = $this->getConfiguration();
            $this->min = $config['min']
                ? Format::parseDateTime($config['min']) : false;
        }

        return $this->min;
    }

    function getMaxDateTime() {

        if (!isset($this->max)) {
            $config = $this->getConfiguration();
            $this->max = $config['max']
                ? Format::parseDateTime($config['max']) : false;
        }

        return $this->max;
    }

    static function getPastPresentLabels() {
      return array(__('Create Date'), __('Reopen Date'),
                    __('Close Date'), __('Last Update'));
    }

    function to_database($value) {
        // Store time in format given by Date Picker (DateTime::W3C)
        return $value;
    }

    function to_php($value) {

        if (!is_numeric($value) && strtotime($value) <= 0)
            return 0;

        return $value;
    }

    function display($value) {
        global $cfg;

        if (!$value || !($datetime = Format::parseDateTime($value)))
            return '';

        $config = $this->getConfiguration();
        $format = $config['format'] ?: false;
        if ($config['gmt'])
            return $this->format((int) $datetime->format('U'), $format);

        // Force timezone if field has one.
        if ($config['timezone']) {
            $timezone = new DateTimezone($config['timezone']);
            $datetime->setTimezone($timezone);
        }

        $value = $this->format($datetime->format('U'),
                $datetime->getTimezone()->getName(),
                $format);
        // No need to show timezone
        if (!$config['time'] || $format)
            return $value;

        // Display is NOT timezone aware show entry's timezone.
        return sprintf('%s (%s)',
                $value, $datetime->format('T'));
    }

    function from_query($row, $name=false) {
        $value = parent::from_query($row, $name);
        $timestamp = is_int($value) ? $value : (int) strtotime($value);
        return ($timestamp > 0) ? $timestamp : '';
    }

    function format($timestamp, $timezone=false, $format=false) {

        if (!$timestamp || $timestamp <= 0)
            return '';

        $config = $this->getConfiguration();
        if ($config['time'])
            $formatted = Format::datetime($timestamp, false, $format,  $timezone);
        else
            $formatted = Format::date($timestamp, false, $format, $timezone);

        return $formatted;
    }

    function toString($value) {
        if (is_array($value))
            return '';

        $timestamp = is_int($value) ? $value : (int) strtotime($value);
        if ($timestamp <= 0)
            return '';

        return $this->format($timestamp);
    }

    function asVar($value, $id=false) {
        global $cfg;

        if (!$value)
            return null;

        $datetime = $this->getDateTime($value);
        $config = $this->getConfiguration();
        if (!$config['gmt'] || !$config['time'])
            $timezone  = $datetime->getTimezone()->getName();
        else
            $timezone  = false;

        return  new FormattedDate($value, array(
                    'timezone'  =>  $timezone,
                    'format'    =>  $config['time'] ? 'long' : 'short'
                    )
                );
    }

    function asVarType() {
        return 'FormattedDate';
    }

    function getConfigurationOptions() {
        return array(
            'time' => new BooleanField(array(
                'id'=>1, 'label'=>__('Time'), 'required'=>false, 'default'=>false,
                'configuration'=>array(
                    'desc'=>__('Show time selection with date picker')))),
            'timezone' => new TimezoneField(array(
                'id'=>2, 'label'=>__('Timezone'), 'required'=>false,
                'hint'=>__('Timezone of the date time selection'),
                'configuration' => array('autodetect'=>false,
                    'prompt' => __("User's timezone")),
               'visibility' => new VisibilityConstraint(
                    new Q(array('time__eq'=> true)),
                    VisibilityConstraint::HIDDEN
                ),
                )),
            'gmt' => new BooleanField(array(
                'id'=>3, 'label'=>__('Timezone Aware'), 'required'=>false,
                'configuration'=>array(
                    'desc'=>__("Show date/time relative to user's timezone")))),
            'min' => new DatetimeField(array(
                'id'=>4, 'label'=>__('Earliest'), 'required'=>false,
                'hint'=>__('Earliest date selectable'))),
            'max' => new DatetimeField(array(
                'id'=>5, 'label'=>__('Latest'), 'required'=>false,
                'default'=>null, 'hint'=>__('Latest date selectable'))),
            'future' => new BooleanField(array(
                'id'=>6, 'label'=>__('Allow Future Dates'), 'required'=>false,
                'default'=>true, 'configuration'=>array(
                    'desc'=>__('Allow entries into the future' /* Used in the date field */)),
            )),
        );
    }

    function validateEntry($value) {
        global $cfg;

        $config = $this->getConfiguration();
        parent::validateEntry($value);
        if (!$value || !($datetime = Format::parseDateTime($value)))
            return;

        // Get configured min/max (if any)
        $min = $this->getMinDateTime();
        $max = $this->getMaxDateTime();

        if (!$datetime) {
            $this->_errors[] = __('Enter a valid date');
        } elseif ($min and $datetime < $min) {
            $this->_errors[] = sprintf('%s (%s)',
                    __('Selected date is earlier than permitted'),
                     Format::date($min->getTimestamp(), false, false,
                         $min->getTimezone()->getName() ?: 'UTC')
                     );
        } elseif ($max and $datetime > $max) {
            $this->_errors[] = sprintf('%s (%s)',
                    __('Selected date is later than permitted'),
                    Format::date($max->getTimestamp(), false, false,
                        $max->getTimezone()->getName() ?: 'UTC')
                    );
        }
    }

    // SearchableField interface ------------------------------
    function getSearchMethods() {
        return array(
            'set' =>        __('has a value'),
            'nset' =>       __('does not have a value'),
            'equal' =>      __('on'),
            'nequal' =>     __('not on'),
            'before' =>     __('before'),
            'after' =>      __('after'),
            'between' =>    __('between'),
            'period' =>     __('period'),
            'ndaysago' =>   __('in the last n days'),
            'ndays' =>      __('in the next n days'),
            'future' =>     __('in the future'),
            'past' =>       __('in the past'),
            'distfut' =>    __('more than n days from now'),
            'distpast' =>   __('more than n days ago'),
        );
    }

    function getSearchMethodWidgets() {
        $config_notime = $config = $this->getConfiguration();
        $config_notime['time'] = false;
        $nday_form = function($x=5) {
            return array(
                'until' => new TextboxField(array(
                    'configuration' => array('validator'=>'number', 'size'=>4))
                ),
                'int' => new ChoiceField(array(
                    'default' => 'd',
                    'choices' => self::intervals($x),
                )),
            );
        };
        return array(
            'set' => null,
            'nset' => null,
            'past' => null,
            'future' => null,
            'equal' => array('DatetimeField', array(
                'configuration' => $config_notime,
            )),
            'nequal' => array('DatetimeField', array(
                'configuration' => $config_notime,
            )),
            'before' => array('DatetimeField', array(
                'configuration' => $config,
            )),
            'after' => array('DatetimeField', array(
                'configuration' => $config,
            )),
            'between' => array('InlineformField', array(
                'form' => array(
                    'left' => new DatetimeField($config + array('required' => true)),
                    'text' => new FreeTextField(array(
                        'configuration' => array('content' => __('and')))
                    ),
                    'right' => new DatetimeField($config + array('required' => true)),
                ),
                'configuration' => array(
                    'error' => '',
                ),
            )),
            'period' => array('ChoiceField', array(
                'choices' => self::periods(),
            )),
            'ndaysago' => array('InlineformField', array('form'=>$nday_form())),
            'ndays' => array('InlineformField', array('form'=>$nday_form())),
            'distfut' => array('InlineformField', array('form'=>$nday_form())),
            'distpast' => array('InlineformField', array('form'=>$nday_form())),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        global $cfg;

        static $intervals = array(
            'm' => 'MONTH',
            'w' => 'WEEK',
            'd' => 'DAY',
            'h' => 'HOUR',
            'i' => 'MINUTE',
        );
        $name = $name ?: $this->get('name');
        $now = SqlFunction::NOW();
        $config = $this->getConfiguration();
       if (is_int($value))
          $value = DateTime::createFromFormat('U', !$config['gmt'] ? Misc::gmtime($value) : $value) ?: $value;
       elseif (is_string($value) && strlen($value) > 2)
           $value = Format::parseDateTime($value) ?: $value;

        switch ($method) {
        case 'equal':
            $l = clone $value;
            $r = $value->add(new DateInterval('P1D'));
            return new Q(array(
                "{$name}__gte" => $l,
                "{$name}__lt" => $r
            ));
        case 'nequal':
            $l = clone $value;
            $r = $value->add(new DateInterval('P1D'));
            return Q::any(array(
                "{$name}__lt" => $l,
                "{$name}__gte" => $r,
            ));
        case 'future':
            $value = $now;
        case 'after':
            return new Q(array("{$name}__gte" => $value));
        case 'past':
            $value = $now;
        case 'before':
            return new Q(array("{$name}__lt" => $value));
        case 'between':
            $left = Format::parseDateTime($value['left']);
            $right = Format::parseDateTime($value['right']);
            if (!$left || !$right)
                return null;

            // TODO: allow time selection for between
            $left = $left->setTime(00, 00, 00);
            $right = $right->setTime(23, 59, 59);
            // Convert time to db timezone
            $dbtz = new DateTimeZone($cfg->getDbTimezone());
            $left->setTimezone($dbtz);
            $right->setTimezone($dbtz);
            return new Q(array(
                "{$name}__gte" =>  $left->format('Y-m-d H:i:s'),
                "{$name}__lte" =>  $right->format('Y-m-d H:i:s'),
            ));
        case 'ndaysago':
            $int = $intervals[(string) $value['int'] ?: 'd'] ?: 'DAY';
            $interval = new SqlInterval($int, $value['until']);
            return new Q(array(
                "{$name}__range" => array($now->minus($interval), $now),
            ));
        case 'ndays':
            $int = $intervals[(string) $value['int'] ?: 'd'] ?: 'DAY';
            $interval = new SqlInterval($int, $value['until']);
            return new Q(array(
                "{$name}__range" => array($now, $now->plus($interval)),
            ));
        // Distant past and future ranges
        case 'distpast':
            $int = $intervals[(string) $value['int'] ?: 'd'] ?: 'DAY';
            $interval = new SqlInterval($int, $value['until']);
            return new Q(array(
                "{$name}__lte" => $now->minus($interval),
            ));
        case 'distfut':
            $int = $intervals[(string) $value['int'] ?: 'd'] ?: 'DAY';
            $interval = new SqlInterval($int, $value['until']);
            return new Q(array(
                "{$name}__gte" => $now->plus($interval),
            ));
        case 'period':
            // User's effective timezone
            $tz = new DateTimeZone($cfg->getTimezone());
            // Get the period range boundaries in user's tz
            $period = Misc::date_range($value, Misc::gmtime('now'), $tz);
            // Convert boundaries to db time
            $dbtz = new DateTimeZone($cfg->getDbTimezone());
            $start = $period->start->setTimezone($dbtz);
            $end = $period->end->setTimezone($dbtz);
            // Set the range
            return new Q(array(
                "{$name}__range" => array(
                    $start->format('Y-m-d H:i:s'),
                    $end->format('Y-m-d H:i:s')
                    )
                ));
            break;
        default:
            return parent::getSearchQ($method, $value, $name);
        }
    }

    function describeSearchMethod($method) {
        switch ($method) {
        case 'before':
            return __('%1$s before %2$s' /* occurs before a date and time */);
        case 'after':
            return __('%1$s after %2$s' /* occurs after a date and time */);
        case 'ndays':
            return __('%1$s in the next %2$s' /* occurs within a window (like 3 days) */);
        case 'ndaysago':
            return __('%1$s in the last %2$s' /* occurs within a recent window (like 3 days) */);
        case 'distfut':
            return __('%1$s after %2$s from now' /* occurs after a window (like 3 days) */);
        case 'distpast':
            return __('%1$s before %2$s ago' /* occurs previous to a window (like 3 days) */);
        case 'between':
            return __('%1$s between %2$s and %3$s');
        case 'future':
            return __('%1$s is in the future');
        case 'past':
            return __('%1$s is in the past');
        case 'period':
            return __('%1$s is %2$s');
        default:
            return parent::describeSearchMethod($method);
        }
    }

    function describeSearch($method, $value, $name=false) {

        $name = $name ?: $this->get('name');
        $desc = $this->describeSearchMethod($method);
        switch ($method) {
            case 'between':
                return sprintf($desc, $name,
                        $this->toString($value['left']),
                        $this->toString($value['right']));
            case 'ndays':
            case 'ndaysago':
            case 'distfut':
            case 'distpast':
                $interval = sprintf('%s %s', $value['until'],
                        self::intervals($value['until'], $value['int']));
                return sprintf($desc, $name, $interval);
                break;
            case 'future':
            case 'past':
                return sprintf($desc, $name);
            case 'before':
            case 'after':
                return sprintf($desc, $name, $this->toString($value));
            case 'period':
                return sprintf($desc, $name, self::periods($value) ?: $value);
            default:
                return parent::describeSearch($method, $value, $name);
        }
    }

    function supportsQuickFilter() {
        return true;
    }

    function getQuickFilterChoices() {
        return array(
            'h' => __('Today'),
            'm' => __('Tomorrow'),
            'g' => __('Yesterday'),
            'l7' => __('Last 7 days'),
            'l30' => __('Last 30 days'),
            'n7' => __('Next 7 days'),
            'n30' => __('Next 30 days'),
            /* Ugh. These boundaries are so difficult in SQL
            'w' =>  __('This Week'),
            'm' =>  __('This Month'),
            'lw' => __('Last Week'),
            'lm' => __('Last Month'),
            'nw' => __('Next Week'),
            'nm' => __('Next Month'),
            */
        );
    }

    function applyQuickFilter($query, $qf_value, $name=false) {
        $name = $name ?: $this->get('name');
        $now = SqlFunction::NOW();
        $midnight = Misc::dbtime(time() - (time() % 86400));
        switch ($qf_value) {
        case 'l7':
            return $query->filter([
                "{$name}__range" => array($now->minus(SqlInterval::DAY(7)), $now),
            ]);
        case 'l30':
            return $query->filter([
                "{$name}__range" => array($now->minus(SqlInterval::DAY(30)), $now),
            ]);
        case 'n7':
            return $query->filter([
                "{$name}__range" => array($now, $now->plus(SqlInterval::DAY(7))),
            ]);
        case 'n30':
            return $query->filter([
                "{$name}__range" => array($now, $now->plus(SqlInterval::DAY(30))),
            ]);
        case 'g':
            $midnight -= 86400;
             // Fall through to the today case
        case 'm':
            if ($qf_value === 'm') $midnight += 86400;
             // Fall through to the today case
        case 'h':
            $midnight = DateTime::createFromFormat('U', $midnight);
            return $query->filter([
                "{$name}__range" => array($midnight,
                    SqlExpression::plus($midnight, SqlInterval::DAY(1))),
            ]);
        }
    }
}



/**
 * TimeField for time selection
 *
 */
class TimeField extends FormField {
    static $widget = 'TimePickerWidget';

    var $start = null;
    var $end = null;

    function getTimeZone() {
        global $cfg;
        $config = $this->getConfiguration();
        return new DateTimeZone($config['timezone'] ?: $cfg->getTimezone());
    }
}
