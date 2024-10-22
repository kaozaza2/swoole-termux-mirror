<?php

namespace Mikore\Apt;

class Config
{
    public static $config = [];

    public static function initialize()
    {
        static::$config = parse_ini_file(realpath(__DIR__.'/../config.ini'));
    }

    public static function get($key, $default = null)
    {
        if (! isset(static::$config[$key])) {
            return $default;
        }

        $value = static::$config[$key];

        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? floatval($value) : intval($value);
        }

        $lower = strtolower($value);
        if ($lower === 'false' || $lower === 'true') {
            return $lower === 'true';
        }

        return $value;
    }
}
