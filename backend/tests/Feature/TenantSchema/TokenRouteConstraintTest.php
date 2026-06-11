<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 (not 500) for a malformed token on the storno page', function () {
    $this->get('/storno/not-a-uuid')->assertNotFound();
});

it('returns 404 for a malformed token on the api show endpoint', function () {
    $this->getJson('/api/v1/widget/appointments/not-a-uuid')->assertNotFound();
});

it('returns 404 for a malformed token on the api cancel endpoint', function () {
    $this->postJson('/api/v1/widget/appointments/not-a-uuid/cancel')->assertNotFound();
});

it('still 404s a well-formed but unknown uuid token', function () {
    $unknown = '00000000-0000-4000-8000-000000000000';
    $this->get("/storno/{$unknown}")->assertNotFound();
    $this->getJson("/api/v1/widget/appointments/{$unknown}")->assertNotFound();
});
