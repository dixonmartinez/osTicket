<?php
/*********************************************************************
    class.forms.widget.php

    Widgets de campo: lo que dibuja cada campo del formulario (caja de
    texto, selector, fecha, subida de archivos…).

    Extraído de class.forms.php (frente 6 de docs/CODE_QUALITY.md). Se
    incluye desde class.forms.php después de FormField, porque todos los
    widgets lo usan, y conserva el orden interno del original.

    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/


class InlineFormWidget extends Widget {
    function render($mode=false) {
        $form = $this->field->getInlineForm();
        if (!$form)
            return;
        // Handle first-step edits -- load data from $this->value
        if ($form instanceof DynamicForm && !$form->getSource())
            $form = $form->getForm($this->value);
        $inc = ($mode == 'client') ? CLIENTINC_DIR : STAFFINC_DIR;
        include $inc . 'templates/inline-form.tmpl.php';
    }

    function getValue() {
        $data = $this->field->getSource();
        if (!$data)
            return null;
        $form = $this->field->getInlineForm($data);
        if (!$form)
            return null;
        return $form->getClean();
    }
}

class Widget {
    static $media = null;

    function __construct($field) {
        $this->field = $field;
        $this->name = $field->getFormName();
        $this->id = '_' . $this->name;
    }

    function parseValue() {
        $this->value = $this->getValue();
        if (!isset($this->value) && is_object($this->field->getAnswer()))
            $this->value = $this->field->getAnswer()->getValue();
        if (!isset($this->value) && isset($this->field->value))
            $this->value = $this->field->value;
    }

    function getValue() {
        $data = $this->field->getSource();
        foreach ($this->field->getFormNames() as $name)
            if (isset($data[$name]))
                return $data[$name];

        return null;
    }

    /**
     * getJsValueGetter
     *
     * Used with the dependent fields feature, this function should return a
     * single javascript expression which can be used in a larger expression
     * (<> == true, where <> is the result of this function). The %s token
     * will be replaced with a jQuery variable representing this widget.
     */
    function getJsValueGetter($id='%s') {
        return sprintf('%s.val()', $id);
    }

    /**
     * getJsComparator
     *
     * Used with the dependent fields to get comparison expression
     *
     */
    function getJsComparator($value, $id) {

        if (strpos($value, '|') !== false)
            return sprintf('$.inArray(%s, %s) !== -1',
                   $this->getJsValueGetter($id),
                   JsonDataEncoder::encode(explode('|', $value)));

        return sprintf('%s == %s',
                $this->getJsValueGetter($id),
                JsonDataEncoder::encode($value));
    }
}

class TextboxWidget extends Widget {
    static $input_type = 'text';

    function render($options=array(), $extraConfig=false) {
        $config = $this->field->getConfiguration();
        if (is_array($extraConfig)) {
            foreach ($extraConfig as $k=>$v)
                if (!isset($config[$k]) || !$config[$k])
                    $config[$k] = $v;
        }

        // Input attributes
        $attrs = array();
        foreach ($config as $k => $v) {
            switch ($k) {
                case 'autocomplete':
                    if (is_numeric($v))
                        $v = $v ? 'on' : 'off';
                    $attrs[$k] = '"'.$v.'"';
                    break;
                case 'disabled':
                    $attrs[$k] = '"disabled"';
                    break;
                case 'translatable':
                    if ($v)
                        $attrs['data-translate-tag'] =  '"'.$v.'"';
                    break;
                case 'length':
                    $k = 'maxlength';
                case 'size':
                case 'maxlength':
                    if ($v && is_numeric($v))
                        $attrs[$k] = '"'.$v.'"';
                    break;
                case 'class':
                case 'classes':
                    $attrs['class'] = '"'.$v.'"';
                    break;
                case 'inputmode':
                case 'pattern':
                    $attrs[$k] = '"'.$v.'"';
                    break;
            }
        }
        // autofocus
        $autofocus = '';
        if (isset($config['autofocus']))
            $autofocus = 'autofocus';
        // placeholder
        $attrs['placeholder'] = sprintf('"%s"',
                Format::htmlchars($this->field->getLocal('placeholder',
                    $config['placeholder'])));
        $type = static::$input_type;
        $types = array(
            'email' => 'email',
            'phone' => 'tel',
        );
        if ($type == 'text' && isset($types[$config['validator']]))
            $type = $types[$config['validator']];
        ?>
        <input type="<?php echo $type; ?>"
            id="<?php echo $this->id; ?>"
            <?php echo $autofocus .' '.Format::array_implode('=', ' ',
                    array_filter($attrs)); ?>
            name="<?php echo $this->name; ?>"
            value="<?php echo Format::htmlchars($this->value, true); ?>"/>
        <?php
    }
}


class TextboxSelectionWidget extends TextboxWidget {
    //TODO: Support multi-input e.g comma separated inputs
    function render($options=array(), $extraConfig=array()) {

        if ($this->value && is_array($this->value))
            $this->value = current($this->value);

        parent::render($options);
    }

    function getValue() {

        $value = parent::getValue();
        if ($value && ($item=$this->field->lookupChoice((string) $value)))
            $value = $item;

        return $value;
    }
}

class PasswordWidget extends TextboxWidget {
    static $input_type = 'password';

    function render($mode=false, $extra=false) {
        $extra = array();
        if (isset($this->field->value)) {
            $extra['placeholder'] = str_repeat('•',
                    strlen($this->field->value));
        }
        return parent::render($mode, $extra);
    }

    function parseValue() {
        parent::parseValue();
        // Show empty box unless failed POST
        if ($_SERVER['REQUEST_METHOD'] != 'POST'
                || !$this->field->getForm()->isValid())
            $this->value = '';
    }
}

class TextareaWidget extends Widget {
    function render($options=array()) {
        $config = $this->field->getConfiguration();
        // process textarea attributes
        $attrs = array();
        foreach ($config as $k => $v) {
            switch ($k) {
                case 'rows':
                case 'cols':
                case 'length':
                case 'maxlength':
                    if ($v && is_numeric($v))
                        $attrs[$k] = '"'.$v.'"';;
                    break;
                case 'context':
                    $attrs['data-root-context'] =  '"'.$v.'"';
                    break;
                case 'class':
                    // This might conflict with html attr below
                    $attrs[$k] = '"'.$v.'"';
                    break;
                case 'html':
                    if ($v) {
                        // Con barra visible (`air` la esconde hasta que se
                        // selecciona texto: el campo parecía un textarea
                        // plano) y corta, que la celda de un formulario no da
                        // para los 23 botones de la barra completa.
                        // `ost-note-editor` marca la superficie para que el
                        // tema oscuro la pinte como el resto de la ficha en
                        // vez del «papel» claro de los correos.
                        $class = array('richtext', 'ost-note-editor', 'ost-memo-field');
                        $class[] = @$config['size'] ?: 'small';
                        $attrs['class'] =  '"'.implode(' ', $class).'"';
                        $this->value = Format::viewableImages($this->value);
                    }
                    break;
            }
        }
        // placeholder
        $attrs['placeholder'] = sprintf('"%s"',
                Format::htmlchars($this->field->getLocal('placeholder',
                $config['placeholder'])));
        ?>
        <span style="display:inline-block;width:100%">
        <textarea <?php echo Format::array_implode('=', ' ',
                array_filter($attrs)); ?>
            id="<?php echo $this->id; ?>"
            name="<?php echo $this->name; ?>"><?php
                echo Format::htmlchars($this->value, ($config['html']));
            ?></textarea>
        </span>
        <?php
    }

    function parseValue() {
        parent::parseValue();
        if (isset($this->value)) {
            $value = $this->value;
            $config = $this->field->getConfiguration();
            // Trim empty spaces based on text input type.
            // Preserve original input if not empty.
            if ($config['html'])
                $this->value = trim($value, " <>br/\t\n\r") ? $value : '';
            else
                $this->value = trim($value) ? $value : '';
        }
    }

}

class PhoneNumberWidget extends Widget {
    function render($options=array()) {
        $config = $this->field->getConfiguration();
        list($phone, $ext) = explode("X", $this->value);
        ?>
        <input id="<?php echo $this->id; ?>" type="tel" name="<?php echo $this->name; ?>" value="<?php
        echo Format::htmlchars($phone); ?>"/><?php
        // Allow display of extension field even if disabled if the phone
        // number being edited has an extension
        if ($ext || $config['ext']) { ?> <?php echo __('Ext'); ?>:
            <input type="text" name="<?php
            echo $this->name; ?>-ext" value="<?php echo Format::htmlchars($ext);
                ?>" size="5"/>
        <?php }
    }

    function getValue() {
        $data = $this->field->getSource();
        $base = parent::getValue();
        if ($base === null)
            return $base;
        $ext = $data["{$this->name}-ext"];
        // NOTE: 'X' is significant. Don't change it
        if ($ext) $ext = 'X'.$ext;
        return $base . $ext;
    }
}

class ChoicesWidget extends Widget {
    function render($options=array()) {
        $mode = null;
        if (isset($options['mode']))
            $mode = $options['mode'];
        elseif (isset($this->field->options['render_mode']))
            $mode = $this->field->options['render_mode'];

        if ($mode == 'view') {
            $val = (string) $this->field;
            echo sprintf('<span id="field_%s" %s >%s</span>', $this->id,
                    $val ? '': 'class="faded"',
                    $val ?: __('None'));
            return;
        }

        $config = $this->field->getConfiguration();
        if ($mode == 'search') {
            $config['multiselect'] = true;
        }

        // Determine the value for the default (the one listed if nothing is
        // selected)
        $choices = $this->field->getChoices(true, $options);
        $prompt = ($config['prompt'])
            ? $this->field->getLocal('prompt', $config['prompt'])
            : __('Select'
            /* Used as a default prompt for a custom drop-down list */);

        $have_def = false;
        // We don't consider the 'default' when rendering in 'search' mode
        if (!strcasecmp($mode, 'search')) {
            $def_val = $prompt;
        } else {
            $showdefault = true;
            if ($mode != 'create')
                 $showdefault = false;
            $def_key = $this->field->get('default');
            if (!$def_key && isset($config['default']))
                $def_key = $config['default'];
            if (is_array($def_key))
                $def_key = key($def_key);
            $have_def = isset($choices[$def_key]);
            $def_val = ($have_def && !$showdefault) ? $choices[$def_key] : $prompt;
        }

        $values = $this->value;
        if (!is_array($values) && isset($values)) {
            $values = array($values => $this->field->getChoice($values));
        }

        if (!is_array($values))
            $values = $have_def ? array($def_key => $choices[$def_key]) : array();

        if (isset($config['classes']))
            $classes = 'class="'.$config['classes'].'"';
        ?>
        <select name="<?php echo $this->name; ?>[]"
            <?php echo implode(' ', array_filter(array($classes))); ?>
            id="<?php echo $this->id; ?>"
            <?php if (isset($config['data']))
              foreach ($config['data'] as $D=>$V)
                echo ' data-'.$D.'="'.Format::htmlchars($V).'"';
            ?>
            data-placeholder="<?php echo Format::htmlchars($prompt); ?>"
            <?php if ($config['disabled'])
                echo ' disabled="disabled"'; ?>
            <?php if ($config['multiselect'])
                echo ' multiple="multiple"'; ?>>
            <?php if ($showdefault || (!$have_def && !$config['multiselect'])) { ?>
            <option value="<?php echo $showdefault ? '' : $def_key; ?>">&mdash; <?php
                echo $def_val; ?> &mdash;</option>
<?php
        }
        $this->emitChoices($choices, $values, $have_def, $def_key); ?>
        </select>
        <?php
        if ($config['multiselect']) {
         ?>
        <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
        $(function() {
            $("#<?php echo $this->id; ?>")
            .select2({'minimumResultsForSearch':10, 'width': '350px'});
        });
        </script>
       <?php
        }
    }

    function emitChoices($choices, $values=array(), $have_def=false, $def_key=null) {
        reset($choices);
        if (is_array(current($choices)) || current($choices) instanceof Traversable)
            return $this->emitComplexChoices($choices, $values, $have_def, $def_key);

        foreach ($choices as $key => $name) {
            if (!$have_def && $key === $def_key)
                continue; ?>
            <option value="<?php echo $key; ?>" <?php
                if (isset($values[$key])) echo 'selected="selected"';
            ?>><?php echo Format::htmlchars($name); ?></option>
        <?php
        }
    }

    function emitComplexChoices($choices, $values=array(), $have_def=false, $def_key=null) {
        foreach ($choices as $label => $group) {
            if (!count($group)) continue;
            ?>
            <optgroup label="<?php echo $label; ?>"><?php
            foreach ($group as $key => $name) {
                if (!$have_def && $key == $def_key)
                    continue; ?>
            <option value="<?php echo $key; ?>" <?php
                if (isset($values[$key])) echo 'selected="selected"';
            ?>><?php echo Format::htmlchars($name); ?></option>
<?php       } ?>
            </optgroup><?php
        }
    }

    function getValue() {

        if (!($value = parent::getValue()))
            return null;

        if ($value && !is_array($value))
            $value = array($value);

        // Assume multiselect
        $values = array();
        $choices = $this->field->getChoices();

        if ($choices && is_array($value)) {
            // Complex choices
            if (is_array(current($choices))
                    || current($choices) instanceof Traversable) {
                foreach ($choices as $label => $group) {
                     foreach ($group as $k => $v)
                        if (in_array($k, $value))
                            $values[$k] = $v;
                }
            } else {
                foreach($value as $k => $v) {
                    if (isset($choices[$v]))
                        $values[$v] = $choices[$v];
                    elseif (($i=$this->field->lookupChoice($v)))
                        $values += $i;
                    elseif (!$k && $v)
                      return $v;
                }
            }
        }

        return $values;
    }

    function getJsValueGetter($id='%s') {
        return sprintf('%s.find(":selected").val()', $id);
    }

}

/**
 * A widget for the ChoiceField which will render a list of radio boxes or
 * checkboxes depending on the value of $config['multiple']. Complex choices
 * are also supported and will be rendered as divs.
 */
class BoxChoicesWidget extends Widget {
    function render($options=array()) {
        $this->emitChoices($this->field->getChoices());
    }

    function emitChoices($choices) {
      static $uid = 1;

      if (!isset($this->value))
          $this->value = $this->field->get('default');
      $config = $this->field->getConfiguration();
      $type = $config['multiple'] ? 'checkbox' : 'radio';

      $classes = array('checkbox');
      if (isset($config['classes']))
          $classes = array_merge($classes, (array) $config['classes']);

      foreach ($choices as $k => $v) {
          if (is_array($v)) {
              $this->renderSectionBreak($k);
              $this->emitChoices($v);
              continue;
          }
          $id = sprintf("%s-%s", $this->id, $uid++);
?>
        <label class="<?php echo implode(' ', $classes); ?>"
            for="<?php echo $id; ?>">
        <input id="<?php echo $id; ?>" type="<?php echo $type; ?>"
            name="<?php echo $this->name; ?>[]" <?php
            if ($this->value[$k]) echo 'checked="checked"'; ?> value="<?php
            echo Format::htmlchars($k); ?>"/>
        <?php
        if ($v) {
            echo Format::viewableImages($v);
        } ?>
        </label>
<?php   }
    }

    function renderSectionBreak($label) { ?>
        <div><?php echo Format::htmlchars($label); ?></div>
<?php
    }

    function getValue() {
        $data = $this->field->getSource();
        if (count($data)) {
            if (!isset($data[$this->name]))
                return array();
            return $this->collectValues($data[$this->name], $this->field->getChoices());
        }
        return parent::getValue();
    }

    function collectValues($data, $choices) {
        $value = array();
        foreach ($choices as $k => $v) {
            if (is_array($v))
                $value = array_merge($value, $this->collectValues($data, $v));
            elseif (@in_array($k, $data))
                $value[$k] = $v;
        }
        return $value;
    }
}

/**
 * An extension to the BoxChoicesWidget which will render complex choices in
 * tabs.
 */
class TabbedBoxChoicesWidget extends BoxChoicesWidget {
    function render($options=array()) {
        $tabs = array();
        foreach ($this->field->getChoices() as $label=>$group) {
            if (is_array($group)) {
                $tabs[$label] = $group;
            }
            else {
                $this->emitChoices(array($label=>$group));
            }
        }
        if ($tabs) {
            ?>
            <div>
            <ul class="alt tabs">
<?php       $i = 0;
            foreach ($tabs as $label => $group) {
                $active = $i++ == 0; ?>
                <li <?php if ($active) echo 'class="active"';
                  ?>><a href="#<?php echo sprintf('%s-%s', $this->name, Format::slugify($label));
                  ?>"><?php echo Format::htmlchars($label); ?></a></li>
<?php       } ?>
            </ul>
<?php       $i = 0;
            foreach ($tabs as $label => $group) {
                $first = $i++ == 0; ?>
                <div class="tab_content <?php if (!$first) echo 'hidden'; ?>" id="<?php
                  echo sprintf('%s-%s', $this->name, Format::slugify($label));?>">
<?php           $this->emitChoices($group); ?>
                </div>
<?php       } ?>
            </div>
<?php   }
    }
}

/**
* TimezoneWidget extends ChoicesWidget to add auto-detect and select2 search
* options
*
**/
class TimezoneWidget extends ChoicesWidget {

    function render($options=array()) {
        parent::render($options);
        $config = $this->field->getConfiguration();
        if (@$config['autodetect']) {
        ?>
        <button type="button" class="action-button" data-call="ostAutodetectTimezone"
            data-timezone-target="#<?php echo $this->id; ?>"
            data-jstz-src="<?php echo ROOT_PATH; ?>js/jstz.min.js"
            style="vertical-align:middle">
            <i class="icon-map-marker"></i> <?php echo __('Auto Detect'); ?>
        </button>
        <?php
        } ?>
        <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
            $(function() {
                $('#<?php echo $this->id; ?>').select2({
                    allowClear: true,
                    width: '300px'
                });
            });
        </script>
      <?php
    }
}

class CheckboxWidget extends Widget {
    function __construct($field) {
        parent::__construct($field);
        $this->name = '_field-checkboxes';
    }

    function render($options=array()) {
        $config = $this->field->getConfiguration();
        if (!isset($this->value))
            $this->value = $this->field->get('default');
        $classes = array('checkbox');
        if (isset($config['classes']))
            $classes = array_merge($classes, (array) $config['classes']);
        ?>
        <label class="<?php echo implode(' ', $classes); ?>">
        <input id="<?php echo $this->id; ?>"
            type="checkbox" name="<?php echo $this->name; ?>[]" <?php
            if ($this->value) echo 'checked="checked"'; ?> value="<?php
            echo $this->field->get('id'); ?>"/>
        <?php
        if ($config['desc']) {
            echo Format::viewableImages($config['desc']);
        } ?>
        </label>
<?php
    }

    function getValue() {
        $data = $this->field->getSource();
        if (is_array($data)) {
            if (isset($data[$this->name]))
                return @in_array($this->field->get('id'),
                        $data[$this->name]);
            // initial value set on source
            if (isset($data[$this->field->get('id')]))
                return $data[$this->field->get('id')];
        }

        if (!$data && isset($this->value))
            return $this->value;


        return parent::getValue();
    }

    function getJsValueGetter($id='%s') {
        return sprintf('%s.is(":checked")', $id);
    }

}

class DatetimePickerWidget extends Widget {

    function render($options=array()) {
        global $cfg;

        $config = $this->field->getConfiguration();
        $timezone = $this->field->getTimezone();
        $dateFormat = $cfg->getDateFormat(true);
        $timeFormat = $cfg->getTimeFormat(true);
        if (!isset($this->value) && ($default=$this->field->get('default')))
            $this->value = $default;

        if ($this->value == 0)
            $this->value = '';

        if ($this->value) {
            $datetime = Format::parseDateTime($this->value);
            if ($config['time'])
                // Convert to user's timezone for update.
                $datetime->setTimezone($timezone);

            // Get formatted date
            $this->value = Format::date($datetime->getTimestamp(), false,
                        false, $timezone ? $timezone->getName() : 'UTC');
            // Get formatted time
            if ($config['time']) {
                 $this->value .=' '.Format::time($datetime->getTimestamp(),
                         false, $timeFormat, $timezone ?
                         $timezone->getName() : 'UTC');
            }

        } else {
            // For timezone display purposes
            $datetime = new DateTime('now');
            $datetime->setTimezone($timezone);
        }
        ?>
        <input type="text" name="<?php echo $this->name; ?>"
            id="<?php echo $this->id; ?>" style="display:inline-block;width:auto"
            value="<?php echo $this->value; ?>"
            size="<?php $config['time'] ? 20 : 12; ?>"
            autocomplete="off" class="dp" />
        <?php
        // Timezone hint
        // Show timzone hit by default but allow field to turn it off.
        $showtimezone = true;
        if (isset($config['showtimezone']))
            $showtimezone = $config['showtimezone'];

        if ($datetime && $showtimezone) {
            echo sprintf('&nbsp;<span class="faded">(<a href="#"
                        data-placement="top" data-toggle="tooltip"
                        title="%s">%s</a>)</span>',
                    $datetime->getTimezone()->getName(),
                    $datetime->format('T'));
        }
        ?>
        <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
            $(function() {
                $('input[name="<?php echo $this->name; ?>"]').<?php echo
                $config['time'] ? 'datetimepicker':'datepicker';?>({
                    <?php
                    if ($dt=$this->field->getMinDateTime())
                        echo sprintf("minDate: new Date(%s),\n", $dt->format('U')*1000);
                    if ($dt=$this->field->getMaxDateTime())
                        echo sprintf("maxDate: new Date(%s),\n", $dt->format('U')*1000);
                    elseif (!$config['future'])
                        echo "maxDate: new Date().getTime(),\n";

                    // Set time options
                    if ($config['time']) {
                        // Set Timezone
                        echo sprintf("timezone: %s,\n",
                                ($datetime->getOffset()/60));
                        echo sprintf("
                                controlType: 'select',\n
                                timeInput: true,\n
                                timeFormat: \"%s\",\n",
                                Format::dtfmt_php2js($timeFormat));
                    }
                    ?>
                    numberOfMonths: 2,
                    showButtonPanel: true,
                    buttonImage: './images/cal.png',
                    showOn:'both',
                    dateFormat: '<?php echo
                        Format::dtfmt_php2js($dateFormat); ?>'
                });
            });
        </script>
        <?php
    }

    /**
     * Function: getValue
     * Combines the datepicker date value and the time dropdown selected
     * time value into a single date and time string value in DateTime::W3C
     */
    function getValue() {
        global $cfg;

        if ($value = parent::getValue()) {
            if (($dt = Format::parseDateTime($value))) {
                // Effective timezone for the selection
                if (($timezone = $this->field->getTimezone()))
                    $dt->setTimezone($timezone);
                // Format date time to universal format
                $value = $dt->format('Y-m-d H:i:s T');
            }
        }

        return $value;
    }
}

class TimePickerWidget extends Widget {

    function render($options=array()) {
        $config = $this->field->getConfiguration();
        if (!isset($this->value) && ($default=$this->field->get('default')))
            $this->value = $default;

        // For timezone display purposes only - for now
        $datetime = new DateTime('now');
        // Selection timezone
        $datetime->setTimezone($this->field->getTimeZone());

        if ($this->value) {
            // TODO: Reformat time here to match settings
        }

        ?>
        <input type="text" name="<?php echo $this->name; ?>"
            id="<?php echo $this->id; ?>" style="display:inline-block;width:auto"
            value="<?php echo $this->value; ?>"
            size="10"
            autocomplete="off"  />
        <?php
        // Timezone hint
        // Show timzone hit by default but allow field to turn it off.
        $showtimezone = true;
        if (isset($config['showtimezone']))
            $showtimezone = $config['showtimezone'];

        if ($showtimezone) {
            echo sprintf('&nbsp;<span class="faded">(<a href="#"
                        data-placement="top" data-toggle="tooltip"
                        title="%s">%s</a>)</span>',
                    $datetime->getTimezone()->getName(),
                    $datetime->format('T'));
        }
        ?>
        <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
            $(function() {
                $('input[name="<?php echo $this->name; ?>"]').timepicker({
                    <?php
                    // Set time options
                    echo sprintf("
                            controlType: 'select',\n
                            timeInput: true,\n
                            timeFormat: \"%s\",\n",
                            "hh:mm tt");
                    echo sprintf("timezone: %s\n",
                            ($datetime->getOffset()/60));
                    ?>
                });
            });
        </script>
        <?php
    }

    /**
     * Function: getValue
     */
    function getValue() {
        global $cfg;

        if ($value = parent::getValue()) {
            // TODO: Return ISO format.
        }

        return $value;
    }
}
class SectionBreakWidget extends Widget {
    function render($options=array()) {
        ?><div class="form-header section-break"><h3><?php
        echo Format::htmlchars($this->field->getLocal('label'));
        ?></h3><em><?php echo Format::display($this->field->getLocal('hint'));
        ?></em></div>
        <?php
    }
}

class ThreadEntryWidget extends Widget {
    function render($options=array()) {

        $config = $this->field->getConfiguration();
        $object_id = false;
        if ($options['client']) {
            $namespace = $options['draft-namespace']
                ?: 'ticket.client';
             $object_id = substr(session_id(), -12);
        } else {
            $namespace = $options['draft-namespace'] ?: 'ticket.staff';
        }

        list($draft, $attrs) = Draft::getDraftAndDataAttrs($namespace, $object_id, $this->value);
        ?>
        <textarea style="width:100%;" name="<?php echo $this->name; ?>"
            placeholder="<?php echo Format::htmlchars($this->field->get('placeholder')); ?>"
            class="<?php if ($config['html']) echo 'richtext';
                ?> draft draft-delete" <?php echo $attrs; ?>
            cols="21" rows="8" style="width:80%;"><?php echo
            ThreadEntryBody::clean($this->value ?: $draft); ?></textarea>
    <?php
        if (!$config['attachments'])
            return;

        $attachments = $this->getAttachments($config);
        print $attachments->render($options);
        foreach ($attachments->getMedia() as $type=>$urls) {
            foreach ($urls as $url)
                Form::emitMedia($url, $type);
        }
    }

    function getAttachments($config=false) {
        if (!$config)
            $config = $this->field->getConfiguration();

        $field = new FileUploadField(array(
            'id'=>'attach',
            'name'=>'attach:' . $this->field->get('id'),
            'configuration'=>$config)
        );
        $field->setForm($this->field->getForm());
        return $field;
    }

    function parseValue() {
        parent::parseValue();
        if (isset($this->value)) {
            $value = $this->value;
            $config = $this->field->getConfiguration();
            // Trim spaces based on text input type.
            // Preserve original input if not empty.
            if ($config['html'])
                $this->value = trim($value, " <>br/\t\n\r") ? $value : '';
            else
                $this->value = trim($value) ? $value : '';
        }
    }

}

class FileUploadWidget extends Widget {
    static $media = array(
        'css' => array(
            '/css/filedrop.css',
        ),
    );

    function render($options=array()) {
        global $ost;

        $config = $this->field->getConfiguration();
        $name = $this->field->getFormName();
        $id = substr(md5(spl_object_hash($this)), 10);
        $mimetypes = array_filter($config['__mimetypes'],
            function($t) { return strpos($t, '/') !== false; }
        );
        $maxfilesize = ($config['size'] ?: 1048576) / 1048576;
        $files = array();
        $new = $this->field->getClean(false);

        foreach ($this->field->getAttachments() as $att) {
            unset($new[$att->file_id]);
            $files[] = array(
                'id' => $att->file->getId(),
                'name' => $att->getFilename(),
                'type' => $att->file->getType(),
                'size' => $att->file->getSize(),
                'download_url' => $att->file->getDownloadUrl(),
            );
        }

        // Add in newly added files not yet saved (if redisplaying after an
        // error)
        if ($new) {
            $F = AttachmentFile::objects()
                ->filter(array('id__in' => array_keys($new)))
                ->all();

            foreach ($F as $f) {
                $f->tmp_name = $new[$f->getId()];
                $files[] = array(
                    'id' => $f->getId(),
                    'name' => $f->getName(),
                    'type' => $f->getType(),
                    'size' => $f->getSize(),
                    'download_url' => $f->getDownloadUrl(),
                );
            }
        }

        //see if the attachment is saved in the session for this specific field
        if ($sessionAttachment = $_SESSION[':form-data'][$this->field->get('name')]) {
            $F = AttachmentFile::objects()
                ->filter(array('id__in' => array_keys($sessionAttachment)))
                ->all();

            foreach ($F as $f) {
                $f->tmp_name = $sessionAttachment[$f->getId()];
                $files[] = array(
                    'id' => $f->getId(),
                    'name' => $f->getName(),
                    'type' => $f->getType(),
                    'size' => $f->getSize(),
                    'download_url' => $f->getDownloadUrl(),
                );
            }
        }

         // Set default $field_id
        $field_id = $this->field->get('id');
        // Get Form Type
        $type = $this->field->getForm()->type;
        // Determine if for Ticket/Task/Custom
        if ($type && !is_numeric($field_id)) {
            if ($type == 'T')
                $field_id = 'ticket/attach';
            elseif ($type == 'A')
                $field_id = 'task/attach';
        }

        ?><div id="<?php echo $id;
            ?>" class="filedrop"><div class="files"></div>
            <div class="dropzone"><i class="icon-upload"></i>
            <?php echo sprintf(
                __('Drop files here or %s choose them %s'),
                '<a href="#" class="manual">', '</a>'); ?>
        <input type="file" multiple="multiple"
            id="file-<?php echo $id; ?>" style="display:none;"
            accept="<?php echo implode(',', $config['__mimetypes']); ?>"/>
        </div></div>
        <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
        $(function(){$('#<?php echo $id; ?> .dropzone').filedropbox({
          url: 'ajax.php/form/upload/<?php echo $field_id; ?>',
          link: $('#<?php echo $id; ?>').find('a.manual'),
          paramname: 'upload[]',
          data: {"__CSRFToken__": "<?php echo $ost->getCSRF()->getToken(); ?>"},
          fallback_id: 'file-<?php echo $id; ?>',
          allowedfileextensions: <?php echo JsonDataEncoder::encode(
            $config['__extensions'] ?: array()); ?>,
          allowedfiletypes: <?php echo JsonDataEncoder::encode(
            $mimetypes); ?>,
          maxfiles: <?php echo $config['max'] ?: 20; ?>,
          maxfilesize: <?php echo str_replace(',', '.', $maxfilesize); ?>,
          name: '<?php echo $name; ?>[]',
          files: <?php echo JsonDataEncoder::encode($files); ?>
        });});
        </script>
<?php
    }

    function getValue() {
        $ids = array();
        // Handle manual uploads (IE<10)
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES[$this->name])) {
            foreach (AttachmentFile::format($_FILES[$this->name]) as $file) {
                try {
                    $F = $this->field->uploadFile($file);
                    $ids[$F->getId()] = $F->getName();
                }
                catch (FileUploadError $ex) {}
            }
            return $ids;
        }

        // Files uploaded here MUST have been uploaded by this user and
        // identified in the session
        //
        // If no value was sent, assume an empty list
        if (!($files = parent::getValue()))
            return array();

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $_files = array();
            foreach ($files as $info) {
                if (@list($id, $name) = explode(',', $info, 2))
                    $_files[$id] = $name;
            }
            $files = $_files;
        }

        $allowed = array();
        // Files already attached to the field are allowed
        foreach ($this->field->getFiles() as $F) {
            // FIXME: This will need special porting in v1.10
            $allowed[$F->id] = $F->getName();
        }

        // New files uploaded in this session are allowed
        if (isset($_SESSION[':uploadedFiles']))
            $allowed += $_SESSION[':uploadedFiles'];

        // Canned attachments initiated by this session
        if (isset($_SESSION[':cannedFiles']))
           $allowed += $_SESSION[':cannedFiles'];

        // Parse the files and make sure it's allowed.
        foreach ($files as $id => $name) {
            if (!isset($allowed[$id]))
                continue;

            // Keep the values as the IDs
            $ids[$id] = $name ?: $allowed[$id];
        }

        return $ids;
    }
}

class FileUploadError extends Exception {}

class FreeTextField extends FormField {
    static $widget = 'FreeTextWidget';
    protected $attachments;

    function getConfigurationOptions() {
        return array(
            'content' => new TextareaField(array(
                'configuration' => array('html' => true, 'size'=>'large'),
                'label'=>__('Content'), 'required'=>true, 'default'=>'',
                'hint'=>__('Free text shown in the form, such as a disclaimer'),
            )),
            'attachments' => new FileUploadField(array(
                'id'=>'attach',
                'label' => __('Attachments'),
                'name'=>'files',
                'configuration' => array('extensions'=>'')
            )),
        );
    }

    function hasData() {
        return false;
    }

    function isBlockLevel() {
        return true;
    }

    function isEditableToStaff() {
        return $this->isVisibleToStaff();
    }

    function isEditableToUsers() {
        return $this->isVisibleToUsers();
    }

    /* utils */

    function to_config($config) {
        if ($config && isset($config['attachments']))
            $keepers = $config['attachments'];
        $this->getAttachments()->keepOnlyFileIds($keepers);

        return $config;
    }

    function db_cleanup($field=false) {

        if ($field && $this->getFiles())
            $this->getAttachments()->deleteAll();
    }

    function getAttachments() {
        if (!isset($this->attachments))
            $this->attachments = GenericAttachments::forIdAndType($this->get('id'), 'I');

        return $this->attachments ?: array();
    }

    function getFiles() {
        if (!isset($this->files)) {
            $files = array();
            if (($attachments=$this->getAttachments()))
                foreach ($attachments->all() as $a)
                    $files[] = $a->getFile();
            $this->files = $files;
        }
        return $this->files;
    }
}

class FreeTextWidget extends Widget {
    function render($options=array()) {
        $config = $this->field->getConfiguration();
        $class = $config['classes'] ?: 'thread-body bleed';
        ?><div class="<?php echo $class; ?>"><?php
        if ($label = $this->field->getLocal('label')) { ?>
            <h3><?php
            echo Format::htmlchars($label);
        ?></h3><?php
        }
        if ($hint = $this->field->getLocal('hint')) { ?>
        <em><?php
            echo Format::display($hint);
        ?></em><?php
        } ?>
        <div><?php
            echo Format::viewableImages($config['content']); ?></div>
        </div>
        <?php
        if (($attachments = $this->field->getAttachments()) && count($attachments)) { ?>
            <section class="freetext-files">
            <div class="title"><?php echo __('Related Resources'); ?></div>
            <?php foreach ($attachments->all() as $attach) {
                $filename = Format::htmlchars($attach->getFilename());
                ?>
                <div class="file">
                <a href="<?php echo $attach->file->getDownloadUrl(); ?>"
                    target="_blank" download="<?php echo $filename; ?>"
                    class="truncate no-pjax">
                    <i class="icon-file"></i>
                    <?php echo $filename; ?>
                </a>
                </div>
            <?php } ?>
        </section>
        <?php }
    }
}

class ColorPickerWidget extends Widget {
    static $media = array(
        'css' => array(
            'css/spectrum.css',
        ),
        'js' => array(
            'js/spectrum.js',
        ),
    );

    function render($options=array()) {
        ?><input type="color"
            id="<?php echo $this->id; ?>"
            name="<?php echo $this->name; ?>"
            value="<?php echo Format::htmlchars($this->value); ?>"/><?php
    }
}
