<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CatalogCache
{
    private const VERSION_KEY = 'widget:catalog:version';

    public static function version(): int
    {
        return (int) Cache::rememberForever(self::VERSION_KEY, fn () => 1);
    }

    public static function flush(): void
    {
        $next = self::version() + 1;
        Cache::forever(self::VERSION_KEY, $next);
    }

    public static function servicesKey(): string
    {
        return 'widget:services:v'.self::version();
    }

    public static function practitionersKey(int $serviceId): string
    {
        return "widget:practitioners:{$serviceId}:v".self::version();
    }
}
