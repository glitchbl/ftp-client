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
     * @var \Psr\Log\LoggerInterface|null Logger
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
     * @param \Psr\Log\LoggerInterface|null $logger Logger
     */
    function __construct($server, $login, $password, $port = 21, LoggerInterface $logger = null)
    {
        $this->server = $server;
        $this->login = $login;
        $this->password = $password;
        $this->port = $port;
        $this->logger = $logger;

        $this->cache = [];

        $this->connection = false;
    }

    function __destruct()
    {
        $this->close();
    }

    public function close()
    {
        if ($this->connection !== false) {
            ftp_close($this->connection);
            $this->connection = false;
            $this->log('info', "Disconnected from server {$this->server}:{$this->port}");
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
     * @throws \Exception
     */
    protected function log($type, $message)
    {
        if ($this->logger) {
            if (method_exists($this->logger, $type)) {
                call_user_func([$this->logger, $type], "FtpClient: {$message}");
            } else {
                throw new Exception("Logger has not '{$type}' method");
            }
        }
    }

    /**
     * @param boolean $pasv Enable passive mode
     * @param integer $timeout Connection timeout
     * @throws \Exception If failed to connect
     */
    public function connect($pasv = true, $timeout = 3600)
    {
        $this->connection = @ftp_connect($this->server, $this->port, $timeout);

        if ($this->connection === false) {
            throw new Exception("FtpClient: Failed to connect to {$this->server}:{$this->port}");
        } else {
            $this->log('info', "Connected to {$this->server}:{$this->port}");
            if (!@ftp_login($this->connection, $this->login, $this->password)) {
                throw new Exception("FtpClient: Failed to login with '{$this->login}'");
            }
            $this->log('info', "Logged with {$this->login}");
            if ($pasv) {
                if (@ftp_set_option($this->connection, FTP_USEPASVADDRESS, false) && @ftp_pasv($this->connection, true)) {
                    $this->log('info', 'Passive mode enabled');
                } else {
                    $this->log('warning', "Failed to enable passive mode");
                }
            }
        }
    }

    /**
     * @throws \Exception If connection is closed
     */
    protected function checkConnection()
    {
        if ($this->connection === false) {
            throw new Exception("FtpClient: Connection is closed");
        }
    }

    /**
     * @param string $directory Directory to nlist
     * @throws \Exception If problems executing nlist
     * @return array Raw list of files and directories
     */
    protected function nlist($directory)
    {
        $this->checkConnection();
        $nlist = @ftp_nlist($this->connection, $directory);
        if ($nlist === false) {
            throw new Exception("Failed to execute nlist for '{$directory}'");
        } else {
            return $nlist;
        }
    }

    /**
     * @param string $directory Directory to check
     * @throws \Exception If errors
     * @return boolean Return true if directory
     */
    public function isDirectory($directory)
    {
        $this->checkConnection();
        $pwd = $this->pwd();
        $is_dir = @ftp_chdir($this->connection, $directory);
        @ftp_chdir($this->connection, $pwd);
        return $is_dir;
    }

    /**
     * @param string $file File to check
     * @return boolean Return true if file
     */
    public function isFile($file)
    {
        $this->checkConnection();
        $parent_directory = dirname($file);
        $files = $this->files($parent_directory);
        return in_array(basename($file), $files);
    }

    /**
     * @param string $directory Directory where list files and directories
     * @param boolean $caching Caching or not the results
     * @return array Files and directories
     */
    public function files_directories($directory = '', $caching = true)
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

        if ($caching && isset($this->cache[$full_path]))
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

        $files_directories = compact('directories', 'files');
        $this->cache[$full_path] = $files_directories;
        return $files_directories;
    }

    /**
     * @param string $directory Directory where list files
     * @return array Files
     */
    public function files($directory = '')
    {
        $files_directories = $this->files_directories($directory);
        return $files_directories['files'];
    }
    
    /**
     * @param string $directory Directory where list directories
     * @return array Directories
     */
    public function directories($directory = '')
    {
        $files_directories = $this->files_directories($directory);
        return $files_directories['directories'];
    }

    /**
     * @throws \Exception If pwd fails
     * @return string Current directory
     */
    public function pwd()
    {
        $this->checkConnection();
        $pwd = @ftp_pwd($this->connection);
        if ($pwd === false) {
            throw new Exception("Failed to execute pwd");
        } else {
            return $pwd;
        }
    }

    /**
     * @param string $directory Directory to navigate to
     * @throws \Exception If impossible to change the current directory
     */
    public function chdir($directory)
    {
        $this->checkConnection();
        if (!@ftp_chdir($this->connection, $directory)) {
            throw new Exception("FtpClient: Failed to change the current directory to '{$directory}'");
        } else {
            $this->log('info', "Current directory changed to '{$directory}'");
        }
    }

    /**
     * @param string $server_file Server file to download
     * @param string $local_file Location where download the file
     * @throws \Exception If errors when downloading the file
     */
    public function get($server_file, $local_file)
    {
        $this->checkConnection();
        if (!@ftp_get($this->connection, $local_file, $server_file, FTP_BINARY)) {
            throw new Exception("FtpClient: Failed to download the server file '{$server_file}' to '{$local_file}'");
        } else {
            $this->log('info', "Server file '{$server_file}' downloaded to '{$local_file}'");
        }
    }

    /**
     * @param string $local_file File to upload
     * @param string|null $server_file Server location where upload
     * @throws \Exception If errors when uploading the file
     */
    public function put($local_file, $server_file = null)
    {
        $this->checkConnection();
        if (!$server_file)
            $server_file = basename($local_file);
        if (!@ftp_put($this->connection, $server_file, $local_file, FTP_BINARY)) {
            throw new Exception("FtpClient: Failed to upload the local file '{$local_file}' to '{$server_file}'");
        } else {
            $this->log('info', "Local file '{$local_file}' uploaded to '{$server_file}'");
        }
    }

    /**
     * @param string $file File to delete
     * @throws \Exception If impossible to delete file
     */
    public function delete($file)
    {
        $this->checkConnection();
        if (!@ftp_delete($this->connection, $file)) {
            throw new Exception("FtpClient: Failed to delete server file '{$file}'");
        } else {
            $this->log('info', "Server file '{$file}' deleted");
        }
    }

    /**
     * @param string $directory Directory to create
     * @throws \Exception If impossible to create directory
     */
    public function mkdir($directory)
    {
        $this->checkConnection();
        if (@ftp_mkdir($this->connection, $directory) === false) {
            throw new Exception("FtpClient: Failed to create directory '{$directory}' ");
        } else {
            $this->log('info', "Directory '{$directory}' created");
        }
    }

    /**
     * @param string $directory Directory to delete
     * @throws \Exception If impossible to delete directory
     */
    public function rmdir($directory)
    {
        $this->checkConnection();
        if (!@ftp_rmdir($this->connection, $directory)) {
            throw new Exception("FtpClient: Failed to delete directory '{$directory}'");
        } else {
            $this->log('info', "Directory '{$directory}' deleted");
        }
    }

    /**
     * @param string $oldname File or directory to rename
     * @param string $newname New name
     * @throws \Exception If errors when renaming the file or directory
     */
    public function rename($oldname, $newname)
    {
        $this->checkConnection();
        if (!@ftp_rename($this->connection, $oldname, $newname)) {
            throw new Exception("FtpClient: Failed to rename '{$oldname}' to '{$newname}'");
        } else {
            $this->log('info', "'{$oldname}' renamed to '{$newname}'");
        }
    }
}