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
        // Seed the key if absent, then bump ATOMICALLY. A non-atomic read-then-
        // write (version()+1) could lose an increment under concurrent staff
        // writes, leaving a stale versioned cache live until the next flush.
        // Cache::increment is atomic on the database/redis/array stores.
        self::version();                     // rememberForever seeds VERSION_KEY=1 if missing
        Cache::increment(self::VERSION_KEY);
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
