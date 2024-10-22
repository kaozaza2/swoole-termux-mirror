<?php

namespace Mikore\Apt\Record;

use Mikore\Apt\Config;
use Mikore\Apt\RequestValidator;

class JsonRecord implements IRecord
{
    private $record;
    private $path;

    public function __construct()
    {
        $path = Config::get('record', realpath(__DIR__ . '/../../data/record.json'));

        if (! file_exists($path)) {
            touch($path);
        }

        $this->path = $path;
        $this->record = json_decode(file_get_contents($path), true) ?? [];
    }

    public function record($request)
    {
        $date = date('Y-m-d');
        $ipColumn = explode('.', $request->ip);

        if (! isset($this->record['total_requests'][$date])) {
            $this->record['total_requests'][$date] = 0;
        }

        $markedIp = $ipColumn[0].'.'.$ipColumn[1].'.***.***';
        if (! isset($this->record['range_requests'][$markedIp])) {
            $this->record['range_requests'][$markedIp] = 0;
        }

        if (! isset($this->record['uri_hits'][$request->uri])) {
            $this->record['uri_hits'][$request->uri] = 0;
        }

        $this->record['total_requests'][$date]++;
        $this->record['range_requests'][$markedIp]++;
        $this->record['uri_hits'][$request->uri]++;

        if (! RequestValidator::isAptRequest($request->ua)) {
            if (! isset($this->record['unknown_ua_request'][$request->ip])) {
                $this->record['unknown_ua_request'][$request->ip] = [];
            }

            array_push(
                $this->record['unknown_ua_request'][$request->ip],
                ['uri' => $request->method.' '.$request->uri, 'ua' => $request->ua]
            );
        }

        arsort($this->record['range_requests']);
        krsort($this->record['uri_hits']);
        arsort($this->record['uri_hits']);

        $this->save();
    }

    public function recordBytes($bytesSend)
    {
        $date = date('Y-m-d');

        $value = bcadd($this->record['total_bytes_send'][$date] ?? '0', $bytesSend);
        $this->record['total_bytes_send'][$date] = $value;

        $this->save();
    }

    public function clean()
    {
        $basepath = Config::get('path', '/var/www/html');
        $hits =  $this->record['uri_hits'];

        foreach ($hits as $uri => $hit) {
            if (! file_exists($basepath.$uri) || ! is_file($basepath.$uri)) {
                unset($this->record['uri_hits'][$uri]);
            }
        }

        $this->record['unknown_ua_request'] = [];

        $this->save();
    }

    public function clear($force = false)
    {
        $timestamp = microtime(true);
        $clearSeconds = Config::get('record-hits-clear-seconds', 86400);
        $clearMin = Config::get('record-hits-min', 3);
        $lch = ($this->record['last_clear_hits'] ??= $timestamp);

        if ($timestamp - $lch > $clearSeconds || $force) {
            $this->record['last_clear_hits'] = $timestamp;

            $hits = $this->record['uri_hits'];

            foreach ($hits as $uri => $hit) {
                if ($hit <= $clearMin) {
                    unset($this->record['uri_hits'][$uri]);
                }
            }

            $this->save();
        }
    }

    public function close()
    {
        $this->save();
    }

    public function save()
    {
        file_put_contents($this->path, json_encode($this->record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
