<?php
/*********************************************************************
    include/cli/seed.plan.php

    Los planes del sembrador de demostración: qué registros se crean
    (frente 2 de docs/CODE_QUALITY.md y `--count` de `docs/SEED.md`).

    El sembrador nació con listas curadas —12 usuarios, 59 tickets, 16
    tareas— escritas a mano para que la demostración contara una historia.
    Eso está bien para enseñar la herramienta y no sirve para medir: una
    cola con 40 tickets no dice nada del rendimiento de una cola con 40.000,
    y las pruebas de volumen necesitan `--count=2000`.

    Aquí está la parte que **inventa** los registros que faltan hasta el
    número pedido. No toca la base de datos ni el módulo: recibe lo que ya
    hay (la lista curada, los nombres de los temas, de los departamentos y
    de los agentes) y devuelve filas con la misma forma que las curadas. Por
    eso se puede probar sin MariaDB, que es justo lo que hacía falta.

    Reglas que se mantienen:

      - Determinista: el mismo número produce siempre la misma fila, así que
        una segunda ejecución reconoce lo que ya creó y no duplica.
      - Único: el asunto del ticket y el título de la tarea llevan el
        número, y el correo del usuario también. El sembrador evita
        duplicados buscando por ese texto.
      - Variado: estados, antigüedades, agentes y orígenes se reparten por
        turno, para que las colas, los filtros y los informes tengan de
        todo aunque se pidan dos mil registros.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class SeedPlan {

    /** Palabra que distingue lo generado de lo curado en asuntos y títulos. */
    const MARK = 'demostración';

    /**
     * Nombres de pila y apellidos para los usuarios generados.
     *
     * Se combinan por índice, no al azar: dos ejecuciones tienen que dar el
     * mismo correo para que el sembrador no cree el usuario dos veces.
     */
    static function firstNames() {
        return array('Alejandro', 'Beatriz', 'Cecilia', 'Diego', 'Emilia',
            'Federico', 'Gabriela', 'Hugo', 'Irene', 'Javier', 'Karina',
            'Lorenzo', 'Mónica', 'Nicolás', 'Olga', 'Pablo', 'Queralt',
            'Rosario', 'Santiago', 'Teresa');
    }

    static function lastNames() {
        return array('Aguilar', 'Bermúdez', 'Cabrera', 'Delgado', 'Escobar',
            'Fuentes', 'Gallardo', 'Herrera', 'Ibarra', 'Jiménez', 'León',
            'Montoya', 'Navarro', 'Ochoa', 'Paredes', 'Quintero', 'Ramos',
            'Sierra', 'Tapia', 'Urbina');
    }

    /**
     * Completa una lista curada hasta `$count` filas.
     *
     * @param array $rows    las filas curadas
     * @param int|null $count  cuántas se quieren; `null` deja las curadas
     * @param callable $make   recibe el índice (0, 1, 2…) y devuelve la fila
     * @return array
     */
    static function fill(array $rows, $count, callable $make) {
        if ($count === null)
            return $rows;

        // Un número negativo no pide nada: no se recorta a cero el plan
        // curado por un `--count` mal escrito.
        $count = max(0, (int) $count);
        $rows = array_slice($rows, 0, $count);

        for ($i = count($rows); $i < $count; $i++)
            $rows[] = $make($i);

        return $rows;
    }

    /**
     * Un usuario: nombre, correo (local) y organización.
     *
     * El 13.º usuario es el primero generado; el correo es
     * `cliente13@dominio`, que es lo que usa el sembrador para no repetir.
     *
     * @param int $n  índice generado (13 en adelante)
     * @return array  array(nombre, correo local, índice de organización|null)
     */
    static function person($n) {
        $first = self::firstNames();
        $last = self::lastNames();
        $name = $first[$n % count($first)] . ' '
            . $last[intdiv($n, count($first)) % count($last)];

        // Uno de cada cuatro va sin organización (particular), como en la
        // lista curada; el resto se reparte entre las tres.
        $org = ($n % 4 == 3) ? null : $n % 3;

        return array($name, 'cliente' . $n, $org);
    }

    /**
     * Un ticket: asunto, tema, antigüedad, si se cierra, mensajes y agente.
     *
     * La antigüedad sube de siete en siete días y da la vuelta a los 60, así
     * que dos mil tickets se reparten por igual entre «de hoy» y «de hace dos
     * meses»; uno de cada tres queda cerrado y el hilo tiene entre 1 y 8
     * mensajes.
     *
     * @param int $n            índice generado
     * @param array $topics     nombres de tema que existen
     * @param array $agents     nombres de usuario de agente (puede ir vacío)
     * @return array            la fila de seis campos de `tickets()`
     */
    static function ticket($n, array $topics, array $agents) {
        $topics = array_values($topics);
        $agents = array_values($agents);
        $topic = $topics ? $topics[$n % count($topics)] : 'Problema técnico';

        return array(
            sprintf('%s (%s %d)', $topic, self::MARK, $n),
            $topic,
            ($n * 7) % 60,
            $n % 3 == 0,
            1 + ($n % 8),
            $agents ? $agents[$n % count($agents)] : null,
        );
    }

    /**
     * Una tarea suelta: título, departamento, antigüedad, estado y vencimiento.
     *
     * Sin ticket a propósito: es lo que se pide con `--only=tasks`, donde no
     * hay tickets de los que colgarla.
     *
     * @param int $n         índice generado
     * @param array $depts   nombres de departamento que existen
     * @param array $agents  nombres de usuario de agente
     * @return array         la fila de ocho campos de `tasks()`
     */
    static function task($n, array $depts, array $agents) {
        $depts = array_values($depts);
        $agents = array_values($agents);

        return array(
            sprintf('Tarea %s %d', self::MARK, $n),
            $depts ? $depts[$n % count($depts)] : 'Soporte',
            ($n * 5) % 40,
            $n % 4 == 0,
            ($n % 14) - 3,
            $agents ? $agents[$n % count($agents)] : null,
            sprintf('Trabajo de demostración número %d: revisar el caso, '
                . 'dejar la nota en el ticket y cerrar cuando esté.', $n),
            null,
        );
    }

    /**
     * Una tarea de seguimiento por cada ticket recién creado.
     *
     * Es `--with-tasks`: el ticket se crea con su hilo y queda la tarea
     * enlazada, que es lo que hace el equipo de verdad al abrir un caso.
     *
     * @param array $tickets  filas con `subject`, `agent` y `age`
     * @param array $depts    nombres de departamento que existen
     * @return array          filas de ocho campos de `tasks()`
     */
    static function followUps(array $tickets, array $depts) {
        $depts = array_values($depts);
        $rows = array();

        foreach ($tickets as $i => $ticket) {
            $rows[] = array(
                'Seguimiento: ' . $ticket['subject'],
                $depts ? $depts[$i % count($depts)] : 'Soporte',
                max(0, (int) $ticket['age'] - 1),
                false,
                3,
                isset($ticket['agent']) ? $ticket['agent'] : null,
                'Comprobar el avance del ticket y avisar al cliente.',
                $ticket['subject'],
            );
        }

        return $rows;
    }
}
