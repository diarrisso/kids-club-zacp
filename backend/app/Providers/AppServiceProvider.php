<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('widget-read', fn (Request $r) => Limit::perMinute(20)->by((tenant()?->getTenantKey() ?? 'central').'|'.$r->ip()));
        RateLimiter::for('widget-book', fn (Request $r) => Limit::perMinute(5)->by((tenant()?->getTenantKey() ?? 'central').'|'.$r->ip()));
    }
}
