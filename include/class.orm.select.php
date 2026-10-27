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
        $meta = $model::getMeta();
        $fieldMap = array();
        $group_by = $additional_group_by = array();
        $need_group_by = false;

        // Qué columnas se piden: una rama por tipo de consulta. Los valores
        // que necesitan las anotaciones (el GROUP BY de `values()` y si hay
        // agregados) salen de la rama en la que se calculan.
        if ($queryset->related) {
            list($fields, $fieldMap) = self::relatedFields($queryset,
                $compiler, $model, $rootAlias, $meta);
        }
        elseif ($queryset->values) {
            list($fields, $group_by, $need_group_by, $additional_group_by)
                = self::valueFields($queryset, $compiler, $model);

            // Con `values()` y agregados, las columnas pedidas son el GROUP
            // BY aunque después no se añada ninguna anotación. El bloque de
            // anotaciones de abajo no basta: se salta las consultas
            // `distinct` y las ya agregadas.
            if ($need_group_by && $additional_group_by)
                $group_by = array_merge($group_by, $additional_group_by);
        }
        else {
            $fields = self::plainFields($queryset, $compiler, $meta,
                $rootAlias);
        }

        // Add in annotations
        if ($queryset->annotations) {
            // Ojo con el mapa: `addAnnotations()` mete cada anotación **en
            // medio** de la lista (detrás de los campos del modelo raíz) y a
            // la vez la apunta en el mapa del tramo raíz. Si se pierde esa
            // segunda parte, las columnas de los modelos relacionados se
            // reparten corridas una posición y la hidratación sale mal.
            list($fields, $need_group_by, $fieldMap) = self::addAnnotations(
                $fields, $queryset, $compiler, $model, $fieldMap,
                $need_group_by);

            // If no group by has been set yet, use the root model pk
            if (!$group_by && !$queryset->aggregated && !$queryset->distinct
                    && $need_group_by) {
                // Con `values()`, el GROUP BY son las columnas pedidas:
                // agrupar por la clave primaria devolvería una fila por
                // registro y dejaría las demás columnas a merced del motor
                // (o sería un error con ONLY_FULL_GROUP_BY).
                if (!empty($additional_group_by))
                    $group_by = $additional_group_by;
                else
                    foreach ($meta['pk'] as $pk)
                        $group_by[] = $rootAlias .'.'. $pk;
            }
        }

        // Add in SELECT extras
        if (isset($queryset->extra['select']))
            $fields = array_merge($fields, self::extraFields($queryset,
                $compiler));

        if (isset($queryset->distinct)) {
            foreach ($queryset->distinct as $d)
                list($group_by[]) = $compiler->getField($d, $model);
        }

        $group_by = $group_by ? ' GROUP BY '.implode(', ', $group_by) : '';

        return array(implode(', ', $fields), $group_by, $fieldMap);
    }

    /**
     * Las columnas de una consulta con `select_related()`.
     *
     * Va primero el modelo raíz y después cada salto de la ruta, saltando los
     * campos diferidos y las columnas que ya se han pedido. El mapa que
     * devuelve es lo que usa el ORM para repartir las filas por modelo.
     *
     * @return array  [campos, mapa de campos]
     */
    private static function relatedFields(QuerySet $queryset,
            SqlCompiler $compiler, $model, $rootAlias, $meta) {
        $fields = $fieldMap = $theseFields = array();
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
                if ($theseFields)
                    $fieldMap[] = array($theseFields, $fmodel, $parts);
                $full_path .= '__';
            }
        }

        return array(array_keys($fields), $fieldMap);
    }

    /**
     * Las columnas de una consulta con `values()`.
     *
     * Cada valor es una ruta de campo o una expresión (`SqlFunction`), que no
     * pasa por `getField()`. Las expresiones agregadas no van al GROUP BY,
     * pero obligan a que haya uno.
     *
     * @return array  [campos, group by, ¿hace falta group by?, columnas del grupo]
     */
    private static function valueFields(QuerySet $queryset,
            SqlCompiler $compiler, $model) {
        $fields = $additional_group_by = array();
        $need_group_by = false;

        foreach ($queryset->values as $alias=>$v) {
            // Una expresión calculada no pasa por getField(): no es una
            // ruta de campo, y su alias ya viene del arreglo de values()
            if ($v instanceof SqlFunction)
                $f = $v;
            else
                list($f) = $compiler->getField($v, $model);
            $unaliased = $f;
            if ($f instanceof SqlFunction) {
                $fields[$f->toSql($compiler, $model, $alias)] = true;
                // La expresión, sin alias, es la que va al GROUP BY
                $unaliased = $f->toSql($compiler, $model, false);
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

        return array(array_keys($fields), array(), $need_group_by,
            $additional_group_by);
    }

    /**
     * Las columnas de una consulta normal: los campos del modelo, o `raíz.*`.
     *
     * @return array
     */
    private static function plainFields(QuerySet $queryset,
            SqlCompiler $compiler, $meta, $rootAlias) {
        // Una consulta agregada no pide columnas del modelo: su SELECT son
        // las anotaciones (COUNT, SUM, MAX...). Pedir ademas `raiz.*` deja un
        // SELECT de dos columnas, y una anotacion con esta consulta dentro
        // -el recuento del hilo, TicketThreadCount::annotate()- deja de ser
        // una subconsulta escalar: MySQL responde [1241] Operand should
        // contain 1 column(s) y la lista de tickets sale vacia.
        if ($queryset->aggregated)
            return array();

        if (!$queryset->defer)
            return array($rootAlias.'.*');

        $fields = array();
        foreach ($meta->getFieldNames() as $f) {
            if (isset($queryset->defer[$f]))
                continue;
            $fields[$rootAlias .'.'. $compiler->quote($f)] = true;
        }

        return array_keys($fields);
    }

    /**
     * Añade las anotaciones a la lista de columnas.
     *
     * Con relaciones van detrás de los campos del modelo raíz (no al final):
     * el mapa de campos reparte las filas por posición y la anotación es del
     * modelo raíz.
     *
     * @return array  [campos, ¿hace falta group by?]
     */
    /**
     * Añade las anotaciones al SELECT y al mapa de campos.
     *
     * Devuelve `array($fields, $need_group_by, $fieldMap)`: el mapa tiene que
     * volver a quien llama, porque las anotaciones se insertan entre los
     * campos de la raíz y los de las relaciones, y el reparto de la fila
     * (`ModelInstanceManager::buildModel()`) se hace con ese mapa.
     */
    private static function addAnnotations(array $fields, QuerySet $queryset,
            SqlCompiler $compiler, $model, array $fieldMap, $need_group_by) {
        foreach ($queryset->annotations as $alias=>$A) {
            // The root model will receive the annotations, add in the
            // annotation after the root model's fields
            if ($A instanceof SqlAggregate)
                $need_group_by = true;
            $T = $A->toSql($compiler, $model, $alias);
            if ($fieldMap) {
                array_splice($fields, count($fieldMap[0][0]), 0, array($T));
                $fieldMap[0][0][] = $alias;
            }
            else {
                // No field map — just add to end of field list
                $fields[] = $T;
            }
        }

        // El mapa se devuelve además: se recibe por valor, así que lo que se
        // apunta aquí no lo vería quien llama.
        return array($fields, $need_group_by, $fieldMap);
    }

    /**
     * Las expresiones de `extra['select']`, con su alias.
     *
     * @return array
     */
    private static function extraFields(QuerySet $queryset,
            SqlCompiler $compiler) {
        $fields = array();

        foreach ($queryset->extra['select'] as $name=>$expr) {
            if ($expr instanceof SqlFunction)
                $expr = $expr->toSql($compiler, false, $name);
            else
                $expr = sprintf('%s AS %s', $expr, $compiler->quote($name));
            $fields[] = $expr;
        }

        return $fields;
    }
}
