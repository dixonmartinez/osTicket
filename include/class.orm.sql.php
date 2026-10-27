<?php
/*********************************************************************
    class.orm.sql.php

    Expresiones SQL y compiladores: lo que convierte una consulta del ORM en
    SQL, y lo que lo ejecuta contra MySQL.

    Parte de include/class.orm.php (frente 6 de docs/CODE_QUALITY.md): el
    archivo tenia 3.643 lineas y 34 clases. Se movieron las clases enteras,
    sin tocar su codigo; el original incluye este al principio, antes de
    las clases del modelo.

    Las expresiones (SqlFunction, SqlExpression, SqlField...) tienen que estar
    declaradas antes que ModelMeta y VerySimpleModel, que las usan en sus
    valores por omision; por eso van aqui y no en un archivo incluido al final.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
class SqlFunction {
    var $alias;
    var $func;
    var $args = array();

    function __construct($name) {
        $this->func = $name;
        $this->args = array_slice(func_get_args(), 1);
    }

    function input($what, $compiler, $model) {
        if ($what instanceof SqlFunction)
            $A = $what->toSql($compiler, $model);
        elseif ($what instanceof Q)
            $A = $compiler->compileQ($what, $model);
        else
            $A = $compiler->input($what);
        return $A;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $args = array();
        foreach ($this->args as $A) {
            $args[] = $this->input($A, $compiler, $model);
        }
        return sprintf('%s(%s)%s', $this->func, implode(', ', $args),
            $alias && $this->alias ? ' AS '.$compiler->quote($this->alias) : '');
    }

    function getAlias() {
        return $this->alias;
    }
    function setAlias($alias) {
        $this->alias = $alias;
    }

    static function __callStatic($func, $args) {
        $I = new static($func);
        $I->args = $args;
        return $I;
    }

    function __call($operator, $other) {
        array_unshift($other, $this);
        return SqlExpression::__callStatic($operator, $other);
    }
}

class SqlCase extends SqlFunction {
    var $cases = array();
    var $else = false;

    static function N() {
        return new static('CASE');
    }

    function when($expr, $result) {
        if (is_array($expr))
            $expr = new Q($expr);
        $this->cases[] = array($expr, $result);
        return $this;
    }
    function otherwise($result) {
        $this->else = $result;
        return $this;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $cases = array();
        foreach ($this->cases as $A) {
            list($expr, $result) = $A;
            $expr = $this->input($expr, $compiler, $model);
            $result = $this->input($result, $compiler, $model);
            $cases[] = "WHEN {$expr} THEN {$result}";
        }
        if ($this->else) {
            $else = $this->input($this->else, $compiler, $model);
            $cases[] = "ELSE {$else}";
        }
        return sprintf('CASE %s END%s', implode(' ', $cases),
            $alias && $this->alias ? ' AS '.$compiler->quote($this->alias) : '');
    }
}

class SqlExpr extends SqlFunction {
    function __construct($args) {
        $this->args = func_get_args();
        if (count($this->args) == 1 && is_array($this->args[0]))
            $this->args = $this->args[0];
    }

    function toSql($compiler, $model=false, $alias=false) {
        $O = array();
        foreach ($this->args as $field=>$value) {
            if ($value instanceof Q) {
                $ex = $compiler->compileQ($value, $model, false);
                $O[] = $ex->text;
            }
            else {
                list($field, $op) = $compiler->getField($field, $model);
                if (is_callable($op))
                    $O[] = call_user_func($op, $field, $value, $model);
                else
                    $O[] = sprintf($op, $field, $compiler->input($value));
            }
        }
        return implode(' ', $O) . ($alias ? ' AS ' .  $compiler->quote($alias) : '');
    }
}

class SqlExpression extends SqlFunction {
    var $operator;
    var $operands;

    function toSql($compiler, $model=false, $alias=false) {
        $O = array();
        foreach ($this->args as $operand) {
            $O[] = $this->input($operand, $compiler, $model);
        }
        return '('.implode(' '.$this->func.' ', $O)
            . ($alias ? ' AS '.$compiler->quote($alias) : '')
            . ')';
    }

    static function __callStatic($operator, $operands) {
        switch ($operator) {
            case 'minus':
                $operator = '-'; break;
            case 'plus':
                $operator = '+'; break;
            case 'times':
                $operator = '*'; break;
            case 'bitand':
                $operator = '&'; break;
            case 'bitor':
                $operator = '|'; break;
            default:
                throw new InvalidArgumentException($operator.': Invalid operator specified');
        }
        return parent::__callStatic($operator, $operands);
    }

    function __call($operator, $operands) {
        array_unshift($operands, $this);
        return SqlExpression::__callStatic($operator, $operands);
    }
}

class SqlInterval extends SqlFunction {
    var $type;

    function toSql($compiler, $model=false, $alias=false) {
        $A = $this->args[0];
        if ($A instanceof SqlFunction)
            $A = $A->toSql($compiler, $model);
        else
            $A = $compiler->input($A);
        return sprintf('INTERVAL %s %s',
            $A,
            $this->func)
            . ($alias ? ' AS '.$compiler->quote($alias) : '');
    }

    static function __callStatic($interval, $args) {
        if (count($args) != 1) {
            throw new InvalidArgumentException("Interval expects a single interval value");
        }
        return parent::__callStatic($interval, $args);
    }
}

class SqlField extends SqlExpression {
    var $field;
    var $level;

    function __construct($field, $level=0) {
        $this->field = $field;
        $this->level = $level;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $L = $this->level;
        while ($L--)
            $compiler = $compiler->getParent();
        list($field) = $compiler->getField($this->field, $model);
        return $field;
    }
}

class SqlCode extends SqlFunction {
    var $code;

    function __construct($code) {
        $this->code = $code;
    }

    function toSql($compiler, $model=false, $alias=false) {
        return $this->code.($alias ? ' AS '.$alias : '');
    }
}

class SqlAggregate extends SqlFunction {

    var $func;
    var $expr;
    var $distinct=false;
    var $constraint=false;

    function __construct($func, $expr, $distinct=false, $constraint=false) {
        $this->func = $func;
        $this->expr = $expr;
        $this->distinct = $distinct;
        if ($constraint instanceof Q)
            $this->constraint = $constraint;
        elseif ($constraint)
            $this->constraint = new Q($constraint);
    }

    static function __callStatic($func, $args) {
        $distinct = @$args[1] ?: false;
        $constraint = @$args[2] ?: false;
        return new static($func, $args[0], $distinct, $constraint);
    }

    function toSql($compiler, $model=false, $alias=false) {
        $options = array('constraint' => $this->constraint, 'model' => true);

        // For DISTINCT, require a field specification — not a relationship
        // specification.
        $E = $this->expr;
        if ($E instanceof SqlFunction) {
            $field = $E->toSql($compiler, $model);
        }
        else {
        list($field, $rmodel) = $compiler->getField($E, $model, $options);
        if ($this->distinct) {
            $pk = false;
            $fpk  = $rmodel::getMeta('pk');
            foreach ($fpk as $f) {
                $pk |= false !== strpos($field, $f);
            }
            if (!$pk) {
                // Try and use the foriegn primary key
                if (count($fpk) == 1) {
                    list($field) = $compiler->getField(
                        $this->expr . '__' . $fpk[0],
                        $model, $options);
                }
                else {
                    throw new OrmException(
                        sprintf('%s :: %s', $rmodel, $field) .
                        ': DISTINCT aggregate expressions require specification of a single primary key field of the remote model'
                    );
                }
            }
        }
        }

        return sprintf('%s(%s%s)%s', $this->func,
            $this->distinct ? 'DISTINCT ' : '', $field,
            $alias && $this->alias ? ' AS '.$compiler->quote($this->alias) : '');
    }

    function getFieldName() {
        return strtolower(sprintf('%s__%s', $this->args[0], $this->func));
    }
}

class SqlCompiler {
    var $options = array();
    var $annotations = array();
    var $params = array();
    var $joins = array();
    var $aliases = array();
    var $alias_num = 1;

    static $operators = array(
        'exact' => '%$1s = %$2s'
    );

    function __construct($options=false) {
        if (is_array($options)) {
            $this->options = array_merge($this->options, $options);
            if (isset($options['subquery']))
                $this->alias_num += 150;
        }
    }

    function getParent() {
        return $this->options['parent'];
    }

    /**
     * Split a criteria item into the identifying pieces: path, field, and
     * operator.
     */
    static function splitCriteria($criteria) {
        static $operators = array(
            'exact' => 1, 'isnull' => 1,
            'gt' => 1, 'lt' => 1, 'gte' => 1, 'lte' => 1, 'range' => 1,
            'contains' => 1, 'like' => 1, 'startswith' => 1, 'endswith' => 1, 'regex' => 1,
            'in' => 1, 'intersect' => 1,
            'hasbit' => 1,
        );
        $path = explode('__', $criteria);
        if (!isset($options['table'])) {
            $field = array_pop($path);
            if (isset($operators[$field])) {
                $operator = $field;
                $field = array_pop($path);
            }
        }
        return array($field, $path, $operator ?? 'exact');
    }

    /**
     * Check if the values match given the operator.
     *
     * Parameters:
     * $record - <ModelBase> An model instance representing a row from the
     *      database
     * $field - Field path including operator used as the evaluated
     *      expression base. To check if field `name` startswith something,
     *      $field would be `name__startswith`.
     * $check - <mixed> value used as the comparison. This would be the RHS
     *      of the condition expressed with $field. This can also be a Q
     *      instance, in which case, $field is not considered, and the Q
     *      will be used to evaluate the $record directly.
     *
     * Throws:
     * OrmException - if $operator is not supported
     */
    static function evaluate($record, $check, $field) {
        static $ops; if (!isset($ops)) { $ops = array(
            'exact' => function($a, $b) { return is_string($a) ? strcasecmp($a, $b) == 0 : $a == $b; },
            'isnull' => function($a, $b) { return is_null($a) == $b; },
            'gt' => function($a, $b) { return $a > $b; },
            'gte' => function($a, $b) { return $a >= $b; },
            'lt' => function($a, $b) { return $a < $b; },
            'lte' => function($a, $b) { return $a <= $b; },
            'in' => function($a, $b) { return in_array($a, is_array($b) ? $b : array($b)); },
            'contains' => function($a, $b) { return stripos($a, $b) !== false; },
            'startswith' => function($a, $b) { return stripos($a, $b) === 0; },
            'endswith' => function($a, $b) { return iEndsWith($a, $b); },
            'regex' => function($a, $b) { return preg_match("/$a/iu", $b); },
            'hasbit' => function($a, $b) { return ($a & $b) == $b; },
        ); }
        // TODO: Support Q expressions
        if ($check instanceof Q)
            return $check->evaluate($record);

        list($field, $path, $operator) = self::splitCriteria($field);
        if (!isset($ops[$operator]))
            throw new OrmException($operator.': Unsupported operator');

        if ($path)
            $record = $record->getByPath($path);

        return $ops[$operator]($record->get($field), $check);
    }

    /**
     * Handles breaking down a field or model search descriptor into the
     * model search path, field, and operator parts. When used in a queryset
     * filter, an expression such as
     *
     * user__email__hostname__contains => 'foobar'
     *
     * would be broken down to search from the root model (passed in,
     * perhaps a ticket) to the user and email models by inspecting the
     * model metadata 'joins' property. The 'constraint' value found there
     * will be used to build the JOIN sql clauses.
     *
     * The 'hostname' will be the field on 'email' model that should be
     * compared in the WHERE clause. The comparison should be made using a
     * 'contains' function, which in MySQL, might be implemented using
     * something like "<lhs> LIKE '%foobar%'"
     *
     * This function will rely heavily on the pushJoin() function which will
     * handle keeping track of joins made previously in the query and
     * therefore prevent multiple joins to the same table for the same
     * reason. (Self joins are still supported).
     *
     * Comparison functions supported by this function are defined for each
     * respective SqlCompiler subclass; however at least these functions
     * should be defined:
     *
     *      function    a__function => b
     *      ----------+------------------------------------------------
     *      exact     | a is exactly equal to b
     *      gt        | a is greater than b
     *      lte       | b is greater than a
     *      lt        | a is less than b
     *      gte       | b is less than a
     *      ----------+------------------------------------------------
     *      contains  | (string) b is contained within a
     *      statswith | (string) first len(b) chars of a are exactly b
     *      endswith  | (string) last len(b) chars of a are exactly b
     *      like      | (string) a matches pattern b
     *      ----------+------------------------------------------------
     *      in        | a is in the list or the nested queryset b
     *      ----------+------------------------------------------------
     *      isnull    | a is null (if b) else a is not null
     *
     * If no comparison function is declared in the field descriptor,
     * 'exact' is assumed.
     *
     * Parameters:
     * $field - (string) name of the field to join
     * $model - (VerySimpleModel) root model for references in the $field
     *      parameter
     * $options - (array) extra options for the compiler
     *      'table' => return the table alias rather than the field-name
     *      'model' => return the target model class rather than the operator
     *      'constraint' => extra constraint for join clause
     *
     * Returns:
     * (mixed) Usually array<field-name, operator> where field-name is the
     * name of the field in the destination model, and operator is the
     * requestion comparison method.
     */
    function getField($field, $model, $options=array()) {
        // Break apart the field descriptor by __ (double-underbars). The
        // first part is assumed to be the root field in the given model.
        // The parts after each of the __ pieces are links to other tables.
        // The last item (after the last __) is allowed to be an operator
        // specifiction.
        list($field, $parts, $op) = static::splitCriteria($field);
        $operator = static::$operators[$op];
        // PHP 8.2 deprecó el uso de 'self' en callables: los operadores son
        // métodos de esta instancia, así que se resuelven a [$this, método]
        if (is_array($operator) && $operator[0] === 'self')
            $operator = array($this, $operator[1]);
        $path = '';
        $rootModel = $model;

        // Call pushJoin for each segment in the join path. A new JOIN
        // fragment will need to be emitted and/or cached
        $joins = array();
        $null = false;
        $push = function($p, $model) use (&$joins, &$path, &$null) {
            $J = $model::getMeta('joins');
            if (!($info = $J[$p])) {
                throw new OrmException(sprintf(
                   'Model `%s` does not have a relation called `%s`',
                    $model, $p));
            }
            // Propogate LEFT joins through other joins. That is, if a
            // multi-join expression is used, the first LEFT join should
            // result in further joins also being LEFT
            if (isset($info['null']))
                $null = $null || $info['null'];
            $info['null'] = $null;
            $crumb = $path;
            $path = ($path) ? "{$path}__{$p}" : $p;
            $joins[] = array($crumb, $path, $model, $info);
            // Roll to foreign model
            return $info['fkey'];
        };

        foreach ($parts as $p) {
            list($model) = $push($p, $model);
        }

        // If comparing a relationship, join the foreign table
        // This is a comparison with a relationship — use the foreign key
        $J = $model::getMeta('joins');
        if (isset($J[$field])) {
            list($model, $field) = $push($field, $model);
        }

        // Apply the joins list to $this->pushJoin
        $last = count($joins) - 1;
        $constraint = false;
        foreach ($joins as $i=>$A) {
            // Add the conststraint as the last arg to the last join
            if ($i == $last)
                $constraint = $options['constraint'] ?? null;
            $alias = $this->pushJoin($A[0], $A[1], $A[2], $A[3], $constraint);
        }

        if (!isset($alias)) {
            // Determine the alias for the root model table
            $alias = (isset($this->joins['']))
                ? $this->joins['']['alias']
                : $this->quote($rootModel::getMeta('table'));
        }

        if (isset($options['table']) && $options['table'])
            $field = $alias;
        elseif (isset($this->annotations[$field]))
            $field = $this->annotations[$field];
        elseif ($alias)
            $field = $alias.'.'.$this->quote($field);
        else
            $field = $this->quote($field);

        if (isset($options['model']) && $options['model'])
            $operator = $model;
        return array($field, $operator);
    }

    /**
     * Uses the compiler-specific `compileJoin` function to compile the join
     * statement fragment, and caches the result in the local $joins list. A
     * new alias is acquired using the `nextAlias` function which will be
     * associated with the join. If the same path is requested again, the
     * algorithm is short-circuited and the originally-assigned table alias
     * is returned immediately.
     */
    function pushJoin($tip, $path, $model, $info, $constraint=false) {
        // TODO: Build the join statement fragment and return the table
        // alias. The table alias will be useful where the join is used in
        // the WHERE and ORDER BY clauses

        // If the join already exists for the statement-being-compiled, just
        // return the alias being used.
        if (!$constraint && isset($this->joins[$path]))
            return $this->joins[$path]['alias'];

        // TODO: Support only using aliases if necessary. Use actual table
        // names for everything except oddities like self-joins

        $alias = $this->nextAlias();
        // Keep an association between the table alias and the model. This
        // will make model construction much easier when we have the data
        // and the table alias from the database.
        $this->aliases[$alias] = $model;

        // TODO: Stash joins and join constraints into local ->joins array.
        // This will be useful metadata in the executor to construct the
        // final models for fetching
        // TODO: Always use a table alias. This will further help with
        // coordination between the data returned from the database (where
        // table alias is available) and the corresponding data.
        $T = array('alias' => $alias);
        $this->joins[$path] = $T;
        $this->joins[$path]['sql'] = $this->compileJoin($tip, $model, $alias, $info, $constraint);
        return $alias;
    }

    /**
     * compileQ
     *
     * Build a constraint represented in an arbitrarily nested Q instance.
     * The placement of the compiled constraint is also considered and
     * represented in the resulting CompiledExpression instance.
     *
     * Parameters:
     * $Q - (Q) constraint represented in a Q instance
     * $model - (VerySimpleModel) root model for all the field references in
     *      the Q instance
     *
     * Returns:
     * (CompiledExpression) object containing the compiled expression (with
     * AND, OR, and NOT operators added). Furthermore, the $type attribute
     * of the CompiledExpression will allow the compiler to place the
     * constraint properly in the WHERE or HAVING clause appropriately.
     */
    function compileQ(Q $Q, $model, $parens=true) {
        $filter = array();
        $type = CompiledExpression::TYPE_WHERE;
        foreach ($Q->constraints as $field=>$value) {
            $fieldName = $field;
            // Handle nested constraints
            if ($value instanceof Q) {
                $filter[] = $T = $this->compileQ($value, $model,
                    !$Q->isCompatibleWith($value));
                // Bubble up HAVING constraints
                if ($T instanceof CompiledExpression
                        && $T->type == CompiledExpression::TYPE_HAVING)
                    $type = $T->type;
            }
            // Handle relationship comparisons with model objects
            elseif ($value instanceof VerySimpleModel) {
                $criteria = array();
                // Avoid a join if possible. Use the local side of the
                // relationship
                if (count($value->pk) === 1) {
                    $path = explode('__', $field);
                    $relationship = array_pop($path);
                    $lmodel = $model::getMeta()->getByPath($path);
                    $local = $lmodel['joins'][$relationship]['local'];
                    $path = $path ? (implode('__', $path) . '__') : '';
                    foreach ($value->pk as $v) {
                        $criteria["{$path}{$local}"] = $v;
                   }
                }
                else {
                    foreach ($value->pk as $f=>$v) {
                        $criteria["{$field}__{$f}"] = $v;
                    }
                }
                // New criteria here is joined with AND, so if the outer
                // criteria is joined with OR, then parentheses are
                // necessary
                $filter[] = $this->compileQ(new Q($criteria), $model, $Q->ored);
            }
            // Handle simple field = <value> constraints
            else {
                list($field, $op) = $this->getField($field, $model);
                if ($field instanceof SqlAggregate) {
                    // This constraint has to go in the HAVING clause
                    $field = $field->toSql($this, $model);
                    $type = CompiledExpression::TYPE_HAVING;
                } elseif ($field instanceof QuerySet) {
                    // Constraint on a subquery goes to HAVING clause
                    list($field) = static::splitCriteria($fieldName);
                    $type = CompiledExpression::TYPE_HAVING;
                }

                if ($value === null)
                    $filter[] = sprintf('%s IS NULL', $field);
                elseif ($value instanceof SqlField)
                    $filter[] = sprintf($op, $field, $value->toSql($this, $model));
                // Allow operators to be callable rather than sprintf
                // strings
                elseif (is_callable($op))
                    $filter[] = call_user_func($op, $field, $value, $model);
                else
                    $filter[] = sprintf($op, $field, $this->input($value));
            }
        }
        $glue = $Q->ored ? ' OR ' : ' AND ';
        $filter = array_filter($filter);
        $clause = implode($glue, $filter);
        if (($Q->negated || $parens) && count($filter) > 1)
            $clause = '(' . $clause . ')';
        if ($Q->negated)
            $clause = 'NOT '.$clause;
        return new CompiledExpression($clause, $type);
    }

    function compileConstraints($where, $model) {
        $constraints = array();
        foreach ($where as $Q) {
            // Constraints are joined by AND operators, so if they have
            // internal OR operators, then they need to be parenthesized
            $constraints[] = $this->compileQ($Q, $model, $Q->ored);
        }
        return $constraints;
    }

    function getParams() {
        return $this->params;
    }

    function getJoins($queryset) {
        $sql = '';
        // Move CDATA to top of joins
        if (array_key_exists("cdata", $this->joins)) {
            $temp = ['cdata' => $this->joins['cdata']];
            unset($this->joins['cdata']);
            $this->joins = $temp + $this->joins;
        }
        foreach ($this->joins as $path => $j) {
            if (!isset($j['sql']))
                continue;
            list($base, $constraints) = $j['sql'];
            // Add in path-specific constraints, if any
            if (isset($queryset->path_constraints[$path])) {
                foreach ($queryset->path_constraints[$path] as $Q) {
                    $constraints[] = $this->compileQ($Q, $queryset->model);
                }
            }
            $sql .= $base;
            if ($constraints)
                $sql .= ' ON ('.implode(' AND ', $constraints).')';
        }
        // Add extra items from QuerySet
        if (isset($queryset->extra['tables'])) {
            foreach ($queryset->extra['tables'] as $S) {
                $join = ' JOIN ';
                // Left joins require an ON () clause
                // TODO: Have a way to indicate a LEFT JOIN
                $sql .= $join.$S;
            }
        }

        // Add extra joins from QuerySet
        if (isset($queryset->extra['joins'])) {
            foreach ($queryset->extra['joins'] as $J) {
                list($base, $constraints, $alias) = $J;
                $join = $constraints ? ' LEFT JOIN ' : ' JOIN ';
                $sql .= "{$join}{$base} $alias";
                if ($constraints instanceof Q)
                    $sql .= ' ON ('.$this->compileQ($constraints, $queryset->model).')';
            }
        }

        return $sql;
    }

    function nextAlias() {
        // Use alias A1-A9,B1-B9,...
        $alias = chr(65 + (int)($this->alias_num / 9)) . $this->alias_num % 9;
        $this->alias_num++;
        return $alias;
    }
}

class CompiledExpression /* extends SplString */ {
    const TYPE_WHERE =   0x0001;
    const TYPE_HAVING =  0x0002;

    var $text = '';
    var $type = self::TYPE_WHERE;

    function __construct($clause, $type=self::TYPE_WHERE) {
        $this->text = $clause;
        $this->type = $type;
    }

    function __toString() {
        return $this->text;
    }
}

class DbEngine {

    static $compiler = 'MySqlCompiler';

    function __construct($info) {
    }

    function connect() {
    }

    // Gets a compiler compatible with this database engine that can compile
    // and execute a queryset or DML request.
    static function getCompiler() {
        $class = static::$compiler;
        return new $class();
    }

    static function delete(VerySimpleModel $model) {
        ModelInstanceManager::uncache($model);
        return static::getCompiler()->compileDelete($model);
    }

    static function save(VerySimpleModel $model) {
        $compiler = static::getCompiler();
        if ($model->__new__)
            return $compiler->compileInsert($model);
        else
            return $compiler->compileUpdate($model);
    }
}

class MySqlCompiler extends SqlCompiler {

    static $operators = array(
        'exact' => '%1$s = %2$s',
        'contains' => array('self', '__contains'),
        'startswith' => array('self', '__startswith'),
        'endswith' => array('self', '__endswith'),
        'gt' => '%1$s > %2$s',
        'lt' => '%1$s < %2$s',
        'gte' => '%1$s >= %2$s',
        'lte' => '%1$s <= %2$s',
        'range' => array('self', '__range'),
        'isnull' => array('self', '__isnull'),
        'like' => '%1$s LIKE %2$s',
        'hasbit' => '%1$s & %2$s != 0',
        'in' => array('self', '__in'),
        'intersect' => array('self', '__find_in_set'),
        'regex' => array('self', '__regex'),
    );

    // Thanks, http://stackoverflow.com/a/3683868
    function like_escape($what, $e='\\') {
        return str_replace(array($e, '%', '_'), array($e.$e, $e.'%', $e.'_'), $what);
    }

    function __contains($a, $b) {
        # {%a} like %{$b}%
        # Escape $b
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("%$b%"));
    }
    function __startswith($a, $b) {
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("$b%"));
    }
    function __endswith($a, $b) {
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("%$b"));
    }

    function __in($a, $b) {
        if (is_array($b)) {
            $vals = array_map(array($this, 'input'), $b);
            $b = '('.implode(', ', $vals).')';
        }
        // MySQL is almost always faster with a join. Use one if possible
        // MySQL doesn't support LIMIT or OFFSET in subqueries. Instead, add
        // the query as a JOIN and add the join constraint into the WHERE
        // clause.
        elseif ($b instanceof QuerySet
            && ($b->isWindowed() || $b->countSelectFields() > 1 || $b->chain)
        ) {
            $f1 = $b->values[0];
            $view = $b->asView();
            $alias = $this->pushJoin($view, $a, $view, array('constraint'=>array()));
            return sprintf('%s = %s.%s', $a, $alias, $this->quote($f1));
        }
        else {
            $b = $this->input($b);
        }
        return sprintf('%s IN %s', $a, $b);
    }

    function __isnull($a, $b) {
        return $b
            ? sprintf('%s IS NULL', $a)
            : sprintf('%s IS NOT NULL', $a);
    }

    function __find_in_set($a, $b) {
        if (is_array($b)) {
            $sql = array();
            foreach (array_map(array($this, 'input'), $b) as $b) {
                $sql[] = sprintf('FIND_IN_SET(%s, %s)', $b, $a);
            }
            $parens = count($sql) > 1;
            $sql = implode(' OR ', $sql);
            return $parens ? ('('.$sql.')') : $sql;
        }
        return sprintf('FIND_IN_SET(%s, %s)', $b, $a);
    }

    function __regex($a, $b) {
        // Strip slashes and options
        if ($b[0] == '/')
            $b = preg_replace('`/[^/]*$`', '', substr($b, 1));
        return sprintf('%s REGEXP %s', $a, $this->input($b));
    }

    function __range($a, $b) {
      return sprintf('%s BETWEEN %s AND %s',
        $a,
        isset($b[2]) ? $b[0] : $this->input($b[0]),
        isset($b[2]) ? $b[1] : $this->input($b[1]));
    }

    function compileJoin($tip, $model, $alias, $info, $extra=false) {
        $constraints = array();
        $join = ' JOIN ';
        if (isset($info['null']) && $info['null'])
            $join = ' LEFT'.$join;
        if (isset($this->joins[$tip]))
            $table = $this->joins[$tip]['alias'];
        else
            $table = $this->quote($model::getMeta('table'));
        foreach ($info['constraint'] as $local => $foreign) {
            list($rmodel, $right) = $foreign;
            // Support a constant constraint with
            // "'constant'" => "Model.field_name"
            if ($local[0] == "'") {
                $constraints[] = sprintf("%s.%s = %s",
                    $alias, $this->quote($right),
                    $this->input(trim($local, '\'"'))
                );
            }
            // Support local constraint
            // field_name => "'constant'"
            elseif ($rmodel[0] == "'" && !$right) {
                $constraints[] = sprintf("%s.%s = %s",
                    $table, $this->quote($local),
                    $this->input(trim($rmodel, '\'"'))
                );
            }
            else {
                $constraints[] = sprintf("%s.%s = %s.%s",
                    $table, $this->quote($local), $alias,
                    $this->quote($right)
                );
            }
        }
        // Support extra join constraints
        if ($extra instanceof Q) {
            $constraints[] = $this->compileQ($extra, $model);
        }
        if (!isset($rmodel))
            $rmodel = $model;
        // Support inline views
        $rmeta = $rmodel::getMeta();
        $table = ($rmeta['view'])
            // XXX: Support parameters from the nested query
            ? $rmodel::getSqlAddParams($this)
            : $this->quote($rmeta['table'], true);
        $base = "{$join}{$table} {$alias}";
        return array($base, $constraints);
    }

    /**
     * input
     *
     * Generate a parameterized input for a database query.
     *
     * Parameters:
     * $what - (mixed) value to be sent to the database. No escaping is
     *      necessary. Pass a raw value here.
     *
     * Returns:
     * (string) token to be placed into the compiled SQL statement. This
     * is a colon followed by a number
     */
    function input($what, $model=false) {
        if ($what instanceof QuerySet) {
            $q = $what->getQuery(array('nosort'=>!($what->limit || $what->offset)));
            // Rewrite the parameter numbers so they fit the parameter numbers
            // of the current parameters of the $compiler
            $self = $this;
            $sql = preg_replace_callback("/:(\d+)/",
            function($m) use ($self, $q) {
                $self->params[] = $q->params[$m[1]-1];
                return ':'.count($self->params);
            }, $q->sql);
            return "({$sql})";
        }
        elseif ($what instanceof SqlFunction) {
            return $what->toSql($this, $model);
        }
        elseif (!isset($what)) {
            return 'NULL';
        }
        else {
            $this->params[] = $what;
            return ':'.(count($this->params));
        }
    }

    function quote($what) {
        return sprintf("`%s`", str_replace("`", "``", $what));
    }

    static function supportsOption($option) {
        return true;
    }

    /**
     * getWhereClause
     *
     * This builds the WHERE ... part of a DML statement. This should be
     * called before ::getJoins(), because it may add joins into the
     * statement based on the relationships used in the where clause
     */
    protected function getWhereHavingClause($queryset) {
        $model = $queryset->model;
        $constraints = $this->compileConstraints($queryset->constraints, $model);
        $where = $having = array();
        foreach ($constraints as $C) {
            if ($C->type == CompiledExpression::TYPE_WHERE)
                $where[] = $C;
            else
                $having[] = $C;
        }
        if (isset($queryset->extra['where'])) {
            foreach ($queryset->extra['where'] as $S) {
                $where[] = "($S)";
            }
        }
        // Un Q vacío compila a cadena vacía: se descarta antes de unir, para
        // no emitir un `WHERE` sin condición
        $where = array_filter($where, function ($C) {
            return strlen((string) $C) > 0;
        });
        if ($where)
            $where = ' WHERE '.implode(' AND ', $where);
        // Igual con HAVING, aunque un agregado vacío no tenga sentido
        $having = array_filter($having, function ($C) {
            return strlen((string) $C) > 0;
        });
        if ($having)
            $having = ' HAVING '.implode(' AND ', $having);
        return array($where ?: '', $having ?: '');
    }

    function compileCount($queryset) {
        $q = clone $queryset;
        // Drop extra fields from the queryset
        // Sin anotaciones ni relaciones: sólo la clave, para contar filas
        $q->related = $q->annotations = false;
        $model = $q->model;
        $q->values = $model::getMeta('pk');
        $exec = $q->getQuery(array('nosort' => true));
        $exec->sql = 'SELECT COUNT(*) FROM ('.$exec->sql.') __';
        try {
            $row = $exec->getRow();
        } catch (mysqli_sql_exception $e) {
            throw new InconsistentModelException(
                'Unable to prepare query: '.db_error().' '.$exec->sql);
        }
        return is_array($row) ? (int) $row[0] : null;
    }

    function getFoundRows() {
        $exec = new MysqlExecutor('SELECT FOUND_ROWS()', array());
        $row = $exec->getRow();
        return is_array($row) ? (int) $row[0] : null;
    }

    function compileSelect($queryset) {
        $model = $queryset->model;
        // Use an alias for the root model table
        $this->joins[''] = array('alias' => ($rootAlias = $this->nextAlias()));

        // Compile the WHERE clause
        $this->annotations = $queryset->annotations ?: array();
        list($where, $having) = $this->getWhereHavingClause($queryset);

        // Compile the ORDER BY clause
        $sort = '';
        if ($columns = $queryset->getSortFields()) {
            $orders = array();
            foreach ($columns as $sort) {
                $dir = 'ASC';
                if (is_array($sort)) {
                    list($sort, $dir) = $sort;
                }
                if ($sort instanceof SqlFunction) {
                    $field = $sort->toSql($this, $model);
                }
                else {
                    if ($sort[0] === '-') {
                        $dir = 'DESC';
                        $sort = substr($sort, 1);
                    }
                    // If the field is already an annotation, then don't
                    // compile the annotation again below. It's included in
                    // the select clause, which is sufficient
                    if (isset($this->annotations[$sort]))
                        $field = $this->quote($sort);
                    else
                        list($field) = $this->getField($sort, $model);
                }
                if ($field instanceof SqlFunction)
                    $field = $field->toSql($this, $model);
                // TODO: Throw exception if $field can be indentified as
                //       invalid

                $orders[] = "{$field} {$dir}";
            }
            $sort = ' ORDER BY '.implode(', ', $orders);
        }

        // Tabla raíz, con su alias
        $meta = $model::getMeta();
        $table = $this->quote($meta['table']).' '.$rootAlias;

        // Lista de campos y agrupación: ver SelectSql
        list($fields, $group_by, $fieldMap) = SelectSql::fields($queryset,
            $this, $model, $rootAlias);

        $joins = $this->getJoins($queryset);
        if ($hint = $queryset->getOption(QuerySet::OPT_INDEX_HINT)) {
            $hint = " USE INDEX ({$hint})";
        }

        $sql = 'SELECT ';
        if ($queryset->hasOption(QuerySet::OPT_MYSQL_FOUND_ROWS))
            $sql .= 'SQL_CALC_FOUND_ROWS ';
        $sql .= $fields.' FROM '
            .$table.$hint.$joins.$where.$group_by.$having.$sort;
        // UNIONS
        if ($queryset->chain) {
            // If the main query is sorted, it will need parentheses
            if ($parens = (bool) $sort)
                $sql = "($sql)";
            foreach ($queryset->chain as $qs) {
                list($qs, $all) = $qs;
                $q = $qs->getQuery(array('nosort' => true));
                // Rewrite the parameter numbers so they fit the parameter numbers
                // of the current parameters of the $compiler
                $self = $this;
                $S = preg_replace_callback("/:(\d+)/",
                function($m) use ($self, $q) {
                    $self->params[] = $q->params[$m[1]-1];
                    return ':'.count($self->params);
                }, $q->sql);
                // Wrap unions in parentheses if they are windowed or sorted
                if ($parens || $qs->isWindowed() || count($qs->getSortFields()))
                    $S = "($S)";
                $sql .= ' UNION '.($all ? 'ALL ' : '').$S;
            }
        }

        if ($queryset->limit)
            $sql .= ' LIMIT '.$queryset->limit;
        if ($queryset->offset)
            $sql .= ' OFFSET '.$queryset->offset;
        switch ($queryset->lock) {
        case QuerySet::LOCK_EXCLUSIVE:
            $sql .= ' FOR UPDATE';
            break;
        case QuerySet::LOCK_SHARED:
            $sql .= ' LOCK IN SHARE MODE';
            break;
        }

        return new MysqlExecutor($sql, $this->params, $fieldMap);
    }

    function __compileUpdateSet($model, array $pk) {
        $fields = array();
        foreach ($model->dirty as $field=>$old) {
            if ($model->__new__ or !in_array($field, $pk)) {
                $fields[] = sprintf('%s = %s', $this->quote($field),
                    $this->input($model->get($field)));
            }
        }
        return ' SET '.implode(', ', $fields);
    }

    function compileUpdate(VerySimpleModel $model) {
        $pk = $model::getMeta('pk');
        $sql = 'UPDATE '.$this->quote($model::getMeta('table'));
        $sql .= $this->__compileUpdateSet($model, $pk);
        // Support PK updates
        $criteria = array();
        foreach ($pk as $f) {
            $criteria[$f] = @$model->dirty[$f] ?: $model->get($f);
        }
        $sql .= ' WHERE '.$this->compileQ(new Q($criteria), $model);
        $sql .= ' LIMIT 1';

        return new MySqlExecutor($sql, $this->params);
    }

    function compileInsert(VerySimpleModel $model) {
        $pk = $model::getMeta('pk');
        $sql = 'INSERT INTO '.$this->quote($model::getMeta('table'));
        $sql .= $this->__compileUpdateSet($model, $pk);

        return new MySqlExecutor($sql, $this->params);
    }

    function compileDelete($model) {
        $table = $model::getMeta('table');

        $where = ' WHERE '.implode(' AND ',
            $this->compileConstraints(array(new Q($model->pk)), $model));
        $sql = 'DELETE FROM '.$this->quote($table).$where.' LIMIT 1';
        return new MySqlExecutor($sql, $this->params);
    }

    function compileBulkDelete($queryset) {
        $model = $queryset->model;
        $table = $model::getMeta('table');
        list($where, $having) = $this->getWhereHavingClause($queryset);
        $joins = $this->getJoins($queryset);
        $sql = 'DELETE '.$this->quote($table).'.* FROM '
            .$this->quote($table).$joins.$where;
        return new MysqlExecutor($sql, $this->params);
    }

    function compileBulkUpdate($queryset, array $what) {
        $model = $queryset->model;
        $table = $model::getMeta('table');
        $set = array();
        foreach ($what as $field=>$value)
            $set[] = sprintf('%s = %s', $this->quote($field), $this->input($value, $model));
        $set = implode(', ', $set);
        list($where, $having) = $this->getWhereHavingClause($queryset);
        $joins = $this->getJoins($queryset);
        $sql = 'UPDATE '.$this->quote($table).$joins.' SET '.$set.$where;
        return new MysqlExecutor($sql, $this->params);
    }

    // Returns meta data about the table used to build queries
    function inspectTable($table) {
        static $cache = array();

        // XXX: Assuming schema is not changing — add support to track
        //      current schema
        if (isset($cache[$table]))
            return $cache[$table];

        $sql = 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            .'WHERE TABLE_NAME = '.db_input($table).' AND TABLE_SCHEMA = DATABASE() '
            .'ORDER BY ORDINAL_POSITION';
        $ex = new MysqlExecutor($sql, array());
        $columns = array();
        while (list($column) = $ex->getRow()) {
            $columns[] = $column;
        }
        return $cache[$table] = $columns;
    }
}

class MySqlPreparedExecutor {

    var $stmt;
    var $fields = array();
    var $sql;
    var $params;
    // Array of [count, model] values representing which fields in the
    // result set go with witch model.  Useful for handling select_related
    // queries
    var $map;

    var $unbuffered = false;
    /* Tipos de columna del resultado, que `_setupCast()` rellena para
       convertir los valores que devuelve MySQL. */
    var $types = array();

    function __construct($sql, $params, $map=null) {
        $this->sql = $sql;
        $this->params = $params;
        $this->map = $map;
    }

    function getMap() {
        return $this->map;
    }

    function setBuffered($buffered) {
        $this->unbuffered = !$buffered;
    }

    function fixupParams() {
        $self = $this;
        $params = array();
        $sql = preg_replace_callback("/:(\d+)/",
        function($m) use ($self, &$params) {
            $params[] = $self->params[$m[1]-1];
            return '?';
        }, $this->sql);
        return array($sql, $params);
    }

    function _prepare() {
        $this->execute();
        $this->_setup_output();
    }

    function execute() {
        list($sql, $params) = $this->fixupParams();
        if (!($this->stmt = db_prepare($sql)))
            throw new InconsistentModelException(
                'Unable to prepare query: '.db_error().' '.$sql);
        if (count($params))
            $this->_bind($params);
        if (!$this->stmt->execute() || !($this->unbuffered || $this->stmt->store_result())) {
            throw new OrmException('Unable to execute query: ' . $this->stmt->error);
        }
        return true;
    }

    function _bind($params) {
        if (count($params) != $this->stmt->param_count)
            throw new Exception(__('Parameter count does not match query'));

        $types = '';
        $ps = array();
        foreach ($params as $i=>&$p) {
            if (is_int($p) || is_bool($p))
                $types .= 'i';
            elseif (is_float($p))
                $types .= 'd';
            elseif (is_string($p))
                $types .= 's';
            elseif ($p instanceof DateTime) {
                $types .= 's';
                $p = $p->format('Y-m-d H:i:s');
            }
            elseif (is_object($p)) {
                $types .= 's';
                $p = (string) $p;
            }
            // TODO: Emit error if param is null
            $ps[] = &$p;
        }
        unset($p);
        array_unshift($ps, $types);
        call_user_func_array(array($this->stmt,'bind_param'), $ps);
    }

    function _setup_output() {
        if (!($meta = $this->stmt->result_metadata()))
            throw new OrmException('Unable to fetch statment metadata: ', $this->stmt->error);
        $this->fields = $meta->fetch_fields();
        $meta->free_result();
    }

    // Iterator interface
    function rewind() {
        if (!isset($this->stmt))
            $this->_prepare();
        $this->stmt->data_seek(0);
    }

    function next() {
        $status = $this->stmt->fetch();
        if ($status === false)
            throw new OrmException($this->stmt->error);
        elseif ($status === null) {
            $this->close();
            return false;
        }
        return true;
    }

    function getArray() {
        $output = array();
        $variables = array();

        if (!isset($this->stmt))
            $this->_prepare();

        foreach ($this->fields as $f)
            $variables[] = &$output[$f->name]; // pass by reference

        if (!call_user_func_array(array($this->stmt, 'bind_result'), $variables))
            throw new OrmException('Unable to bind result: ' . $this->stmt->error);

        if (!$this->next())
            return false;
        return $output;
    }

    function getRow() {
        $output = array();
        $variables = array();

        if (!isset($this->stmt))
            $this->_prepare();

        foreach ($this->fields as $f)
            $variables[] = &$output[]; // pass by reference

        if (!call_user_func_array(array($this->stmt, 'bind_result'), $variables))
            throw new OrmException('Unable to bind result: ' . $this->stmt->error);

        if (!$this->next())
            return false;
        return $output;
    }

    function close() {
        if (!$this->stmt)
            return;

        $this->stmt->close();
        $this->stmt = null;
    }

    function affected_rows() {
        return $this->stmt->affected_rows;
    }

    function insert_id() {
        return $this->stmt->insert_id;
    }

    function __toString() {
        $self = $this;
        return preg_replace_callback("/:(\d+)(?=([^']*'[^']*')*[^']*$)/",
        function($m) use ($self) {
            $p = $self->params[$m[1]-1];
            switch (true) {
            case is_bool($p):
                $p = (int) $p;
            case is_int($p):
            case is_float($p):
                return $p;
            case $p instanceof DateTime:
                $p = $p->format('Y-m-d H:i:s');
            default:
                return db_real_escape((string) $p, true);
           }
        }, $this->sql);
    }
}

/**
 * Simplified executor which uses the mysqli_query() function to process
 * queries. This method is faster on MySQL as it doesn't require the PREPARE
 * overhead, nor require two trips to the database per query. All parameters
 * are escaped and placed directly into the SQL statement. With this style,
 * it is possible that multiple parameters could compile a statement which
 * exceeds the MySQL max_allowed_packet setting.
 */
class MySqlExecutor
extends MySqlPreparedExecutor {
    function execute() {
        $sql = $this->__toString();
        if (!($this->stmt = db_query($sql, true, !$this->unbuffered)))
            // `db_error()` describe la última operación de la conexión, y el
            // aviso de `db_query()` (INSERT en `ost_syslog`, correo) la deja
            // vacía. Se recurre al error guardado por `db_query()` para no
            // lanzar «Unable to prepare query:  SELECT …» sin explicación.
            throw new InconsistentModelException(
                'Unable to prepare query: '
                . (db_error() ?: db_last_failure_text()) . ' '.$sql);
        // mysqli_query() return TRUE for UPDATE queries and friends
        if ($this->stmt !== true)
            $this->_setupCast();
        return true;
    }

    function _setupCast() {
        $fields = $this->stmt->fetch_fields();
        $this->types = array();
        foreach ($fields as $F) {
            $this->types[] = $F->type;
        }
    }

    function _cast($record) {
        $i=0;
        foreach ($record as &$f) {
            switch ($this->types[$i++]) {
            case MYSQLI_TYPE_DECIMAL:
            case MYSQLI_TYPE_NEWDECIMAL:
            case MYSQLI_TYPE_LONGLONG:
            case MYSQLI_TYPE_FLOAT:
            case MYSQLI_TYPE_DOUBLE:
                $f = isset($f) ? (float) $f : $f;
                break;

            case MYSQLI_TYPE_BIT:
            case MYSQLI_TYPE_TINY:
            case MYSQLI_TYPE_SHORT:
            case MYSQLI_TYPE_LONG:
            case MYSQLI_TYPE_INT24:
                $f = isset($f) ? (int) $f : $f;
                break;

            default:
                // No change (leave as string)
            }
        }
        unset($f);
        return $record;
    }

    function getArray() {
        if (!isset($this->stmt))
            $this->execute();

        if (null === ($record = $this->stmt->fetch_assoc()))
            return false;
        return $this->_cast($record);
    }

    function getRow() {
        if (!isset($this->stmt))
            $this->execute();

        if (null === ($record = $this->stmt->fetch_row()))
            return false;
        return $this->_cast($record);
    }

    function affected_rows() {
        return db_affected_rows();
    }

    function insert_id() {
        return db_insert_id();
    }
}
