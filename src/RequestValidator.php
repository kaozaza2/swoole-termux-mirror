<?php

namespace Mikore\Apt;

class RequestValidator
{
    private static $botUserAgent = [
        'Googlebot', 'DuckDuckBot', 'bingbot', 'Baiduspider',
        'YandexBot', 'Yahoo! Slurp', 'Sogou web spider', 'Exabot', 'SeznamBot',
        'facebookexternalhit', 'Applebot', 'AhrefsBot', 'SemrushBot', 'MJ12bot',
        'PetalBot', 'MegaIndex.ru', 'MauiBot', 'Quora-Bot', 'DotBot', 'proximic;',
        'bot; snapchat;',
    ];

    private static $aptUserAgent = [
        'Termux-PKG/2.0 mirror-checker',
        'Debian APT-HTTP/1.3',
        'Debian APT-CURL/1.0',
        'nala/0.15.4',
    ];

    public static function validated($request, $response)
    {
        if (! in_array(strtoupper($request->method), ['GET', 'HEAD'])) {
            $response->status(405);
            $response->end("Method Not Allowed.");
            return false;
        }

        if (RateLimiter::attempt($request->path, $request->ip, $response)) {
            return false;
        }

        if (empty($request->ua)) {
            $response->status(403);
            $response->end("Unknown Request Not Allowed.");
            return false;
        }

        $isRobotics = array_reduce(static::$botUserAgent, fn($a, $n) => $a || str_contains($request->ua, $n), false);
        if ($isRobotics) {
            $response->header('X-Robots-Tag', 'noindex, nofollow, nosnippet, noarchive');
            $response->status(403);
            $response->end("Robot not allowed.");
            return false;
        }

        return true;
    }
    
    public static function exists($request, $response)
    {
        $basepath = Config::get('path', '/var/www/html');

        if ($request->path === false || (strpos($request->path, $basepath) !== 0 || strpos($request->path, '/..') !== false) || !file_exists($request->path)) {
            $response->status(404);
            $response->end("File not found.");
            return false;
        }

        return true;
    }

    public static function isAptRequest($ua)
    {
        foreach (static::$aptUserAgent as $aua) {
            if (str_starts_with($ua, $aua)) {
                return true;
            }
        }

        return false;
    }
}
