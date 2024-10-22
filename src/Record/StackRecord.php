<?php

namespace Mikore\Apt\Record;

class StackRecord implements IRecord
{
    private $records = [];

    public function add(...$records)
    {
        foreach ($records as $record) {
            $this->records[] = new $record;
        }
    }

    public function record($request)
    {
        array_walk($this->records, fn($record) => $record->record($request));
    }

    public function recordBytes($bytesSend)
    {
        array_walk($this->records, fn($record) => $record->recordBytes($bytesSend));
    }

    public function clear($force = false)
    {
        array_walk($this->records, fn($record) => $record->clear($force));
    }

    public function clean()
    {
        array_walk($this->records, fn($r) => method_exists($r, 'clean') && $r->clean());
    }

    public function close()
    {
        array_walk($this->records, fn($record) => $record->close());
    }
}
