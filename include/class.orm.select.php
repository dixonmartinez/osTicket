<?php
/**
 * include/class.orm.select.php
 *
 * Lista de campos y agrupación de un SELECT, sacadas de
 * `SqlCompiler::compileSelect()` (frente 2 de docs/CODE_QUALITY.md). Es el
 * tramo que decide qué columnas se piden según el tipo de consulta:
 *
 *   - con relaciones, los campos del modelo raíz y los de cada salto,
 *   - con `values()`, sólo las columnas pedidas,
 *   - sin nada de eso, `raíz.*` (o los campos no diferidos),
 *   - más las anotaciones, los `extra['select']` y el `GROUP BY`.
 *
 * No toca la base de datos: recibe la consulta, el compilador (para citar
 * nombres y resolver campos) y el alias de la tabla raíz, y devuelve texto.
 * Lo que genera está fijado en tests/QuerySqlTest.php.
 */

class SelectSql {

    /**
     * @return array  [lista de campos, GROUP BY (o ''), mapa de campos]
     */
    static function fields(QuerySet $queryset, SqlCompiler $compiler, $model,
            $rootAlias) {
        // Qué columnas se piden
        $fields = $group_by = array();
        $meta = $model::getMeta();
        // Handle related tables
        $need_group_by = false;
        if ($queryset->related) {
            $count = 0;
            $fieldMap = $theseFields = array();
            $defer = $queryset->defer ?: array();
            // Add local fields first
            foreach ($meta->getFieldNames() as $f) {
                // Handle deferreds
                if (isset($defer[$f]))
                    continue;
                $fields[$rootAlias . '.' . $compiler->quote($f)] = true;
                $theseFields[] = $f;
            }
            $fieldMap[] = array($theseFields, $model);
            // Add the JOINs to this query
            foreach ($queryset->related as $sr) {
                // XXX: Sort related by the paths so that the shortest paths
                //      are resolved first when building out the models.
                $full_path = '';
                $parts = array();
                // Track each model traversal and fetch data for each of the
                // models in the path of the related table
                foreach (explode('__', $sr) as $field) {
                    $full_path .= $field;
                    $parts[] = $field;
                    $theseFields = array();
                    list($alias, $fmodel) = $compiler->getField($full_path, $model,
                        array('table'=>true, 'model'=>true));
                    foreach ($fmodel::getMeta()->getFieldNames() as $f) {
                        // Handle deferreds
                        if (isset($defer[$sr . '__' . $f]))
                            continue;
                        elseif (isset($fields[$alias.'.'.$compiler->quote($f)]))
                            continue;
                        $fields[$alias . '.' . $compiler->quote($f)] = true;
                        $theseFields[] = $f;
                    }
                    if ($theseFields) {
                        $fieldMap[] = array($theseFields, $fmodel, $parts);
                    }
                    $full_path .= '__';
                }
            }
        }
        // Support retrieving only a list of values rather than a model
        elseif ($queryset->values) {
            $additional_group_by = array();
            foreach ($queryset->values as $alias=>$v) {
                list($f) = $compiler->getField($v, $model);
                $unaliased = $f;
                if ($f instanceof SqlFunction) {
                    $fields[$f->toSql($compiler, $model, $alias)] = true;
                    if ($f instanceof SqlAggregate) {
                        // Don't group_by aggregate expressions, but if there is an
                        // aggergate expression, then we need a GROUP BY clause.
                        $need_group_by = true;
                        continue;
                    }
                }
                else {
                    if (!is_int($alias) && $unaliased != $alias)
                        $f .= ' AS '.$compiler->quote($alias);
                    $fields[$f] = true;
                }
                // If there are annotations, add in these fields to the
                // GROUP BY clause
                if ($queryset->annotations && !$queryset->distinct)
                    $additional_group_by[] = $unaliased;
            }
            if ($need_group_by && $additional_group_by)
                $group_by = array_merge($group_by, $additional_group_by);
        }
        // Simple selection from one table
        elseif (!$queryset->aggregated) {
            if ($queryset->defer) {
                foreach ($meta->getFieldNames() as $f) {
                    if (isset($queryset->defer[$f]))
                        continue;
                    $fields[$rootAlias .'.'. $compiler->quote($f)] = true;
                }
            }
            else {
                $fields[$rootAlias.'.*'] = true;
            }
        }
        $fields = array_keys($fields);
        // Add in annotations
        if ($queryset->annotations) {
            foreach ($queryset->annotations as $alias=>$A) {
                // The root model will receive the annotations, add in the
                // annotation after the root model's fields
                if ($A instanceof SqlAggregate)
                    $need_group_by = true;
                $T = $A->toSql($compiler, $model, $alias);
                if (isset($fieldMap)) {
                    array_splice($fields, count($fieldMap[0][0]), 0, array($T));
                    $fieldMap[0][0][] = $alias;
                }
                else {
                    // No field map — just add to end of field list
                    $fields[] = $T;
                }
            }
            // If no group by has been set yet, use the root model pk
            if (!$group_by && !$queryset->aggregated && !$queryset->distinct && $need_group_by) {
                foreach ($meta['pk'] as $pk)
                    $group_by[] = $rootAlias .'.'. $pk;
            }
        }
        // Add in SELECT extras
        if (isset($queryset->extra['select'])) {
            foreach ($queryset->extra['select'] as $name=>$expr) {
                if ($expr instanceof SqlFunction)
                    $expr = $expr->toSql($compiler, false, $name);
                else
                    $expr = sprintf('%s AS %s', $expr, $compiler->quote($name));
                $fields[] = $expr;
            }
        }
        if (isset($queryset->distinct)) {
            foreach ($queryset->distinct as $d)
                list($group_by[]) = $compiler->getField($d, $model);
        }
        $group_by = $group_by ? ' GROUP BY '.implode(', ', $group_by) : '';

        return array(implode(', ', $fields), $group_by,
            isset($fieldMap) ? $fieldMap : array());
    }
}
