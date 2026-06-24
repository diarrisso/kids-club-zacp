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
            'parent_phone' => '+49 160 1234567',
            'parent_email' => null,
            'service_id' => null,
            'notes' => null,
        ];
    }
}
