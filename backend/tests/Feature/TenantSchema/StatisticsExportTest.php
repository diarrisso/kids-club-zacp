<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
});

// Distinct helper names — StatisticsTest.php already declares statsStaff()/pastAt()
// at top level in the same Pest suite; re-declaring them would fatal.
function csvStaff(): User
{
    return User::factory()->create([
        'role' => 'secretaire',
        'two_factor_confirmed_at' => now(),
    ]);
}

function csvPast(): CarbonImmutable
{
    return CarbonImmutable::now('Europe/Berlin')->subDays(10)->setTime(9, 0);
}

it('exports a CSV with text/csv content type and a dated attachment filename', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(3)->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => csvPast()]);

    $from = CarbonImmutable::now('Europe/Berlin')->subDays(30)->toDateString();
    $to = CarbonImmutable::now('Europe/Berlin')->toDateString();

    $res = $this->actingAs(csvStaff())->get("/statistiken/export?from={$from}&to={$to}");

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain("noshow-statistik_{$from}_{$to}.csv");
});

it('writes per-practitioner rows sorted by rate desc plus a Gesamt total row', function () {
    $a = Practitioner::factory()->create(['title' => 'Dr.', 'first_name' => 'Anna', 'last_name' => 'M']);
    $b = Practitioner::factory()->create(['title' => 'Dr.', 'first_name' => 'Bo', 'last_name' => 'S']);
    Appointment::factory()->count(5)->create(['practitioner_id' => $a->id, 'attendance' => 'arrived', 'starts_at' => csvPast()]);
    Appointment::factory()->count(1)->create(['practitioner_id' => $a->id, 'attendance' => 'no_show', 'starts_at' => csvPast()]);
    Appointment::factory()->count(2)->create(['practitioner_id' => $b->id, 'attendance' => 'no_show', 'starts_at' => csvPast()]);

    $csv = $this->actingAs(csvStaff())->get('/statistiken/export')->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // fputcsv quotes fields containing spaces — header columns with spaces are quoted
    expect($lines[0])->toBe('Behandler,Erschienen,"Nicht erschienen","Nicht erfasst","No-Show-Quote (%)"');
    // b first (100%), then a (1/6 = 16.7%) — names with spaces are quoted by fputcsv
    expect($lines[1])->toBe('"Dr. Bo S",0,2,,100');
    expect($lines[2])->toBe('"Dr. Anna M",5,1,,16.7');
    // Gesamt last: arrived 5, no_show 3, notRecorded 0, rate 3/8 = 37.5
    expect($lines[3])->toBe('Gesamt,5,3,0,37.5');
});

it('scopes a linked medecin export to their own single row', function () {
    $a = Practitioner::factory()->create();
    $b = Practitioner::factory()->create();
    Appointment::factory()->count(3)->create(['practitioner_id' => $a->id, 'attendance' => 'arrived', 'starts_at' => csvPast()]);
    Appointment::factory()->count(4)->create(['practitioner_id' => $b->id, 'attendance' => 'no_show', 'starts_at' => csvPast()]);

    $medecin = User::factory()->create([
        'role' => 'medecin', 'practitioner_id' => $a->id, 'two_factor_confirmed_at' => now(),
    ]);

    $csv = $this->actingAs($medecin)->get('/statistiken/export')->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // header + exactly 1 practitioner row + Gesamt
    expect($lines)->toHaveCount(3);
    // Gesamt = a's figures only (3 arrived, 0 no_show, rate 0)
    expect($lines[2])->toBe('Gesamt,3,0,0,0');
});

it('fails closed: an unlinked medecin export has no practitioner rows', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(5)->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => csvPast()]);

    $unlinked = User::factory()->create([
        'role' => 'medecin', 'practitioner_id' => null, 'two_factor_confirmed_at' => now(),
    ]);

    $csv = $this->actingAs($unlinked)->get('/statistiken/export')->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // header + Gesamt only
    expect($lines)->toHaveCount(2);
    expect($lines[1])->toBe('Gesamt,0,0,0,');
});

it('rejects an invalid period on export with 422', function () {
    $this->actingAs(csvStaff())
        ->get('/statistiken/export?from=not-a-date')
        ->assertStatus(422);
});
