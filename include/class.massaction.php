<?php
/**
 * include/class.massaction.php
 *
 * Piezas comunes de las acciones en lote de tickets (`include/ajax.tickets.php`)
 * y de tareas (`include/ajax.tasks.php`), sacadas a una clase sin base de
 * datos (frente 2 de docs/CODE_QUALITY.md).
 *
 * Las dos `massProcess()` son gemelas: tabla de acciones, recuento de lo
 * seleccionado, identificadores que llegan por GET o POST, y los mismos dos
 * mensajes de resultado. Lo que difiere —y se queda en cada archivo— es el
 * trabajo con la base de datos y los textos propios de cada acción.
 *
 * Ojo con `claim`: en tickets el verbo es «assigned» y en tareas «claimed».
 * Es intencionado y hay pruebas que lo fijan.
 */
class MassAction {

    /**
     * Acciones disponibles, con el verbo que se usa en los mensajes.
     *
     * @param string $kind  'ticket' o 'task'
     * @return array        acción => array('verbed' => texto)
     */
    static function actions($kind) {
        $verbs = $kind === 'task'
            ? array(
                'transfer' => __('transferred'),
                'assign'   => __('assigned'),
                'claim'    => __('claimed'),
                'delete'   => __('deleted'),
                'reopen'   => __('reopened'),
                'close'    => __('closed'),
            )
            : array(
                'transfer' => __('transferred'),
                'assign'   => __('assigned'),
                'refer'    => __('referred'),
                'merge'    => __('merged'),
                'link'     => __('linked'),
                'claim'    => __('assigned'),
                'delete'   => __('deleted'),
                'reopen'   => __('reopen'),
                'close'    => __('closed'),
            );

        $actions = array();
        foreach ($verbs as $action => $verbed)
            $actions[$action] = array('verbed' => $verbed);

        return $actions;
    }

    /** ¿Existe la acción para este tipo de objeto? */
    static function isAction($kind, $action) {
        $actions = self::actions($kind);
        return isset($actions[$action]);
    }

    /** Singular y plural del objeto, para los mensajes. */
    static function noun($kind) {
        return $kind === 'task'
            ? array('selected task', 'selected tasks')
            : array('selected ticket', 'selected tickets');
    }

    /**
     * Cuántos elementos se han seleccionado.
     *
     * Con POST, la lista viene en `tids`. Sin POST (primera carga del
     * diálogo) sólo se conoce el número, que llega en `count`.
     *
     * @return array  ['count' => int|mixed, 'error' => string|null]
     */
    static function selection($post, $request, $kind) {
        if (!$post)
            return array('count' => $request['count'] ?? 0, 'error' => null);

        $ids = $post['tids'] ?? null;
        $count = is_array($ids) ? count($ids) : 0;

        return array(
            'count' => $count,
            'error' => $ids ? null : self::selectionError($kind),
        );
    }

    /** Aviso de «no has seleccionado nada». */
    static function selectionError($kind) {
        return sprintf(__('You must select at least %s.'),
            $kind === 'task' ? __('one task') : __('one ticket'));
    }

    /**
     * Identificadores seleccionados, vengan por GET (enlaces de la lista),
     * por POST (el formulario) o de la petición.
     *
     * Antes, `merge`/`link` hacían `explode(',', $_POST['tids'])`: si `tids`
     * llegaba como lista, eso era un TypeError. Aquí una lista se usa tal
     * cual y una cadena se parte por comas.
     *
     * @return array  lista de identificadores, sin huecos ni vacíos
     */
    static function ids($get, $post, $request) {
        foreach (array($get, $post, $request) as $source) {
            if (isset($source['tids']) && $source['tids'] !== '')
                return self::splitIds($source['tids']);
        }

        return array();
    }

    /** Parte `1,2,3` en [1, 2, 3]; una lista se devuelve tal cual. */
    static function splitIds($value) {
        if (is_array($value))
            return array_values(array_filter($value, function ($id) {
                return $id !== '' && $id !== null;
            }));

        $ids = array();
        foreach (explode(',', (string) $value) as $id) {
            $id = trim($id);
            if ($id !== '')
                $ids[] = $id;
        }

        return $ids;
    }

    /**
     * ¿La petición de combinar es un merge o un link? Sale de la ruta.
     *
     * @return array  ['title' => 'merge'|'link', 'event' => 'merged'|'linked']
     */
    static function mergeAction($pathInfo) {
        $title = strpos((string) $pathInfo, 'link') !== false ? 'link' : 'merge';

        return array(
            'title' => $title,
            'event' => $title === 'link' ? 'linked' : 'merged',
        );
    }

    /**
     * Mueve el identificador del ticket padre al principio de la lista,
     * conservando el orden del resto. Es lo que hace que el padre aparezca
     * primero en el diálogo de combinación.
     */
    static function moveToFront(array $ids, $id) {
        if ($id === null || $id === '')
            return array_values($ids);

        $first = array();
        $rest = array();
        $moved = false;

        foreach ($ids as $value) {
            if (!$moved && (string) $value === (string) $id) {
                $first[] = $value;
                $moved = true;
                continue;
            }
            $rest[] = $value;
        }

        return array_merge($first, $rest);
    }

    /** Estado al que lleva una acción de cambio de estado. */
    static function statusFor($action) {
        switch ($action) {
        case 'reopen':
            return 'open';
        case 'close':
            return 'closed';
        }

        return null;
    }

    /**
     * Mensaje cuando se ha hecho con todos: «Successfully transferred 3
     * selected tickets.»
     */
    static function successMessage($verbed, $count, $kind) {
        list($one, $many) = self::noun($kind);

        return sprintf(
            __('Successfully %1$s %2$s.'
                /* Tokens are <actioned> <x selected task(s)> */),
            $verbed,
            sprintf('%1$d %2$s', $count, _N($one, $many, $count)));
    }

    /**
     * Mensaje cuando sólo se ha podido con algunos: «2 of 3 selected tickets
     * transferred».
     */
    static function partialMessage($done, $count, $verbed, $kind) {
        list($one, $many) = self::noun($kind);

        return sprintf(
            __('%1$d of %2$d %3$s %4$s'
                /* Tokens are <x> of <y> <selected task(s)> <actioned> */),
            $done, $count, _N($one, $many, $count), $verbed);
    }

    /**
     * El script que copia las casillas marcadas al formulario del diálogo.
     *
     * Estaba escrito dos veces, letra por letra, en los dos `massProcess()`.
     * Sólo cambia el selector del formulario de la lista.
     */
    static function selectedScript($form) {
        $tpl = <<<'JS'

        <script type="text/javascript">
        $(function() {
            $('form#%s input[name="tids[]"]:checkbox:checked')
            .each(function() {
                $('<input>')
                .prop('type', 'hidden')
                .attr('name', 'tids[]')
                .val($(this).val())
                .appendTo('form.mass-action');
            });
        });
        </script>
JS;

        // El texto se arma en PHP (heredoc sin interpolación), así que el
        // nonce se pone después: el script se inyecta con jQuery y tiene que
        // llevar el nonce de la página
        return Http::nonceScripts(sprintf($tpl, $form));
    }
}
