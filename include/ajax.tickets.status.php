<?php
/*********************************************************************
    ajax.tickets.status.php

    Cambiar el estado de un ticket y marcarlo (cerrar, abrir, borrar,
    marcar como duplicado o como respondido): los cuatro metodos del panel
    que comparten el mismo formulario de estado.

    Es un rasgo de TicketsAjaxAPI y no una clase porque son metodos de la
    misma API: usan `$thisstaff`, `Http::response()` y los ayudantes de
    AjaxController. Se sacaron de include/ajax.tickets.php (frente 6 de
    docs/CODE_QUALITY.md) moviendo los metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

if(!defined('INCLUDE_DIR')) die('403');

trait TicketsAjaxStatus {

    function changeTicketStatus($tid, $status, $id=0) {
        global $thisstaff;

        if (!$thisstaff)
            Http::response(403, 'Access denied');
        elseif (!$tid
                || !($ticket=Ticket::lookup($tid))
                || !$ticket->checkStaffPerm($thisstaff))
            Http::response(404, 'Unknown ticket #');

        $role = $ticket->getRole($thisstaff);

        $info = array();
        $state = null;
        switch($status) {
            case 'open':
            case 'reopen':
                $state = 'open';
                break;
            case 'close':
                if (!$role->hasPerm(Ticket::PERM_CLOSE))
                    Http::response(403, 'Access denied');
                $state = 'closed';

                // Check if ticket is closeable
                if (is_string($closeable=$ticket->isCloseable()))
                    $info['warn'] =  $closeable;

                break;
            case 'delete':
                if (!$role->hasPerm(Ticket::PERM_DELETE))
                    Http::response(403, 'Access denied');
                $state = 'deleted';
                break;
            default:
                $state = $ticket->getStatus()->getState();
                $info['warn'] = sprintf(__('%s: Unknown or invalid'),
                        __('status'));
        }

        $info['status_id'] = $id ?: $ticket->getStatusId();

        return self::_changeTicketStatus($ticket, $state, $info);
    }

    function setTicketStatus($tid) {
        global $thisstaff, $ost;

        if (!$thisstaff)
            Http::response(403, 'Access denied');
        elseif (!$tid
                || !($ticket=Ticket::lookup($tid))
                || !$ticket->checkStaffPerm($thisstaff))
            Http::response(404, 'Unknown ticket #');

        $errors = $info = array();
        if (!$_POST['status_id']
                || !($status= TicketStatus::lookup($_POST['status_id'])))
            $errors['status_id'] = sprintf('%s %s',
                    __('Unknown or invalid'), __('status'));
        elseif ($status->getId() == $ticket->getStatusId())
            $errors['err'] = sprintf(__('Ticket already set to %s status'),
                    __($status->getName()));
        elseif (($role = $ticket->getRole($thisstaff))) {
            // Make sure the agent has permission to set the status
            switch(mb_strtolower($status->getState())) {
                case 'open':
                    if (!$role->hasPerm(Ticket::PERM_CLOSE)
                            && !$role->hasPerm(Ticket::PERM_CREATE))
                        $errors['err'] = sprintf(__('You do not have permission %s'),
                                __('to reopen tickets'));
                    break;
                case 'closed':
                    if (!$role->hasPerm(Ticket::PERM_CLOSE))
                        $errors['err'] = sprintf(__('You do not have permission %s'),
                                __('to resolve/close tickets'));
                    break;
                case 'deleted':
                    if (!$role->hasPerm(Ticket::PERM_DELETE))
                        $errors['err'] = sprintf(__('You do not have permission %s'),
                                __('to archive/delete tickets'));
                    break;
                default:
                    $errors['err'] = sprintf('%s %s',
                            __('Unknown or invalid'), __('status'));
            }
        } else {
            $errors['err'] = __('Access denied');
        }

        $state = strtolower($status->getState());

        if (!$errors && $ticket->setStatus($status, $_REQUEST['comments'], $errors)) {
            $failures = array();
            // Set children statuses (if applicable)
            if ($_REQUEST['children']) {
                $children = $ticket->getChildren();

                foreach ($children as $cid) {
                    $child = Ticket::lookup($cid[0]);
                    if (!$child->setStatus($status, '', $errors))
                        $failures[$cid[0]] = $child->getNumber();
                }
            }

            if (!$failures) {
                if ($state == 'deleted') {
                    $msg = sprintf('%s %s',
                            sprintf(__('Ticket #%s'), $ticket->getNumber()),
                            __('deleted sucessfully')
                            );
                } elseif ($state != 'open') {
                     $msg = sprintf(__('%s status changed to %s'),
                             sprintf(__('Ticket #%s'), $ticket->getNumber()),
                             $status->getName());
                } else {
                    $msg = sprintf(
                            __('%s status changed to %s'),
                            __('Ticket'),
                            $status->getName());
                }
            } else {
                $tickets = array();
                foreach ($failures as $id=>$num) {
                    $tickets[] = sprintf('<a href="tickets.php?id=%d"><b>#%s</b></a>',
                                    $id,
                                    $num);
                }
                $info['warn'] = sprintf(__('Error updating ticket status for %s'),
                                 ($tickets) ? implode(', ', $tickets) : __('child tickets')
                                 );
            }
            $_SESSION['::sysmsgs']['msg'] = $msg;

            Http::response(201, 'Successfully processed');
        } elseif (!$errors['err']) {
            $errors['err'] =  __('Error updating ticket status');
        }

        $state = $state ?: $ticket->getStatus()->getState();
        $info['status_id'] = $status
            ? $status->getId() : $ticket->getStatusId();

        return self::_changeTicketStatus($ticket, $state, $info, $errors);
    }

    function changeSelectedTicketsStatus($status, $id=0) {
        global $thisstaff, $cfg;

        if (!$thisstaff)
            Http::response(403, 'Access denied');

        $state = null;
        $info = array();
        switch($status) {
            case 'open':
            case 'reopen':
                $state = 'open';
                break;
            case 'close':
                if (!$thisstaff->hasPerm(Ticket::PERM_CLOSE, false))
                    Http::response(403, 'Access denied');
                $state = 'closed';
                break;
            case 'delete':
                if (!$thisstaff->hasPerm(Ticket::PERM_DELETE, false))
                    Http::response(403, 'Access denied');

                $state = 'deleted';
                break;
            default:
                $info['warn'] = sprintf('%s %s',
                        __('Unknown or invalid'), __('status'));
        }

        $info['status_id'] = $id;

        return self::_changeSelectedTicketsStatus($state, $info);
    }

    function setSelectedTicketsStatus($state) {
        global $thisstaff, $ost;

        $errors = $info = array();
        if (!$thisstaff || !$thisstaff->canManageTickets())
            $errors['err'] = sprintf('%s %s',
                    sprintf(__('You do not have permission %s'),
                        __('to mass manage tickets')),
                    __('Contact admin for such access'));
        elseif (!$_REQUEST['tids'] || !count($_REQUEST['tids']))
            $errors['err']=sprintf(__('You must select at least %s.'),
                    __('one ticket'));
        elseif (!($status= TicketStatus::lookup($_REQUEST['status_id'])))
            $errors['status_id'] = sprintf('%s %s',
                    __('Unknown or invalid'), __('status'));
        elseif (!$errors) {
            // Make sure the agent has permission to set the status
            switch(mb_strtolower($status->getState())) {
                case 'open':
                    if (!$thisstaff->hasPerm(Ticket::PERM_CLOSE, false)
                            && !$thisstaff->hasPerm(Ticket::PERM_CREATE, false))
                        $errors['err'] = sprintf(__('You do not have permission %s'),
                                __('to reopen tickets'));
                    break;
                case 'closed':
                    if (!$thisstaff->hasPerm(Ticket::PERM_CLOSE, false))
                        $errors['err'] = sprintf(__('You do not have permission %s'),
                                __('to resolve/close tickets'));
                    break;
                case 'deleted':
                    if (!$thisstaff->hasPerm(Ticket::PERM_DELETE, false))
                        $errors['err'] = sprintf(__('You do not have permission %s'),
                                __('to archive/delete tickets'));
                    break;
                default:
                    $errors['err'] = sprintf('%s %s',
                            __('Unknown or invalid'), __('status'));
            }
        }

        $count = count($_REQUEST['tids']);
        if (!$errors) {
            $i = 0;
            $comments = $_REQUEST['comments'];
            foreach ($_REQUEST['tids'] as $tid) {

                if (($ticket=Ticket::lookup($tid))
                        && $ticket->getStatusId() != $status->getId()
                        && $ticket->checkStaffPerm($thisstaff)
                        && $ticket->setStatus($status, $comments, $errors))
                    $i++;
            }

            if (!$i) {
                $errors['err'] = $errors['err']
                    ?: sprintf(__('Unable to change status for %s'),
                        _N('selected ticket', 'selected tickets', $count));
            }
            else {
                // Assume success
                if ($i==$count) {

                    if (!strcasecmp($status->getState(), 'deleted')) {
                        $msg = sprintf(__( 'Successfully deleted %s.'),
                                _N('selected ticket', 'selected tickets',
                                    $count));
                    } else {
                       $msg = sprintf(
                            __(
                                /* 1$ will be 'selected ticket(s)', 2$ is the new status */
                                'Successfully changed status of %1$s to %2$s'),
                            _N('selected ticket', 'selected tickets',
                                $count),
                            $status->getName());
                    }

                    $_SESSION['::sysmsgs']['msg'] = $msg;
                } else {

                    if (!strcasecmp($status->getState(), 'deleted')) {
                        $warn = sprintf(__('Successfully deleted %s.'),
                                sprintf(__('%1$d of %2$d selected tickets'),
                                    $i, $count)
                                );
                    } else {

                        $warn = sprintf(
                                __('%1$d of %2$d %3$s status changed to %4$s'),$i, $count,
                                _N('selected ticket', 'selected tickets',
                                    $count),
                                $status->getName());
                    }

                    $_SESSION['::sysmsgs']['warn'] = $warn;
                }

                Http::response(201, 'Successfully processed');
            }
        }

        return self::_changeSelectedTicketsStatus($state, $info, $errors);
    }

    function markAs($tid, $action='') {
        global $thisstaff;

        // Standard validation
        if (!($ticket=Ticket::lookup($tid)))
            Http::response(404, __('No such ticket'));

        if (!$ticket->checkStaffPerm($thisstaff, Ticket::PERM_MARKANSWERED) && !$thisstaff->isManager($ticket->getDept()))
            Http::response(403, __('Permission denied'));

        $errors = array();
        $info = array(':title' => __('Please Confirm'));

        // Instantiate form for comment field
        $form = MarkAsForm::instantiate($_POST);

        // Mark as answered or unanswered
        if ($_POST) {
            switch($action) {
                case 'answered':
                    if($ticket->isAnswered())
                        $errors['err'] = __('Ticket is already marked as answered');
                    elseif (!$ticket->markAnswered())
                        $errors['err'] = __('Cannot mark ticket as answered');
                    break;

                case 'unanswered':
                    if(!$ticket->isAnswered())
                        $errors['err'] = __('Ticket is already marked as unanswered');
                    elseif (!$ticket->markUnAnswered())
                        $errors['err'] - __('Cannot mark ticket as unanswered');
                    break;

                default:
                    Http::response(404, __('Unknown action'));
            }

            // Retrun errors to form (if any)
            if($errors) {
                $info['error'] = $errors['err'] ?: sprintf(__('Unable to mark ticket as %s'), $action);
                $form->addErrors($errors);
            } else {
                // Add comment (if provided)
                $comments = $form->getComments();
                if ($comments) {
                    $title = __(sprintf('Ticket Marked %s', ucfirst($action)));
                    $_errors = array();

                    $ticket->postNote(
                        array('note' => $comments, 'title' => $title),
                        $_errors, $thisstaff, false);
                }

                // Add success messages and log activity
                $_SESSION['::sysmsgs']['msg'] = sprintf(__('Ticket marked as %s successfully'), $action);
                $msg = sprintf(__('Ticket flagged as %s by %s'), $action, $thisstaff->getName());
                $ticket->logActivity(sprintf(__('Ticket Marked %s'), ucfirst($action)), $msg);
                Http::response(201, $ticket->getId());
            }
        }

        include STAFFINC_DIR . 'templates/mark-as.tmpl.php';
    }
}
