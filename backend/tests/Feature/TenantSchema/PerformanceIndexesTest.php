<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function indexNames(string $table): array
{
    return collect(DB::select(
        'SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]
    ))->pluck('indexname')->all();
}

it('creates the partial overlap index on appointments', function () {
    expect(indexNames('appointments'))->toContain('appointments_overlap_idx');
});

it('keeps the existing practitioner_id+starts_at index on appointments', function () {
    expect(indexNames('appointments'))->toContain('appointments_practitioner_id_starts_at_index');
});

it('creates the composite availabilities index and drops the redundant standalone', function () {
    $idx = indexNames('availabilities');
    expect($idx)->toContain('availabilities_practitioner_id_day_of_week_index');
    expect($idx)->not->toContain('availabilities_practitioner_id_index');
});

it('creates the composite exceptions index and drops the redundant standalones', function () {
    $idx = indexNames('availability_exceptions');
    expect($idx)->toContain('availability_exceptions_practitioner_id_starts_at_ends_at_index');
    expect($idx)->not->toContain('availability_exceptions_starts_at_index');
    expect($idx)->not->toContain('availability_exceptions_practitioner_id_index');
});
