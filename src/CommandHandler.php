<?php

namespace Mikore\Apt;

class CommandHandler
{
    public static function handle($request, $response, $record)
    {
        $req = new AptRequest($request);
        $command = basename($req->uri);

        if ($req->method === 'DELETE') {
            if ($command === "clear_hits") {
                $record->clear(true);
                return static::ok($response);
            }

            if ($command === "clear_rate_limit") {
                RateLimiter::clear();
                return static::ok($response);
            }

            if ($command === "cleanup") {
                $record->clean();
                return static::ok($response);
            }
        }

        return false;
    }

    private static function ok($response)
    {
        $response->status(201);
        $response->end("Command processed.");

        return true;
    }
}
