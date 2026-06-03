<?php

use App\Models\Setting;

it('renders a PNG QR when booking_url is configured (no auth needed)', function () {
    Setting::put('booking_url', 'https://cabinet.de/rendez-vous');

    $res = $this->get('/termin-qrcode.png');

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('image/png');
    expect($res->headers->get('cache-control'))->toContain('max-age=86400');
});

it('renders an SVG QR when booking_url is configured', function () {
    Setting::put('booking_url', 'https://cabinet.de/rendez-vous');

    $res = $this->get('/termin-qrcode.svg');

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('image/svg+xml');
});

it('returns 404 when booking_url is not configured', function () {
    $this->get('/termin-qrcode.png')->assertNotFound();
});

it('returns 404 for an unsupported format', function () {
    Setting::put('booking_url', 'https://cabinet.de/rendez-vous');

    $this->get('/termin-qrcode.gif')->assertNotFound();
});
