<?php
/*********************************************************************
    class.orm.result.php

    Conjuntos de resultados: lo que devuelve una consulta cuando se recorre, y
    las listas instrumentadas que usan las relaciones del modelo.

    Parte de include/class.orm.php (frente 6 de docs/CODE_QUALITY.md): el
    archivo tenia 3.643 lineas y 34 clases. Se movieron las clases enteras,
    sin tocar su codigo; el original incluye este al final.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
class DoesNotExist extends Exception {}

class ObjectNotUnique extends Exception {}

class CachedResultSet
extends BaseList
implements ArrayAccess {
    protected $inner;
    protected $eoi = false;

    function __construct(IteratorAggregate $iterator) {
        $this->inner = $iterator->getIterator();
    }

    function fillTo($level) {
        while (!$this->eoi && count($this->storage) < $level) {
            if (!$this->inner->valid()) {
                $this->eoi = true;
                break;
            }
            $this->storage[] = $this->inner->current();
            $this->inner->next();
        }
    }

    function asArray() {
        $this->fillTo(PHP_INT_MAX);
        return $this->getCache();
    }

    function getCache() {
        return $this->storage;
    }

    function reset() {
        $this->eoi = false;
        $this->storage = array();
        // XXX: Should the inner be recreated to refetch?
        $this->inner->rewind();
    }

    function getIterator(): Traversable {
        $this->asArray();
        return new ArrayIterator($this->storage);
    }

    function offsetExists($offset): bool {
        $this->fillTo($offset+1);
        return count($this->storage) > $offset;
    }
    function offsetGet($offset): mixed {
        $this->fillTo($offset+1);
        return $this->storage[$offset];
    }
    function offsetUnset($a): void {
        throw new Exception(__('QuerySet is read-only'));
    }
    function offsetSet($a, $b): void {
        throw new Exception(__('QuerySet is read-only'));
    }

    function count($mode=COUNT_NORMAL): int {
        $this->asArray();
        return count($this->storage);
    }

    /**
     * Sort the instrumented list in place. This would be useful to change the
     * sorting order of the items in the list without fetching the list from
     * the database again.
     *
     * Parameters:
     * $key - (callable|int) A callable function to produce the sort keys
     *      or one of the SORT_ constants used by the array_multisort
     *      function
     * $reverse - (bool) true if the list should be sorted descending
     *
     * Returns:
     * This instrumented list for chaining and inlining.
     */
    function sort($key=false, $reverse=false) {
        // Fetch all records into the cache
        $this->asArray();
        parent::sort($key, $reverse);
        return $this;
    }

    /**
     * Reverse the list item in place. Returns this object for chaining
     */
    function reverse() {
        $this->asArray();
        return parent::reverse();
    }
}

class ModelResultSet
extends CachedResultSet {
    /* Los rellena InstrumentedList (listas de relaciones); se declaran aquí
       para que no sean propiedades dinámicas. */
    var $model;
    var $queryset;

    /**
     * Find the first item in the current set which matches the given criteria.
     * This would be used in favor of ::filter() which might trigger another
     * database query. The criteria is intended to be quite simple and should
     * not traverse relationships which have not already been fetched.
     * Otherwise, the ::filter() or ::window() methods would provide better
     * performance.
     *
     * Example:
     * >>> $a = new User();
     * >>> $a->roles->add(Role::lookup(['name' => 'administator']));
     * >>> $a->roles->findFirst(['roles__name__startswith' => 'admin']);
     * <Role: administrator>
     */
    function findFirst($criteria) {
        $records = $this->findAll($criteria, 1);
        return count($records) > 0 ? $records[0] : null;
    }

    /**
     * Find all the items in the current set which match the given criteria.
     * This would be used in favor of ::filter() which might trigger another
     * database query. The criteria is intended to be quite simple and should
     * not traverse relationships which have not already been fetched.
     * Otherwise, the ::filter() or ::window() methods would provide better
     * performance, as they can provide results with one more trip to the
     * database.
     */
    function findAll($criteria, $limit=false) {
        $records = new ListObject();
        foreach ($this as $record) {
            $matches = true;
            foreach ($criteria as $field=>$check) {
                if (!SqlCompiler::evaluate($record, $check, $field)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches)
                $records[] = $record;
            if ($limit && count($records) == $limit)
                break;
        }
        return $records;
    }
}

class ModelInstanceManager
implements IteratorAggregate {
    var $model;
    var $map;
    var $resource;
    var $annotations;
    var $defer;

    static $objectCache = array();

    function __construct(QuerySet $queryset) {
        $this->model = $queryset->model;
        $this->resource = $queryset->getQuery();
        $cache = !$queryset->hasOption(QuerySet::OPT_NOCACHE);
        $this->resource->setBuffered($cache);
        $this->map = $this->resource->getMap();
        $this->annotations = $queryset->annotations;
        $this->defer = $queryset->defer;
    }

    function cache($model) {
        $key = sprintf('%s.%s',
            $model::$meta->model, implode('.', $model->get('pk')));
        self::$objectCache[$key] = $model;
    }

    /**
     * uncache
     *
     * Drop the cached reference to the model. If the model is deleted
     * database-side. Lookups for the same model should not be short
     * circuited to retrieve the cached reference.
     */
    static function uncache($model) {
        $key = sprintf('%s.%s',
            $model::$meta->model, implode('.', $model->pk));
        unset(self::$objectCache[$key]);
    }

    static function flushCache() {
        self::$objectCache = array();
    }

    static function checkCache($modelClass, $fields) {
        $key = $modelClass::$meta->model;
        foreach ($modelClass::getMeta('pk') as $f)
            $key .= '.'.$fields[$f];
        return @self::$objectCache[$key];
    }

    /**
     * getOrBuild
     *
     * Builds a new model from the received fields or returns the model
     * already stashed in the model cache. Caching helps to ensure that
     * multiple lookups for the same model identified by primary key will
     * fetch the exact same model. Therefore, changes made to the model
     * anywhere in the project will be reflected everywhere.
     *
     * For annotated models (models build from querysets with annotations),
     * the built or cached model is wrapped in an AnnotatedModel instance.
     * The annotated fields are in the AnnotatedModel instance and the
     * database-backed fields are managed by the Model instance.
     */
    function getOrBuild($modelClass, $fields, $cache=true) {
        // Check for NULL primary key, used with related model fetching. If
        // the PK is NULL, then consider the object to also be NULL
        foreach ($modelClass::getMeta('pk') as $pkf) {
            if (!isset($fields[$pkf])) {
                return null;
            }
        }
        $annotations = $this->annotations;
        $extras = array();
        // For annotations, drop them from the $fields list and add them to
        // an $extras list. The fields passed to the root model should only
        // be the root model's fields. The annotated fields will be wrapped
        // using an AnnotatedModel instance.
        if ($annotations && $modelClass == $this->model) {
            foreach ($annotations as $name=>$A) {
                if (array_key_exists($name, $fields)) {
                    $extras[$name] = $fields[$name];
                    unset($fields[$name]);
                }
            }
        }
        // Check the cache for the model instance first
        if (!($m = self::checkCache($modelClass, $fields))) {
            // Construct and cache the object
            $m = $modelClass::__hydrate($fields);
            // XXX: defer may refer to fields not in this model
            $m->__deferred__ = $this->defer;
            $m->__onload();
            if ($cache)
                $this->cache($m);
        }
        // Wrap annotations in an AnnotatedModel
        if ($extras) {
            $m = AnnotatedModel::wrap($m, $extras, $modelClass);
        }
        // TODO: If the model has deferred fields which are in $fields,
        // those can be resolved here
        return $m;
    }

    /**
     * buildModel
     *
     * This method builds the model including related models from the record
     * received. For related recordsets, a $map should be setup inside this
     * object prior to using this method. The $map is assumed to have this
     * configuration:
     *
     * array(array(<fieldNames>, <modelClass>, <relativePath>))
     *
     * Where $modelClass is the name of the foreign (with respect to the
     * root model ($this->model), $fieldNames is the number and names of
     * fields in the row for this model, $relativePath is the path that
     * describes the relationship between the root model and this model,
     * 'user__account' for instance.
     */
    function buildModel($row, $cache=true) {
        // TODO: Traverse to foreign keys
        if ($this->map) {
            if ($this->model != $this->map[0][1])
                throw new OrmException('Internal select_related error');

            $offset = 0;
            foreach ($this->map as $info) {
                @list($fields, $model_class, $path) = $info;
                $values = array_slice($row, $offset, count($fields));
                $record = array_combine($fields, $values);
                if (!$path) {
                    // Build the root model
                    $model = $this->getOrBuild($this->model, $record, $cache);
                }
                elseif ($model) {
                    $i = 0;
                    // Traverse the declared path and link the related model
                    $tail = array_pop($path);
                    $m = $model;
                    foreach ($path as $field) {
                        if (!($m = $m->get($field)))
                            break;
                    }
                    if ($m) {
                        // Only apply cache setting to the root model.
                        // Reference models should use caching
                        $m->set($tail, $this->getOrBuild($model_class, $record, $cache));
                    }
                }
                $offset += count($fields);
            }
        }
        else {
            $model = $this->getOrBuild($this->model, $row, $cache);
        }
        return $model;
    }

    function getIterator(): Traversable {
        $func = ($this->map) ? 'getRow' : 'getArray';
        $func = array($this->resource, $func);
        $cache = true;

        return new CallbackSimpleIterator(function() use ($func, $cache) {
            global $StopIteration;

            if ($row = $func())
                return $this->buildModel($row, $cache);

            $this->resource->close();
            throw $StopIteration;
        });
    }
}

class CallbackSimpleIterator
implements Iterator {
    var $current;
    var $eoi;
    var $callback;
    var $key = -1;

    function __construct($callback) {
        assert(is_callable($callback));
        $this->callback = $callback;
    }

    function rewind(): void {
        $this->eoi = false;
        $this->next();
    }

    function key(): mixed {
        return $this->key;
    }

    function valid(): bool {
        if (!isset($this->eoi))
            $this->rewind();
        return !$this->eoi;
    }

    function current(): mixed {
        if ($this->eoi) return false;
        return $this->current;
    }

    function next(): void {
        try {
            $cbk = $this->callback;
            $this->current = $cbk();
            $this->key++;
        }
        catch (StopIteration $x) {
            $this->eoi = true;
        }
    }
}

// Use a global variable, as constructing exceptions is expensive
class StopIteration extends Exception {}

class FlatArrayIterator
implements IteratorAggregate {
    var $queryset;
    var $resource;

    function __construct(QuerySet $queryset) {
        $this->queryset = $queryset;
    }

    function getIterator(): Traversable {
        $this->resource = $this->queryset->getQuery();
        return new CallbackSimpleIterator(function() {
            global $StopIteration;

            if ($row = $this->resource->getRow())
                return $row;

            $this->resource->close();
            throw $StopIteration;
        });
    }
}

class HashArrayIterator
implements IteratorAggregate {
    var $queryset;
    var $resource;

    function __construct(QuerySet $queryset) {
        $this->queryset = $queryset;
    }

    function getIterator(): Traversable {
        $this->resource = $this->queryset->getQuery();
        return new CallbackSimpleIterator(function() {
            global $StopIteration;

            if ($row = $this->resource->getArray())
                return $row;

            $this->resource->close();
            throw $StopIteration;
        });
    }
}

class InstrumentedList
extends ModelResultSet {
    var $key;

    function __construct($fkey, $queryset=false,
        $iterator='ModelInstanceManager'
    ) {
        list($model, $this->key) = $fkey;
        if (!$queryset) {
            $queryset = $model::objects()->filter($this->key);
            if ($related = $model::getMeta('select_related'))
                $queryset->select_related($related);
        }
        parent::__construct(new $iterator($queryset));
        $this->model = $model;
        $this->queryset = $queryset;
    }

    function add($object, $save=true, $at=false) {
        // NOTE: Attempting to compare $object to $this->model will likely
        // be problematic, and limits creative applications of the ORM
        if (!$object) {
            throw new Exception(sprintf(
                'Attempting to add invalid object to list. Expected <%s>, but got <NULL>',
                $this->model
            ));
        }

        foreach ($this->key as $field=>$value)
            $object->set($field, $value);

        if (!$object->__new__ && $save)
            $object->save();

        if ($at !== false)
            $this->storage[$at] = $object;
        else
            $this->storage[] = $object;

        return $object;
    }

    function merge(InstrumentedList $list, $save=false) {
       foreach ($list as $object)
         $this->add($object, $save);

       return $this;
    }

    function remove($object, $delete=true) {
        if ($delete)
            $object->delete();
        else
            foreach ($this->key as $field=>$value)
                $object->set($field, null);
    }

    /**
     * Slight edit to the standard iteration method which will skip deleted
     * items.
     */
    function getIterator(): Traversable {
        return new CallbackFilterIterator(parent::getIterator(),
            function($i) { return !$i->__deleted__; }
        );
    }

    /**
     * Reduce the list to a subset using a simply key/value constraint. New
     * items added to the subset will have the constraint automatically
     * added to all new items.
     *
     * Parameters:
     * $criteria - (<Traversable>) criteria by which this list will be
     *    constrained and filtered.
     * $evaluate - (<bool>) if set to TRUE, the criteria will be evaluated
     *    without making any more trips to the database. NOTE this may yield
     *    unexpected results if this list does not contain all the records
     *    from the database which would be matched by another query.
     */
    function window($constraint, $evaluate=false) {
        $model = $this->model;
        $fields = $model::getMeta()->getFieldNames();
        $key = $this->key;
        foreach ($constraint as $field=>$value) {
            if (!is_string($field) || false === in_array($field, $fields))
                throw new OrmException('InstrumentedList windowing must be performed on local fields only');
            $key[$field] = $value;
        }
        $list = new static(array($this->model, $key), $this->filter($constraint));
        if ($evaluate) {
            $list->setCache($this->findAll($constraint));
        }
        return $list;
    }

    /**
     * Disable database fetching on this list by providing a static list of
     * objects. ::add() and ::remove() are still supported.
     * XXX: Move this to a parent class?
     */
    function setCache(array $cache) {
        if (count($this->storage) > 0)
            throw new Exception('Cache must be set before fetching records');
        // Set cache and disable fetching
        $this->reset();
        $this->storage = $cache;
    }

    // Save all changes made to any list items
    function saveAll() {
        foreach ($this as $I)
            if (!$I->save())
                return false;
        return true;
    }

    // QuerySet delegates
    function exists() {
        return $this->queryset->exists();
    }
    function expunge() {
        if ($this->queryset->delete())
            $this->reset();
    }
    function update(array $what) {
        return $this->queryset->update($what);
    }

    // Fetch a new QuerySet
    function objects() {
        return clone $this->queryset;
    }

    function offsetUnset($a): void {
        $this->fillTo($a);
        $this->storage[$a]->delete();
    }
    function offsetSet($a, $b): void {
        $this->fillTo($a);
        if ($obj = $this->storage[$a])
            $obj->delete();
        $this->add($b, true, $a);
    }

    // QuerySet overriedes
    function __call($what, $how) {
        return call_user_func_array(array($this->objects(), $what), $how);
    }
}
