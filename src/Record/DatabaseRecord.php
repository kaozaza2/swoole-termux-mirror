<?php

namespace Mikore\Apt\Record;

use Mikore\Apt\Config;
use Mikore\Apt\ConsoleLogger;
use Mikore\Apt\RequestValidator;
use PDO;
use PDOException;

class DatabaseRecord implements IRecord
{
    public $db;

    public function __construct()
    {
        $path = Config::get('db', realpath(__DIR__ . '/../../data/database.db'));

        $shouldRunMigration = false;
        if (!file_exists($path)) {
            touch($path);
            $shouldRunMigration = true;
        }

        $this->db = new PDO("sqlite:$path");
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($shouldRunMigration) {
            $this->migration();
        }
    }

    public function record($request)
    {
        $requestIsApt = RequestValidator::isAptRequest($request->ua);

        $this->execute(
            'INSERT INTO requests (ip_address, user_agent, is_apt, request_method, request_uri) VALUES (:ip_address, :user_agent, :is_apt, :request_method, :request_uri);',
            [':ip_address' => $request->ip, ':user_agent' => $request->ua, ':is_apt' => $requestIsApt, ':request_method' => $request->method, ':request_uri' => $request->uri]
        );
    }

    public function recordBytes($bytesSend)
    {
        $date = date('Y-m-d');

        $this->execute(
            'INSERT INTO data_transfer (date, total_bytes_sent) VALUES (:date, :totalBytes) ON CONFLICT(date) DO UPDATE SET total_bytes_sent = total_bytes_sent + excluded.total_bytes_sent;',
            [':date' => $date, ':totalBytes' => $bytesSend]
        );
    }

    public function clear($force = false)
    {
        // unused
    }

    public function close()
    {
        $this->db = null;
    }

    private function execute($query, $data)
    {
        try {
            $statement = $this->db->prepare($query);
            $statement->execute($data);
        } catch (PDOException $e) {
            ConsoleLogger::e('[database]: ' . $e->getMessage());
        }
    }

    private function migration()
    {
        try {
            $this->db->exec('
CREATE TABLE IF NOT EXISTS requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    user_agent TEXT NOT NULL,
    is_apt BOOLEAN NOT NULL,
    request_method TEXT NOT NULL,
    request_uri TEXT NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
);');

            $this->db->exec('CREATE INDEX idx_requests_ip ON requests (ip_address);');
            $this->db->exec('CREATE INDEX idx_requests_uri ON requests (request_uri);');

            $this->db->exec('
CREATE TABLE IF NOT EXISTS data_transfer (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL UNIQUE,
    total_bytes_sent INTEGER NOT NULL
);');

            $this->db->exec('CREATE INDEX idx_data_transfer_date ON data_transfer (date);');
        } catch (PDOException $e) {
            ConsoleLogger::e('[database][init]: ' . $e->getMessage());
        }
    }
}
