<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    private static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }

    /**
     * Reads through a forever-cache. The cache is only invalidated by put(), so any
     * write that bypasses put() (e.g. a raw seeder INSERT) must Cache::forget() the
     * key — otherwise an absent key stays cached as null. Always write via put().
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Cache::rememberForever(
            self::cacheKey($key),
            fn () => static::query()->where('key', $key)->value('value')
        );

        return $value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }
}
