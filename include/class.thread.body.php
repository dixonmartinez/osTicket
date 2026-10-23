<?php
/*********************************************************************
    class.thread.body.php

    Cuerpo de una entrada del hilo (texto y HTML) y las tres formas de
    entrada: mensaje, respuesta y nota.

    Extraído de class.thread.php (frente 6 de docs/CODE_QUALITY.md). Se
    incluye desde class.thread.php después de ThreadEntry, porque las tres
    clases de entrada heredan de ella.

    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/


class ThreadEntryBody /* extends SplString */ {

    static $types = array('text', 'html');

    var $body;
    var $type;
    var $stripped_images = array();
    var $embedded_images = array();
    var $options = array(
        'strip-embedded' => true
    );

    function __construct($body, $type='text', $options=array()) {
        $type = strtolower($type);
        if (!in_array($type, static::$types))
            throw new Exception("$type: Unsupported ThreadEntryBody type");
        $this->body = (string) $body;
        if (strlen($this->body) > 250000) {
            $max_packet = db_get_variable('max_allowed_packet', 'global');
            // Truncate just short of the max_allowed_packet
            $this->body = substr($this->body, 0, $max_packet - 2048) . ' ... '
               . _S('(truncated)');
        }
        $this->type = $type;
        $this->options = array_merge($this->options, $options);
    }

    function isEmpty() {
        return !$this->body || $this->body == '-';
    }

    function convertTo($type) {
        if ($type === $this->type)
            return $this;

        $conv = $this->type . ':' . strtolower($type);
        switch ($conv) {
        case 'text:html':
            return new ThreadEntryBody(sprintf('<pre>%s</pre>',
                Format::htmlchars($this->body)), $type);
        case 'html:text':
            return new ThreadEntryBody(Format::html2text((string) $this), $type);
        }
    }

    function stripQuotedReply($tag) {

        //Strip quoted reply...on emailed  messages
        if (!$tag || strpos($this->body, $tag) === false)
            return;

        // Capture a list of inline images
        $images_before = $images_after = array();
        preg_match_all('/src=("|\'|\b)cid:(\S+)\1/', $this->body, $images_before,
            PREG_PATTERN_ORDER);

        // Strip the quoted part of the body
        if ((list($msg) = explode($tag, $this->body, 2)) && trim($msg)) {
            $this->body = $msg;

            // Capture a list of dropped inline images
            if ($images_before) {
                preg_match_all('/src=("|\'|\b)cid:(\S+)\1/', $this->body,
                    $images_after, PREG_PATTERN_ORDER);
                $this->stripped_images = array_diff($images_before[2],
                    $images_after[2]);
            }
        }
    }

    function getStrippedImages() {
        return $this->stripped_images;
    }

    function getEmbeddedHtmlImages() {
        return $this->embedded_images;
    }

    function getType() {
        return $this->type;
    }

    function getClean() {
        switch ($this->type) {
        case 'html':
            return trim($this->body, " <>br/\t\n\r") ? $this->body: '';
        case 'text':
            return trim($this->body) ? $this->body: '';
        default:
            return trim($this->body);
        }
    }

    function __toString() {
        return (string) $this->body;
    }

    function toHtml() {
        return $this->display('html');
    }

    function prepend($what) {
        $this->body = $what . $this->body;
    }

    function append($what) {
        $this->body .= $what;
    }

    function asVar() {
        // Email template, assume HTML
        return $this->display('email');
    }

    function display($format=false) {
        throw new Exception('display: Abstract display() method not implemented');
    }

    function getSearchable() {
        return Format::searchable($this->body);
    }

    static function fromFormattedText($text, $format=false, $options=array()) {
        switch ($format) {
        case 'text':
            return new TextThreadEntryBody($text);
        case 'html':
            return new HtmlThreadEntryBody($text, array('strip-embedded'=>false) + $options);
        default:
            return new ThreadEntryBody($text);
        }
    }

    static function clean($text, $format=null) {
        global $cfg;
        $format = $format ?: ($cfg->isRichTextEnabled() ? 'html' : 'text');
        $body = static::fromFormattedText($text, $format);
        return $body->getClean();
    }
}

class TextThreadEntryBody extends ThreadEntryBody {
    function __construct($body, $options=array()) {
        parent::__construct($body, 'text', $options);
    }

    function getClean() {
        return Format::htmlchars(Format::html_balance(Format::stripEmptyLines(parent::getClean())));
    }

    function prepend($what) {
        $this->body = $what . "\n\n" . $this->body;
    }

    function display($output=false) {
        if ($this->isEmpty())
            return '(empty)';

        $escaped = Format::htmlchars($this->body);
        switch ($output) {
        case 'html':
            return '<div style="white-space:pre-wrap">'
                .Format::clickableurls($escaped).'</div>';
        case 'email':
            return '<div style="white-space:pre-wrap">'
                .$escaped.'</div>';
        case 'pdf':
            return nl2br($escaped);
        default:
            return '<pre>'.$escaped.'</pre>';
        }
    }
}
class HtmlThreadEntryBody extends ThreadEntryBody {
    function __construct($body, $options=array()) {
        if (!isset($options['strip-embedded']) || $options['strip-embedded'])
            $body = $this->extractEmbeddedHtmlImages($body);
        parent::__construct($body, 'html', $options);
    }

    function extractEmbeddedHtmlImages($body) {
        $self = $this;
        return preg_replace_callback('/src="(data:[^"]+)"/',
        function ($m) use ($self) {
            $info = Format::parseRfc2397($m[1], false, false);
            $info['cid'] = 'img'.Misc::randCode(12);
            list(,$type) = explode('/', $info['type'], 2);
            $info['name'] = 'image'.Misc::randCode(4).'.'.$type;
            $self->embedded_images[] = $info;
            return 'src="cid:'.$info['cid'].'"';
        }, $body);
    }

    function getClean() {
        global $thisclient, $thisstaff;

        $clean = ($thisstaff || $thisclient)
                ? Format::editor_spacing(parent::getClean())
                : parent::getClean();

        return Format::sanitize($clean);
    }

    function getSearchable() {
        // Replace tag chars with spaces (to ensure words are separated)
        $body = Format::html($this->body, array('hook_tag' => function($el, $attributes=0) {
            static $non_ws = array('wbr' => 1);
            return (isset($non_ws[$el])) ? '' : ' ';
        }));
        // Collapse multiple white-spaces
        $body = html_entity_decode($body, ENT_QUOTES);
        $body = preg_replace('`\s+`u', ' ', $body);
        return Format::searchable($body);
    }

    function prepend($what) {
        $this->body = sprintf('<div>%s<br/><br/></div>%s', $what, $this->body);
    }

    function display($output=false) {
        if ($this->isEmpty())
            return '(empty)';

        switch ($output) {
        case 'email':
            return $this->body;
        case 'pdf':
            return Format::clickableurls(Format::stripExternalImages($this->body, true));
        default:
            return Format::display($this->body, true, !$this->options['balanced']);
        }
    }
}


/* Message - Ticket thread entry of type message */
class MessageThreadEntry extends ThreadEntry {

    const ENTRY_TYPE = 'M';

    function getSubject() {
        return $this->getTitle();
    }

    static function add($vars, &$errors=array()) {

        if (!$vars || !is_array($vars) || !$vars['threadId'])
            $errors['err'] = __('Missing or invalid data');
        elseif (!$vars['message'])
            $errors['message'] = __('Message content is required');

        if ($errors) return false;

        $vars['type'] = self::ENTRY_TYPE;
        $vars['body'] = $vars['message'];

        if (!$vars['poster']
                && $vars['userId']
                && ($user = User::lookup($vars['userId'])))
            $vars['poster'] = (string) $user->getName();

        return parent::add($vars);
    }

    static function getVarScope() {
        $base = parent::getVarScope();
        unset($base['staff']);
        return $base;
    }
}

/* thread entry of type response */
class ResponseThreadEntry extends ThreadEntry {

    const ENTRY_TYPE = 'R';

    function getActivity() {
        return new ThreadActivity(
                _S('New Response'),
                _S('New response posted'));
    }

    function getSubject() {
        return $this->getTitle();
    }

    function getRespondent() {
        return $this->getStaff();
    }

    static function add($vars, &$errors=array()) {

        if (!$vars || !is_array($vars) || !$vars['threadId'])
            $errors['err'] = __('Missing or invalid data');
        elseif (!$vars['response'])
            $errors['response'] = __('Response content is required');

        if ($errors) return false;

        $vars['type'] = self::ENTRY_TYPE;
        $vars['body'] = $vars['response'];
        if (!$vars['pid'] && $vars['msgId'])
            $vars['pid'] = $vars['msgId'];

        if (!$vars['poster']
                && $vars['staffId']
                && ($staff = Staff::lookup($vars['staffId'])))
            $vars['poster'] = (string) $staff->getName();

        return parent::add($vars);
    }

    static function getVarScope() {
        $base = parent::getVarScope();
        unset($base['user']);
        return $base;
    }
}

/* Thread entry of type note (Internal Note) */
class NoteThreadEntry extends ThreadEntry {
    const ENTRY_TYPE = 'N';

    function getMessage() {
        return $this->getBody();
    }

    function getActivity() {
        return new ThreadActivity(
                _S('New Internal Note'),
                _S('New internal note posted'));
    }

    static function add($vars, &$errors=array()) {

        //Check required params.
        if (!$vars || !is_array($vars) || !$vars['threadId'])
            $errors['err'] = __('Missing or invalid data');
        elseif (!$vars['note'])
            $errors['note'] = __('Note content is required');

        if ($errors) return false;

        //TODO: use array_intersect_key  when we move to php 5 to extract just what we need.
        $vars['type'] = self::ENTRY_TYPE;
        $vars['body'] = $vars['note'];

        return parent::add($vars);
    }

    static function getVarScope() {
        $base = parent::getVarScope();
        unset($base['user']);
        return $base;
    }
}
