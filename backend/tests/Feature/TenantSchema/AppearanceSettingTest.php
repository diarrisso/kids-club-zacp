<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('requires authentication', function () {
    $this->get(route('tenant.appearance.index'))->assertRedirect();
});

it('renders the appearance page with the current settings', function () {
    Setting::put('widget_theme', json_encode(['colorPrimary' => '#123456']));
    Setting::put('datenschutz_url', 'https://praxis.test/ds');

    $this->actingAs(User::factory()->create())
        ->get(route('tenant.appearance.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Appearance')
            ->where('theme.colorPrimary', '#123456')
            ->where('theme.colorAccent', '#EC0A8C')
            ->where('datenschutzUrl', 'https://praxis.test/ds')
            ->where('logoUrl', null)
            ->has('fontOptions'));
});

it('persists a valid theme payload', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('tenant.appearance.update'), [
            'colorPrimary' => '#0E7C3A', 'colorPrimaryTo' => '#222222',
            'colorAccent' => '#AA00BB', 'colorBackground' => '#FAFAFA', 'colorText' => '#111111',
            'fontHeading' => 'Poppins', 'fontBody' => 'Inter', 'radius' => 12,
            'datenschutz_url' => 'https://praxis.test/datenschutz',
            'impressum_url' => null,
        ])->assertRedirect();

    $theme = json_decode(Setting::get('widget_theme'), true);
    expect($theme['colorPrimary'])->toBe('#0E7C3A')
        ->and($theme['radius'])->toBe('12px')
        ->and(Setting::get('datenschutz_url'))->toBe('https://praxis.test/datenschutz');
});

it('rejects malformed colors, unknown fonts, out-of-range radius and bad urls', function () {
    $valid = [
        'colorPrimary' => '#0E7C3A', 'colorPrimaryTo' => '#222222', 'colorAccent' => '#AA00BB',
        'colorBackground' => '#FAFAFA', 'colorText' => '#111111',
        'fontHeading' => 'Fredoka', 'fontBody' => 'Nunito', 'radius' => 26,
    ];
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('tenant.appearance.update'), ['colorPrimary' => 'red'] + $valid)
        ->assertSessionHasErrors('colorPrimary');
    $this->actingAs($user)->post(route('tenant.appearance.update'), ['fontHeading' => 'Comic Sans'] + $valid)
        ->assertSessionHasErrors('fontHeading');
    $this->actingAs($user)->post(route('tenant.appearance.update'), ['radius' => 99] + $valid)
        ->assertSessionHasErrors('radius');
    $this->actingAs($user)->post(route('tenant.appearance.update'), ['datenschutz_url' => 'not-a-url'] + $valid)
        ->assertSessionHasErrors('datenschutz_url');
    $this->actingAs($user)->post(route('tenant.appearance.update'), ['datenschutz_url' => 'javascript://comment%0aalert(1)'] + $valid)
        ->assertSessionHasErrors('datenschutz_url');
    $this->actingAs($user)->post(route('tenant.appearance.update'), ['datenschutz_url' => 'data://text/html,x'] + $valid)
        ->assertSessionHasErrors('datenschutz_url');
});

it('stores an uploaded logo on the public disk and replaces the old one', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $payload = [
        'colorPrimary' => '#6B8FA3', 'colorPrimaryTo' => '#C40C78', 'colorAccent' => '#EC0A8C',
        'colorBackground' => '#FFFFFF', 'colorText' => '#26257F',
        'fontHeading' => 'Fredoka', 'fontBody' => 'Nunito', 'radius' => 26,
    ];

    $this->actingAs($user)->post(route('tenant.appearance.update'),
        $payload + ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)])->assertRedirect();
    $first = Setting::get('widget_logo_path');
    Storage::disk('public')->assertExists($first);

    $this->actingAs($user)->post(route('tenant.appearance.update'),
        $payload + ['logo' => UploadedFile::fake()->image('logo2.png', 200, 80)])->assertRedirect();
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists(Setting::get('widget_logo_path'));
});

it('removes the logo when remove_logo is set', function () {
    Storage::fake('public');
    Storage::disk('public')->put('widget/old.png', 'x');
    Setting::put('widget_logo_path', 'widget/old.png');

    $this->actingAs(User::factory()->create())->post(route('tenant.appearance.update'), [
        'colorPrimary' => '#6B8FA3', 'colorPrimaryTo' => '#C40C78', 'colorAccent' => '#EC0A8C',
        'colorBackground' => '#FFFFFF', 'colorText' => '#26257F',
        'fontHeading' => 'Fredoka', 'fontBody' => 'Nunito', 'radius' => 26,
        'remove_logo' => true,
    ])->assertRedirect();

    expect(Setting::get('widget_logo_path'))->toBeNull();
    Storage::disk('public')->assertMissing('widget/old.png');
});
