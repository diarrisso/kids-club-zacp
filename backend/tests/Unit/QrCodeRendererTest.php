<?php

use App\Support\QrCodeRenderer;

it('renders a PNG with the image/png mime type', function () {
    $result = (new QrCodeRenderer)->render('https://cabinet.de/rendez-vous', 'png');

    expect($result['mime'])->toBe('image/png')
        ->and($result['body'])->toStartWith("\x89PNG");
});

it('renders an SVG with the image/svg+xml mime type', function () {
    $result = (new QrCodeRenderer)->render('https://cabinet.de/rendez-vous', 'svg');

    expect($result['mime'])->toBe('image/svg+xml')
        ->and($result['body'])->toContain('<svg');
});
