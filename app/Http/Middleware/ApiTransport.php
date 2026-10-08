<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;

class ApiTransport
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->method() === 'OPTIONS') {
            return response('', 204);
        }
        if ($request->query('action') && config('divan.require_https') && ! $request->secure()) {
            throw new ApiError(426, 'https_required', 'ورود و مدیریت فقط با اتصال HTTPS انجام می‌شود.');
        }
        $maxRequest = $request->query('action') === 'media_upload' ? 60000000 : 6000000;
        if ((int) $request->header('Content-Length', 0) > $maxRequest) {
            throw new ApiError(413, 'request_too_large', 'حجم درخواست بیش از حد مجاز است.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
