<?php
/*********************************************************************
    class.template.php

    Email Template

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require_once INCLUDE_DIR.'class.i18n.php';
require_once INCLUDE_DIR.'class.yaml.php';
// Este archivo define dos modelos del ORM, así que declara la dependencia.
// (`Dept` y `Attachment` se usan en `load()` y `delete()`: los carga el resto
// de la aplicación, y arrastrarlos aquí metería `class.ticket.php` —que a su
// vez incluye este archivo— en el arranque.)
require_once INCLUDE_DIR.'class.orm.php';

/**
 * Una fila de `EMAIL_TEMPLATE_GRP_TABLE`.
 *
 * `EmailTemplateGroup` no es un modelo de fila —es la clase que compone y
 * cachea las plantillas— sino el grupo cargado desde disco. Este modelo
 * permite consultar los grupos con el ORM en lugar de escribir el SELECT.
 */
class EmailTemplateGroupModel extends VerySimpleModel {
    static $meta = array(
        'table' => EMAIL_TEMPLATE_GRP_TABLE,
        'pk' => array('tpl_id'),
        'joins' => array(
            // Departamentos que usan el conjunto. `null` => LEFT JOIN, para
            // que los conjuntos sin departamentos también salgan en la lista.
            'depts' => array(
                'constraint' => array('tpl_id' => 'Dept.tpl_id'),
                'null' => true,
            ),
        ),
    );
}

/**
 * Una fila de `EMAIL_TEMPLATE_TABLE`.
 *
 * `EmailTemplate` no es un modelo de fila —compone el asunto y el cuerpo,
 * resuelve variables y adjuntos— así que necesita su propio modelo para
 * consultar sin escribir el SQL.
 */
class EmailTemplateModel extends VerySimpleModel {
    static $meta = array(
        'table' => EMAIL_TEMPLATE_TABLE,
        'pk' => array('id'),
    );
}

class EmailTemplateGroup {

    var $id;
    var $ht;
    var $_templates;
    static $all_groups = array(
        'sys' => /* @trans */ 'System Management Templates',
        'a.ticket.user' => /* @trans */ 'Ticket End-User Email Templates',
        'b.ticket.staff' => /* @trans */ 'Ticket Agent Email Templates',
        'c.task' => /* @trans */ 'Task Email Templates',
    );
    static $all_names=array(
        'ticket.autoresp'=>array(
            'group'=>'a.ticket.user',
            'name'=>/* @trans */ 'New Ticket Auto-response',
            'desc'=>/* @trans */ 'Autoresponse sent to user, if enabled, on new ticket.',
            'context' => array(
                'ticket', 'signature', 'message', 'recipient'
            ),
        ),
        'ticket.autoreply'=>array(
            'group'=>'a.ticket.user',
            'name'=>/* @trans */ 'New Ticket Auto-reply',
            'desc'=>/* @trans */ 'Canned Auto-reply sent to user on new ticket, based on filter matches. Overwrites "normal" auto-response.',
            'context' => array(
                'ticket', 'signature', 'response', 'recipient',
            ),
        ),
        'message.autoresp'=>array(
            'group'=>'a.ticket.user',
            'name'=>/* @trans */ 'New Message Auto-response',
            'desc'=>/* @trans */ 'Confirmation sent to user when a new message is appended to an existing ticket.',
            'context' => array(
                'ticket', 'signature', 'recipient',
            ),
        ),
        'ticket.notice'=>array(
            'group'=>'a.ticket.user',
            'name'=>/* @trans */ 'New Ticket Notice',
            'desc'=>/* @trans */ 'Notice sent to user, if enabled, on new ticket created by an agent on their behalf (e.g phone calls).',
            'context' => array(
                'ticket', 'signature', 'recipient', 'staff', 'message',
            ),
        ),
        'ticket.overlimit'=>array(
            'group'=>'a.ticket.user',
            'name'=>/* @trans */ 'Overlimit Notice',
            'desc'=>/* @trans */ 'A one-time notice sent, if enabled, when user has reached the maximum allowed open tickets.',
            'context' => array(
                'ticket', 'signature',
            ),
        ),
        'ticket.reply'=>array(
            'group'=>'a.ticket.user',
            'name'=>/* @trans */ 'Response/Reply Template',
            'desc'=>/* @trans */ 'Template used on ticket response/reply',
            'context' => array(
                'ticket', 'signature', 'response', 'staff', 'poster', 'recipient',
            ),
        ),
        'ticket.activity.notice'=>array(
            'group'=>'a.ticket.user',
            'name'=>/* @trans */ 'New Activity Notice',
            'desc'=>/* @trans */ 'Template used to notify collaborators on ticket activity (e.g CC on reply)',
            'context' => array(
                'ticket', 'signature', 'message', 'poster', 'recipient',
            ),
        ),
        'ticket.alert'=>array(
            'group'=>'b.ticket.staff',
            'name'=>/* @trans */ 'New Ticket Alert',
            'desc'=>/* @trans */ 'Alert sent to agents, if enabled, on new ticket.',
            'context' => array(
                'ticket', 'recipient', 'message',
            ),
        ),
        'message.alert'=>array(
            'group'=>'b.ticket.staff',
            'name'=>/* @trans */ 'New Message Alert',
            'desc'=>/* @trans */ 'Alert sent to agents, if enabled, when user replies to an existing ticket.',
            'context' => array(
                'ticket', 'recipient', 'message', 'poster',
            ),
        ),
        'note.alert'=>array(
            'group'=>'b.ticket.staff',
            'name'=>/* @trans */ 'Internal Activity Alert',
            'desc'=>/* @trans */ 'Alert sent out to Agents when internal activity such as an internal note or an agent reply is appended to a ticket.',
            'context' => array(
                'ticket', 'recipient', 'note', 'comments', 'activity',
            ),
        ),
        'assigned.alert'=>array(
            'group'=>'b.ticket.staff',
            'name'=>/* @trans */ 'Ticket Assignment Alert',
            'desc'=>/* @trans */ 'Alert sent to agents on ticket assignment.',
            'context' => array(
                'ticket', 'recipient', 'comments', 'assignee', 'assigner',
            ),
        ),
        'transfer.alert'=>array(
            'group'=>'b.ticket.staff',
            'name'=>/* @trans */ 'Ticket Transfer Alert',
            'desc'=>/* @trans */ 'Alert sent to agents on ticket transfer.',
            'context' => array(
                'ticket', 'recipient', 'comments', 'staff',
            ),
        ),
        'ticket.overdue'=>array(
            'group'=>'b.ticket.staff',
            'name'=>/* @trans */ 'Overdue Ticket Alert',
            'desc'=>/* @trans */ 'Alert sent to agents on stale or overdue tickets.',
            'context' => array(
                'ticket', 'recipient', 'comments',
            ),
        ),
        'task.alert' => array(
            'group'=>'c.task',
            'name'=>/* @trans */ 'New Task Alert',
            'desc'=>/* @trans */ 'Alert sent to agents, if enabled, on new task.',
            'context' => array(
                'task', 'recipient', 'message',
            ),
        ),
        'task.activity.notice' => array(
            'group'=>'c.task',
            'name'=>/* @trans */ 'New Activity Notice',
            'desc'=>/* @trans */ 'Template used to notify collaborators on task activity.',
            'context' => array(
                'task', 'signature', 'message', 'poster', 'recipient',
            ),
        ),
        'task.activity.alert'=>array(
            'group'=>'c.task',
            'name'=>/* @trans */ 'New Activity Alert',
            'desc'=>/* @trans */ 'Alert sent to selected agents, if enabled, on new activity.',
            'context' => array(
                'task', 'recipient', 'note', 'comments', 'activity',
            ),
        ),
        'task.assignment.alert' => array(
            'group'=>'c.task',
            'name'=>/* @trans */ 'Task Assignment Alert',
            'desc'=>/* @trans */ 'Alert sent to agents on task assignment.',
            'context' => array(
                'task', 'recipient', 'comments', 'assignee', 'assigner',
            ),
        ),
        'task.transfer.alert'=>array(
            'group'=>'c.task',
            'name'=>/* @trans */ 'Task Transfer Alert',
            'desc'=>/* @trans */ 'Alert sent to agents on task transfer.',
            'context' => array(
                'task', 'recipient', 'note', 'comments', 'activity',
            ),
        ),
        'task.overdue.alert'=>array(
            'group'=>'c.task',
            'name'=>/* @trans */ 'Overdue Task Alert',
            'desc'=>/* @trans */ 'Alert sent to agents on stale or overdue task.',
            'context' => array(
                'task', 'recipient', 'comments',
            ),
        ),
    );

    function __construct($id=0){
        $this->id=0;
        $this->load($id);
    }

    function load($id) {

        if(!$id && !($id=$this->getId()))
            return false;

        if (!($tpl = EmailTemplateGroupModel::lookup($id)))
            return false;

        // El número de departamentos con este conjunto: `load()` lo guarda en
        // `$ht['depts']` y `isInUse()` lo consulta.
        $this->ht = $tpl->getDbFields();
        $this->ht['depts'] = Dept::objects()
            ->filter(array('tpl_id' => $this->ht['tpl_id']))
            ->count();
        $this->id = $this->ht['tpl_id'];

        return true;
    }

    function reload() {
        return $this->load($this->getId());
    }

    function getId(){
        return $this->id;
    }

    function getName(){
        return $this->ht['name'];
    }

    function getNotes(){
        return $this->ht['notes'];
    }

    function isEnabled() {
         return ($this->ht['isactive']);
    }

    function isActive(){
        return $this->isEnabled();
    }

    function getLanguage() {
        return $this->ht['lang'];
    }

    function isInUse(){
        global $cfg;

        return ($this->ht['depts'] || ($cfg && $this->getId()==$cfg->getDefaultTemplateId()));
    }

    function getHashtable() {
        return $this->ht;
    }

    function getInfo() {
        return $this->getHashtable();
    }

    function setStatus($status){
        /* `update()` devuelve las filas que cambiaron de verdad, así que
           activar un conjunto que ya estaba activo da 0 aunque el UPDATE se
           haya ejecutado. `db_affected_rows()` hacía lo mismo, de ahí que se
           compruebe el resultado con la fila ya en memoria. */
        EmailTemplateGroupModel::objects()
            ->filter(array('tpl_id' => $this->getId()))
            ->update(array(
                'updated' => SqlFunction::NOW(),
                'isactive' => $status ? 1 : 0,
            ));

        return true;
    }

    static function getTemplateDescription($name) {
        return static::$all_names[$name];
    }

    function getMsgTemplate($name) {
        global $ost;

        if ($tpl=EmailTemplate::lookupByName($this->getId(), $name, $this))
            return $tpl;

        if ($tpl=EmailTemplate::fromInitialData($name, $this))
            return $tpl;

        $ost->logWarning(_S('Template Fetch Error'),
            sprintf(_S('Unable to fetch "%1$s" template - id #%d'), $name, $this->getId()));
        return false;
    }

    function getTemplates() {
        /* Se reconstruye en cada llamada, como antes: la caché que había
           estaba mal escrita (`$this->_tempates`, con una letra de menos), así
           que nunca se usó. Cambiarla por una caché de verdad alteraría el
           caso de añadir una plantilla y volver a pedir la lista con el mismo
           objeto, y eso no toca hacerlo aquí. */
        $this->_templates = array();
        $templates = EmailTemplateModel::objects()
            ->filter(array('tpl_id' => $this->getId()))
            ->values_flat('id', 'code_name')
            ->order_by('code_name');
        foreach ($templates as list($id, $cn))
            $this->_templates[$cn] = EmailTemplate::lookup($id, $this);

        return $this->_templates;
    }

    function getUndefinedTemplateNames() {
        $list = static::$all_names;
        foreach ($this->getTemplates() as $cn=>$tpl)
            unset($list[$cn]);
        return $list;
    }


    function getNewTicketAlertMsgTemplate() {
        return $this->getMsgTemplate('ticket.alert');
    }

    function getNewMessageAlertMsgTemplate() {
        return $this->getMsgTemplate('message.alert');
    }

    function getNewTicketNoticeMsgTemplate() {
        return $this->getMsgTemplate('ticket.notice');
    }

    function getNewMessageAutorepMsgTemplate() {
        return $this->getMsgTemplate('message.autoresp');
    }

    function getAutoRespMsgTemplate() {
        return $this->getMsgTemplate('ticket.autoresp');
    }

    function getAutoReplyMsgTemplate() {
        return $this->getMsgTemplate('ticket.autoreply');
    }

    function getReplyMsgTemplate() {
        return $this->getMsgTemplate('ticket.reply');
    }

    function  getActivityNoticeMsgTemplate() {
        return $this->getMsgTemplate('ticket.activity.notice');
    }

    function getOverlimitMsgTemplate() {
        return $this->getMsgTemplate('ticket.overlimit');
    }

    function getNoteAlertMsgTemplate() {
        return $this->getMsgTemplate('note.alert');
    }

    function getTransferAlertMsgTemplate() {
        return $this->getMsgTemplate('transfer.alert');
    }

    function getAssignedAlertMsgTemplate() {
        return $this->getMsgTemplate('assigned.alert');
    }

    function getOverdueAlertMsgTemplate() {
        return $this->getMsgTemplate('ticket.overdue');
    }

    /* Tasks templates */
    function getNewTaskAlertMsgTemplate() {
        return $this->getMsgTemplate('task.alert');
    }

    function  getTaskActivityAlertMsgTemplate() {
        return $this->getMsgTemplate('task.activity.alert');
    }

    function  getTaskActivityNoticeMsgTemplate() {
        return $this->getMsgTemplate('task.activity.notice');
    }

    function getTaskTransferAlertMsgTemplate() {
        return $this->getMsgTemplate('task.transfer.alert');
    }

    function getTaskAssignmentAlertMsgTemplate() {
        return $this->getMsgTemplate('task.assignment.alert');
    }

    function getTaskOverdueAlertMsgTemplate() {
        return $this->getMsgTemplate('task.overdue.alert');
    }

    function update($vars,&$errors) {
        if(!$vars['isactive'] && $this->isInUse())
            $errors['isactive']=__('In-use template set cannot be disabled!');

        if(!$this->save($this->getId(),$vars,$errors))
            return false;

        $this->reload();

        return true;
    }

    function enable(){
        return ($this->setStatus(1));
    }

    function disable(){
        return (!$this->isInUse() && $this->setStatus(0));
    }

    function delete(){
        global $cfg;

        if($this->isInUse() || $cfg->getDefaultTemplateId()==$this->getId())
            return 0;

        $num = EmailTemplateGroupModel::objects()
            ->filter(array('tpl_id' => $this->getId()))
            ->delete();
        if ($num) {
            //isInuse check is enough - but it doesn't hurt make sure deleted tpl is not in-use.
            Dept::objects()
                ->filter(array('tpl_id' => $this->getId()))
                ->update(array('tpl_id' => 0));

            // Drop attachments (images) de las plantillas del conjunto
            $templates = array();
            foreach (EmailTemplateModel::objects()
                    ->filter(array('tpl_id' => $this->getId()))
                    ->values_flat('id') as list($id))
                $templates[] = $id;

            if ($templates)
                Attachment::objects()
                    ->filter(array('type' => 'T', 'object_id__in' => $templates))
                    ->delete();

            EmailTemplateModel::objects()
                ->filter(array('tpl_id' => $this->getId()))
                ->delete();
        }

        $type = array('type' => 'deleted');
        Signal::send('object.deleted', $this, $type);

        return $num;
    }

    static function create($vars,&$errors) {
        $group = new static();
        return $group->save(0,$vars,$errors);
    }

    static function add($vars, &$errors) {
        return self::lookup(self::create($vars, $errors));
    }

    function getIdByName($name){
        $ids = EmailTemplateGroupModel::objects()
            ->filter(array('name' => $name))
            ->values_flat('tpl_id')
            ->limit(1);

        foreach ($ids as list($id))
            return $id;

        return null;
    }

    static function lookup($id){
        return ($id && is_numeric($id) && ($t= new EmailTemplateGroup($id)) && $t->getId()==$id)?$t:null;
    }

    function save($id, $vars, &$errors) {
        global $ost;

        $tpl=null;
        $vars['name']=Format::striptags(trim($vars['name']));

        if($id && $id!=$vars['tpl_id'])
            $errors['err']=__('Internal error occurred');

        if(!$vars['name'])
            $errors['name']=__('Name is required');
        elseif(($tid=EmailTemplateGroup::getIdByName($vars['name'])) && $tid!=$id)
            $errors['name']=__('Template name already exists');

        if(!$id && ($vars['tpl_id'] && !($tpl=EmailTemplateGroup::lookup($vars['tpl_id']))))
            $errors['tpl_id']=__('Invalid template set specified');

        if($errors) return false;

        foreach ($vars as $key => $value) {
            if ($id && isset($this->ht[$key]) && ($this->ht[$key] != $value)) {
                $type = array('type' => 'edited', 'key' => $key);
                Signal::send('object.edited', $this, $type);
            }
        }

        $fields = array(
            'updated' => SqlFunction::NOW(),
            'name' => $vars['name'],
            'isactive' => $vars['isactive'] ?? 0,
            'notes' => Format::sanitize($vars['notes']),
        );

        if (!empty($vars['lang_id']))
            // TODO: Validation of lang_id
            $fields['lang'] = $vars['lang_id'];

        if($id) {
            try {
                EmailTemplateGroupModel::objects()
                    ->filter(array('tpl_id' => $id))
                    ->update($fields);
            }
            catch (OrmException $e) {
                $errors['err']=sprintf(__('Unable to update %s.'), __('this template set'))
                   .' '.__('Internal error occurred');
                return false;
            }

            return true;
        }

        $group = new EmailTemplateGroupModel();
        $group->created = SqlFunction::NOW();
        if (isset($vars['id']))
            $group->tpl_id = $vars['id'];
        foreach ($fields as $field => $value)
            $group->set($field, $value);

        if (!$group->save() || !($new_id = $group->tpl_id)) {
            $errors['err']=sprintf(__('Unable to create %s.'), __('this template set'))
               .' '.__('Internal error occurred');
            return false;
        }

        if ($tpl && $tpl->getId()) {
            // Copia de las plantillas del conjunto de origen
            $source = EmailTemplateModel::objects()
                ->filter(array('tpl_id' => $tpl->getId()))
                ->values_flat('code_name', 'subject', 'body');
            foreach ($source as list($code_name, $subject, $body)) {
                $copy = new EmailTemplateModel();
                $copy->created = SqlFunction::NOW();
                $copy->updated = SqlFunction::NOW();
                $copy->tpl_id = $new_id;
                $copy->code_name = $code_name;
                $copy->subject = $subject;
                $copy->body = $body;
                if (!$copy->save())
                    return false;
            }
        }

        return $new_id;
    }
}

class EmailTemplate {

    var $id;
    var $ht;
    var $_group;

    function __construct($id=0, $group=null){
        $this->id=0;
        if ($id) $this->load($id);
        if ($group) $this->_group = $group;
    }

    function load($id) {

        if(!$id && !($id=$this->getId()))
            return false;

        if (!($tpl = EmailTemplateModel::lookup($id)))
            return false;

        $this->ht = $tpl->getDbFields();
        $this->id = $this->ht['id'];
        $this->attachments = GenericAttachments::forIdAndType($this->id, 'T');

        return true;
    }

    function reload() {
        return $this->load($this->getId());
    }

    function getId(){
        return $this->id;
    }

    function asArray() {
        return array(
            'id' => $this->getId(),
            'subj' => $this->getSubject(),
            'body' => $this->getBody(),
        );
    }

    function getSubject() {
        return $this->ht['subject'];
    }

    function getBody() {
        return $this->ht['body'];
    }

    function getBodyWithImages() {
        return Format::viewableImages($this->getBody());
    }
    function getCodeName() {
        return $this->ht['code_name'];
    }

    function getLastUpdated() {
        return $this->ht['updated'];
    }

    function getTplId() {
        return $this->ht['tpl_id'];
    }

    function getGroup() {
        if (!isset($this->_group))
            $this->_group = EmailTemplateGroup::lookup($this->getTplId());
        return $this->_group;

    }

    function getDescription() {
        return $this->getGroup()->getTemplateDescription($this->ht['code_name']);
    }

    function getInvalidVariableUsage() {
        $context = VariableReplacer::getContextForRoot($this->ht['code_name']);
        $invalid = array();
        foreach (array($this->getSubject(), $this->getBody()) as $B) {
            $variables = array();
            if (!preg_match_all('`%\{([^}]*)\}`', $B, $variables, PREG_SET_ORDER))
                continue;
            foreach ($variables as $V) {
                if (!isset($context[$V[1]])) {
                    $invalid[] = $V[0];
                }
            }
        }
        return $invalid;
    }

    function update($vars, &$errors) {

        if(!$this->save($this->getId(),$vars,$errors))
            return false;

        $this->reload();

        // Inline images (attached to the draft)
        $keepers = Draft::getAttachmentIds($this->getBody());
        // Just keep the IDs only
        $keepers = array_map(function($i) { return $i['id']; }, $keepers);
        $this->attachments->keepOnlyFileIds($keepers, true);

        return true;
    }

    function save($id, $vars, &$errors) {
        if(!$vars['subject'])
            $errors['subject'] = __('Message subject is required');

        if(!$vars['body'])
            $errors['body'] = __('Message body is required');

        if (!$id) {
            if (!$vars['tpl_id'])
                $errors['tpl_id'] = __('Template set is required');
            if (!$vars['code_name'])
                $errors['code_name'] = __('Code name is required');
        }

        if ($errors)
            return false;

        $vars['body'] = Format::sanitize($vars['body'], false);

        if ($id) {
            foreach ($vars as $key => $value) {
                if (isset($this->ht[$key]) && ($this->ht[$key] != $value)) {
                    $type = array('type' => 'edited', 'key' => $this->getCodeName());
                    Signal::send('object.edited', $this->getGroup(), $type);
                }
            }
            try {
                EmailTemplateModel::objects()
                    ->filter(array('id' => $this->getId()))
                    ->update(array(
                        'updated' => SqlFunction::NOW(),
                        'subject' => $vars['subject'],
                        'body' => $vars['body'],
                    ));
            }
            catch (OrmException $e) {
                return false;
            }

            return true;
        } else {
            $template = new EmailTemplateModel();
            $template->created = SqlFunction::NOW();
            $template->updated = SqlFunction::NOW();
            $template->setAll(array(
                'tpl_id' => $vars['tpl_id'],
                'code_name' => $vars['code_name'],
                'subject' => $vars['subject'],
                'body' => $vars['body'],
            ));

            if ($template->save() && ($id = $template->id)) {
                $template = EmailTemplate::lookup($id);
                foreach ($vars as $key => $value) {
                    if (isset($template->ht[$key]) && ($template->ht[$key] != $value)) {
                        $type = array('type' => 'edited', 'key' => $template->getCodeName());
                        Signal::send('object.edited', $template->getGroup(), $type);
                    }
                }
                return $id;
            }
        }
        return null;
    }

    static function create($vars, &$errors) {
        $template = new static();
        return $template->save(0, $vars, $errors);
    }

    static function add($vars, &$errors) {
        $inst = self::lookup(self::create($vars, $errors));

        // Inline images (attached to the draft)
        if ($inst)
            $inst->attachments->upload(Draft::getAttachmentIds($inst->getBody()), true);

        return $inst;
    }

    static function lookupByName($tpl_id, $name, $group=null) {
        $ids = EmailTemplateModel::objects()
            ->filter(array('tpl_id' => $tpl_id, 'code_name' => $name))
            ->values_flat('id')
            ->limit(1);

        foreach ($ids as list($id))
            return self::lookup($id, $group);

        return false;
    }

    static function lookup($id, $group=null) {
        return ($id && is_numeric($id) && ($t= new EmailTemplate($id, $group)) && $t->getId()==$id)?$t:null;
    }

    /**
     * Load the template from the initial_data directory. The format of the
     * file should be free flow text. The first line is the subject and the
     * rest of the file is the body.
     */
    static function fromInitialData($name, $group=null) {
        $templ = new EmailTemplate(0, $group);
        $lang = ($group) ? $group->getLanguage() : 'en_US';
        $i18n = new Internationalization($lang);
        if ((!($tpl = $i18n->getTemplate("templates/email/$name.yaml")))
                || (!($info = $tpl->getData())))
            return false;
        if (isset($info['subject']) && isset($info['body'])) {
            $templ->ht = $info;
            return $templ;
        }
        raise_error("$lang/templates/$name.yaml: "
            . _S('Email templates must define both "subject" and "body" parts of the template'),
            'InitialDataError');
        return false;
    }
}
?>
