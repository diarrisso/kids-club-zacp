<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the default when the key is absent', function () {
    expect(Setting::get('booking_url', 'fallback'))->toBe('fallback');
});

it('persists and reads a value', function () {
    Setting::put('booking_url', 'https://cabinet.de/rendez-vous');

    expect(Setting::get('booking_url'))->toBe('https://cabinet.de/rendez-vous');
});

it('overwrites an existing value and invalidates the cache', function () {
    Setting::put('booking_url', 'https://old.de');
    Setting::put('booking_url', 'https://new.de');

    expect(Setting::get('booking_url'))->toBe('https://new.de');
});
