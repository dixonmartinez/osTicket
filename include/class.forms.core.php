<?php
/*********************************************************************
    class.forms.core.php

    Formularios: el marco.

    Parte de include/class.forms.php (frente 6 de docs/CODE_QUALITY.md):
    el archivo tenia 4.515 lineas y 37 clases. Se movieron las clases
    enteras, sin tocar su codigo.

    Form, SimpleForm, CustomForm,
 * AbstractForm, FormRenderer, FormLayout, GridFluidLayout, Cell y GridFluidCell.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/


/**
 * Form template, used for designing the custom form and for entering custom
 * data for a ticket
 */
class Form {
    static $renderer = 'GridFluidLayout';
    static $id = 0;

    var $options = array();
    var $fields = array();
    var $title = '';
    var $notice = '';
    var $instructions = '';

    var $validators = array();

    var $_errors = null;
    var $_source = false;

    function __construct($source=null, $options=array()) {

        $this->options = $options;
        if (isset($options['title']))
            $this->title = $options['title'];
        if (isset($options['instructions']))
            $this->instructions = $options['instructions'];
        if (isset($options['notice']))
            $this->notice = $options['notice'];
        if (isset($options['id']))
            $this->id = $options['id'];

        // Use POST data if source was not specified
        $this->_source = $source ?: $_POST;
    }

    function getFormId() {
        return @$this->id ?: static::$id;
    }
    function setId($id) {
        $this->id = $id;
    }

    function setNotice($notice) {
        $this->notice = $notice;
    }

    function data($source) {
        foreach ($this->fields as $name=>$f)
            if (isset($source[$name]))
                $f->value = $source[$name];
    }

    function setFields($fields) {

        if (!is_array($fields) && !$fields instanceof Traversable)
            return;

        $this->fields = $fields;
        foreach ($fields as $k=>$f) {
            $f->setForm($this);
            if (!$f->get('name') && $k && !is_numeric($k))
                $f->set('name', $k);
        }
    }

    function getFields() {
        return $this->fields;
    }

    function getField($name) {
        $fields = $this->getFields();
        foreach($fields as $f)
            if(!strcasecmp($f->get('name'), $name))
                return $f;
        if (isset($fields[$name]))
            return $fields[$name];
    }

    function hasField($name) {
        return $this->getField($name);
    }

    function hasAnyEnabledFields() {
        return $this->hasAnyVisibleFields(false);
    }

    function hasAnyVisibleFields($user=false) {
        $visible = 0;
        $isstaff = $user instanceof Staff;
        foreach ($this->getFields() as $F) {
            if (!$user) {
                // Assume hasAnyEnabledFields
                if ($F->isEnabled())
                    $visible++;
            } elseif($isstaff) {
                if ($F->isVisibleToStaff())
                    $visible++;
            } elseif ($F->isVisibleToUsers()) {
                $visible++;
            }
        }
        return $visible > 0;
    }

    function getTitle() { return $this->title; }
    function getNotice() { return $this->notice; }
    function getInstructions() { return Format::htmldecode($this->instructions); }
    function getSource() { return $this->_source; }
    function setSource($source) { $this->_source = $source; }

    /**
     * Validate the form and indicate if there no errors.
     *
     * Parameters:
     * $filter - (callback) function to receive each field and return
     *      boolean true if the field's errors are significant
     */
    function isValid($include=false) {
        if (!isset($this->_errors)) {
            $this->_errors = array();
            $this->validate($this->getClean());
            foreach ($this->getFields() as $field)
                if ($field->errors() && (!$include || $include($field)))
                    $this->_errors[$field->get('id')] = $field->errors();
        }
        return !$this->_errors;
    }

    function validate($clean_data) {
        // Validate the whole form so that errors can be added to the
        // individual fields and collected below.
        foreach ($this->validators as $V) {
            $V($this);
        }
    }

    function getClean($validate=true) {
        if (!$this->_clean) {
            $this->_clean = array();
            foreach ($this->getFields() as $key=>$field) {
                if (!$field->hasData())
                    continue;

                // Prefer indexing by field.id if indexing numerically
                if (is_int($key) && $field->get('id'))
                    $key = $field->get('id');
                $this->_clean[$key] = $this->_clean[$field->get('name')]
                    = $field->getClean($validate);
            }
            unset($this->_clean[""]);
        }
        return $this->_clean;
    }
    /*
     * Transform form data to database ready clean data.
     *
     */
    function to_db($clean=null, $validate=true) {
        if (!$clean
                && !$this->isValid()
                && !($clean=$this->getClean($validate)))
            return false;
        $data = [];
        foreach ($clean as $name => $val) {
            if (!($f = $this->getField($name)))
                continue;
            try {
                $data[$name] = $f->to_database($val);
            } catch (FieldUnchanged $e) {
                // Unset field if it's unchanged...mainly
                // useful for Secret/PasswordField
                unset($data[$name]);
            }
        }
        return $data;
    }

    /*
     * Process the form input and return clean data.
     *
     * It's similar to to_db but forms downstream can use it to skip or add
     * extra validations

     */
    function process($validate=true) {
        return $this->to_db($validate);
    }


    function errors($formOnly=false) {
        return ($formOnly) ? $this->_errors['form'] : $this->_errors;
    }

    function addError($message, $index=false) {
        if ($index)
            $this->_errors[$index] = $message;
        else
            $this->_errors['form'][] = $message;
    }

    function addErrors($errors=[]) {
        foreach ($errors as $k => $v) {
            if (($f=$this->getField($k)))
                $f->addError($v);
            else
                $this->addError($v, $k);
        }
    }

    function addValidator($function) {
        if (!is_callable($function))
            throw new Exception('Form validator must be callable');
        $this->validators[] = $function;
    }

    function render($options=array()) {
        if (isset($options['title']))
            $this->title = $options['title'];
        if (isset($options['instructions']))
            $this->instructions = $options['instructions'];
        if (isset($options['notice']))
            $this->notice = $options['notice'];

        $form = $this;
        $template = $options['template'] ?: 'dynamic-form.tmpl.php';
        if (isset($options['staff']) && $options['staff'])
            include(STAFFINC_DIR . 'templates/' . $template);
        else
            include(CLIENTINC_DIR . 'templates/' . $template);
        echo $this->getMedia();
    }

    function getLayout($title=false, $options=array()) {
        $rc = @$options['renderer'] ?: static::$renderer;
        return new $rc($title, $options);
    }

    function asTable($title=false, $options=array()) {
        return $this->getLayout($title, $options)->asTable($this);
        // XXX: Media can't go in a table
        echo $this->getMedia();
    }

    function getMedia() {
        static $dedup = array();

        foreach ($this->getFields() as $f) {
            if (($M = $f->getMedia()) && is_array($M)) {
                foreach ($M as $type=>$files) {
                    foreach ($files as $url) {
                        $key = strtolower($type.$url);
                        if (isset($dedup[$key]))
                            continue;

                        self::emitMedia($url, $type);

                        $dedup[$key] = true;
                    }
                }
            }
        }
    }

    function emitJavascript($options=array()) {

        // Check if we need to emit javascript
        if (!($fid=$this->getFormId()))
            return;
        ?>
        <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
          $(function() {
            <?php
            //XXX: We ONLY want to watch field on this form. We'll only
            // watch form inputs if form_id is specified. Current FORM API
            // doesn't generate the entire form  (just fields)
            if ($fid) {
                ?>
                $(document).off('change.<?php echo $fid; ?>');
                $(document).on('change.<?php echo $fid; ?>',
                    'form#<?php echo $fid; ?> :input',
                    function() {
                        //Clear any current errors...
                        var errors = $('#field'+$(this).attr('id')+'_error');
                        if (errors.length)
                            errors.slideUp('fast', function (){
                                $(this).remove();
                                });
                        //TODO: Validation input inplace or via ajax call
                        // and set any new errors AND visibilty changes
                    }
                   );
            <?php
            }
            ?>
            });
        </script>
        <?php
    }

    static function emitMedia($url, $type) {
        if ($url[0] == '/')
            $url = ROOT_PATH . substr($url, 1);

        switch (strtolower($type)) {
        case 'css': ?>
        <link rel="stylesheet" type="text/css" href="<?php echo $url; ?>"/><?php
            break;
        case 'js': ?>
        <script type="text/javascript" src="<?php echo $url; ?>"></script><?php
            break;
        }
    }

    /**
     * getState
     *
     * Retrieves an array of information which can be passed to the
     * ::loadState method later to recreate the current state of the form
     * fields and values.
     */
    function getState() {
        $info = array();
        foreach ($this->getFields() as $f) {
            // Skip invisible fields
            if (!$f->isVisible())
                continue;

            // Skip fields set to default values
            $v = $f->getClean();
            $d = $f->get('default');
            if ($v == $d)
                continue;

            // Skip empty values
            if (!$v)
                continue;

            $info[$f->get('name') ?: $f->get('id')] = $f->to_database($v);
        }
        return $info;
    }

    /**
     * loadState
     *
     * Reset this form to the state previously recorded by the ::getState()
     * method
     */
    function loadState($state) {
        foreach ($this->getFields() as $f) {
            $name = $f->get('name');
            $f->reset();
            if (isset($state[$name])) {
                $f->value = $f->to_php($state[$name]);
            }
        }
    }

    /*
     * Initialize a generic static form
     */
    static function instantiate() {
        $r = new ReflectionClass(get_called_class());
        return $r->newInstanceArgs(func_get_args());
    }
}


/**
 * SimpleForm
 * Wrapper for inline/static forms.
 *
 */
class SimpleForm extends Form {
    function __construct($fields=array(), $source=null, $options=array()) {
        parent::__construct($source, $options);
        if (isset($options['type']))
            $this->type = $options['type'];
        $this->setFields($fields);
    }

    function getId() {
        return $this->getFormId();
    }
}


class CustomForm extends SimpleForm {

    function getFields() {
        global $thisstaff, $thisclient;

        $options = $this->options;
        $user = $options['user'] ?: $thisstaff ?: $thisclient;
        $isedit = ($options['mode'] == 'edit');
        $fields = array();
        foreach (parent::getFields() as $field) {
            if ($isedit && !$field->isEditable($user))
                continue;

            $fields[] = $field;
        }

        return $fields;
    }
}


abstract class AbstractForm extends Form {
    function __construct($source=null, $options=array()) {
        parent::__construct($source, $options);
        $this->setFields($this->buildFields());
    }
    /**
     * Fetch the fields defined for this form. This method is only called
     * once.
     */
    abstract function buildFields();
}


/**
 * Container class to represent the connection between the form fields and the
 * rendered state of the form.
 */
interface FormRenderer {
    // Render the form fields into a table
    function asTable($form);
    // Render the form fields into divs
    function asBlock($form);
}


abstract class FormLayout {
    static $default_cell_layout = 'Cell';

    var $title;
    var $options;

    function __construct($title=false, $options=array()) {
        $this->title = $title;
        $this->options = $options;
    }

    function getLayout($field) {
        $layout = $field->get('layout') ?: static::$default_cell_layout;
        if (is_string($layout))
            $layout = new $layout();
        return $layout;
    }
}


class GridFluidLayout
extends FormLayout
implements FormRenderer {
    function asTable($form) {
      ob_start();
?>
      <table class="<?php echo 'grid form' ?>">
          <caption><?php echo Format::htmlchars($this->title ?: $form->getTitle()); ?>
            <div><small><?php echo Format::viewableImages($form->getInstructions()); ?></small></div>
            <?php
            if ($form->getNotice())
                echo sprintf('<div><small><p id="msg_warning">%s</p></small></div>',
                        Format::htmlchars($form->getNotice()));
            ?>
        </caption>
        <tbody><tr><?php for ($i=0; $i<12; $i++) echo '<td style="width:8.3333%"/>'; ?></tr></tbody>
<?php
      $row_size = 12;
      $cols = $row = 0;

      //Layout and rendering options
      $options = $this->options;

      foreach ($form->getFields() as $f) {
          $layout = $this->getLayout($f);
          $size = $layout->getWidth() ?: 12;
          if ($offs = $layout->getOffset()) {
              $size += $offs;
          }
          if ($cols < $size || $layout->isBreakForced()) {
              if ($row) echo '</tr>';
              echo '<tr>';
              $cols = $row_size;
              $row++;
          }
          // Render the cell
          $cols -= $size;
          $attrs = array('colspan' => $size, 'rowspan' => $layout->getHeight(),
              'style' => '"'.$layout->getOption('style').'"');
          if ($offs) { ?>
              <td colspan="<?php echo $offs; ?>"></td> <?php
          }
          ?>
          <td class="cell" <?php echo Format::array_implode('=', ' ', array_filter($attrs)); ?>
              data-field-id="<?php echo $f->get('id'); ?>">
              <fieldset class="field <?php if (!$f->isVisible()) echo 'hidden'; ?>"
                id="field<?php echo $f->getWidget()->id; ?>"
                data-field-id="<?php echo $f->get('id'); ?>">
<?php         $label = $f->get('label'); ?>
              <label class="<?php if ($f->isRequired()) echo 'required'; ?>"
                  for="<?php echo $f->getWidget()->id; ?>">
                  <?php echo $label ? (Format::htmlchars($label).':') : '&nbsp;'; ?>
                <?php if ($f->isRequired()) { ?>
                <span class="error">*</span>
              </label>
<?php         }
              if ($f->get('hint')) { ?>
                  <div class="field-hint-text">
                      <?php echo Format::htmlchars($f->get('hint')); ?>
                  </div>
<?php         }
              $f->render($options);
              if ($f->errors())
                  foreach ($f->errors() as $e)
                      echo sprintf('<div class="error">%s</div>', Format::htmlchars($e));
?>
              </fieldset>
          </td>
      <?php
      }
      if ($row)
        echo  '</tr>';

      echo '</tbody></table>';

      return ob_get_clean();
    }

    function asBlock($form) {}
}


/**
 * Basic container for field and form layouts. By default every cell takes
 * a whole output row and does not imply any sort of width.
 */
class Cell {
    function isBreakForced()  { return true; }
    function getWidth()       { return false; }
    function getHeight()      { return 1; }
    function getOffset()      { return 0; }
    function getOption($prop) { return false; }
}


/**
 * Fluid grid layout, meaning each cell renders to the right of the previous
 * cell (for left-to-right layouts). A width in columns can be specified for
 * each cell along with an offset from the previous cell. A height of columns
 * along with an optional break is supported.
 */
class GridFluidCell
extends Cell {
    var $span;
    var $options;

    function __construct($span, $options=array()) {
        $this->span = $span;
        $this->options = $options + array(
            'rows' => 1,        # rowspan
            'offset' => 0,      # skip some columns
            'break' => false,   # start on a new row
        );
    }

    function isBreakForced()  { return $this->options['break']; }
    function getWidth()       { return $this->span; }
    function getHeight()      { return $this->options['rows']; }
    function getOffset()      { return $this->options['offset']; }
    function getOption($prop) { return $this->options[$prop]; }
}
