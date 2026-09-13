<?php

use Illuminate\Http\Request;

ini_set('max_execution_time', 180000);
ini_set('upload_max_filesize', 180000);
ini_set('post_max_size', 180000);

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
