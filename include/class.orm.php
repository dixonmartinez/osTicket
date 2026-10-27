<?php
/*********************************************************************
    class.orm.php

    Simple ORM (Object Relational Mapper) for PHP5 based on Django's ORM,
    except that complex filter operations are not supported. The ORM simply
    supports ANDed filter operations without any GROUP BY support.

    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

/* Memoria del último error de la conexión (mensaje de las consultas) */
require_once(INCLUDE_DIR . 'class.db.failure.php');

require_once INCLUDE_DIR . 'class.util.php';
require_once INCLUDE_DIR . 'class.signal.php';
require_once INCLUDE_DIR . 'class.orm.select.php';

/* Conjuntos de resultados y listas instrumentadas: ver class.orm.result.php */
include_once(INCLUDE_DIR . 'class.orm.result.php');

/* Expresiones y compiladores SQL: ver class.orm.sql.php */
include_once(INCLUDE_DIR . 'class.orm.sql.php');

class OrmException extends Exception {}
class OrmConfigurationException extends Exception {}
// Database fields/tables do not match codebase
class InconsistentModelException extends OrmException {
    function __construct() {
        // Drop the model cache (just incase)
        ModelMeta::flushModelCache();
        call_user_func_array(array('parent', '__construct'), func_get_args());
    }
}

/**
 * Meta information about a model including edges (relationships), table
 * name, default sorting information, database fields, etc.
 *
 * This class is constructed and built automatically from the model's
 * ::getMeta() method using a class's ::$meta array.
 */
class ModelMeta implements ArrayAccess {

    static $base = array(
        'pk' => false,
        'table' => false,
        'defer' => array(),
        'select_related' => array(),
        'view' => false,
        'joins' => array(),
        'foreign_keys' => array(),
    );
    static $model_cache;

    var $model;
    var $meta = array();
    var $new;
    /* Sólo se rellena cuando el modelo hereda de otro; sin declararla, cada
       modelo con padre creaba una propiedad dinámica (deprecación en PHP 8.2) */
    var $parent;
    var $subclasses = array();
    var $fields;

    function __construct($model) {
        $this->model = $model;

        // Merge ModelMeta from parent model (if inherited)
        $parent = get_parent_class($this->model);
        $meta = $model::$meta;
        if ($model::$meta instanceof self)
            $meta = $meta->meta;
        if (is_subclass_of($parent, 'VerySimpleModel')) {
            $this->parent = $parent::getMeta();
            $meta = $this->parent->extend($this, $meta);
        }
        else {
            $meta = $meta + self::$base;
        }

        // Short circuit the meta-data processing if APCu is available.
        // This is preferred as the meta-data is unlikely to change unless
        // osTicket is upgraded, (then the upgrader calls the
        // flushModelCache method to clear this cache). Also, GIT_VERSION is
        // used in the APC key which should be changed if new code is
        // deployed.
        if (function_exists('apcu_store')) {
            $loaded = false;
            $apc_key = SECRET_SALT.GIT_VERSION."/orm/{$this->model}";
            $this->meta = apcu_fetch($apc_key, $loaded);
            if ($loaded)
                return;
        }

        if (!$meta['view']) {
            if (!$meta['table'])
                throw new OrmConfigurationException(
                    sprintf(__('%s: Model does not define meta.table'), $this->model));
            elseif (!$meta['pk'])
                throw new OrmConfigurationException(
                    sprintf(__('%s: Model does not define meta.pk'), $this->model));
        }

        // Ensure other supported fields are set and are arrays
        foreach (array('pk', 'ordering', 'defer', 'select_related') as $f) {
            if (!isset($meta[$f]))
                $meta[$f] = array();
            elseif (!is_array($meta[$f]))
                $meta[$f] = array($meta[$f]);
        }

        // Break down foreign-key metadata
        foreach ($meta['joins'] as $field => &$j) {
            $this->processJoin($j);
            if ($j['local'])
                $meta['foreign_keys'][$j['local']] = $field;
        }
        unset($j);
        $this->meta = $meta;

        if (function_exists('apcu_store')) {
            apcu_store($apc_key, $this->meta, 1800);
        }
    }

    /**
     * Merge this class's meta-data into the recieved child meta-data.
     * When a model extends another model, the meta data for the two models
     * is merged to form the child's meta data. Returns the merged, child
     * meta-data.
     */
    function extend(ModelMeta $child, $meta) {
        $this->subclasses[$child->model] = $child;
        // Merge 'joins' settings (instead of replacing)
        if (isset($this->meta['joins'])) {
            $meta['joins'] = array_merge($meta['joins'] ?: array(),
                $this->meta['joins']);
        }
        return $meta + $this->meta + self::$base;
    }

    function isSuperClassOf($model) {
        if (isset($this->subclasses[$model]))
            return true;
        foreach ($this->subclasses as $M=>$meta)
            if ($meta->isSuperClassOf($M))
                return true;
    }

    function isSubclassOf($model) {
        if (!isset($this->parent))
            return false;

        if ($this->parent->model === $model)
            return true;

        return $this->parent->isSubclassOf($model);
    }

    /**
     * Adds some more information to a declared relationship. If the
     * relationship is a reverse relation, then the information from the
     * reverse relation is loaded into the local definition
     *
     * Compiled-Join-Structure:
     * 'constraint' => array(local => array(foreign_field, foreign_class)),
     *      Constraint used to construct a JOIN in an SQL query
     * 'list' => boolean
     *      TRUE if an InstrumentedList should be employed to fetch a list
     *      of related items
     * 'broker' => Handler for the 'list' property. Usually a subclass of
     *      'InstrumentedList'
     * 'null' => boolean
     *      TRUE if relation is nullable
     * 'fkey' => array(class, pk)
     *      Classname and field of the first item in the constraint that
     *      points to a PK field of a foreign model
     * 'local' => string
     *      The local field corresponding to the 'fkey' property
     */
    function processJoin(&$j) {
        $constraint = array();
        if (isset($j['reverse'])) {
            list($fmodel, $key) = explode('.', $j['reverse']);
            // NOTE: It's ok if the forein meta data is not yet inspected.
            $info = $fmodel::$meta['joins'][$key];
            if (!is_array($info['constraint']))
                throw new OrmConfigurationException(sprintf(__(
                    // `reverse` here is the reverse of an ORM relationship
                    '%s: Reverse does not specify any constraints'),
                    $j['reverse']));
            foreach ($info['constraint'] as $foreign => $local) {
                list($L,$field) = is_array($local) ? $local : explode('.', $local);
                $constraint[$field ?: $L] = array($fmodel, $foreign);
            }
            if (!isset($j['list']))
                $j['list'] = true;
            if (!isset($j['null']))
                // By default, reverse releationships can be empty lists
                $j['null'] = true;
        }
        else {
            foreach ($j['constraint'] as $local => $foreign) {
                list($class, $field) = $constraint[$local]
                    = is_array($foreign) ? $foreign : explode('.', $foreign);
            }
        }
        if (isset($j['list']) && !isset($j['broker'])) {
            $j['broker'] = 'InstrumentedList';
        }
        if (isset($j['broker']) && !class_exists($j['broker'])) {
            throw new OrmException($j['broker'] . ': List broker does not exist');
        }
        foreach ($constraint as $local => $foreign) {
            list($class, $field) = $foreign;
            if ((isset($local[0]) && $local[0] == "'") || $field[0] == "'" || !class_exists($class))
                continue;
            $j['fkey'] = $foreign;
            $j['local'] = $local;
        }
        $j['constraint'] = $constraint;
    }

    function addJoin($name, array $join) {
        $this->meta['joins'][$name] = $join;
        $this->processJoin($this->meta['joins'][$name]);
    }

    /**
     * Fetch ModelMeta instance by following a join path from this model
     */
    function getByPath($path) {
        if (is_string($path))
            $path = explode('__', $path);
        $root = $this;
        foreach ($path as $P) {
            if (!($root = $root['joins'][$P]['fkey'][0]))
                break;
            $root = $root::getMeta();
        }
        return $root;
    }

    function offsetGet($field): mixed {
        return $this->meta[$field];
    }
    function offsetSet($field, $what): void {
        $this->meta[$field] = $what;
    }
    function offsetExists($field): bool {
        return isset($this->meta[$field]);
    }
    function offsetUnset($field): void {
        throw new Exception('Model MetaData is immutable');
    }

    /**
     * Fetch the column names of the table used to persist instances of this
     * model in the database.
     */
    function getFieldNames() {
        if (!isset($this->fields))
            $this->fields = $this->inspectFields();
        return $this->fields;
    }

    /**
     * Create a new instance of the model, optionally hydrating it with the
     * given hash table. The constructor is not called, which leaves the
     * default constructor free to assume new object status.
     *
     * Three methods were considered, with runtime for 10000 iterations
     *   * unserialze('O:9:"ModelBase":0:{}') - 0.0671s
     *   * new ReflectionClass("ModelBase")->newInstanceWithoutConstructor()
     *      - 0.0478s
     *   * and a hybrid by cloning the reflection class instance - 0.0335s
     */
    function newInstance($props=false) {
        if (!isset($this->new)) {
            $rc = new ReflectionClass($this->model);
            $this->new = $rc->newInstanceWithoutConstructor();
        }
        $instance = clone $this->new;
        // Hydrate if props were included
        if (is_array($props)) {
            $instance->ht = $props;
        }
        return $instance;
    }

    function inspectFields() {
        if (!isset(self::$model_cache))
            self::$model_cache = function_exists('apcu_fetch');
        if (self::$model_cache) {
            $key = SECRET_SALT.GIT_VERSION."/orm/{$this['table']}";
            if ($fields = apcu_fetch($key)) {
                return $fields;
            }
        }
        $fields = DbEngine::getCompiler()->inspectTable($this['table']);
        if (self::$model_cache) {
            apcu_store($key, $fields, 1800);
        }
        return $fields;
    }

    static function flushModelCache() {
        if (self::$model_cache)
            @apcu_clear_cache();
    }
}

class VerySimpleModel {
    static $meta = array(
        'table' => false,
        'ordering' => false,
        'pk' => false
    );

    var $ht = array();
    var $dirty = array();
    var $__new__ = false;
    var $__deleted__ = false;
    var $__deferred__ = array();

    function __construct($row=false) {
        if (is_array($row))
            foreach ($row as $field=>$value)
                if (!is_array($value))
                    $this->set($field, $value);
        $this->__new__ = true;
    }

    /**
     * Creates a new instance of the model without calling the constructor.
     * If the constructor is required, consider using the PHP `new` keyword.
     * The instance returned from this method will not be considered *new*
     * and will now result in an INSERT when sent to the database.
     */
    static function __hydrate($row=false) {
        return static::getMeta()->newInstance($row);
    }

    function __wakeup() {
        // If a model is stashed in a session, refresh the model from the database
        $this->refetch();
    }

    function get($field, $default=false) {
        if (array_key_exists($field, $this->ht))
            return $this->ht[$field];
        elseif (($joins = static::getMeta('joins')) && isset($joins[$field])) {
            $j = $joins[$field];
            // Support instrumented lists and such
            if (isset($j['list']) && $j['list']) {
                $class = $j['fkey'][0];
                $fkey = array();
                // Localize the foreign key constraint
                foreach ($j['constraint'] as $local=>$foreign) {
                    list($_klas,$F) = $foreign;
                    $fkey[$F ?: $_klas] = ($local[0] == "'")
                        ? trim($local, "'") : $this->ht[$local] ?? null;
                }
                $v = $this->ht[$field] = new $j['broker'](
                    // Send Model, [Foriegn-Field => Local-Id]
                    array($class, $fkey)
                );
                return $v;
            }
            // Support relationships
            elseif (isset($j['fkey'])) {
                $criteria = array();
                foreach ($j['constraint'] as $local => $foreign) {
                    list($klas,$F) = $foreign;
                    if (class_exists($klas))
                        $class = $klas;
                    if ($local[0] == "'") {
                        $criteria[$F] = trim($local,"'");
                    }
                    elseif ($F[0] == "'") {
                        // Does not affect the local model
                        continue;
                    }
                    else {
                        $criteria[$F] = $this->ht[$local];
                    }
                }
                try {
                    $v = $this->ht[$field] = $class::lookup($criteria);
                }
                catch (DoesNotExist $e) {
                    $v = null;
                }
                return $v;
            }
        }
        elseif (isset($this->__deferred__[$field])) {
            // Fetch deferred field
            $row = static::objects()->filter($this->getPk())
                // XXX: Seems like all the deferred fields should be fetched
                ->values_flat($field)
                ->one();
            if ($row)
                return $this->ht[$field] = $row[0];
        }
        elseif ($field == 'pk') {
            return $this->getPk();
        }

        if (isset($default))
            return $default;

        // For new objects, assume the field is NULLable
        if ($this->__new__)
            return null;

        // Check to see if the column referenced is actually valid
        if (in_array($field, static::getMeta()->getFieldNames()))
            return null;

        throw new OrmException(sprintf(__('%s: %s: Field not defined'),
            get_class($this), $field));
    }
    function __get($field) {
        return $this->get($field, null);
    }

    function getByPath($path) {
        if (is_string($path))
            $path = explode('__', $path);
        $root = $this;
        foreach ($path as $P)
            $root = $root->get($P);
        return $root;
    }

    function __isset($field) {
        return ($this->ht && array_key_exists($field, $this->ht))
            || isset(static::$meta['joins'][$field]);
    }

    function __unset($field) {
        if ($this->__isset($field))
            unset($this->ht[$field]);
        else
            unset($this->{$field});
    }

    function set($field, $value) {
        // Update of foreign-key by assignment to model instance
        $related = false;
        $joins = static::getMeta('joins');
        if (isset($joins[$field])) {
            $j = $joins[$field];
            if (isset($j['list']) && ($value instanceof InstrumentedList)) {
                // Magic list property
                $this->ht[$field] = $value;
                return;
            }
            if ($value === null) {
                $this->ht[$field] = $value;
                if (in_array($j['local'], static::$meta['pk'])) {
                    // Reverse relationship — don't null out local PK
                    return;
                }
                // Pass. Set local field to NULL in logic below
            }
            elseif ($value instanceof VerySimpleModel) {
                // Ensure that the model being assigned as a relationship is
                // an instance of the foreign model given in the
                // relationship, or is a super class thereof. The super
                // class case is used primary for the xxxThread classes
                // which all extend from the base Thread class.
                if (!$value instanceof $j['fkey'][0]
                    && !$value::getMeta()->isSuperClassOf($j['fkey'][0])
                ) {
                    throw new InvalidArgumentException(
                        sprintf(__('Expecting NULL or instance of %s. Got a %s instead'),
                        $j['fkey'][0], is_object($value) ? get_class($value) : gettype($value)));
                }
                // Capture the object under the object's field name
                $this->ht[$field] = $value;
                $value = $value->get($j['fkey'][1]);
                // Fall through to the standard logic below
            }
            // Capture the foreign key id value
            $field = $j['local'];
        }
        // elseif $field is in a relationship, adjust the relationship
        elseif (isset(static::$meta['foreign_keys'][$field])) {
            // meta->foreign_keys->{$field} points to the property of the
            // foreign object. For instance 'object_id' points to 'object'
            $related = static::$meta['foreign_keys'][$field];
        }
        $old = isset($this->ht[$field]) ? $this->ht[$field] : null;
        // `null == ''` y `null == 0` son ciertos en PHP, así que la
        // comparación suelta no basta: asignar una cadena vacía o un cero a un
        // campo que aún no estaba no marcaba el modelo como cambiado,
        // `save()` se daba por terminado y el valor no llegaba a la base (o se
        // quedaba el valor por defecto de la columna). Se compara la nulidad
        // aparte y se deja el `!=` para lo demás, que es lo que hace que un
        // '5' venido de la base y un 5 asignado no cuenten como cambio.
        if ($old != $value || ($old === null) !== ($value === null)) {
            // isset should not be used here, because `null` should not be
            // replaced in the dirty array
            if (!array_key_exists($field, $this->dirty))
                $this->dirty[$field] = $old;
            if ($related)
                // $related points to a foreign object propery. If setting a
                // new object_id value, the relationship to object should be
                // cleared and rebuilt
                unset($this->ht[$related]);
        }
        $this->ht[$field] = $value;
    }
    function __set($field, $value) {
        return $this->set($field, $value);
    }

    function setAll($props) {
        foreach ($props as $field=>$value)
            $this->set($field, $value);
    }

    function __onload() {}

    function serialize() {
        return $this->getPk();
    }

    function unserialize($data) {
        $this->ht = $data;
        $this->refetch();
    }

    static function getMeta($key=false) {
        if (!static::$meta instanceof ModelMeta
            || get_called_class() != static::$meta->model
        ) {
            static::$meta = new ModelMeta(get_called_class());
        }
        $M = static::$meta;
        return ($key) ? $M->offsetGet($key) : $M;
    }

    static function getOrmFields($recurse=false) {
        $fks = $lfields = $fields = array();
        $myname = get_called_class();
        foreach (static::getMeta('joins') as $name=>$j) {
            $fks[$j['local']] = true;
            if (!$j['reverse'] && !$j['list'] && $recurse) {
                foreach ($j['fkey'][0]::getOrmFields($recurse - 1) as $name2=>$f) {
                    $fields["{$name}__{$name2}"] = "{$name} / $f";
                }
            }
        }
        foreach (static::getMeta('fields') as $f) {
            if (isset($fks[$f]))
                continue;
            if (in_array($f, static::getMeta('pk')))
                continue;
            $lfields[$f] = "{$f}";
        }
        return $lfields + $fields;
    }

    /**
     * objects
     *
     * Retrieve a QuerySet for this model class which can be used to fetch
     * models from the connected database. Subclasses can override this
     * method to apply forced constraints on the QuerySet.
     */
    static function objects() {
        return new QuerySet(get_called_class());
    }

    /**
     * lookup
     *
     * Retrieve a record by its primary key. This method may be short
     * circuited by model caching if the record has already been loaded by
     * the database. In such a case, the database will not be consulted for
     * the model's data.
     *
     * This method can be called with an array of keyword arguments matching
     * the PK of the object or the values of the primary key. Both of these
     * usages are correct:
     *
     * >>> User::lookup(1)
     * >>> User::lookup(array('id'=>1))
     *
     * For composite primary keys and the first usage, pass the values in
     * the order they are given in the Model's 'pk' declaration in its meta
     * data.
     *
     * Parameters:
     * $criteria - (mixed) primary key for the sought model either as
     *      arguments or key/value array as the function's first argument
     *
     * Returns:
     * (Object<Model>|null) a single instance of the sought model or null if
     * no such instance exists.
     */
    static function lookup($criteria) {
        // Model::lookup(1), where >1< is the pk value
        $args = func_get_args();
        if (!is_array($criteria)) {
            $criteria = array();
            $pk = static::getMeta('pk');
            foreach ($args as $i=>$f)
                $criteria[$pk[$i]] = $f;

            // Only consult cache for PK lookup, which is assumed if the
            // values are passed as args rather than an array
            if ($cached = ModelInstanceManager::checkCache(get_called_class(),
                    $criteria))
                return $cached;
        }
        try {
            return static::objects()->filter($criteria)->one();
        }
        catch (DoesNotExist $e) {
            return null;
        }
    }

    function delete() {
        $ex = DbEngine::delete($this);
        try {
            $ex->execute();
            if ($ex->affected_rows() != 1)
                return false;

            $this->__deleted__ = true;
            Signal::send('model.deleted', $this);
        }
        catch (OrmException $e) {
            return false;
        }
        return true;
    }

    /**
     * Mensaje del último error del motor al guardar.
     *
     * `save()` se traga la excepción del ORM y devuelve `false`, que es lo
     * correcto para la aplicación pero deja al que llama sin saber por qué
     * (un `update()` que devuelve `false` y un `$errors` genérico). Se guarda
     * aquí para poder mostrarlo —el sembrador de datos lo imprime— sin
     * cambiar lo que devuelve `save()`.
     */
    static $lastError;

    static function getLastError() {
        return static::$lastError;
    }

    function save($refetch=false) {
        if ($this->__deleted__)
            throw new OrmException('Trying to update a deleted object');

        $pk = static::getMeta('pk');
        $wasnew = $this->__new__;

        // First, if any foreign properties of this object are connected to
        // another *new* object, then save those objects first and set the
        // local foreign key field values
        foreach (static::getMeta('joins') as $prop => $j) {
            if (isset($this->ht[$prop])
                && ($foreign = $this->ht[$prop])
                && $foreign instanceof VerySimpleModel
                && !in_array($j['local'], $pk)
                && null === $this->get($j['local'])
            ) {
                if ($foreign->__new__ && !$foreign->save())
                    return false;
                $this->set($j['local'], $foreign->get($j['fkey'][1]));
            }
        }

        // If there's nothing in the model to be saved, then we're done
        if (count($this->dirty) === 0)
            return true;

        static::$lastError = null;
        $ex = DbEngine::save($this);
        try {
            $ex->execute();
            if ($ex->affected_rows() != 1) {
                // This doesn't really signify an error. It just means that
                // the database believes that the row did not change. For
                // inserts though, it's a deal breaker
                if ($this->__new__) {
                    static::$lastError = sprintf(
                        'la base no insertó la fila de %s (affected_rows = %d)',
                        static::getMeta('table'), $ex->affected_rows());
                    return false;
                }
                else
                    // No need to reload the record if requested — the
                    // database didn't update anything
                    $refetch = false;
            }
        }
        catch (OrmException $e) {
            // Lo que el motor dijo (columna desconocida, valor fuera de
            // rango, clave repetida…), para quien quiera mostrarlo
            static::$lastError = $e->getMessage();
            return false;
        }

        if ($wasnew) {
            if (count($pk) == 1)
                // XXX: Ensure AUTO_INCREMENT is set for the field
                $this->ht[$pk[0]] = $ex->insert_id();
            $this->__new__ = false;
            Signal::send('model.created', $this);
        }
        else {
            $data = array('dirty' => $this->dirty);
            Signal::send('model.updated', $this, $data);
            foreach ($this->dirty as $key => $value) {
                if ($key != 'value' && $key != 'updated') {
                    $type = array('type' => 'edited', 'key' => $key, 'orm_audit' => true);
                    Signal::send('object.edited', $this, $type);
                }
            }
        }
        # Refetch row from database
        if ($refetch) {
            // Preserve non database information such as list relationships
            // across the refetch
            $this->refetch();
        }
        if ($wasnew) {
            // Attempt to update foreign, unsaved objects with the PK of
            // this newly created object
            foreach (static::getMeta('joins') as $prop => $j) {
                if (isset($this->ht[$prop])
                    && ($foreign = $this->ht[$prop])
                    && in_array($j['local'], $pk)
                ) {
                    if ($foreign instanceof VerySimpleModel
                        && null === $foreign->get($j['fkey'][1])
                    ) {
                        $foreign->set($j['fkey'][1], $this->get($j['local']));
                    }
                    elseif ($foreign instanceof InstrumentedList) {
                        foreach ($foreign as $item) {
                            if (null === $item->get($j['fkey'][1]))
                                $item->set($j['fkey'][1], $this->get($j['local']));
                        }
                    }
                }
            }
            $this->__onload();
        }
        $this->dirty = array();
        return true;
    }

    private function refetch() {
        try {
            $this->ht =
                static::objects()->filter($this->getPk())->values()->one()
                + $this->ht;
        } catch (DoesNotExist $ex) {}
    }

    private function getPk() {
        $pk = array();
        foreach ($this::getMeta('pk') as $f)
            $pk[$f] = $this->ht[$f];
        return $pk;
    }

    function getDbFields() {
        return $this->ht;
    }

    /**
     * Create a new clone of this model. The primary key will be unset and the
     * object will be set as __new__. The __clone() magic method is reserved
     * by the buildModel() system, because it clone's a single instance when
     * hydrating objects from the database.
     */
    function copy() {
        // Drop the PK and set as unsaved
        $dup = clone $this;
        foreach ($dup::getMeta('pk') as $f)
            $dup->__unset($f);
        $dup->__new__ = true;
        return $dup;
    }
}

/**
 * AnnotatedModel
 *
 * Simple wrapper class which allows wrapping and write-protecting of
 * annotated fields retrieved from the database. Instances of this class
 * will delegate most all of the heavy lifting to the wrapped Model instance.
 */
class AnnotatedModel {
    static function wrap(VerySimpleModel $model, $extras=array(), $class=false) {
        static $classes;

        $class = $class ?: get_class($model);

        if ($extras instanceof VerySimpleModel) {
            $extra = "Writeable";
        }
        $extra = $extra ?? null;
        if (!isset($classes[$class])) {
            $classes[$class] = eval(<<<END_CLASS
class {$extra}AnnotatedModel___{$class}
extends {$class} {
    protected \$__overlay__;
    use {$extra}AnnotatedModelTrait;

    static function __hydrate(\$ht=false, \$annotations=false) {
        \$instance = parent::__hydrate(\$ht);
        \$instance->__overlay__ = \$annotations;
        return \$instance;
    }
}
return "{$extra}AnnotatedModel___{$class}";
END_CLASS
            );
        }
        return $classes[$class]::__hydrate($model->ht, $extras);
    }
}

trait AnnotatedModelTrait {
    function get($what, $default=false) {
        if (isset($this->__overlay__[$what]))
            return $this->__overlay__[$what];
        return parent::get($what);
    }

    function set($what, $to) {
        if (isset($this->__overlay__[$what]))
            throw new OrmException('Annotated fields are read-only');
        return parent::set($what, $to);
    }

    function __isset($what) {
        if (isset($this->__overlay__[$what]))
            return true;
        return parent::__isset($what);
    }

    function getDbFields() {
        return $this->__overlay__ + parent::getDbFields();
    }
}

/**
 * Slight variant on the AnnotatedModelTrait, except that the overlay is
 * another model. Its fields are preferred over the wrapped model's fields.
 * Updates to the overlayed fields are tracked in the overlay model and
 * therefore kept separate from the annotated model's fields. ::save() will
 * call save on both models. Delete will only delete the overlay model (that
 * is, the annotated model will remain).
 */
trait WriteableAnnotatedModelTrait {
    function get($what, $default=false) {
        if ($this->__overlay__->__isset($what))
            return $this->__overlay__->get($what);
        return parent::get($what);
    }

    function set($what, $to) {
        if (isset($this->__overlay__)
            && $this->__overlay__->__isset($what)) {
            return $this->__overlay__->set($what, $to);
        }
        return parent::set($what, $to);
    }

    function __isset($what) {
        if (isset($this->__overlay__) && $this->__overlay__->__isset($what))
            return true;
        return parent::__isset($what);
    }

    function getDbFields() {
        return $this->__overlay__->getDbFields() + parent::getDbFields();
    }

    function save($refetch=false) {
        $this->__overlay__->save($refetch);
        return parent::save($refetch);
    }

    function delete() {
        if ($rv = $this->__overlay__->delete())
            // Mark the annotated object as deleted
            $this->__deleted__ = true;
        return $rv;
    }
}

















class QuerySet implements IteratorAggregate, ArrayAccess, Serializable, Countable {
    var $model;

    var $constraints = array();
    var $path_constraints = array();
    var $ordering = array();
    var $limit = false;
    var $offset = 0;
    var $related = array();
    var $values = array();
    var $defer = array();
    var $aggregated = false;
    var $annotations = array();
    var $extra = array();
    var $distinct = array();
    var $lock = false;
    var $chain = array();
    var $options = array();

    const LOCK_EXCLUSIVE = 1;
    const LOCK_SHARED = 2;

    const ASC = 'ASC';
    const DESC = 'DESC';

    const OPT_NOSORT    = 'nosort';
    const OPT_NOCACHE   = 'nocache';
    const OPT_MYSQL_FOUND_ROWS = 'found_rows';
    const OPT_INDEX_HINT = 'indexhint';

    const ITER_MODELS   = 1;
    const ITER_HASH     = 2;
    const ITER_ROW      = 3;

    var $iter = self::ITER_MODELS;

    var $compiler = 'MySqlCompiler';

    var $query;
    var $count;
    /* Iterador ya creado: `getIterator()` lo guarda para no repetir la
       consulta. Sin declararlo, la primera vuelta de cada QuerySet creaba
       una propiedad dinámica. */
    var $_iterator;
    var $total;

    function __construct($model) {
        $this->model = $model;
    }

    function filter() {
        // Multiple arrays passes means OR
        foreach (func_get_args() as $Q) {
            // Un filtro vacío no restringe nada. Si se guardara, el SQL
            // saldría con un `WHERE` sin condición (error de sintaxis en
            // MySQL) — pasa en las listas que arman el criterio según los
            // filtros de la petición y no hay ninguno.
            if (self::isEmptyFilter($Q))
                continue;
            $this->constraints[] = $Q instanceof Q ? $Q : new Q($Q);
        }
        return $this;
    }

    function exclude() {
        foreach (func_get_args() as $Q) {
            if (self::isEmptyFilter($Q))
                continue;
            $this->constraints[] = $Q instanceof Q ? $Q->negate() : Q::not($Q);
        }
        return $this;
    }

    /** ¿El filtro no aporta ninguna condición? */
    static function isEmptyFilter($Q) {
        if ($Q instanceof Q)
            return !$Q->constraints;
        return is_array($Q) && !$Q;
    }

    /**
     * Add a path constraint for the query. This is different from ::filter
     * in that the constraint is added to a join clause which is normally
     * built from the model meta data. The ::filter() method on the other
     * hand adds the constraint to the where clause. This is generally useful
     * for aggregate queries and left join queries where multiple rows might
     * match a filter in the where clause and would produce incorrect results.
     *
     * Example:
     * Find users with personal email hosted with gmail.
     * >>> $Q = User::objects();
     * >>> $Q->constrain(['user__emails' => new Q(['type' => 'personal']))
     * >>> $Q->filter(['user__emails__address__contains' => '@gmail.com'])
     */
    function constrain() {
        foreach (func_get_args() as $I) {
            foreach ($I as $path => $Q) {
                if (!is_array($Q) && !$Q instanceof Q) {
                    // ->constrain(array('field__path__op' => val));
                    $Q = array($path => $Q);
                    list(, $path) = SqlCompiler::splitCriteria($path);
                    $path = implode('__', $path);
                }
                $this->path_constraints[$path][] = $Q instanceof Q ? $Q : Q::all($Q);
            }
        }
        return $this;
    }

    function defer() {
        foreach (func_get_args() as $f)
            $this->defer[$f] = true;
        return $this;
    }
    function order_by($order, $direction=false) {
        if ($order === false)
            return $this->options(array('nosort' => true));

        $args = func_get_args();
        if (in_array($direction, array(self::ASC, self::DESC))) {
            $args = array($args[0]);
        }
        else
            $direction = false;

        $new = is_array($order) ?  $order : $args;
        if ($direction) {
            foreach ($new as $i=>$x) {
                $new[$i] = array($x, $direction);
            }
        }
        $this->ordering = array_merge($this->ordering, $new);
        return $this;
    }
    function getSortFields() {
        $ordering = $this->ordering;
        if (isset($this->extra['order_by']))
            $ordering = array_merge($ordering, $this->extra['order_by']);
        return $ordering;
    }

    function lock($how=false) {
        $this->lock = $how ?: self::LOCK_EXCLUSIVE;
        return $this;
    }

    function limit($count) {
        $this->limit = $count;
        return $this;
    }

    function offset($at) {
        $this->offset = $at;
        return $this;
    }

    function isWindowed() {
        return $this->limit || $this->offset || (count($this->values) + count($this->annotations) + @count($this->extra['select'] ?? array())) > 1;
    }

    /**
     * Fetch related fields with the query. This will result in better
     * performance as related items are fetched with the root model with
     * only one trip to the database.
     *
     * Either an array of fields can be sent as one argument, or the list of
     * fields can be sent as the arguments to the function.
     *
     * Example:
     * >>> $q = User::objects()->select_related('role');
     */
    function select_related() {
        $args = func_get_args();
        if (is_array($args[0]))
            $args = $args[0];

        $this->related = array_merge($this->related, $args);
        return $this;
    }

    function extra(array $extra) {
        foreach ($extra as $section=>$info) {
            $this->extra[$section] = array_merge($this->extra[$section] ?? array(), $info);
        }
        return $this;
    }

    function addExtraJoin(array $join) {
       return $this->extra(array('joins' => array($join)));
    }

    function distinct() {
        foreach (func_get_args() as $D)
            $this->distinct[] = $D;
        return $this;
    }

    function models() {
        $this->iter = self::ITER_MODELS;
        $this->values = $this->related = array();
        return $this;
    }

    function values() {
        $args = func_get_args();
        // Un arreglo suelto permite alias explícito y expresiones calculadas,
        // que no pueden ser claves de arreglo: values(array('day' => $expr))
        if (count($args) == 1 && is_array($args[0]))
            $args = $args[0];
        foreach ($args as $k => $A) {
            if (is_string($k)) {
                if ($A instanceof SqlFunction)
                    $A->setAlias($k);
                $this->values[$k] = $A;
            }
            else {
                $this->values[$A] = $A;
            }
        }
        $this->iter = self::ITER_HASH;
        // This disables related models
        $this->related = false;
        return $this;
    }

    function values_flat() {
        $this->values = func_get_args();
        $this->iter = self::ITER_ROW;
        // This disables related models
        $this->related = false;
        return $this;
    }

    function copy() {
        return clone $this;
    }

    function all() {
        return $this->getIterator()->asArray();
    }

    function first() {
        $list = $this->limit(1)->all();
        return $list[0];
    }

    /**
     * one
     *
     * Finds and returns a single model instance based on the criteria in
     * this QuerySet instance.
     *
     * Throws:
     * DoesNotExist - if no such model exists with the given criteria
     * ObjectNotUnique - if more than one model matches the given criteria
     *
     * Returns:
     * (Object<Model>) a single instance of the sought model is guarenteed.
     * If no such model or multiple models exist, an exception is thrown.
     */
    function one() {
        $list = $this->all();
        if (count($list) == 0)
            throw new DoesNotExist();
        elseif (count($list) > 1)
            throw new ObjectNotUnique('One object was expected; however '
                .'multiple objects in the database matched the query. '
                .sprintf('In fact, there are %d matching objects.', count($list))
            );
        return $list[0];
    }

    function count(): int {
        // Defer to the iterator if fetching already started
        if (isset($this->_iterator)) {
            return $this->_iterator->count();
        }
        elseif (isset($this->count)) {
            return $this->count;
        }
        $class = $this->compiler;
        $compiler = new $class();
        return $this->count = $compiler->compileCount($this);
    }

    /**
     * Similar to count, except that the LIMIT and OFFSET parts are not
     * considered in the counts. That is, this will return the count of rows
     * if the query were not windowed with limit() and offset().
     *
     * For MySQL, the query will be submitted and fetched and the
     * SQL_CALC_FOUND_ROWS hint will be sent in the query. Afterwards, the
     * result of FOUND_ROWS() is fetched and is the result of this function.
     *
     * The result of this function is cached. If further changes are made
     * after this is run, the changes should be made in a clone.
     */
    function total() {
        if (isset($this->total))
            return $this->total;

        // Optimize the query with the CALC_FOUND_ROWS if
        // - the compiler supports it
        // - the iterator hasn't yet been built, that is, the query for this
        //   statement has not yet been sent to the database
        $compiler = $this->compiler;
        if ($compiler::supportsOption(self::OPT_MYSQL_FOUND_ROWS)
            && !isset($this->_iterator)
        ) {
            // This optimization requires caching
            $this->options(array(
                self::OPT_MYSQL_FOUND_ROWS => 1,
                self::OPT_NOCACHE => null,
            ));
            $this->exists(true);
            $compiler = new $compiler();
            return $this->total = $compiler->getFoundRows();
        }

        $query = clone $this;
        $query->limit(false)->offset(false)->order_by(false);
        return $this->total = $query->count();
    }

    function toSql($compiler, $model, $alias=false) {
        // FIXME: Force root model of the compiler to $model
        $exec = $this->getQuery(array('compiler' => get_class($compiler),
             'parent' => $compiler, 'subquery' => true));
        // Rewrite the parameter numbers so they fit the parameter numbers
        // of the current parameters of the $compiler
        $sql = preg_replace_callback("/:(\d+)/",
        function($m) use ($compiler, $exec) {
            $compiler->params[] = $exec->params[$m[1]-1];
            return ':'.count($compiler->params);
        }, $exec->sql);
        return "({$sql})".($alias ? " AS {$alias}" : '');
    }

    /**
     * exists
     *
     * Determines if there are any rows in this QuerySet. This can be
     * achieved either by evaluating a SELECT COUNT(*) query or by
     * attempting to fetch the first row from the recordset and return
     * boolean success.
     *
     * Parameters:
     * $fetch - (bool) TRUE if a compile and fetch should be attempted
     *      instead of a SELECT COUNT(*). This would be recommended if an
     *      accurate count is not required and the records would be fetched
     *      if this method returns TRUE.
     *
     * Returns:
     * (bool) TRUE if there would be at least one record in this QuerySet
     */
    function exists($fetch=false) {
        if ($fetch) {
            return (bool) $this[0];
        }
        return $this->count() > 0;
    }

    function annotate($annotations) {
        if (!is_array($annotations))
            $annotations = func_get_args();
        foreach ($annotations as $name=>$A) {
            if ($A instanceof SqlFunction) {
                if (is_int($name))
                    $name = $A->getFieldName();
                $A->setAlias($name);
            }
            $this->annotations[$name] = $A;
        }
        return $this;
    }

    function aggregate($annotations) {
        // Aggregate works like annotate, except that it sets up values
        // fetching which will disable model creation
        $this->annotate($annotations);
        $this->values();
        // Disable other fields from being fetched
        $this->aggregated = true;
        $this->related = false;
        return $this;
    }

    function options($options) {
        // Make an array with $options as the only key
        if (!is_array($options))
            $options = array($options => 1);

        $this->options = array_merge($this->options, $options);
        return $this;
    }

    function hasOption($option) {
        return isset($this->options[$option]);
    }

    function getOption($option) {
        return @$this->options[$option] ?: false;
    }

    function setOption($option, $value) {
        $this->options[$option] = $value;
    }

    function clearOption($option) {
        unset($this->options[$option]);
    }

    function countSelectFields() {
        $count = count($this->values) + count($this->annotations);
        if (isset($this->extra['select']))
            foreach (@$this->extra['select'] as $S)
                $count += count($S);
        return $count;
    }

    function union(QuerySet $other, $all=true) {
        // Values and values_list _must_ match for this to work
        if ($this->countSelectFields() != $other->countSelectFields())
            throw new OrmException('Union queries must have matching values counts');

        // TODO: Clear OFFSET and LIMIT in the $other query

        $this->chain[] = array($other, $all);
        return $this;
    }

    function delete() {
        $class = $this->compiler;
        $compiler = new $class();
        // XXX: Mark all in-memory cached objects as deleted
        $ex = $compiler->compileBulkDelete($this);
        $ex->execute();
        return $ex->affected_rows();
    }

    function update(array $what) {
        $class = $this->compiler;
        $compiler = new $class;
        $ex = $compiler->compileBulkUpdate($this, $what);
        $ex->execute();
        return $ex->affected_rows();
    }

    function __clone() {
        unset($this->_iterator);
        unset($this->query);
        unset($this->count);
        unset($this->total);
    }

    function __call($name, $args) {

        if (!is_callable(array($this->getIterator(), $name)))
            throw new OrmException('Call to undefined method QuerySet::'.$name);

        return $args
            ? call_user_func_array(array($this->getIterator(), $name), $args)
            : call_user_func(array($this->getIterator(), $name));
    }

    // IteratorAggregate interface
    function getIterator($iterator=false): Traversable {
        if (!isset($this->_iterator)) {
            $class = $iterator ?: $this->getIteratorClass();
            $it = new $class($this);
            if (!$this->hasOption(self::OPT_NOCACHE)) {
                if ($this->iter == self::ITER_MODELS)
                    // Add findFirst() and such
                    $it = new ModelResultSet($it);
                else
                    $it = new CachedResultSet($it);
            }
            else {
                $it = $it->getIterator();
            }
            $this->_iterator = $it;
        }
        return $this->_iterator;
    }

    function getIteratorClass() {
        switch ($this->iter) {
        case self::ITER_MODELS:
            return 'ModelInstanceManager';
        case self::ITER_HASH:
            return 'HashArrayIterator';
        case self::ITER_ROW:
            return 'FlatArrayIterator';
        }
    }

    // ArrayAccess interface
    function offsetExists($offset): bool {
        return $this->getIterator()->offsetExists($offset);
    }
    function offsetGet($offset): mixed {
        return $this->getIterator()->offsetGet($offset);
    }
    function offsetUnset($a): void {
        throw new Exception(__('QuerySet is read-only'));
    }
    function offsetSet($a, $b): void {
        throw new Exception(__('QuerySet is read-only'));
    }

    function __toString() {
        return (string) $this->getQuery();
    }

    function getQuery($options=array()) {
        if (isset($this->query))
            return $this->query;

        // Load defaults from model
        $model = $this->model;
        $meta = $model::getMeta();
        $query = clone $this;
        $options += $this->options;
        if (isset($options['nosort']))
            $query->ordering = array();
        elseif (!$query->ordering && $meta['ordering'])
            $query->ordering = $meta['ordering'];
        if (false !== $query->related && !$query->related && !$query->values && $meta['select_related'])
            $query->related = $meta['select_related'];
        if (!$query->defer && $meta['defer'])
            $query->defer = $meta['defer'];

        $class = $options['compiler'] ?? $this->compiler;
        $compiler = new $class($options);
        $this->query = $compiler->compileSelect($query);

        return $this->query;
    }

    /**
     * Fetch a model class which can be used to render the QuerySet as a
     * subquery to be used as a JOIN.
     */
    function asView() {
        $unique = spl_object_hash($this);
        $classname = "QueryView{$unique}";

        if (class_exists($classname))
            return $classname;

        $class = <<<EOF
class {$classname} extends VerySimpleModel {
    static \$meta = array(
        'view' => true,
    );
    static \$queryset;

    static function getQuery(\$compiler) {
        return ' ('.static::\$queryset->getQuery().') ';
    }

    static function getSqlAddParams(\$compiler) {
        return static::\$queryset->toSql(\$compiler, self::\$queryset->model);
    }
}
EOF;
        eval($class); // Ugh
        $classname::$queryset = $this;
        return $classname;
    }

    // Fix PHP 8.1.x Deprecation Warnings
    // Serializable interface will be removed in PHP 9.x
    function serialize() {
        return serialize($this->__serialize());
    }

    function unserialize($data) {
        $this->__unserialize(unserialize($data));
    }

    function __serialize() {
        $info = get_object_vars($this);
        unset($info['query']);
        unset($info['limit']);
        unset($info['offset']);
        unset($info['_iterator']);
        unset($info['count']);
        unset($info['total']);
        return $info;
    }

    function __unserialize($data) {
        foreach ($data as $name => $val) {
            $this->{$name} = $val;
        }
    }
}













$StopIteration = new StopIteration();



















class Q implements Serializable {
    const NEGATED = 0x0001;
    const ANY =     0x0002;

    var $constraints;
    var $negated = false;
    var $ored = false;

    function __construct($filter=array(), $flags=0) {
        if (!is_array($filter))
            $filter = array($filter);
        $this->constraints = $filter;
        $this->negated = $flags & self::NEGATED;
        $this->ored = $flags & self::ANY;
    }

    function isNegated() {
        return $this->negated;
    }

    function isOred() {
        return $this->ored;
    }

    function negate() {
        $this->negated = !$this->negated;
        return $this;
    }

    function union() {
        $this->ored = true;
    }

    /**
     * Two neighboring Q's are compatible in a where clause if they have
     * the same boolean AND / OR operator. Negated Q's should always use
     * parentheses if there is more than one criterion.
     */
    function isCompatibleWith(Q $other) {
        return $this->ored == $other->ored;
    }

    function add($constraints) {
        if (is_array($constraints))
            $this->constraints = array_merge($this->constraints, $constraints);
        elseif ($constraints instanceof static)
            $this->constraints[] = $constraints;
        else
            throw new InvalidArgumentException('Expected an instance of Q or an array thereof');
        return $this;
    }

    static function not($constraints) {
        return new static($constraints, self::NEGATED);
    }

    static function any($constraints) {
        return new static($constraints, self::ANY);
    }

    static function all($constraints) {
        return new static($constraints);
    }

    function evaluate($record) {
        // Start with FALSE for OR and TRUE for AND
        $result = !$this->ored;
        foreach ($this->constraints as $field=>$check) {
            $R = SqlCompiler::evaluate($record, $check, $field);
            if ($this->ored) {
                if ($result |= $R)
                    break;
            }
            elseif (!$R) {
                // Anything AND false
                $result = false;
                break;
            }
        }
        if ($this->negated)
            $result = !$result;
        return $result;
    }

    // Fix PHP 8.1.x Deprecation Warnings
    // Serializable interface will be removed in PHP 9.x
    function serialize() {
        return serialize($this->__serialize());
    }

    function unserialize($data) {
        $this->__unserialize(unserialize($data));
    }

    function __serialize() {
        return array($this->negated, $this->ored, $this->constraints);
    }

    function __unserialize($data) {
        list($this->negated, $this->ored, $this->constraints) = $data;
    }
}
?>
