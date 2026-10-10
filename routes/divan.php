<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\ContentPageController;
use App\Http\Controllers\ReaderController;
use App\Http\Middleware\ApiTransport;
use Illuminate\Support\Facades\Route;

// No web/session middleware: Bearer is the only authorization mechanism.
Route::middleware(ApiTransport::class)->group(function () {
    Route::match(['GET', 'OPTIONS'], '/api.php', ReaderController::class);
    Route::match(['GET', 'OPTIONS'], '/pages.php', ContentPageController::class);
    // The shipped Android APK concatenates its base URL into //api.php.
    // Serve the same public reader directly; a redirect would break old clients.
    Route::match(['GET', 'OPTIONS'], '/{legacySlashes}api.php', ReaderController::class)
        ->where('legacySlashes', '/+');
    Route::match(['GET', 'POST', 'OPTIONS'], '/mobile-api.php', ApiController::class);
});

$downloadApp = function () {
    $candidates = [
        storage_path('app/apk/divan-ansaralhossein.apk'),
        public_path('download/divan-ansaralhossein.apk'),
        base_path('../divan-ansaralhossein.apk'),
        base_path('../build/app/outputs/flutter-apk/app-release.apk'),
    ];
    foreach ($candidates as $path) {
        if (file_exists($path) && is_readable($path)) {
            return response()->download($path, 'divan-ansaralhossein.apk', [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'no-cache, must-revalidate',
            ]);
        }
    }
    abort(404, 'فایل اپلیکیشن هنوز روی سرور قرار نگرفته است.');
};

Route::get('/download/app', $downloadApp)->name('app.download');
Route::get('/download/divan.apk', $downloadApp);
Route::get('/app.apk', $downloadApp);

$panel = fn () => response()->view('panel')->header('Cache-Control', 'no-store')
    ->header('X-Content-Type-Options', 'nosniff')->header('X-Frame-Options', 'DENY');
Route::get('/', $panel)->name('panel');
// Old bookmarks still open the new panel. They do not accept cookie mutations.
foreach (['index', 'dashboard', 'story', 'category', 'admin', 'setting', 'add-menu', 'edit-menu', 'delete-menu', 'menu-detail', 'add-category', 'edit-category', 'delete-category', 'logout'] as $name) {
    Route::get('/'.$name.'.php', $panel);
    Route::post('/'.$name.'.php', fn () => response()->json([
        'ok' => false, 'error' => 'legacy_panel_retired', 'message' => 'از پنل جدید و ورود با توکن استفاده کنید.',
    ], 410));
}
Route::any('/public/{path}', fn () => response()->json(['ok' => false, 'error' => 'legacy_panel_retired'], 410))->where('path', '.*');
