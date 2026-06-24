<?php

use App\Mail\WaitlistEntryMail;
use App\Models\Tenant\Service;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

it('stores a waitlist entry with all fields and returns 201', function () {
    $service = Service::factory()->create();

    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Emma',
        'patient_last_name' => 'Müller',
        'parent_first_name' => 'Katrin',
        'parent_last_name' => 'Müller',
        'parent_phone' => '+49 160 1234567',
        'parent_email' => 'katrin@example.com',
        'service_id' => $service->id,
        'notes' => 'So früh wie möglich',
        'consent' => true,
    ])
        ->assertStatus(201)
        ->assertJson(['message' => 'Auf der Warteliste eingetragen.']);

    expect(WaitlistEntry::count())->toBe(1);
    $e = WaitlistEntry::first();
    expect($e->patient_first_name)->toBe('Emma');
    expect($e->parent_phone)->toBe('+49 160 1234567');
    expect($e->status->value)->toBe('pending');
});

it('stores a waitlist entry without optional fields (email, service, notes)', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Lina',
        'patient_last_name' => 'Schmidt',
        'parent_first_name' => 'Anna',
        'parent_last_name' => 'Schmidt',
        'parent_phone' => '+49 170 9876543',
        'consent' => true,
    ])
        ->assertStatus(201);

    $e = WaitlistEntry::first();
    expect($e->parent_email)->toBeNull();
    expect($e->service_id)->toBeNull();
});

it('rejects a request without parent_phone (required field)', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Max',
        'patient_last_name' => 'Becker',
        'parent_first_name' => 'Tom',
        'parent_last_name' => 'Becker',
        'consent' => true,
        // parent_phone missing
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_phone']);
});

it('rejects a request without consent', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Max',
        'patient_last_name' => 'Becker',
        'parent_first_name' => 'Tom',
        'parent_last_name' => 'Becker',
        'parent_phone' => '+49 170 000',
        // consent missing
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['consent']);
});

it('rejects a non-existent service_id', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Max',
        'patient_last_name' => 'Becker',
        'parent_first_name' => 'Tom',
        'parent_last_name' => 'Becker',
        'parent_phone' => '+49 170 000',
        'service_id' => 9999,
        'consent' => true,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['service_id']);
});

it('queues a cabinet notification email on successful registration', function () {
    $service = Service::factory()->create();

    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Emma',
        'patient_last_name' => 'Müller',
        'parent_first_name' => 'Katrin',
        'parent_last_name' => 'Müller',
        'parent_phone' => '+49 160 1234567',
        'service_id' => $service->id,
        'consent' => true,
    ])->assertStatus(201);

    Mail::assertQueued(WaitlistEntryMail::class);
});

it('sends no notification email if PRACTICE_NOTIFICATION_EMAIL is not configured', function () {
    config(['mail.practice_notification_address' => null]);

    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Emma',
        'patient_last_name' => 'Müller',
        'parent_first_name' => 'Katrin',
        'parent_last_name' => 'Müller',
        'parent_phone' => '+49 160 1234567',
        'consent' => true,
    ])->assertStatus(201);

    Mail::assertNothingQueued();
});

it('silently accepts a honeypot-filled request without creating a DB entry', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Bot',
        'patient_last_name' => 'Bot',
        'parent_first_name' => 'Bot',
        'parent_last_name' => 'Bot',
        'parent_phone' => '+49 000 0000000',
        'consent' => true,
        'website' => 'http://spam.example.com',
    ])->assertStatus(201);

    expect(WaitlistEntry::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('is rate-limited by the widget-book throttle', function () {
    // ThrottleRequests hashes named-limiter keys as md5($limiterName.$limit->key).
    // Clear the global circuit-breaker bucket so prior tests don't bleed into this one.
    RateLimiter::clear(md5('widget-book'.'widget-book-global'));

    // The throttle middleware runs before FormRequest validation, so even a valid
    // minimal payload consumes a token. 5/min per IP — the 6th must be 429.
    $payload = [
        'patient_first_name' => 'Max',
        'patient_last_name'  => 'Test',
        'parent_first_name'  => 'Tom',
        'parent_last_name'   => 'Test',
        'parent_phone'       => '+49 170 0000000',
        'consent'            => true,
    ];

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/widget/warteliste', $payload)->assertStatus(201);
    }

    $this->postJson('/api/v1/widget/warteliste', $payload)->assertStatus(429);
});
