<?php

namespace Mikore\Apt\Record;

interface IRecord
{
    public function record($request);

    public function recordBytes($bytesSend);

    public function clear($force = false);

    public function close();
}
