<?php
/*********************************************************************
    class.log.php

    Log

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

/**
 * Una fila de `SYSLOG_TABLE`.
 *
 * `Log` es el envoltorio que lee un registro y lo expone con getters; el
 * modelo es lo que permite escribir y borrar con el ORM en vez de con SQL a
 * mano (y en el registro de errores, un fallo de SQL no puede tumbar la
 * petición: se captura).
 */
class SyslogModel extends VerySimpleModel {
    static $meta = array(
        'table' => SYSLOG_TABLE,
        'pk' => array('log_id'),
    );
}

class Log {

    var $id;
    var $info;

    function __construct($id){
        $this->id=0;
        return $this->load($id);
    }

    function load($id){

        if (!($log = SyslogModel::lookup($id)))
            return false;

        $this->info = $log->getDbFields();
        $this->id = $this->info['log_id'];
        
        return $this->id;
    }

    function reload(){
        return $this->load($this->getId());
    }

    function getId(){
        return $this->id;
    }
        
    function getType(){
        return $this->info['log_type'];    
    }

    function getTitle(){
        return $this->info['title'];
    }

    function getText(){
        return $this->info['log'];
    }

    function getIp(){
        return $this->info['ip_address'];
    }

    function getCreateDate(){
        return $this->info['created'];
    }

    function getInfo(){
        return $this->info;
    }

    /*** static function ***/
    static function lookup($id){
        return ($id && is_numeric($id) && ($l= new Log($id)) && $l->getId()==$id)?$l:null;
    }
}
?>
