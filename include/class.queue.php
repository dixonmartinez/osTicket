<?php
/*********************************************************************
    class.queue.php

    Custom (ticket) queues for osTicket

    Jared Hancock <jared@osticket.com>
    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2015 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

/* Columnas, decoraciones y condiciones: ver class.queue.column.php */
include_once(INCLUDE_DIR . 'class.queue.column.php');
/* Filtros de columna: ver class.queue.filter.php */
include_once(INCLUDE_DIR . 'class.queue.filter.php');
/* Reparto de las ordenaciones del POST: ver class.queue.sort.php */
include_once(INCLUDE_DIR . 'class.queue.sort.php');

class CustomQueue extends VerySimpleModel {
    static $meta = array(
        'table' => QUEUE_TABLE,
        'pk' => array('id'),
        'ordering' => array('sort'),
        'select_related' => array('parent', 'default_sort'),
        'joins' => array(
            'children' => array(
                'reverse' => 'CustomQueue.parent',
                'constrain' => ['children__id__gt' => 0],
            ),
            'columns' => array(
                'reverse' => 'QueueColumnGlue.queue',
                'constrain' => array('staff_id' =>'QueueColumnGlue.staff_id'),
                'broker' => 'QueueColumnListBroker',
            ),
            'sorts' => array(
                'reverse' => 'QueueSortGlue.queue',
                'broker' => 'QueueSortListBroker',
            ),
            'default_sort' => array(
                'constraint' => array('sort_id' => 'QueueSort.id'),
                'null' => true,
            ),
            'exports' => array(
                'reverse' => 'QueueExport.queue',
            ),
            'parent' => array(
                'constraint' => array(
                    'parent_id' => 'CustomQueue.id',
                ),
                'null' => true,
            ),
            'staff' => array(
                'constraint' => array(
                    'staff_id' => 'Staff.staff_id',
                )
            ),
        )
    );

    const FLAG_PUBLIC =           0x0001; // Shows up in e'eryone's saved searches
    const FLAG_QUEUE =            0x0002; // Shows up in queue navigation
    const FLAG_DISABLED =         0x0004; // NOT enabled
    const FLAG_INHERIT_CRITERIA = 0x0008; // Include criteria from parent
    const FLAG_INHERIT_COLUMNS =  0x0010; // Inherit column layout from parent
    const FLAG_INHERIT_SORTING =  0x0020; // Inherit advanced sorting from parent
    const FLAG_INHERIT_DEF_SORT = 0x0040; // Inherit default selected sort
    const FLAG_INHERIT_EXPORT  =  0x0080; // Inherit export fields from parent


    const FLAG_INHERIT_EVERYTHING = 0x158; // Maskf or all INHERIT flags

    var $criteria;
    var $_conditions;

    static function queues() {
        return parent::objects()->filter(array(
            'flags__hasbit' => static::FLAG_QUEUE
        ));
    }

    function __onload() {
        // Ensure valid state
        if ($this->hasFlag(self::FLAG_INHERIT_COLUMNS) && !$this->parent_id)
            $this->clearFlag(self::FLAG_INHERIT_COLUMNS);

       if ($this->hasFlag(self::FLAG_INHERIT_EXPORT) && !$this->parent_id)
            $this->clearFlag(self::FLAG_INHERIT_EXPORT);
    }

    function getId() {
        return $this->id;
    }

    function getName() {
        return $this->title;
    }

    function getHref() {
        // TODO: Get base page from getRoot();
        $root = $this->getRoot();
        return 'tickets.php?queue='.$this->getId();
    }

    function getRoot() {
        switch ($this->root) {
        case 'T':
        default:
            return 'Ticket';
        }
    }

    function getPath() {
        return $this->path ?: $this->buildPath();
    }

    function criteriaRequired() {
        return true;
    }

    function getCriteria($include_parent=false) {
        if (!isset($this->criteria)) {
            $this->criteria = is_string($this->config)
                ? JsonDataParser::decode($this->config)
                : $this->config;
            // XXX: Drop this block in v1.12
            // Auto-upgrade v1.10 saved-search criteria to new format
            // But support new style with `conditions` support
            $old = @$this->config[0] === '{';
            if ($old && is_array($this->criteria)
                && !isset($this->criteria['conditions'])
            ) {
                // TODO: Upgrade old ORM path names
                // Parse criteria out of JSON if any.
                $this->criteria = self::isolateCriteria($this->criteria,
                        $this->getRoot());
            }
        }
        $criteria = $this->criteria ?: array();
        // Support new style with `conditions` support
        if (isset($criteria['criteria']))
            $criteria = $criteria['criteria'];
        if ($include_parent && $this->parent_id && $this->parent) {
            $criteria = array_merge($this->parent->getCriteria(true),
                $criteria);
        }
        return $criteria;
    }

    function describeCriteria($criteria=false){
        global $account;

        if (!($all = $this->getSupportedMatches($this->getRoot())))
            return '';

        $items = array();
        $criteria = $criteria ?: $this->getCriteria(true);
        foreach ($criteria ?: array() as $C) {
            list($path, $method, $value) = $C;
            if ($path === ':keywords') {
                $items[] = Format::htmlchars("\"{$value}\"");
                continue;
            }
            if (!isset($all[$path]))
                continue;
            list($label, $field) = $all[$path];
            $items[] = $field->describeSearch($method, $value, $label);
        }
        return implode("\nAND ", $items);
    }

    /**
     * Fetch an AdvancedSearchForm instance for use in displaying or
     * configuring this search in the user interface.
     *
     * Parameters:
     * $search - <array> Request parameters ($_POST) used to update the
     *      search beyond the current configuration of the search criteria
     * $searchables - search fields - default to current if not provided
     */
    function getForm($source=null, $searchable=null) {
        $fields = array();
        if (!isset($searchable)) {
            $fields = array(
                ':keywords' => new TextboxField(array(
                    'id' => 3001,
                    'configuration' => array(
                        'size' => 40,
                        'length' => 400,
                        'autofocus' => true,
                        'classes' => 'full-width headline',
                        'placeholder' => __('Keywords — Optional'),
                    ),
                    'validators' => function($self, $v) {
                        if (mb_str_wc($v) > 3)
                            $self->addError(__('Search term cannot have more than 3 keywords'));
                    },
                )),
            );

            $searchable = $this->getCurrentSearchFields($source);
        }

        foreach ($searchable ?: array() as $path => $field)
            $fields = array_merge($fields, static::getSearchField($field, $path));

        $form = new AdvancedSearchForm($fields, $source);

        // Field selection validator
        if ($this->criteriaRequired()) {
            $form->addValidator(function($form) {
                    if (!$form->getNumFieldsSelected())
                        $form->addError(__('No fields selected for searching'));
                });
        }

        // Load state from current configuraiton
        if (!$source) {
            foreach ($this->getCriteria() as $I) {
                list($path, $method, $value) = $I;
                if ($path == ':keywords' && $method === null) {
                    if ($F = $form->getField($path))
                        $F->value = $value;
                    continue;
                }

                if (!($F = $form->getField("{$path}+search")))
                    continue;
                $F->value = true;

                if (!($F = $form->getField("{$path}+method")))
                    continue;
                $F->value = $method;

                if ($value && ($F = $form->getField("{$path}+{$method}")))
                    $F->value = $value;
            }
        }
        return $form;
    }

    /**
     * Fetch a bucket of fields for a custom search. The fields should be
     * added to a form before display. One searchable field may encompass 10
     * or more actual fields because fields are expanded to support multiple
     * search methods along with the fields for each search method. This
     * method returns all the FormField instances for all the searchable
     * model fields currently in use.
     *
     * Parameters:
     * $source - <array> data from a request. $source['fields'] is expected
     *      to contain a list extra fields by ORM path, of newly added
     *      fields not yet saved in this object's getCriteria().
     */
    function getCurrentSearchFields($source=array(), $criteria=array()) {
        static $basic = array(
            'Ticket' => array(
                'status__id',
                'status__state',
                'dept_id',
                'assignee',
                'topic_id',
                'created',
                'est_duedate',
                'duedate',
            )
        );

        $all = $this->getSupportedMatches();
        $core = array();

        // Include basic fields for new searches
        if (!isset($this->id))
            foreach ($basic[$this->getRoot()] as $path)
                if (isset($all[$path]))
                    $core[$path] = $all[$path];

        // Add others from current configuration
        foreach ($criteria ?: $this->getCriteria() as $C) {
            list($path) = $C;
            if (isset($all[$path]))
                $core[$path] = $all[$path];
        }

        if (isset($source['fields']))
            foreach ($source['fields'] as $path)
                if (isset($all[$path]))
                    $core[$path] = $all[$path];

        return $core;
    }

    /**
    * Fetch all supported ORM fields filterable by this search object.
    */
    function getSupportedFilters() {
        return static::getFilterableFields($this->getRoot());
    }


    /**
     * Get get supplemental matches for public queues.
     *
     */

    function getSupplementalMatches() {
        return array();
    }

    function getSupplementalCriteria() {
        return array();
    }

    /**
     * Fetch all supported ORM fields searchable by this search object. The
     * returned list represents searchable fields, keyed by the ORM path.
     * Use ::getCurrentSearchFields() or ::getSearchField() to retrieve for
     * use in the user interface.
     */
    function getSupportedMatches() {
        return static::getSearchableFields($this->getRoot());
    }

    /**
     * Trace ORM fields from a base object and retrieve a complete list of
     * fields which can be used in an ORM query based on the base object.
     * The base object must implement Searchable interface and extend from
     * VerySimpleModel. Then all joins from the object are also inspected,
     * and any which implement the Searchable interface are traversed and
     * automatically added to the list. The resulting list is cached based
     * on the $base class, so multiple calls for the same $base return
     * quickly.
     *
     * Parameters:
     * $base - Class, name of a class implementing Searchable
     * $recurse - int, number of levels to recurse, default is 2
     * $cache - bool, cache results for future class for the same base
     * $customData - bool, include all custom data fields for all general
     *      forms
     */
    static function getSearchableFields($base, $recurse=2,
        $customData=true, $exclude=array()
    ) {
        static $cache = array(), $otherFields;

        // Early exit if already cached
        $fields = &$cache[$base];
        if ($fields)
            return $fields;

        if (!in_array('Searchable', class_implements($base)))
            return array();

        $fields = $fields ?: array();
        foreach ($base::getSearchableFields() as $path=>$F) {
            if (is_array($F)) {
                list($label, $field) = $F;
            }
            else {
                $label = $F->getLocal('label');
                $field = $F;
            }
            $fields[$path] = array($label, $field);
        }

        if ($customData && $base::supportsCustomData()) {
            if (!isset($otherFields)) {
                $otherFields = array();
                $dfs = DynamicFormField::objects()
                    ->filter(array('form__type' => 'G'))
                    ->select_related('form');
                foreach ($dfs as $field) {
                    $otherFields[$field->getId()] = array($field->form,
                        $field->getImpl());
                }
            }
            foreach ($otherFields as $id=>$F) {
                list($form, $field) = $F;
                $label = sprintf("%s / %s",
                    $form->getTitle(), $field->getLocal('label'));
                $fields["entries__answers!{$id}__value"] = array(
                    $label, $field);
            }
        }

        if ($recurse) {
            $exclude[$base] = 1;
            foreach ($base::getMeta('joins') as $path=>$j) {
                $fc = $j['fkey'][0];
                if (isset($exclude[$fc]) || (isset($j['list']) && $j['list'] === true)
                        || (isset($j['searchable']) && $j['searchable'] === false))
                    continue;
                foreach (static::getSearchableFields($fc, $recurse-1,
                    true, $exclude)
                as $path2=>$F) {
                    list($label, $field) = $F;
                    $fields["{$path}__{$path2}"] = array(
                        sprintf("%s / %s", $fc, $label),
                        $field);
                }
            }
        }

        // Sort the field listing by the (localized) label name
        if (function_exists('collator_create')) {
            $coll = Collator::create(Internationalization::getCurrentLanguage());
            $keys = array_map(function($a) use ($coll) {
                return $coll->getSortKey($a[0]); #nolint
            }, $fields);
        }
        else {
            // Fall back to 8-bit string sorting
            $keys = array_map(function($a) { return $a[0]; }, $fields);
        }
        array_multisort($keys, $fields);

        return $fields;
    }

  /**
     * Fetch all searchable fileds, for the base object  which support quick filters.
     */
    function getFilterableFields($object) {
        $filters = array();
        foreach (static::getSearchableFields($object) as $p => $f) {
            list($label, $field) = $f;
            if ($field && $field->supportsQuickFilter())
                $filters[$p] = $f;
        }

        return $filters;
    }

    /**
     * Fetch the FormField instances used when for configuring a searchable
     * field in the user interface. This is the glue between a field
     * representing a searchable model field and the configuration of that
     * search in the user interface.
     *
     * Parameters:
     * $F - <array<string, FormField>> the label and the FormField instance
     *      representing the configurable search
     * $name - <string> ORM path for the search
     */
    static function getSearchField($F, $name) {
        list($label, $field) = $F;

        $pieces = array();
        $pieces["{$name}+search"] = new BooleanField(array(
            'id' => sprintf('%u', crc32($name)) >> 1,
            'configuration' => array(
                'desc' => $label ?: $field->getLocal('label'),
                'classes' => 'inline',
            ),
        ));
        $methods = $field->getSearchMethods();

        //remove future options for datetime fields that can't be in the future
        if (in_array($field->getLabel(), DateTimeField::getPastPresentLabels()))
          unset($methods['ndays'], $methods['future'], $methods['distfut']);

        $pieces["{$name}+method"] = new ChoiceField(array(
            'choices' => $methods,
            'default' => key($methods),
            'visibility' => new VisibilityConstraint(new Q(array(
                "{$name}+search__eq" => true,
            )), VisibilityConstraint::HIDDEN),
        ));
        $offs = 0;
        foreach ($field->getSearchMethodWidgets() as $m=>$w) {
            if (!$w)
                continue;
            list($class, $args) = $w;
            $args['required'] = true;
            $args['__searchval__'] = true;
            $args['visibility'] = new VisibilityConstraint(new Q(array(
                    "{$name}+method__eq" => $m,
                )), VisibilityConstraint::HIDDEN);
            $pieces["{$name}+{$m}"] = new $class($args);
        }
        return $pieces;
    }

    function getField($path) {
        $searchable = $this->getSupportedMatches();
        return $searchable[$path];
    }

    // Remove this and adjust advanced-search-criteria template to use the
    // getCriteria() list and getField()
    function getSearchFields($form=false) {
        $form = $form ?: $this->getForm();
        $searchable = $this->getCurrentSearchFields();
        $info = array();
        foreach ($form->getFields() as $f) {
            if (substr($f->get('name'), -7) == '+search') {
                $name = substr($f->get('name'), 0, -7);
                $value = null;
                // Determine the search method and fetch the original field
                if (($M = $form->getField("{$name}+method"))
                    && ($method = $M->getClean())
                    && (list(,$field) = $searchable[$name])
                ) {
                    // Request the field to generate a search Q for the
                    // search method and given value
                    if ($value = $form->getField("{$name}+{$method}"))
                        $value = $value->getClean();
                }
                $info[$name] = array(
                    'field' => $field,
                    'method' => $method,
                    'value' => $value,
                    'active' =>  $f->getClean(),
                );
            }
        }
        return $info;
    }

    /**
     * Take the criteria from the SavedSearch fields setup and isolate the
     * field name being search, the method used for searhing, and the method-
     * specific data entered in the UI.
     */
    static function isolateCriteria($criteria, $base='Ticket') {

        if (!is_array($criteria))
            return null;

        $items = array();
        $searchable = static::getSearchableFields($base);
        foreach ($criteria as $k=>$v) {
            if (substr($k, -7) === '+method') {
                list($name,) = explode('+', $k, 2);
                if (!isset($searchable[$name]))
                    continue;

                // Require checkbox to be checked too
                if (!$criteria["{$name}+search"])
                    continue;

                // Lookup the field to search this condition
                list($label, $field) = $searchable[$name];
                // Get the search method
                $method = is_array($v) ? key($v) : $v;
                // Not all search methods require a value
                $value = $criteria["{$name}+{$method}"];

                $items[] = array($name, $method, $value);
            }
        }
        if (isset($criteria[':keywords'])
            && ($kw = $criteria[':keywords'])
        ) {
            $items[] = array(':keywords', null, $kw);
        }
        return $items;
    }

    function getConditions() {
        if (!isset($this->_conditions)) {
            $this->getCriteria();
            $conds = array();
            if (is_array($this->criteria)
                && isset($this->criteria['conditions'])
            ) {
                $conds = $this->criteria['conditions'];
            }
            foreach ($conds as $C)
                if ($T = QueueColumnCondition::fromJson($C))
                    $this->_conditions[] = $T;
        }
        return $this->_conditions;
    }

    static function getExportableFields() {
        $cdata = $fields = array();
        foreach (TicketForm::getInstance()->getFields() as $f) {
            // Ignore core fields
            if (in_array($f->get('name'), array('priority')))
                continue;
            // Ignore non-data fields
            elseif (!$f->hasData() || $f->isPresentationOnly())
                continue;
            // Ignore disabled fields
            elseif (!$f->hasFlag(DynamicFormField::FLAG_ENABLED))
                continue;

            $name = $f->get('name') ?: 'field_'.$f->get('id');
            $key = 'cdata__'.$name;
            $cdata[$key] = $f->getLocal('label');
        }

        // Standard export fields if none is provided.
        $fields = array(
                'number' =>         __('Ticket Number'),
                'created' =>        __('Date Created'),
                'cdata__subject' =>  __('Subject'),
                'user__name' =>      __('From'),
                'user__emails__address' => __('From Email'),
                'cdata__priority' => __('Priority'),
                'dept_id' => __('Department'),
                'topic_id' => __('Help Topic'),
                'source' =>         __('Source'),
                'status__id' =>__('Current Status'),
                'lastupdate' =>     __('Last Updated'),
                'est_duedate' =>    __('SLA Due Date'),
                'sla_id' => __('SLA Plan'),
                'duedate' =>        __('Due Date'),
                'closed' =>         __('Closed Date'),
                'isoverdue' =>      __('Overdue'),
                'merged' =>       __('Merged'),
                'linked' =>       __('Linked'),
                'isanswered' =>     __('Answered'),
                'staff_id' => __('Agent Assigned'),
                'team_id' =>  __('Team Assigned'),
                'thread_count' =>   __('Thread Count'),
                'reopen_count' =>   __('Reopen Count'),
                'attachment_count' => __('Attachment Count'),
                'task_count' => __('Task Count'),
                ) + $cdata;

        return $fields;
    }

    function getExportFields($inherit=true) {

        $fields = array();
        if ($inherit
            && $this->parent_id
            && $this->hasFlag(self::FLAG_INHERIT_EXPORT)
            && $this->parent
        ) {
            $fields = $this->parent->getExportFields();
        }
        elseif (count($this->exports)) {
            foreach ($this->exports as $f)
                $fields[$f->path] = $f->getHeading();
        }
        elseif ($this->isAQueue())
            $fields = $this->getExportableFields();

        if (!count($fields))
            $fields = $this->getExportableFields();

        return $fields;
    }

    function getExportColumns($fields=array()) {
        $columns = array();
        $fields = $fields ?: $this->getExportFields();
        $i = 0;
        foreach ($fields as $path => $label) {
            $c = QueueColumn::placeholder(array(
                        'id' => $i++,
                        'heading' => $label,
                        'primary' => $path,
                        ));
            $c->setQueue($this);
            $columns[$path] = $c;
        }
        return $columns;
    }

    function getStandardColumns() {
        return $this->getColumns();
    }

    function getColumns($use_template=false) {
        if ($this->columns_id
            && ($q = CustomQueue::lookup($this->columns_id))
        ) {
            // Use columns from cited queue
            return $q->getColumns();
        }
        elseif ($this->parent_id
            && $this->hasFlag(self::FLAG_INHERIT_COLUMNS)
            && $this->parent
        ) {
            $columns = $this->parent->getColumns();
            foreach ($columns as $c)
                $c->setQueue($this);
            return $columns;
        }
        elseif (count($this->columns)) {
            return $this->columns;
        }

        // Use the columns of the "Open" queue as a default template
        if ($use_template && ($template = CustomQueue::lookup(1)))
            return $template->getColumns();

        // Last resort — use standard columns
        foreach (array(
            QueueColumn::placeholder(array(
                "id" => 1,
                "heading" => __("Number"),
                "primary" => 'number',
                "width" => 85,
                "bits" => QueueColumn::FLAG_SORTABLE,
                "filter" => "link:ticketP",
                "annotations" => '[{"c":"TicketSourceDecoration","p":"b"}, {"c":"MergedFlagDecoration","p":">"}]',
                "conditions" => '[{"crit":["isanswered","nset",null],"prop":{"font-weight":"bold"}}]',
            )),
            QueueColumn::placeholder(array(
                "id" => 2,
                "heading" => __("Created"),
                "primary" => 'created',
                "filter" => 'date:full',
                "truncate" =>'wrap',
                "width" => 120,
                "bits" => QueueColumn::FLAG_SORTABLE,
            )),
            QueueColumn::placeholder(array(
                "id" => 3,
                "heading" => __("Subject"),
                "primary" => 'cdata__subject',
                "width" => 250,
                "bits" => QueueColumn::FLAG_SORTABLE,
                "filter" => "link:ticket",
                "annotations" => '[{"c":"TicketThreadCount","p":">"},{"c":"ThreadAttachmentCount","p":"a"},{"c":"OverdueFlagDecoration","p":"<"}]',
                "conditions" => '[{"crit":["isanswered","nset",null],"prop":{"font-weight":"bold"}}]',
                "truncate" => 'ellipsis',
            )),
            QueueColumn::placeholder(array(
                "id" => 4,
                "heading" => __("From"),
                "primary" => 'user__name',
                "width" => 150,
                "bits" => QueueColumn::FLAG_SORTABLE,
            )),
            QueueColumn::placeholder(array(
                "id" => 5,
                "heading" => __("Priority"),
                "primary" => 'cdata__priority',
                "width" => 120,
                "bits" => QueueColumn::FLAG_SORTABLE,
            )),
            QueueColumn::placeholder(array(
                "id" => 8,
                "heading" => __("Assignee"),
                "primary" => 'assignee',
                "width" => 100,
                "bits" => QueueColumn::FLAG_SORTABLE,
            )),
        ) as $col)
            $this->addColumn($col);

        return $this->getColumns();
    }

    function addColumn(QueueColumn $col) {
        $this->columns->add($col);
        $col->queue = $this;
    }

    function getColumn($id) {
        // TODO: Got to be easier way to search instrumented list.
        foreach ($this->getColumns() as $C)
            if ($C->getId() == $id)
                return $C;
    }

    function getSortOptions() {
        if ($this->inheritSorting() && $this->parent) {
            return $this->parent->getSortOptions();
        }
        return $this->sorts;
    }

    function getDefaultSortId() {
        if ($this->isDefaultSortInherited() && $this->parent
            && ($sort_id = $this->parent->getDefaultSortId())
        ) {
            return $sort_id;
        }
        return $this->sort_id;
    }

    function getDefaultSort() {
        if ($this->isDefaultSortInherited() && $this->parent
            && ($sort = $this->parent->getDefaultSort())
        ) {
            return $sort;
        }
        return $this->default_sort;
    }

    function getStatus() {
        return $this->hasFlag(self::FLAG_DISABLED)
            ? __('Disabled') : __('Active');
    }

    function getChildren() {
        return $this->children;
    }

    function getPublicChildren() {
        return $this->children->findAll(array(
            'flags__hasbit' => self::FLAG_QUEUE
        ));
    }

    function getMyChildren() {
        global $thisstaff;
        if (!$thisstaff instanceof Staff)
            return array();

        return $this->children->findAll(array(
            'staff_id' => $thisstaff->getId(),
            Q::not(array(
                'flags__hasbit' => self::FLAG_PUBLIC
            ))
        ));
    }

    function export(CsvExporter $exporter, $options=array()) {
        global $thisstaff;

        if (!$thisstaff
                || !($query=$this->getQuery())
                || !($fields=$this->getExportFields()))
            return false;

        // Do not store results in memory
        $query->setOption(QuerySet::OPT_NOCACHE, true);

        // See if we have cached export preference
        if (isset($_SESSION['Export:Q'.$this->getId()])) {
            $opts = $_SESSION['Export:Q'.$this->getId()];
            if (isset($opts['fields'])) {
                $fields = array_intersect_key($fields,
                        array_flip($opts['fields']));
                $exportableFields = CustomQueue::getExportableFields();
                foreach ($opts['fields'] as $key => $name) {
                    if (is_null($fields[$name]) && isset($exportableFields)) {
                        $fields[$name] = $exportableFields[$name];
                    }
                 }
            }
        }

        // Apply columns
        $columns = $this->getExportColumns($fields);
        $headers = array(); // Reset fields based on validity of columns
        foreach ($columns as $column) {
            $query = $column->mangleQuery($query, $this->getRoot());
            $headers[] = $column->getHeading();
        }

        // Apply visibility
        if (!$this->ignoreVisibilityConstraints($thisstaff))
            $query->filter($thisstaff->getTicketsVisibility());

        // Get stashed sort or else get the default
        if (!($sort = $_SESSION['sort'][$this->getId()]))
            $sort = $this->getDefaultSort();

        // Apply sort
        if ($sort instanceof QueueSort)
            $sort->applySort($query);
        elseif ($sort && isset($sort['queuesort']))
            $sort['queuesort']->applySort($query, $sort['dir']);
        elseif ($sort && $sort['col'] &&
                ($C=$this->getColumn($sort['col'])))
            $query = $C->applySort($query, $sort['dir']);
        else
            $query->order_by('-created');

        // Distinct ticket_id to avoid duplicate results
        $query->distinct('ticket_id');

        // Render Util
        $render = function ($row) use($columns) {
            if (!$row) return false;

            $record = array();
            foreach ($columns as $path => $column) {
                $record[] = (string) $column->from_query($row) ?:
                    $row[$path] ?: '';
            }
            return $record;
        };

        $exporter->write($headers);
        foreach ($query as $row)
            $exporter->write($render($row));
    }

    /**
     * Add critiera to a query based on the constraints configured for this
     * queue. The criteria of the parent queue is also automatically added
     * if the queue is configured to inherit the criteria.
     */
    function getBasicQuery() {
        if ($this->parent && $this->inheritCriteria()) {
            $query = $this->parent->getBasicQuery();
        }
        else {
            $root = $this->getRoot();
            $query = $root::objects();
        }
        return $this->mangleQuerySet($query);
    }

    /**
     * Retrieve a QuerySet instance based on the type of object (root) of
     * this Q, which is automatically configured with the data and criteria
     * of the queue and its columns.
     *
     * Returns:
     * <QuerySet> instance
     */
    function getQuery($form=false, $quick_filter=null) {
        // Start with basic criteria
        $query = $this->getBasicQuery($form);

        // Apply quick filter
        if (isset($quick_filter)
            && ($qf = $this->getQuickFilterField($quick_filter))
        ) {
            $filter = @self::getOrmPath($this->getQuickFilter(), $query);
            $query = $qf->applyQuickFilter($query, $quick_filter,
                $filter);
        }

        // Apply column, annotations and conditions additions
        foreach ($this->getColumns() as $C) {
            $C->setQueue($this);
            $query = $C->mangleQuery($query, $this->getRoot());
        }
        return $query;
    }

    function getQuickFilter() {
        if ($this->filter == '::' && $this->parent) {
            return $this->parent->getQuickFilter();
        }
        return $this->filter;
    }

    function getQuickFilterField($value=null) {
        if ($this->filter == '::') {
            if ($this->parent) {
                return $this->parent->getQuickFilterField($value);
            }
        }
        elseif ($this->filter
            && ($fields = self::getSearchableFields($this->getRoot()))
            && (list(,$f) = @$fields[$this->filter])
            && $f->supportsQuickFilter()
        ) {
            $f->value = $value;
            return $f;
        }
    }

    /**
     * Get a description of a field in a search. Expects an entry from the
     * array retrieved in ::getSearchFields()
     */
    function describeField($info, $name=false) {
        $name = $name ?: $info['field']->get('label');
        return $info['field']->describeSearch($info['method'], $info['value'], $name);
    }

    function mangleQuerySet(QuerySet $qs, $form=false) {
        $qs = clone $qs;
        $searchable = $this->getSupportedMatches();

        // Figure out fields to search on
        foreach ($this->getCriteria() as $I) {
            list($name, $method, $value) = $I;

            // Consider keyword searching
            if ($name === ':keywords') {
                global $ost;
                $qs = $ost->searcher->find($value, $qs, false, ['boolean' => false]);
            }
            else {
                $nullable = ($method === 'nset') ? false : null;
                // XXX: Move getOrmPath to be more of a utility
                // Ensure the special join is created to support custom data joins
                $name = @static::getOrmPath($name, $qs, $nullable);

                if (preg_match('/__answers!\d+__/', $name)) {
                    $qs->annotate(array($name => SqlAggregate::MAX($name)));
                }

                // Fetch a criteria Q for the query
                if (list(,$field) = $searchable[$name]) {
                    // Add annotation if the field supports it.
                    if (is_subclass_of($field, 'AnnotatedField'))
                       $qs = $field->annotate($qs, $name);

                    if ($q = $field->getSearchQ($method, $value, $name))
                        $qs = $qs->filter($q);
                }
            }
        }

        return $qs;
    }

    function applyDefaultSort($qs) {
        // Apply default sort
        if ($sorter = $this->getDefaultSort()) {
            $qs = $sorter->applySort($qs, false, $this->getRoot());
        }
        return $qs;
    }

    function checkAccess(Staff $agent) {
        return $this->isPublic() || $this->checkOwnership($agent);
    }

    function checkOwnership(Staff $agent) {

        return ($agent->getId() == $this->staff_id &&
                !$this->isAQueue());
    }

    function isOwner(Staff $agent) {
        return $agent && $this->isPrivate() && $this->checkOwnership($agent);
    }

    function isSaved() {
        return true;
    }

    function ignoreVisibilityConstraints(Staff $agent) {
        // For searches (not queues), some staff can have a permission to
        // see all records
        return ($this->isASearch()
                && $this->isOwner($agent)
                && $agent->canSearchEverything());
    }

    function inheritCriteria() {
        return $this->flags & self::FLAG_INHERIT_CRITERIA &&
            $this->parent_id;
    }

    function inheritColumns() {
        return $this->hasFlag(self::FLAG_INHERIT_COLUMNS);
    }

    function useStandardColumns() {
        return ($this->hasFlag(self::FLAG_INHERIT_COLUMNS) ||
                !count($this->columns));
    }

    function inheritExport() {
        return ($this->hasFlag(self::FLAG_INHERIT_EXPORT) ||
                !count($this->exports));
    }

    function inheritSorting() {
        return $this->hasFlag(self::FLAG_INHERIT_SORTING);
    }

    function isDefaultSortInherited() {
        return $this->hasFlag(self::FLAG_INHERIT_DEF_SORT);
    }

    function buildPath() {
        if (!$this->id)
            return;

        $path = $this->parent ? $this->parent->buildPath() : '';
        return rtrim($path, "/") . "/{$this->id}/";
    }

    function getFullName() {
        $base = $this->getName();
        if ($this->parent)
            $base = sprintf("%s / %s", $this->parent->getFullName(), $base);
        return $base;
    }

    function isASubQueue() {
        return $this->parent ? $this->parent->isASubQueue() :
            $this->isAQueue();
    }

    function isAQueue() {
        return $this->hasFlag(self::FLAG_QUEUE);
    }

    function isASearch() {
        return !$this->isAQueue() || !$this->isSaved();
    }

    function isPrivate() {
        return !$this->isAQueue() && $this->staff_id;
    }

    function isPublic() {
        return $this->hasFlag(self::FLAG_PUBLIC);
    }

    protected function hasFlag($flag) {
        return ($this->flags & $flag) !== 0;
    }

    protected function clearFlag($flag) {
        return $this->flags &= ~$flag;
    }

    protected function setFlag($flag, $value=true) {
        return $value
            ? $this->flags |= $flag
            : $this->clearFlag($flag);
    }

    function disable() {
        $this->setFlag(self::FLAG_DISABLED);
    }

    function enable() {
        $this->clearFlag(self::FLAG_DISABLED);
    }

    function getRoughCount() {
        if (($count = $this->getRoughCountAPC()) !== false)
            return $count;

        $query = Ticket::objects();
        $Q = $this->getBasicQuery();
        $expr = SqlCase::N()->when(new SqlExpr(new Q($Q->constraints)),
            new SqlField('ticket_id'));
        $query = $query->aggregate(array(
            "ticket_count" => SqlAggregate::COUNT($expr)
        ));

        $row = $query->values()->one();
        return $row['ticket_count'];
    }

    function getRoughCountAPC() {
        if (!function_exists('apcu_store'))
            return false;

        $key = "rough.counts.".SECRET_SALT;
        $cached = false;
        $counts = apcu_fetch($key, $cached);
        if ($cached === true && isset($counts["q{$this->id}"]))
            return $counts["q{$this->id}"];

        // Fetch rough counts of all queues. That is, fetch a total of the
        // counts based on the queue criteria alone. Do no consider agent
        // access. This should be fast and "rought"
        $queues = static::objects()
            ->filter(['flags__hasbit' => CustomQueue::FLAG_PUBLIC])
            ->exclude(['flags__hasbit' => CustomQueue::FLAG_DISABLED]);

        $query = Ticket::objects();
        $prefix = "";

        foreach ($queues as $queue) {
            $Q = $queue->getBasicQuery();
            $expr = SqlCase::N()->when(new SqlExpr(new Q($Q->constraints)),
                new SqlField('ticket_id'));
            $query = $query->aggregate(array(
                "q{$queue->id}" => SqlAggregate::COUNT($expr)
            ));
        }

        $counts = $query->values()->one();

        apcu_store($key, $counts, 900);
        return @$counts["q{$this->id}"];
    }

    function updateExports($fields, $save=true) {

        if (!$fields)
            return false;

        $order = array_keys($fields);

        $new = $fields;
        foreach ($this->exports as $f) {
            $heading = $f->getHeading();
            $key = $f->getPath();
            if (!isset($fields[$key])) {
                $this->exports->remove($f);
                continue;
            }

            $f->set('heading', isset($fields[$key]['heading']) ? $fields[$key]['heading'] : $heading);
            $f->set('sort', array_search($key, $order)+1);
            unset($new[$key]);
        }

        $exportableFields = CustomQueue::getExportableFields();
        foreach ($new as $k => $field) {
            if (isset($exportableFields[$k]))
                $heading = $exportableFields[$k];
            elseif (is_array($field))
                $heading = $field['heading'];
            else
                $heading = $field;

            $f = QueueExport::create(array(
                        'path' => $k,
                        'heading' => $heading,
                        'sort' => array_search($k, $order)+1));
            $this->exports->add($f);
        }

        $this->exports->sort(function($f) { return $f->sort; });

        if (!count($this->exports) && $this->parent)
            $this->hasFlag(self::FLAG_INHERIT_EXPORT);

        if ($save)
            $this->exports->saveAll();

        return true;
    }

    /**
     * Guarda la cola con lo que llega del formulario.
     *
     * Cada paso tiene su método y van en el mismo orden que antes: los errores
     * de un paso se suman al array y se devuelven todos juntos al final, para
     * que el agente no arregle el formulario de uno en uno.
     */
    function update($vars, &$errors=array()) {

        $this->setupTitle($vars, $errors);
        $this->setupFilter($vars, $errors);
        $this->setupInheritance($vars);
        $this->setupColumns($vars, $errors);
        $this->setupExports($vars);
        $this->setupSorts($vars);
        $this->setupDefaultSort($vars, $errors);
        $this->setupCriteria($vars, $errors);

        return 0 === count($errors);
    }

    /**
     * Título y cola padre. `path` no se calcula aquí: depende del padre que se
     * acaba de asignar y lo hace `setupInheritance()`.
     */
    function setupTitle($vars, &$errors) {
        // Set basic search information
        if (!$vars['queue-name'])
            $errors['queue-name'] = __('A title is required');
        elseif (($q=CustomQueue::lookup(array(
                        'title' => Format::htmlchars($vars['queue-name']),
                        'parent_id' => $vars['parent_id'] ?: 0,
                        'staff_id'  => $this->staff_id)))
                && $q->getId() != $this->id
                )
            $errors['queue-name'] = __('Saved queue with same name exists');

        $this->title = Format::htmlchars($vars['queue-name']);
        $this->parent_id = @$vars['parent_id'] ?: 0;
        if ($this->parent_id && !$this->parent)
            $errors['parent_id'] = __('Select a valid queue');

        // Try to avoid infinite recursion determining ancestry
        if ($this->parent_id && isset($this->id)) {
            $P = $this;
            while ($P = $P->parent)
                if ($P->parent_id == $this->id)
                    $errors['parent_id'] = __('Cannot be a descendent of itself');
        }
    }

    /**
     * El filtro rápido: `::` significa «el de la cola padre»; cualquier otro
     * valor tiene que estar entre los campos buscables de la raíz.
     */
    function setupFilter($vars, &$errors) {
        // Configure quick filter options
        $this->filter = $vars['filter'];
        if ($vars['sort_id']) {
            if ($vars['filter'] === '::') {
                if (!$this->parent)
                    $errors['filter'] = __('No parent selected');
            }
            elseif ($vars['filter'] && !array_key_exists($vars['filter'],
                static::getSearchableFields($this->getRoot()))
            ) {
                $errors['filter'] = __('Select an item from the list');
            }
        }
    }

    /**
     * `path` y las banderas de herencia. Una cola hija hereda criterios
     * siempre; columnas, exportación y ordenación sólo si se piden, y se dejan
     * de heredar cuando el POST trae lo propio.
     */
    function setupInheritance($vars) {
        // Set basic queue information
        $this->path = $this->buildPath();
        $this->setFlag(self::FLAG_INHERIT_CRITERIA, $this->parent_id);
        $this->setFlag(self::FLAG_INHERIT_COLUMNS,
            $this->parent_id > 0 && isset($vars['inherit-columns']));
        $this->setFlag(self::FLAG_INHERIT_EXPORT,
            $this->parent_id > 0 && isset($vars['inherit-exports']));
        $this->setFlag(self::FLAG_INHERIT_SORTING,
            $this->parent_id > 0 && isset($vars['inherit-sorting']));

        // Saved Search - Use standard columns
        if ($this instanceof SavedSearch && isset($vars['inherit-columns']))
            $this->setFlag(self::FLAG_INHERIT_COLUMNS);
        // Update queue columns (but without save)
        if (!isset($vars['columns']) && $this->parent) {
            // No columns -- imply column inheritance
            $this->setFlag(self::FLAG_INHERIT_COLUMNS);
        }
    }

    /** Las columnas: sólo se tocan en una cola ya guardada. */
    function setupColumns($vars, &$errors) {
        if ($this->getId()
                && isset($vars['columns'])
                && !$this->hasFlag(self::FLAG_INHERIT_COLUMNS)) {

            if ($this->columns->updateColumns($vars['columns'], $errors, array(
                                'queue_id' => $this->getId(),
                                'staff_id' => $this->staff_id)))
                $this->columns->reset();
        }
    }

    /** Los campos de exportación y la herencia implícita si no queda ninguno. */
    function setupExports($vars) {
        // Update export fields for the queue
        if (isset($vars['exports']) &&
                 !$this->hasFlag(self::FLAG_INHERIT_EXPORT)) {
            $this->updateExports($vars['exports'], false);
        }

        if (!count($this->exports) && $this->parent)
            $this->hasFlag(self::FLAG_INHERIT_EXPORT);
    }

    /**
     * Las ordenaciones. El reparto entre «se queda», «se va» y «se añade» lo
     * decide `QueueSortPlan`, que no toca la base de datos; aquí sólo se
     * aplica.
     */
    function setupSorts($vars) {
        // Update advanced sorting options for the queue
        if (isset($vars['sorts']) && !$this->hasFlag(self::FLAG_INHERIT_SORTING)) {
            $current = array();
            foreach ($this->sorts as $sort)
                $current[] = $sort->sort_id;

            $plan = QueueSortPlan::reconcile($current, $vars['sorts']);

            foreach ($this->sorts as $sort) {
                if (in_array($sort->sort_id, $plan['remove']))
                    $this->sorts->remove($sort);
                elseif (array_key_exists($sort->sort_id, $plan['keep']))
                    $sort->set('sort', $plan['keep'][$sort->sort_id]);
            }
            // Add new columns
            foreach ($plan['add'] as $addition) {
                list($id, $position) = $addition;
                if (!$sort = QueueSort::lookup($id))
                    continue;
                $glue = new QueueSortGlue(array(
                    'sort_id' => $id,
                    'queue' => $this,
                    'sort' => $position,
                ));
                $this->sorts->add($sort, $glue);
            }
            // Re-sort the in-memory columns array
            $this->sorts->sort(function($c) { return $c->sort; });
        }
        if (!count($this->sorts) && $this->parent) {
            // No sorting -- imply sorting inheritance
            $this->setFlag(self::FLAG_INHERIT_SORTING);
        }
    }

    /** La ordenación por omisión y su herencia. */
    function setupDefaultSort($vars, &$errors) {
        // Configure default sorting
        $this->setFlag(self::FLAG_INHERIT_DEF_SORT,
            $this->parent && $vars['sort_id'] === '::');
        if ($vars['sort_id']) {
            if ($vars['sort_id'] === '::') {
                if (!$this->parent)
                    $errors['sort_id'] = __('No parent selected');
                else
                     $this->sort_id = 0;
            }
            elseif ($qs = QueueSort::lookup($vars['sort_id'])) {
                $this->sort_id = $vars['sort_id'];
            }
            else {
                $errors['sort_id'] = __('Select an item from the list');
            }
        } else
             $this->sort_id = 0;
    }

    /** Los criterios: se guardan como JSON y se vacían los objetos en memoria. */
    function setupCriteria($vars, &$errors) {
        list($this->_conditions, $conditions)
            = QueueColumn::getConditionsFromPost($vars, $this->id, $this->getRoot());

        // TODO: Move this to SavedSearch::update() and adjust
        //       AjaxSearch::_saveSearch()
        /* Antes era `$form ?: $this->getForm($vars)`, con `$form` sin definir:
           un aviso de PHP en cada guardado que nunca llegaba a verse porque el
           POST de la cola no lo muestra. El valor era `null`, así que el
           resultado es el mismo y el aviso desaparece. */
        $form = $this->getForm($vars);
        if (!$vars) {
            $errors['criteria'] = __('No criteria specified');
        }
        elseif (!$form->isValid()) {
            $errors['criteria'] = __('Validation errors exist on criteria');
        }
        else {
            $this->criteria = static::isolateCriteria($form->getClean(),
                $this->getRoot());
            $this->config = JsonDataEncoder::encode([
                'criteria' => $this->criteria,
                'conditions' => $conditions,
            ]);
            // Clear currently set criteria.and conditions.
             $this->criteria = $this->_conditions = null;
        }
    }


    function psave() {
        return parent::save();
    }

    function save($refetch=false) {

        $nopath = !isset($this->path);
        $path_changed = isset($this->dirty['parent_id']);

        if ($this->dirty)
            $this->updated = SqlFunction::NOW();

        $clearCounts = ($this->dirty || $this->__new__);
        if (!($rv = parent::save($refetch || $this->dirty)))
            return $rv;

        if ($nopath) {
            $this->path = $this->buildPath();
            $this->save();
        }
        if ($path_changed) {
            $this->children->reset();
            $move_children = function($q) use (&$move_children) {
                foreach ($q->children as $qq) {
                    $qq->path = $qq->buildPath();
                    $qq->save();
                    $move_children($qq);
                }
            };
            $move_children($this);
        }

        // Refetch the queue counts
        if ($clearCounts)
            SavedQueue::clearCounts();

        return $this->columns->saveAll()
            && $this->exports->saveAll()
            && $this->sorts->saveAll();
    }

    /**
     * Fetch a tree-organized listing of the queues. Each queue is listed in
     * the tree exactly once, and every visible queue is represented. The
     * returned structure is an array where the items are two-item arrays
     * where the first item is a CustomQueue object an the second is a list
     * of the children using the same pattern (two-item arrays of a CustomQueue
     * and its children). Visually:
     *
     * [ [ $queue, [ [ $child, [] ], [ $child, [] ] ], [ $queue, ... ] ]
     *
     * Parameters:
     * $staff - <Staff> staff object which should be used to determine
     *      visible queues.
     * $pid - <int> parent_id of root queue. Default is zero (top-level)
     */
    static function getHierarchicalQueues(Staff $staff, $pid=0,
            $primary=true) {
        $query = static::objects()
            ->annotate(array('_sort' =>  SqlCase::N()
                        ->when(array('sort' => 0), 999)
                        ->otherwise(new SqlField('sort'))))
            ->filter(Q::any(array(
                'flags__hasbit' => self::FLAG_PUBLIC,
                'flags__hasbit' => static::FLAG_QUEUE,
                'staff_id' => $staff->getId(),
            )))
            ->exclude(['flags__hasbit' => self::FLAG_DISABLED])
            ->order_by('parent_id', '_sort', 'title');
        $all = $query->asArray();
        // Find all the queues with a given parent
        $for_parent = function($pid) use ($primary, $all, &$for_parent) {
            $results = [];
            foreach (new \ArrayIterator($all) as $q) {
                if ($q->parent_id != $pid)
                    continue;

                if ($pid == 0 && (
                            ($primary &&  !$q->isAQueue())
                            || (!$primary && $q->isAQueue())))
                    continue;

                $results[] = [ $q, $for_parent($q->getId()) ];
            }

            return $results;
        };

        return $for_parent($pid);
    }

    static function getOrmPath($name, $query=null, $nullable=null) {
        // Special case for custom data `__answers!id__value`. Only add the
        // join and constraint on the query the first pass, when the query
        // being mangled is received.
        $path = array();
        if ($query && preg_match('/^(.+?)__(answers!(\d+))/', $name, $path)) {
            // Add a join to the model of the queryset where the custom data
            // is forked from — duplicate the 'answers' join and add the
            // constraint to the query based on the field_id
            // $path[1] - part before the answers (user__org__entries)
            // $path[2] - answers!xx join part
            // $path[3] - the `xx` part of the answers!xx join component
            $root = $query->model;
            $meta = $root::getMeta()->getByPath($path[1]);
            $joins = $meta['joins'];
            // If the method is 'nset' (ie. IS NULL) we want to force normal
            // JOIN instead of LEFT JOIN to get proper results
            if (isset($nullable))
                $joins['answers']['null'] = $nullable;
            if (!isset($joins[$path[2]])) {
                $meta->addJoin($path[2], $joins['answers']);
            }
            // Ensure that the query join through answers!xx is only for the
            // records which match field_id=xx
            $query->constrain(array("{$path[1]}__{$path[2]}" =>
                array("{$path[1]}__{$path[2]}__field_id" => (int) $path[3])
            ));
            // Leave $name unchanged
        }
        return $name;
    }


    static function create($vars=false) {

        $queue = new static($vars);
        $queue->created = SqlFunction::NOW();
        if (!isset($vars['flags'])) {
            $queue->setFlag(self::FLAG_PUBLIC);
            $queue->setFlag(self::FLAG_QUEUE);
        }

        return $queue;
    }

    static function __create($vars) {
        $q = static::create($vars);
        $q->psave();
        foreach ($vars['columns'] ?: array() as $info) {
            $glue = new QueueColumnGlue($info);
            $glue->queue_id = $q->getId();
            $glue->save();
        }
        if (isset($vars['sorts'])) {
            foreach ($vars['sorts'] as $info) {
                $glue = new QueueSortGlue($info);
                $glue->queue_id = $q->getId();
                $glue->save();
            }
        }
        return $q;
    }
}

/* Columnas, exportacion y ordenaciones: ver class.queue.objects.php */
include_once(INCLUDE_DIR . 'class.queue.objects.php');
