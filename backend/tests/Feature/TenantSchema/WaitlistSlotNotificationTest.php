<?php

use App\Mail\WaitlistSlotAvailableMail;
use App\Models\Tenant\Appointment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Attendance;
use App\Support\WaitlistNotifier;
use App\Support\WaitlistStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

function cancellingStaff(): User
{
    return User::factory()->create(['two_factor_confirmed_at' => now()]);
}

// ─── WaitlistNotifier unit-level tests ────────────────────────────────────────

it('promotes the oldest pending entry to contacted when a slot becomes available', function () {
    $older = WaitlistEntry::factory()->create(['created_at' => now()->subHour()]);
    $newer = WaitlistEntry::factory()->create(['created_at' => now()]);

    WaitlistNotifier::notifySlotAvailable();

    expect($older->fresh()->status)->toBe(WaitlistStatus::Contacted);
    expect($newer->fresh()->status)->toBe(WaitlistStatus::Pending);
});

it('sends the slot-available email when the entry has an email address', function () {
    WaitlistEntry::factory()->create(['parent_email' => 'mutter@example.de']);

    WaitlistNotifier::notifySlotAvailable();

    Mail::assertQueued(WaitlistSlotAvailableMail::class, fn ($mail) => $mail->hasTo('mutter@example.de')
    );
});

it('promotes entry without email to contacted but sends no email', function () {
    $entry = WaitlistEntry::factory()->create(['parent_email' => null]);

    WaitlistNotifier::notifySlotAvailable();

    expect($entry->fresh()->status)->toBe(WaitlistStatus::Contacted);
    Mail::assertNothingQueued();
});

it('does nothing when the waitlist has no pending entries', function () {
    $entry = WaitlistEntry::factory()->create();
    $entry->status = WaitlistStatus::Booked;
    $entry->save();

    WaitlistNotifier::notifySlotAvailable();

    expect($entry->fresh()->status)->toBe(WaitlistStatus::Booked);
    Mail::assertNothingQueued();
});

// ─── Controller integration tests ─────────────────────────────────────────────

it('notifies waitlist when staff cancels appointment from calendar', function () {
    $appointment = Appointment::factory()->create();
    $entry = WaitlistEntry::factory()->create(['parent_email' => 'warte@example.de']);

    $this->actingAs(cancellingStaff())
        ->deleteJson("/termine/{$appointment->id}")
        ->assertOk();

    expect($entry->fresh()->status)->toBe(WaitlistStatus::Contacted);
    Mail::assertQueued(WaitlistSlotAvailableMail::class);
});

it('notifies waitlist when parent cancels via storno page', function () {
    $appointment = Appointment::factory()->create();
    $entry = WaitlistEntry::factory()->create(['parent_email' => 'warte@example.de']);

    $this->post("/storno/{$appointment->cancellation_token}")
        ->assertOk();

    expect($entry->fresh()->status)->toBe(WaitlistStatus::Contacted);
    Mail::assertQueued(WaitlistSlotAvailableMail::class);
});

it('notifies waitlist when staff marks attendance as no_show', function () {
    $appointment = Appointment::factory()->create();
    $entry = WaitlistEntry::factory()->create(['parent_email' => 'warte@example.de']);

    $this->actingAs(cancellingStaff())
        ->patchJson("/termine/{$appointment->id}", ['attendance' => 'no_show'])
        ->assertOk();

    expect($entry->fresh()->status)->toBe(WaitlistStatus::Contacted);
    Mail::assertQueued(WaitlistSlotAvailableMail::class);
});

it('does not double-notify when no_show is already set', function () {
    $appointment = Appointment::factory()->create();
    $appointment->attendance = Attendance::NoShow;
    $appointment->save();

    WaitlistEntry::factory()->create(['parent_email' => 'warte@example.de']);

    $this->actingAs(cancellingStaff())
        ->patchJson("/termine/{$appointment->id}", ['attendance' => 'no_show'])
        ->assertOk();

    Mail::assertNothingQueued();
});

it('does not notify when arrived attendance is set (no slot freed)', function () {
    $appointment = Appointment::factory()->create();
    WaitlistEntry::factory()->create(['parent_email' => 'warte@example.de']);

    $this->actingAs(cancellingStaff())
        ->patchJson("/termine/{$appointment->id}", ['attendance' => 'arrived'])
        ->assertOk();

    Mail::assertNothingQueued();
});
