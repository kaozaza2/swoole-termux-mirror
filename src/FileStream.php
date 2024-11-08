<?php

namespace Mikore\Apt;

class FileStream
{
    private static $closed = [];

    public static function stream($request, $response, $fd, $record)
    {
        $fileSize = filesize($request->path);
        $fileMTime = gmdate('D, d M Y H:i:s T', filemtime($request->path));
        $fileHash = md5_file($request->path);
        $speedLimit = Config::get('speed-limit', 1 * 1024 * 1024);
        $speedLimitDisabled = Config::get('speed-limit-disabled', false);

        if (! is_null($request->etag) && $fileHash == $request->etag) {
            $response->status(304);
            $response->end('Not Modified.');
            return;
        }

        if (! is_null($request->etagMatch) && $fileHash != $request->etagMatch) {
            $response->status(412);
            $response->end('Precondition Failed.');
            return;
        }

        $response->header('ETag', $fileHash);
        $response->header('Last-Modified', $fileMTime);

        if ($request->method === 'HEAD') {
            $response->status(200);
            $response->header('Content-Type', 'application/octet-stream');
            $response->header('Content-Length', $fileSize);
            $response->header('Accept-Ranges', 'bytes');
            $response->end();
            return;
        }

        if ($request->range !== false) {
            [$start, $end] = explode('-', substr($request->range, 6));

            $start = (int)$start;
            $end = (int)($end ?: $fileSize - 1);

            // Validate the range
            if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
                $response->status(416);
                $response->end('Requested Range Not Satisfiable.');
                return;
            }

            // Set headers for partial content
            $response->status(206);
            $response->header('Content-Range', "bytes $start-$end/$fileSize");
            $response->header('Content-Type', 'application/octet-stream');
            $response->header('Content-Disposition', 'attachment; filename="' . basename($request->path) . '"');
        } else {
            // No range requested; send the whole file
            $response->header('Content-Type', 'application/octet-stream');
            $response->header('Content-Disposition', 'attachment; filename="' . basename($request->path) . '"');
            $response->header('Accept-Ranges', 'bytes');
        }

        if ($request->compress) {
            $response->header("Content-Encoding", "gzip");
        }

        $bytesToSend = isset($end) ? $end - $start + 1 : $fileSize;
        if ($bytesToSend > $speedLimit) {
            $response->header("Transfer-Encoding", "chunked");
        } else if (! $request->compress) {
            $response->header('Content-Length', $bytesToSend);
        }

        $file = fopen($request->path, 'rb');

        if ($file !== false) {

            if ($request->range !== false) {
                fseek($file, $start);
            }

            while (!feof($file) && $bytesToSend > 0) {
                $originalChunk = fread($file, min($speedLimit, $bytesToSend));
                $chunk = $request->compress ? gzencode($originalChunk) : $originalChunk;

                $response->write($chunk);

                // Check if the client has disconnected during the loop
                if (in_array($fd, static::$closed, true)) {
                    static::$closed = array_diff(static::$closed, [$fd]);
                    break;
                }

                $bytesToSend -= strlen($originalChunk);
                $chunkSize = strlen($chunk);
                $record->recordBytes($chunkSize);

                if (!$speedLimitDisabled) {
                    $sleepTime = (1000000 * $chunkSize) / $speedLimit; // microseconds to sleep + 1ms buffer
                    usleep((int)$sleepTime);  // Sleep to limit the speed
                }
            }

            $response->end();
        } else {
            $response->status(500);
            $response->end('Service Unavailable.');
        }
    }

    public static function close($clientFd)
    {
        array_push(static::$closed, $clientFd);
    }
}
