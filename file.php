<?php
/*********************************************************************
    file.php

    File download facilitator for clients

    Peter Rotich <peter@osticket.com>
    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2014 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require('client.inc.php');
require_once(INCLUDE_DIR.'class.file.php');

//Basic checks
$key = $_GET['key'] ?? '';
$signature = $_GET['signature'] ?? '';
$expires = $_GET['expires'] ?? 0;
if (!$key
    || !$signature
    || !$expires
    || !($file = AttachmentFile::lookupByHash($key))
) {
    Http::response(404, __('Unknown or invalid file'));
}

// Get the object type the file is attached to
$type = '';
$attachment = null;
if (($id = $_GET['id'] ?? 0)
        && ($attachment=$file->attachments->findFirst(array('id' => $id))))
    $type = $attachment->type;

// Enforce security settings if enabled.
if ($cfg->isAuthRequiredForFiles()
        // FAQ & Page files allowed without login.
        && !in_array($type, ['P', 'F'])
        // Check user login. El arranque del cliente deja `$thisclient`; la
        // variable `thisuser`, que es la que se miraba antes, no existe, así
        // que un cliente con sesión volvía al login.
        && !$thisclient
        // Check staff login
        && !StaffAuthenticationBackend::getUser()
        ) {

    // Try and determine if an agent is viewing the page / file
    if (strpos($_SERVER['HTTP_REFERER'] ?? '', ROOT_PATH .  'scp/') !== false) {
        $_SESSION['_staff']['auth']['dest'] = Http::refresh_url();
        Http::redirect(ROOT_PATH.'scp/login.php');
    } else {
        require ROOT_DIR.'secure.inc.php';
    }
}


// Validate session access hash - we want to make sure the link is FRESH!
// and the user has access to the parent ticket!!
if ($file->verifySignature($signature, $expires)) {
    try {
        if (($s = $_GET['s'] ?? false) && $file->isInlineSafeImage())
            return $file->display($s);

        // Download the file..
        $filename = $attachment ? $attachment->name : $file->getName();
        $disposition = $_GET['disposition'] ?? false;
        $file->download($filename, $disposition, $expires);
    }
    catch (Exception $ex) {
        $ost->logError(__('File download error'), $ex->getMessage());
        Http::response(500, __('Unable to retrieve the requested file'));
    }
}
// else
Http::response(404, __('Unknown or invalid file'));
