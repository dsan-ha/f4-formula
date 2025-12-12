<?php
namespace App\Utils;

class FtpClient
{
    private $conn;

    public function __construct(
        private string $host,
        private string $login,
        private string $pass,
        private int $port = 21
    ) {
        $this->connect();
    }

    private function connect(): void
    {
        $this->conn = ftp_connect($this->host, $this->port);
        if (!$this->conn) {
            throw new \RuntimeException("FTP connect failed: {$this->host}:{$this->port}");
        }
        if (!ftp_login($this->conn, $this->login, $this->pass)) {
            ftp_close($this->conn);
            throw new \RuntimeException("FTP login failed for {$this->login}");
        }
        ftp_pasv($this->conn, true);
    }

    public function upload(string $local, string $remote): void
    {
        if (!ftp_put($this->conn, $remote, $local, FTP_BINARY)) {
            throw new \RuntimeException("FTP upload failed: $local → $remote");
        }
    }

    public function download(string $remote, string $local): void
    {
        if (!ftp_get($this->conn, $local, $remote, FTP_BINARY)) {
            throw new \RuntimeException("FTP download failed: $remote → $local");
        }
    }

    public function delete(string $remote): void
    {
        if (!ftp_delete($this->conn, $remote)) {
            throw new \RuntimeException("FTP delete failed: $remote");
        }
    }

    public function close(): void
    {
        if ($this->conn) {
            ftp_close($this->conn);
            $this->conn = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
