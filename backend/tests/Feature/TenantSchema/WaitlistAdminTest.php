<?php

use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\WaitlistStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function waitlistStaff(): User
{
    return User::factory()->create(['two_factor_confirmed_at' => now()]);
}

it('lists waitlist entries filtered by pending status by default', function () {
    WaitlistEntry::factory()->count(3)->create();
    $entry = WaitlistEntry::factory()->create();
    $entry->status = WaitlistStatus::Contacted;
    $entry->save();

    $this->actingAs(waitlistStaff())
        ->get('/warteliste')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Waitlist/Index')
            ->has('entries.data', 3)    // only pending
            ->has('statusOptions'));
});

it('lists all entries when status filter is empty', function () {
    WaitlistEntry::factory()->count(2)->create();
    $e = WaitlistEntry::factory()->create();
    $e->status = WaitlistStatus::Contacted;
    $e->save();

    $this->actingAs(waitlistStaff())
        ->get('/warteliste?status=')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 3));
});

it('updates the status of a waitlist entry', function () {
    $entry = WaitlistEntry::factory()->create();

    $this->actingAs(waitlistStaff())
        ->patchJson("/warteliste/{$entry->id}", ['status' => 'contacted'])
        ->assertOk()
        ->assertJson(['status' => 'contacted']);

    expect($entry->fresh()->status)->toBe(WaitlistStatus::Contacted);
});

it('rejects an invalid status on update', function () {
    $entry = WaitlistEntry::factory()->create();

    $this->actingAs(waitlistStaff())
        ->patchJson("/warteliste/{$entry->id}", ['status' => 'invalid'])
        ->assertStatus(422);
});

it('shares waitlist_pending_count in Inertia props', function () {
    WaitlistEntry::factory()->count(4)->create();

    $this->actingAs(waitlistStaff())
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('waitlist_pending_count', 4));
});
