<?php
/*********************************************************************
    class.search.fields.php

    Campos de busqueda que se calculan con una consulta: la interfaz
    `AnnotatedField` y los contadores del ticket y de su hilo.

    Parte de include/class.search.php (frente 6 de docs/CODE_QUALITY.md): el
    archivo tenia 2.030 lineas. Se movieron las clases enteras, sin tocar su
    codigo; el original incluye este al final. La interfaz `Searchable` se
    queda alli, porque `Ticket` y `Task` la implementan.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
/*
 * Implemented by annotated fields
 *
 */

interface AnnotatedField {
     // Add the annotation to a QuerySet
    function annotate($query, $name);
}

class TicketThreadCountField extends NumericField
implements AnnotatedField {

    function addToQuery($query, $name=false) {
        return TicketThreadCount::addToQuery($query, $name);
    }

    function from_query($row, $name=false) {
         return TicketThreadCount::from_query($row, $name);
    }

    function annotate($query, $name) {
        return TicketThreadCount::annotate($query, $name);
    }
}

class TicketReopenCountField extends NumericField
implements AnnotatedField {

    function addToQuery($query, $name=false) {
        return TicketReopenCount::addToQuery($query, $name);
    }

    function from_query($row, $name=false) {
         return TicketReopenCount::from_query($row, $name);
    }

    function annotate($query, $name) {
        return TicketReopenCount::annotate($query, $name);
    }
}

class ThreadAttachmentCountField extends NumericField
implements AnnotatedField {

    function addToQuery($query, $name=false) {
        return ThreadAttachmentCount::addToQuery($query, $name);
    }

    function from_query($row, $name=false) {
         return ThreadAttachmentCount::from_query($row, $name);
    }

    function annotate($query, $name) {
        return ThreadAttachmentCount::annotate($query, $name);
    }
}

class ThreadCollaboratorCountField extends NumericField
implements  AnnotatedField {

    function addToQuery($query, $name=false) {
        return ThreadCollaboratorCount::addToQuery($query, $name);
    }

    function from_query($row, $name=false) {
         return ThreadCollaboratorCount::from_query($row, $name);
    }

    function annotate($query, $name) {
        return ThreadCollaboratorCount::annotate($query, $name);
    }
}

class TicketTasksCountField extends NumericField
implements  AnnotatedField {

    function addToQuery($query, $name=false) {
        return TicketTasksCount::addToQuery($query, $name);
    }

    function from_query($row, $name=false) {
         return TicketTasksCount::from_query($row, $name);
    }

    function annotate($query, $name) {
        return TicketTasksCount::annotate($query, $name);
    }
}
