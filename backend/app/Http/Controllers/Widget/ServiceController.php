<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Service;
use App\Support\CatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Cache::rememberForever(CatalogCache::servicesKey(), fn () => Service::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'duration_minutes', 'color', 'description'])
                ->toArray()) // cache plain primitives: a raw Eloquent Collection deserializes to __PHP_Incomplete_Class on read-back under a serializing store (Redis)
        );
    }

    public function practitioners(Service $service): JsonResponse
    {
        return response()->json(
            Cache::rememberForever(CatalogCache::practitionersKey($service->id), fn () => $service->practitioners()
                ->where('is_active', true)
                ->orderBy('last_name')
                ->get(['practitioners.id', 'first_name', 'last_name', 'title', 'color'])
                ->toArray()) // cache plain primitives (see index(): avoids __PHP_Incomplete_Class on Redis read-back)
        );
    }
}
