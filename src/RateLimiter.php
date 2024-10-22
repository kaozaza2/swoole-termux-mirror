<?php

namespace Mikore\Apt;

class RateLimiter
{
    private static $attepmts = [];

    public static function attempt($uri, $ip, $response)
    {
        $timestamp = microtime(true);
        $window = Config::get('rate-limit-window', 60);
        $lockdown = Config::get('rate-limit-attempt', 5);
        $key = static::throttleKey($ip);

        if (! isset(static::$attepmts[$key])) {
            static::$attepmts[$key] = [0, $timestamp];
        }

        [$attempt, $lastTimestamp] = static::$attepmts[$key];

        if ($timestamp - $lastTimestamp > $window) {
            static::$attepmts[$key] = [1, $timestamp];
        } else {
            static::$attepmts[$key][0]++;
        }

        if ($attempt > $lockdown) {
            $remain = max(0, $window - ($timestamp - $lastTimestamp));
            $response->status(403);
            $response->end("Rate limiting reached, try again in $remain seconds.");
            return true;
        }

        return false;
    }

    public static function clear()
    {
        $attepmts = array_reverse(static::$attepmts);
        $window = Config::get('rate-limit-window', 60);
        $current = microtime(true);

        foreach ($attepmts as $key => $value) {
            [$attempt, $timestamp] = $value;

            if ($current - $timestamp < $window) {
                unset(static::$attepmts[$key]);
            }
        }
    }

    private static function throttleKey($ip)
    {
        return hash('md5', $ip);
    }
}
