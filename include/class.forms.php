<?php
/*********************************************************************
    class.forms.php

    osTicket forms framework

    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once(INCLUDE_DIR . "class.json.php");

/* Familias del marco de formularios: cada una en su archivo, en orden de
   dependencia (el campo base antes que sus hijas). */
include_once(INCLUDE_DIR . 'class.forms.core.php');
include_once(INCLUDE_DIR . 'class.forms.field.php');
include_once(INCLUDE_DIR . 'class.forms.fields.php');
include_once(INCLUDE_DIR . 'class.forms.datetime.php');
include_once(INCLUDE_DIR . 'class.forms.ticket.php');
include_once(INCLUDE_DIR . 'class.forms.file.php');
include_once(INCLUDE_DIR . 'class.forms.inline.php');

FormField::addFieldTypes(/*@trans*/ 'Dynamic Fields', function() {
    return array(
        'priority' => array(__('Priority Level'), 'PriorityField'),
    );
});
FormField::addFieldTypes(/*@trans*/ 'Dynamic Fields', function() {
    return array(
        'department' => array(__('Department'), 'DepartmentField'),
    );
});
FormField::addFieldTypes(/*@trans*/ 'Dynamic Fields', function() {
    return array(
        'assignee' => array(__('Assignee'), 'AssigneeField'),
    );
});
FormField::addFieldTypes('Dynamic Fields', function() {
    return array(
        'state' => array('Ticket State', 'TicketStateField', false),
    );
});

FormField::addFieldTypes('Dynamic Fields', function() {
    return array(
        'flags' => array('Ticket Flags', 'TicketFlagField', false),
    );
});

/* Widgets de campo: ver class.forms.widget.php */
include_once(INCLUDE_DIR . 'class.forms.widget.php');

/* Formularios de asignación: ver class.forms.assignment.php */
include_once(INCLUDE_DIR . 'class.forms.assignment.php');
