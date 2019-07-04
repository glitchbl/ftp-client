<?php

namespace Glitchbl;

use Psr\Log\LoggerInterface;
use Exception;

class FtpClient {
    /**
     * @var string FTP Server
     */
    protected $server;

    /**
     * @var string FTP Login
     */
    protected $login;
    
    /**
     * @var string FTP Password
     */
    protected $password;
    
    /**
     * @var integer FTP Port
     */
    protected $port;

    /**
     * @var mixed FTP Connection
     */
    protected $connection;

    /**
     * @var \Psr\Log\LoggerInterface Logger
     */
    protected $logger;

    /**
     * @var array Files list cache
     */
    private $cache;

    /**
     * @param string $server FTP Server
     * @param string $login FTP Login
     * @param string $password FTP Password
     * @param integer $port FTP Port
     * @param \Psr\Log\LoggerInterface $logger Logger
     */
    function __construct($server, $login, $password, $port = 21, LoggerInterface $logger = null)
    {
        $this->server = $server;
        $this->login = $login;
        $this->password = $password;
        $this->port = $port;
        $this->logger = $logger;

        $cache = [];

        $this->connection = false;
    }

    function __destruct() {
        if ($this->connection !== false) {
            ftp_close($this->connection);
            $this->log('info', "FtpClient: Disconnected from server {$this->server}:{$this->port}");
        }
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger Logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $type Log type
     * @param string $message Log message
     * @throws Exception
     */
    protected function log($type, $message)
    {
        if ($this->logger) {
            if (method_exists($this->logger, $type)) {
                call_user_func([$this->logger, $type], $message);
            } else {
                throw new Exception("Logger has not '{$type}' method");
            }
        }
    }

    /**
     * @param bool $pasv Enable passive mode
     * @throws Exception If connection is impossible
     */
    public function connect($pasv = true)
    {
        $this->connection = @ftp_connect($this->server, $this->port, 3600);

        if ($this->connection === false) {
            throw new Exception("FtpClient: Connection to {$this->server}:{$this->port} impossible");
        } else {
            $this->log('info', "FtpClient: Connected to {$this->server}:{$this->port}");
            if (!@ftp_login($this->connection, $this->login, $this->password)) {
                throw new Exception("FtpClient: Login to {$this->server}:{$this->port} impossible with credentials");
            }
            $this->log('info', "FtpClient: Logged with {$this->login}");
            if ($pasv) {
                if (@ftp_set_option($this->connection, FTP_USEPASVADDRESS, false) && @ftp_pasv($this->connection, true)) {
                    $this->log('info', 'FtpClient: Passive mode enabled');
                } else {
                    $this->log('warning', "FtpClient: Enabling passive mode impossible");
                }
            }
        }
    }

    /**
     * @throws Exception If connection is closed
     */
    protected function checkConnection()
    {
        if ($this->connection === false) {
            throw new Exception("FtpClient: Can't execute command on a closed connection");
        }
    }

    /**
     * @param string $directory Directory to nlist
     * @throws Exception If problem with nlist
     * @return array Files and directories of the directory
     */
    protected function nlist($directory)
    {
        $this->checkConnection();
        $nlist = ftp_nlist($this->connection, $directory);
        if ($nlist === false) {
            throw new Exception("Impossible to execute nlist to '{$directory}' directory");
        } else {
            return $nlist;
        }
    }

    /**
     * @param string $directory Directory to check
     * @throws Exception If errors
     * @return bool Return true if directory
     */
    public function isDirectory($directory)
    {
        $this->checkConnection();
        $pwd = $this->pwd();
        $is_dir = @ftp_chdir($this->connection, $directory);
        $this->chdir($pwd);
        return $is_dir;
    }

    /**
     * @param string $file File to check
     * @return bool Return true if file
     */
    public function isFile($file)
    {
        $this->checkConnection();
        $parent_directory = dirname($file);
        $files = $this->files($parent_directory);
        return in_array(basename($file), $files);
    }

    /**
     * @param string $directory Directory where list directories and files
     * @return array Directories and files of the directory
     */
    public function directories_files($directory = '')
    {
        $this->checkConnection();
        if (isset($directory[0]) && $directory[0] === '/') {
            $full_path = $directory;
        } else {
            $pwd = $this->pwd();
            if ($directory === '' || $directory === '.') {
                $full_path = $pwd;
            } else {
                if ($pwd === '/') {
                    $full_path = "/{$directory}";
                } else {
                    $full_path = "{$pwd}/{$directory}";
                }
            }
        }
        $full_path = rtrim($full_path, '/');

        if (isset($this->cache[$full_path]))
            return $this->cache[$full_path];

        $raw_lists = $this->nlist($directory);

        $directories = [];
        $files = [];

        foreach ($raw_lists as $item) {
            if (in_array($item, ['.', '..']))
                continue;
            $file = $directory !== '' && $directory !== '/'? "{$directory}/{$item}": $item;
            if ($this->isDirectory($file)) {
                $directories[] = $item;
            } else {
                $files[] = $item;
            }
        }

        $directories_files = compact('directories', 'files');
        $this->cache[$full_path] = $directories_files;
        return $directories_files;
    }

    /**
     * @param string $directory Directory where list files
     * @return array Files of the directory
     */
    public function files($directory = '')
    {
        $directories_files = $this->directories_files($directory);
        return $directories_files['files'];
    }
    
    /**
     * @param string $directory Directory where list directories
     * @return array Directories of the directory
     */
    public function directories($directory = '')
    {
        $directories_files = $this->directories_files($directory);
        return $directories_files['directories'];
    }

    /**
     * @throws Exception If pwd fails
     * @return string Current directory
     */
    public function pwd()
    {
        $this->checkConnection();
        $pwd = @ftp_pwd($this->connection);
        if ($pwd === false) {
            throw new Exception("Impossible to execute {$pwd}");
        } else {
            return $pwd;
        }
    }

    /**
     * @param string $directory Directory to navigate to
     * @throws Exception If impossible to change the current directory
     */
    public function chdir($directory)
    {
        $this->checkConnection();
        if (!@ftp_chdir($this->connection, $directory)) {
            throw new Exception("FtpClient: Impossible to change the current directory to '{$directory}'");
        } else {
            $this->log('info', "FtpClient: Current directory changed to '{$directory}'");
        }
    }

    /**
     * @param string $server_file Server file to download
     * @param string $local_file Location where download the file
     * @throws Exception If errors when downloading the file
     */
    public function get($server_file, $local_file)
    {
        $this->checkConnection();
        if (!@ftp_get($this->connection, $local_file, $server_file, FTP_BINARY)) {
            throw new Exception("FtpClient: Impossible to download the server file '{$server_file}' to '{$local_file}'");
        } else {
            $this->log('info', "FtpClient: File '{$server_file}' downloaded to '{$local_file}'");
        }
    }

    /**
     * @param string $local_file File to upload
     * @param string|null $server_file Server location where upload
     * @throws Exception If errors when uploading the file
     */
    public function put($local_file, $server_file = null)
    {
        $this->checkConnection();
        if (!$server_file)
            $server_file = basename($local_file);
        if (!@ftp_put($this->connection, $server_file, $local_file, FTP_BINARY)) {
            throw new Exception("FtpClient: Impossible to upload the local file '{$local_file}' to server file '{$server_file}'");
        } else {
            $this->log('info', "FtpClient: File '{$local_file}' uploaded to '{$server_file}'");
        }
    }

    /**
     * @param string $file File to delete
     * @throws Exception If impossible to delete file
     */
    public function delete($file)
    {
        $this->checkConnection();
        if (!@ftp_delete($this->connection, $file)) {
            throw new Exception("FtpClient: Impossible to delete server file '{$file}'");
        } else {
            $this->log('info', "FtpClient: Server file '{$file}' deleted");
        }
    }

    /**
     * @param string $directory Directory to create
     * @throws Exception If impossible to create directory
     */
    public function mkdir($directory)
    {
        $this->checkConnection();
        if (@ftp_mkdir($this->connection, $directory) === false) {
            throw new Exception("FtpClient: Impossible to create '{$directory}' directory");
        } else {
            $this->log('info', "FtpClient: Directory '{$directory}' created");
        }
    }

    /**
     * @param string $directory Directory to delete
     * @throws Exception If impossible to delete directory
     */
    public function rmdir($directory)
    {
        $this->checkConnection();
        if (!@ftp_rmdir($this->connection, $directory)) {
            throw new Exception("FtpClient: Impossible to delete '{$directory}' directory");
        } else {
            $this->log('info', "FtpClient: Directory '{$directory}' deleted");
        }
    }

    /**
     * @param string $oldname Directory or file to rename
     * @param string $newname New name
     * @throws Exception If errors when renaming the file or directory
     */
    public function rename($oldname, $newname)
    {
        $this->checkConnection();
        if (!@ftp_rename($this->connection, $oldname, $newname)) {
            throw new Exception("FtpClient: Impossible to rename the directory/file '{$oldname}' to '{$newname}'");
        } else {
            $this->log('info', "FtpClient: Directory/file '{$oldname}' renamed to '{$newname}'");
        }
    }
}