<?php

/**
 * The waitlist dedup has a fast-path exists-check in the controller AND an atomic
 * DB backstop (partial unique index) for the concurrent race the check can't cover.
 * These tests assert the index itself, bypassing the controller, incl. the NULL
 * service_id branch (COALESCE(service_id, 0) in the index).
 */

use App\Models\Tenant\Service;
use App\Models\WaitlistEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pendingEntry(array $override = []): WaitlistEntry
{
    return WaitlistEntry::create(array_merge([
        'patient_first_name' => 'Emma', 'patient_last_name' => 'Müller',
        'parent_first_name' => 'Katrin', 'parent_last_name' => 'Müller',
        'parent_phone' => '+49 160 1234567',
    ], $override));
}

it('rejects a second pending row with the same phone and service at the DB level', function () {
    $service = Service::factory()->create();
    pendingEntry(['service_id' => $service->id]);

    pendingEntry(['service_id' => $service->id]);
})->throws(UniqueConstraintViolationException::class);

it('rejects a second pending row with the same phone and NO service (NULL branch)', function () {
    pendingEntry(['service_id' => null]);

    pendingEntry(['service_id' => null]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same phone for a different service', function () {
    $a = Service::factory()->create();
    $b = Service::factory()->create();

    pendingEntry(['service_id' => $a->id]);
    pendingEntry(['service_id' => $b->id]);

    expect(WaitlistEntry::count())->toBe(2);
});

it('allows re-joining once the earlier entry is no longer pending', function () {
    $service = Service::factory()->create();
    $first = pendingEntry(['service_id' => $service->id]);
    $first->forceFill(['status' => 'contacted'])->save(); // status not fillable

    pendingEntry(['service_id' => $service->id]);

    expect(WaitlistEntry::where('status', 'pending')->count())->toBe(1);
});
