<?php

/**
 * Regression guards for the 2026-07-11 security hardening pass (branch
 * fix/securite-durcissement). One test per finding — if a guard fails, a
 * previously-closed hole has reopened.
 */

use App\Mail\WaitlistEntryMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Availability;
use App\Models\Tenant\AvailabilityException;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

// Uniquely-named helpers (Pest includes every test file; global function names
// must not collide with WidgetBookingTest's bookingSetup/bookingPayload).
function secBookableMonday(): CarbonImmutable
{
    return CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
}

function secBookingSetup(int $duration = 30): array
{
    $p = Practitioner::factory()->create(['is_active' => true]);
    $s = Service::factory()->create(['duration_minutes' => $duration, 'is_active' => true]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    $startsAt = CarbonImmutable::parse(secBookableMonday()->toDateString().' 09:00', 'Europe/Berlin');

    return [$p, $s, $startsAt];
}

function secBookingPayload(array $override = []): array
{
    return array_merge([
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
        'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000000',
        'consent' => true, 'website' => '',
    ], $override);
}

// ── Finding auth-M1: TOTP secret + recovery codes never serialized ──────────
it('hides two_factor_secret and recovery codes from model serialization', function () {
    $array = User::factory()->create()->toArray();

    expect($array)->not->toHaveKey('two_factor_secret')
        ->and($array)->not->toHaveKey('two_factor_recovery_codes')
        ->and($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('remember_token');
});

// ── Finding auth-M2: role / practitioner_id not mass-assignable ─────────────
it('never mass-assigns role or practitioner_id via fill()', function () {
    $p = Practitioner::factory()->create();

    $user = (new User)->fill([
        'name' => 'Mallory', 'email' => 'mallory@example.com', 'password' => 'a-strong-passphrase',
        'role' => 'medecin', 'practitioner_id' => $p->id,
    ]);

    expect($user->role)->toBeNull()
        ->and($user->practitioner_id)->toBeNull();
});

// ── Finding storno-LOW: markdown metacharacters rejected in names ───────────
it('rejects a booking whose name smuggles a markdown phishing link', function () {
    [$p, $s, $startsAt] = secBookingSetup();

    $this->postJson('/api/v1/widget/appointments', secBookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'parent_first_name' => '[Jetzt anmelden](https://evil.tld)',
    ]))->assertStatus(422)->assertJsonValidationErrors('parent_first_name');
});

it('still accepts legitimate accented, hyphenated and apostrophe names', function () {
    [$p, $s, $startsAt] = secBookingSetup();

    $this->postJson('/api/v1/widget/appointments', secBookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'patient_first_name' => 'José', 'patient_last_name' => 'Müller',
        'parent_first_name' => 'Jean-Pierre', 'parent_last_name' => "O'Brien",
    ]))->assertCreated();
});

// ── Finding widget-L2: identical pending waitlist entry deduplicated ────────
it('deduplicates an identical pending waitlist entry — no second row, no second email', function () {
    Mail::fake();
    config(['mail.practice_notification_address' => 'cabinet@example.com']);
    $service = Service::factory()->create();

    $payload = [
        'patient_first_name' => 'Emma', 'patient_last_name' => 'Müller',
        'parent_first_name' => 'Katrin', 'parent_last_name' => 'Müller',
        'parent_phone' => '+49 160 1234567', 'service_id' => $service->id, 'consent' => true,
    ];

    $this->postJson('/api/v1/widget/warteliste', $payload)->assertStatus(201);
    $this->postJson('/api/v1/widget/warteliste', $payload)->assertStatus(201);

    expect(WaitlistEntry::count())->toBe(1);
    Mail::assertQueued(WaitlistEntryMail::class, 1);
});

it('deduplicates identical waitlist entries even with no service (service_id IS NULL branch)', function () {
    Mail::fake();
    config(['mail.practice_notification_address' => 'cabinet@example.com']);

    // No service_id: exercises the ->where('service_id', null) path, which Eloquent
    // compiles to "service_id IS NULL" (not "= NULL"). Two identical submissions
    // must still collapse to one row + one cabinet email.
    $payload = [
        'patient_first_name' => 'Emma', 'patient_last_name' => 'Müller',
        'parent_first_name' => 'Katrin', 'parent_last_name' => 'Müller',
        'parent_phone' => '+49 160 1234567', 'consent' => true,
    ];

    $this->postJson('/api/v1/widget/warteliste', $payload)->assertStatus(201);
    $this->postJson('/api/v1/widget/warteliste', $payload)->assertStatus(201);

    expect(WaitlistEntry::count())->toBe(1);
    Mail::assertQueued(WaitlistEntryMail::class, 1);
});

// ── Finding widget-L1: a slot covered by an absence is never bookable ───────
// The absence overlap is now re-checked UNDER the pessimistic lock, inside the
// transaction; this guards the user-facing invariant either path enforces.
it('refuses to book a slot that overlaps a practitioner absence', function () {
    [$p, $s, $startsAt] = secBookingSetup();
    AvailabilityException::factory()->create([
        'practitioner_id' => $p->id,
        'starts_at' => $startsAt->copy()->subHour(),
        'ends_at' => $startsAt->copy()->addHours(2),
        'type' => 'vacation',
    ]);

    $this->postJson('/api/v1/widget/appointments', secBookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertStatus(422);

    expect(Appointment::count())->toBe(0);
});

// ── Finding deps: security-patched versions pinned in composer.lock ─────────
it('keeps security-patched dependency versions in composer.lock', function () {
    $lock = json_decode(file_get_contents(base_path('composer.lock')), true);
    $versions = collect($lock['packages'])->pluck('version', 'name');

    $atLeast = fn (string $pkg, string $min) => version_compare(ltrim($versions[$pkg], 'v'), $min, '>=');

    expect($atLeast('guzzlehttp/guzzle', '7.12.1'))->toBeTrue()
        ->and($atLeast('guzzlehttp/psr7', '2.12.1'))->toBeTrue()
        ->and($atLeast('web-auth/webauthn-lib', '5.3.5'))->toBeTrue();
});
