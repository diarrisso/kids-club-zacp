<?php

namespace Database\Seeders;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class KidsClubSeeder extends Seeder
{
    public function run(): void
    {
        // Never seed a predictable password outside local/testing: require an
        // explicit env var in any other environment, or abort.
        $adminPassword = app()->environment(['local', 'testing'])
            ? 'changeme'
            : env('KIDSCLUB_ADMIN_PASSWORD');

        if (! $adminPassword) {
            throw new RuntimeException('KIDSCLUB_ADMIN_PASSWORD is required to seed the admin account outside local/testing.');
        }

        // The practice owner works reception (cosmetic role — both roles are full admin).
        User::firstOrCreate(['email' => 'michael@kidsclub.de'], [
            'name' => 'Michael Rohling',
            'password' => Hash::make($adminPassword),
            'role' => 'secretaire',
        ]);

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

        // A medecin login linked to Anna's Behandler fiche, so her dashboard
        // highlights "her" appointments. michael (above) stays secretaire.
        User::firstOrCreate(['email' => 'arzt@kidsclub.de'], [
            'name' => 'Dr. Anna Müller',
            'password' => Hash::make($adminPassword),
            'role' => 'medecin',
            'practitioner_id' => $anna->id,
        ]);

        // Demo appointments so the dashboard + calendar show real content. Rooms
        // are spread across the 5 KidsClub colours (plus one without a choice).
        $serviceList = Service::orderBy('id')->get();
        if ($serviceList->isNotEmpty()) {
            $today = CarbonImmutable::now('Europe/Berlin')->startOfDay();
            $weekStart = CarbonImmutable::now('Europe/Berlin')->startOfWeek();

            // [start, practitioner_id, room, [childFirst, childLast], [parentFirst, parentLast]]
            $demo = [
                [$today->setTime(9, 0), $anna->id, 'green', ['Max', 'Becker'], ['Julia', 'Becker']],
                [$today->setTime(10, 30), $bjorn->id, 'blue', ['Lena', 'Hofmann'], ['Stefan', 'Hofmann']],
                [$today->setTime(14, 0), $anna->id, 'peach', ['Tom', 'Wagner'], ['Nicole', 'Wagner']],
                [$weekStart->setTime(11, 0), $bjorn->id, 'yellow', ['Mia', 'Fischer'], ['Daniel', 'Fischer']],
                [$weekStart->addDays(2)->setTime(9, 30), $anna->id, 'purple', ['Paul', 'Weber'], ['Sandra', 'Weber']],
                [$weekStart->addDays(3)->setTime(15, 0), $bjorn->id, null, ['Emma', 'Klein'], ['Markus', 'Klein']],
            ];

            foreach ($demo as $i => [$start, $practitionerId, $room, $child, $parent]) {
                $service = $serviceList[$i % $serviceList->count()];
                Appointment::firstOrCreate(
                    ['practitioner_id' => $practitionerId, 'starts_at' => $start],
                    [
                        'service_id' => $service->id,
                        'ends_at' => $start->addMinutes($service->duration_minutes),
                        'status' => 'confirmed',
                        'room' => $room,
                        'patient_first_name' => $child[0],
                        'patient_last_name' => $child[1],
                        'patient_birthdate' => '2018-04-12',
                        'parent_first_name' => $parent[0],
                        'parent_last_name' => $parent[1],
                        'parent_email' => strtolower($parent[0]).'.'.strtolower($parent[1]).'@example.de',
                        'parent_phone' => '030 1234567',
                        'parent_consent_at' => CarbonImmutable::now(),
                        'cancellation_token' => (string) Str::uuid(),
                    ]
                );
            }
        }
    }
}
