<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AppointmentScheduler;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

function baseData(Practitioner $p, Service $s, CarbonImmutable $start): array
{
    return [
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller', 'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller', 'parent_phone' => '+49 170 0',
    ];
}

it('creates a manual appointment outside opening hours (cabinet override)', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    // 20:00 — outside any normal opening hours; override must allow it.
    $start = CarbonImmutable::parse('2026-06-01 20:00', 'Europe/Berlin');

    $a = app(AppointmentScheduler::class)->create(baseData($p, $s, $start));

    expect($a->status)->toBe('confirmed')
        ->and($a->cancellation_token)->not->toBeNull();
});

it('rejects an overlapping appointment for the same practitioner (409)', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    app(AppointmentScheduler::class)->create(baseData($p, $s, $start->addMinutes(15)));
})->throws(HttpException::class);

it('reschedule moves the slot and excludes the appointment itself from the overlap check', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    // Move to the same slot (self-overlap must be allowed) then to a new slot.
    // Compare on the wall clock: stored timestamps hold Berlin wall-clock time
    // (re-read as UTC), so equalTo() across timezones would be off by the offset.
    $wall = fn ($dt) => $dt->format('Y-m-d H:i');
    $same = app(AppointmentScheduler::class)->reschedule($a, ['starts_at' => $start, 'ends_at' => $start->addMinutes(30)]);
    expect($wall($same->starts_at))->toBe($wall($start));

    $moved = app(AppointmentScheduler::class)->reschedule($a, ['starts_at' => $start->addHour(), 'ends_at' => $start->addHour()->addMinutes(30)]);
    expect($wall($moved->starts_at))->toBe($wall($start->addHour()));
});
