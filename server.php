<?php

error_reporting(E_ALL & ~E_NOTICE);
date_default_timezone_set('Asia/Bangkok');

require 'vendor/autoload.php';

use Swoole\Http\Server;
use Swoole\Timer;
use Mikore\Apt\Config;
use Mikore\Apt\ConsoleLogger;
use Mikore\Apt\CommandHandler;
use Mikore\Apt\RequestHandler;
use Mikore\Apt\RateLimiter;
use Mikore\Apt\Record\{LogRecord, JsonRecord, DatabaseRecord, StackRecord};

// Initialize configuration
Config::initialize();

// Create a record stack
$record = new StackRecord();
$record->add(LogRecord::class, JsonRecord::class, DatabaseRecord::class);

// Create and configure the server
$host = Config::get('host', '0.0.0.0');
$port = Config::get('port', 46161);
$server = new Server($host, $port);

// Setup periodic timers
$recordHitsClearInterval = Config::get('record-hits-clear-seconds', 86400) * 1000;
Timer::tick($recordHitsClearInterval, fn() => $record->clear());

$rateLimitWindow = Config::get('rate-limit-window', 60) * 1000;
Timer::tick($rateLimitWindow, fn() => RateLimiter::clear());

// Define server event handlers
$server->on("start", function ($server) {
    ConsoleLogger::i("Server started at http://{$server->host}:{$server->port}");
});

$server->on("request", function ($request, $response) use ($record) {
    CommandHandler::handle($request, $response, $record) 
        ?: RequestHandler::handle($request, $response, $record);
});

// Handle server shutdown
register_shutdown_function(function () use ($record, $server) {
    $record->close();
    $server->stop();
});

// Start the server
$server->start();
