<?php
/*********************************************************************
    ajax.tasks.php

    AJAX interface for tasks

    Peter Rotich <peter@osticket.com>
    Copyright (c)  20014 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

if(!defined('INCLUDE_DIR')) die('403');

include_once(INCLUDE_DIR.'class.ticket.php');
include_once(INCLUDE_DIR.'class.massaction.php');
require_once(INCLUDE_DIR.'class.ajax.php');
require_once(INCLUDE_DIR.'class.task.php');
include_once INCLUDE_DIR . 'class.thread_actions.php';

class TasksAjaxAPI extends AjaxController {

    function lookup() {
        global $thisstaff;

        $limit = Http::requestInt('limit', 25);
        $query = Http::request('q');
        $tasks = array();

        $visibility = Q::any(array(
            'staff_id' => $thisstaff->getId(),
            'team_id__in' => $thisstaff->teams->values_flat('team_id'),
        ));

        if (!$thisstaff->showAssignedOnly() && ($depts=$thisstaff->getDepts())) {
            $visibility->add(array('dept_id__in' => $depts));
        }


        $hits = TaskModel::objects()
            ->filter(Q::any(array(
                'number__startswith' => $query,
            )))
            ->filter($visibility)
            ->values('number')
            ->annotate(array('tasks' => SqlAggregate::COUNT('id')))
            ->order_by('-created')
            ->limit($limit);

        foreach ($hits as $T) {
            $tasks[] = array('id'=>$T['number'], 'value'=>$T['number'],
                'info'=>"{$T['number']}",
                'matches'=>$query);
        }

        return $this->json_encode($tasks);
    }

    function triggerThreadAction($task_id, $thread_id, $action) {
        global $thisstaff;

        if (!($task = Task::lookup($task_id))
                || !$task->checkStaffPerm($thisstaff))
            Http::response(404, 'No such task thread entry');

        $thread = ThreadEntry::lookup($thread_id);
        if (!$thread)
            Http::response(404, 'No such task thread entry');
        if ($thread->getThread()->getObjectType() != 'A'
                || $thread->getThread()->getObjectId() != $task_id)
            Http::response(404, 'No such task thread entry');

        $valid = false;
        foreach ($thread->getActions() as $group=>$list) {
            foreach ($list as $name=>$A) {
                if ($A->getId() == $action) {
                    $valid = true; break;
                }
            }
        }

        if (!$valid)
          Http::response(400, 'Not a valid action for this thread');


        $thread->triggerAction($action);
    }

    function add($tid=0, $vars=array()) {
        global $thisstaff;

        if ($tid) {
            $vars = array_merge($_SESSION[':form-data'], $vars);
            $originalTask = Task::lookup($tid);
        }
        else
          unset($_SESSION[':form-data']);

        $info=$errors=array();
        if ($_POST) {
            Draft::deleteForNamespace('task.add', $thisstaff->getId());
            // Default form
            $form = TaskForm::getInstance();
            $form->setSource($_POST);
            // Internal form
            $iform = TaskForm::getInternalForm($_POST);
            $isvalid = true;
            if (!$iform->isValid())
                $isvalid = false;
            if (!$form->isValid())
                $isvalid = false;

            if ($isvalid) {
                $vars = $_POST;
                $vars['default_formdata'] = $form->getClean();
                $vars['internal_formdata'] = $iform->getClean();
                $desc = $form->getField('description');
                $vars['description'] = $desc->getClean();
                if ($desc
                        && $desc->isAttachmentsEnabled()
                        && ($attachments=$desc->getWidget()->getAttachments()))
                            $vars['files'] = $attachments->getFiles();
                $vars['staffId'] = $thisstaff->getId();
                $vars['poster'] = $thisstaff;
                $vars['ip_address'] = $_SERVER['REMOTE_ADDR'];
                if (($task=Task::create($vars, $errors))) {
                  if ($_SESSION[':form-data']['eid']) {
                    //add internal note to original task:
                    $taskLink = sprintf('<a href="tasks.php?id=%d"><b>#%s</b></a>',
                        $task->getId(),
                        $task->getNumber());

                    $entryLink = sprintf('<a href="#entry-%d"><b>%s</b></a>',
                        $_SESSION[':form-data']['eid'],
                        Format::datetime($_SESSION[':form-data']['timestamp']));

                    $note = array(
                            'title' => __('Task Created From Thread Entry'),
                            'note' => sprintf(__(
                                // %1$s is the task ID number and %2$s is the thread
                                // entry date
                                'Task %1$s<br/> Thread Entry: %2$s'),
                                $taskLink, $entryLink)
                            );

                    $originalTask->postNote($note, $errors, $thisstaff);

                    //add internal note to new task:
                    $taskLink = sprintf('<a href="tasks.php?id=%d"><b>#%s</b></a>',
                        $originalTask->getId(),
                        $originalTask->getNumber());

                    $note = array(
                            'title' => __('Task Created From Thread Entry'),
                            'note' => sprintf(__('This Task was created from Task %1$s'), $taskLink));

                    $task->postNote($note, $errors, $thisstaff);
                  }

                  Http::response(201, $task->getId());
                }
              }
              $info['error'] = sprintf('%s - %s', __('Error adding task'), __('Please try again!'));
            }

        if ($originalTask) {
          $info['action'] = sprintf('#tasks/%d/add', $originalTask->getId());
          $info['title'] = sprintf(
                  __( 'Task #%1$s: %2$s'),
                  $originalTask->getNumber(),
                  __('Add New Task')
                  );
        }

        include STAFFINC_DIR . 'templates/task.tmpl.php';
    }


    function preview($tid) {
        global $thisstaff;

        // No perm. check -- preview allowed for staff
        // XXX: perhaps force preview via parent object?
        if(!$thisstaff || !($task=Task::lookup($tid)))
            Http::response(404, __('No such task'));

        include STAFFINC_DIR . 'templates/task-preview.tmpl.php';
    }

    function edit($tid) {
        global $thisstaff;

        if(!($task=Task::lookup($tid)))
            Http::response(404, __('No such task'));

        if (!$task->checkStaffPerm($thisstaff, Task::PERM_EDIT))
            Http::response(403, __('Permission denied'));

        $info = $errors = array();
        $forms = DynamicFormEntry::forObject($task->getId(),
                ObjectModel::OBJECT_TYPE_TASK);

        if ($_POST && $forms) {
            // TODO: Validate internal form

            // Validate dynamic meta-data
            if ($task->update($forms, $_POST, $errors)) {
                Http::response(201, 'Task updated successfully');
            } elseif(!$errors['err']) {
                $errors['err'] = sprintf('%s %s',
                    sprintf(__('Unable to update %s.'), __('this task')),
                    __('Correct any errors below and try again.'));
            }
            $info = Format::htmlchars($_POST);
        }

        include STAFFINC_DIR . 'templates/task-edit.tmpl.php';
    }

    function editField($tid, $fid) {
        global $thisstaff;

        if (!($task=Task::lookup($tid)))
            Http::response(404, __('No such task'));
        elseif (!$task->checkStaffPerm($thisstaff, Task::PERM_EDIT))
            Http::response(403, __('Permission denied'));
        elseif (!($field=$task->getField($fid)))
            Http::response(404, __('No such field'));
        $errors = array();
        $info = array(
                ':title' => sprintf(__('Task #%s: %s %s'),
                  $task->getNumber(),
                  __('Update'),
                  $field->getLabel()
                  ),
              ':action' => sprintf('#tasks/%d/field/%s/edit',
                  $task->getId(), $field->getId())
              );

        $form = $field->getEditForm($_POST);
        if ($_POST && $form->isValid()) {

            if ($task->updateField($form, $errors)) {
                $msg = sprintf(
                      __('%s successfully'),
                      sprintf(
                          __('%s updated'),
                          __($field->getLabel())
                          )
                      );

                switch (true) {
                    case $field instanceof DateTime:
                    case $field instanceof DatetimeField:
                        $clean = Format::datetime((string) $field->getClean());
                        break;
                    case $field instanceof FileUploadField:
                        $field->save();
                        $answer =  $field->getAnswer();
                        $clean = $answer->display() ?: '&mdash;' . __('Empty') .  '&mdash;';
                        break;
                    case $field instanceof DepartmentField:
                        $clean = (string) Dept::lookup($field->getClean());
                        break;
                    case $field instanceof TextareaField:
                        $clean =  (string) $field->getClean();
                        $clean = Format::striptags($clean) ? $clean : '&mdash;' . __('Empty') .  '&mdash;';
                        if (strlen($clean) > 200)
                             $clean = Format::truncate($clean, 200);
                        break;
                    case $field instanceof BooleanField:
                        $clean = $field->getClean();
                        $clean = $field->toString($clean);
                        break;
                    default:
                        $clean =  $field->getClean();
                        $clean = is_array($clean) ? implode(',', $clean) :
                            (string) $clean;
                        if (strlen($clean) > 200)
                             $clean = Format::truncate($clean, 200);
                }

                $clean = is_array($clean) ? $clean[0] : $clean;
                Http::response(201, $this->json_encode(['value' =>
                            $clean ?: '&mdash;' . __('Empty') .  '&mdash;',
                            'id' => $fid, 'msg' => $msg]));
            }

            $form->addErrors($errors);
            $info['error'] = $errors['err'] ?: __('Unable to update field');
        }

        include STAFFINC_DIR . 'templates/field-edit.tmpl.php';
    }

    function massProcess($action, $w=null)  {
        global $thisstaff, $cfg;

        // Tabla de acciones y textos: ver MassAction
        $actions = MassAction::actions('task');

        if (!isset($actions[$action]))
            Http::response(404, __('Unknown action'));


        $info = $errors = $e = array();
        $inc = null;
        $i = 0;
        // Cuántos se han seleccionado y aviso si no hay ninguno
        $selection = MassAction::selection($_POST, $_REQUEST, 'task');
        $count = $selection['count'];
        if ($selection['error'])
            $errors['err'] = $selection['error'];
        switch ($action) {
        case 'claim':
            $w = 'me';
        case 'assign':
            $inc = 'assign.tmpl.php';
            // Textos del diálogo: ver MassAction::assignPrompt()
            $info = array_merge($info, MassAction::assignPrompt('task', $w, $count));

            $form = AssignmentForm::instantiate($_POST);

            $assignCB = function($t, $f, $e) {
                return $t->assign($f, $e);
            };

            $assignees = $prompt = null;
            switch ($w) {
            case 'agents':
                $tids = MassAction::ids($_POST, $_POST, $_REQUEST);
                $depts = $tids
                    ? Task::objects()
                        ->distinct('dept_id')
                        ->filter(array('id__in' => $tids))
                        ->values_flat('dept_id')
                    : array();
                // Agentes a los que se les puede asignar lo seleccionado
                $agent = MassAction::memberOptions(
                    MassAction::taskAgentMembers($depts,
                        $cfg->getAgentNameFormat()));
                break;
            case 'teams':
                $agent = MassAction::teamAssignees(Team::getActiveTeams());
                break;
            case 'me':
                $info = array_merge($info,
                    MassAction::assignPrompt('task', 'me', $count));
                $verb = sprintf('%s, %s', __('Yes'), __('Claim'));
                $id = sprintf('s%s', $thisstaff->getId());
                $assignees = array($id => $thisstaff->getName());
                $vars = $_POST ?: array('assignee' => array($id));
                $form = ClaimForm::instantiate($vars);
                $assignCB = function($t, $f, $e) {
                    return $t->claim($f, $e);
                };
                break;
            }

            if (isset($agent)) {
                $assignees = $agent['assignees'];
                $prompt = $agent['prompt'];
                if ($agent['warn'])
                    $info['warn'] = $agent['warn'];
            }

            if ($assignees != null)
                $form->setAssignees($assignees);

            if ($prompt && ($f=$form->getField('assignee')))
                $f->configure('prompt', $prompt);

            if ($_POST && $form->isValid() && !$errors) {
                foreach ($_POST['tids'] as $tid) {
                    if (($t=Task::lookup($tid))
                            // Make sure the agent is allowed to
                            // access and assign the task.
                            && $t->checkStaffPerm($thisstaff, Task::PERM_ASSIGN)
                            // Do the assignment
                            && $assignCB($t, $form, $e)
                            )
                        $i++;
                }

                if (!$i)
                    $info['error'] = MassAction::failure('task', 'assign', $count);
            }
            break;
        case 'transfer':
            $inc = 'transfer.tmpl.php';
            $info = array_merge($info, MassAction::confirm('task', $action, $count));
            $form = TransferForm::instantiate($_POST);
            $form->hideDisabled();
            if ($_POST && $form->isValid()) {
                foreach ($_POST['tids'] as $tid) {
                    if (($t=Task::lookup($tid))
                            // Make sure the agent is allowed to
                            // access and transfer the task.
                            && $t->checkStaffPerm($thisstaff, Task::PERM_TRANSFER)
                            // Do the transfer
                            && $t->transfer($form, $e)
                            )
                        $i++;
                }

                if (!$i)
                    $info['error'] = MassAction::failure('task', 'transfer', $count);
            }
            break;
        case 'reopen':
        case 'close':
            $inc = 'task-status.tmpl.php';
            $info['status'] = MassAction::statusFor($action);
            $perm = '';
            switch ($info['status']) {
            case 'open':
                // If an agent can create a task then they're allowed to
                // reopen closed ones.
                $perm = Task::PERM_CREATE;
                break;
            case 'closed':
                $perm = Task::PERM_CLOSE;
                break;
            default:
                Http::response(404, __('Unknown action'));
            }
            $info = array_merge($info, MassAction::confirm('task', $action, $count));
            // Check generic permissions --  department specific permissions
            // will be checked below.
            if ($perm && !$thisstaff->hasPerm($perm, false))
                $errors['err'] = sprintf(
                        __('You do not have permission to %s tasks'
                            /* %s will be an action verb */ ),
                        __($action));

            if ($_POST && !$errors) {
                $newStatus = Http::post('status');
                if (!$newStatus
                        || !in_array($newStatus, array('open', 'closed')))
                    $errors['status'] = __('Status selection required');
                else {
                    foreach (Http::post('tids', array()) as $tid) {
                        if (($t=Task::lookup($tid))
                                && $t->checkStaffPerm($thisstaff, $perm ?: null)
                                && $t->setStatus($newStatus,
                                    Http::post('comments'))
                                )
                            $i++;
                    }

                    if (!$i)
                        $info['error'] = MassAction::failure('task', $action, $count);
                }
            }
            break;
        case 'delete':
            $inc = 'delete.tmpl.php';
            $info = array_merge($info, MassAction::confirm('task', $action, $count));

            if ($_POST && !$errors) {
                foreach (Http::post('tids', array()) as $tid) {
                    if (($t=Task::lookup($tid))
                            && $t->getDeptId() != Http::post('dept_id')
                            && $t->checkStaffPerm($thisstaff, Task::PERM_DELETE)
                            && $t->delete($_POST, $e)
                            )
                        $i++;
                }

                if (!$i)
                    $info['error'] = MassAction::failure('task', 'delete', $count);
            }
            break;
        default:
            Http::response(404, __('Unknown action'));
        }


        // Mensaje de resultado, aviso y campos devueltos: ver MassAction
        $info = MassAction::finish('task', $action, $count, $i, $info, $errors,
            $actions[$action]['verbed']);

        include STAFFINC_DIR . "templates/$inc";
        //  Copy checked tasks to the form.
        echo MassAction::selectedScript('tasks');
    }

    function transfer($tid) {
        global $thisstaff;

        if(!($task=Task::lookup($tid)))
            Http::response(404, __('No such task'));

        if (!$task->checkStaffPerm($thisstaff, Task::PERM_TRANSFER))
            Http::response(403, __('Permission denied'));

        $errors = array();

        $info = array(
                ':title' => sprintf(__('Task #%s: %s'),
                    $task->getNumber(),
                    __('Transfer')),
                ':action' => sprintf('#tasks/%d/transfer',
                    $task->getId())
                );

        $form = $task->getTransferForm($_POST);
        $form->hideDisabled();

        if ($_POST && $form->isValid()) {
            if ($task->transfer($form, $errors)) {
                $_SESSION['::sysmsgs']['msg'] = sprintf(
                        __('%s successfully'),
                        sprintf(
                            __('%s transferred to %s department'),
                            __('Task'),
                            $task->getDept()
                            )
                        );
                Http::response(201, $task->getId());
            }

            $form->addErrors($errors);
            $info['error'] = $errors['err'] ?: __('Unable to transfer task');
        }

        $info['dept_id'] = $info['dept_id'] ?: $task->getDeptId();

        include STAFFINC_DIR . 'templates/transfer.tmpl.php';
    }

    function assign($tid, $target=null) {
        global $thisstaff;

        if (!($task=Task::lookup($tid)))
            Http::response(404, __('No such task'));

        if (!$task->checkStaffPerm($thisstaff, Task::PERM_ASSIGN)
                || !($form=$task->getAssignmentForm($_POST, array(
                            'target' => $target))))
            Http::response(403, __('Permission denied'));

        $errors = array();
        $info = array(
                ':title' => sprintf(__('Task #%s: %s'),
                    $task->getNumber(),
                    $task->isAssigned() ? __('Reassign') :  __('Assign')),
                ':action' => sprintf('#tasks/%d/assign%s',
                    $task->getId(),
                    $target ? "/$target" : ''),
                );
        if ($task->isAssigned()) {
            $info['notice'] = sprintf(__('%s is currently assigned to <b>%s</b>'),
                    __('Task'),
                    $task->getAssigned());
        }

        if ($_POST && $form->isValid()) {
            if ($task->assign($form, $errors)) {
                $_SESSION['::sysmsgs']['msg'] = sprintf(
                        __('%s successfully'),
                        sprintf(
                            __('%s assigned to %s'),
                            __('Task'),
                            $form->getAssignee())
                        );

                $assignee =  $task->isAssigned() ? Format::htmlchars(implode('/', $task->getAssignees())) :
                                            '<span class="faded">&mdash; '.__('Unassigned').' &mdash;';
                Http::response(201, $this->json_encode(['value' =>
                    $assignee, 'id' => 'assign', 'msg' => $_SESSION['::sysmsgs']['msg']]));
            }

            $form->addErrors($errors);
            $info['error'] = $errors['err'] ?: __('Unable to assign task');
        }

        include STAFFINC_DIR . 'templates/assign.tmpl.php';
    }

    function claim($tid) {

        global $thisstaff;

        if (!($task=Task::lookup($tid)))
            Http::response(404, __('No such task'));

        // Check for premissions and such
        if (!$task->checkStaffPerm($thisstaff, Task::PERM_ASSIGN)
                || !($form = $task->getClaimForm($_POST)))
            Http::response(403, __('Permission denied'));

        $errors = array();
        $info = array(
                ':title' => sprintf(__('Task #%s: %s'),
                    $task->getNumber(),
                    __('Claim')),
                ':action' => sprintf('#tasks/%d/claim',
                    $task->getId()),

                );

        if ($task->isAssigned()) {
            if ($task->getStaffId() == $thisstaff->getId())
                $assigned = __('you');
            else
                $assigneed = $task->getAssigned();

            $info['error'] = sprintf(__('%s is currently assigned to <b>%s</b>'),
                    __('This task'),
                    $assigned);
        } else {
            $info['warn'] = sprintf(__('Are you sure you want to CLAIM %s?'),
                    __('this task'));
        }

        if ($_POST && $form->isValid()) {
            if ($task->claim($form, $errors)) {
                $_SESSION['::sysmsgs']['msg'] = sprintf(
                        __('%s successfully'),
                        sprintf(
                            __('%s assigned to %s'),
                            __('Task'),
                            __('you'))
                        );
                Http::response(201, $task->getId());
            }

            $form->addErrors($errors);
            $info['error'] = $errors['err'] ?: __('Unable to claim task');
        }

        $verb = sprintf('%s, %s', __('Yes'), __('Claim'));

        include STAFFINC_DIR . 'templates/assign.tmpl.php';

    }

   function delete($tid) {
        global $thisstaff;

        if(!($task=Task::lookup($tid)))
            Http::response(404, __('No such task'));

        if (!$task->checkStaffPerm($thisstaff, Task::PERM_DELETE))
            Http::response(403, __('Permission denied'));

        $errors = array();
        $info = array(
                ':title' => sprintf(__('Task #%s: %s'),
                    $task->getNumber(),
                    __('Delete')),
                ':action' => sprintf('#tasks/%d/delete',
                    $task->getId()),
                );

        if ($_POST) {
            if ($task->delete($_POST,  $errors)) {
                $_SESSION['::sysmsgs']['msg'] = sprintf(
                            __('%s #%s deleted successfully'),
                            __('Task'),
                            $task->getNumber(),
                            $task->getDept());
                Http::response(201, 0);
            }
            $info = array_merge($info, Format::htmlchars($_POST));
            $info['error'] = $errors['err'] ?: __('Unable to delete task');
        }
        $info[':placeholder'] = sprintf(__(
                    'Optional reason for deleting %s'),
                __('this task'));
        $info['warn'] = sprintf(__(
                    'Are you sure you want to DELETE %s?'),
                    __('this task'));
        $info[':extra'] = sprintf('<strong>%s</strong>',
                    __('Deleted tasks CANNOT be recovered, including any associated attachments.')
                    );

        include STAFFINC_DIR . 'templates/delete.tmpl.php';
    }

   function changeStatus($tid, $status) {
        global $thisstaff;
        $statuses = array(
                'open' => __('Reopen'),
                'closed' => __('Close'),
                );

        if(!($task=Task::lookup($tid)) || !$task->checkStaffPerm($thisstaff))
            Http::response(404, __('No such task'));

        $perm = null;
        $info = $errors = array();
        switch ($status) {
        case 'open':
            $perm = Task::PERM_CREATE;
            $info = array(
                    ':title' => sprintf(__('Reopen Task #%s'),
                        $task->getNumber()),
                    ':action' => sprintf('#tasks/%d/reopen',
                        $task->getId())
                    );
            break;
        case 'closed':
            $perm = Task::PERM_CLOSE;
            $info = array(
                    ':title' => sprintf(__('Close Task #%s'),
                        $task->getNumber()),
                    ':action' => sprintf('#tasks/%d/close',
                        $task->getId())
                    );

            if (($m=$task->isCloseable()) !== true)
                $errors['err'] = $info['error'] = $m;
            else
                $info['warn'] = sprintf(__('Are you sure you want to change status of %s?'),
                        __('this task'));
            break;
        default:
            Http::response(404, __('Unknown status'));
        }

        if (!$errors && (!$perm || !$task->checkStaffPerm($thisstaff, $perm)))
            $errors['err'] = sprintf(
                        __('You do not have permission to %s tasks'),
                        $statuses[$status]);

        if ($_POST && !$errors) {
            if ($task->setStatus($status, $_POST['comments'], $errors))
                Http::response(201, 0);

            $info['error'] = $errors['err'] ?: __('Unable to change status of the task');
        }

        $info['status'] = $status;

        include STAFFINC_DIR . 'templates/task-status.tmpl.php';
   }

   function reopen($tid) {
       return $this->changeStatus($tid, 'open');
   }

   function close($tid) {
       return $this->changeStatus($tid, 'closed');
   }

    function task($tid) {
        global $thisstaff;

        if (!($task=Task::lookup($tid))
                || !$task->checkStaffPerm($thisstaff))
            Http::response(404, __('No such task'));

        $info=$errors=array();
        $note_form = new SimpleForm(array(
            'attachments' => new FileUploadField(array('id'=>'attach',
            'name'=>'attach:note',
            'configuration' => array('extensions'=>'')))
            ));

        if ($_POST) {

            switch ($_POST['a']) {
            case 'postnote':
                $vars = $_POST;
                $attachments = $note_form->getField('attachments')->getFiles();
                $vars['files'] = array_merge(
                    $vars['files'] ?: array(), $attachments);
                if(($note=$task->postNote($vars, $errors, $thisstaff))) {
                    $msg=__('Note posted successfully');
                    // Clear attachment list
                    $note_form->setSource(array());
                    $note_form->getField('attachments')->reset();
                    Draft::deleteForNamespace('task.note.'.$task->getId(),
                            $thisstaff->getId());
                } else {
                    if(!$errors['err'])
                        $errors['err'] = __('Unable to post the note - missing or invalid data.');
                }
                break;
            default:
                $errors['err'] = __('Unknown action');
            }
        }

        include STAFFINC_DIR . 'templates/task-view.tmpl.php';
    }
}
?>
