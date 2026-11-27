<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (@ini_get('upload_max_filesize') && (int) @ini_get('upload_max_filesize') < 64) {
    @ini_set('upload_max_filesize', '64M');
}
if (@ini_get('post_max_size') && (int) @ini_get('post_max_size') < 64) {
    @ini_set('post_max_size', '64M');
}
if (@ini_get('memory_limit') && (int) @ini_get('memory_limit') < 512) {
    @ini_set('memory_limit', '512M');
}
if ((int) @ini_get('max_execution_time') > 0 && (int) @ini_get('max_execution_time') < 120) {
    @ini_set('max_execution_time', '120');
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
