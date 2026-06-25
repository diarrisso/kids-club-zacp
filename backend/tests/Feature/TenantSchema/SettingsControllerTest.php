<?php

use App\Models\PracticeSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->user = User::factory()->create());

function validSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'reminder_enabled' => true,
        'reminder_channel' => 'email',
        'reminder_lead_hours' => 24,
        'reminder_message' => 'Hallo {Datum} {Uhrzeit}',
        'booking_confirmation_enabled' => true,
        'notify_on_booking' => true,
        'notify_on_cancellation' => false,
    ], $overrides);
}

it('persists validated settings', function () {
    actingAs($this->user)
        ->patch(route('tenant.settings.update'), validSettingsPayload(['reminder_lead_hours' => 48]))
        ->assertSessionHasNoErrors();

    expect(PracticeSettings::current()->reminder_lead_hours)->toBe(48);
});

it('rejects a lead time outside the allowed set', function () {
    actingAs($this->user)
        ->patch(route('tenant.settings.update'), validSettingsPayload(['reminder_lead_hours' => 12]))
        ->assertSessionHasErrors('reminder_lead_hours');
});

it('rejects a non-email reminder channel', function () {
    actingAs($this->user)
        ->patch(route('tenant.settings.update'), validSettingsPayload(['reminder_channel' => 'sms']))
        ->assertSessionHasErrors('reminder_channel');
});
