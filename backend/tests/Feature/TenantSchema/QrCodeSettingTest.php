<?php

use App\Models\Setting;
use App\Models\User;

it('redirects guests away from the QR settings page', function () {
    $this->get('/termin-qr-code')->assertRedirect(route('login'));
});

it('shows the QR settings page to an authenticated staff member', function () {
    $this->actingAs(User::factory()->create())
        ->get('/termin-qr-code')
        ->assertOk();
});

it('persists a valid booking url', function () {
    $this->actingAs(User::factory()->create())
        ->from('/termin-qr-code')
        ->post('/termin-qr-code', ['booking_url' => 'https://cabinet.de/rendez-vous'])
        ->assertRedirect('/termin-qr-code')
        ->assertSessionHas('success');

    expect(Setting::get('booking_url'))->toBe('https://cabinet.de/rendez-vous');
});

it('rejects a non-http url', function () {
    $this->actingAs(User::factory()->create())
        ->post('/termin-qr-code', ['booking_url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors('booking_url');

    expect(Setting::get('booking_url'))->toBeNull();
});

it('rejects a whitespace-only url', function () {
    $this->actingAs(User::factory()->create())
        ->from('/termin-qr-code')
        ->post('/termin-qr-code', ['booking_url' => '   '])
        ->assertSessionHasErrors('booking_url');

    expect(Setting::get('booking_url'))->toBeNull();
});
