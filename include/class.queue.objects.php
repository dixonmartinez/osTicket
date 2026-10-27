<?php
/*********************************************************************
    class.queue.objects.php

    Piezas de las colas guardadas: las columnas, sus condiciones, la
    exportacion y las ordenaciones.

    Parte de include/class.queue.php (frente 6 de docs/CODE_QUALITY.md): el
    archivo tenia 2.400 lineas y once clases. Se movieron las clases enteras,
    sin tocar su codigo; `CustomQueue` se queda en el archivo original, que
    incluye este al final.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

/**
 * A column of a custom queue. Columns have many customizable features
 * including:
 *
 *   * Data Source (primary and secondary)
 *   * Heading
 *   * Link (to an object like the ticket)
 *   * Size and truncate settings
 *   * annotations (like counts and flags)
 *   * Conditions (which change the formatting like bold text)
 *
 * Columns are stored in a separate table from the queue itself, but other
 * breakout items for the annotations and conditions, for instance, are stored
 * as JSON text in the QueueColumn model.
 */
class QueueColumn
extends VerySimpleModel {
    static $meta = array(
        'table' => COLUMN_TABLE,
        'pk' => array('id'),
        'ordering' => array('name'),
    );

    const FLAG_SORTABLE = 0x0001;

    var $_annotations;
    var $_conditions;
    var $_queue;            // Apparent queue if being inherited
    var $_fields;

    function getId() {
        return $this->id;
    }

    function getFilter() {
         if ($this->filter
                && ($F = QueueColumnFilter::getInstance($this->filter)))
            return $F;
     }

    function getName() {
        return $this->name;
    }

    // These getters fetch data from the annotated overlay from the
    // queue_column table
    function getQueue() {
        if (!isset($this->_queue)) {
            $queue = $this->queue;

            if (!$queue && ($queue_id = $this->queue_id) && is_numeric($queue_id))
                $queue = CustomQueue::lookup($queue_id);

            $this->_queue = $queue;
        }

        return $this->_queue;
    }
    /**
     * If a column is inherited into a child queue and there are conditions
     * added to that queue, then the column will need to be linked at
     * run-time to the child queue rather than the parent.
     */
    function setQueue(CustomQueue $queue) {
        $this->_queue = $queue;
    }

    function getFields() {
        if (!isset($this->_fields)) {
            $root = ($q = $this->getQueue()) ? $q->getRoot() : 'Ticket';
            $fields = CustomQueue::getSearchableFields($root);
            $primary = CustomQueue::getOrmPath($this->primary);
            $secondary = CustomQueue::getOrmPath($this->secondary);
            if (($F = $fields[$primary]) && (list(,$field) = $F))
                $this->_fields[$primary] = $field;
            if ((isset($fields[$secondary]) && ($F = $fields[$secondary])) && (list(,$field) = $F))
                $this->_fields[$secondary] = $field;
        }
        return $this->_fields;
    }

    function getField($path=null) {
        $fields = $this->getFields();
        return @$fields[$path ?: $this->primary];
    }

    function getWidth() {
        return $this->width ?: 100;
    }

    function getHeading() {
        return $this->heading;
    }

    function getTranslateTag($subtag) {
        return _H(sprintf('column.%s.%s.%s', $subtag, $this->queue_id, $this->id));
    }
    function getLocal($subtag) {
        $tag = $this->getTranslateTag($subtag);
        $T = CustomDataTranslation::translate($tag);
        return $T != $tag ? $T : $this->get($subtag);
    }
    function getLocalHeading() {
        return $this->getLocal('heading');
    }

    protected function setFlag($flag, $value=true, $field='flags') {
        return $value
            ? $this->{$field} |= $flag
            : $this->clearFlag($flag, $field);
    }

    protected function clearFlag($flag, $field='flags') {
        return $this->{$field} &= ~$flag;
    }

    function isSortable() {
        return $this->bits & self::FLAG_SORTABLE;
    }

    function setSortable($sortable) {
        $this->setFlag(self::FLAG_SORTABLE, $sortable, 'bits');
    }

    function render($row) {
        // Basic data
        $text = $this->renderBasicValue($row);

        // Filter
        if ($text && ($filter = $this->getFilter())) {
            $text = $filter->filter($text, $row) ?: $text;
        }

        $styles = array();
        if ($text instanceof LazyDisplayWrapper) {
            $text = $text->display($styles);
        }

        // Truncate
        $text = $this->applyTruncate($text, $row);

        // annotations and conditions
        foreach ($this->getAnnotations() as $D) {
            $text = $D->render($row, $text);
        }
        foreach ($this->getConditions() as $C) {
            $text = $C->render($row, $text, $styles);
        }
        $style = Format::array_implode(':', ';', $styles);
        return array($text, $style);
    }

    function renderBasicValue($row) {
        $fields = $this->getFields();
        $primary = CustomQueue::getOrmPath($this->primary);
        $secondary = CustomQueue::getOrmPath($this->secondary);

        // Return a lazily ::display()ed value so that the value to be
        // rendered by the field could be changed or display()ed when
        // converted to a string.
        if (($F = $fields[$primary])
            && ($T = $F->from_query($row, $primary))
        ) {
            return new LazyDisplayWrapper($F, $T);
        }
        if (isset($fields[$secondary]) && ($F = $fields[$secondary])
            && ($T = $F->from_query($row, $secondary))
        ) {
            return new LazyDisplayWrapper($F, $T);
        }

         return new LazyDisplayWrapper($F, '');
    }

    function from_query($row) {
        if (!($f = $this->getField($this->primary)))
            return '';

        $val = $f->to_php($f->from_query($row, $this->primary));
        if (!is_string($val) || is_numeric($val))
            $val = $f->display($val);

        return $val;
    }

    function applyTruncate($text, $row) {
        $offset = 0;
        foreach ($this->getAnnotations() as $a)
            $offset += $a->getWidth($row);

        $width = $this->width - $offset;
        $class = array();
        switch ($this->truncate) {
        case 'lclip':
            $linfo = Internationalization::getCurrentLanguageInfo();
            // Use `rtl` class to cut the beginning of LTR text. But, wrap
            // the text with an appropriate direction so the ending
            // punctuation is not rearranged.
            $dir = $linfo['direction'] ?: 'ltr';
            $text = sprintf('<span class="%s">%s</span>', $dir, $text);
            $class[] = $dir == 'rtl' ? 'ltr' : 'rtl';
        case 'clip':
            $class[] = 'bleed';
        case 'ellipsis':
            $class[] = 'truncate';
            return sprintf('<span class="%s" style="max-width:%dpx">%s</span>',
                implode(' ', $class), $width, $text);
        default:
        case 'wrap':
            return $text;
        }
    }

    function addToQuery($query, $field, $path) {
        if (preg_match('/__answers!\d+__/', $path)) {
            // Ensure that only one record is returned from the join through
            // the entry and answers joins
            return $query->annotate(array(
                $path => SqlAggregate::MAX($path)
            ));
        }
        return $field->addToQuery($query, $path);
    }

    function mangleQuery($query, $root=null) {
        // Basic data
        $fields = $this->getFields();
        if ($field = $fields[$this->primary]) {
            $query = $this->addToQuery($query, $field,
                CustomQueue::getOrmPath($this->primary, $query));
        }
        if (isset($fields[$this->secondary]) && ($field = $fields[$this->secondary])) {
            $query = $this->addToQuery($query, $field,
                CustomQueue::getOrmPath($this->secondary, $query));
        }

        if ($filter = $this->getFilter())
            $query = $filter->mangleQuery($query, $this);

        // annotations
        foreach ($this->getAnnotations() as $D) {
            $query = $D->annotate($query);
        }

        // Conditions
        foreach ($this->getConditions() as $C) {
            $query = $C->annotate($query);
        }

        return $query;
    }

    function applySort($query, $reverse=false) {
	    $root = ($q = $this->getQueue()) ? $q->getRoot() : 'Ticket';
        $fields = CustomQueue::getSearchableFields($root);

        $keys = array();
        if ($primary = $fields[$this->primary]) {
            list(,$field) = $primary;
            $keys[] = array(CustomQueue::getOrmPath($this->primary, $query),
                    $field);
        }

        if ($secondary = $fields[$this->secondary]) {
            list(,$field) = $secondary;
            $keys[] = array(CustomQueue::getOrmPath($this->secondary,
                        $query), $field);
        }

        if (count($keys) > 1) {
            $fields = array();
            foreach ($keys as $key) {
                list($path, $field) = $key;
                foreach ($field->getSortKeys($path) as $field)
                    $fields[]  = new SqlField($field);
            }
            // Force nulls to the buttom.
            $fields[] = 'zzz';

            $alias = sprintf('C%d', $this->getId());
            $expr = call_user_func_array(array('SqlFunction', 'COALESCE'),
                    $fields);
            $query->annotate(array($alias => $expr));

            $reverse = $reverse ? '-' : '';
            $query = $query->order_by("{$reverse}{$alias}");
        } elseif($keys[0]) {
            list($path, $field) = $keys[0];
            $query = $field->applyOrderBy($query, $reverse, $path);
        }

        return $query;
    }

    function getDataConfigForm($source=false) {
        return new QueueColDataConfigForm($source ?: $this->getDbFields(),
            array('id' => $this->id));
    }

    function getAnnotations() {
        if (!isset($this->_annotations)) {
            $this->_annotations = array();
            if ($this->annotations
                && ($anns = JsonDataParser::decode($this->annotations))
            ) {
                foreach ($anns as $D)
                    if ($T = QueueColumnAnnotation::fromJson($D))
                        $this->_annotations[] = $T;
            }
        }
        return $this->_annotations;
    }

    function getConditions($include_queue=true) {
        if (!isset($this->_conditions)) {
            $this->_conditions = array();
            if ($this->conditions
                && ($conds = JsonDataParser::decode($this->conditions))
            ) {
                foreach ($conds as $C)
                    if ($T = QueueColumnCondition::fromJson($C))
                        $this->_conditions[] = $T;
            }
            // Support row-spanning conditions
            if ($include_queue && ($q = $this->getQueue())
                && ($q_conds = $q->getConditions())
            ) {
                $this->_conditions = array_merge($q_conds, $this->_conditions);
            }
        }
        return $this->_conditions;
    }

    static function __create($vars) {
        $c = new static($vars);
        $c->save();
        return $c;
    }

    static function placeholder($vars) {
        return static::__hydrate($vars);
    }

    function update($vars, $root='Ticket') {
        $form = $this->getDataConfigForm($vars);
        foreach ($form->getClean() as $k=>$v)
            $this->set($k, $v);

        // Do the annotations
        $this->_annotations = $annotations = array();
        if (isset($vars['annotations'])) {
            foreach (@$vars['annotations'] as $i=>$class) {
                if ($vars['deco_column'][$i] != $this->id)
                    continue;
                if (!class_exists($class) || !is_subclass_of($class, 'QueueColumnAnnotation'))
                    continue;
                $json = array('c' => $class, 'p' => $vars['deco_pos'][$i]);
                $annotations[] = $json;
                $this->_annotations[] = QueueColumnAnnotation::fromJson($json);
            }
        }

        // Do the conditions
        $this->_conditions = $conditions = array();
        if (isset($vars['conditions'])) {
            list($this->_conditions, $conditions)
                = self::getConditionsFromPost($vars, $this->id, $root);
        }

        // Store as JSON array
        $this->annotations = JsonDataEncoder::encode($annotations);
        $this->conditions = JsonDataEncoder::encode($conditions);
    }

    static function getConditionsFromPost(array $vars, $myid, $root='Ticket') {
        $condition_objects = $conditions = array();

        if (!isset($vars['conditions']))
            return array($condition_objects, $conditions);

        foreach (@$vars['conditions'] as $i=>$id) {
            if ($vars['condition_column'][$i] != $myid)
                // Not a condition for this column
                continue;
            // Determine the criteria
            $name = $vars['condition_field'][$i];
            $fields = CustomQueue::getSearchableFields($root);
            if (!isset($fields[$name]))
                // No such field exists for this queue root type
                continue;
            $parts = CustomQueue::getSearchField($fields[$name], $name);
            $search_form = new SimpleForm($parts, $vars, array('id' => $id));
            $search_form->getField("{$name}+search")->value = true;
            $crit = $search_form->getClean();
            // Check the box to enable searching on the field
            $crit["{$name}+search"] = true;

            // Isolate only the critical parts of the criteria
            $crit = QueueColumnCondition::isolateCriteria($crit);

            // Determine the properties
            $props = array();
            foreach ($vars['properties'] as $i=>$cid) {
                if ($cid != $id)
                    // Not a property for this condition
                    continue;

                // Determine the property configuration
                $prop = $vars['property_name'][$i];
                if (!($F = QueueColumnConditionProperty::getField($prop))) {
                    // Not a valid property
                    continue;
                }
                $prop_form = new SimpleForm(array($F), $vars, array('id' => $cid));
                $props[$prop] = $prop_form->getField($prop)->getClean();
            }
            $json = array('crit' => $crit, 'prop' => $props);
            $condition_objects[] = QueueColumnCondition::fromJson($json);
            $conditions[] = $json;
        }
        return array($condition_objects, $conditions);
    }
}

class QueueConfig
extends VerySimpleModel {
    static $meta = array(
        'table' => QUEUE_CONFIG_TABLE,
        'pk' => array('queue_id', 'staff_id'),
        'joins' => array(
            'queue' => array(
                'constraint' => array(
                    'queue_id' => 'CustomQueue.id'),
            ),
            'staff' => array(
                'constraint' => array(
                    'staff_id' => 'Staff.staff_id',
                )
            ),
            'columns' => array(
                'reverse' => 'QueueColumnGlue.config',
                'constrain' => array('staff_id' =>'QueueColumnGlue.staff_id'),
                'broker' => 'QueueColumnListBroker',
            ),
        ),
    );

    function getSettings() {
        return JsonDataParser::decode($this->setting);
    }


    function update($vars, &$errors) {

        // settings of interest
        $setting = array(
                'sort_id' => (int) $vars['sort_id'],
                'filter' => $vars['filter'],
                'inherit-sort' => ($vars['sort_id'] == '::'),
                'inherit-columns' => isset($vars['inherit-columns']),
                'criteria' => $vars['criteria'] ?: array(),
                );

        if (!$setting['inherit-columns'] && $vars['columns']) {
            if (!$this->columns->updateColumns($vars['columns'], $errors, array(
                                'queue_id' => $this->queue_id,
                                'staff_id' => $this->staff_id)))
                $setting['inherit-columns'] = true;
            $this->columns->reset();
        }

        $this->setting =  JsonDataEncoder::encode($setting);
        return $this->save(true);
    }

    function save($refetch=false) {
        if ($this->dirty)
            $this->updated = SqlFunction::NOW();
        return parent::save($refetch || $this->dirty);
    }

    static function create($vars=false) {
        $inst = new static($vars);
        return $inst;
    }
}

class QueueExport
extends VerySimpleModel {
    static $meta = array(
        'table' => QUEUE_EXPORT_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'queue' => array(
                'constraint' => array('queue_id' => 'CustomQueue.id'),
            ),
        ),
        'select_related' => array('queue'),
        'ordering' => array('sort'),
    );


    function getPath() {
        return $this->path;
    }

    function getField() {
        return $this->getPath();
    }

    function getHeading() {
        return $this->heading;
    }

    static function create($vars=false) {
        $inst = new static($vars);
        return $inst;
    }
}

class QueueColumnGlue
extends VerySimpleModel {
    static $meta = array(
        'table' => QUEUE_COLUMN_TABLE,
        'pk' => array('queue_id', 'staff_id', 'column_id'),
        'joins' => array(
            'column' => array(
                'constraint' => array('column_id' => 'QueueColumn.id'),
            ),
            'queue' => array(
                'constraint' => array(
                    'queue_id' => 'CustomQueue.id',
                    'staff_id' => 'CustomQueue.staff_id'),
            ),
            'config' => array(
                'constraint' => array(
                    'queue_id' => 'QueueConfig.queue_id',
                    'staff_id' => 'QueueConfig.staff_id'),
            ),
        ),
        'select_related' => array('column'),
        'ordering' => array('sort'),
    );
}

class QueueColumnGlueMIM
extends ModelInstanceManager {
    function getOrBuild($modelClass, $fields, $cache=true) {
        $m = parent::getOrBuild($modelClass, $fields, $cache);
        if ($m && $modelClass === 'QueueColumnGlue') {
            // Instead, yield the QueueColumn instance with the local fields
            // in the association table as annotations
            $m = AnnotatedModel::wrap($m->column, $m, 'QueueColumn');
        }
        return $m;
    }
}

class QueueColumnListBroker
extends InstrumentedList {
    function __construct($fkey, $queryset=false) {
        parent::__construct($fkey, $queryset, 'QueueColumnGlueMIM');
        $this->queryset->select_related('column');
    }

    function add($column, $glue=null, $php7_is_annoying=true) {
        $glue = $glue ?: new QueueColumnGlue();
        $glue->column = $column;
        $anno = AnnotatedModel::wrap($column, $glue);
        parent::add($anno, false);
        return $anno;
    }

    function updateColumns($columns, &$errors, $options=array()) {
        $new = $columns;
        $order = array_keys($new);
        foreach ($this as $col) {
            $key = $col->column_id;
            if (!isset($columns[$key])) {
                $this->remove($col);
                continue;
            }
            $info = $columns[$key];
            $col->set('sort', array_search($key, $order));
            $col->set('heading', $info['heading']);
            $col->set('width', $info['width']);
            $col->setSortable($info['sortable']);
            unset($new[$key]);
        }
        // Add new columns
        foreach ($new as $info) {
            $glue = new QueueColumnGlue(array(
                'staff_id' => $options['staff_id'] ?: 0 ,
                'queue_id' => $options['queue_id'] ?: 0,
                'column_id' => $info['column_id'],
                'sort' => array_search($info['column_id'], $order),
                'heading' => $info['heading'],
                'width' => $info['width'] ?: 100,
                'bits' => $info['sortable'] ?  QueueColumn::FLAG_SORTABLE : 0,
            ));

            $this->add(QueueColumn::lookup($info['column_id']), $glue);
        }
        // Re-sort the in-memory columns array
        $this->sort(function($c) { return $c->sort; });

        return $this->saveAll();
    }
}

class QueueSort
extends VerySimpleModel {
    static $meta = array(
        'table' => QUEUE_SORT_TABLE,
        'pk' => array('id'),
        'ordering' => array('name'),
        'joins' => array(
            'queue' => array(
                'constraint' => array('queue_id' => 'CustomQueue.id'),
            ),
        ),
    );

    var $_columns;
    var $_extra;

    function getRoot($hint=false) {
        switch ($hint ?: $this->root) {
        case 'T':
        default:
            return 'Ticket';
        }
    }

    function getName() {
        return $this->name;
    }

    function getId() {
        return $this->id;
    }

    function getExtra() {
        if (isset($this->extra) && !isset($this->_extra))
            $this->_extra = JsonDataParser::decode($this->extra);
        return $this->_extra;
    }

    function applySort(QuerySet $query, $reverse=false, $root=false) {
        $fields = CustomQueue::getSearchableFields($this->getRoot($root));
        foreach ($this->getColumnPaths() as $path=>$descending) {
            $descending = $reverse ? !$descending : $descending;
            if (isset($fields[$path])) {
                list(,$field) = $fields[$path];
                $query = $field->applyOrderBy($query, $descending,
                    CustomQueue::getOrmPath($path, $query));
            }
        }
        // Add index hint if defined
        if (($extra = $this->getExtra()) && isset($extra['index'])) {
            $query->setOption(QuerySet::OPT_INDEX_HINT, $extra['index']);
        }
        return $query;
    }

    function getColumnPaths() {
        if (!isset($this->_columns)) {
            $columns = array();
            foreach (JsonDataParser::decode($this->columns) as $path) {
                if ($descending = $path[0] == '-')
                    $path = substr($path, 1);
                $columns[$path] = $descending;
            }
            $this->_columns = $columns;
        }
        return $this->_columns;
    }

    function getColumns() {
        $columns = array();
        $paths = $this->getColumnPaths();
        $everything = CustomQueue::getSearchableFields($this->getRoot());
        foreach ($paths as $p=>$descending) {
            if (isset($everything[$p])) {
                $columns[$p] = array($everything[$p], $descending);
            }
        }
        return $columns;
    }

    function getDataConfigForm($source=false) {
        return new QueueSortDataConfigForm($source ?: $this->getDbFields(),
            array('id' => $this->id));
    }

    function getAdvancedConfigForm($source=false) {
        return new QueueSortAdvancedConfigForm($source ?: $this->getExtra(),
            array('id' => $this->id));
    }

    static function forQueue(CustomQueue $queue) {
        return static::objects()->filter([
            'root' => $queue->root ?: 'T',
        ]);
    }

    function save($refetch=false) {
        if ($this->dirty)
            $this->updated = SqlFunction::NOW();
        return parent::save($refetch || $this->dirty);
    }

    function update($vars, &$errors=array()) {
        if (!isset($vars['name']))
            $errors['name'] = __('A title is required');

        $this->name = $vars['name'];
        if (isset($vars['root']))
            $this->root = $vars['root'];
        elseif (!isset($this->root))
            $this->root = 'T';

        $fields = CustomQueue::getSearchableFields($this->getRoot($vars['root']));
        $columns = array();
        if (@is_array($vars['columns'])) {
            foreach ($vars['columns']as $path=>$info) {
                $descending = (int) @$info['descending'];
                // TODO: Check if column is valid, stash in $columns
                if (!isset($fields[$path]))
                    continue;
                $columns[] = ($descending ? '-' : '') . $path;
            }
            $this->columns = JsonDataEncoder::encode($columns);
        }

        if ($this->getExtra() !== null) {
            $extra = $this->getAdvancedConfigForm($vars)->getClean();
            $this->extra = JsonDataEncoder::encode($extra);
        }

        if (count($errors))
            return false;

        return $this->save();
    }

    static function __create($vars) {
        $c = new static($vars);
        $c->save();
        return $c;
    }
}

class QueueSortGlue
extends VerySimpleModel {
    static $meta = array(
        'table' => QUEUE_SORTING_TABLE,
        'pk' => array('sort_id', 'queue_id'),
        'joins' => array(
            'ordering' => array(
                'constraint' => array('sort_id' => 'QueueSort.id'),
            ),
            'queue' => array(
                'constraint' => array('queue_id' => 'CustomQueue.id'),
            ),
        ),
        'select_related' => array('ordering', 'queue'),
        'ordering' => array('sort'),
    );
}

class QueueSortGlueMIM
extends ModelInstanceManager {
    function getOrBuild($modelClass, $fields, $cache=true) {
        $m = parent::getOrBuild($modelClass, $fields, $cache);
        if ($m && $modelClass === 'QueueSortGlue') {
            // Instead, yield the QueueColumn instance with the local fields
            // in the association table as annotations
            $m = AnnotatedModel::wrap($m->ordering, $m, 'QueueSort');
        }
        return $m;
    }
}

class QueueSortListBroker
extends InstrumentedList {
    function __construct($fkey, $queryset=false) {
        parent::__construct($fkey, $queryset, 'QueueSortGlueMIM');
        $this->queryset->select_related('ordering');
    }

    function add($ordering, $glue=null, $php7_is_annoying=true) {
        $glue = $glue ?: new QueueSortGlue();
        $glue->ordering = $ordering;
        $anno = AnnotatedModel::wrap($ordering, $glue);
        parent::add($anno, false);
        return $anno;
    }
}
