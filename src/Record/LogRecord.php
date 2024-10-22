<?php

namespace Mikore\Apt\Record;

use Mikore\Apt\Config;
use Mikore\Apt\ConsoleLogger;

class LogRecord implements IRecord
{
    public $path;

    public function __construct()
    {
        $date = date('Y-m-d');
        $this->path = Config::get('log', realpath(__DIR__ . '/../../logs')) . "/request-$date.log";
    }

    public function record($request)
    {
        $date = date('Y/m/d H:i:s');
        $log = "[$date] {$request->ip} - {$request->method} {$request->uri}\n";

        ConsoleLogger::i($log);
        file_put_contents($this->path, $log, FILE_APPEND);
    }

    public function recordBytes($bytesSend)
    {
        // unused
    }

    public function clear($force = false)
    {
        // unused
    }

    public function close()
    {
        // unused
    }
}
