<?php
/*********************************************************************
    include/cli/modules/seed.repair.php

    La revision y la reparacion de los datos sembrados: `--doctor` y
    `--repair`. Van en su propio archivo porque `seed.php` volvio a pasar de
    las 2.000 lineas al anadirlas (frente 6 de docs/CODE_QUALITY.md); se
    movieron los metodos enteros, sin tocar su codigo.

    Es un rasgo de SeedManager y no una clase aparte porque la revision usa el
    estado de la ejecucion (`$this->out()`, `$this->warn()`, `$this->scalar()`)
    y los manifiestos de la propia clase.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait SeedRepair {

    /**
     * Revisa que los datos sembrados cuadren con lo que el panel muestra.
     *
     * Nació de las capturas: el sembrador decía «59 tickets, 0 avisos»
     * mientras el *Statistics* mostraba servicios de 0.0 s, la cola «Open»
     * pintaba menos filas de las que decía el paginador y un agente aparecía
     * con 20 tickets borrados. Todo eso son **relaciones entre tablas** que
     * ninguna consulta suelta del sembrador comprueba.
     *
     * Cada revisión imprime su veredicto y, si falla, qué hacer. El comando
     * sale con error si algo no cuadra, así que sirve en un `make`.
     *
     * @return int 0 si todo cuadra, 1 si no
     */
    function doctor($repair = false) {
        if ($repair) {
            $this->out('Reparación de los datos sembrados '
                . '(no se borra nada y no se crea nada nuevo)');
            $this->out('');
            $this->repair();
            $this->out('');
            $this->out('Revisión después de reparar');
        }
        else {
            $this->out('Revisión de los datos sembrados '
                . '(no se crea ni se borra nada)');
        }
        $this->out('');

        $checks = array();
        $add = function ($label, $sql, $expectZero = true, $hint = '') use (&$checks) {
            $checks[] = array('label' => $label, 'sql' => $sql,
                'zero' => $expectZero, 'hint' => $hint);
        };

        // 1. Tickets sin evento de creación: el Statistics los ignora y su
        //    «Service Time» sale 0.0
        $add('tickets con hilo pero sin evento «created»',
            'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' k
               JOIN ' . THREAD_TABLE . " t
                 ON t.object_id = k.ticket_id AND t.object_type = 'T'
              WHERE NOT EXISTS (SELECT 1 FROM " . THREAD_EVENT_TABLE . " e
                                  WHERE e.thread_id = t.id
                                    AND e.event_id = (SELECT id FROM " . EVENT_TABLE . "
                                                       WHERE name = 'created'))",
            true, 'vuelva a sembrar: el sembrador fecha los eventos desde la '
                . 'última versión');

        // 2. Tickets cerrados sin evento «closed»: Statistics dice 0 cerrados
        $add('tickets cerrados sin evento «closed»',
            'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' k
              WHERE k.closed IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM ' . THREAD_TABLE . " t
                  JOIN " . THREAD_EVENT_TABLE . " e ON e.thread_id = t.id
                 WHERE t.object_id = k.ticket_id AND t.object_type = 'T'
                   AND e.event_id = (SELECT id FROM " . EVENT_TABLE . "
                                      WHERE name = 'closed'))",
            true, 'el paso `tickets` registra el cierre desde la última versión');

        // 3. Filas de hilo sin dueño: se cuentan en los informes aunque no
        //    salgan en ninguna cola («Deleted 20»)
        $add('filas de evento de hilo sin hilo detrás',
            'SELECT COUNT(*) FROM ' . THREAD_EVENT_TABLE . ' e
               LEFT JOIN ' . THREAD_TABLE . ' t ON t.id = e.thread_id
              WHERE t.id IS NULL',
            true, 'ejecute `--fresh` (limpia huérfanos) o `--doctor --repair`');

        // 4. Respuestas fechadas antes que su mensaje padre: tiempos de
        //    respuesta negativos
        $add('entradas anteriores a su mensaje padre',
            'SELECT COUNT(*) FROM ' . THREAD_ENTRY_TABLE . ' e
               JOIN ' . THREAD_ENTRY_TABLE . ' p ON p.id = e.pid
              WHERE e.created < p.created',
            true, 'las respuestas quedaron antes que su mensaje padre: '
                . '`make seed-repair` las recoloca detrás (o vuelva a sembrar)');

        // 5. Tickets sin fila en la vista de datos: invisibles en el panel
        $add('tickets sin datos de formulario (ticket__cdata)',
            'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' k
              WHERE NOT EXISTS (SELECT 1 FROM ' . TICKET_CDATA_TABLE . ' c
                                 WHERE c.ticket_id = k.ticket_id)',
            true, 'reconstruya la vista con `make db-rebuild-views` (o vuelva a '
                . 'sembrar: el sembrador la reconstruye al terminar)');

        // 5b. Departamentos con tickets fuera del alcance del administrador:
        //     las colas filtran por la visibilidad del agente, así que esos
        //     tickets se cuentan pero no se listan.
        if (($admin = $this->adminStaff ?: $this->firstAdmin())) {
            $depts = array_map('intval', $admin->getDepts());
            if ($depts) {
                $add('tickets en departamentos que el administrador no ve',
                    'SELECT COUNT(*) FROM ' . TICKET_TABLE
                    . ' WHERE dept_id NOT IN (' . implode(',', $depts) . ')',
                    true, 'las colas aplican la visibilidad del agente: '
                        . 'Admin → Agents → ' . $admin->getUserName()
                        . ' → Departments, o vuelva a sembrar (el sembrador '
                        . 'concede el acceso)');
            }
        }

        // 6. Artículos de conocimiento sin categoría válida
        $add('artículos sin categoría (huérfanos)',
            'SELECT COUNT(*) FROM ' . FAQ_TABLE . ' f
               LEFT JOIN ' . FAQ_CATEGORY_TABLE . ' c
                 ON c.category_id = f.category_id
              WHERE c.category_id IS NULL',
            true, 'los artículos sin categoría no se ven en la base de '
                . 'conocimiento: `make seed` o `make seed-repair` los '
                . 'recolocan en la suya (no se borra ninguno)');

        // 7. Tickets cuyo departamento no es el del tema (el tema manda)
        $add('tickets en un departamento distinto al de su tema',
            'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' k
               JOIN ' . TOPIC_TABLE . ' h ON h.topic_id = k.topic_id
              WHERE h.dept_id > 0 AND k.dept_id <> h.dept_id',
            true, 'el departamento lo pone el tema al crear el ticket');

        // 8. Duplicados: mismo asunto
        $add('asuntos de ticket repetidos',
            'SELECT COUNT(*) FROM (
                SELECT subject FROM ' . TICKET_CDATA_TABLE . '
                 GROUP BY subject HAVING COUNT(*) > 1) d',
            true, 'el sembrador salta los asuntos repetidos');

        // 9. Cola «Open»: el paginador cuenta por un lado y la consulta
        //    devuelve por otro cuando faltan filas en la vista
        $add('tickets activos que no salen en ninguna cola',
            'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' k
               JOIN ' . TICKET_STATUS_TABLE . ' s ON s.id = k.status_id
              WHERE s.state IN (\'open\', \'in_progress\', \'answered\')
                AND NOT EXISTS (SELECT 1 FROM ' . TICKET_CDATA_TABLE . ' c
                                 WHERE c.ticket_id = k.ticket_id)',
            true, 'sin fila en la vista, la cola los cuenta pero no los pinta: '
                . '`make db-rebuild-views`');

        $failed = 0;
        foreach ($checks as $check) {
            $value = $this->scalar($check['sql']);
            if ($value === null) {
                $this->err(sprintf('  ?  %s — no se pudo comprobar', $check['label']));
                $failed++;
                continue;
            }
            $value = (int) $value;
            if ($check['zero'] ? $value === 0 : $value > 0) {
                $this->out(sprintf('  ✓  %s', $check['label']));
            }
            else {
                $failed++;
                $this->err(sprintf('  ✗  %s: %d', $check['label'], $value));
                if ($check['hint'])
                    $this->err('       → ' . $check['hint']);
            }
        }

        // Resumen de cifras, para comparar con las capturas
        $this->out('');
        $this->out('Cifras');
        foreach (array(
            'tickets' => 'SELECT COUNT(*) FROM ' . TICKET_TABLE,
            'abiertos' => 'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' k
                JOIN ' . TICKET_STATUS_TABLE . " s ON s.id = k.status_id
                WHERE s.state IN ('open', 'in_progress', 'answered')",
            'cerrados' => 'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' WHERE closed IS NOT NULL',
            'eventos de hilo' => 'SELECT COUNT(*) FROM ' . THREAD_EVENT_TABLE,
            'artículos' => 'SELECT COUNT(*) FROM ' . FAQ_TABLE,
            'categorías' => 'SELECT COUNT(*) FROM ' . FAQ_CATEGORY_TABLE,
            'agentes' => 'SELECT COUNT(*) FROM ' . STAFF_TABLE . ' WHERE isactive = 1',
            // Sin agente no hay «Assigned To» en la cola: el sembrador deja
            // algunos a propósito, pero conviene ver cuántos son
            'activos sin agente' => 'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' k
                JOIN ' . TICKET_STATUS_TABLE . " s ON s.id = k.status_id
                WHERE s.state IN ('open', 'in_progress', 'answered')
                  AND k.staff_id = 0",
        ) as $label => $sql) {
            $value = $this->scalar($sql);
            $this->out(sprintf('  %-18s %s', $label . ':', $value === null ? '?' : $value));
        }

        $this->out('');
        if ($failed) {
            $this->err(sprintf('%d comprobación(es) con problemas.', $failed));
            return 1;
        }

        $this->out('Todo cuadra.');
        return 0;
    }

    /**
     * Arregla, sin borrar y sin volver a sembrar, lo que el chequeo detecta.
     *
     * Existe porque el doctor lo prometía en dos de sus pistas
     * («`--doctor --repair`») y la opción no estaba: el comando respondía lo
     * mismo que sin ella. Se repara sólo lo que tiene una reparación **sin
     * pérdida**: no se borra un artículo, no se reescribe un ticket y no se
     * toca nada que no haya creado el sembrador.
     *
     * Lo que no se repara aquí: un ticket sin evento de creación o de cierre,
     * y un departamento que el administrador no ve. Eso es volver a sembrar
     * (`make seed`) o dar el acceso desde el panel.
     */
    function repair() {
        $this->cleanupOrphans();
        $this->repairFaqCategories();
        $this->repairEntryDates();

        // Las vistas materializadas: si a un ticket le falta su fila, la cola
        // lo cuenta y no lo pinta.
        try {
            DynamicForm::rebuildDynamicDataViews();
            $this->out('  · vistas de datos de los formularios reconstruidas');
        }
        catch (Throwable $e) {
            $this->warn('no se pudieron reconstruir las vistas: '
                . $e->getMessage());
        }
    }

    /**
     * Recoloca los artículos de demostración que se quedaron sin categoría.
     *
     * Pasa cuando la purga borra las categorías y los artículos sobreviven
     * (o al revés): el artículo queda con un `category_id` que ya no existe y
     * **no se ve en la base de conocimiento**. El manifiesto del sembrador
     * dice a qué categoría va cada pregunta, así que se recoloca por pregunta
     * exacta; lo que no está en el manifiesto no se toca.
     */
    function repairFaqCategories() {
        $seen = $found = $ok = $fixed = $missing = 0;
        $notfound = array();

        foreach (self::faqs() as $name => $articles) {
            if (!($category = $this->categoryByName($name))) {
                // Sin la categoría no hay dónde recolocar: la crea el paso
                // `faq` de `make seed`.
                $missing++;
                continue;
            }

            foreach ($articles as $article) {
                list($question) = $article;
                $seen++;

                // Por la tabla (misma columna con la que `FAQ::update()`
                // decide que hay duplicado) y, si no aparece, por el ORM.
                $faq = null;
                if (($id = $this->faqIdByQuestion($question)))
                    $faq = FAQ::lookup($id);
                if (!$faq)
                    $faq = FAQ::lookup(array('question' => $question));
                if (!$faq) {
                    if (count($notfound) < 3)
                        $notfound[] = $question;
                    continue;
                }

                $found++;

                if ($faq->getCategoryId() == $category->getId()) {
                    $ok++;
                    continue;
                }

                $vars = array(
                    'id' => $faq->getId(),
                    'question' => $faq->getQuestion(),
                    'answer' => $faq->getAnswer(),
                    'category_id' => $category->getId(),
                    'ispublished' => FAQ::VISIBILITY_PUBLIC,
                    'notes' => $faq->getNotes(),
                    'topics' => $faq->getHelpTopicsIds(),
                );
                $errors = array();
                if (!$faq->update($vars, $errors)) {
                    $this->warn(sprintf('no se pudo recolocar «%s»: %s',
                        $question,
                        Format::array_implode(' · ', ' · ', $errors)));
                    continue;
                }
                $fixed++;
            }
        }

        // Las cifras van siempre: sin ellas, un «0 recolocados» no dice si el
        // problema es que no se encuentran los artículos, que ya están en su
        // sitio o que la categoría no existe.
        $this->out(sprintf('  · %d artículo(s) del manifiesto: %d en la base, '
            . '%d ya en su categoría, %d recolocados',
            $seen, $found, $ok, $fixed));

        // Y el estado real de la tabla: cuántos artículos hay y a qué
        // categorías apuntan. Un `category_id` que no existe en
        // `ost_faq_category` es lo que cuenta el chequeo del doctor.
        try {
            $used = array();
            foreach (FAQ::objects()->values('category_id')
                    ->distinct('category_id') as $row)
                $used[] = $row['category_id'] === null
                    ? 'NULL' : (string) $row['category_id'];

            $this->out(sprintf('  · %d artículo(s) en la base; apuntan a las '
                . 'categorías: %s', FAQ::objects()->count(),
                $used ? implode(', ', $used) : '—'));
        }
        catch (Throwable $e) {
            $this->warn('no se pudo leer la tabla de artículos: '
                . $e->getMessage());
        }

        if ($notfound)
            $this->warn('no se encuentran por su pregunta (pueden ser de otra '
                . 'versión del sembrador): ' . implode(' · ', $notfound));

        // Una pregunta del manifiesto y otra de la base, lado a lado: si no
        // coinciden letra a letra, se ve aquí y no hay que adivinar.
        if ($seen && !$found) {
            try {
                $sample = array();
                foreach (FAQ::objects()->values('question')->limit(2) as $row)
                    $sample[] = (string) $row['question'];
                $categories = self::faqs();
                $articles = $categories ? reset($categories) : array();
                $article = $articles ? reset($articles) : array();
                $mine = $article ? (string) $article[0] : '';
                $this->out(sprintf('  · manifiesto: «%s»', $mine));
                foreach ($sample as $question)
                    $this->out(sprintf('  · en la base:  «%s»', $question));
            }
            catch (Throwable $e) {
                // El muestrario es una ayuda, no un requisito
            }
        }

        if ($missing)
            $this->warn(sprintf('%d categoría(s) del manifiesto no existen: '
                . 'créalas con `make seed --only=faq`', $missing));
    }

    /**
     * Recoloca detrás de su mensaje padre las entradas fechadas antes.
     *
     * El *Statistics* nativo mide el tiempo de respuesta restando la fecha del
     * padre a la de la respuesta: si la resta es negativa, el informe publica
     * «-3.5 h». La causa está en versiones anteriores del sembrador, que
     * dejaban la entrada de apertura en `NOW()`; el arreglo (`backdate()`) ya
     * fecha la apertura, pero una base sembrada antes lo conserva.
     *
     * Se recorre el hilo entero y se ordena por id: los hijos de un mismo
     * padre se separan un minuto entre sí —el orden en que se escribieron— y
     * se guardan con el ORM, sin SQL a mano.
     */
    function repairEntryDates() {
        // Se leen los modelos, no un `values()`: así se usan los mismos
        // campos que el resto del módulo (`$entry->pid`, `$entry->created`) y
        // no se depende de cómo se llamen las columnas en el SELECT.
        $entries = array();
        foreach (ThreadEntry::objects()->all() as $entry)
            $entries[(int) $entry->getId()] = array(
                'id' => (int) $entry->getId(),
                'pid' => (int) $entry->pid,
                'created' => $entry->created,
                'thread_id' => (int) $entry->thread_id,
            );

        ksort($entries);

        $fixed = 0;
        $withParent = 0;
        $outOfOrder = 0;
        $threads = array();
        $offset = array();

        foreach ($entries as $id => $row) {
            $pid = (int) ($row['pid'] ?? 0);
            if (!$pid || !isset($entries[$pid]))
                continue;

            $withParent++;

            if (strtotime($row['created']) >= strtotime($entries[$pid]['created']))
                continue;

            $outOfOrder++;

            // Un minuto más por hermano ya recolocado, para no dejar dos
            // entradas con la misma marca de tiempo.
            $offset[$pid] = isset($offset[$pid]) ? $offset[$pid] + 1 : 1;
            $when = date('Y-m-d H:i:s',
                strtotime($entries[$pid]['created'] . ' +' . $offset[$pid] . ' minutes'));

            ThreadEntry::objects()
                ->filter(array('id' => $id))
                ->update(array('created' => $when));

            $entries[$id]['created'] = $when;
            $threads[(int) $row['thread_id']] = true;
            $fixed++;
        }

        // La última actividad del hilo, que es lo que ordena la columna
        // «Last Updated» de las colas.
        foreach (array_keys($threads) as $threadId) {
            $last = null;
            foreach (ThreadEntry::objects()
                    ->filter(array('thread_id' => $threadId))->all() as $entry)
                if ($last === null || $entry->created > $last)
                    $last = $entry->created;

            if ($last)
                Thread::objects()
                    ->filter(array('id' => $threadId))
                    ->update(array('lastmessage' => $last));
        }

        // Las cifras también aquí: distinguen «no hay ninguna entrada con
        // padre» (el dato que se leyó no era el que se creía) de «no hay
        // ninguna fuera de orden» (ya estaba bien).
        $this->out(sprintf('  · %d entrada(s): %d con mensaje padre, '
            . '%d antes de su padre, %d recolocadas',
            count($entries), $withParent, $outOfOrder, $fixed));
    }
}
