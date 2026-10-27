<?php
/*********************************************************************
    class.dynamic_forms.widget.php

    El buscador de una lista de seleccion multiple, que es un widget y no un
    formulario.

    Parte de include/class.dynamic_forms.php (frente 6 de docs/CODE_QUALITY.md):
    el archivo tenia 2.026 lineas. Se movio la clase entera, sin tocar su
    codigo; el original incluye este al final.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
class TypeaheadSelectionWidget extends ChoicesWidget {
    function render($options=array()) {

        if ($options['mode'] == 'search')
            return parent::render($options);

        $name = $this->getEnteredValue();
        $config = $this->field->getConfiguration();
        if (is_array($this->value)) {
            $name = $name ?: current($this->value);
            $value = key($this->value);
        }
        else {
            // Pull configured default (if configured)
            $def_key = $this->field->get('default');
            if (!$def_key && $config['default'])
                $def_key = $config['default'];
            if (is_array($def_key))
                $name = current($def_key);
        }

        $source = array();
        foreach ($this->field->getList()->getItems() as $i)
            $source[] = array(
                'value' => $i->getValue(), 'id' => $i->getId(),
                'info' => sprintf('%s%s',
                    $i->getValue(),
                    (($extra= $i->getAbbrev()) ? " — $extra" : '')),
            );
        ?>
        <span style="display:inline-block">
        <input type="text" size="30" name="<?php echo $this->name; ?>_name"
            id="<?php echo $this->name; ?>" value="<?php echo Format::htmlchars($name); ?>"
            placeholder="<?php echo $config['prompt'];
            ?>" autocomplete="off" />
        <input type="hidden" name="<?php echo $this->name;
            ?>_id" id="<?php echo $this->name;
            ?>_id" value="<?php echo Format::htmlchars($value); ?>"/>
        <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
        $(function() {
            $('input#<?php echo $this->name; ?>').typeahead({
                source: <?php echo JsonDataEncoder::encode($source); ?>,
                property: 'info',
                onselect: function(item) {
                    $('input#<?php echo $this->name; ?>_name').val(item['value'])
                    $('input#<?php echo $this->name; ?>_id')
                      .attr('name', '<?php echo $this->name; ?>[' + item['id'] + ']')
                      .val(item['value']);
                    return false;
                }
            });
        });
        </script>
        </span>
        <?php
    }

    function parsedValue() {
        return array($this->getValue() => $this->getEnteredValue());
    }

    function getValue() {
        $data = $this->field->getSource();
        $name = $this->field->get('name');
        if (isset($data["{$this->name}_id"]) && is_numeric($data["{$this->name}_id"])) {
            return array($data["{$this->name}_id"] => $data["{$this->name}_name"]);
        }
        elseif (isset($data[$name])) {
            return $data[$name];
        }
        // Attempt to lookup typed value (usually from a default)
        elseif ($val = $this->getEnteredValue()) {
            return $this->field->lookupChoice($val);
        }

        return parent::getValue();
    }

    function getEnteredValue() {
        // Used to verify typeahead fields
        $data = $this->field->getSource();
        if (isset($data[$this->name.'_name'])) {
            // Drop the extra part, if any
            $v = $data[$this->name.'_name'];
            $pos = strrpos($v, ' — ');
            if ($pos !== false)
                $v = substr($v, 0, $pos);

            return trim($v);
        }
        return parent::getValue();
    }
}
