<?php

require __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Glitchbl\FtpClient;

final class FtpClientTest extends TestCase {
    /**
     * @var \Glitchbl\FtpClient
     */
    protected static $ftp_client;

    public static function setUpBeforeClass() : void
    {
        $server = 'ftp.server.com';
        $login = 'login';
        $password = 'password';

        if (is_file(__DIR__ . '/config.php'))
            require __DIR__ . '/config.php';
            
        self::$ftp_client = new FtpClient($server, $login, $password);
        self::$ftp_client->connect();

        
        file_put_contents(__DIR__ . '/file1', 'file1');
    }

    public static function tearDownAfterClass(): void
    {
        self::$ftp_client->close();
        self::$ftp_client = false;

        if (is_file(__DIR__ . '/file1'))
            unlink(__DIR__ . '/file1');
    }

    public function testDirectories()
    {
        self::$ftp_client->mkdir('folder1');
        $this->assertTrue(self::$ftp_client->isDirectory('folder1'));

        self::$ftp_client->rmdir('folder1');
        $this->assertFalse(self::$ftp_client->isDirectory('folder1'));

        self::$ftp_client->mkdir('folder1');
        self::$ftp_client->mkdir('/folder1/folder2');
        $this->assertTrue(self::$ftp_client->isDirectory('folder1'));
        $this->assertTrue(self::$ftp_client->isDirectory('folder1/folder2'));
        $this->assertTrue(self::$ftp_client->isDirectory('/folder1'));
        $this->assertTrue(self::$ftp_client->isDirectory('/folder1/folder2'));

        self::$ftp_client->chdir('folder1');
        $this->assertTrue(self::$ftp_client->isDirectory('folder2'));
        $this->assertTrue(self::$ftp_client->isDirectory('/folder1'));

        self::$ftp_client->rmdir('folder2');
        $this->assertFalse(self::$ftp_client->isDirectory('folder2'));
        
        self::$ftp_client->chdir('/');
        $this->assertTrue(self::$ftp_client->pwd() === '/');

        self::$ftp_client->rmdir('/folder1');
        $this->assertFalse(self::$ftp_client->isDirectory('folder1'));
    }

    public function testFiles()
    {
        self::$ftp_client->put(__DIR__ . '/file1');
        self::$ftp_client->put(__DIR__ . '/file1', 'file2');
        $this->assertTrue(self::$ftp_client->isFile('file1'));
        $this->assertTrue(self::$ftp_client->isFile('file2'));

        self::$ftp_client->delete('file1');
        $this->assertFalse(self::$ftp_client->isFile('file1'));

        $file2 = self::$ftp_client->files()[0];
        $this->assertEquals($file2, 'file2');

        self::$ftp_client->delete($file2);
        $this->assertFalse(self::$ftp_client->isFile('file2'));
    }

    public function testGetFiles()
    {
        self::$ftp_client->mkdir('folder1');

        self::$ftp_client->chdir('folder1');
        self::$ftp_client->put(__DIR__ . '/file1');

        self::$ftp_client->chdir('/');
        self::$ftp_client->put(__DIR__ . '/file1', '/folder1/file2');
        
        $this->assertTrue(self::$ftp_client->isFile('/folder1/file1'));
        $this->assertTrue(self::$ftp_client->isFile('folder1/file2'));

        $this->assertEquals(self::$ftp_client->files('folder1'), ['file1', 'file2']);

        self::$ftp_client->delete('/folder1/file1');
        self::$ftp_client->delete('folder1/file2');

        $this->assertEquals(self::$ftp_client->files('/folder1'), []);
        self::$ftp_client->rmdir('folder1');
    }

    public function testRename()
    {
        self::$ftp_client->put(__DIR__ . '/file1');
        self::$ftp_client->mkdir('folder1');
        self::$ftp_client->rename('file1', 'folder1/file2');
        $this->assertTrue(self::$ftp_client->isFile('folder1/file2'));

        self::$ftp_client->delete('folder1/file2');
        self::$ftp_client->rmdir('folder1');
    }
}