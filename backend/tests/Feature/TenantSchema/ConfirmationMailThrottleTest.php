<?php

use App\Mail\AppointmentConfirmationMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    // The per-recipient throttle uses the RateLimiter FACADE directly (no
    // middleware), so its key is the literal 'confirm-mail:'.sha1(...) with NO
    // namespace prefix — clear the exact buckets this test touches.
    RateLimiter::clear('confirm-mail:'.sha1('parent@example.de'));
    RateLimiter::clear('confirm-mail:'.sha1('other@example.de'));

    // The widget-book GLOBAL circuit-breaker (30/min) is enforced by the
    // ThrottleRequests middleware, which HASHES named-limiter keys:
    // md5($limiterName.$limit->key) = md5('widget-book'.'widget-book-global').
    // The array cache store persists across tests in one process, so clear the
    // real hashed key to stop earlier booking tests from tripping the breaker.
    RateLimiter::clear(md5('widget-book'.'widget-book-global'));
});

// practitioner + linked 30-min service + Monday 09:00-17:00 availability.
function throttleBookingSetup(): array
{
    $p = Practitioner::factory()->create(['is_active' => true]);
    $s = Service::factory()->create(['duration_minutes' => 30, 'is_active' => true]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '17:00',
    ]);

    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    return [$p, $s, $monday];
}

// Four distinct bookable starts on the same future Monday (09:00..10:30).
function throttleSlot(CarbonImmutable $monday, int $index): CarbonImmutable
{
    return CarbonImmutable::parse(
        $monday->toDateString().' 09:00', 'Europe/Berlin'
    )->addMinutes(30 * $index);
}

// POST a successful booking from a distinct IP (keeps per-IP < 5/min) and
// return the response.
function bookConfirmedSlot(Practitioner $p, Service $s, CarbonImmutable $startsAt, string $email, int $ipOctet): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$ipOctet])->postJson(
        '/api/v1/widget/appointments',
        [
            'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
            'patient_birthdate' => '2019-04-12',
            'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
            'parent_email' => $email, 'parent_phone' => '+49 170 0000000',
            'consent' => true, 'website' => '',
            'practitioner_id' => $p->id, 'service_id' => $s->id,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        ]
    );
}

it('queues a confirmation mail for the first three bookings to one email', function () {
    [$p, $s, $monday] = throttleBookingSetup();

    foreach (range(0, 2) as $i) {
        bookConfirmedSlot($p, $s, throttleSlot($monday, $i), 'parent@example.de', $i + 1)
            ->assertCreated();
    }

    Mail::assertQueued(AppointmentConfirmationMail::class, 3);
});

it('stops queueing mail after 3 to the same email but still books (201)', function () {
    [$p, $s, $monday] = throttleBookingSetup();

    foreach (range(0, 3) as $i) {
        bookConfirmedSlot($p, $s, throttleSlot($monday, $i), 'parent@example.de', $i + 1)
            ->assertCreated();
    }

    Mail::assertQueued(AppointmentConfirmationMail::class, 3);
    expect(Appointment::where('parent_email', 'parent@example.de')->count())->toBe(4);
});

it('treats email case and whitespace as the same recipient', function () {
    [$p, $s, $monday] = throttleBookingSetup();

    foreach (range(0, 2) as $i) {
        bookConfirmedSlot($p, $s, throttleSlot($monday, $i), 'parent@example.de', $i + 1)
            ->assertCreated();
    }

    // 4th booking: mixed-case + trailing space → normalizes to the same bucket.
    bookConfirmedSlot($p, $s, throttleSlot($monday, 3), 'Parent@Example.de ', 4)
        ->assertCreated();

    Mail::assertQueued(AppointmentConfirmationMail::class, 3);
});

it('does not throttle a different recipient', function () {
    [$p, $s, $monday] = throttleBookingSetup();

    foreach (range(0, 2) as $i) {
        bookConfirmedSlot($p, $s, throttleSlot($monday, $i), 'parent@example.de', $i + 1)
            ->assertCreated();
    }

    // 4th booking to a fresh address → its own bucket → still mailed.
    bookConfirmedSlot($p, $s, throttleSlot($monday, 3), 'other@example.de', 4)
        ->assertCreated();

    Mail::assertQueued(AppointmentConfirmationMail::class, 4);
});
