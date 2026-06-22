<?php

use App\Models\Tenant\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function staffUser(): User
{
    return User::factory()->create(['two_factor_confirmed_at' => now()]);
}

it('finds appointments by patient last name (case-insensitive)', function () {
    Appointment::factory()->create(['patient_last_name' => 'Diallo']);
    Appointment::factory()->create(['patient_last_name' => 'Barry']);

    $this->actingAs(staffUser())
        ->get('/termine/liste?q=diallo')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Appointments/List')
            ->where('appointments.data.0.patient_last_name', 'Diallo')
            ->has('appointments.data', 1));
});

it('finds appointments by parent last name', function () {
    Appointment::factory()->create(['parent_last_name' => 'Soumah', 'patient_last_name' => 'X']);
    Appointment::factory()->create(['parent_last_name' => 'Bah', 'patient_last_name' => 'Y']);

    $this->actingAs(staffUser())
        ->get('/termine/liste?q=soumah')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments.data', 1));
});

it('is immune to SQL injection in the search term', function () {
    Appointment::factory()->count(3)->create();

    $this->actingAs(staffUser())
        ->get('/termine/liste?'.http_build_query(['q' => "'; DROP TABLE appointments; --"]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments.data', 0));

    // The table must still exist and hold its rows.
    expect(DB::table('appointments')->count())->toBe(3);
});

it('filters by attendance', function () {
    Appointment::factory()->create(['attendance' => 'no_show']);
    Appointment::factory()->create(['attendance' => 'arrived']);
    Appointment::factory()->create(['attendance' => null]);

    $this->actingAs(staffUser())
        ->get('/termine/liste?attendance=no_show')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments.data', 1));
});

it('paginates at 25 per page', function () {
    Appointment::factory()->count(30)->create();

    $this->actingAs(staffUser())
        ->get('/termine/liste')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments.data', 25));
});

it('rejects an over-long search term with 422', function () {
    $this->actingAs(staffUser())
        ->get('/termine/liste?q='.str_repeat('a', 101))
        ->assertStatus(422);
});

it('does not N+1 across the appointment list', function () {
    $user = staffUser();
    Appointment::factory()->count(20)->create();

    DB::enableQueryLog();
    $this->actingAs($user)->get('/termine/liste')->assertOk();
    $first = count(DB::getQueryLog());

    // Disable log while inserting more rows so factory INSERTs don't skew the count.
    DB::disableQueryLog();
    Appointment::factory()->count(20)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($user)->get('/termine/liste')->assertOk();
    $second = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Query count must not grow with the number of rows (eager-load).
    expect($second)->toBe($first);
});
