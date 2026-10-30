<?php
/*********************************************************************
    class.forms.field.php

    Formularios: el campo base.

    Parte de include/class.forms.php (frente 6 de docs/CODE_QUALITY.md):
    el archivo tenia 4.515 lineas y 37 clases. Se movieron las clases
    enteras, sin tocar su codigo.

    FormField, del que heredan todos los demas tipos de campo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/


class FormField {
    static $widget = false;

    var $ht = array(
        'label' => false,
        'required' => false,
        'default' => false,
        'configuration' => array(),
    );

    var $_form;
    var $_cform;
    var $_clean;
    var $_errors = array();
    var $_widget;
    var $answer;
    var $parent;
    var $presentation_only = false;

    static $types = array(
        /* @trans */ 'Basic Fields' => array(
            'text'  => array(   /* @trans */ 'Short Answer', 'TextboxField'),
            'memo' => array(    /* @trans */ 'Long Answer', 'TextareaField'),
            'thread' => array(  /* @trans */ 'Thread Entry', 'ThreadEntryField', false),
            'datetime' => array(/* @trans */ 'Date and Time', 'DatetimeField'),
            'timezone' => array(/* @trans */ 'Timezone', 'TimezoneField'),
            'phone' => array(   /* @trans */ 'Phone Number', 'PhoneField'),
            'bool' => array(    /* @trans */ 'Checkbox', 'BooleanField'),
            'choices' => array( /* @trans */ 'Choices', 'ChoiceField'),
            'files' => array(   /* @trans */ 'File Upload', 'FileUploadField'),
            'break' => array(   /* @trans */ 'Section Break', 'SectionBreakField'),
            'info' => array(    /* @trans */ 'Information', 'FreeTextField'),
        ),
    );
    static $more_types = array();
    static $uid = null;

    static function _uid() {
        return ++self::$uid;
    }

    function __construct($options=array()) {
        $this->ht = array_merge($this->ht, $options);
        if (!isset($this->ht['id']))
            $this->ht['id'] = self::_uid();
    }

    function __clone() {
        $this->_widget = null;
        $this->ht['id'] = self::_uid();
    }

    static function addFieldTypes($group, $callable) {
        static::$more_types[$group][] = $callable;
    }

    static function allTypes() {
        if (static::$more_types) {
            foreach (static::$more_types as $group => $entries)
                foreach ($entries as $c)
                    static::$types[$group] = array_merge(
                            static::$types[$group] ?? array(), call_user_func($c));

            static::$more_types = array();
        }
        return static::$types;
    }

    static function getFieldType($type) {
        foreach (static::allTypes() as $group=>$types)
            if (isset($types[$type]))
                return $types[$type];
    }

    function get($what, $default=null) {
        return array_key_exists($what, $this->ht)
            ? $this->ht[$what]
            : $default;
    }
    function set($field, $value) {
        $this->ht[$field] = $value;
    }

    function getId() {
        return $this->ht['id'];
    }

    /**
     * getClean
     *
     * Validates and cleans inputs from POST request. This is performed on a
     * field instance, after a DynamicFormSet / DynamicFormSection is
     * submitted via POST, in order to kick off parsing and validation of
     * user-entered data.
     */
    function getClean($validate=true) {
        if (!isset($this->_clean)) {
            $this->_clean = (isset($this->value))
                // XXX: The widget value may be parsed already if this is
                //      linked to dynamic data via ::getAnswer()
                ? $this->value : $this->parse($this->getWidget()->value);

            if ($vs = $this->get('cleaners')) {
                if (is_array($vs)) {
                    foreach ($vs as $cleaner)
                        if (is_callable($cleaner))
                            $this->_clean = call_user_func_array(
                                    $cleaner, array($this, $this->_clean));
                }
                elseif (is_callable($vs))
                    $this->_clean = call_user_func_array(
                            $vs, array($this, $this->_clean));
            }

            if (!isset($this->_clean) && ($d = $this->get('default')))
                $this->_clean = $d;

            if ($this->isVisible() && $validate)
                $this->validateEntry($this->_clean);
        }
        return $this->_clean;
    }

    function reset() {
        $this->value = $this->_clean = $this->_widget = null;
    }

    function getValue() {
        return $this->getWidget()->getValue();
    }

    function errors() {
        return $this->_errors;
    }

    function resetErrors() {
        $this->_errors = [];
        return !($this->_errors);
    }

    function addError($message, $index=false) {
        // Un campo puede validarse más de una vez en la misma petición (el
        // formulario del cliente se pide desde el ticket y otra vez al
        // crearlo). Sin esto, el mismo aviso salía dos y tres veces debajo
        // del campo, como si fueran tres problemas distintos.
        if ($index)
            $this->_errors[$index] = $message;
        elseif (!in_array($message, $this->_errors, true))
            $this->_errors[] = $message;

        // Update parent form errors for the field
        if ($this->_form)
            $this->_form->addError($this->errors(), $this->get('id'));
    }

    function isValidEntry() {
        $this->validateEntry();
        return count($this->_errors) == 0;
    }

    /**
     * validateEntry
     *
     * Validates user entry on an instance of the field on a dynamic form.
     * This is called when an instance of this field (like a TextboxField)
     * receives data from the user and that value should be validated.
     *
     * Parameters:
     * $value - (string) input from the user
     */
    function validateEntry($value) {
        if (!$value && count($this->_errors))
            return;

        # Validates a user-input into an instance of this field on a dynamic
        # form
        if ($this->get('required') && !$value && $this->hasData())
            $this->_errors[] = $this->getLocal('label')
                ? sprintf(__('%s is a required field'), $this->getLocal('label'))
                : __('This is a required field');

        # Perform declared validators for the field
        if ($vs = $this->get('validators')) {
            if (is_array($vs)) {
                foreach ($vs as $validator)
                    if (is_callable($validator))
                        $validator($this, $value);
            }
            elseif (is_callable($vs))
                $vs($this, $value);
        }
    }

    /**
     * isVisible
     *
     * If this field has visibility configuration, then it will parse the
     * constraints with the visibility configuration to determine if the
     * field is visible and should be considered for validation
     */
    function isVisible() {
        if ($this->get('visibility') instanceof VisibilityConstraint) {
            return $this->get('visibility')->isVisible($this);
        }
        return true;
    }

    /**
     * Check if the user has edit rights
     *
     */

    function isEditable($user=null) {

        // Internal editable flag used by internal forms e.g internal lists
        if (!$user && isset($this->ht['editable']))
            return $this->ht['editable'];

        if ($user instanceof Staff)
            $flag = DynamicFormField::FLAG_AGENT_EDIT;
        else
            $flag = DynamicFormField::FLAG_CLIENT_EDIT;

        return (($this->get('flags') & $flag) != 0);
    }


    /**
     * isStorable
     *
     * Indicate if this field data is storable locally (default).Some field's data
     * might beed to be stored elsewhere for optimization reasons at the
     * application level.
     *
     */

    function isStorable() {
        return (($this->get('flags') & DynamicFormField::FLAG_EXT_STORED) == 0);
    }

    function isRequired() {
        return $this->get('required');
    }

    /**
     * parse
     *
     * Used to transform user-submitted data to a PHP value. This value is
     * not yet considered valid. The ::validateEntry() method will be called
     * on the value to determine if the entry is valid. Therefore, if the
     * data is clearly invalid, return something like NULL that can easily
     * be deemed invalid in ::validateEntry(), however, can still produce a
     * useful error message indicating what is wrong with the input.
     */
    function parse($value) {
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * to_php
     *
     * Transforms the data from the value stored in the database to a PHP
     * value. The ::to_database() method is used to produce the database
     * valse, so this method is the compliment to ::to_database().
     *
     * Parameters:
     * $value - (string or null) database representation of the field's
     *      content
     */
    function to_php($value) {
        return $value;
    }

    /**
     * to_config
     *
     * Transform the data from the value to config form (as determined by
     * field). to_php is used for each field returned from
     * ::getConfigurationOptions(), and when the whole configuration is
     * built, to_config() is called and receives the config array. The array
     * should be returned, perhaps with modifications, and will be JSON
     * encoded and stashed in the database.
     */
    function to_config($value) {
        return $value;
    }

    /**
     * to_database
     *
     * Determines the value to be stored in the database. The database
     * backend for all fields is a text field, so this method should return
     * a text value or NULL to represent the value of the field. The
     * ::to_php() method will convert this value back to PHP.
     *
     * Paremeters:
     * $value - PHP value of the field's content
     */
    function to_database($value) {
        return $value;
    }

    /**
     * toString
     *
     * Converts the PHP value created in ::parse() or ::to_php() to a
     * pretty-printed value to show to the user. This is especially useful
     * for something like dates which are stored considerably different in
     * the database from their respective human-friendly versions.
     * Furthermore, this method allows for internationalization and
     * localization.
     *
     * Parametes:
     * $value - PHP value of the field's content
     */
    function toString($value) {
        return (string) $value;
    }

    function __toString() {
        return $this->toString($this->value);
    }

    /**
     * When data for this field is deleted permanently from some storage
     * backend (like a database), other associated data may need to be
     * cleaned as well. This hook allows fields to participate when the data
     * for a field is cleaned up.
     */
    function db_cleanup($field=false) {
    }

    /**
     * Returns an HTML friendly value for the data in the field.
     */
    function display($value) {
        return Format::htmlchars($this->toString($value ?: $this->value));
    }

    /**
     * Returns a value suitable for exporting to a foreign system. Mostly
     * useful for things like dates and phone numbers which should be
     * formatted using a standard when exported
     */
    function export($value) {
        return $this->toString($value);
    }

    /**
     * Fetch a value suitable for embedding the value of this field in an
     * email template. Reference implementation uses ::to_php();
     */
    function asVar($value, $id=false) {
        return $this->to_php($value, $id);
    }

    /**
     * Fetch the var type used with the email templating system's typeahead
     * feature. This helps with variable expansion if supported by this
     * field's ::asVar() method. This method should return a valid classname
     * which implements the `TemplateVariable` interface.
     */
    function asVarType() {
        return false;
    }

    /**
     * Describe the difference between the to two values. Note that the
     * values should be passed through ::parse() or to_php() before
     * utilizing this method.
     */
    function whatChanged($before, $after) {
        if ($before)
            $desc = __('changed from <strong>%1$s</strong> to <strong>%2$s</strong>');
        else
            $desc = __('set to <strong>%2$s</strong>');
        return sprintf($desc, $this->display($before), $this->display($after));
    }

    /**
     * Convert the field data to something matchable by filtering. The
     * primary use of this is for ticket filtering.
     */
    function getFilterData() {
        return $this->toString($this->getClean());
    }

    /**
     * Fetches a value that represents this content in a consistent,
     * searchable format. This is used by the search engine system and
     * backend.
     */
    function searchable($value) {
        return Format::searchable($this->toString($value));
    }

    function getKeys($value) {
        return $this->to_database($value);
    }

    /**
     * Fetches a list of options for searching. The values returned from
     * this method are passed to the widget's `::render()` method so that
     * the widget can be affected by this setting. For instance, date fields
     * might have a 'between' search option which should trigger rendering
     * of two date widgets for search results.
     */
    function getSearchMethods() {
        return array(
            'set' =>        __('has a value'),
            'nset' =>       __('does not have a value'),
            'equal' =>      __('is'),
            'nequal' =>     __('is not'),
            'contains' =>   __('contains'),
            'match' =>      __('matches'),
        );
    }

    function getSearchMethodWidgets() {
        return array(
            'set' => null,
            'nset' => null,
            'equal' => array('TextboxField', array('configuration' => array('size' => 40))),
            'nequal' => array('TextboxField', array('configuration' => array('size' => 40))),
            'contains' => array('TextboxField', array('configuration' => array('size' => 40))),
            'match' => array('TextboxField', array(
                'placeholder' => __('Valid regular expression'),
                'configuration' => array('size'=>30),
                'validators' => function($self, $v) {
                    if (false === @preg_match($v, ' ')
                        && false === @preg_match("/$v/", ' '))
                        $self->addError(__('Cannot compile this regular expression'));
                })),
        );
    }

    /**
     * This is used by the searching system to build a query for the search
     * engine. The function should return a criteria listing to match
     * content saved by the field by the `::to_database()` function.
     */
    function getSearchQ($method, $value, $name=false) {
        $criteria = array();
        $Q = new Q();
        $name = $name ?: $this->get('name');
        switch ($method) {
            case 'nset':
                $Q->negate();
            case 'set':
                $criteria[$name . '__isnull'] = false;
                break;

            case 'nequal':
                $Q->negate();
            case 'equal':
                $criteria[$name] = $value;
                break;

            case 'contains':
                $criteria[$name . '__contains'] = $value;
                break;

            case 'match':
                $criteria[$name . '__regex'] = $value;
                break;
        }
        return $Q->add($criteria);
    }

    function getSearchWidget($method) {
        $methods = $this->getSearchMethodWidgets();
        $info = $methods[$method];
        if (is_array($info)) {
            $class = $info[0];
            return new $class($info[1]);
        }
        return $info;
    }

    function describeSearchMethod($method) {
        switch ($method) {
        case 'set':
            return __('%s has a value');
        case 'nset':
            return __('%s does not have a value');
        case 'equal':
            return __('%s is %s' /* describes an equality */);
        case 'nequal':
            return __('%s is not %s' /* describes an inequality */);
        case 'contains':
            return __('%s contains "%s"');
        case 'match':
            return __('%s matches pattern %s');
        case 'includes':
            return __('%s in (%s)');
        case '!includes':
            return __('%s not in (%s)');
        }
    }

    function describeSearch($method, $value, $name=false) {
        $name = $name ?: $this->get('name');
        $desc = $this->describeSearchMethod($method);
        switch ($method) {
            case 'set':
            case 'nset':
                return sprintf($desc, $name);
            default:
                 return sprintf($desc, $name, $this->toString($value));
        }
    }

    function addToQuery($query, $name=false) {
        return $query->values($name ?: $this->get('name'));
    }

    /**
     * Similary to to_php() and parse(), except a row from a queryset is
     * passed. The value returned should be what would be retured from
     * parse() or to_php()
     */
    function from_query($row, $name=false) {
        return $row[$name ?: $this->get('name')];
    }

    /**
     * If the field can be used in a quick filter. To be used, it should
     * also implement getQuickFilterChoices() which should return a list of
     * choices to appear in a quick filter drop-down
     */
    function supportsQuickFilter() {
        return false;
    }

    /**
     * Fetch a keyed array of quick filter choices. The keys should be
     * passed later to ::applyQuickFilter() to apply the quick filter to a
     * query. The values should be localized titles for the choices.
     */
    function getQuickFilterChoices() {
        return array();
    }

    /**
     * Apply a quick filter selection of this field to the query. The
     * modified query should be returned. Optionally, the orm path / field
     * name can be passed.
     */
    function applyQuickFilter($query, $choice, $name=false) {
        return $query;
    }

    function getLabel() { return $this->get('label'); }

    function getSortKeys($path) {
        return array($path);
    }

    function getOrmPath($name=false, $query=null) {
        return CustomQueue::getOrmPath($name ?:$this->get('name'), $query);
    }

    function applyOrderBy($query, $reverse=false, $name=false) {
        $col = sprintf('%s%s',
                $reverse ? '-' : '',
                $this->getOrmPath($name, $query));
        return $query->order_by($col);
    }

    /**
     * getImpl
     *
     * Magic method that will return an implementation instance of this
     * field based on the simple text value of the 'type' value of this
     * field instance. The list of registered fields is determined by the
     * global get_dynamic_field_types() function. The data from this model
     * will be used to initialize the returned instance.
     *
     * For instance, if the value of this field is 'text', a TextField
     * instance will be returned.
     */
    function getImpl($parent=null) {
        // Allow registration with ::addFieldTypes and delayed calling
        $type = static::getFieldType($this->get('type'));
        $clazz = $type[1];
        $inst = new $clazz($this->ht);
        $inst->parent = $parent;
        $inst->setForm($this->_form);
        return $inst;
    }

    function __call($what, $args) {
        // XXX: Throw exception if $this->parent is not set
        if (!$this->parent)
            throw new Exception(sprintf(__('%s: Call to undefined function'),
                $what));
        // BEWARE: DynamicFormField has a __call() which will create a new
        //      FormField instance and invoke __call() on it or bounce
        //      immediately back
        return call_user_func_array(
            array($this->parent, $what), $args);
    }

    function getAnswer() { return $this->answer; }
    function setAnswer($ans) { $this->answer = $ans; }

    function setValue($value) {
        $this->reset();
        $this->getWidget()->value = $value;
    }

    /**
     * Fetch a pseudo-random id for this form field. It is used when
     * rendering the widget in the @name attribute emitted in the resulting
     * HTML. The form element is based on the form id, field id and name,
     * and the current user's session id. Therefore, the same form fields
     * will yield differing names for different users. This is used to ward
     * off bot attacks as it makes it very difficult to predict and
     * correlate the form names to the data they represent.
     */
    function getFormName() {
        $default = $this->get('name') ?: $this->get('id');
        if ($this->_form && is_numeric($fid = $this->_form->getFormId()))
            return substr(md5(
                session_id() . "-form-field-id-$fid-$default-" . SECRET_SALT), -14);
        elseif (is_numeric($this->get('id')))
            return substr(md5(
                session_id() . '-field-id-'.$this->get('id') . '-' . SECRET_SALT), -16);

        return $default;
    }

    function getFormNames() {

        // All possible names - this is important for inline data injection
        $names = array_filter([
                'hash' => $this->getFormName(),
                'name' => $this->get('name'),
                'id' => $this->get('id')]);

        // Force pseudo-random name for Dynamicforms on POST (Web Tickets)
        if (0 && $_POST
                && !defined('APICALL')
                && isset($this->ht['form'])
                && ($this->ht['form'] instanceof DynamicForm))
            return [$names['hash']];

        return $names;
    }

    function setForm($form) {
        $this->_form = $form;
    }
    function getForm() {
        return $this->_form;
    }
    /**
     * Returns the data source for this field. If created from a form, the
     * data source from the form is returned. Otherwise, if the request is a
     * POST, then _POST is returned.
     */
    function getSource() {
        if ($this->_form)
            return $this->_form->getSource();
        elseif ($_SERVER['REQUEST_METHOD'] == 'POST')
            return $_POST;
        else
            return array();
    }

    function render($options=array()) {
        $rv = $this->getWidget()->render($options);
        if ($v = $this->get('visibility')) {
            $v->emitJavascript($this);
        }
        return $rv;
    }

    function renderExtras($options=array()) {
        return;
    }

    function getMedia() {
        $widget = $this->getWidget();
        return $widget::$media;
    }

    function getConfigurationOptions() {
        return array();
    }

    /**
     * getConfiguration
     *
     * Loads configuration information from database into hashtable format.
     * Also, the defaults from ::getConfigurationOptions() are integrated
     * into the database-backed options, so that if options have not yet
     * been set or a new option has been added and not saved for this field,
     * the default value will be reflected in the returned configuration.
     */
    function getConfiguration() {
        if (!isset($this->_config)) {
            $this->_config = $this->get('configuration');
            if (is_string($this->_config))
                $this->_config = JsonDataParser::parse($this->_config);
            elseif (!$this->_config)
                $this->_config = array();
            foreach ($this->getConfigurationOptions() as $name=>$field)
                if (!isset($this->_config[$name]))
                    $this->_config[$name] = $field->get('default');
        }
        return $this->_config;
    }

    /**
     * If the [Config] button should be shown to allow for the configuration
     * of this field
     */
    function isConfigurable() {
        return true;
    }

    /**
     * Field type is changeable in the admin interface
     */
    function isChangeable() {
        return true;
    }

    /**
     * Field does not contain data that should be saved to the database. Ie.
     * non data fields like section headers
     */
    function hasData() {
        return true;
    }

    /**
     * Returns true if the field/widget should be rendered as an entire
     * block in the target form.
     */
    function isBlockLevel() {
        return false;
    }

    /**
     * Fields should not be saved with the dynamic data. It is assumed that
     * some static processing will store the data elsewhere.
     */
    function isPresentationOnly() {
        return $this->presentation_only;
    }

    /**
     * Indicates if the field places data in the `value_id` column. This
     * is currently used by the materialized view system
     */
    function hasIdValue() {
        return false;
    }

    /**
     * Indicates if the field has subfields accessible via getSubFields()
     * method. Useful for filter integration. Should connect with
     * getFilterData()
     */
    function hasSubFields() {
        return false;
    }
    function getSubFields() {
        return null;
    }

    function getConfigurationForm($source=null) {
        if (!$this->_cform) {
            $type = static::getFieldType($this->get('type'));
            $clazz = $type[1];
            $T = new $clazz($this->ht);
            $config = $this->getConfiguration();
            $this->_cform = new SimpleForm($T->getConfigurationOptions(), $source);
            if (!$source) {
                foreach ($this->_cform->getFields() as $name=>$f) {
                    if ($config && isset($config[$name]))
                        $f->value = $config[$name];
                    elseif ($f->get('default'))
                        $f->value = $f->get('default');
                }
            }
        }
        return $this->_cform;
    }

    function configure($prop, $value) {
        $this->getConfiguration();
        $this->_config[$prop] = $value;
    }

    function getWidget($widgetClass=false) {
        if (!static::$widget)
            throw new Exception(__('Widget not defined for this field'));
        if (!isset($this->_widget)) {
            $wc = $widgetClass ?: $this->get('widget') ?: static::$widget;
            $this->_widget = new $wc($this);
            $this->_widget->parseValue();
        }
        return $this->_widget;
    }

    function getSelectName() {
        $name = $this->get('name') ?: 'field_'.$this->get('id');
        if ($this->hasIdValue())
            $name .= '_id';

        return $name;
    }

    function getTranslateTag($subtag) {
        return _H(sprintf('field.%s.%s%s', $subtag, $this->get('id'),
            $this->get('form_id') ? '' : '*internal*'));
    }

    function getLocal($subtag, $default=false) {
        $tag = $this->getTranslateTag($subtag);
        $T = CustomDataTranslation::translate($tag);
        return $T != $tag ? $T : ($default ?: $this->get($subtag));
    }

    function getEditForm($source=null) {
        $fields = array(
                'field' => $this,
                'comments' => new TextareaField(array(
                        'id' => 2,
                        'label'=> '',
                        'required' => false,
                        'default' => '',
                        'configuration' => array(
                            'html' => true,
                            'size' => 'small',
                            'placeholder' => __('Optional reason for the update'),
                            )
                        ))
                );

        return new SimpleForm($fields, $source);
    }

    function getChanges() {
        $new = $this->getValue();
        $old = $this->answer ? $this->answer->getValue() : $this->get('default');
        return ($old != $new) ? array($this->to_database($old), $this->to_database($new)) : false;
    }


    function save() {

        if (!($changes=$this->getChanges()))
            return true;

        if (!($a = $this->answer))
            return false;

        $val = $changes[1];
        if (is_array($val)) {
            $a->set('value', $val[0]);
            $a->set('value_id', $val[1]);
        } else {
            $a->set('value', $val);
        }

        if (!$a->save(true))
            return false;

        $this->_clean = $this->_widget = null;
        return $this->parent->save();
    }


    static function init($config) {
        return new Static($config);
    }
}
