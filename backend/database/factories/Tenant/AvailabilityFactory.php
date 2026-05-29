<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

class AvailabilityFactory extends Factory
{
    protected $model = Availability::class;

    public function definition(): array
    {
        return [
            'practitioner_id' => Practitioner::factory(),
            'day_of_week' => $this->faker->numberBetween(1, 5),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ];
    }
}
