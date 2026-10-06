<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Also support public_html beside the private divan/ project on shared hosting.
$basePath = dirname(__DIR__);
if (! is_file($basePath.'/bootstrap/app.php')) {
    $basePath .= '/divan';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $basePath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $basePath.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $basePath.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
