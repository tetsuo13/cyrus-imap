<?php
/**
 * CREATED
 * 2006-02-05 By Andrei Nicholson <andre@neo-anime.org>
 *
 * PURPOSE
 * Interface to Cyrus IMAP server.
 *
 * All sorts of IMAP commands:
 * http://cri.univ-lyon2.fr/doc/ImapMaisCEstTresSimple.html
 * http://www.bobpeers.com/technical/telnet_imap
 *
 * TODO
 *  * Create a function which will test all other capabilitise of class. Too
 *    often a new functionality is added which breaks a previous one.
 *  * Instead of calling trigger_error() and halting all program execution on
 *    connection errors, should set a flag stating that we're not connected and
 *    always check for this flag when doing anything.
 *
 * HISTORY
 * 2007-03-19
 *  * Added delete_message() and store_mail_flag().
 */


define('CI_STATE_OK',       'OK');
define('CI_STATE_NOEXIST',  'Non existant');
define('CI_STATE_LOGEDOUT', 'Not loged in');

// Valid message flags.
define('CI_FLAG_ANSWERED', 'Answered'); // Message has been answered
define('CI_FLAG_FLAGGED',  'Flagged');  // Message is "flagged" for urgent/special attention
define('CI_FLAG_DRAFT',    'Draft');    // Message has not completed composition
define('CI_FLAG_SEEN',     'Seen');     // Message has been read
define('CI_FLAG_DELETED',  'Deleted');  // Message is "deleted" for removal by later EXPUNGE


/**
 * Class tested against Cyrus IMAP at this point but along the way abstraction
 * was thought about in the name of later successfully interfacing with other
 * IMAP servers.
 */
class CyrusImap
{
    /**
     * Vital connection parameters.
     *
     * @var string
     */
    private $host     = '';
    private $user     = '';
    private $password = '';
    private $port     = 143;

    /**
     * The resource to the open IMAP connection.
     *
     * @var resource
     */
    private $conn = null;

    /**
     * All IMAP commands must be prefixed by a "command" prefix. We'll be using
     * a three-digit incrementing number.
     *
     * @var int
     */
    private $prefix = 1;

    /**
     * Every time we do something on the server, we'll store the server's response
     * to that command.
     *
     * @var string
     */
    private $response = '';

    /**
     * Each folder's seperator. Cyrus may use '/' or '.' for example.
     *
     * @var string
     */
    private $heirarchy_delimiter = '';

    /**
     * The root of the mailbox. Cyrus is 'INBOX', Courier is ''.
     *
     * @var string
     */
    private $leading_prefix = '';

    /**
     * Other users' namespace, their root.
     *
     * @var string
     */
    private $user_leading_prefix = '';

    /**
     * Other users' namespace, their folder seperator.
     *
     * @var string
     */
    private $user_heirarchy_delimiter = '';

    /**
     * The IMAP server's -- broadcast -- capabilities.
     *
     * $var array
     */
    private $capabilities = array();

    /**
     * The current state of the connection.
     *
     * @var string
     */
    private $state = CI_STATE_OK;

    /**
     * If we select any folder for SELECT or EXAMINE, store its info.
     *
     * @var array
     */
    private $folders = array();

    /**
     * Output debugging values.
     *
     * @var bool
     */
    const debug = false;


    // ------------------------------------------------------------------------
    // Constructor
    //
    // @param  string $host
    // @@param string $user
    // @param  string $password
    // @return void
    // ------------------------------------------------------------------------
    public function __construct($host, $user, $password)
    {
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;

        if ($host == '')
        {
            $this->error(__FUNCTION__.'(): Missing parameter host');
        }

        $this->open_connection();

        $this->login();

        $this->get_capabilities();

        // Find out how we seperate folders, the root folder name, etc.
        $this->get_namespace();

        // Knowing that the account doesn't exist isn't a showstopper (as maybe
        // we'll create accounts and/or edit existing accounts) but it's important
        // to know for as some functionality will be lost.
        if (!$this->account_exists())
        {
            $this->state = CI_STATE_NOEXIST;
        }
    }

    // ------------------------------------------------------------------------
    // Deconstructor
    //
    // @return void
    // ------------------------------------------------------------------------
    public function __destruct()
    {
        if ($this->conn)
        {
            $this->logout();

            @fclose($this->conn);
        }
    }

    // ------------------------------------------------------------------------
    // Stores the IMAP server's capabilities -- as broadcast.
    //
    // @return void
    // ------------------------------------------------------------------------
    private function get_capabilities()
    {
        $this->put_line('CAPABILITY');

        $capabilities = $this->response;

        // Retreive the OK line.
        $this->response = $this->get_line();

        // Remove the word "CAPABILITY" from the beginning.
        $capabilities = substr($capabilities, strpos($capabilities, ' ', 3)+1);

        $this->capabilities = split(' ', $capabilities);
    }

    // ------------------------------------------------------------------------
    // Returns a line of text (a response) from the IMAP server.
    //
    // @return string
    // ------------------------------------------------------------------------
    private function& get_line()
    {
/*
        while ($line = fgets($this->conn))
        {
            if (preg_match("/(\*|\d{3}) (.*)\r\n$/", $line, $args))
            {
            }
        }
*/

        $ret = fgets($this->conn);

        // Remove the "\r\n" from the end.
        $ret = substr($ret, 0, -2);

/*
        while (!feof($this->conn))
        {
            $ret .= fgets($this->conn);

            if (strlen($ret) > 2 && substr($ret, -2) == "\r\n")
            {
                $ret = substr($ret, 0, -2);

                break;
            }
        }
*/
        return $ret;
    }

    // ------------------------------------------------------------------------
    // Returns the server's response to the last command.
    //
    // @return string
    // ------------------------------------------------------------------------
    public function get_response()
    {
        return $this->response;
    }

    // ------------------------------------------------------------------------
    // Need to issue "GETQUOTAROOT user/123@neo-anime.org".
    //
    // Example of output:
    //
    //    Existing account with quota defined:
    //      001 QUOTAROOT user/123@neo-anime.org
    //      001 QUOTA user/123@neo-anime.org (STORAGE 7 5000)
    //      001 OK Completed
    //
    //    Existing account with no quota defined:
    //      001 QUOTAROOT user/123@neo-anime.org
    //      001 OK Completed
    //
    //    Nonexistant account:
    //      001 QUOTAROOT user/123@neo-anime.org
    //      001 NO Mailbox does not exist
    //
    // @return mixed  null if there are any errors; an array otherwise.
    // ------------------------------------------------------------------------
    public function get_quotaroot()
    {
        if ($this->state != CI_STATE_OK)
        {
            return null;
        }

        $response = array();

        $msg = sprintf('GETQUOTAROOT %s%s', $this->user_leading_prefix, $this->user);

        $this->put_line($msg);

        while (!$this->completed_ok())
        {
            array_push($response, $this->response);
            $this->response = $this->get_line();
        }

        // If a quota exists for this account, there should be two elements in
        // the array at this point.
        if (count($response) != 2)
        {
            return null;
        }

        // Look at the second string only.
        $response = $response[1];

        // An account with no quota will look like this:
        //    * QUOTA user/123@neo-anime.org ()
        if (strpos($response, 'STORAGE'))
        {
            // Strip out everything except the usage and total.
            $response = substr($response, strpos($response, 'STORAGE')+8, -1);

            // Break apart the usage and total into two elements in an array.
            $response = explode(' ', $response);
        }
        else
        {
            $response[0] = $response[1] = 0;
        }

        $ret = array(
            'total'   => $response[1],
            'used'    => $response[0],
            'percent' => 0);

        if ($ret['total'] != 0)
        {
            $ret['percent'] = $ret['used'] / $ret['total'] * 100;
            $ret['percent'] = round($ret['percent'], 2);
        }

        return $ret;
    }

    // ------------------------------------------------------------------------
    // Return the folder delimiter that's used.
    //
    // @return string
    // ------------------------------------------------------------------------
    public function get_folder_delimiter()
    {
        // Regular -- non-master -- users logged in will have this value set and
        // so we should return that one. If a master user is logged in, theirs
        // won't be set.
        if (empty($this->heirarchy_delimiter))
        {
            return $this->user_heirarchy_delimiter;
        }

        return $this->heirarchy_delimiter;
    }

    // ------------------------------------------------------------------------
    // Must be logged in first. Issuing this command will return the various
    // namespaces used.
    //
    // RFC 2342 - IMAP4 Namespace
    //
    //    Regular users:
    //      * NAMESPACE (("INBOX/" "/")) (("user/" "/")) (("" "/"))
    //
    //    Cyrus user:
    //      * NAMESPACE NIL (("user/" "/")) (("" "/"))
    //
    // @return void
    // ------------------------------------------------------------------------
    private function get_namespace()
    {
        $this->put_line('NAMESPACE');

        $matches = explode(')) ((', $this->response);

        // There should be at least two, personal and other users. This is for
        // Cyrus especially.
        if (count($matches) < 2)
        {
            $this->error('Error retreiving namespacing');
        }

        $matches[0] = substr($matches[0], strpos($matches[0], '((')+2);

        $this->set_namespace($this->leading_prefix, $this->heirarchy_delimiter, $matches[0]);
        $this->set_namespace($this->user_leading_prefix, $this->user_heirarchy_delimiter, $matches[1]);

        $this->response = $this->get_line();
    }

    // ------------------------------------------------------------------------
    // Returns the total number of messages in the mailbox.
    //
    // Example of output:
    //
    //      001 STATUS user/123@neo-anime.org (MESSAGES)
    //      * STATUS user/123@neo-anime.org (MESSAGES 229)
    //      001 OK Completed
    //
    // Or, if mailbox doesn't exist:
    //
    //      001 STATUS user/123@neo-anime.org (MESSAGES)
    //      001 NO Mailbox does not exist
    //
    // @return int  Total number of messages.
    // ------------------------------------------------------------------------
    public function get_num_messages()
    {
        if ($this->state != CI_STATE_OK)
        {
            return 0;
        }

        $msg = sprintf('STATUS %s%s (%s)', $this->user_leading_prefix, $this->user, 'MESSAGES');
        $this->put_line($msg);

        $num_messages = $this->response;
        $this->response = $this->get_line();

        if (!$this->completed_ok())
        {
            return 0;
        }

        if (!$this->completed_ok())
        {
            $this->error("Error retreiving total number of messages ($this->response)");
        }

        $num_messages = substr($num_messages, strrpos($num_messages, ' '), -1);

        return $num_messages;
    }

    // ------------------------------------------------------------------------
    // Retreives all subfolders for the currently logged in account.
    //
    // Example of output:
    //
    //    001 LIST * *
    //    * LIST (\HasChildren) "/" "INBOX"
    //    * LIST (\HasNoChildren) "/" "INBOX/Drafts"
    //    * LIST (\HasNoChildren) "/" "INBOX/Sent"
    //    * LIST (\HasNoChildren) "/" "INBOX/Spam"
    //    * LIST (\HasNoChildren) "/" "INBOX/Trash"
    //    001 OK Completed (0.000 secs 6 calls)
    //
    // @return array  All folders
    // ------------------------------------------------------------------------
    public function& get_folders()
    {
        $folders = array();

        if ($this->state != CI_STATE_OK)
        {
            $folders[] = 'Mailbox does not exist';

            return $folders;
        }

        $this->put_line('LIST * *');

        while (!$this->completed_ok())
        {
            $folders[] = substr($this->response, strpos($this->response, '" "')+3, -1);
            $this->response = $this->get_line();
        }

        return $folders;
    }

    // ------------------------------------------------------------------------
    // Sets the deleted flag on a given set of messages.
    //
    // @param  string $number
    // @return void
    // @since  2007-03-19
    // @author Andrei Nicholson <andre@neo-anime.org>
    // ------------------------------------------------------------------------
    public function delete_message($number)
    {
        $this->store_mail_flag($number, 'FLAGS', CI_FLAG_DELETED);
    }

    // ------------------------------------------------------------------------
    // Alters data associated with a message in mailbox.
    //
    // Example, setting message number 3 as having been read:
    //
    //      store_mail_flag('3', '+FLAGS', CI_FLAG_SEEN)
    //      store_mail_flag('1:*', 'FLAGS', CI_FLAG_DELETED)
    //
    // @param  string $set
    // @param  string $data_name Available defined data items:
    //                              FLAGS <flag list>
    //                                  Replace the flags for the message with
    //                                  the argument. The new value of the flags
    //                                  are returned as if a FETCH of those was done.
    //
    //                              FLAGS.SILENT <flag list>
    //                                  Equivalent to FLAGS, but without return a new value.
    //
    //                              +FLAGS <flag list>
    //                                  Add the argument to the flags for the
    //                                  message. The new value of the flags is
    //                                  returned as if a FETCH of those flags was done.
    //
    //                              +FLAGS.SILENT <flag list>
    //                                  Equivalent to +FLAGS, but without return a new value.
    //
    //                              -FLAGS <flag list>
    //                                  Remove the argument from the flags for
    //                                  the message. The new value is returned as
    //                                  if a FETCH of those flags was done.
    //
    //                              -FLAGS.SILENT <flag list>
    //                                  Equivalent to -FLAGS, but without returning a new value.
    // @param  string $value
    // @return void
    // @since  2007-03-19
    // @author Andrei Nicholson <andre@neo-anime.org>
    // ------------------------------------------------------------------------
    private function store_mail_flag($set, $data_name, $value)
    {
        // TODO: Detect if a mailbox has been selected.

        $msg = sprintf('STORE %s %s \\%s', $set, $data_name, $value);

        $this->put_line($msg);

        // TODO: Check return value for success/error.
        while (!$this->completed_ok())
        {
            $this->response = $this->get_line();
        }
    }

    // ------------------------------------------------------------------------
    // This will return info on a given folder and leave it open for read-write
    // operations. The command EXAMINE would be for read-only.
    //
    //    001 SELECT INBOX/FolderName
    //    *  FLAGS (\Answered \Flagged \Draft \Deleted \Seen $MDNSent $Forwarded $Label3)
    //    * OK [PERMANENTFLAGS (\Answered \Flagged \Draft \Deleted \Seen $MDNSent $Forwarded $Label3 \*)]  
    //    * 8 EXISTS
    //    * 8 RECENT
    //    * OK [UNSEEN 1]  
    //    * OK [UIDVALIDITY 1107956238]  
    //    * OK [UIDNEXT 190971]  
    //    001 OK [READ-WRITE] Completed
    //
    //  001 SELECT INBOX/DoesntExist
    //  001 NO Mailbox does not exist
    //
    // @param  string $folder
    // @return boolean
    // ------------------------------------------------------------------------ 
    public function select_folder($folder)
    {
        $response = array();

        $msg = sprintf('SELECT %s%s', $this->leading_prefix, $folder);

        $this->put_line($msg);

        // Folder does not exist or there's a permission problem or who knows what.
        if (!$this->completed_ok() && $this->response{0} != '*')
        {
            return false;
        }

        array_push($response, $this->response);

        while (!$this->completed_ok())
        {
            array_push($response, $this->response);
            $this->response = $this->get_line();

            if (strpos($this->response, 'EXISTS') !== false)
            {
                $this->folders[$folder]['exists'] = substr($this->response, 2, strpos($this->response, 'EXISTS')-3);
            }
            else if (strpos($this->response, 'RECENT') !== false)
            {
                $this->folders[$folder]['recent'] = substr($this->response, 2, strpos($this->response, 'RECENT')-3);
            }
        }

        $this->folders[$folder]['info'] = $response;

        return true;
    }

    // ------------------------------------------------------------------------
    // Given a string of namespace, sets accordingly.
    //
    // @param  string $name Left operand.
    // @param  string $sep  Right operand.
    // @param  string $line Example: "INBOX/" "/"
    // @return void
    // ------------------------------------------------------------------------
    private function set_namespace(&$name, &$sep, &$line)
    {
        $line = explode(' ', $line);

        // Strip off the leading quote and trailing quote.
        $name = substr($line[0], 1, -1);

        // Strip off the leading and trailing quote.
        $sep = substr($line[1], 1, -1);
    }

    // ------------------------------------------------------------------------
    // Change Access Control List for a mailbox.
    //
    // RFC 4314 - IMAP4 Access Control List (ACL) Extension
    //
    // Cyrus IMAP ACL:
    //   l   Look up the name of the mailbox (but not its contents).
    //   r   Read the contents of the mailbox.
    //   s   Preserve the "seen" and "recent" status of messages across IMAP sessions.
    //   w   Write (change message flags such as "recent," "answered," and "draft").
    //   i   Insert (move or copy) a message into the mailbox.
    //   p   Post a message in the mailbox by sending the message to the mailbox's
    //       submission address (for example, post a message in the cyrushelp mailbox
    //       by sending a message to sysadmin+cyrushelp@somewhere.net).
    //   c   Create a new mailbox below the top-level mailbox (ordinary users cannot
    //       create top-level mailboxes).
    //   d   Delete a message and/or the mailbox itself.
    //   a   Administer the mailbox (change the mailbox's ACL).
    //
    // ACL special strings:
    //   none
    //   read    lrs
    //   post    lrsp
    //   append  lrsip
    //   write   lrswipcd
    //   delete  lrd
    //   all     lrswipcda
    //
    // @param  string $identifier  Mailbox or user to give rights to.
    // @param  string $rights      Set of given rights.
    // @param  string $mailbox     Target mailbox.
    // @param  string $prefix      Usually "user/" except for special cases (cyrus).
    // @return void
    // ------------------------------------------------------------------------
    public function set_acl($identifier, $rights, $mailbox, $prefix = null)
    {
        if ($prefix == null)
        {
            $prefix = $this->leading_prefix;
        }

        $msg = sprintf('SETACL %s%s %s %s', $prefix, $mailbox, $identifier, $rights);
        $this->put_line($msg);

        if (!$this->completed_ok())
        {
            $this->error("Error setting ACL ($this->response)");
        }
    }

    // ------------------------------------------------------------------------
    // Sets a quota root. This assumes the command is being performed by the
    // cyrus user.
    //
    // RFC 2087 - IMAP4 QUOTA extension
    //
    // @param  int    $quota    The new resource limits for the named quota.
    // @param  string $mailbox  Mailbox to set quota for.
    // @return void
    // ------------------------------------------------------------------------
    public function set_quota($quota, $mailbox)
    {
        if (!$this->has_capability('QUOTA'))
        {
            $this->error("QUOTA not listed in server's capabilities");
        }

        if ($quota == '')
        {
            $resource = '';
        }
        else
        {
            $resource = 'STORAGE '.$quota;
        }

        $msg = sprintf('SETQUOTA "%s%s" (%s)', $this->leading_prefix, $mailbox, $resource);
        $this->put_line($msg);

        if (!$this->completed_ok())
        {
            $this->error("Error setting quota ($this->response)");
        }
    }

    // ------------------------------------------------------------------------
    // Removes a quota root. Telling Cyrus that the resource STORAGE's value is
    // "none" will remove the quota.
    //
    // @param  string $mailbox  Mailbox to remove quota.
    // @return void
    // ------------------------------------------------------------------------
    public function remove_quota($mailbox)
    {
        if (!$this->has_capability('QUOTA'))
        {
            $this->error("QUOTA not listed in server's capabilities");
        }

        $this->set_quota('', $mailbox);
    }

    // ------------------------------------------------------------------------
    // Creates a new mailbox. This function assumes that user used to log in to
    // IMAP has credentials to create mailboxes.
    //
    // @param  string $mailbox  The name of the new mailbox.
    // @return void
    // ------------------------------------------------------------------------
    public function create_mailbox($mailbox)
    {
        $msg = sprintf('CREATE %s%s', $this->leading_prefix, $mailbox);
        $this->put_line($msg);

        if (!$this->completed_ok())
        {
            $this->error("Error creating mailbox ($this->response)");
        }
    }

    // ------------------------------------------------------------------------
    // Removes a mailbox.
    //
    // @param  string $mailbox
    // @return void
    // ------------------------------------------------------------------------
    function delete_mailbox($mailbox)
    {
        $msg = sprintf('DELETE %s%s', $this->leading_prefix, $mailbox);
        $this->put_line($msg);

        if (!$this->completed_ok())
        {
            $this->error("Error deleting mailbox $mailbox ($this->response)");
        }
    }

    // ------------------------------------------------------------------------
    // RFC 1730
    //
    // @param  string $mailbox
    // @return void
    // ------------------------------------------------------------------------
    function subscribe_mailbox($mailbox)
    {
        $msg = sprintf('SUBSCRIBE %s%s', $this->leading_prefix, $mailbox);
        $this->put_line($msg);

        if (!$this->completed_ok())
        {
            $this->error("Error subscribing mailbox $mailbox ($this->response)");
        }
    }

    // ------------------------------------------------------------------------
    // Searches through the IMAP server's broadcast capabilities.
    //
    // @param  string $capability  The capability to look for.
    // @return bool                true if found; false otherwise.
    // ------------------------------------------------------------------------
    function has_capability($capability)
    {
        if (!in_array($capability, $this->capabilities))
        {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------------
    // Sends a line of text to the server.
    //
    // @param  string $msg
    // @return void
    // ------------------------------------------------------------------------
    private function put_line($msg)
    {
        $msg = sprintf('A%03d %s', $this->prefix, $msg);

        if (self::debug)
        {
            flush();
            ob_flush();
            printf('<pre style="text-align:left">%s</pre>'."\n", $msg);
        }

        $this->prefix++;

        @fputs($this->conn, "$msg\r\n");

        $this->response = $this->get_line();

        if (self::debug)
        {
            flush();
            ob_flush();
            printf('<pre style="text-align:left; color:red; padding-left:1em">%s</pre>'."\n", $this->response);
        }
    }

    // ------------------------------------------------------------------------
    // Establishes a connection to the IMAP server.
    //
    // @return void
    // ------------------------------------------------------------------------
    private function open_connection()
    {
        $this->conn = @fsockopen($this->host, $this->port, $errno, $errstr, 5);

        if (!$this->conn)
        {
            $this->error(__FUNCTION__."(): $errstr ($errno)");
        }

        $this->response = $this->get_line();
    }

    // ------------------------------------------------------------------------
    // Verifies that the last command sent to the server completed successfully.
    //
    // Command responses look like this:
    //    A001 OK CAPABILITY completed
    //    A001 OK User logged in
    //    A001 NO Login failed: authentication failure
    //    A001 NO Mailbox does not exist
    //    * BYE LOGOUT received
    //
    // @return bool
    // ------------------------------------------------------------------------
    private function completed_ok()
    {
        $answer = $this->response{5}.$this->response{6};

        //!is_numeric(strpos($this->response, 'OK Completed'))

        // Look immediately after our prefix for the next two letters.
        //if ($answer != 'OK' || $answer != 'NO')
        if ($answer == 'OK')
        {
            return true;
        }

        return false;
    }

    // ------------------------------------------------------------------------
    // Perform a simple test to verify that the account exists on the server.
    //
    // @return bool
    // ------------------------------------------------------------------------
    private function account_exists()
    {
        $msg = sprintf('STATUS %s%s (%s)', $this->user_leading_prefix, $this->user, 'MESSAGES');
        $this->put_line($msg);

        if (strpos($this->response, 'does not exist') !== false)
        {
            return false;
        }

        $this->response = $this->get_line();

        return true;
    }

    // ------------------------------------------------------------------------
    // Logs into the IMAP server using crudentials supplied.
    //
    // @return void
    // ------------------------------------------------------------------------
    private function login()
    {
        $line = sprintf('LOGIN %s %s', $this->user, $this->password);

        $this->put_line($line);

        if (!$this->completed_ok())
        {
            $this->state = CI_STATE_LOGEDOUT;

            $this->error("Could not log in ($this->response)");
        }
    }

    // ------------------------------------------------------------------------
    // Logs out of the server.
    //
    // @return void
    // ------------------------------------------------------------------------
    private function logout()
    {
        $this->put_line('LOGOUT');
    }

    // ------------------------------------------------------------------------
    // An error occurred from which we cannot recover gracefully. Abort all.
    //
    // @param  string $error_msg
    // @return void
    // ------------------------------------------------------------------------
    function error($error_msg)
    {
        trigger_error("CyrusImap: $error_msg", E_USER_ERROR);
    }
}
