<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement([
                'Erstuntersuchung', 'Prophylaxe', 'Kontrolle', 'Versiegelung',
            ]),
            'duration_minutes' => $this->faker->randomElement([15, 30, 45, 60]),
            'color' => $this->faker->hexColor(),
            'is_active' => true,
        ];
    }
}
