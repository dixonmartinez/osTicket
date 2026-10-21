<?php
/*************************************************************************
    class.passwd.php

    Password Hasher - Interface for phpass bcrypt hasher.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once(INCLUDE_DIR.'PasswordHash.php'); //helper class - will be removed then we move to php5

// bcrypt cost. 8 was the historical default; modern hardware makes it too
// cheap. Existing hashes keep working (the cost is stored in the hash) and
// are transparently upgraded on the next successful login.
define('DEFAULT_WORK_FACTOR', 10);

class Passwd {

    static function cmp($passwd,$hash,$work_factor=0){
        
        if($work_factor < 4 || $work_factor > 31)
            $work_factor=DEFAULT_WORK_FACTOR;

        $hasher = new PasswordHash($work_factor,FALSE);

        return ($hasher && $hasher->CheckPassword($passwd,$hash));
    }

    static function hash($passwd, $work_factor=0){
       
        if($work_factor < 4 || $work_factor > 31)
            $work_factor=DEFAULT_WORK_FACTOR;

        $hasher = new PasswordHash($work_factor,FALSE);
        
        return ($hasher && ($hash=$hasher->HashPassword($passwd)))?$hash:null;
    }

    /**
     * needsRehash
     *
     * Returns true when the stored hash was produced with a weaker
     * algorithm or cost than the one currently configured. Callers should
     * re-hash (and persist) the password after a successful verification.
     */
    static function needsRehash($hash, $work_factor=0) {
        if (!$hash || !is_string($hash) || strlen($hash) < 4)
            return false;

        if ($work_factor < 4 || $work_factor > 31)
            $work_factor = DEFAULT_WORK_FACTOR;

        // bcrypt: $2y$10$<salt><hash>
        if (preg_match('/^\$2[abxy]?\$(\d{2})\$/', $hash, $m))
            return ((int) $m[1]) < $work_factor;

        // Anything else (legacy MD5, DES, unknown) should be upgraded
        return true;
    }
}
?>
