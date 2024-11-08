<?php

namespace Mikore\Apt;

use RuntimeException;

class Config
{
    private static $config = [];

    public static function initialize($configPath = null)
    {
        $configPath = $configPath ?? realpath(__DIR__.'/../config.ini');
        $config = parse_ini_file($configPath);

        if ($config === false) {
            throw new RuntimeException("Failed to parse config file: $configPath");
        }

        static::$config = $config;
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
        if (in_array($lower, ['false', 'true', 'yes', 'no'], true)) {
            return filter_var($lower, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return $value;
    }

    public static function set($key, $value)
    {
        static::$config[$key] = $value;

        return $value;
    }
}
