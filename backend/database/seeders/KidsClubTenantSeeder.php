<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class KidsClubTenantSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::firstOrCreate(['name' => 'Starter'], [
            'price_monthly' => 2900,
            'features' => ['max_practitioners' => 5, 'sms' => false],
        ]);

        $tenant = Tenant::firstOrCreate(
            ['id' => 'kidsclub'],
            ['name' => 'Kids Club by zacp', 'slug' => 'kidsclub', 'status' => 'active', 'plan_id' => $plan->id]
        );

        $tenant->domains()->firstOrCreate(
            ['domain' => 'kidsclub.masinga-booking.test'],
            ['is_primary' => true]
        );

        User::firstOrCreate(['email' => 'michael@kidsclub.de'], [
            'name' => 'Michael Rohling',
            'password' => Hash::make('changeme'),
            'role' => 'tenant_owner',
            'tenant_id' => $tenant->id,
        ]);

        $tenant->run(function () {
            $anna = Practitioner::firstOrCreate(['email' => 'anna@kidsclub.de'], [
                'first_name' => 'Anna', 'last_name' => 'Müller',
                'title' => 'Dr.', 'color' => '#FF6B6B', 'is_active' => true,
            ]);

            $bjorn = Practitioner::firstOrCreate(['email' => 'bjorn@kidsclub.de'], [
                'first_name' => 'Björn', 'last_name' => 'Schmidt',
                'title' => 'Zahnarzt', 'color' => '#4ECDC4', 'is_active' => true,
            ]);

            $services = [
                ['name' => 'Erstuntersuchung Kind', 'duration_minutes' => 45],
                ['name' => 'Prophylaxe', 'duration_minutes' => 30],
                ['name' => 'Notfall', 'duration_minutes' => 60],
            ];
            foreach ($services as $s) {
                $service = Service::firstOrCreate(['name' => $s['name']], $s + [
                    'color' => '#0a6cb3', 'is_active' => true,
                ]);
                $service->practitioners()->syncWithoutDetaching([$anna->id, $bjorn->id]);
            }

            foreach ([1, 2, 3, 4, 5] as $day) {
                Availability::firstOrCreate(
                    ['practitioner_id' => $anna->id, 'day_of_week' => $day],
                    ['start_time' => '09:00', 'end_time' => '17:00']
                );
            }
        });
    }
}
