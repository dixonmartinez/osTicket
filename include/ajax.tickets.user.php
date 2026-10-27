<?php
/*********************************************************************
    ajax.tickets.user.php

    Buscar al usuario de un ticket y editarlo desde el panel: el buscador por
    nombre, numero y correo, la vista previa del ticket y la ficha del usuario.

    Es un rasgo de TicketsAjaxAPI y no una clase porque son metodos de la
    misma API: usan `$thisstaff`, `$this->response()` y los ayudantes de
    AjaxController. Se sacaron de include/ajax.tickets.php (frente 6 de
    docs/CODE_QUALITY.md) moviendo los metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

if(!defined('INCLUDE_DIR')) die('403');

trait TicketsAjaxUser {

function lookup($wildcard=false) {
        global $thisstaff;

        $limit = isset($_REQUEST['limit']) ? (int) $_REQUEST['limit']:25;
        $tickets=array();
        // Bail out of query is empty
        if (!$_REQUEST['q'])
            return $this->json_encode($tickets);

        $visibility = $thisstaff->getTicketsVisibility();
        $hits = Ticket::objects()
            ->filter($visibility)
            ->values('user__default_email__address', 'cdata__subject', 'user__name', 'ticket_id', 'thread__id', 'flags')
            ->annotate(array(
                'number' => new SqlCode('null'),
                'tickets' => SqlAggregate::COUNT('ticket_id', true),
                'tasks' => SqlAggregate::COUNT('tasks__id', true),
                'collaborators' => SqlAggregate::COUNT('thread__collaborators__id', true),
                'entries' => SqlAggregate::COUNT('thread__entries__id', true),
            ))
            ->order_by(SqlAggregate::SUM(new SqlCode('Z1.relevance')), QuerySet::DESC)
            ->distinct('user__default_email__address')
            ->limit($limit);

        $q = $_REQUEST['q'];

        if (strlen(Format::searchable($q)) < 3)
            return $this->encode(array());

        global $ost;
        $hits = $ost->searcher->find($q, $hits, false, ['boolean' => $wildcard]);

        $T = array();
        if (preg_match('/\d{2,}[^*]/', $q, $T)) {
            $hits = $this->lookupByNumber($limit, $visibility, $hits);
        }
        elseif (preg_match('/(.*@.{2,})|(.{2,}@.*)/', $q, $T)) {
            $hits = $this->lookupByEmail($limit, $visibility, $hits);
        }
        elseif (!$wildcard && !count($hits) && preg_match('`^[a-zA-Z0-9]+$`u', $q)) {
            // Do wild-card fulltext search
            $_REQUEST['q'] = $q.'*';
            return $this->lookup(true);
        }

        // TODO: Why not aggregate based on relationship?
        foreach ($hits as $T) {
            $email = $T['user__default_email__address'];
            $count = $T['tickets'];
            if ($T['number']) {
                $tickets[$T['number']] = array('id'=>$T['number'], 'value'=>$T['number'],
                    'ticket_id'=>$T['ticket_id'],
                    'info'=>"{$T['number']} — {$email}",
                    'subject'=>$T['cdata__subject'],
                    'user'=>$T['user__name'],
                    'tasks'=>$T['tasks'],
                    'thread_id'=>$T['thread__id'],
                    'collaborators'=>$T['collaborators'],
                    'entries'=>$T['entries'],
                    'mergeType'=>Ticket::getMergeTypeByFlag($T['flags']),
                    'children'=>count(Ticket::getChildTickets($T['ticket_id'])) > 0 ? true : false,
                    'matches'=>$_REQUEST['q']);
            }
            else {
                $tickets[$email] = array('email'=>$email, 'value'=>$email,
                    'info'=>"$email ($count)", 'matches'=>$_REQUEST['q']);
            }
        }
        $tickets = array_values($tickets);

        return $this->json_encode($tickets);
    }

function lookupByNumber($limit=false, $visibility=false, $matches=false) {
        global $thisstaff;

        if (!$limit)
            $limit = isset($_REQUEST['limit']) ? (int) $_REQUEST['limit']:25;

        $tickets=array();
        // Bail out of query is empty
        if (!$_REQUEST['q'])
            return $this->json_encode($tickets);

        $q = trim($_REQUEST['q']);

        if (!$visibility)
            $visibility = $thisstaff->getTicketsVisibility();

        $hits = Ticket::objects()
            ->values('user__default_email__address', 'cdata__subject', 'user__name', 'ticket_id', 'thread__id', 'flags', 'number')
            ->annotate(array(
                'tickets' => new SqlCode('1'),
                'tasks' => SqlAggregate::COUNT('tasks__id', true),
                'collaborators' => SqlAggregate::COUNT('thread__collaborators__id', true),
                'entries' => SqlAggregate::COUNT('thread__entries__id', true),
            ))
            ->filter($visibility)
            ->filter(array('number__startswith' => $q))
            ->order_by('number')
            ->limit($limit);

        if ($matches && is_a($matches, 'QuerySet'))
            return $hits->union($matches);

        foreach ($hits as $T) {
            $email = $T['user__default_email__address'];
            $tickets[$T['number']] = array('id'=>$T['number'], 'value'=>$T['number'],
                'ticket_id'=>$T['ticket_id'],
                'info'=>"{$T['number']} — {$email}",
                'subject'=>$T['cdata__subject'],
                'user'=>$T['user__name'],
                'tasks'=>$T['tasks'],
                'thread_id'=>$T['thread__id'],
                'collaborators'=>$T['collaborators'],
                'entries'=>$T['entries'],
                'mergeType'=>Ticket::getMergeTypeByFlag($T['flags']),
                'children'=>count(Ticket::getChildTickets($T['ticket_id'])) > 0 ? true : false,
                'matches'=>$_REQUEST['q']);
        }
        $tickets = array_values($tickets);

        return $this->json_encode($tickets);
    }

function lookupByEmail($limit=false, $visibility=false, $matches=false) {
        global $thisstaff;

        if (!$limit)
            $limit = isset($_REQUEST['limit']) ? (int) $_REQUEST['limit']:25;

        $tickets=array();
        // Bail out of query is empty
        if (!$_REQUEST['q'])
            return $this->json_encode($tickets);

        $q = trim($_REQUEST['q']);

        if (!$visibility)
            $visibility = $thisstaff->getTicketsVisibility();

        $hits = Ticket::objects()
            ->values('user__default_email__address', 'cdata__subject', 'user__name', 'ticket_id', 'thread__id', 'flags')
            ->annotate(array(
                'number' => new SqlCode('null'),
                'tickets' => SqlAggregate::COUNT('ticket_id', true),
                'tasks' => SqlAggregate::COUNT('tasks__id', true),
                'collaborators' => SqlAggregate::COUNT('thread__collaborators__id', true),
                'entries' => SqlAggregate::COUNT('thread__entries__id', true),
            ))
            ->filter($visibility)
            ->filter(array('user__default_email__address__contains' => $q))
            ->order_by('user__default_email__address')
            ->limit($limit);

        if ($matches && is_a($matches, 'QuerySet'))
            return $hits->union($matches);

        foreach ($hits as $T) {
            $count = $T['tickets'];
            $email = $T['user__default_email__address'];
            $tickets[$email] = array('email'=>$email, 'value'=>$email,
                'info'=>"$email ($count)", 'matches'=>$_REQUEST['q']);
        }
        $tickets = array_values($tickets);

        return $this->json_encode($tickets);
    }

function previewTicket ($tid) {
        global $thisstaff;

        if(!$thisstaff || !($ticket=Ticket::lookup($tid))
                || !$ticket->checkStaffPerm($thisstaff))
            Http::response(404, __('No such ticket'));

        include STAFFINC_DIR . 'templates/ticket-preview.tmpl.php';
    }

function viewUser($tid) {
        global $thisstaff;

        if(!$thisstaff
                || !($ticket=Ticket::lookup($tid))
                || !$ticket->checkStaffPerm($thisstaff))
            Http::response(404, 'No such ticket');


        if(!($user = User::lookup($ticket->getOwnerId())))
            Http::response(404, 'Unknown user');


        $info = array(
            'title' => sprintf(__('Ticket #%s: %s'), $ticket->getNumber(),
                Format::htmlchars($user->getName()))
            );

        ob_start();
        include(STAFFINC_DIR . 'templates/user.tmpl.php');
        $resp = ob_get_contents();
        ob_end_clean();
        return $resp;

    }

function updateUser($tid) {

        global $thisstaff;

        if(!$thisstaff
                || !($ticket=Ticket::lookup($tid))
                || !$ticket->checkStaffPerm($thisstaff)
                || !($user = User::lookup($ticket->getOwnerId())))
            Http::response(404, 'No such ticket/user');

        $errors = array();
        if($user->updateInfo($_POST, $errors, true))
             Http::response(201, $user->to_json());

        $forms = $user->getForms();

        $info = array(
            'title' => sprintf(__('Ticket #%s: %s'), $ticket->getNumber(),
                Format::htmlchars($user->getName()))
            );

        ob_start();
        include(STAFFINC_DIR . 'templates/user.tmpl.php');
        $resp = ob_get_contents();
        ob_end_clean();
        return $resp;
    }

function changeUserForm($tid) {
        global $thisstaff;

        if(!$thisstaff
                || !($ticket=Ticket::lookup($tid))
                || !$ticket->checkStaffPerm($thisstaff))
            Http::response(404, 'No such ticket');


        $user = User::lookup($ticket->getOwnerId());

        $info = array(
                'title' => sprintf(__('Change user for ticket #%s'), $ticket->getNumber())
                );

        return self::_userlookup($user, null, $info);
    }

static function _userlookup($user, $form, $info) {
        global $thisstaff;

        ob_start();
        include(STAFFINC_DIR . 'templates/user-lookup.tmpl.php');
        $resp = ob_get_contents();
        ob_end_clean();
        return $resp;

    }
}
