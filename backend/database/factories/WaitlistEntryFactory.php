<?php

namespace Database\Factories;

use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    public function definition(): array
    {
        return [
            'patient_first_name' => 'Emma',
            'patient_last_name' => 'Test',
            'parent_first_name' => 'Katrin',
            'parent_last_name' => 'Test',
            // Unique per entry: a partial unique index forbids two pending rows with
            // the same phone + service, so a fixed default would collide when a test
            // creates several entries. Tests needing a specific phone override it.
            'parent_phone' => '+49 160 '.fake()->unique()->numerify('#######'),
            'parent_email' => null,
            'service_id' => null,
            'notes' => null,
        ];
    }
}
