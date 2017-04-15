<?php
# set the new path of config.php (must be in a safe location outside the `public_html`)
require_once '../../config.php';

# load php dependencies:
require_once './backend-libs/autoload.php';

$mailbox = new PhpImap\Mailbox($config['imap']['url'],
    $config['imap']['username'],
    $config['imap']['password']);

/**
 * print error and stop program.
 * @param $status integer http status
 * @param $text string error text
 */
function error($status, $text) {
    @http_response_code($status);
    @print("{\"error\": \"$text\"}");
    die();
}

/**
 * print all mails for the given $user.
 * @param $address string email address
 */
function print_emails($address)
{
    global $mailbox;

    // Search for mails with the recipient $address in TO or CC.
    $mailsIdsTo = imap_sort($mailbox->getImapStream(), SORTARRIVAL, true, SE_UID, 'TO "' . $address . '"');
    $mailsIdsCc = imap_sort($mailbox->getImapStream(), SORTARRIVAL, true, SE_UID, 'CC "' . $address . '"');
    $mail_ids = array_merge($mailsIdsTo, $mailsIdsCc);

    $emails = _load_emails($mail_ids, $address);
    header('Content-type: application/json');
    print(json_encode(array("mails" => $emails, 'address' => $address)));
}


/**
 * deletes emails by id and address. The $address must match the recipient in the email.
 *
 * @param $mailid integer imap email id
 * @param $address string email address
 * @internal
 */
function delete_email($mailid, $address) {
    global $mailbox;

    if (_load_one_email($mailid, $address) !== null) {
        $mailbox->deleteMail($mailid);
        $mailbox->expungeDeletedMails();
        header('Content-type: application/json');
        print(json_encode(array("success" => true)));
    } else {
        error(404, 'delete error: invalid address/mailid combination');
    }
}

/**
 * download email by id and address. The $address must match the recipient in the email.
 *
 * @param $mailid integer imap email id
 * @param $address string email address
 * @internal
 */

function download_email($mailid, $address) {
    global $mailbox;

    if (_load_one_email($mailid, $address) !== null) {
        header("Content-Type: message/rfc822; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$address-$mailid.eml\"");

        $headers = imap_fetchheader($mailbox->getImapStream(), $mailid, FT_UID);
        $body = imap_body($mailbox->getImapStream(), $mailid, FT_UID);
        print ($headers . "\n" . $body);
    } else {
        error(404, 'download error: invalid address/mailid combination');
    }
}

/**
 * Load exactly one email, the $address in TO or CC has to match.
 * @param $mailid integer
 * @param $address String address
 * @return email or null
 */
function _load_one_email($mailid, $address) {
    // in order to avoid https://www.owasp.org/index.php/Top_10_2013-A4-Insecure_Direct_Object_References
    // the recipient in the email has to match the $address.
    $emails = _load_emails(array($mailid), $address);
    return count($emails) === 1 ? $emails[0] : null;
}

/**
 * Load emails using the $mail_ids, the mails have to match the $address in TO or CC.
 * @param $mail_ids array of integer ids
 * @param $address String address
 * @return array of emails
 */
function _load_emails($mail_ids, $address) {
    global $mailbox;

    $emails = array();
    foreach ($mail_ids as $id) {
        $mail = $mailbox->getMail($id);
        // imap_search also returns partials matches. The mails have to be filtered again:
        if (array_key_exists($address, $mail->to) || array_key_exists($address, $mail->cc)) {
            $emails[] = $mail;
        }
    }
    return $emails;
}

/**
 * Remove illegal characters from address. You may extend it if your server supports them.
 * @param $address
 * @return string clean address
 */
function _clean_address($address)
{
    $address = strtolower($address);
    return preg_replace('/[^A-Za-z0-9_.+-@]/', "", $address);   // remove special characters
}

/**
 * Return true if and only if address is valid.
 * @param $address
 * @return string clean address
 */
function _valid_address($address)
{
    return strlen($address) > 0 && strpos($address, "@") !== false;
}



/**
 * deletes messages older than X days.
 */
function delete_old_messages() {
    global $mailbox, $config;

    $ids = $mailbox->searchMailbox('BEFORE ' . date('d-M-Y', strtotime($config['delete_messages_older_than'])));
    foreach ($ids as $id) {
        $mailbox->deleteMail($id);
    }
    $mailbox->expungeDeletedMails();
}

// Never cache requests:
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (isset($_GET['address'])) {
    // perform common validation:
    $address = _clean_address($_GET['address']);
    if (!_valid_address($address)) {
        error(400, 'invalid address');
    }

    // simple router:
    if (isset($_GET['download_email_id'])) {
        download_email($_GET['download_email_id'], $address);
    } else if (isset($_GET['delete_email_id'])) {
        delete_email($_GET['delete_email_id'], $address);
    } else {
        print_emails($address);
    }
} elseif (isset($_GET['get_config'])) {
    print(json_encode(array("config" => $config['public'])));
} else {
    error(400, 'invalid action');
}

// run on every request
delete_old_messages();
