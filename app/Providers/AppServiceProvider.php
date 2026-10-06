<?php

namespace App\Providers;

use App\Exceptions\ApiError;
use App\Models\User;
use App\Services\CategoryImages;
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
        $this->app->bind(DivanApi::class, fn ($app) => new DivanApi(
            $app->make(DivanRepository::class), config('divan.token_ttl'),
            $app->make(CategoryImages::class),
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
