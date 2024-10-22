<?php

namespace Mikore\Apt;

class AptRequest
{
    public $method;
    public $uri;
    public $ip;
    public $ua;
    public $path;
    public $compress;
    public $range;

    public function __construct($request)
    {
        $basepath = Config::get('path', '/var/www/html');

        $this->method = $request->server['request_method'];
        $this->uri = $request->server['request_uri'];
        $this->ip = $request->header['x-real-ip'] ?? $request->server['remote_addr'];
        $this->ua = $request->header['user-agent'] ?? '';
        $this->path = realpath($basepath . urldecode(parse_url($this->uri, PHP_URL_PATH)));
        $this->compress = str_contains($request->header['accept-encoding'] ?? '', 'gzip');
        $this->range = $request->header['range'] ?? false;
    }
}
