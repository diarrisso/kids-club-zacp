<?php

use App\Mail\WaitlistEntryMail;
use App\Models\Tenant\Service;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

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
        // parent_phone missing
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_phone']);
});

it('rejects a non-existent service_id', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Max',
        'patient_last_name' => 'Becker',
        'parent_first_name' => 'Tom',
        'parent_last_name' => 'Becker',
        'parent_phone' => '+49 170 000',
        'service_id' => 9999,
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
    ])->assertStatus(201);

    Mail::assertNothingQueued();
});
