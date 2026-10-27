<?php
/*********************************************************************
    module.search.php

    Search Engine for osTicket

    This module defines the pieces for a search engine for osTicket.
    Searching can be performed by various search engine backends which can
    make use of the features of various search providers.

    A reference search engine backend is provided which uses MySQL MyISAM
    tables. This default backend should not be used on Galera clusters.

    Jared Hancock <jared@osticket.com>
    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require_once INCLUDE_DIR . 'class.role.php';
require_once INCLUDE_DIR . 'class.list.php';
require_once INCLUDE_DIR . 'class.search.record.php';
require_once INCLUDE_DIR . 'class.queue.php';

abstract class SearchBackend {
    static $id = false;
    static $registry = array();

    const SORT_RELEVANCE = 1;
    const SORT_RECENT = 2;
    const SORT_OLDEST = 3;

    const PERM_EVERYTHING = 'search.all';

    static protected $perms = array(
        self::PERM_EVERYTHING => array(
            'title' => /* @trans */ 'Search',
            'desc'  => /* @trans */ 'See all tickets in search results, regardless of access',
            'primary' => true,
        ),
    );

    abstract function update($model, $id, $content, $new=false, $attrs=array());
    abstract function find($query, QuerySet $criteria, $addRelevance=true, $options=array());

    static function register($backend=false) {
        $backend = $backend ?: get_called_class();

        if ($backend::$id == false)
            throw new Exception('SearchBackend must define an ID');

        static::$registry[$backend::$id] = $backend;
    }

    static function getInstance($id) {
        if (!isset(self::$registry[$id]))
            return null;

        return new self::$registry[$id]();
    }

    static function getPermissions() {
        return self::$perms;
    }
}
RolePermission::register(/* @trans */ 'Miscellaneous', SearchBackend::getPermissions());

// Register signals to intercept saving of various content throughout the
// system

class SearchInterface {

    var $backend;

    function __construct() {
        $this->bootstrap();
    }

    function find($query, QuerySet $criteria, $addRelevance=true, $options=array()) {
        $query = Format::searchable($query);
        return $this->backend->find($query, $criteria, $addRelevance, $options);
    }

    function update($model, $id, $content, $new=false, $attrs=array()) {
        if ($this->backend)
            $this->backend->update($model, $id, $content, $new, $attrs);
    }

    function createModel($model) {
        return $this->updateModel($model, true);
    }

    function deleteModel($model) {
        if ($this->backend)
            $this->backend->delete($model);
    }

    function updateModel($model, $new=false) {
        // The MySQL backend does not need to index attributes of the
        // various models, because those other attributes are available in
        // the local database in other tables.
        switch (true) {
        case $model instanceof ThreadEntry:
            // Only index an entry for threads if a human created the
            // content
            if (!$model->getUserId() && !$model->getStaffId())
                break;

            $this->update($model, $model->getId(),
                $model->getBody()->getSearchable(),
                $new === true,
                array(
                    'title' =>      $model->getTitle(),
                    'created' =>    $model->getCreateDate(),
                )
            );
            break;

        case $model instanceof Ticket:
            $cdata = array();
            foreach ($model->loadDynamicData() as $a)
                if ($v = $a->getSearchable())
                    $cdata[] = $v;
            $this->update($model, $model->getId(),
                trim(implode("\n", $cdata)),
                $new === true,
                array(
                    'title'=>       Format::searchable($model->getSubject()),
                    'number'=>      $model->getNumber(),
                    'status'=>      $model->getStatus(),
                    'topic_id'=>    $model->getTopicId(),
                    'priority_id'=> $model->getPriorityId(),
                    // Stats (comments, attachments)
                    // Access constraints
                    'dept_id'=>     $model->getDeptId(),
                    'staff_id'=>    $model->getStaffId(),
                    'team_id'=>     $model->getTeamId(),
                    // Sorting and ranging preferences
                    'created'=>     $model->getCreateDate(),
                    // Add last-updated timestamp
                )
            );
            break;

        case $model instanceof User:
            $cdata = array();
            foreach ($model->getDynamicData($false) as $e)
                foreach ($e->getAnswers() as $tag=>$a)
                    if ($tag != 'subject' && ($v = $a->getSearchable()))
                        $cdata[] = $v;
            $this->update($model, $model->getId(),
                trim(implode("\n", $cdata)),
                $new === true,
                array(
                    'title'=>       Format::searchable($model->getFullName()),
                    'emails'=>      $model->emails->asArray(),
                    'org_id'=>      $model->getOrgId(),
                    'created'=>     $model->getCreateDate(),
                )
            );
            break;

        case $model instanceof Organization:
            $cdata = array();
            foreach ($model->getDynamicData(false) as $e)
                foreach ($e->getAnswers() as $a)
                    if ($v = $a->getSearchable())
                        $cdata[] = $v;
            $this->update($model, $model->getId(),
                trim(implode("\n", $cdata)),
                $new === true,
                array(
                    'title'=>       Format::searchable($model->getName()),
                    'created'=>     $model->getCreateDate(),
                )
            );
            break;

        case $model instanceof FAQ:
            $this->update($model, $model->getId(),
                $model->getSearchableAnswer(),
                $new === true,
                array(
                    'title'=>       Format::searchable($model->getQuestion()),
                    'keywords'=>    $model->getKeywords(),
                    'topics'=>      $model->getHelpTopicsIds(),
                    'category_id'=> $model->getCategoryId(),
                    'created'=>     $model->getCreateDate(),
                )
            );
            break;

        default:
            // Not indexed
            break;
        }
    }

    function bootstrap() {
        // Determine the backend
        if (defined('SEARCH_BACKEND'))
            $bk = SearchBackend::getInstance(SEARCH_BACKEND);

        if (!isset($bk) && !($bk = SearchBackend::getInstance('mysql')))
            // No backend registered or defined
            return false;

        $this->backend = $bk;
        $this->backend->bootstrap();

        $self = $this;

        // Thread entries
        // Tickets, which can be edited as well
        // Knowledgebase articles (FAQ and canned responses)
        // Users, organizations
        Signal::connect('threadentry.created', array($this, 'createModel'));
        Signal::connect('ticket.created', array($this, 'createModel'));
        Signal::connect('user.created', array($this, 'createModel'));
        Signal::connect('organization.created', array($this, 'createModel'));
        Signal::connect('model.created', array($this, 'createModel'), 'FAQ');

        Signal::connect('model.updated', array($this, 'updateModel'));
        Signal::connect('model.deleted', array($this, 'deleteModel'));
    }
}

require_once(INCLUDE_DIR.'class.config.php');

// Saved search system

/**
 * Custom Queue truly represent a saved advanced search.
 */
class SavedQueue extends CustomQueue {
    // Override the ORM relationship to force no children
    private $children = false;
    private $_config;
    private $_criteria;
    private $_columns;
    private $_settings;
    private $_form;
    private $_sorts;


    function __onload() {
        global $thisstaff;

        // Load custom settings for this staff
        if ($thisstaff) {
            $this->_config = QueueConfig::lookup(array(
                         'queue_id' => $this->getId(),
                         'staff_id' => $thisstaff->getId())
                    );
        }
    }

    static function forStaff(Staff $agent) {
        return static::objects()->filter(Q::any(array(
            'staff_id' => $agent->getId(),
            'flags__hasbit' => self::FLAG_PUBLIC,
        )))
        ->exclude(array('flags__hasbit'=>self::FLAG_QUEUE));
    }

    private function getSettings() {
        if (!isset($this->_settings)) {
            $this->_settings = array();
            if ($this->_config)
                $this->_settings = $this->_config->getSettings();
        }

        return  $this->_settings;
    }

    private function getCustomColumns() {

        if (!isset($this->_columns)) {
            $this->_columns = array();
            if ($this->_config
                    && $this->_config->columns->count())
                $this->_columns = $this->_config->columns;
        }

        return $this->_columns;
    }

    static function getHierarchicalQueues(Staff $staff, $pid = 0, $primary = true) {
        return CustomQueue::getHierarchicalQueues($staff, 0, false);
    }


    /*
     * Determine if sort is inherited
     */
    function isDefaultSortInherited() {
        if ($this->parent
                && $this->getSettings()
                && @$this->_settings['inherit-sort'])
            return true;

        return parent::isDefaultSortInherited();
    }

    function getSortOptions() {

        if (!isset($this->_sorts)) {
            // See if the queue has sort options
            if (($sorts=parent::getSortOptions()) && $sorts->count())
                $this->_sorts = $sorts;
            // otherwise return all sorts
            else
                 $this->_sorts = QueueSort::objects();
        }

        return $this->_sorts;
    }

    function getDefaultSort() {
        if ($this->getSettings()
                && $this->_settings['sort_id']
                && ($sort = QueueSort::lookup($this->_settings['sort_id'])))
            return $sort;

        return parent::getDefaultSort();
    }

    /**
     * Fetch an AdvancedSearchForm instance for use in displaying or
     * configuring this search in the user interface.
     *
     */
    function getForm($source=null, $searchable=array()) {
        $searchable = null;
        if ($this->isAQueue())
            // Only allow supplemental matches.
            $searchable = array_intersect_key($this->getCurrentSearchFields($source),
                    $this->getSupplementalMatches());

        return parent::getForm($source, $searchable);
    }

   /**
     * Get get supplemental matches for public queues.
     *
     */
    function getSupplementalMatches() {
        // Target flags
        $flags = array('isoverdue', 'isassigned', 'isreopened', 'isanswered');
        $current = array();
        // Check for closed state - whih disables above flags
        foreach (parent::getCriteria() as $c) {
            if (!strcasecmp($c[0], 'status__state')
                    && isset($c[2]['closed']))
                return array();

            $current[] = $c[0];
        }

        // Filter out fields already in criteria
        $matches = array_intersect_key($this->getSupportedMatches(),
                array_flip(array_diff($flags, $current)));

        return $matches;
    }

    function criteriaRequired() {
        return !$this->isAQueue();
    }

    function describeCriteria($criteria=false){
        $criteria = $criteria ?: parent::getCriteria();
        return parent::describeCriteria($criteria);
    }

    function getCriteria($include_parent=true) {

        if (!isset($this->_criteria)) {
            $this->getSettings();
            $this->_criteria = $this->_settings['criteria'] ?? array();
        }

        $criteria = $this->_criteria;
        if ($include_parent)
            $criteria = array_merge($criteria,
                    parent::getCriteria($include_parent));


        return $criteria;
    }

    function getSupplementalCriteria() {
        return $this->getCriteria(false);
    }

    function useStandardColumns() {

        $this->getSettings();
        if ($this->getCustomColumns()
                && isset($this->_settings['inherit-columns']))
            return $this->_settings['inherit-columns'];

        // owner?? edit away.
        if ($this->_config
                && $this->_config->staff_id == $this->staff_id)
            return false;

        return parent::useStandardColumns();
    }

    function inheritColumns() {
        if ($this->getSettings() && isset($this->_settings['inherit-columns']))
            return $this->_settings['inherit-columns'];

        return parent::inheritColumns();
    }

    function getStandardColumns() {
        return parent::getColumns(is_null($this->parent));
    }

    function getColumns($use_template=false) {

        if (!$this->useStandardColumns() && ($columns=$this->getCustomColumns()))
            return $columns;

        return parent::getColumns($use_template);
    }

    function update($vars, &$errors=array()) {
        global $thisstaff;

        if (!$this->checkAccess($thisstaff))
            return false;

        if ($this->checkOwnership($thisstaff)) {
            // Owner of the queue - can update everything
            if (!parent::update($vars, $errors))
                return false;

            // Personal queues _always_ inherit from their parent
            $this->setFlag(self::FLAG_INHERIT_CRITERIA, $this->parent_id >
                    0);

            return true;
        }

        // Agent's config for public queue.
        if (!$this->_config)
            $this->_config = QueueConfig::create(array(
                        'queue_id' => $this->getId(),
                        'staff_id' => $thisstaff->getId()));

        //  Validate & isolate supplemental criteria (if any)
        $vars['criteria'] = array();
        if (isset($vars['fields'])) {
           $form = $this->getForm($vars, $thisstaff);
            if ($form->isValid()) {
                $criteria = self::isolateCriteria($form->getClean(),
                        $this->getRoot());
                $allowed = $this->getSupplementalMatches();
                foreach ($criteria as $k => $c)
                    if (!isset($allowed[$c[0]]))
                        unset($criteria[$k]);

                $vars['criteria'] = $criteria ?: array();
            } else {
                $errors['criteria'] = __('Validation errors exist on supplimental criteria');
            }
        }

        if (!$errors && $this->_config->update($vars, $errors)) {
            // reset settings
            $this->_settings = $this->_criteria = null;
            // Reset chached queue options
            unset($_SESSION['sort'][$this->getId()]);

        }

        return (!$errors);
    }

    function getTotal($agent=null) {
        $query = $this->getQuery();
        if ($agent)
            $query = $agent->applyVisibility($query);
        $query->filter(Q::any([
                'ticket_pid__isnull' => true,
                'flags__hasbit' => Ticket::FLAG_LINKED
            ]))->limit(false)->offset(false)->order_by(false);
        try {
            return $query->count();
        } catch (Exception $e) {
            return null;
        }
    }

    function getCount($agent, $cached=true) {
        $count = null;
        if ($cached && ($counts = self::counts($agent, $cached)))
            $count = $counts["q{$this->getId()}"];

        if ($count == null)
            $count = $this->getTotal($agent);

        return $count;
    }

    // Get ticket counts for queues the agent has acces to.
    static function counts($agent, $cached=true, $criteria=array()) {

        if (!$agent instanceof Staff)
            return null;

        // Cache TLS in seconds
        $ttl = 5*60;
        // Cache key based on agent and salt of the installation
        $key = "counts.queues.{$agent->getId()}.".SECRET_SALT;
        if ($criteria && is_array($criteria)) // Consider additional criteria.
            $key .= '.'.md5(serialize($criteria));

        // only consider cache if requesed
        if ($cached && ($counts=self::getCounts($key, $ttl)))
            return $counts;

        $queues = static::objects()
            ->filter(Q::any(array(
                'flags__hasbit' => CustomQueue::FLAG_QUEUE,
                'staff_id' => $agent->getId(),
            )))
            ->filter(Q::not(array(
                'flags__hasbit' => CustomQueue::FLAG_DISABLED,
            )));

        if ($criteria && is_array($criteria))
            $queues->filter($criteria);

       $counts = array();
        $query = Ticket::objects();
        // Apply tickets visibility for the agent
        $query = $agent->applyVisibility($query, true);
        // Aggregate constraints
        foreach ($queues as $queue) {
            $Q = $queue->getBasicQuery();

            // only get counts for regular tickets (not children tickets) unless
            // queue is a saved search
            if ($queue->isAQueue() || $queue->isASubQueue()) {
                $reg = Q::any(array('thread__object_type' => 'T'));
                $Q->constraints[] = $reg;
            }

            if ($Q->constraints) {
                $empty = false;
                if (count($Q->constraints) > 1) {
                    foreach ($Q->constraints as $value) {
                        if (!$value->constraints)
                            $empty = true;
                    }
                }
            }

            // Add extra tables joins  (if any)
            if ($Q->extra && isset($Q->extra['tables'])) {
               // skip counting keyword searches. Display them as '-'
               $counts['q'.$queue->getId()] = '-';
               continue;
               $contraints = array();
               if ($Q->constraints)
                    $constraints = new Q($Q->constraints);
               foreach ($Q->extra['tables'] as $T)
                   $query->addExtraJoin(array($T, $constraints, ''));
            }

            if ($Q->constraints && !$empty) {
                $constraints = $Q->constraints;
                // Add path_constraints to get the correct counts
                foreach ($Q->path_constraints as $pc) {
                    if (!empty($pc[0]->constraints))
                        $constraints[] = $pc[0];
                }
                $expr = SqlCase::N()->when(new SqlExpr(new Q($constraints)), new SqlField('ticket_id'));
                $query->aggregate(array(
                    "q{$queue->id}" => SqlAggregate::COUNT($expr, true)
                ));
            } else //display skipped counts as '-'
                $counts['q'.$queue->getId()] = '-';
        }

        try {
            $counts = array_merge($counts, $query->values()->one());
        }  catch (Exception $ex) {
            foreach ($queues as $q)
                $counts['q'.$q->getId()] = $q->getTotal();
        }

        // Always cache the results
        self::storeCounts($key, $counts, $ttl);

        return $counts;
    }

    static function getCounts($key, $ttl) {

        if (!$key) {
            return array();
        } elseif (function_exists('apcu_store')) {
            $found = false;
            $counts = apcu_fetch($key, $found);
            if ($found === true)
                return $counts;
        } elseif (isset($_SESSION['qcounts'][$key])
                && (time() - $_SESSION['qcounts'][$key]['time']) < $ttl) {
            return $_SESSION['qcounts'][$key]['counts'];
        } else {
            // Auto clear missed session cache (if any)
            unset($_SESSION['qcounts'][$key]);
        }
    }

    static function storeCounts($key, $counts, $ttl) {
        if (function_exists('apcu_store')) {
            apcu_store($key, $counts, $ttl);
        } else {
            // Poor man's cache
            $_SESSION['qcounts'][$key]['counts'] = $counts;
            $_SESSION['qcounts'][$key]['time'] = time();
        }
    }

    static function clearCounts() {
        if (function_exists('apcu_store')) {
            if (class_exists('APCUIterator')) {
                $regex = '/^counts.queues.\d+.' . preg_quote(SECRET_SALT, '/') . '$/';
                foreach (new APCUIterator($regex, APC_ITER_KEY) as $key) {
                    apcu_delete($key);
                }
            }
            // Also clear rough counts
            apcu_delete("rough.counts.".SECRET_SALT);
        }
    }

    static function lookup($criteria) {
        $queue = parent::lookup($criteria);
        // Annoted cusom settings (if any)
        if (($c=$queue->_config)) {
            $queue->_settings = $c->getSettings() ?: array();
            $queue = AnnotatedModel::wrap($queue,
                        array_intersect_key($queue->_settings,
                            array_flip(array('sort_id', 'filter'))));
            $queue->_config = $c;
        }

        return $queue;
    }

    static function create($vars=false) {
        $search = parent::create($vars);
        $search->clearFlag(self::FLAG_QUEUE);
        return $search;
    }
}

class SavedSearch extends SavedQueue {

    function isSaved() {
        return (!$this->__new__);
    }

    function getCount($agent, $cached=true) {
        return 500;
    }
}

class AdhocSearch
extends SavedSearch {

    function isSaved() {
        return false;
    }

    function isOwner(Staff $staff) {
        return $this->ht['staff_id'] == $staff->getId();
    }

    function checkAccess(Staff $staff) {
        return true;
    }

    function getName() {
        return $this->title ?: $this->describeCriteria();
    }

    static function load($key) {
        global $thisstaff;

        if (strpos($key, 'adhoc') === 0)
            list(, $key) = explode(',', $key, 2);

        if (!$key
                || !isset($_SESSION['advsearch'])
                || !($config=$_SESSION['advsearch'][$key]))
            return null;

       $queue = new AdhocSearch(array(
                   'id' => "adhoc,$key",
                   'root' => 'T',
                   'staff_id' => $thisstaff->getId(),
                   'title' => __('Advanced Search'),
                ));
       $queue->config = $config;

       return $queue;
    }
}

// AdvacedSearchForm
class AdvancedSearchForm extends SimpleForm {
    static $id = 1337;

    function getNumFieldsSelected() {
        $selected = 0;
        foreach ($this->getFields() as $F) {
            if (substr($F->get('name'), -7) == '+search'
                    && $F->getClean())
                $selected += 1;
            // Consider keyword searches
            elseif ($F->get('name') == ':keywords'
                    && $F->getClean())
                $selected += 1;
        }
        return $selected;
    }
}

// Advanced search special fields

class AdvancedSearchSelectionField extends ChoiceField {

    function hasIdValue() {
        return false;
    }

    function getSearchQ($method, $value, $name=false) {
        switch ($method) {
            case 'includes':
            case '!includes':
                if (!$value) return;
                $Q = new Q();
                if (count($value) > 1)
                    $Q->add(array("{$name}__in" => array_keys($value)));
                else
                    $Q->add(array($name => key($value)));

                if ($method == '!includes')
                    $Q->negate();
                return $Q;
                break;
            // osTicket commonly uses `0` to represent an unset state, so
            // the set and unset checks need to check for both not null and
            // nonzero
            case 'nset':
                return new Q([$name => 0]);
            case 'set':
                return Q::not([$name => 0]);
            default:
                return parent::getSearchQ($method, $value, $name);
        }

    }

}

class HelpTopicChoiceField extends AdvancedSearchSelectionField {
    static $_topics;

    function hasIdValue() {
        return true;
    }

    function getChoices($verbose=false, $options=array()) {
        global $thisstaff;
        if (!isset($this->_topics)) {
            $this->_topics = $thisstaff ? $thisstaff->getTopicNames(false, Topic::DISPLAY_DISABLED) :
                Topic::getHelpTopics(false, Topic::DISPLAY_DISABLED);;
        }

        return $this->_topics;
    }
}

class SLAChoiceField extends AdvancedSearchSelectionField {
    static $_slas;

    function hasIdValue() {
        return true;
    }

    function getChoices($verbose=false, $options=array()) {
        if (!isset($this->_slas))
            $this->_slas = SLA::getSLAs(array('nameOnly' => true));

        return $this->_slas;
    }
}

require_once INCLUDE_DIR . 'class.dept.php';
class DepartmentChoiceField extends AdvancedSearchSelectionField {
    static $_depts;
    static $_alldepts;
    var $_choices;

    function getDepts($criteria=array()) {
        global $thisstaff;

        $staff = $criteria['staff'];
        $depts = array();
        if ($staff)
            foreach ($staff->getDepartmentNames(true) as $id => $name)
                $depts[$id] = $name;
        else
            foreach (Dept::getDepartments() as $id => $name)
                $depts[$id] = $name;

        return $depts;
    }

    function getChoices($verbose=false, $options=array()) {
        global $thisstaff;
        $config = $this->getConfiguration();

        $criteria = array(
                'staff' => $config['staff'] ?: $thisstaff
                );
        if (!isset($this->_choices))
            $this->_choices = $this->getDepts($criteria);

        return $this->_choices;

    }

    function toString($value) {
        if (!isset($this->_alldepts))
            $this->_alldepts = $this->getDepts();
        $choices =  $this->_alldepts;
        $selection = array();
        if (!is_array($value))
            $value = array($value => $value);

        foreach ($value as $k => $v)
            if (isset($choices[$k]))
                $selection[] = $choices[$k];

        return $selection ?  implode(',', $selection) :
            parent::toString($value);
    }

    function getQuickFilterChoices() {
       global $thisstaff;

       if (!isset($this->_choices)) {
         $depts = $thisstaff ? $thisstaff->getDepts() : array();
         foreach ($this->getChoices() as $id => $name) {
           if (!$depts || in_array($id, $depts))
               $this->_choices[$id] = $name;
         }
       }

       return $this->_choices;
    }

    function getSearchMethods() {
        return array(
            'includes' =>   __('is'),
            '!includes' =>  __('is not'),
        );
    }

    function addToQuery($query, $name=false) {
        return $query->values('dept_id', 'dept__name');
    }

    function applyOrderBy($query, $reverse=false, $name=false) {
        $reverse = $reverse ? '-' : '';
        return $query->order_by("{$reverse}dept__name");
    }
}


class AssigneeChoiceField extends ChoiceField {

    protected $_items;


    function getChoices($verbose=false, $options=array()) {
        global $thisstaff;

        if (!isset($this->_items)) {
            $items = array(
                'M' => __('Me'),
                'T' => __('One of my teams'),
            );
            $assignees = Staff::getStaffMembers(array('staff' => $thisstaff));

            foreach ($assignees as $id=>$name) {
                // Don't include $thisstaff (since that's 'Me')
                if ($thisstaff && $thisstaff->getId() == $id)
                    continue;
                $items['s' . $id] = $name;
            }
            foreach (Team::getTeams() as $id=>$name) {
                $items['t' . $id] = $name;
            }

            $this->_items = $items;
        }

        return $this->_items;
    }

    function getChoice($k) {
        $choices = $this->getChoices();
        return $choices[$k] ?: null;
    }

    function getSearchMethods() {
        return array(
            'assigned' =>   __('assigned'),
            '!assigned' =>  __('unassigned'),
            'includes' =>   __('includes'),
            '!includes' =>  __('does not include'),
        );
    }

    function getSearchMethodWidgets($options=array()) {
        return array(
            'assigned' => null,
            '!assigned' => null,
            'includes' => array('ChoiceField', array(
                'choices' => $this->getChoices(false, $options),
                'configuration' => array('multiselect' => true),
            )),
            '!includes' => array('ChoiceField', array(
                'choices' => $this->getChoices(false, $options),
                'configuration' => array('multiselect' => true),
            )),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        global $thisstaff;

        $Q = new Q();
        switch ($method) {
        case 'assigned':
            $Q->negate();
        case '!assigned':
            $Q->add(array('team_id' => 0,
                'staff_id' => 0));
            break;
        case '!includes':
            $Q->negate();
        case 'includes':
            $teams = $agents = array();
            $matches = count($value);
            foreach ($value as $id => $ST) {
                switch ($id[0]) {
                case 'M':
                    $agents[] = $thisstaff->getId();
                    break;
                case 's':
                    $agents[] = (int) substr($id, 1);
                    break;
                case 'T':
                    if ($thisstaff && ($staffTeams = $thisstaff->getTeams()))
                         $teams = array_merge($staffTeams);
                    elseif ($matches == 1)
                        return Q::any(['team_id' => null]);
                    break;
                case 't':
                    $teams[] = (int) substr($id, 1);
                    break;
                }
            }
            $constraints = array();
            if ($teams)
                $constraints['team_id__in'] = $teams;
            if ($agents)
                $constraints['staff_id__in'] = $agents;
            $Q->add(Q::any($constraints));
        }
        return $Q;
    }

    function describeSearchMethod($method) {
        switch ($method) {
        case 'assigned':
            return __('assigned');
        case '!assigned':
            return __('unassigned');
        default:
            return parent::describeSearchMethod($method);
        }
    }

    function addToQuery($query, $name=false) {

        $fields = array();
        foreach(Staff::getsortby('staff__') as $key)
             $fields[] = new SqlField($key);
        $fields[] =  new SqlField('team__name');
        $fields[] = 'zzz';
        $expr = call_user_func_array(array('SqlFunction', 'COALESCE'), $fields);
        $query->annotate(array($name ?: 'assignee' => $expr));
        return $query->values('staff__firstname', 'staff__lastname', 'team__name', 'team_id');
    }

    function from_query($row, $name=false) {
        if ($row['staff__firstname'])
            return new AgentsName(array('first' => $row['staff__firstname'], 'last' => $row['staff__lastname']));
        if ($row['team_id'])
            return Team::getLocalById($row['team_id'], 'name', $row['team__name']);

    }

    function display($value) {
        return (string) $value;
    }

    function toString($value) {
        if (!is_array($value))
             $value = array($value => $value);
        $selection = array();
        foreach ($value as $k => $v)
            $selection[] = $this->getChoice($k) ?: (string) $v;
        return implode(', ', $selection);
    }
}

class AssignedField extends AssigneeChoiceField {

    function getChoices($verbose=false, $options=array()) {
        return array(
            'assigned' =>   __('Assigned'),
            '!assigned' =>  __('Unassigned'),
        );
    }

    function getSearchMethods() {
        return array(
            'assigned' =>   __('assigned'),
            '!assigned' =>  __('unassigned'),
        );
    }

    function addToQuery($query, $name=false) {
        return $query->values('staff_id', 'team_id');
    }

    function from_query($row, $name=false) {
        return ($row['staff_id'] || $row['staff_id'])
            ? __('Yes') : __('No');
    }

}

class MergedField extends FormField {
    function getSearchMethods() {
        return array(
            'set' =>        __('checked'),
            'nset' =>    __('unchecked'),
        );
    }

    function addToQuery($query, $name=false) {
        $query->annotate(array(
                'merged' => new SqlExpr(new Q(array(
                    Q::any(array(
                        'flags__hasbit' => Ticket::FLAG_SEPARATE_THREADS,
                        'flags__hasbit' => Ticket::FLAG_COMBINE_THREADS,
                )))
            ))));

        return $query->values('merged');
    }

    function getSearchQ($method, $value, $name=false) {
        global $thisstaff;

        $Q = new Q();
        switch ($method) {
        case 'set':
            $visibility = Q::any(array(
                'flags__hasbit' => Ticket::FLAG_SEPARATE_THREADS,
            ));
            $visibility->add(Q::any(array(
                'flags__hasbit' => Ticket::FLAG_COMBINE_THREADS
            )));
            $visibility->ored = true;
            return $visibility;
        case 'nset':
            $visibility = Q::all(array());
            $visibility->add(Q::not(array(
                'flags__hasbit' => Ticket::FLAG_SEPARATE_THREADS,
            )));
            $visibility->add(Q::not(array(
                'flags__hasbit' => (Ticket::FLAG_COMBINE_THREADS)
            )));
            return $visibility;
            break;
        }
    }

    function from_query($row, $name=false) {
        $flags = $row['flags'];
        $combine = ($flags & Ticket::FLAG_COMBINE_THREADS) != 0;
        $separate = ($flags & Ticket::FLAG_SEPARATE_THREADS) != 0;
        return ($combine || $separate)
            ? __('Yes') : __('No');
    }
}

class LinkedField extends FormField {
    function getSearchMethods() {
        return array(
            'set' =>        __('checked'),
            'nset' =>    __('unchecked'),
        );
    }

    function addToQuery($query, $name=false) {
        return $query->values('ticket_pid', 'flags');
    }

    function getSearchQ($method, $value, $name=false) {
        global $thisstaff;

        $Q = new Q();
        switch ($method) {
        case 'set':
            return Q::any(array(
                'flags__hasbit' => Ticket::FLAG_LINKED,
            ));
        case 'nset':
            return Q::not(array(
                'flags__hasbit' => Ticket::FLAG_LINKED,));
            break;
        }
    }

    function from_query($row, $name=false) {
        $flags = $row['flags'];
        $linked = ($flags & Ticket::FLAG_LINKED) != 0;
        return ($linked)
            ? __('Yes') : __('No');
    }

}

/**
 * Simple trait which changes the SQL for "has a value" and "does not have a
 * value" to check for zero or non-zero. Useful for not nullable fields.
 */
trait ZeroMeansUnset {
    function getSearchQ($method, $value, $name=false) {
        $name = $name ?: $this->get('name');
        switch ($method) {
        // osTicket commonly uses `0` to represent an unset state, so
        // the set and unset checks need to check for both not null and
        // nonzero
        case 'nset':
            return new Q([$name => 0]);
        case 'set':
            return Q::not([$name => 0]);
        }
        return parent::getSearchQ($method, $value, $name);
    }
}

class AgentSelectionField extends AdvancedSearchSelectionField {
    use ZeroMeansUnset;
    static $_allagents;
    static $_agents;

    function getAgents($criteria=array()) {
        $dept = $criteria['dept'] ?: null;
        $staff = $criteria['staff'] ?: null;
        $agents = array();
        if ($dept) {
            foreach ($dept->getAssignees(array('staff' => $staff)) as $a)
                $agents[$a->getId()] = $a;
        } else {
            foreach (Staff::getStaffMembers(array('staff' => $staff)) as $id => $name) {
                if ($staff && $staff->getId() == $id)
                    $agents['M'] = __('Me');
                $agents[$id] = $name;
            }
        }
        return $agents;
    }

    function getChoices($verbose=false, $options=array()) {
        global $thisstaff;
        $config = $this->getConfiguration();
        $criteria = array(
                'dept' => $config['dept'] ?: null,
                'staff' => $config['staff'] ?: $thisstaff
                );
        if (!isset($this->_choices))
            $this->_choices = $this->getAgents($criteria);

        return $this->_choices;

    }

    function toString($value) {
        if (!isset($this->_allagents))
            $this->_allagents = $this->getAgents();
        $choices =  $this->_allagents;
        $selection = array();
        if (!is_array($value))
            $value = array($value => $value);

        foreach ($value as $k => $v)
            if (isset($choices[$k]))
                $selection[] = $choices[$k];

        return $selection ?  implode(',', $selection) :
            parent::toString($value);
    }

    function getSearchQ($method, $value, $name=false) {
        global $thisstaff;
        // unpack me
        if (isset($value['M']) && $thisstaff) {
            $value[$thisstaff->getId()] = $thisstaff->getName();
            unset($value['M']);
        }

        return parent::getSearchQ($method, $value, $name);
    }

    function getSortKeys($path='') {
        return Staff::getsortby('staff__');
    }

    function applyOrderBy($query, $reverse=false, $name=false) {
        $reverse = $reverse ? '-' : '';
        return Staff::nsort($query, "{$reverse}staff__");
    }
}

class DepartmentManagerSelectionField extends AgentSelectionField {
    static $_members;

    function getChoices($verbose=false, $options=array()) {
        global $thisstaff;

        if (!isset($this->_members)) {
            $managers = array();
            $mgr = Dept::objects()->filter(array('manager_id__gt' => 0))->values_flat('manager_id');
            $staff = $thisstaff->getDeptAgents(array('available' => true, 'namesOnly' => true));
            foreach ($mgr as $mid) {
                $mid = $mid[0];
                if (array_key_exists($mid, $staff))
                    $managers['s'.$mid] = $staff[$mid]->getName()->name;
            }
            $this->_members = $managers;
        }

        return $this->_members;
    }

    function getSearchQ($method, $value, $name=false) {
        return parent::getSearchQ($method, $value, 'dept__manager_id');
    }
}

class TeamSelectionField extends AdvancedSearchSelectionField {
    static $_teams;

    function getChoices($verbose=false, $options=array()) {
        if (!isset($this->_teams) && $teams = Team::getTeams())
            $this->_teams = array('T' => __('One of my teams')) +
                $teams;

        return $this->_teams;
    }

    function getSearchQ($method, $value, $name=false) {
        global $thisstaff;

        // Unpack my teams
        if (isset($value['T'])) {
             if (!$thisstaff || !($teams = $thisstaff->getTeams()))
                return Q::any(['team_id' => null]);

            unset($value['T']);
            $value = $value + array_flip($teams);
        }
        return parent::getSearchQ($method, $value, $name);
    }

    function getSortKeys($path) {
        return array('team__name');
    }

    function applyOrderBy($query, $reverse=false, $name=false) {
        $reverse = $reverse ? '-' : '';
        return $query->order_by("{$reverse}team__name");
    }

    function toString($value) {
        $choices =  $this->getChoices();
        $selection = array();
        if (!is_array($value))
            $value = array($value => $value);
        foreach ($value as $k => $v)
            if (isset($choices[$k]))
                $selection[] = $choices[$k];
        return $selection ?  implode(',', $selection) :
            parent::toString($value);
    }

}

class TicketStateChoiceField extends AdvancedSearchSelectionField {
    function getChoices($verbose=false, $options=array()) {
        return array(
            'open' => __('Open'),
            'closed' => __('Closed'),
            'archived' => _P('ticket state name', 'Archived'),
            'deleted' => _P('ticket state name','Deleted'),
        );
    }

    function getSearchMethods() {
        return array(
            'includes' =>   __('is'),
            '!includes' =>  __('is not'),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        return parent::getSearchQ($method, $value, 'status__state');
    }
}

class TicketFlagChoiceField extends ChoiceField {
    function getChoices($verbose=false, $options=array()) {
        return array(
            'isanswered' =>   __('Answered'),
            'isoverdue' =>    __('Overdue'),
        );
    }

    function getSearchMethods() {
        return array(
            'includes' =>   __('is'),
            '!includes' =>  __('is not'),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        $Q = new Q();
        if (isset($value['isanswered']))
            $Q->add(array('isanswered' => 1));
        if (isset($value['isoverdue']))
            $Q->add(array('isoverdue' => 1));
        if ($method == '!includes')
            $Q->negate();
        if ($Q->constraints)
            return $Q;
    }
}

class TicketSourceChoiceField extends ChoiceField {
    function getChoices($verbose=false, $options=array()) {
        return Ticket::getSources();
    }

    function getSearchMethods() {
        return array(
            'includes' =>   __('is'),
            '!includes' =>  __('is not'),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        return parent::getSearchQ($method, $value, 'source');
    }
}

class OpenClosedTicketStatusList extends TicketStatusList {
    function getItems($criteria=array()) {
        $rv = array();
        $base = parent::getItems($criteria);
        foreach ($base as $idx=>$S) {
            if (in_array($S->state, array('open', 'closed')))
                $rv[$idx] = $S;
        }
        return $rv;
    }
}

class TicketStatusChoiceField extends SelectionField {
    static $widget = 'ChoicesWidget';

    function getList() {
        return new OpenClosedTicketStatusList(
            DynamicList::lookup(
                array('type' => 'ticket-status'))
        );
    }

    function getSearchMethods() {
        return array(
            'includes' =>   __('is'),
            '!includes' =>  __('is not'),
        );
    }

    function getSearchQ($method, $value, $name=false) {
        $name = $name ?: $this->get('name');
        if (!$value)
            return false;
        switch ($method) {
        case '!includes':
            return Q::not(array("{$name}__in" => array_keys($value)));
        case 'includes':
            return new Q(array("{$name}__in" => array_keys($value)));
        default:
            return parent::getSearchQ($method, $value, $name);
        }
    }

    function applyOrderBy($query, $reverse=false, $name=false) {
        $reverse = $reverse ? '-' : '';
        return $query->order_by("{$reverse}status__name");
    }
}













interface Searchable {
    // Fetch an array of [ orm__path => Field() ] pairs. The field label is
    // used when this list is rendered in a dropdown, and the field search
    // mechanisms are use to apply query filtering based on the field.
    static function getSearchableFields();

    // Determine if the object supports abritrary form additions, through
    // the "Manage Forms" dialog usually
    static function supportsCustomData();
}

/* Campos anotados y contadores: ver class.search.fields.php */
include_once(INCLUDE_DIR . 'class.search.fields.php');

/* El backend de MySQL y su configuración: ver class.search.mysql.php. Va
 * después de las clases de arriba porque lo que hay allí las hereda. */
include_once(INCLUDE_DIR . 'class.search.mysql.php');
