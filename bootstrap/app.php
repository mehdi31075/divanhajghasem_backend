<?php

use App\Exceptions\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: fn () => require __DIR__.'/../routes/divan.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API receives raw passwords and HTML; global trimming would change them.
        $middleware->remove([TrimStrings::class,
            ConvertEmptyStringsToNull::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api.php', 'mobile-api.php', 'public/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (ApiError $error, Request $request) => response()->json([
            'ok' => false, 'error' => $error->errorCode, 'message' => $error->getMessage(),
        ], $error->status));
    })->create();

// Use the same public path for Artisan and HTTP after public/ is moved to public_html.
$sharedPublicPath = dirname(__DIR__, 2).'/public_html';
if (! is_dir($app->publicPath()) && is_dir($sharedPublicPath)) {
    $app->usePublicPath($sharedPublicPath);
}

return $app;
