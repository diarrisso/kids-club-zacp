<?php

namespace App\Providers;

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Observers\CatalogObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        if ($this->app->environment('production')) {
            // Belt-and-braces with TrustProxies' X-Forwarded-Proto: emails and
            // storno/reset links must never go out as http://.
            URL::forceScheme('https');
        }

        Service::observe(CatalogObserver::class);
        Practitioner::observe(CatalogObserver::class);

        RateLimiter::for('widget-read', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('widget-book', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        // Fonts get their own bucket: on NAT'd shared IPs (practice waiting-room
        // WiFi) cold-cache font traffic must never 429 the actual booking calls.
        RateLimiter::for('widget-font', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('storno', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('qr', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
    }
}
