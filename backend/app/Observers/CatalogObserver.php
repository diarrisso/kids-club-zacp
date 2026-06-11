<?php

namespace App\Observers;

use App\Support\CatalogCache;

class CatalogObserver
{
    public function saved(mixed $model): void
    {
        CatalogCache::flush();
    }

    public function deleted(mixed $model): void
    {
        CatalogCache::flush();
    }
}
