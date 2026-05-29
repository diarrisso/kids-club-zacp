<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Practitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

class PractitionerFactory extends Factory
{
    protected $model = Practitioner::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->randomElement(['Anna', 'Lukas', 'Sophie', 'Felix', 'Marie']),
            'last_name' => $this->faker->randomElement(['Müller', 'Schmidt', 'Weber', 'Fischer', 'Wagner']),
            'title' => $this->faker->randomElement(['Dr.', 'Zahnärztin', 'Zahnarzt']),
            'email' => $this->faker->safeEmail(),
            'color' => $this->faker->hexColor(),
            'is_active' => true,
        ];
    }
}
