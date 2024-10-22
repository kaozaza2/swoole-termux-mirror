<?php

namespace Mikore\Apt;

class RequestHandler
{
    public static function handle($request, $response, $record)
    {
        $aptRequest = new AptRequest($request);

        if (! RequestValidator::validated($aptRequest, $response)) {
            return;
        }

        $record->record($aptRequest);

        if (! RequestValidator::exists($aptRequest, $response)) {
            return;
        }

        is_dir($aptRequest->path)
            ? DirectoryIndexer::index($aptRequest, $response, $record)
            : FileStream::stream($aptRequest, $response, $record);
    }
}
