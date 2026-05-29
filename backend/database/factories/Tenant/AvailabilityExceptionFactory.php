<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\AvailabilityException;
use App\Models\Tenant\Practitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

class AvailabilityExceptionFactory extends Factory
{
    protected $model = AvailabilityException::class;

    public function definition(): array
    {
        return [
            'practitioner_id' => Practitioner::factory(),
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-08-15 23:59:59',
            'type' => $this->faker->randomElement(['vacation', 'sick', 'block']),
            'reason' => null,
        ];
    }
}
