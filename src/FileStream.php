<?php

namespace Mikore\Apt;

class FileStream
{
    public static function stream($request, $response, $record)
    {
        $fileSize = filesize($request->path);
        $mimeType = mime_content_type($request->path);
        $speedLimit = Config::get('speed-limit', 1 * 1024 * 1024);

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
            $response->header('Content-Type', $mimeType);
            $response->header('Content-Disposition', 'attachment; filename="'.basename($request->path).'"');
        } else {
            // No range requested; send the whole file
            $response->header('Content-Type', $mimeType);
            $response->header('Content-Disposition', 'attachment; filename="'.basename($request->path).'"');
            $response->header('Accept-Ranges', 'bytes');
         }

        if ($request->compress) {
            $response->header("Content-Encoding", "gzip");
        }

        if ($fileSize > $speedLimit) {
            $response->header("Transfer-Encoding", "chunked");
        }

        $file = fopen($request->path, 'rb');

        if ($file !== false) {
            // Determine the bytes to send
            $bytesToSend = isset($end) ? $end - $start + 1 : $fileSize;

            $response->header('Content-Length', $bytesToSend);

            // Set the file pointer if range is requested
            if ($request->range !== false) {
                fseek($file, $start);
            }

            while (!feof($file) && $bytesToSend > 0) {
                // Read a chunk respecting the speed limit
                $originalChunk = fread($file, min($speedLimit, $bytesToSend));

                // Compress if requested
                $chunk = $request->compress ? gzencode($originalChunk) : $originalChunk;

                // Send the chunk
                $write = $response->write($chunk);

                if ($write === false) {
                    break; // Stop if writing fails
                }

                // Reduce bytes remaining to send based on the original chunk size
                $bytesToSend -= strlen($originalChunk);

                // Sleep to respect speed limit
                $chunkSize = strlen($originalChunk);  // Use the original chunk size

                $record->recordBytes($chunkSize);

                $sleepTime = (1000000 * $chunkSize) / $speedLimit + 1000; // microseconds to sleep + 1ms buffer
                usleep((int)$sleepTime);  // Sleep to limit the speed
            }

            $response->end();
        } else {
            $response->status(500);
            $response->end('Error reading file.');
        }
    }
}
