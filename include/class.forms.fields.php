<?php
/*********************************************************************
    class.forms.fields.php

    Formularios: campos simples.

    Parte de include/class.forms.php (frente 6 de docs/CODE_QUALITY.md):
    el archivo tenia 4.515 lineas y 37 clases. Se movieron las clases
    enteras, sin tocar su codigo.

    Texto, contrasena, area de texto, telefono, si/no, eleccion, numero y
 * color: los que no necesitan saber nada del ticket.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/


class TextboxField extends FormField {
    static $widget = 'TextboxWidget';

    function getConfigurationOptions() {
        return array(
            'size'  =>  new TextboxField(array(
                'id'=>1, 'label'=>__('Size'), 'required'=>false, 'default'=>16,
                    'validator' => 'number')),
            'length' => new TextboxField(array(
                'id'=>2, 'label'=>__('Max Length'), 'required'=>false, 'default'=>30,
                    'validator' => 'number')),
            'validator' => new ChoiceField(array(
                'id'=>3, 'label'=>__('Validator'), 'required'=>false, 'default'=>'',
                'choices' => array('phone'=>__('Phone Number'),'email'=>__('Email Address'),
                    'ip'=>__('IP Address'), 'number'=>__('Number'),
                    'regex'=>__('Custom (Regular Expression)'), ''=>__('None')))),
            'regex' => new TextboxField(array(
                'id'=>6, 'label'=>__('Regular Expression'), 'required'=>true,
                'configuration'=>array('size'=>40, 'length'=>100),
                'visibility' => new VisibilityConstraint(
                    new Q(array('validator__eq'=>'regex')),
                    VisibilityConstraint::HIDDEN
                ),
                'cleaners' => function ($self, $value) {
                    $wrapped = "/".$value."/iu";
                    if (false === @preg_match($value, ' ')
                            && false !== @preg_match($wrapped, ' ')) {
                        $value = $wrapped;
                    }
                    if ($value == '//iu')
                        return '';

                    return $value;
                },
                'validators' => function($self, $v) {
                    if (false === @preg_match($v, ' '))
                        $self->addError(__('Cannot compile this regular expression'));
                })),
            'validator-error' => new TextboxField(array(
                'id'=>4, 'label'=>__('Validation Error'), 'default'=>'',
                'configuration'=>array('size'=>40, 'length'=>60,
                    'translatable'=>$this->getTranslateTag('validator-error')
                ),
                'hint'=>__('Message shown to user if the input does not match the validator'))),
            'placeholder' => new TextboxField(array(
                'id'=>5, 'label'=>__('Placeholder'), 'required'=>false, 'default'=>'',
                'hint'=>__('Text shown in before any input from the user'),
                'configuration'=>array('size'=>40, 'length'=>40,
                    'translatable'=>$this->getTranslateTag('placeholder')
                ),
            )),
        );
    }

    function validateEntry($value) {
        //check to see if value is the string '0'
        $value = ($value === '0') ? '&#48' : Format::htmlchars($this->toString($value ?: $this->value));
        parent::validateEntry($value);
        $config = $this->getConfiguration();
        $validators = array(
            '' => '',
            'noop' => array(
                function($a, &$b) { return true; }
            ),
            'formula' => array(array('Validator', 'is_formula'),
                __('Content cannot start with the following characters: = - + @')),
            'email' =>  array(array('Validator', 'is_valid_email'),
                __('Enter a valid email address')),
            'phone' =>  array(array('Validator', 'is_phone'),
                __('Enter a valid phone number')),
            'ip' =>     array(array('Validator', 'is_ip'),
                __('Enter a valid IP address')),
            'number' => array(array('Validator', 'is_numeric'),
                __('Enter a number')),
            'password' => array(array('Validator', 'check_passwd'),
                __('Invalid Password')),
            'regex' => array(
                function($v) use ($config) {
                    $regex = $config['regex'];
                    return @preg_match($regex, $v);
                }, $config['validator-error'] ?? __('Value does not match required pattern')
            ),
        );
        // Support configuration forms, as well as GUI-based form fields
        $valid = $this->get('validator');
        if (!$valid) {
            $valid = $config['validator'];
        }
        if (!$value || !isset($validators[$valid]))
            return;
        // If no validators are set and not an instanceof AdvancedSearchForm
        // force formula validation
        if (!$valid && !($this->getForm() instanceof AdvancedSearchForm))
            $valid = 'formula';
        $func = $validators[$valid];
        $error = $err = null;
        // If validator is number and the value is &#48 set to 0 (int) for is_numeric
        if ($valid == 'number' && $value == '&#48')
            $value = 0;
        if ($config['validator-error'])
            $error = $this->getLocal('validator-error', $config['validator-error']);
        if (is_array($func) && is_callable($func[0]))
            if (!call_user_func_array($func[0], array($value, &$err)))
                $this->_errors[] =  $error ?: $err ?: $func[1];
    }

    function parse($value) {
        return Format::strip_emoticons(Format::striptags($value));
    }

    function display($value) {
        return ($value === '0') ? '&#48;' : Format::htmlchars($this->toString($value ?: $this->value), true);
    }
}


class PasswordField extends TextboxField {
    static $widget = 'PasswordWidget';

    function __construct($options=array()) {
        parent::__construct($options);
        if (!isset($options['validator']))
            $this->set('validator', 'password');
    }

    protected function getMasterKey() {
        return SECRET_SALT;
    }

    protected function getSubKey() {
        $config = $this->getConfiguration();
        return $config['key'] ?: 'pwfield';
    }

    function parse($value) {
        // Don't trim the value
        return $value;
    }

    function to_database($value) {
        // If not set in UI, don't save the empty value
        if (!$value)
            throw new FieldUnchanged();
        return Crypto::encrypt($value, $this->getMasterKey(),
                $this->getSubKey());
    }

    function to_php($value) {
        return Crypto::decrypt($value, $this->getMasterKey(),
                $this->getSubKey());
    }
}


class TextareaField extends FormField {
    static $widget = 'TextareaWidget';

    function getConfigurationOptions() {
        return array(
            'cols'  =>  new TextboxField(array(
                'id'=>1, 'label'=>__('Width').' '.__('(chars)'), 'required'=>true, 'default'=>40)),
            'rows'  =>  new TextboxField(array(
                'id'=>2, 'label'=>__('Height').' '.__('(rows)'), 'required'=>false, 'default'=>4)),
            'length' => new TextboxField(array(
                'id'=>3, 'label'=>__('Max Length'), 'required'=>false, 'default'=>0)),
            'html' => new BooleanField(array(
                'id'=>4, 'label'=>__('HTML'), 'required'=>false, 'default'=>true,
                'configuration'=>array('desc'=>__('Allow HTML input in this box')))),
            'placeholder' => new TextboxField(array(
                'id'=>5, 'label'=>__('Placeholder'), 'required'=>false, 'default'=>'',
                'hint'=>__('Text shown in before any input from the user'),
                'configuration'=>array('size'=>40, 'length'=>40,
                    'translatable'=>$this->getTranslateTag('placeholder')),
            )),
        );
    }

    function validateEntry($value) {
        parent::validateEntry($value);
        if (!$value)
            return;
        $config = $this->getConfiguration();
        $validators = array(
            '' =>       array(array('Validator', 'is_formula'),
                __('Content cannot start with the following characters: = - + @')),
            'choices' => array(
                function($val) {
                    $val = str_replace('"', '', JsonDataEncoder::encode($val));
                    $regex = "/^(?! )[A-z0-9 _-]+:{1}[^\n]+$/";
                    foreach (explode('\r\n', $val) as $v) {
                        if (!preg_match($regex, $v))
                            return false;
                    }
                    return true;
                }, __('Each choice requires a key and has to be on a new line. (eg. key:value)')
            ),
        );
        // Support configuration forms, as well as GUI-based form fields
        if (!($valid = $this->get('validator')) && isset($config['validator']))
            $valid = $config['validator'];

        if (!isset($validators[$valid]))
            return;

        $func = $validators[$valid];
        $error = $func[1];
        if ($config['validator-error'])
            $error = $this->getLocal('validator-error', $config['validator-error']);
        if (is_array($func) && is_callable($func[0]))
            if (!call_user_func($func[0], $value))
                $this->_errors[] = $error;
    }

    function display($value) {
        $config = $this->getConfiguration();
        if ($config['html'])
            return Format::safe_html($value);
        else
            return nl2br(Format::htmlchars($value, true));
    }

    function searchable($value) {
        $body = new HtmlThreadEntryBody($value);
        return $body->getSearchable();
    }

    function export($value) {
        return (!$value) ? $value : Format::html2text($value);
    }

    function parse($value) {
        $config = $this->getConfiguration();
        if ($config['html'])
            return Format::sanitize($value);
        else
            return $value;
    }

}


class PhoneField extends FormField {
    static $widget = 'PhoneNumberWidget';

    function getConfigurationOptions() {
        return array(
            'ext' => new BooleanField(array(
                'label'=>__('Extension'), 'default'=>true,
                'configuration'=>array(
                    'desc'=>__('Add a separate field for the extension'),
                ),
            )),
            'digits' => new TextboxField(array(
                'label'=>__('Minimum length'), 'default'=>7,
                'hint'=>__('Fewest digits allowed in a valid phone number'),
                'configuration'=>array('validator'=>'number', 'size'=>5),
            )),
            'format' => new ChoiceField(array(
                'label'=>__('Display format'), 'default'=>'us',
                'choices'=>array(''=>'-- '.__('Unformatted').' --',
                    'us'=>__('United States')),
            )),
        );
    }

    function validateEntry($value) {
        parent::validateEntry($value);
        $config = $this->getConfiguration();
        # Run validator against $this->value for email type
        list($phone, $ext) = explode("X", $value, 2);
        if ($phone && (
                !is_numeric($phone) ||
                strlen($phone) < $config['digits']))
            $this->_errors[] = __("Enter a valid phone number");
        if ($ext && $config['ext']) {
            if (!is_numeric($ext))
                $this->_errors[] = __("Enter a valid phone extension");
            elseif (!$phone)
                $this->_errors[] = __("Enter a phone number for the extension");
        }
    }

    function parse($value) {
        // NOTE: Value may have a legitimate 'X' to separate the number and
        // extension parts. Don't remove the 'X'
        $val = preg_replace('/[^\dX]/', '', $value);
        // Pass completely-incorrect string for validation error
        return $val ?: $value;
    }

    function toString($value) {
        $config = $this->getConfiguration();
        list($phone, $ext) = explode("X", $value, 2);
        switch ($config['format']) {
        case 'us':
            $phone = Format::phone($phone);
            break;
        }
        if ($ext)
            $phone.=" x$ext";
        return $phone;
    }
}


class BooleanField extends FormField {
    static $widget = 'CheckboxWidget';

    function getConfigurationOptions() {
        return array(
            'desc' => new TextareaField(array(
                'id'=>1, 'label'=>__('Description'), 'required'=>false, 'default'=>'',
                'hint'=>__('Text shown inline with the widget'),
                'configuration'=>array('rows'=>2)))
        );
    }

    function to_database($value) {
        return ($value) ? '1' : '0';
    }

    function parse($value) {
        return $this->to_php($value);
    }
    function to_php($value) {
        return $value ? true : false;
    }

    function toString($value) {
        return ($value) ? __('Yes') : __('No');
    }

    function asVar($value, $id=false) {
        return $this->toString($value);
    }

    function getClean($validate=true) {
        if (!isset($this->_clean)) {
            $this->_clean = (isset($this->value))
                ? $this->value : $this->getValue();

            if ($this->isVisible() && $validate)
                $this->validateEntry($this->_clean);
        }
        return $this->_clean;
    }

    function getSearchMethods() {
        return array(
            'set' =>        __('checked'),
            'nset' =>    __('unchecked'),
        );
    }

    function describeSearchMethod($method) {

        $methods = $this->get('descsearchmethods');
        if (isset($methods[$method]))
            return $methods[$method];

        return parent::describeSearchMethod($method);
    }

    function getSearchMethodWidgets() {
        return array(
            'set' => null,
            'nset' => null,
        );
    }

    function getSearchQ($method, $value, $name=false) {
        $name = $name ?: $this->get('name');
        switch ($method) {
        case 'set':
            return new Q(array($name => '1'));
        case 'nset':
            return new Q(array($name => '0'));
        default:
            return parent::getSearchQ($method, $value, $name);
        }
    }

    function supportsQuickFilter() {
        return true;
    }

    function getQuickFilterChoices() {
        return array(
            true => __('Checked'),
            false => __('Not Checked'),
        );
    }

    function applyQuickFilter($query, $qf_value, $name=false) {
        return $query->filter(array(
            $name ?: $this->get('name') => (int) $qf_value,
        ));
    }
}


class ChoiceField extends FormField {
    static $widget = 'ChoicesWidget';
    var $_choices;

    function getConfigurationOptions() {
        return array(
            'choices'  =>  new TextareaField(array(
                'id'=>1, 'label'=>__('Choices'), 'required'=>false, 'default'=>'',
                'hint'=>__('List choices, one per line. To protect against spelling changes, specify key:value names to preserve entries if the list item names change.</br><b>Note:</b> If you have more than two choices, use a List instead.'),
                'validator'=>'choices',
                'configuration'=>array(
                    'html' => false,
                    'disabled' => false,
                    )
            )),
            'default' => new TextboxField(array(
                'id'=>3, 'label'=>__('Default'), 'required'=>false, 'default'=>'',
                'hint'=>__('(Enter a key). Value selected from the list initially'),
                'configuration'=>array('size'=>20, 'length'=>40),
            )),
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40,
                    'translatable'=>$this->getTranslateTag('prompt'),
                ),
            )),
            'multiselect' => new BooleanField(array(
                'id'=>1, 'label'=>'Multiselect', 'required'=>false, 'default'=>false,
                'configuration'=>array(
                    'desc'=>'Allow multiple selections')
            )),
        );
    }

    function parse($value) {
        return $this->to_php($value ?: null);
    }

    function to_database($value) {
        if (!is_array($value)) {
            $choices = $this->getChoices();
            if (isset($choices[$value]))
                $value = array($value => $choices[$value]);
        }
        if (is_array($value))
            $value = JsonDataEncoder::encode($value);

        return $value;
    }

    function to_php($value) {
        if (is_string($value))
            $value = JsonDataParser::parse($value) ?: $value;

        // CDATA table may be built with comma-separated key,value,key,value
        if (is_string($value) && strpos($value, ',')) {
            $values = array();
            $choices = $this->getChoices();
            $vals = array_map('trim', explode(',', $value));
            foreach ($vals as $V) {
                if (isset($choices[$V]))
                    $values[$V] = $choices[$V];
            }
            if (array_filter($values))
                $value = $values;
            elseif($vals)
                list($value) = $vals;

        }
        $config = $this->getConfiguration();
        if (!$config['multiselect'] && is_array($value) && count($value) < 2) {
            reset($value);
            $value = key($value);
        }
        return $value;
    }

    function toString($value) {
        if (!is_array($value))
            $value = $this->getChoice($value);
        if (is_array($value))
            return implode(', ', $value);
        return (string) $value;
    }

    function getKeys($value) {
        if (!is_array($value))
            $value = $this->getChoice($value);
        if (is_array($value))
            return implode(', ', array_keys($value));
        return (string) $value;
    }

    function asVar($value, $id=false) {
        $value = $this->to_php($value);
        return $this->toString($this->getChoice($value));
    }

    function getChanges() {
        $new = $this->to_database($this->getValue());
        $old = $this->to_database($this->answer ? $this->answer->getValue()
                : $this->get('default'));
        // Compare old and new
        return ($old == $new)
            ? false
            : array($old, $new);
    }

    function whatChanged($before, $after) {
        $B = (array) $before;
        $A = (array) $after;
        $added = array_diff($A, $B);
        $deleted = array_diff($B, $A);
        $added = array_map(array($this, 'display'), $added);
        $deleted = array_map(array($this, 'display'), $deleted);
        $added = array_filter($added);
        $deleted = array_filter($deleted);

        if ($added && $deleted) {
            $desc = sprintf(
                __('added <strong>%1$s</strong> and removed <strong>%2$s</strong>'),
                implode(', ', $added), implode(', ', $deleted));
        }
        elseif ($added) {
            $desc = sprintf(
                __('added <strong>%1$s</strong>'),
                implode(', ', $added));
        }
        elseif ($deleted) {
            $desc = sprintf(
                __('removed <strong>%1$s</strong>'),
                implode(', ', $deleted));
        }
        else {
            $desc = sprintf(
                __('changed from <strong>%1$s</strong> to <strong>%2$s</strong>'),
                $this->display($before), $this->display($after));
        }
        return $desc;
    }

    /*
     Return criteria to which the choice should be filtered by
     */
    function getCriteria() {
        $config = $this->getConfiguration();
        $criteria = array();
        if (isset($config['criteria']))
            $criteria = $config['criteria'];

        return $criteria;
    }

    function getChoice($value) {

        $choices = $this->getChoices();
        $selection = array();
        if ($value && is_array($value)) {
            $selection = $value;
        } elseif (isset($choices[$value]))
            $selection[$value] = $choices[$value];
        elseif (($v=$this->get('default')) && isset($choices[$v]))
            $selection[$v] = $choices[$v];

        return $selection;
    }

    function getChoices($verbose=false, $options=array()) {
        if ($this->_choices === null || $verbose) {
            // Allow choices to be set in this->ht (for configurationOptions)
            $this->_choices = $this->get('choices');
            if (!$this->_choices) {
                $this->_choices = array();
                $config = $this->getConfiguration();
                $choices = explode("\n", $config['choices']);
                foreach ($choices as $choice) {
                    // Allow choices to be key: value
                    list($key, $val) = explode(':', $choice, 2);
                    if ($val == null)
                        $val = $key;
                    $this->_choices[trim($key)] = trim($val);
                }
                // Add old selections if nolonger available
                // This is necessary so choices made previously can be
                // retained
                $values = ($a=$this->getAnswer()) ? $a->getValue() : array();
                if ($values && is_array($values)) {
                    foreach ($values as $k => $v) {
                        if (!isset($this->_choices[$k])) {
                            if ($verbose) $v .= ' (retired)';
                            $this->_choices[$k] = $v;
                        }
                    }
                }
            }
        }
        return $this->_choices;
    }

    function lookupChoice($value) {
        return null;
    }

    function getSearchMethods() {
        return array(
            'set' =>        __('has a value'),
            'nset' =>     __('does not have a value'),
            'includes' =>   __('includes'),
            '!includes' =>  __('does not include'),
        );
    }

    function getSearchMethodWidgets() {
        return array(
            'set' => null,
            'nset' => null,
            'includes' => array('ChoiceField', array(
                'choices' => $this->getChoices(),
                'configuration' => array('multiselect' => true),
            )),
            '!includes' => array('ChoiceField', array(
                'choices' => $this->getChoices(),
                'configuration' => array('multiselect' => true),
            )),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        $name = $name ?: $this->get('name');
        $val = $value;
        if ($value && is_array($value))
            $val = '"?(?<![0-9])'.implode('("|,|$)|"?(?<![0-9])', array_keys($value)).'("|,|$)';
        switch ($method) {
        case '!includes':
            return Q::not(array("{$name}__regex" => $val));
        case 'includes':
            return new Q(array("{$name}__regex" => $val));
        default:
            return parent::getSearchQ($method, $value, $name);
        }
    }

    function describeSearchMethod($method) {
        switch ($method) {
        case 'includes':
            return __('%s includes %s' /* includes -> if a list includes a selection */);
        case '!includes':
            return __('%s does not include %s' /* includes -> if a list includes a selection */);
        default:
            return parent::describeSearchMethod($method);
        }
    }

    function supportsQuickFilter() {
        return true;
    }

    function getQuickFilterChoices() {
        return $this->getChoices();
    }

    function applyQuickFilter($query, $qf_value, $name=false) {
        global $thisstaff;

        $field = new AssigneeChoiceField();
        //special assignment quick filters
        switch (true) {
            case ($qf_value == 'assigned'):
            case ($qf_value == '!assigned'):
                $result = $field->getSearchQ($qf_value, $qf_value);
                return $query->filter($result);
            case (strpos($qf_value, 's') !== false):
            case (strpos($qf_value, 't') !== false):
            case ($qf_value == 'M'):
            case ($qf_value == 'T'):
                $value = array($qf_value => $qf_value);
                $result = $field->getSearchQ('includes', $value);
                return $query->filter($result);
                break;
        }

        return $query->filter(array(
            $name ?: $this->get('name') => $qf_value,
        ));
    }
}


class NumericField extends FormField {

    function getSearchMethods() {
        return array(
            'equal' =>   __('Equal'),
            'greater' =>  __('Greater Than'),
            'less' =>  __('Less Than'),
        );
    }

    function getSearchMethodWidgets() {
        return array(
            'equal' => array('TextboxField', array(
                    'configuration' => array(
                        'validator' => 'number',
                        'size' => 6
                        ),
            )),
            'greater' => array('TextboxField', array(
                    'configuration' => array(
                        'validator' => 'number',
                        'size' => 6
                        ),
            )),
            'less' => array('TextboxField', array(
                    'configuration' => array(
                        'validator' => 'number',
                        'size' => 6
                        ),
            )),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        switch ($method) {
        case 'equal':
            return new Q(array(
                "{$name}__exact" => intval($value)
            ));
        break;
        case 'greater':
            return Q::any(array(
                "{$name}__gt" => intval($value)
            ));
        break;
        case 'less':
            return Q::any(array(
                "{$name}__lt" => intval($value)
            ));
        break;
        default:
            return parent::getSearchQ($method, $value, $name);
        }
    }
}


class ColorChoiceField extends FormField {
    static $widget = 'ColorPickerWidget';
}
