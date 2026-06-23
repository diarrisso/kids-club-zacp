<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
});

function statsStaff(): User
{
    return User::factory()->create([
        'role' => 'secretaire',
        'two_factor_confirmed_at' => now(),
    ]);
}

// A past Berlin datetime (10 days ago at 09:00), safely inside the default 30-day window.
function pastAt(): CarbonImmutable
{
    return CarbonImmutable::now('Europe/Berlin')->subDays(10)->setTime(9, 0);
}

it('computes the no-show rate from past recorded appointments', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(8)->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->count(2)->create(['practitioner_id' => $p->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Statistics/Index')
            ->where('kpis.arrived', 8)
            ->where('kpis.noShow', 2)
            ->where('kpis.rate', 20));
});

it('excludes not-recorded (null) appointments from the rate denominator', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(8)->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->count(2)->create(['practitioner_id' => $p->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);
    Appointment::factory()->count(5)->create(['practitioner_id' => $p->id, 'attendance' => null, 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.rate', 20)          // 2 / (8+2), NOT 2/15. Int: json_encode drops .0
            ->where('kpis.notRecorded', 5));
});

it('excludes future appointments entirely', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    // Future appointment: tomorrow, not yet recorded — must not count anywhere.
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'attendance' => null,
        'starts_at' => CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(9, 0),
    ]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.arrived', 1)
            ->where('kpis.notRecorded', 0));
});

it('excludes cancelled appointments', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'no_show', 'status' => 'cancelled', 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.arrived', 1)
            ->where('kpis.noShow', 0));
});

it('bounds the population by the from/to period', function () {
    $p = Practitioner::factory()->create();
    // Inside default window:
    Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    // 100 days ago — outside a from=60-days-ago filter:
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'attendance' => 'arrived',
        'starts_at' => CarbonImmutable::now('Europe/Berlin')->subDays(100)->setTime(9, 0),
    ]);

    $from = CarbonImmutable::now('Europe/Berlin')->subDays(60)->toDateString();
    $to = CarbonImmutable::now('Europe/Berlin')->toDateString();

    $this->actingAs(statsStaff())
        ->get("/statistiken?from={$from}&to={$to}")
        ->assertInertia(fn ($page) => $page->where('kpis.arrived', 1));
});

it('returns a null rate when no appointment is recorded', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(3)->create(['practitioner_id' => $p->id, 'attendance' => null, 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.rate', null)
            ->where('kpis.notRecorded', 3));
});

it('breaks the figures down per practitioner', function () {
    $a = Practitioner::factory()->create();
    $b = Practitioner::factory()->create();
    Appointment::factory()->count(5)->create(['practitioner_id' => $a->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->count(1)->create(['practitioner_id' => $a->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);
    Appointment::factory()->count(2)->create(['practitioner_id' => $b->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->has('perPractitioner', 2)
            // sorted by rate desc: b (100%) before a (~16.7%)
            ->where('perPractitioner.0.id', $b->id)
            ->where('perPractitioner.0.rate', 100)
            ->where('perPractitioner.1.id', $a->id));
});

it('scopes a linked medecin to their own figures only', function () {
    $a = Practitioner::factory()->create();
    $b = Practitioner::factory()->create();
    Appointment::factory()->count(3)->create(['practitioner_id' => $a->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->count(4)->create(['practitioner_id' => $b->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);

    $medecin = User::factory()->create([
        'role' => 'medecin', 'practitioner_id' => $a->id, 'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($medecin)
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('scoped', true)
            ->where('kpis.arrived', 3)
            ->where('kpis.noShow', 0)          // b's no_shows excluded
            ->has('perPractitioner', 1));
});

it('keeps a constant query count regardless of practitioner count (no N+1)', function () {
    $mk = function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            $p = Practitioner::factory()->create();
            Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
        }
    };
    $mk(2);
    DB::enableQueryLog();
    $this->actingAs(statsStaff())->get('/statistiken')->assertOk();
    $first = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Create the extra practitioners with logging OFF so only the request's
    // queries are counted on the second pass (flushQueryLog alone leaves
    // logging enabled, which would count these INSERTs).
    $mk(8);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs(statsStaff())->get('/statistiken')->assertOk();
    $second = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($second)->toBe($first);
});

it('rejects an invalid period with 422', function () {
    $this->actingAs(statsStaff())
        ->get('/statistiken?from=not-a-date')
        ->assertStatus(422);
});

it('rejects an inverted period (from after to) with 422', function () {
    $from = CarbonImmutable::now('Europe/Berlin')->toDateString();
    $to = CarbonImmutable::now('Europe/Berlin')->subDays(10)->toDateString();

    $this->actingAs(statsStaff())
        ->get("/statistiken?from={$from}&to={$to}")
        ->assertStatus(422);
});
