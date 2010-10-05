<?php
/**
 * CREATED
 * 2007-02-01 By Andrei Nicholson <andre@neo-anime.org>
 *
 * PURPOSE
 * Interface to Sieve server.
 */


define('S_OK',    1);
define('S_NO',    2);
define('S_ERROR', 0);


/**
 */
class Sieve
{
    /**
     * Vital connection parameters.
     *
     * @var string
     */
    private $host     = '';
    private $user     = '';
    private $password = '';
    private $port     = 2000;

    /**
     * The resource to the open IMAP connection.
     *
     * @var resource
     */
    private $conn = null;

    /**
     * Every time we do something on the server, we'll store the server's response
     * to that command.
     *
     * @var string
     */
    private $response = '';

    /**
     * @var array
     */
    private $capabilities = array();

    /**
     * @var string
     */
    private $error = '';

    /**
     * @var int
     */
    private $status = S_OK;

    /**
     * Output debugging values.
     *
     * @var bool
     */
    private $debug = FALSE;


    // ------------------------------------------------------------------------
    // Constructor
    //
    // @param  string $host
    // @param  string $user
    // @param  string $password
    // @return void
    // ------------------------------------------------------------------------
    public function __construct($host, $user, $password)
    {
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;

        $this->open_connection();
    }

    // ------------------------------------------------------------------------
    // Deconstructor
    //
    // @return void
    // ------------------------------------------------------------------------
    public function __destruct()
    {
        $this->put_line('LOGOUT');

        @fclose($this->conn);
    }

    // ------------------------------------------------------------------------
    // @return void
    // ------------------------------------------------------------------------
    private function put_line($cmd)
    {
        if ($this->debug == TRUE)
        {
            print("Putting: $cmd\n");
        }

        fputs($this->conn, "$cmd\r\n");
    }

    // ------------------------------------------------------------------------
    // @param  string $script_name
    // @param  string $script
    // @return void
    // ------------------------------------------------------------------------
    public function put_script($script_name, $script)
    {
        $cmd = sprintf('PUTSCRIPT "%s" {%d+}', $script_name, strlen($script));

        $this->put_line($cmd);
        $this->put_line($script);

        if ($this->get_status() != S_OK)
        {
            trigger_error("Sieve: Error putting script ($this->response)", E_USER_ERROR);
        }
    }

    // ------------------------------------------------------------------------
    // @return void
    // ------------------------------------------------------------------------
    private function get_line()
    {
        $this->response = fgets($this->conn, 1024);

        // Get rid of the trailing \r
        $this->response = substr($this->response, 0, -1);
    }

    // ------------------------------------------------------------------------
    // @return int
    // ------------------------------------------------------------------------
    private function get_status()
    {
        switch (substr($this->response, 0, 2))
        {
        case 'OK':
            return S_OK;
            break;

        case 'NO':
            return S_NO;
            break;

        default:
            break;
        }

        return S_ERROR;
    }

    // ------------------------------------------------------------------------
    // LISTSCRIPTS
    // "sieve_webmaster" ACTIVE
    // OK
    //
    // @return array   Will look like this:
    //                     array[sieve_webmaster]['name'] = sieve_webmaster
    //                     array[sieve_webmaster]['active'] = TRUE
    //                     array[sieve_webmaster]['script'] = <SIEVE SCRIPT>
    // ------------------------------------------------------------------------
    public function get_scripts()
    {
        $scripts = array();

        $this->put_line('LISTSCRIPTS');
        $this->get_line();

        while ($this->get_status() != S_OK)
        {
            // Grab the script name that's in quotes.
            $script_name = substr($this->response, 1, strrpos($this->response, '"')-1);

            $scripts[$script_name]['name'] = $script_name;

            if (strpos($this->response, 'ACTIVE') !== FALSE)
            {
                $scripts[$script_name]['active'] = TRUE;
            }
            else
            {
                $scripts[$script_name]['active'] = FALSE;
            }

            $this->get_line();
        }

        foreach ($scripts as $script_name => $sub)
        {
            $scripts[$script_name]['script'] = $this->get_script($script_name);
        }

        return $scripts;
    }

    // ------------------------------------------------------------------------
    // @param  string $script_name
    // @return string
    // ------------------------------------------------------------------------
    public function& get_script($script_name)
    {
        $script = '';

        $this->put_line("GETSCRIPT \"$script_name\"");
        $this->get_line();

        if ($this->get_status() == S_NO)
        {
            trigger_error("Sieve: Error retrieving script $script_name ($this->response)", E_USER_ERROR);
        }

        // The first line returned is the script length in characters, we don't care.
        $this->get_line();

        while ($this->get_status() != S_OK)
        {
            $script .= $this->response."\n";

            $this->get_line();
        }

        // Get rid of that last \n we put.
        if (strlen($script))
        {
            $script = substr($script, 0, -2);
        }

        return $script;
    }

    // ------------------------------------------------------------------------
    // Sets an uploaded script as the active one.
    //
    // @param  string $script_name
    // @return void
    // ------------------------------------------------------------------------
    public function set_active($script_name)
    {
        $this->put_line("SETACTIVE \"$script_name\"");

        if ($this->get_status() != S_OK)
        {
            trigger_error("Sieve: Error setting active $script_name ($this->response)", E_USER_ERROR);
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

        if ($this->conn == FALSE)
        {
            $this->error("$errstr ($errno)");
        }

        while ($this->get_status() != S_OK)
        {
            $this->get_line();
        }

        if ($this->get_status() != S_OK)
        {
            $this->error = $this->response;
            $this->status = F_ERROR;
        }

        $this->authenticate();
    }

    // ------------------------------------------------------------------------
    // Perform SASL authentication to SIEVE server.
    //
    // @return void
    // ------------------------------------------------------------------------
    private function authenticate()
    {

        $auth = base64_encode("\0$this->user\0$this->password");

        $this->put_line(sprintf('AUTHENTICATE "PLAIN" {%s+}', strlen($auth)));
        $this->put_line($auth);

        $this->get_line();

        if ($this->get_status() != S_OK)
        {
            trigger_error("Sieve: Error authenticating ($this->response)", E_USER_ERROR);
        }
    }
}
