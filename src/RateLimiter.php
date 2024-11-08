<?php

namespace Mikore\Apt;

class RateLimiter
{
    private static $attempts = [];

    public static function attempt($request, $response)
    {
        $timestamp = microtime(true);
        $window = Config::get('rate-limit-window', 60);
        $lockdown = Config::get('rate-limit-attempt', 5);
        $key = static::throttleKey($request->ua);
        $basepath = Config::get('path', '/var/www/html');

        if (strpos($request->path, '//') === false
           && strpos($request->path, '/..') === false
           && file_exists($request->path)) {
            return false;
        }

        if (! isset(static::$attempts[$key])) {
            static::$attempts[$key] = [0, $timestamp];
        }

        if ($timestamp - static::$attempts[$key][1] > $window) {
            static::$attempts[$key] = [1, $timestamp];
        } else {
            static::$attempts[$key][0]++;
        }

        if (static::$attempts[$key][0] > $lockdown) {
            $remain = max(0, $window - ($timestamp - static::$attempts[$key][1]));
            $response->status(403);
            $response->end("Rate limiting reached, try again in $remain seconds.");
            return true;
        }

        return false;
    }

    public static function clear()
    {
        $attempts = static::$attempts;
        $window = Config::get('rate-limit-window', 60);
        $current = microtime(true);

        foreach ($attempts as $key => $value) {
            [$attempt, $timestamp] = $value;

            if ($current - $timestamp > $window) {
                unset(static::$attempts[$key]);
            }
        }
    }

    private static function throttleKey($ua)
    {
        return hash('sha256', $ua);
    }
}
