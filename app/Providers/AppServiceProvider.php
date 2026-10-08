<?php

namespace App\Providers;

use App\Exceptions\ApiError;
use App\Models\User;
use App\Services\CategoryImages;
use App\Services\ContentMedia;
use App\Services\DivanApi;
use App\Services\DivanRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Laravel merges built-in connections into config/database.php.
        // Keep only the drivers this backend actually uses, including after config:cache.
        $config = $this->app['config'];
        $config->set('database.connections', array_intersect_key(
            $config->get('database.connections'), array_flip(['mysql', 'mariadb']),
        ));
        $this->app->bind(DivanApi::class, fn ($app) => new DivanApi(
            $app->make(DivanRepository::class), config('divan.token_ttl'),
            $app->make(CategoryImages::class), $app->make(ContentMedia::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('divan-token', function ($request) {
            try {
                $username = app(DivanApi::class)->principal($request->bearerToken(), time());

                return User::where('Username', $username)->first();
            } catch (ApiError) {
                return null;
            }
        });
    }
}
