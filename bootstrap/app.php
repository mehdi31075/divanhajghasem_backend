<?php

use App\Exceptions\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
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
