<?php

namespace SRWieZ\PHPImap;

/**
 * @see https://github.com/srwiez/php-imap
 * @author Barbushin Sergey http://linkedin.com/in/barbushin
 * @author Eser Deniz http://eserdeniz.fr
 *
 */
 
class ImapMailbox {

    // Mailbox attributes
    const LATT_NOINFERIORS = 1; // It is not possible for any child levels of hierarchy to exist under this name; no child levels exist now and none can be created in the future
    const LATT_NOSELECT = 2; // This is only a container, not a mailbox - you cannot open it.
    const LATT_MARKED = 4; // This mailbox is marked. This means that it may contain new messages since the last time it was checked. Not provided by all IMAP servers.
    const LATT_UNMARKED = 8; // This mailbox is not marked, does not contain new messages. If either MARKED or UNMARKED is provided, you can assume the IMAP server supports this feature for this mailbox.
    const LATT_REFERRAL = 16; // This mailbox is a link to another remote mailbox (http://www.ietf.org/rfc/rfc2193.txt)
    const LATT_HASCHILDREN = 32; // This mailbox contains children.
    const LATT_HASNOCHILDREN = 64; // This mailbox not contain any children.

    protected $imapPath;
    protected $server;
    protected $mailbox;
    protected $login;
    protected $password;
    protected $serverEncoding;
    protected $attachmentsDir;

    public function __construct($server, $mailbox, $login, $password, $attachmentsDir = null, $serverEncoding = 'utf-8') {
        $this->imapPath = $server.$mailbox;
        $this->server = $server;
        $this->mailbox = $mailbox;
        $this->login = $login;
        $this->password = $password;
        $this->serverEncoding = $serverEncoding;
        if($attachmentsDir) {
            if(!is_dir($attachmentsDir)) {
                throw new Exception('Directory "' . $attachmentsDir . '" not found');
            }
            $this->attachmentsDir = rtrim(realpath($attachmentsDir), '\\/');
        }
    }

    /**
     * Get IMAP mailbox connection stream
     * @param bool $forceConnection Initialize connection if it's not initialized
     * @return null|resource
     */
    public function getImapStream($forceConnection = true) {
        static $imapStream;
        if($forceConnection) {
            if($imapStream && (!is_resource($imapStream) || !imap_ping($imapStream))) {
                $this->disconnect();
                $imapStream = null;
            }
            if(!$imapStream) {
                $imapStream = $this->initImapStream();
            }
        }
        return $imapStream;
    }

    protected function initImapStream() {
        $imapStream = @imap_open($this->imapPath, $this->login, $this->password);
        if(!$imapStream) {
            throw new ImapMailboxException('Connection error: ' . imap_last_error());
        }
        return $imapStream;
    }

    protected function disconnect() {
        $imapStream = $this->getImapStream(false);
        if($imapStream && is_resource($imapStream)) {
            imap_close($imapStream, CL_EXPUNGE);
        }
    }

    /**
     * Get the list of mailboxes
     *
     * Returns the information in an object with following properties:
     *
     * @param bool $tree convert unidimensional array on a tree of mailboxes
     * @return array of stdClass
     */
    public function getMailboxes($tree = false) {
        $mailboxes = imap_getmailboxes($this->getImapStream(), $this->imapPath,'*');

        foreach($mailboxes as $id => $mailbox)
        {
            $mailbox->name = str_replace($this->server, '', $mailbox->name);
            $mailbox->attributes = $this->parseMailboxAttributes($mailbox->attributes);
        }

        if($tree) {
            $mailboxes = $this->mailboxesTreeArray($mailboxes);
        }

        return $mailboxes;
    }

    /**
     * Get information about the current mailbox.
     *
     * Returns the information in an object with following properties:
     *  Date - current system time formatted according to RFC2822
     *  Driver - protocol used to access this mailbox: POP3, IMAP, NNTP
     *  Mailbox - the mailbox name
     *  Nmsgs - number of mails in the mailbox
     *  Recent - number of recent mails in the mailbox
     *
     * @return stdClass
     */
    public function checkMailbox() {
        return imap_check($this->getImapStream());
    }

    /**
     * Creates a new mailbox specified by mailbox.
     *
     * @return bool
     */

    public function createMailbox() {
        return imap_createmailbox($this->getImapStream(), mb_convert_encoding($this->imapPath, $this->serverEncoding, "UTF-7"));
    }

    /**
     * Gets status information about the given mailbox.
     *
     * This function returns an object containing status information.
     * The object has the following properties: messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return stdClass | FALSE if the box doesn't exist
     */

    public function statusMailbox() {
        return imap_status($this->getImapStream(), $this->imapPath, SA_ALL);
    }

    /**
     * This function performs a search on the mailbox currently opened in the given IMAP stream.
     * For example, to match all unanswered mails sent by Mom, you'd use: "UNANSWERED FROM mom".
     * Searches appear to be case insensitive. This list of criteria is from a reading of the UW
     * c-client source code and may be incomplete or inaccurate (see also RFC2060, section 6.4.4).
     *
     * @param string $criteria String, delimited by spaces, in which the following keywords are allowed. Any multi-word arguments (e.g. FROM "joey smith") must be quoted. Results will match all criteria entries.
     *    ALL - return all mails matching the rest of the criteria
     *    ANSWERED - match mails with the \\ANSWERED flag set
     *    BCC "string" - match mails with "string" in the Bcc: field
     *    BEFORE "date" - match mails with Date: before "date"
     *    BODY "string" - match mails with "string" in the body of the mail
     *    CC "string" - match mails with "string" in the Cc: field
     *    DELETED - match deleted mails
     *    FLAGGED - match mails with the \\FLAGGED (sometimes referred to as Important or Urgent) flag set
     *    FROM "string" - match mails with "string" in the From: field
     *    KEYWORD "string" - match mails with "string" as a keyword
     *    NEW - match new mails
     *    OLD - match old mails
     *    ON "date" - match mails with Date: matching "date"
     *    RECENT - match mails with the \\RECENT flag set
     *    SEEN - match mails that have been read (the \\SEEN flag is set)
     *    SINCE "date" - match mails with Date: after "date"
     *    SUBJECT "string" - match mails with "string" in the Subject:
     *    TEXT "string" - match mails with text "string"
     *    TO "string" - match mails with "string" in the To:
     *    UNANSWERED - match mails that have not been answered
     *    UNDELETED - match mails that are not deleted
     *    UNFLAGGED - match mails that are not flagged
     *    UNKEYWORD "string" - match mails that do not have the keyword "string"
     *    UNSEEN - match mails which have not been read yet
     *
     * @return array Mails ids
     */
    public function searchMailbox($criteria = 'ALL') {
        $mailsIds = imap_search($this->getImapStream(), $criteria, SE_UID, $this->serverEncoding);
        return $mailsIds ? $mailsIds : array();
    }

    /**
     * Marks mails listed in mailId for deletion.
     * @return bool
     */
    public function deleteMail($mailId) {
        return imap_delete($this->getImapStream(), $mailId, FT_UID);
    }

    public function moveMail($mailId, $mailBox) {
        return imap_mail_move($this->getImapStream(), $mailId, $mailBox, CP_UID) && $this->expungeDeletedMails();
    }

    /**
     * Deletes all the mails marked for deletion by imap_delete(), imap_mail_move(), or imap_setflag_full().
     * @return bool
     */
    public function expungeDeletedMails() {
        return imap_expunge($this->getImapStream());
    }

    /**
     * Add the flag \Seen to a mail.
     * @return bool
     */
    public function markMailAsRead($mailId) {
        return $this->setFlag(array($mailId), '\\Seen');
    }

    /**
     * Remove the flag \Seen from a mail.
     * @return bool
     */
    public function markMailAsUnread($mailId) {
        return $this->clearFlag(array($mailId), '\\Seen');
    }

    /**
     * Add the flag \Flagged to a mail.
     * @return bool
     */
    public function markMailAsImportant($mailId) {
        return $this->setFlag(array($mailId), '\\Flagged');
    }

    /**
     * Add the flag \Seen to a mails.
     * @return bool
     */
    public function markMailsAsRead(array $mailId) {
        return $this->setFlag($mailId, '\\Seen');
    }

    /**
     * Remove the flag \Seen from some mails.
     * @return bool
     */
    public function markMailsAsUnread(array $mailId) {
        return $this->clearFlag($mailId, '\\Seen');
    }

    /**
     * Add the flag \Flagged to some mails.
     * @return bool
     */
    public function markMailsAsImportant(array $mailId) {
        return $this->setFlag($mailId, '\\Flagged');
    }

    /**
     * Causes a store to add the specified flag to the flags set for the mails in the specified sequence.
     *
     * @param array $mailsIds
     * @param $flag Flags which you can set are \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060.
     * @return bool
     */
    public function setFlag(array $mailsIds, $flag) {
        return imap_setflag_full($this->getImapStream(), implode(',', $mailsIds), $flag, ST_UID);
    }

    /**
     * Cause a store to delete the specified flag to the flags set for the mails in the specified sequence.
     *
     * @param array $mailsIds
     * @param $flag Flags which you can set are \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060.
     * @return bool
     */
    public function clearFlag(array $mailsIds, $flag) {
        return imap_clearflag_full($this->getImapStream(), implode(',', $mailsIds), $flag, ST_UID);
    }

    /**
     * Fetch mail headers for listed mails ids
     *
     * Returns an array of objects describing one mail header each. The object will only define a property if it exists. The possible properties are:
     *  subject - the mails subject
     *  from - who sent it
     *  to - recipient
     *  date - when was it sent
     *  message_id - Mail-ID
     *  references - is a reference to this mail id
     *  in_reply_to - is a reply to this mail id
     *  size - size in bytes
     *  uid - UID the mail has in the mailbox
     *  msgno - mail sequence number in the mailbox
     *  recent - this mail is flagged as recent
     *  flagged - this mail is flagged
     *  answered - this mail is flagged as answered
     *  deleted - this mail is flagged for deletion
     *  seen - this mail is flagged as already read
     *  draft - this mail is flagged as being a draft
     *
     * @param array $mailsIds
     * @return array
     */
    public function getMailsInfo(array $mailsIds) {
        $mails = imap_fetch_overview($this->getImapStream(), implode(',', $mailsIds), FT_UID);
        if(is_array($mails) && count($mails))
        {
            foreach($mails as &$mail)
            {
                if(isset($mail->subject)) {
                    $mail->subject = $this->decodeMimeStr($mail->subject, $this->serverEncoding);
                }
                if(isset($mail->from)) {
                    $mail->from = $this->decodeMimeStr($mail->from, $this->serverEncoding);
                }
                if(isset($mail->to)) {
                    $mail->to = $this->decodeMimeStr($mail->to, $this->serverEncoding);
                }
            }
        }
        return $mails;
    }

    /**
     * Get information about the current mailbox.
     *
     * Returns an object with following properties:
     *  Date - last change (current datetime)
     *  Driver - driver
     *  Mailbox - name of the mailbox
     *  Nmsgs - number of messages
     *  Recent - number of recent messages
     *  Unread - number of unread messages
     *  Deleted - number of deleted messages
     *  Size - mailbox size
     *
     * @return object Object with info | FALSE on failure
     */

    public function getMailboxInfo() {
        return imap_mailboxmsginfo($this->getImapStream());
    }

    /**
     * Gets mails ids sorted by some criteria
     *
     * Criteria can be one (and only one) of the following constants:
     *  SORTDATE - mail Date
     *  SORTARRIVAL - arrival date (default)
     *  SORTFROM - mailbox in first From address
     *  SORTSUBJECT - mail subject
     *  SORTTO - mailbox in first To address
     *  SORTCC - mailbox in first cc address
     *  SORTSIZE - size of mail in octets
     *
     * @param int $criteria
     * @param bool $reverse
     * @return array Mails ids
     */
    public function sortMails($criteria = SORTARRIVAL, $reverse = true) {
        return imap_sort($this->getImapStream(), $criteria, $reverse, SE_UID);
    }

    /**
     * Get mails count in mail box
     * @return int
     */
    public function countMails() {
        return imap_num_msg($this->getImapStream());
    }

    /**
     * Retrieve the quota settings per user
     * @return array - FALSE in the case of call failure
     */
    protected function getQuota() {
        return imap_get_quotaroot($this->getImapStream(), 'INBOX');
    }

    /**
     * Return quota limit in KB
     * @return int - FALSE in the case of call failure
     */
    public function getQuotaLimit() {
        $quota = $this->getQuota();
        if(is_array($quota)) {
            $quota = $quota['STORAGE']['limit'];
        }
        return $quota;
    }

    /**
     * Return quota usage in KB
     * @return int - FALSE in the case of call failure
     */
    public function getQuotaUsage() {
        $quota = $this->getQuota();
        if(is_array($quota)) {
            $quota = $quota['STORAGE']['usage'];
        }
        return $quota;
    }

    /**
     * Get mail data
     *
     * @param $mailId
     * @return IncomingMail
     */
    public function getMail($mailId) {
        $head = imap_rfc822_parse_headers(imap_fetchheader($this->getImapStream(), $mailId, FT_UID));

        $mail = new IncomingMail();
        $mail->id = $mailId;
        $mail->date = date('Y-m-d H:i:s', isset($head->date) ? strtotime($head->date) : time());
        $mail->subject = $this->decodeMimeStr($head->subject, $this->serverEncoding);
        $mail->fromName = isset($head->from[0]->personal) ? $this->decodeMimeStr($head->from[0]->personal, $this->serverEncoding) : null;
        $mail->fromAddress = strtolower($head->from[0]->mailbox . '@' . $head->from[0]->host);

        if(isset($head->to)) {
            $toStrings = array();
            foreach($head->to as $to) {
                if(!empty($to->mailbox) && !empty($to->host)) {
                    $toEmail = strtolower($to->mailbox . '@' . $to->host);
                    $toName = isset($to->personal) ? $this->decodeMimeStr($to->personal, $this->serverEncoding) : null;
                    $toStrings[] = $toName ? "$toName <$toEmail>" : $toEmail;
                    $mail->to[$toEmail] = $toName;
                }
            }
            $mail->toString = implode(', ', $toStrings);
        }

        if(isset($head->cc)) {
            foreach($head->cc as $cc) {
                $mail->cc[strtolower($cc->mailbox . '@' . $cc->host)] = isset($cc->personal) ? $this->decodeMimeStr($cc->personal, $this->serverEncoding) : null;
            }
        }

        if(isset($head->reply_to)) {
            foreach($head->reply_to as $replyTo) {
                $mail->replyTo[strtolower($replyTo->mailbox . '@' . $replyTo->host)] = isset($replyTo->personal) ? $this->decodeMimeStr($replyTo->personal, $this->serverEncoding) : null;
            }
        }

        $mailStructure = imap_fetchstructure($this->getImapStream(), $mailId, FT_UID);

        if(empty($mailStructure->parts)) {
            $this->initMailPart($mail, $mailStructure, 0);
        }
        else {
            foreach($mailStructure->parts as $partNum => $partStructure) {
                $this->initMailPart($mail, $partStructure, $partNum + 1);
            }
        }

        return $mail;
    }

    protected function initMailPart(IncomingMail $mail, $partStructure, $partNum) {
        $data = $partNum ? imap_fetchbody($this->getImapStream(), $mail->id, $partNum, FT_UID) : imap_body($this->getImapStream(), $mail->id, FT_UID);

        if($partStructure->encoding == 1) {
            $data = imap_utf8($data);
        }
        elseif($partStructure->encoding == 2) {
            $data = imap_binary($data);
        }
        elseif($partStructure->encoding == 3) {
            $data = imap_base64($data);
        }
        elseif($partStructure->encoding == 4) {
            $data = imap_qprint($data);
        }

        $params = array();
        if(!empty($partStructure->parameters)) {
            foreach($partStructure->parameters as $param) {
                $params[strtolower($param->attribute)] = $param->value;
            }
        }
        if(!empty($partStructure->dparameters)) {
            foreach($partStructure->dparameters as $param) {
                $paramName = strtolower(preg_match('~^(.*?)\*~', $param->attribute, $matches) ? $matches[1] : $param->attribute);
                if(isset($params[$paramName])) {
                    $params[$paramName] .= $param->value;
                }
                else {
                    $params[$paramName] = $param->value;
                }
            }
        }
        if(!empty($params['charset'])) {
            $data = iconv(strtoupper($params['charset']), $this->serverEncoding . '//IGNORE', $data);
        }

        // attachments
        $attachmentId = $partStructure->ifid
            ? trim($partStructure->id, " <>")
            : (isset($params['filename']) || isset($params['name']) ? mt_rand() . mt_rand() : null);
        if($attachmentId) {
            if(empty($params['filename']) && empty($params['name'])) {
                $fileName = $attachmentId . '.' . strtolower($partStructure->subtype);
            }
            else {
                $fileName = !empty($params['filename']) ? $params['filename'] : $params['name'];
                $fileName = $this->decodeMimeStr($fileName, $this->serverEncoding);
                $fileName = $this->decodeRFC2231($fileName, $this->serverEncoding);
            }
            $attachment = new IncomingMailAttachment();
            $attachment->id = $attachmentId;
            $attachment->name = $fileName;
            if($this->attachmentsDir) {
                $replace = array(
                    '/\s/' => '_',
                    '/[^0-9a-zA-Z_\.]/' => '',
                    '/_+/' => '_',
                    '/(^_)|(_$)/' => '',
                );
                $fileSysName = preg_replace('~[\\\\/]~', '', $mail->id . '_' . $attachmentId . '_' . preg_replace(array_keys($replace), $replace, $fileName));
                $attachment->filePath = $this->attachmentsDir . DIRECTORY_SEPARATOR . $fileSysName;
                file_put_contents($attachment->filePath, $data);
            }
            $mail->addAttachment($attachment);
        }
        elseif($partStructure->type == 0 && $data) {
            if(strtolower($partStructure->subtype) == 'plain') {
                $mail->textPlain .= $data;
            }
            else {
                $mail->textHtml .= $data;
            }
        }
        elseif($partStructure->type == 2 && $data) {
            $mail->textPlain .= trim($data);
        }
        if(!empty($partStructure->parts)) {
            foreach($partStructure->parts as $subPartNum => $subPartStructure) {
                if($partStructure->type == 2 && $partStructure->subtype == 'RFC822') {
                    $this->initMailPart($mail, $subPartStructure, $partNum);
                }
                else {
                    $this->initMailPart($mail, $subPartStructure, $partNum . '.' . ($subPartNum + 1));
                }
            }
        }
    }

    protected function decodeMimeStr($string, $charset = 'utf-8') {
        $newString = '';
        $elements = imap_mime_header_decode($string);
        for($i = 0; $i < count($elements); $i++) {
            if($elements[$i]->charset == 'default') {
                $elements[$i]->charset = 'iso-8859-1';
            }
            $newString .= iconv(strtoupper($elements[$i]->charset), $charset . '//IGNORE', $elements[$i]->text);
        }
        return $newString;
    }

    function isUrlEncoded($string) {
        $string = str_replace('%20', '+', $string);
        $decoded = urldecode($string);
        return $decoded != $string && urlencode($decoded) == $string;
    }

    protected function decodeRFC2231($string, $charset = 'utf-8') {
        if(preg_match("/^(.*?)'.*?'(.*?)$/", $string, $matches)) {
            $encoding = $matches[1];
            $data = $matches[2];
            if($this->isUrlEncoded($data)) {
                $string = iconv(strtoupper($encoding), $charset . '//IGNORE', urldecode($data));
            }
        }
        return $string;
    }

    protected function mailboxesTreeArray($array) {
        $result = array();
        $containers = array();

        foreach ($array as $key => $item)
        {
            $parts = explode($item->delimiter, $item->name);
            $current = &$result;
            $max = count($parts);

            if($max > 1)
            {
                for ($i = 1; $i < $max; $i++)
                {
                    if(is_array($current))
                    {
                        if(!isset($current[$containers[$parts[$i-1]]]->children)) {
                            $current[$containers[$parts[$i-1]]]->children = array();
                        }

                        $current = &$current[$containers[$parts[$i-1]]];
                    }
                    elseif(isset($current->children))
                    {
                        if(!isset($current->children[$containers[$parts[$i-1]]]->children)) {
                            $current->children[$containers[$parts[$i-1]]]->children = array();
                        }

                        $current = &$current->children[$containers[$parts[$i-1]]];
                    }
                }

                $array[$key]->readable_name = $parts[$i-1];

                $current->children[] = $array[$key];

                if(in_array(self::LATT_HASCHILDREN, $array[$key]->attributes)) {

                    $containers[$parts[$i-1]] = array_keys($current->children, $array[$key])[0];
                }
            }
            else
            {
                if(in_array(self::LATT_HASCHILDREN, $item->attributes)) {
                    $containers[$item->name] = $key;
                }

                $item->readable_name = mb_convert_encoding($item->name, "UTF-7", $this->serverEncoding);

                $current[] = $array[$key];
            }
        }

        return $result;
    }



    protected function parseMailboxAttributes($attributes)
    {
        $bin = decbin($attributes);
        $attributes = array();

        if ($bin >=1000000){
            $attributes[] = self::LATT_HASNOCHILDREN;
            $bin = $bin-1000000;
        }

        if ($bin >=100000){
            $attributes[] = self::LATT_HASCHILDREN;
            $bin = $bin-100000;
        }

        if ($bin >=10000){
            $attributes[] = self::LATT_REFERRAL;
            $bin = $bin-10000;
        }

        if ($bin >=1000){
            $attributes[] = self::LATT_UNMARKED;
            $bin = $bin-1000;
        }

        if ($bin >=100){
            $attributes[] = self::LATT_MARKED;
            $bin = $bin-100;
        }

        if ($bin >=10){
            $attributes[] = self::LATT_NOSELECT;
            $bin = $bin-10;
        }

        if ($bin >=1){
            $attributes[] = self::LATT_NOINFERIORS;
            $bin = $bin-1;
        }

        return $attributes;
    }
    
    public function __destruct() {
        $this->disconnect();
    }
}
