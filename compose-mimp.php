<?php
/**
 * Minimalist compose display page.
 *
 * URL Parameters:
 * 'a'
 *
 * Copyright 2002-2008 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author Chuck Hagenbuch <chuck@horde.org>
 * @author Michael Slusarz <slusarz@curecanti.org>
 */

function &_getIMPContents($index, $mailbox)
{
    if (empty($index)) {
        return false;
    }
    $imp_contents = &IMP_Contents::singleton($index . IMP::IDX_SEP . $mailbox);
    if (is_a($imp_contents, 'PEAR_Error')) {
        $GLOBALS['notification']->push(_("Could not retrieve the message from the mail server."), 'horde.error');
        return false;
    }
    return $imp_contents;
}

require_once dirname(__FILE__) . '/lib/base.php';
require_once 'Horde/Identity.php';

/* The message text. */
$msg = '';

/* The headers of the message. */
$header = array(
    'bcc' => '',
    'cc' => '',
    'in_reply_to' => Util::getFormData('in_reply_to'),
    'references' => Util::getFormData('references'),
    'subject' => '',
    'to' => '',
);

/* Set the current identity. */
$identity = &Identity::singleton(array('imp', 'imp'));
if (!$prefs->isLocked('default_identity')) {
    $identity_id = Util::getFormData('identity');
    if ($identity_id !== null) {
        $identity->setDefault($identity_id);
    }
}

$save_sent_mail = $prefs->getValue('save_sent_mail');
$sent_mail_folder = $identity->getValue('sent_mail_folder');
$index = Util::getFormData('index');
$thismailbox = Util::getFormData('thismailbox');
$resume_draft = false;

/* Determine if mailboxes are readonly. */
$draft = IMP::folderPref($prefs->getValue('drafts_folder'), true);
$readonly_drafts = (empty($draft)) ? false : $imp_imap->isReadOnly($draft);
if ($imp_imap->isReadOnly($sent_mail_folder)) {
    $save_sent_mail = false;
}

/* Determine if compose mode is disabled. */
$compose_disable = !empty($conf['hooks']['disable_compose']) &&
    Horde::callHook('_imp_hook_disable_compose', array(true), 'imp');

/* Set the current time zone. */
NLS::setTimeZone();

/* Initialize the IMP_Compose:: object. */
$oldCacheID = Util::getFormData('composeCache');
$imp_compose = &IMP_Compose::singleton($oldCacheID);

/* Run through the action handlers. */
$actionID = Util::getFormData('a');
switch ($actionID) {
// 'd' = draft
case 'd':
    $result = $imp_compose->resumeDraft($index . IMP::IDX_SEP . $thismailbox);
    if (is_a($result, 'PEAR_Error')) {
        $notification->push($result, 'horde.error');
    } else {
        $msg = $result['msg'];
        $header = array_merge($header, $result['header']);
        if (!is_null($result['identity']) &&
            ($result['identity'] != $identity->getDefault()) &&
            !$prefs->isLocked('default_identity')) {
            $identity->setDefault($result['identity']);
            $sent_mail_folder = $identity->getValue('sent_mail_folder');
        }
        $resume_draft = true;
    }
    break;

case _("Expand Names"):
    $action = Util::getFormData('action');
    $imp_ui = new IMP_UI_Compose();
    $header['to'] = $imp_ui->expandAddresses(Util::getFormData('to'), $imp_compose);
    if ($action !== 'rc') {
        if ($prefs->getValue('compose_cc')) {
            $header['cc'] = $imp_ui->expandAddresses(Util::getFormData('cc'), $imp_compose);
        }
        if ($prefs->getValue('compose_bcc')) {
            $header['bcc'] = $imp_ui->expandAddresses(Util::getFormData('bcc'), $imp_compose);
        }
    }
    if ($action !== null) {
        $actionID = $action;
    }
    break;

// 'r' = reply
// 'rl' = reply to list
// 'ra' = reply to all
case 'r':
case 'ra':
case 'rl':
    if (!($imp_contents = &_getIMPContents($index, $thismailbox))) {
        break;
    }
    $actions = array('r' => 'reply', 'ra' => 'reply_all', 'rl' => 'reply_list');
    $reply_msg = $imp_compose->replyMessage($actions[$actionID], $imp_contents, Util::getFormData('to'));
    $header = $reply_msg['headers'];
    break;

// 'f' = forward
case 'f':
    if (!($imp_contents = &_getIMPContents($index, $thismailbox))) {
        break;
    }
    $fwd_msg = $imp_compose->forwardMessage($imp_contents);
    $header = $fwd_msg['headers'];
    break;

case _("Redirect"):
    if (!($imp_contents = &_getIMPContents($index, $thismailbox))) {
        break;
    }

    $imp_ui = new IMP_UI_Compose();

    $f_to = $imp_ui->getAddressList(Util::getFormData('to'));

    $result = $imp_ui->redirectMessage($f_to, $imp_compose, $imp_contents, NLS::getEmailCharset());
    if (!is_a($result, 'PEAR_Error')) {
        if ($prefs->getValue('compose_confirm')) {
            $notification->push(_("Message redirected successfully."), 'horde.success');
        }
        require IMP_BASE . '/mailbox-mimp.php';
        exit;
    }
    $actionID = 'rc';
    $notification->push($result, 'horde.error');
    break;

case _("Send"):
    if ($compose_disable) {
        break;
    }
    $message = Util::getFormData('message', '');
    $f_to = Util::getFormData('to');
    $f_cc = $f_bcc = null;
    $header = array();

    if ($ctype = Util::getFormData('ctype')) {
        if (!($imp_contents = &_getIMPContents($index, $thismailbox))) {
            break;
        }

        switch ($ctype) {
        case 'reply':
            $reply_msg = $imp_compose->replyMessage('reply', $imp_contents, $f_to);
            $msg = $reply_msg['body'];
            $message .= "\n" . $msg;
            break;

        case 'forward':
            $fwd_msg = $imp_compose->forwardMessage($imp_contents);
            $msg = $fwd_msg['body'];
            $message .= "\n" . $msg;
            $imp_compose->attachIMAPMessage(array($index . IMP::IDX_SEP . $thismailbox), $header);
            break;
        }
    }

    $sig = $identity->getSignature();
    if (!empty($sig)) {
        $message .= "\n" . $sig;
    }

    $header['from'] = $identity->getFromLine(null, Util::getFormData('from'));
    $header['replyto'] = $identity->getValue('replyto_addr');
    $header['subject'] = Util::getFormData('subject');

    $imp_ui = new IMP_UI_Compose();

    $header['to'] = $imp_ui->getAddressList(Util::getFormData('to'));
    if ($conf['compose']['allow_cc']) {
        $header['cc'] = $imp_ui->getAddressList(Util::getFormData('cc'));
    }
    if ($conf['compose']['allow_bcc']) {
        $header['bcc'] = $imp_ui->getAddressList(Util::getFormData('bcc'));
    }

    $options = array(
        'save_sent' => $save_sent_mail,
        'sent_folder' => $sent_mail_folder,
        'reply_type' => $ctype,
        'reply_index' => empty($index) ? null : $index . IMP::IDX_SEP . $thismailbox,
        'readreceipt' => Util::getFormData('request_read_receipt')
    );
    $sent = $imp_compose->buildAndSendMessage($message, $header, NLS::getEmailCharset(), false, $options);

    if (is_a($sent, 'PEAR_Error')) {
        $notification->push($sent, 'horde.error');
    } elseif ($sent) {
        if (Util::getFormData('resume_draft') &&
            $prefs->getValue('auto_delete_drafts')) {
            $imp_message = &IMP_Message::singleton();
            $idx_array = array($index . IMP::IDX_SEP . $thismailbox);
            $delete_draft = $imp_message->delete($idx_array, true);
        }

        $notification->push(_("Message sent successfully."), 'horde.success');
        require IMP_BASE . '/mailbox-mimp.php';
        exit;
    }
    break;
}

/* Get the message cache ID. */
$cacheID = $imp_compose->getCacheId();

$title = _("Message Composition");
$mimp_render->set('title', $title);

$select_list = $identity->getSelectList();

/* Grab any data that we were supplied with. */
if (empty($msg)) {
    $msg = Util::getFormData('message', '');
}
foreach (array('to', 'cc', 'bcc', 'subject') as $val) {
    if (empty($header[$val])) {
        $header[$val] = Util::getFormData($val);
    }
}

$menu = &new Horde_Mobile_card('o', _("Menu"));
$mset = &$menu->add(new Horde_Mobile_linkset());
MIMP::addMIMPMenu($mset, 'compose');

if ($actionID == 'rc') {
    require IMP_TEMPLATES . '/compose/redirect-mimp.inc';
} else {
    require IMP_TEMPLATES . '/compose/compose-mimp.inc';
}
