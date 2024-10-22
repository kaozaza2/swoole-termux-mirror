<?php

namespace Mikore\Apt;

class ConsoleLogger
{
    public static function __callStatic($methodName, $arguments)
    {
        $prefix = ['i' => '[Info]', 'w' => '[Warn]', 'e' => '[Error]', 'd' => '[Debug]'];

        if (! isset($prefix[$methodName]) || count($arguments) === 0) {
            echo "[Error] Unsupported log level or no message provided.\n";
            return;
        }

        $message = implode("\n\n", $arguments);

        echo $prefix[$methodName] . " $message\n";
    }
}
