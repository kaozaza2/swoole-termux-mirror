<?php

error_reporting(E_ALL & ~E_NOTICE);
date_default_timezone_set('Asia/Bangkok');

use Swoole\Http\Server;
use Swoole\Timer;

// Configuration for the file server
define( 'APT_HOST', '0.0.0.0' );
define( 'APT_PORT', 46161 );
define( 'APT_PATH', '/var/www/html' );
define( 'APT_LOG_PATH', __DIR__ . '/logs' );
define( 'APT_RECORD_FILE', __DIR__ . '/data/record.json' );
define( 'APT_SPEED_LIMIT', (int)(1.5 * 1024 * 1024) ); // 1.5 MB/s
define( 'APT_RATE_LIMIT_WINDOW', 60 );
define( 'APT_RATE_LIMIT_ATTEMPT', 5 );
define( 'APT_HITS_MIN', 3 );
define( 'APT_HITS_CLEAR_SECONDS', 86400 );

$ratelimit = [];

if (!file_exists(APT_RECORD_FILE)) {
    touch(APT_RECORD_FILE);
}
$record = json_decode(file_get_contents(APT_RECORD_FILE), true) ?? [];

// Create Swoole server
$server = new Server(APT_HOST, APT_PORT);

Timer::tick(APT_HITS_CLEAR_SECONDS * 1000, fn() => clear_hits());
Timer::tick(APT_RATE_LIMIT_WINDOW * 1000, fn() => clear_rate_limit());

$server->on("start", function ($server) {
    echo "Server started at http://{$server->host}:{$server->port}\n";
});

$server->on("request", function ($request, $response) {
    handle_client($request, $response);
});

$server->start();

function clear_hits($force = false) {
    global $record;

    $timestamp = microtime(true);
    $lch = ($record['last_clear_hits'] ??= $timestamp);

    if ($timestamp - $lch > APT_HITS_CLEAR_SECONDS || $force) {
        $record['last_clear_hits'] = $timestamp;

        $reverse = array_reverse($record['uri_hits']);

        foreach ($reverse as $uri => $hit) {
            if ($hit <= APT_HITS_MIN) {
                unset($record['uri_hits'][$uri]);
            }
        }

        write_record_file();
    }
}

function clear_rate_limit() {
    global $ratelimit;

    $reverse = array_reverse($ratelimit);

    foreach ($reverse as $ip => $value) {
        [$attempt, $timestamp] = $value;

        if (microtime(true) - $timestamp < APT_RATE_LIMIT_WINDOW) {
            unset($ratelimit[$ip]);
        }
    }
}

function handle_client($request, $response) {
    $method = $request->server['request_method'];
    $uri = $request->server['request_uri'];
    $ip = $request->header['x-real-ip'] ?? $request->server['remote_addr'];
    $ua = $request->header['user-agent'] ?? '';
    $path = realpath(APT_PATH . urldecode(parse_url($uri, PHP_URL_PATH)));
    $compress = str_contains($request->header['accept-encoding'] ?? '', 'gzip');
    $range = $request->header['range'] ?? false;

    if (strtoupper($method) === 'DELETE' && handle_command(basename($uri))) {
        log_request($ip, $method, $uri, $ua);
        $response->status(201);
        $response->end("Command processed.");
        return;
    }

    // Checking method and user agent
    if (!is_supported_method($method, $response) || !is_valid_user_agent($ua, $response)) {
        return;
    }

    // Rate limitting on unknown path
    if (str_starts_with($path, '//') && is_rate_limited($ip, $response)) {
        return;
    }

    log_request($ip, $method, $uri, $ua);
    record($ip, $method, $uri, $ua);

    // Validate file path
    if (!is_valid_path($path, $response)) {
        return;
    }

    is_dir($path) ? show_indexing($response, $path, $compress)
                  : stream_file($response, $path, $range, $compress);
}

function handle_command($command) {
    global $record, $ratelimit;

    if ($command === "clear_hits") {
        clear_hits(true);
        return true;
    }

    if ($command === "clear_invalid_hits") {
        $reverse = array_reverse($record['uri_hits']);
        foreach ($reverse as $uri => $hit) {
            if (!file_exists(APT_PATH.$uri) || $uri === '/') {
                unset($record['uri_hits'][$uri]);
            }
        }
        write_record_file();
        return true;
    }

    if ($command === "clear_ua") {
        $record['unknown_ua_request'] = [];
        write_record_file();
        return true;
    }

    if ($command === "clear_rate") {
        $ratelimit = [];
        return true;
    }

    return false;
}

function is_supported_method($method, $response) {
    if (!in_array(strtoupper($method), ['GET', 'HEAD'])) {
        $response->status(405);
        $response->end("Method Not Allowed.");
        return false;
    }

    return true;
}

function is_valid_user_agent($ua, $response) {
    if (empty($ua)) {
        $response->status(403);
        $response->end("Unknown Request Not Allowed.");
        return false;
    }

    if (str_contains_any($ua, ['Googlebot', 'DuckDuckBot', 'bingbot', 'Baiduspider',
      'YandexBot', 'Yahoo! Slurp', 'Sogou web spider', 'Exabot', 'SeznamBot',
      'facebookexternalhit', 'Applebot', 'AhrefsBot', 'SemrushBot', 'MJ12bot',
      'PetalBot', 'MegaIndex.ru', 'MauiBot', 'Quora-Bot', 'DotBot', 'proximic;',
      'bot; snapchat;']))
    {
        $response->header('X-Robots-Tag', 'noindex, nofollow, nosnippet, noarchive');
        $response->status(403);
        $response->end("Robot not allowed.");
        return false;
    }

    return true;
}

function is_valid_path($path, $response) {
    if ($path === false || (strpos($path, APT_PATH) !== 0 || strpos($path, '/..') !== false) || !file_exists($path)) {
        $response->status(404);
        $response->end("File not found.");
        return false;
    }

    return true;
}

function is_rate_limited($ip, $response) {
    global $ratelimit;

    $timestamp = microtime(true);

    if (!isset($ratelimit[$ip])) {
        $ratelimit[$ip] = [0, $timestamp];
    }

    [&$attempt, &$lastTimestamp] = $ratelimit[$ip];

    if ($timestamp - $lastTimestamp > APT_RATE_LIMIT_WINDOW) {
        $attempt = 1;
        $lastTimestamp = $timestamp;
    } else {
        $attempt++;
    }

    if ($attempt > APT_RATE_LIMIT_ATTEMPT) {
        $remain = max(0, APT_RATE_LIMIT_WINDOW - ($timestamp - $lastTimestamp));
        $response->status(403);
        $response->end("Rate limiting reached, try again in $remain seconds.");
        return true;
    }

    return false;
}

function log_request($ip, $method, $uri, $ua) {
    $date = date('Y/m/d H:i:s');
    $log = "$ip [$date] - $method $uri\n";
    echo $log;
    file_put_contents(APT_LOG_PATH.'/request-' . date('Y-m-d') . '.log', $log, FILE_APPEND);
}

function show_indexing($response, $path, $compress) {
    $files = array_diff(scandir($path), ['.', '..']);

    $dirs = [];
    $filesList = [];

    // Separate directories and files
    foreach ($files as $file) {
        $fullPath = $path . '/' . $file;
        if (is_dir($fullPath)) {
            $dirs[] = $file;
        } else {
            $filesList[] = $file;
        }
    }

    // Sort directories and files
    sort($dirs);
    sort($filesList);

    $allFiles = array_merge($dirs, $filesList);
    $relativePath = str_replace(APT_PATH, '', $path);
    $title = $relativePath ? "/" . trim($relativePath, '/') : '/';

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow, nosnippet, noarchive"><title>Directory listing for '.$title.'</title><style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Arial",sans-serif;background-color:#f8f9fa;color:#333;line-height:1.6;padding:20px}h1{font-size:2em;margin-bottom:20px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:12px;text-align:left;border-bottom:1px solid #ccc;word-wrap:break-word;max-width:200px}th{background-color:#e9ecef}tr:hover{background-color:#f1f1f1}a{color:#007bff;text-decoration:none}a:visited{color:#7f00ff}a:hover{text-decoration:underline}</style></head><body>';
    $html .= "<h1>Directory listing for $title</h1><table><tr><th>File Name</th><th>Size</th><th>Last Modified</th></tr>";

    if (strlen($path) > strlen(APT_PATH) + 1) {
        $filePath = '/' . ltrim(htmlspecialchars(substr(dirname($path), strlen(APT_PATH))), '/');
        $html .= "<tr><td><a href=\"$filePath\">..</a></td><td></td><td></td></tr>";
    }

    foreach ($allFiles as $file) {
        $filePath = '/' . ltrim(htmlspecialchars($title . '/' . $file), '/');
        $fullPath = realpath($path . '/' . $file);
        $size = is_file($fullPath) ? human_filesize(filesize($fullPath)) : '';
        $modified = is_file($fullPath) ? date("d/m/Y H:i:s", filemtime($fullPath)) : '';
        $html .= "<tr><td><a href=\"$filePath\">$file</a></td><td>$size</td><td>$modified</td></tr>";
    }

    $html .= "</table></body></html>";

    $response->header("Content-Type", "text/html");

    if ($compress) {
        $response->header("Content-Encoding", "gzip");
        $html = gzencode($html);
    } else {
        $response->header("Content-Length", strlen($html));
    }

    $response->end($html);

    record_bytes(strlen($html));
}

function stream_file($response, $path, $range, $compress) {
    $fileSize = filesize($path);
    $mimeType = mime_content_type($path);

    // Check for Range header
    if ($range !== false) {
        [$start, $end] = explode('-', substr($range, 6));

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
        $response->header('Content-Disposition', 'attachment; filename="'.basename($path).'"');
    } else {
        // No range requested; send the whole file
        $response->header('Content-Type', $mimeType);
        $response->header('Content-Disposition', 'attachment; filename="'.basename($path).'"');
        $response->header('Accept-Ranges', 'bytes');
    }

    if ($compress) {
        $response->header("Content-Encoding", "gzip");
    }

    if ($fileSize > APT_SPEED_LIMIT) {
        $response->header("Transfer-Encoding", "chunked");
    }

    $file = @fopen($path, 'rb');

    if ($file !== false) {
        // Determine the bytes to send
        $bytesToSend = isset($end) ? $end - $start + 1 : $fileSize;

        // Set the file pointer if range is requested
        if ($range !== false) {
            fseek($file, $start);
        }

        while (!feof($file) && $bytesToSend > 0) {
            // Read a chunk respecting the speed limit (1.5 MB/s)
            $originalChunk = fread($file, min(APT_SPEED_LIMIT, $bytesToSend));

            // Compress if requested
            $chunk = $compress ? gzencode($originalChunk) : $originalChunk;

            // Send the chunk
            $write = $response->write($chunk);

            if ($write === false) {
                break; // Stop if writing fails
            }

            // Reduce bytes remaining to send based on the original chunk size
            $bytesToSend -= strlen($originalChunk);

            // Sleep to respect speed limit
            $chunkSize = strlen($originalChunk);  // Use the original chunk size
            record_bytes($chunkSize); // Record total bytes send to clients.
            $sleepTime = (1000000 * $chunkSize) / APT_SPEED_LIMIT + 1000; // microseconds to sleep + 1ms buffer
            usleep((int)$sleepTime);  // Sleep to limit the speed
        }

        $response->end();
    } else {
        $response->status(500);
        $response->end('Error reading file.');
    }
}

function record($ip, $method, $uri, $ua) {
    global $record;

    $date = date('Y-m-d');
    $col = explode('.', $ip);

    if (! isset($record['total_requests'][$date])) {
        $record['total_requests'][$date] = 0;
    }

    $markedIp = $col[0].'.'.$col[1].'.***.***';
    if (! isset($record['range_requests'][$markedIp])) {
        $record['range_requests'][$markedIp] = 0;
    }

    if (! isset($record['uri_hits'][$uri])) {
        $record['uri_hits'][$uri] = 0;
    }

    $record['total_requests'][$date]++;
    $record['range_requests'][$markedIp]++;
    $record['uri_hits'][$uri]++;

    if (!str_starts_with($ua, 'Termux-PKG/2.0 mirror-checker')
     && !str_starts_with($ua, 'Debian APT-HTTP/1.3')
     && !str_starts_with($ua, 'Debian APT-CURL/1.0')
     && !str_starts_with($ua, 'nala/0.15.4')) {
        $record['unknown_ua_request'][$ip][] = ['uri' => $method.' '.$uri, 'ua' => $ua];
    }

    arsort($record['range_requests']);
    krsort($record['uri_hits']);
    arsort($record['uri_hits']);

    write_record_file();
}

function record_bytes($bytesSend) {
    global $record;

    $date = date('Y-m-d');

    $value = bcadd($record['total_bytes_send'][$date] ?? '0', $bytesSend);
    $record['total_bytes_send'][$date] = $value;

    write_record_file();
}

function write_record_file() {
    global $record;

    file_put_contents(APT_RECORD_FILE, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function str_contains_any($haystack, $needles) {
    return array_reduce($needles, fn($a, $n) => $a || str_contains($haystack, $n), false);
}

function human_filesize($bytes, $dec = 2) {
    $sizes = ['B', 'kB', 'MB', 'GB', 'TB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$dec}f %s", $bytes / (1024 ** $factor), $sizes[$factor]);
}
