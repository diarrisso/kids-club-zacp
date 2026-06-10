<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('returns the documented defaults when nothing is configured', function () {
    $this->getJson('/api/v1/widget/config')
        ->assertOk()
        ->assertJson([
            'theme' => [
                'colorPrimary' => '#6B8FA3',
                'colorPrimaryTo' => '#C40C78',
                'colorAccent' => '#EC0A8C',
                'colorBackground' => '#FFFFFF',
                'colorText' => '#26257F',
                'fontHeading' => 'Fredoka',
                'fontBody' => 'Nunito',
                'radius' => '26px',
            ],
            'logoUrl' => null,
            'datenschutzUrl' => null,
            'impressumUrl' => null,
        ]);
});

it('returns configured values merged over the defaults', function () {
    Setting::put('widget_theme', json_encode(['colorPrimary' => '#123456', 'radius' => '8px']));
    Setting::put('datenschutz_url', 'https://praxis.example/datenschutz');

    $this->getJson('/api/v1/widget/config')
        ->assertOk()
        ->assertJsonPath('theme.colorPrimary', '#123456')
        ->assertJsonPath('theme.radius', '8px')
        ->assertJsonPath('theme.colorAccent', '#EC0A8C') // default survives partial config
        ->assertJsonPath('datenschutzUrl', 'https://praxis.example/datenschutz');
});

it('reflects an update immediately (Setting::put invalidates its cache)', function () {
    Setting::put('widget_theme', json_encode(['colorPrimary' => '#111111']));
    $this->getJson('/api/v1/widget/config')->assertJsonPath('theme.colorPrimary', '#111111');

    Setting::put('widget_theme', json_encode(['colorPrimary' => '#222222']));
    $this->getJson('/api/v1/widget/config')->assertJsonPath('theme.colorPrimary', '#222222');
});

it('drops unknown keys stored in widget_theme', function () {
    Setting::put('widget_theme', json_encode(['colorPrimary' => '#123456', 'evil' => '<script>']));

    $this->getJson('/api/v1/widget/config')
        ->assertJsonPath('theme.colorPrimary', '#123456')
        ->assertJsonMissingPath('theme.evil');
});

it('falls back to full defaults on corrupt json', function () {
    Setting::put('widget_theme', 'not-json{{{');

    $this->getJson('/api/v1/widget/config')
        ->assertOk()
        ->assertJsonPath('theme.colorPrimary', '#6B8FA3');
});

it('builds the public logo url from widget_logo_path', function () {
    Setting::put('widget_logo_path', 'widget/logo.png');

    $this->getJson('/api/v1/widget/config')
        ->assertJsonPath('logoUrl', Storage::disk('public')->url('widget/logo.png'));
});
