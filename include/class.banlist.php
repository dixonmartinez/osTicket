<?php
/*********************************************************************
    class.banlist.php

    Banned email addresses handle.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once "class.filter.php";
class Banlist {

    static function add($email,$submitter='') {
        return self::getSystemBanList()->addRule('email','equal',$email);
    }

    static function remove($email) {
        return self::getSystemBanList()->removeRule('email','equal',$email);
    }

    /**
     * Quick function to determine if the received email-address is in the
     * banlist. Returns the filter of the filter that has the address
     * blacklisted and FALSE if the email is not blacklisted.
     *
     */
    static function isBanned($addr) {

        if (!($filter=self::getFilter()))
            return false;

        $rule = FilterRule::objects()
            ->filter(array(
                'filter_id' => $filter->getId(),
                'filter__isactive' => 1,
                'isactive' => 1,
                'what' => 'email',
                'val' => $addr,
            ));

        return $rule->exists() ? $filter : false;
    }

    static function includes($email) {
        return self::getSystemBanList()->containsRule('email','equal',$email);
    }

    static function ensureSystemBanList() {

        if (!($id=Filter::getByName('SYSTEM BAN LIST')))
            $id=self::createSystemBanList();

        return $id;
    }

    static function createSystemBanList() {
        # XXX: Filter::create should return the ID!!!
        $errors=array();
        return Filter::create(array(
            'execorder'     => 99,
            'name'          => 'SYSTEM BAN LIST',
            'isactive'      => 1,
            'match_all_rules' => false,
            'actions'       => array(
                'Nreject',
            ),
            'rules'         => array(),
            'notes'         => __('Internal list for email banning. Do not remove')
        ), $errors);
    }

    static function getSystemBanList() {
        return self::ensureSystemBanList();
    }

    static function getFilter() {
        return self::getSystemBanList();
    }
}
