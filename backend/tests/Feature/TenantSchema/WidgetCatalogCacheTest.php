<?php

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Caching a raw Eloquent Collection is fragile: under a serializing cache store
// (Redis/predis), the model graph survives the WRITE but the READ-BACK in a fresh
// request deserializes to __PHP_Incomplete_Class, so the endpoint emits
// {"__PHP_Incomplete_Class_Name":"...Collection"} instead of a JSON array — the
// widget then renders an empty "undefined" service. Cache plain arrays only.
it('caches the services catalog as a plain array, not an Eloquent object', function () {
    Service::factory()->create(['is_active' => true]);

    $this->getJson('/api/v1/widget/services')->assertOk(); // warms cache

    $cached = Cache::get(CatalogCache::servicesKey());

    expect($cached)->toBeArray()->toHaveCount(1);
    expect($cached[0])->toBeArray()->toHaveKeys(['id', 'name', 'duration_minutes']);
});

it('caches the practitioners catalog as a plain array, not an Eloquent object', function () {
    $service = Service::factory()->create(['is_active' => true]);
    $p = Practitioner::factory()->create(['is_active' => true]);
    $service->practitioners()->sync([$p->id]);

    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")->assertOk();

    $cached = Cache::get(CatalogCache::practitionersKey($service->id));

    expect($cached)->toBeArray()->toHaveCount(1);
    expect($cached[0])->toBeArray()->toHaveKeys(['id', 'first_name', 'last_name']);
});

// Locks the wire format regardless of cache store: the read-back must stay a JSON
// array of objects and never leak the __PHP_Incomplete_Class_Name shape.
it('keeps the services wire format an array of objects on the cached read-back', function () {
    Service::factory()->create(['is_active' => true, 'name' => 'Erstuntersuchung Kind']);

    $this->getJson('/api/v1/widget/services')->assertOk(); // warms cache

    $this->getJson('/api/v1/widget/services') // served from cache — the path that broke in prod
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonStructure([['id', 'name', 'duration_minutes', 'color', 'description']])
        ->assertJsonFragment(['name' => 'Erstuntersuchung Kind'])
        ->assertJsonMissingPath('__PHP_Incomplete_Class_Name');
});

it('serves the services list from cache on the second call', function () {
    Service::factory()->create(['is_active' => true]);

    $this->getJson('/api/v1/widget/services')->assertOk(); // warms cache

    $count = 0;
    DB::listen(function ($q) use (&$count) {
        if (str_contains($q->sql, 'from "services"')) {
            $count++;
        }
    });
    $this->getJson('/api/v1/widget/services')->assertOk();

    expect($count)->toBe(0); // served from cache
});

it('invalidates the services cache when a service is created via the staff side', function () {
    $this->getJson('/api/v1/widget/services')->assertOk()->assertJsonCount(0);

    Service::factory()->create(['is_active' => true, 'name' => 'Neue Leistung']);

    $this->getJson('/api/v1/widget/services')->assertOk()->assertJsonCount(1);
});

it('invalidates the services cache when a service is renamed', function () {
    $service = Service::factory()->create(['is_active' => true, 'name' => 'Alt']);
    $this->getJson('/api/v1/widget/services')->assertOk()->assertJsonFragment(['name' => 'Alt']);

    $service->update(['name' => 'Neu']);

    $this->getJson('/api/v1/widget/services')->assertOk()
        ->assertJsonFragment(['name' => 'Neu'])
        ->assertJsonMissing(['name' => 'Alt']);
});

it('invalidates the services cache when a service is deleted', function () {
    $service = Service::factory()->create(['is_active' => true]);
    $this->getJson('/api/v1/widget/services')->assertOk()->assertJsonCount(1);

    $service->delete();

    $this->getJson('/api/v1/widget/services')->assertOk()->assertJsonCount(0);
});

it('invalidates the practitioners cache when the pivot is synced', function () {
    $service = Service::factory()->create(['is_active' => true]);
    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()->assertJsonCount(0);

    $p = Practitioner::factory()->create(['is_active' => true]);
    $service->practitioners()->sync([$p->id]);
    CatalogCache::flush(); // mirrors what the staff ServiceController does after sync

    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()->assertJsonCount(1);
});

it('invalidates when a practitioner is toggled inactive', function () {
    $service = Service::factory()->create(['is_active' => true]);
    $p = Practitioner::factory()->create(['is_active' => true]);
    $service->practitioners()->sync([$p->id]);
    CatalogCache::flush();

    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()->assertJsonCount(1);

    $p->update(['is_active' => false]); // Practitioner saved → observer flushes

    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()->assertJsonCount(0);
});

it('advances the catalog version monotonically on each flush', function () {
    $v0 = CatalogCache::version();
    CatalogCache::flush();
    $v1 = CatalogCache::version();
    CatalogCache::flush();
    $v2 = CatalogCache::version();

    expect($v1)->toBeGreaterThan($v0)->and($v2)->toBeGreaterThan($v1);
});
