<?php
/*********************************************************************
    class.validator.php

    Input validation helper. This class contains collection of functions used for data validation.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

// Tabla de reglas de tipo de validate() (frente 2)
require_once dirname(__FILE__).'/class.validator.rule.php';

class Validator {

    var $input=array();
    var $fields=array();
    var $errors=array();

    function __construct($fields=null) {
        $this->setFields($fields);
    }
    function setFields(&$fields){

        if($fields && is_array($fields)):
            $this->fields=$fields;
            return (true);
        endif;

        return (false);
    }


    function validate($source,$userinput=true){
        $this->errors=array();
        //Check the input and make sure the fields are specified.
        if(!$source || !is_array($source))
            $this->errors['err']=__('Invalid input');
        elseif(!$this->fields || !is_array($this->fields))
            $this->errors['err']=__('No fields set up');
        //Abort on error
        if($this->errors)
            return false;

        //if magic quotes are enabled - then try cleaning up inputs before validation...
        if($userinput && function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc())
            $source=Format::strip_slashes($source);


        $this->input=$source;

        //Do the do.
        foreach($this->fields as $k=>$field){
            // Una definición de campo puede traer sólo el tipo: aquí se
            // completa para no leer claves que no existen (`min` sólo lo
            // define quien lo necesita) y no llenar el log de avisos.
            $field += array('required' => false, 'error' => null,
                'type' => '*', 'min' => 0);

            // Un campo que no viene en la entrada no es un aviso de PHP: es
            // «vacío». Antes se leía $this->input[$k] sin comprobarlo y
            // llenaba el log de «Undefined array key».
            $value = $this->input[$k] ?? null;

            if(!$field['required'] && !$value) //NOT required...and no data provided...
                continue;

            if($field['required'] && !isset($this->input[$k]) || (!$value && $field['type']!='int')){ //Required...and no data provided...
                $this->errors[$k]=$field['error'];
                continue;
            }

            //We don't care about the type.
            if ($field['type'] == '*') continue;

            // La tabla de reglas vive en class.validator.rule.php
            if (($error = ValidatorRule::check($field['type'], $value, $field)) !== null)
                $this->errors[$k] = $error;
        }
        return ($this->errors)?(FALSE):(TRUE);
    }

    function iserror(){
        return $this->errors?true:false;
    }

    function errors(){
        return $this->errors;
    }

    /*** Functions below can be called directly without class instance.
         Validator::func(var..);  (nolint) ***/
    static function is_email($email, $list=false, $verify=false) {
        require_once PEAR_DIR . 'Mail/RFC822.php';
        require_once PEAR_DIR . 'PEAR.php';
        $rfc822 = new Mail_RFC822();
        if (!($mails = @$rfc822->parseAddressList($email)) || PEAR::isError($mails))
            return false;

        if (!$list && count($mails) > 1)
            return false;

        foreach ($mails as $m) {
            if (!$m->mailbox)
                return false;
            if ($m->host == 'localhost')
                return false;
        }

        // According to RFC2821, the domain (A record) can be treated as an
        // MX if no MX records exist for the domain. Also, include a
        // full-stop trailing char so that the default domain of the server
        // is not added automatically
        if ($verify and !dns_get_record($m->host.'.', DNS_MX))
            return (count(dns_get_record($m->host.'.', DNS_A|DNS_AAAA) ?: []));

        return true;
    }

    static function is_emailish($email) {
        return (preg_match('/(.*@.{2,})|(.{2,}@.*)/', $email));
    }

    static function is_numeric($number, &$error='') {
        if (!is_numeric($number))
            $error = __('Enter a Number');
        return $error == '';
    }

    static function is_valid_email($email, &$error='') {
        global $cfg;
        // Default to FALSE for installation
        return self::is_email($email, false, $cfg && $cfg->verifyEmailAddrs());
    }

    static function is_phone($phone, &$error='') {
        /* We're not really validating the phone number but just making sure it doesn't contain illegal chars and of acceptable len */
        $stripped=preg_replace("(\(|\)|\-|\.|\+|[  ]+)","",$phone);
        return (!is_numeric($stripped) || ((strlen($stripped)<7) || (strlen($stripped)>16)))?false:true;
    }

    static function is_url($url) {
        //XXX: parse_url is not ideal for validating urls but it's ideal for basic checks.
        $info = $url ? parse_url($url) : null;

        return (!empty($info['host']));
    }

    static function is_ip($ip, &$error='') {
        return filter_var(trim($ip), FILTER_VALIDATE_IP) !== false;
    }

    static function is_username($username, &$error='') {
        if (strlen($username)<2)
            $error = __('Username must have at least two (2) characters');
        elseif (is_numeric($username) || !preg_match('/^[\p{L}\d._-]+$/u', $username))
            $error = __('Username contains invalid characters');
        return $error == '';
    }

    static  function is_userid($userid, &$error='', $verify=false) {
        if (!self::is_username($userid)
                    && !self::is_email($userid, false, $verify))
            $error = __('Invalid User Id ');
        return $error == '';
    }

    static function is_formula($text, &$error='') {
        if (!preg_match('/(^[^=\+@-].*$)|(^\+\d+$)/s', $text))
            $error = __('Content cannot start with the following characters: = - + @');
        return $error == '';
    }

    static function check_passwd($passwd, &$error='') {
        try {
            PasswordPolicy::checkPassword($passwd, null);
        } catch (BadPassword $ex) {
            $error = $ex->getMessage();
        }
        return $error == '';
    }

    /*
     * check_ip
     * Checks if an IP (IPv4 or IPv6) address is contained in the list of given IPs or subnets.
     *
     * @credit - borrowed from Symfony project
     *
     */
    public static function check_ip($ip, $ips) {

        if (!Validator::is_ip($ip))
            return false;

        $method = substr_count($ip, ':') > 1 ? 'check_ipv6' : 'check_ipv4';
        $ips = is_array($ips) ? $ips : array($ips);
        foreach ($ips as $_ip) {
            if (self::$method($ip, $_ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * check_ipv4
     * Compares two IPv4 addresses.
     * In case a subnet is given, it checks if it contains the request IP.
     *
     * @credit - borrowed from Symfony project
     */
    public static function check_ipv4($ip, $cidr) {

        if (false !== strpos($cidr, '/')) {
            list($address, $netmask) = explode('/', $cidr, 2);

            if ($netmask === '0')
                return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

            if ($netmask < 0 || $netmask > 32)
                return false;

        } else {
            $address = $cidr;
            $netmask = 32;
        }

        return 0 === substr_compare(
                sprintf('%032b', ip2long($ip)),
                sprintf('%032b', ip2long($address)),
                0, $netmask);
    }

    /**
     * Compares two IPv6 addresses.
     * In case a subnet is given, it checks if it contains the request IP.
     *
     * @credit - borrowed from Symfony project
     * @author David Soria Parra <dsp at php dot net>
     *
     * @see https://github.com/dsp/v6tools
     *
     */
    public static function check_ipv6($ip, $cidr) {

        if (!((extension_loaded('sockets') && defined('AF_INET6')) || @inet_pton('::1')))
            return false;

        if (false !== strpos($cidr, '/')) {
            list($address, $netmask) = explode('/', $cidr, 2);
            if ($netmask < 1 || $netmask > 128)
                return false;
        } else {
            $address = $cidr;
            $netmask = 128;
        }

        $bytesAddr = unpack('n*', @inet_pton($address));
        $bytesTest = unpack('n*', @inet_pton($ip));
        if (!$bytesAddr || !$bytesTest)
            return false;

        for ($i = 1, $ceil = ceil($netmask / 16); $i <= $ceil; ++$i) {
            $left = $netmask - 16 * ($i - 1);
            $left = ($left <= 16) ? $left : 16;
            $mask = ~(0xffff >> $left) & 0xffff;
            if (($bytesAddr[$i] & $mask) != ($bytesTest[$i] & $mask)) {
                return false;
            }
        }

        return true;
    }

    static function process($fields,$vars,&$errors){

        $val = new Validator();
        $val->setFields($fields);
        if(!$val->validate($vars))
            $errors=array_merge($errors,$val->errors());

        return (!$errors);
    }

    static function check_acl($backend) {
        global $cfg;

        $acl = $cfg->getACL();
        if (empty($acl))
            return true;
        $ip = osTicket::get_client_ip();
        if (empty($ip))
            return false;

        $aclbk = $cfg->getACLBackend();
        switch($backend) {
            case 'client':
                if (in_array($aclbk, array(0,3))
                        || ($aclbk == 2 && StaffAuthenticationBackend::getUser()))
                    return true;
                break;
            case 'staff':
                if (in_array($aclbk, array(0,2)))
                    return true;
                break;
            default:
                return false;
                break;
        }

        if (!in_array($ip, $acl))
            return false;

        return true;
    }
}
?>
