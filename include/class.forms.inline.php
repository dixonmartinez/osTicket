<?php
/*********************************************************************
    class.forms.inline.php

    Formularios: campos en linea.

    Parte de include/class.forms.php (frente 6 de docs/CODE_QUALITY.md):
    el archivo tenia 4.515 lineas y 37 clases. Se movieron las clases
    enteras, sin tocar su codigo.

    Los que se pintan dentro de otro campo, mas VisibilityConstraint y
 * FieldUnchanged.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/


class InlineFormData extends ArrayObject {
    var $_form;

    function __construct($form, array $data=array()) {
        parent::__construct($data);
        $this->_form = $form;
    }

    function getVar($tag) {
        foreach ($this->_form->getFields() as $f) {
            if ($f->get('name') == $tag)
                return $this[$f->get('id')];
        }
    }
}



class InlineFormField extends FormField {
    static $widget = 'InlineFormWidget';

    var $_iform = null;

    function validateEntry($value) {
        if (!$this->getInlineForm()->isValid()) {
            $config = $this->getConfiguration();
            $this->_errors[] = isset($config['error'])
                ? $config['error'] : __('Correct any errors below and try again.');
        }
    }

    function parse($value) {
        // The InlineFieldWidget returns an array of cleaned data
        return $value;
    }

    function to_database($value) {
        return JsonDataEncoder::encode($value);
    }

    function to_php($value) {
        $data = JsonDataParser::decode($value);
        // The InlineFormData helps with the variable replacer API
        return new InlineFormData($this->getInlineForm(), $data);
    }

    function display($data) {
        $form = $this->getInlineForm();
        ob_start(); ?>
        <div><?php
        foreach ($form->getFields() as $field) { ?>
            <span style="display:inline-block;padding:0 5px;vertical-align:top">
                <strong><?php echo Format::htmlchars($field->get('label')); ?></strong>
                <div><?php
                    $value = $data[$field->get('id')];
                    echo $field->display($value); ?></div>
            </span><?php
        } ?>
        </div><?php
        return ob_get_clean();
    }

    function getInlineForm($data=false) {
        $form = $this->get('form');
        if (is_array($form)) {
            $form = new SimpleForm($form, $data ?: $this->value ?: $this->getSource());
            // Ensure unique, but predictable form and field IDs
            $form->setId(sprintf('%u', crc32($this->get('name')) >> 1));
        }
        return $form;
    }
}


class InlineDynamicFormField extends FormField {
    function getInlineForm($data=false) {
        if (!isset($this->_iform) || $data) {
            $config = $this->getConfiguration();
            $this->_iform = DynamicForm::lookup($config['form']);
            if ($data)
                $this->_iform = $this->_iform->getForm($data);
        }
        return $this->_iform;
    }

    function getConfigurationOptions() {
        $forms = DynamicForm::objects()->filter(array('type'=>'G'))
            ->values_flat('id', 'title');
        $choices = array();
        foreach ($forms as $row) {
            list($id, $title) = $row;
            $choices[$id] = $title;
        }
        return array(
            'form' => new ChoiceField(array(
                'id'=>2, 'label'=>'Inline Form', 'required'=>true,
                'default'=>'', 'choices'=>$choices
            )),
        );
    }
}


class VisibilityConstraint {
    static $operators = array(
        'eq' => 1,
        'neq' => 1,
    );

    const HIDDEN =      0x0001;
    const VISIBLE =     0x0002;

    var $initial;
    var $constraint;

    function __construct($constraint, $initial=self::VISIBLE) {
        $this->constraint = $constraint;
        $this->initial = $initial;
    }

    function emitJavascript($field) {

        if (!$this->constraint->constraints)
            return;

        $func = 'recheck_'.$field->getWidget()->id;
        $form = $field->getForm();
?>
    <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
      !(function() {
        var <?php echo $func; ?> = function() {
          var target = $('#field<?php echo $field->getWidget()->id; ?>');
<?php   $fields = $this->getAllFields($this->constraint);
        foreach ($fields as $f) {
            if (!($field = $form->getField($f)))
                continue;
            echo sprintf('var %1$s = x = $("#%1$s");',
                $field->getWidget()->id);
        }
        $expression = $this->compileQ($this->constraint, $form);
?>
          if (<?php echo $expression; ?>) {
            target.slideDown('fast', function (){
                $(this).trigger('show');
                });
          } else {
            target.slideUp('fast', function (){
                $(this).trigger('hide');
                });
          }
        };

<?php   foreach ($fields as $f) {
            if (!($field=$form->getField($f)))
                continue;
            $w = $field->getWidget();
?>
        $('#<?php echo $w->id; ?>').on('change', <?php echo $func; ?>);
        $('#field<?php echo $w->id; ?>').on('show hide', <?php
                echo $func; ?>);
<?php   } ?>
      })();
    </script><?php
    }

    /**
     * Determines if the field was visible when the form was submitted
     */
    function isVisible($field) {

        // Assume initial visibility if constraint is not provided.
        if (!$this->constraint->constraints)
            return $this->initial == self::VISIBLE;


        return $this->compileQPhp($this->constraint, $field);
    }

    static function splitFieldAndOp($field) {
        if (false !== ($last = strrpos($field, '__'))) {
            $op = substr($field, $last + 2);
            if (isset(static::$operators[$op]))
                $field = substr($field, 0, strrpos($field, '__'));
        }
        return array($field, $op);
    }

    function compileQPhp(Q $Q, $field) {
        if (!($form = $field->getForm())) {
            return $this->initial == self::VISIBLE;
        }
        $expr = array();
        foreach ($Q->constraints as $c=>$value) {
            if ($value instanceof Q) {
                $expr[] = $this->compileQPhp($value, $field);
            }
            else {
                @list($f, $op) = self::splitFieldAndOp($c);
                $field = $form->getField($f);
                $wval = $field ? $field->getClean() : null;
                $values = explode('|', $value);
                switch ($op) {
                case 'neq':
                    $expr[] = ($wval && !in_array($wval, $values) && $field->isVisible());
                    break;
                case 'eq':
                case null:
                    $expr[] = (in_array($wval, $values) && $field->isVisible());
                }
            }
        }
        $glue = $Q->isOred()
            ? function($a, $b) { return $a || $b; }
            : function($a, $b) { return $a && $b; };
        $initial = !$Q->isOred();
        $expression = array_reduce($expr, $glue, $initial);
        if ($Q->isNegated)
            $expression = !$expression;
        return $expression;
    }

    function getAllFields(Q $Q, &$fields=array()) {
        foreach ($Q->constraints as $c=>$value) {
            if ($c instanceof Q) {
                $this->getAllFields($c, $fields);
            }
            else {
                @list($f) = self::splitFieldAndOp($c);
                $fields[$f] = true;
            }
        }
        return array_keys($fields);
    }

    function compileQ($Q, $form) {
        $expr = array();
        foreach ($Q->constraints as $c=>$value) {
            if ($value instanceof Q) {
                $expr[] = $this->compileQ($value, $form);
            }
            else {
                list($f, $op) = self::splitFieldAndOp($c);
                if (!($field=$form->getField($f))) continue;
                $widget = $field->getWidget();
                $id = $widget->id;
                switch ($op) {
                case 'neq':
                    $expr[] = sprintf('(%s.is(":visible") && !(%s))',
                            $id, $widget->getJsComparator($value, $id));
                    break;
                case 'eq':
                case null:
                    $expr[] = sprintf('(%s.is(":visible") && (%s))',
                            $id, $widget->getJsComparator($value, $id));
                }
            }
        }
        $glue = $Q->isOred() ? ' || ' : ' && ';
        $expression = implode($glue, $expr);
        if (count($expr) > 1)
            $expression = '('.$expression.')';
        if ($Q->isNegated)
            $expression = '!'.$expression;
        return $expression;
    }
}


/**
 * FieldUnchanged
 *
 * Thrown in the to_database() method to indicate the value should not be
 * saved in the database (it wasn't changed in the request)
 */
class FieldUnchanged extends Exception {}
