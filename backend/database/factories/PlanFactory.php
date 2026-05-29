<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => 'Starter',
            'price_monthly' => 2900,
            'features' => ['max_practitioners' => 1, 'sms' => false],
        ];
    }
}
